<?php

namespace ApManBundle\Service;

/**
 * Compares the hostapd configuration that is actually running on every access
 * point, grouped by SSID and band.
 *
 * This catches the class of error nothing else notices: a single access point
 * with a different mobility domain or FT key keeps serving clients perfectly,
 * it just never does fast roaming with the rest. That happened here because
 * OpenWrt derives mobility_domain and the FT key when they are not configured
 * (wifi-scripts ap.uc), and the derivation changed between firmware versions,
 * so an access point on an older build silently formed its own roaming island.
 */
class WlanConsistencyService
{
    /** must match across all access points serving the same SSID on the same band */
    public const CRITICAL = [
        'wpa', 'wpa_key_mgmt', 'wpa_pairwise', 'rsn_pairwise', 'ieee80211w',
        'ieee80211r', 'mobility_domain', 'r0kh', 'r1kh', 'ft_over_ds',
        'ft_psk_generate_local', 'pmk_r1_push', 'reassociation_deadline',
        'r0_key_lifetime', 'auth_server_addr', 'auth_server_port',
        'acct_server_addr', 'okc', 'disable_pmksa_caching', 'dynamic_vlan',
        'wmm_enabled', 'ap_isolate', 'multi_ap', 'sae_pwe', 'sae_require_mfp',
        // where the keys come from: these decide whether per device keys work
        // at all, and they must be the same on every access point of a network
        'wpa_psk_radius', 'macaddr_acl',
    ];

    /**
     * Options where only the *presence* has to match, not the value.
     *
     * The two key files carry the interface name, so their values differ by
     * design. But an access point that renders one while its neighbours do
     * not has drifted — which is exactly what a patched wifi-scripts looks
     * like. Six of seven access points ran such a patch on 2026-08-21 and
     * nothing noticed, because no comparison covered these options.
     */
    public const PRESENCE = [
        'wpa_psk_file', 'sae_password_file', 'wpa_passphrase',
    ];

    /** never rendered, only compared as a hash */
    public const SECRETS = [
        'wpa_passphrase', 'wpa_psk', 'sae_password',
        'auth_server_shared_secret', 'acct_server_shared_secret',
    ];

    /**
     * Options ucode writes for itself, whatever we set.
     *
     * `ap.uc` assigns these outright rather than reading what uci says, so a
     * value set here is discarded on every provisioning run and the option
     * looks like a setting for as long as nobody checks. Measured on OpenWrt
     * 25.12.5, ap.uc md5 c799af52a701, the same file on all seven access
     * points.
     *
     * `start_disabled` is the one that matters in the field: every bss in the
     * fleet carries it — eleven to fifteen uci sections per access point — and
     * it reaches none of the twenty-one generated hostapd configurations. It
     * was set to keep a network from coming up, and the network has been up the
     * whole time.
     *
     * option => [the ap.uc line, what it does instead]
     */
    public const CLOBBERED = [
        'start_disabled' => ['generate():540',
            'ap.uc sets it from the staging flag wifi-scripts computes for a reload, so a value '
            .'from uci is always overwritten. To keep a bss off the air use disabled, or take the '
            .'network off that radio on its rollout page.'],
        'wmm_enabled' => ['iface_setup():51', 'ap.uc writes 1 unconditionally.'],
        'ssid2' => ['iface_setup():50', 'ap.uc writes the ssid into it unconditionally.'],
        'group_mgmt_cipher' => ['iface_encryption():438',
            'ap.uc takes it from ieee80211w_mgmt_cipher, or its own default when that is unset.'],
    ];

    /** these carry key material, show the tail only */
    public const MASKED = ['r0kh', 'r1kh'];

    private $logger;
    private $doctrine;
    private $rpcService;
    private $ubus;
    private $apService;
    private $cacheFactory;
    private $stateTree;
    private $schema;
    private $builds;

    /**
     * Which access points run the patched hostapd, by name.
     *
     * Filled once per check() and read by blockRules(), which works on parsed
     * configuration and has an access point name rather than an entity.
     */
    private array $patched = [];

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \ApManBundle\Factory\CacheFactory $cacheFactory,
        StateTreeService $stateTree,
        WirelessSchemaService $schema,
        ApUbusService $ubus,
        AccessPointService $apService,
        HostapdBuildService $builds
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->ubus = $ubus;
        $this->apService = $apService;
        $this->cacheFactory = $cacheFactory;
        $this->stateTree = $stateTree;
        $this->schema = $schema;
        $this->builds = $builds;
    }

    /**
     * @return array [findings, coverage, errors]
     */
    public function check($maxAge = 600)
    {
        $cached = $this->cacheFactory->getCacheItemValue('wlan.consistency');
        if (is_array($cached) && isset($cached['ts']) && (time() - $cached['ts']) < $maxAge) {
            return $cached;
        }
        $result = $this->run();
        $this->cacheFactory->addCacheItem('wlan.consistency', $result, 7 * 86400);

        return $result;
    }

    /**
     * What a radio's own lines say in the configuration it is running.
     *
     * The radio page can set mbssid, the spatial reuse block and the rssi
     * thresholds, and could not show what any of them actually became. For an
     * option whose whole risk is "does this driver do it" — and the fleet has
     * five driver families, ath11k, ath11k_pci, ath10k_pci, mt7915e and
     * mt798x-wmac — setting it and checking it belong in the same place.
     *
     * Read from what the consistency run already parsed and cached. Never
     * fetched here: a page load must not wait on eight access points, and a
     * value from ten minutes ago is the right answer for a line that only
     * changes on a provisioning run.
     *
     * @return array|null the radio level preamble, or null if nothing is cached
     */
    public function runningRadioConfig(\ApManBundle\Entity\Radio $radio): ?array
    {
        $ap = $radio->getAccessPoint();
        if (!$ap) {
            return null;
        }
        $cached = $this->cacheFactory->getCacheItemValue('wlan.radioconf');
        if (!is_array($cached)) {
            return null;
        }

        return $cached[$ap->getName()][$radio->getName()] ?? null;
    }

    /**
     * The configuration one bss is running, as the access point generated it.
     *
     * Secrets are replaced by their length and the roaming key lists by their
     * tail, the same way the consistency page shows them — this is a page in a
     * browser, and a wpa_passphrase belongs in neither.
     *
     * @return array|null option => value, or null if nothing is cached
     */
    public function runningBssConfig(\ApManBundle\Entity\Device $device): ?array
    {
        $radio = $device->getRadio();
        $ap = $radio ? $radio->getAccessPoint() : null;
        $ifname = (string) $device->ifname();
        if (!$ap || '' === $ifname) {
            return null;
        }
        $cached = $this->cacheFactory->getCacheItemValue('wlan.bssconf');

        return is_array($cached) ? ($cached[$ap->getName()][$ifname] ?? null) : null;
    }

    private function run()
    {
        $aps = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findBy(['IsProductive' => true]);
        $blocks = [];
        $errors = [];
        foreach ($aps as $ap) {
            $conf = $this->fetchConfig($ap);
            if (null === $conf) {
                $errors[] = $ap->getName().': config not readable';
                continue;
            }
            $keyfiles = [];
            foreach ($this->parse($conf, $keyfiles) as $bss => $cfg) {
                $blocks[] = ['ap' => $ap->getName(), 'bss' => $bss, 'cfg' => $cfg,
                    'keyfiles' => $keyfiles];
            }
        }

        // The radio level lines, per radio, so the radio page can show what its
        // options became without asking an access point on every page load.
        // The block's ifname resolves to the device and from there to the radio.
        $radioConf = [];
        $byIfname = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\Device')->findAll() as $device) {
            $r = $device->getRadio();
            $a = $r ? $r->getAccessPoint() : null;
            if ($a && $device->ifname()) {
                $byIfname[$a->getName()][$device->ifname()] = $r->getName();
            }
        }
        foreach ($blocks as $b) {
            $radioName = $byIfname[$b['ap']][$b['bss']] ?? null;
            if (null !== $radioName && !isset($radioConf[$b['ap']][$radioName])) {
                $radioConf[$b['ap']][$radioName] = $b['cfg']['_radio'] ?? [];
            }
        }
        $this->cacheFactory->addCacheItem('wlan.radioconf', $radioConf, 7 * 86400);

        // and the same per bss, which is where most options actually live
        $bssConf = [];
        foreach ($blocks as $b) {
            $cfg = $b['cfg'];
            unset($cfg['_radio'], $cfg['_band']);
            foreach (self::SECRETS as $secret) {
                if (isset($cfg[$secret])) {
                    $cfg[$secret] = '(set, '.strlen((string) $cfg[$secret]).' characters)';
                }
            }
            foreach (self::MASKED as $masked) {
                if (isset($cfg[$masked])) {
                    $parts = explode(' ', $cfg[$masked]);
                    $cfg[$masked] = implode(' ', array_slice($parts, 0, -1)).' …'.substr(end($parts), -8);
                }
            }
            $bssConf[$b['ap']][$b['bss']] = $cfg;
        }
        $this->cacheFactory->addCacheItem('wlan.bssconf', $bssConf, 7 * 86400);

        // group by ssid and band: 6 GHz legitimately differs in pmf and akm
        $groups = [];
        foreach ($blocks as $b) {
            $ssid = $b['cfg']['ssid'] ?? trim($b['cfg']['ssid2'] ?? '', '"');
            if ('' === $ssid) {
                continue;
            }
            $groups[$ssid.' / '.($b['cfg']['_band'] ?? '?')][] = $b;
        }

        $findings = [];
        $coverage = [];
        foreach ($groups as $key => $members) {
            $coverage[$key] = ['bss' => count($members), 'aps' => count(array_unique(array_column($members, 'ap')))];
            if (count($members) < 2) {
                continue;
            }
            foreach (self::CRITICAL as $opt) {
                $seen = [];
                foreach ($members as $m) {
                    $val = $m['cfg'][$opt] ?? '<unset>';
                    if (in_array($opt, self::MASKED, true) && '<unset>' !== $val) {
                        $parts = explode(' ', $val);
                        $val = implode(' ', array_slice($parts, 0, -1)).' …'.substr(end($parts), -8);
                    }
                    $seen[$val][] = $m['ap'].'/'.$m['bss'];
                }
                if (count($seen) > 1) {
                    $findings[] = [
                        'group' => $key, 'option' => $opt, 'values' => $seen,
                        'roaming' => in_array($opt, ['mobility_domain', 'r0kh', 'r1kh', 'ft_psk_generate_local', 'wpa_key_mgmt', 'ieee80211w'], true),
                    ];
                }
            }
            foreach (self::PRESENCE as $opt) {
                $seen = [];
                foreach ($members as $m) {
                    $seen[isset($m['cfg'][$opt]) ? 'set' : '<unset>'][] = $m['ap'].'/'.$m['bss'];
                }
                if (count($seen) > 1) {
                    $findings[] = ['group' => $key, 'option' => $opt.' (set on some, not on others)',
                        'values' => $seen, 'roaming' => false];
                }
            }

            // secrets are compared, never shown
            foreach (self::SECRETS as $opt) {
                $seen = [];
                foreach ($members as $m) {
                    if (!isset($m['cfg'][$opt])) {
                        continue;
                    }
                    // iPSK: every AP answers with its own RADIUS secret —
                    // a difference across the fleet is the design, not a
                    // drift. Only external auth servers must match.
                    if ('auth_server_shared_secret' === $opt
                        && isset($m['cfg']['auth_server_addr'])
                        && '127.0.0.1' === $m['cfg']['auth_server_addr']) {
                        continue;
                    }
                    $seen[substr(hash('sha256', $m['cfg'][$opt]), 0, 8)][] = $m['ap'].'/'.$m['bss'];
                }
                if (count($seen) > 1) {
                    $findings[] = ['group' => $key, 'option' => $opt.' (hash)', 'values' => $seen, 'roaming' => true];
                }
            }
        }

        // Rules that hold for a single bss on its own. Everything above
        // compares access points against each other and is blind to a fleet
        // that is uniformly wrong — which is how sae_pwe=2 reached every
        // access point at once and locked out a whole network without a
        // single deviation being reported.
        //
        // Two of the rules below invert with the hostapd build, so the answer
        // has to be in hand before the first of them runs. Only the access
        // points that actually appear in the dump are asked — an entity that
        // is not in here has no block to judge, and asking it would spend a
        // timeout on an access point nobody is looking at.
        $seen = [];
        foreach ($blocks as $b) {
            if (!empty($b['ap'])) {
                $seen[$b['ap']] = true;
            }
        }
        $this->patched = [];
        if ($seen) {
            $aps = $this->doctrine->getRepository('ApManBundle\\Entity\\AccessPoint')
                ->findBy(['name' => array_keys($seen)]);
            $this->patched = $this->builds->fleet($aps);
        }

        foreach ($blocks as $b) {
            foreach ($this->blockRules($b) as $f) {
                $findings[] = $f;
            }
        }
        foreach ($this->fleetRules($blocks) as $f) {
            $findings[] = $f;
        }
        foreach ($this->runningRadioRules() as $f) {
            $findings[] = $f;
        }
        foreach ($this->clobberedOptionRules() as $f) {
            $findings[] = $f;
        }
        foreach ($this->arrivalRules($blocks) as $f) {
            $findings[] = $f;
        }
        foreach ($this->addressRules($blocks) as $f) {
            $findings[] = $f;
        }
        foreach ($this->ifnameRules($blocks) as $f) {
            $findings[] = $f;
        }
        foreach ($this->macListRules() as $f) {
            $findings[] = $f;
        }
        foreach ($this->rawOptionRules() as $f) {
            $findings[] = $f;
        }
        foreach ($this->missingBssRules($aps, $blocks) as $f) {
            $findings[] = $f;
        }

        usort($findings, function ($a, $b) {
            return ($b['roaming'] <=> $a['roaming']) ?: strcmp($a['group'], $b['group']);
        });

        return ['ts' => time(), 'findings' => $findings, 'coverage' => $coverage, 'errors' => $errors];
    }

    /**
     * What one bss must not look like, whatever its neighbours do.
     *
     * Every rule here is a failure that happened on this fleet. The comparison
     * above cannot catch any of them, because a wrong setting rolled out
     * everywhere is perfectly consistent.
     */
    private function blockRules(array $b)
    {
        $cfg = $b['cfg'];
        $where = $b['ap'].'/'.$b['bss'];
        $ssid = $cfg['ssid'] ?? trim($cfg['ssid2'] ?? '', '"');
        $group = $ssid.' / '.($cfg['_band'] ?? '?');
        $out = [];
        $say = function ($option, $value, $roaming = false) use (&$out, $group, $where) {
            $out[] = ['group' => $group, 'option' => $option,
                'values' => [$value => [$where]], 'roaming' => $roaming];
        };

        $radiusKeys = isset($cfg['wpa_psk_radius']) && '0' !== $cfg['wpa_psk_radius'];
        $sae = false !== strpos($cfg['wpa_key_mgmt'] ?? '', 'SAE');
        // unknown counts as stock: see HostapdBuildService on why the answer
        // leans that way
        $patched = (bool) ($this->patched[$b['ap']] ?? false);

        // The one that cost a night: with a passphrase configured,
        // sae_get_password() takes it and never looks at the key the RADIUS
        // sent, so every device on the network shares one secret again.
        if ($radiusKeys && isset($cfg['wpa_passphrase']) && '' !== $cfg['wpa_passphrase']) {
            $say('wpa_passphrase on a network with RADIUS keys',
                'set — it shadows every per device key under SAE', true);
        }

        // macaddr_acl=2 is what makes hostapd ask at all. Without it the
        // RADIUS answers nothing because nobody asks; with it and no server
        // configured, every station is denied at the ACL.
        if ($radiusKeys && '2' !== ($cfg['macaddr_acl'] ?? '')) {
            $say('macaddr_acl', 'wpa_psk_radius is set but nobody asks the server');
        }
        if ('2' === ($cfg['macaddr_acl'] ?? '') && empty($cfg['auth_server_addr'])) {
            $say('auth_server_addr', 'macaddr_acl=2 with no server — every station is denied');
        }

        // What sae_pwe means, from hostapd.conf, because this rule had it
        // backwards until 2026-08-29 and said so confidently:
        //
        //   0 = hunting-and-pecking loop only
        //   1 = hash-to-element ONLY
        //   2 = BOTH hunting-and-pecking and hash-to-element
        //
        // So 2 is the permissive value and 1 the restrictive one, which is
        // the opposite of what the old comment claimed.
        //
        // That also explains the 2026-08-21 outage properly. sae_pwe=2 does
        // not force H2E - it advertises it, and a station that can do H2E
        // then chooses it. On stock hostapd a password from an Access-Accept
        // has no PT, so every one of those stations was refused with status
        // 126 while, in principle, hunting-and-pecking was still on offer.
        // The ones that fell off were exactly the capable ones.
        //
        // Note also that a station using an SAE Password Identifier gets H2E
        // regardless of this setting - hostapd.conf says so outright - which
        // matters now that sae_password_radius is on.
        $pwe = (string) ($cfg['sae_pwe'] ?? '');
        if ($sae && '6g' !== ($cfg['_band'] ?? '')) {
            if ($radiusKeys && !$patched && in_array($pwe, ['1', '2'], true)) {
                $say('sae_pwe', $pwe.' on stock hostapd with per station keys — a password '
                    .'from RADIUS has no PT, so '.('1' === $pwe
                        ? 'no station can associate at all'
                        : 'every station that can do H2E is refused'), true);
            } elseif ('1' === $pwe) {
                // hash-to-element only: a deliberate exclusion rather than a
                // mistake, so it is said quietly
                $say('sae_pwe', '1 (hash-to-element only) — stations without H2E cannot '
                    .'associate; 2 admits both');
            }
        }

        // A key that arrives over RADIUS has no PT on stock hostapd, so it can
        // do no H2E, and 6 GHz permits nothing else. The combination cannot
        // work there at all — measured on ap-av-grwz 2026-08-22: wap-kc2
        // beaconed on 6055 MHz with SAE FT-SAE, wpa_psk_radius=2 and no
        // sae_pwe, and never had a station. Not a network configured wrongly,
        // a network nobody can enter, advertised in every scan.
        if ($radiusKeys && $sae && '6g' === ($cfg['_band'] ?? '')) {
            if (!$patched) {
                $say('wpa_psk_radius on 6 GHz SAE',
                    'keys delivered over RADIUS carry no PT, and 6 GHz requires H2E — '
                    .'this bss can never admit a station');
            } elseif (!in_array($pwe, ['1', '2'], true)) {
                // The build can do it; the configuration has not asked for it.
                // ap.uc's ppsk guard (ap.uc:111) suppresses only its own
                // default, so on an iPSK network nothing writes sae_pwe by
                // itself - but a configured value passes through untouched,
                // because append_vars (ap.uc:202) writes it whenever it is
                // set. So it is set as the ordinary option it is.
                $say('sae_pwe',
                    'unset on a 6 GHz RADIUS-keyed SAE bss — this build supports H2E, '
                    .'but ap.uc writes no default while ppsk is set. Set sae_pwe=2.');
            }
        }

        // Fast transition on a network whose keys are per station.
        //
        // Two things have to hold, and neither is the default:
        //
        //   ft_psk_generate_local=0   the target of a roam cannot derive
        //                             PMK-R0 itself, because it has not seen
        //                             this station and holds no key of its
        //                             own to derive from
        //   r0kh / r1kh               and the key it fetches instead has to be
        //                             one both ends share. Left unset, ap.uc
        //                             derives it from md5(mobility_domain +
        //                             '/' + auth_secret) — the per access
        //                             point RADIUS secret, so no two agree.
        //
        // Measured on the fleet 2026-08-28: kalclients has both and roams,
        // kalnet has neither. The difference is which of four similarly named
        // 802.11r feature templates somebody picked — "802.11r PSK" sets the
        // flag to 1, "802.11r PSK SAE" sets it to 0 — which is not something a
        // network's roaming should turn on. kalinfra keeps the flag at 1 and
        // is right to: it has one passphrase for everybody, so local
        // derivation is exactly what it wants. That is why this asks about
        // per station keys and not about fast transition alone.
        $ft = false !== strpos($cfg['wpa_key_mgmt'] ?? '', 'FT-');
        if ($ft && $radiusKeys) {
            if ('0' !== ($cfg['ft_psk_generate_local'] ?? '')) {
                $say('ft_psk_generate_local',
                    'not 0 while every station has its own key — the target of a roam has '
                    .'nothing to derive PMK-R0 from', true);
            }
            if (empty($cfg['r0kh'])) {
                $say('r0kh',
                    'missing on a network with per station keys — ap.uc then derives the FT '
                    .'key from the per access point RADIUS secret and no two access points '
                    .'agree. apman:ft-key <network> --create', true);
            }
        }

        // OWE without protected management frames cannot work: the whole
        // point of OWE is an encrypted association, and 802.11 requires PMF
        // for it. hostapd sets it itself for an OWE-only bss, so this fires
        // where something else has written ieee80211w back down.
        if (false !== strpos($cfg['wpa_key_mgmt'] ?? '', 'OWE') && '2' !== ($cfg['ieee80211w'] ?? '')) {
            $say('ieee80211w', 'OWE requires protected management frames, this bss does not require them');
        }
        // Same for SAE. A WPA3 network that lets a station opt out of PMF is
        // not a WPA3 network.
        if ($sae && '2' !== ($cfg['ieee80211w'] ?? '')) {
            $say('ieee80211w', 'SAE without required management frame protection');
        }

        // A server nobody asks. Without a RADIUS key management method and
        // without macaddr_acl there is no path from this configuration to the
        // server it names, so the address and the shared secret sit in the
        // file doing nothing but looking configured.
        if (!empty($cfg['auth_server_addr']) && !$radiusKeys
            && '2' !== ($cfg['macaddr_acl'] ?? '')
            && false === strpos($cfg['wpa_key_mgmt'] ?? '', 'EAP')) {
            $say('auth_server_addr', 'a RADIUS server is configured but nothing in this bss ever asks it');
        }

        // Leftovers. For a network on iPSK the files exist and are empty; what
        // is in them is old key material in cleartext that nothing rewrites
        // while the interface is down.
        if ($radiusKeys) {
            foreach (['wpa_psk_file', 'sae_password_file'] as $opt) {
                $path = $cfg[$opt] ?? null;
                if (null !== $path && !empty($b['keyfiles'][$path])) {
                    $say($opt, $b['keyfiles'][$path].' bytes of stale keys — should be empty');
                }
            }
        }

        return $out;
    }

    /**
     * Rules that need more than one bss to see.
     *
     * @param array $blocks every bss of every access point
     */
    private function fleetRules(array $blocks)
    {
        $out = [];

        // The colour rule used to live here and compared the *configured*
        // value. That was wrong, and it reported collisions that did not
        // exist: hostapd assigns the colour itself and resolves collisions
        // itself, so every radio in the fleet runs a distinct one while every
        // configuration says 128, the schema default. What runs is read from
        // the status cache instead — see runningRadioRules().

        // The other half of an owe transition pair, named and not there. An
        // owe_transition_ifname pointing at nothing turns the encrypted half
        // into a network nobody finds.
        $byAp = [];
        foreach ($blocks as $b) {
            $byAp[$b['ap']][$b['bss']] = true;
        }
        foreach ($blocks as $b) {
            $partner = $b['cfg']['owe_transition_ifname'] ?? null;
            if (null === $partner || '' === $partner) {
                continue;
            }
            if (!isset($byAp[$b['ap']][$partner])) {
                $out[] = [
                    'group' => ($b['cfg']['ssid'] ?? '?').' / owe transition',
                    'option' => 'owe_transition_ifname',
                    'values' => [$partner.' — no such bss on this access point' => [$b['ap'].'/'.$b['bss']]],
                    'roaming' => false,
                ];
            }
        }

        // Logging at the most verbose level hostapd has. Level 0 is everything;
        // the schema default is 2. Every radio in the fleet ran at 0 until
        // 2026-08-22, on machines whose agent shares the log chain with the
        // radius server.
        $noisy = [];
        foreach ($blocks as $b) {
            $radio = $b['cfg']['_radio'] ?? [];
            foreach (['logger_syslog_level', 'logger_stdout_level'] as $opt) {
                if (isset($radio[$opt]) && '0' === $radio[$opt]) {
                    $noisy[$opt][$b['ap']] = true;
                }
            }
        }
        foreach ($noisy as $opt => $aps) {
            $out[] = [
                'group' => 'logging',
                'option' => $opt,
                'values' => ['0 — everything hostapd has to say' => array_keys($aps)],
                'roaming' => false,
            ];
        }

        return $out;
    }

    /**
     * What the radios are actually doing, as opposed to what they were told.
     *
     * Read from `status.device.<id>.ap_status`, which carries hostapd's own
     * `get_status` — the frequency it settled on and the colour it chose.
     * Neither is the configured value, and for the colour that is by design:
     * hostapd picks one and changes it when it sees a collision, so comparing
     * configurations answers a question nobody asked.
     *
     * Grouped by frequency, not by channel number. hostapd reports sometimes
     * the control and sometimes the centre channel, so the number alone does
     * not identify a radio's place in the band; the frequency does.
     */
    private function runningRadioRules()
    {
        $radios = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')->findAll() as $device) {
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            if (!$radio || !$ap) {
                continue;
            }
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            $ap_status = is_array($status) ? ($status['ap_status'] ?? null) : null;
            if (!is_array($ap_status) || !isset($ap_status['freq'])) {
                continue;
            }
            // one entry per radio, not per bss: the colour and the frequency
            // belong to the radio and every bss on it repeats them
            $radios[$ap->getName().'/'.$radio->getName()] = [
                'ap' => $ap->getName(),
                'radio' => $radio->getName(),
                'freq' => (int) $ap_status['freq'],
                'channel' => $ap_status['channel'] ?? null,
                // -1 is the driver saying it has no colour to report, which is
                // what a radio without HE says; it is not a value that collides
                'colour' => (isset($ap_status['bss_color']) && $ap_status['bss_color'] >= 0)
                    ? $ap_status['bss_color'] : null,
                'wanted' => $radio->getConfigChannel(),
            ];
        }

        $out = [];
        $byFreq = [];
        foreach ($radios as $r) {
            if (null !== $r['colour']) {
                $byFreq[$r['freq']][$r['colour']][$r['ap'].'/'.$r['radio']] = true;
            }
        }
        foreach ($byFreq as $freq => $colours) {
            foreach ($colours as $colour => $where) {
                if (count($where) < 2) {
                    continue;
                }
                $out[] = [
                    'group' => $freq.' MHz / bss colour',
                    'option' => 'he_bss_color',
                    'values' => [$colour.' — two radios on one frequency cannot be told apart'
                        => array_keys($where)],
                    'roaming' => false,
                ];
            }
        }

        // A radio that is not where it was told to be. Usually DFS moved it,
        // which is the system working — but it is worth seeing, because the
        // configuration and the air disagree and only one of them is checked
        // anywhere else.
        foreach ($radios as $r) {
            $wanted = (string) $r['wanted'];
            if ('' === $wanted || 'auto' === strtolower($wanted) || null === $r['channel']) {
                continue;
            }
            if ((string) $r['channel'] !== $wanted) {
                $out[] = [
                    'group' => 'channel',
                    'option' => $r['ap'].'/'.$r['radio'],
                    'values' => ['configured '.$wanted.', running '.$r['channel'].' ('.$r['freq'].' MHz)'
                        => [$r['ap'].'/'.$r['radio']]],
                    'roaming' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * Options we set that never arrive, because ucode writes its own value.
     *
     * The counterpart of the custom_cfg finding: there the container was wrong,
     * here the option is right and something downstream overwrites it. Both
     * fail in the same silent way — the configuration says one thing, the
     * access point does another, and nothing complains.
     */
    private function clobberedOptionRules()
    {
        $where = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\SSID')->findAll() as $ssid) {
            foreach ((array) $ssid->exportConfig() as $name => $value) {
                if (isset(self::CLOBBERED[$name]) && '' !== (string) $value) {
                    $where[$name]['network '.$ssid->getName()] = true;
                }
            }
        }
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')->findAll() as $device) {
            $config = $device->getConfig();
            if (!is_array($config)) {
                continue;
            }
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            foreach ($config as $name => $value) {
                if (isset(self::CLOBBERED[$name]) && '' !== (string) $value) {
                    $where[$name][($ap ? $ap->getName().'/' : '').$device->getName()] = true;
                }
            }
        }

        $out = [];
        foreach ($where as $name => $places) {
            [$line, $what] = self::CLOBBERED[$name];
            $out[] = [
                'group' => 'set, and overwritten',
                'option' => $name,
                'values' => [$what.' ('.$line.')' => array_keys($places)],
                'roaming' => false,
            ];
        }

        return $out;
    }

    /**
     * Options we set that the access point does not carry.
     *
     * The general form of the two findings that turned up by hand today. With
     * `custom_cfg` the container was wrong; with `start_disabled` the option was
     * right and ucode overwrote it. Both fail the same way — the configuration
     * says one thing and the device does another, silently — and both were
     * found only because somebody happened to grep for them.
     *
     * The check is empirical rather than a list, so it needs no maintaining: an
     * option is only compared when it appears **as a key** in at least one bss
     * block somewhere in the fleet. That proves ap.uc renders it under its own
     * name, and it keeps the many uci options that legitimately have no line of
     * their own — encryption, key, ppsk, band, network — out of the comparison
     * entirely, because they never appear as a key anywhere.
     *
     * @param array $blocks every bss of every access point, as parsed
     */
    private function arrivalRules(array $blocks)
    {
        // What ap.uc renders under its own name — learnt per network and band
        // rather than across the fleet, because many options render only under
        // a condition. `reassociation_deadline` is written inside the 802.11r
        // block, so every network without fast roaming is missing it and none
        // of them is wrong; compared fleet-wide it produced twenty-four
        // findings and every one was noise. The same network on two access
        // points, on the same band, should render the same way — that is a
        // disagreement worth a word.
        $rendered = [];
        $groupOf = [];
        foreach ($blocks as $i => $b) {
            $ssid = $b['cfg']['ssid'] ?? trim($b['cfg']['ssid2'] ?? '', '"');
            if ('' === $ssid) {
                continue;
            }
            $key = $ssid.' / '.($b['cfg']['_band'] ?? '?');
            $groupOf[$i] = $key;
            $rendered[$key] = $rendered[$key] ?? [];
            foreach (array_keys($b['cfg']) as $option) {
                if ('_' !== $option[0]) {
                    $rendered[$key][$option] = true;
                }
            }
        }

        // ifname to device, per access point
        $byIfname = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')->findAll() as $device) {
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            if ($ap && $device->ifname()) {
                $byIfname[$ap->getName()][$device->ifname()] = $device;
            }
        }

        $missing = [];
        $differs = [];
        $broken = [];
        foreach ($blocks as $i => $b) {
            $group = $groupOf[$i] ?? null;
            // one bss of a network on one band has nothing to disagree with
            if (null === $group || count($rendered[$group]) < 1) {
                continue;
            }
            $device = $byIfname[$b['ap']][$b['bss']] ?? null;
            if (!$device || !$device->getSsid()) {
                continue;
            }
            // The payload the provisioning actually sends, not what somebody
            // typed. The feature chain rewrites values on the way out — the
            // network says wpa_group_rekey 86400 and a feature makes it 3600 —
            // and comparing the typed value would report sixty-one bsses as
            // wrong when every one of them is running what we told it to.
            try {
                // getDeviceConfig() returns [the uci section, the extra
                // sections it needs]. The options are inside the first one, in
                // ->values; casting the pair itself gives an array of two and a
                // rule that compares nothing and reports nothing, which is what
                // the first version of this did.
                $built = $this->apService->getDeviceConfig($device);
                $wanted = (array) ($built[0]->values ?? []);
            } catch (\Throwable $e) {
                // Saying nothing here would be the same failure this rule
                // exists to catch: a bss whose configuration cannot even be
                // built is the strongest possible version of "what we think we
                // send is not what is running".
                $broken[$e->getMessage()][$b['ap'].'/'.$b['bss']] = true;
                continue;
            }
            if (!$wanted) {
                $broken['the built configuration has no options at all'][$b['ap'].'/'.$b['bss']] = true;
                continue;
            }
            $where = $b['ap'].'/'.$b['bss'];
            foreach ($wanted as $name => $value) {
                if (!isset($rendered[$group][$name]) || is_array($value) || is_object($value)) {
                    continue;
                }
                // An option set to nothing is not set; a secret is not compared,
                // because the parser masks it and a mask never matches.
                if ('' === (string) $value || in_array($name, self::SECRETS, true)
                    || in_array($name, self::MASKED, true)) {
                    continue;
                }
                if (!array_key_exists($name, $b['cfg'])) {
                    $missing[$name.' | '.$group][$where] = true;
                    continue;
                }
                if (!$this->sameValue($value, $b['cfg'][$name])) {
                    $differs[$name.' = '.$value.', running '.$b['cfg'][$name]][$where] = true;
                }
            }
        }

        $out = [];
        foreach ($broken as $why => $places) {
            $out[] = [
                'group' => 'configuration cannot be built',
                'option' => 'getDeviceConfig',
                'values' => [$why => array_keys($places)],
                'roaming' => false,
            ];
        }
        foreach ($missing as $what => $places) {
            [$name, $group] = explode(' | ', $what, 2);
            $out[] = [
                'group' => $group,
                'option' => $name,
                'values' => ['set for this bss, and this bss does not carry it — the same network '
                    .'renders it on another access point, so it is not a name that is ignored here'
                    => array_keys($places)],
                'roaming' => false,
            ];
        }
        foreach ($differs as $what => $places) {
            $out[] = [
                'group' => 'set, and changed on the way',
                'option' => explode(' = ', $what)[0],
                'values' => [$what => array_keys($places)],
                'roaming' => false,
            ];
        }

        return $out;
    }

    /**
     * Whether what we asked for and what is running are the same thing.
     *
     * uci writes booleans as 1 and 0 and people write them as true, on and yes;
     * hostapd.conf carries the number. A string in the file may be quoted where
     * ours is not. None of that is a difference worth reporting, and reporting
     * it would bury the ones that are.
     */
    private function sameValue($wanted, $running): bool
    {
        $norm = function ($v) {
            $v = trim((string) $v, " \t\"'");
            $lower = strtolower($v);
            if (in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                return '1';
            }
            if (in_array($lower, ['0', 'false', 'off', 'no'], true)) {
                return '0';
            }

            return $v;
        };

        return $norm($wanted) === $norm($running);
    }

    /**
     * The name we asked for and the name that is running.
     *
     * Two columns since 2026-08-22, and a difference between them means an
     * access point did not take the name provisioning gave it — which used to
     * be invisible, because the status message wrote over the wish.
     */
    /**
     * The address a bss was given, and the address it is using.
     *
     * A bss address is not decoration. It is the BSSID a station stores, the
     * name a neighbour report carries, the identity 802.11r keys are held
     * against, and the thing a MAC filter matches on. Two bsses sharing one is
     * an outage that presents as "some clients cannot connect, sometimes", and
     * nothing looked for it.
     *
     * Three questions, in order of how much trouble a wrong answer causes:
     * does anything have the same address as something else, is the address we
     * configured the address that is running, and is there an address at all.
     *
     * @param array $blocks every bss of every access point, as parsed
     */
    /**
     * The address a bss was given, and the address it is using.
     *
     * A bss address is not decoration. It is the BSSID a station stores, the
     * name a neighbour report carries, the identity 802.11r keys are held
     * against, and the thing a MAC filter matches on. Two bsses sharing one is
     * an outage that presents as "some clients cannot connect, sometimes", and
     * nothing looked for it.
     *
     * This half reads; addressFindings() decides, so the deciding can be tested
     * against cases the fleet does not currently have. A rule that is silent
     * because everything is right and a rule that is silent because it is
     * broken look exactly the same from outside.
     *
     * @param array $blocks every bss of every access point, as parsed
     */
    private function addressRules(array $blocks)
    {
        $rows = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')->findAll() as $device) {
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            $rows[] = [
                'ap' => $ap ? $ap->getName() : null,
                'productive' => $ap ? (bool) $ap->getIsProductive() : false,
                // a bss on a switched off radio is configured and cannot run,
                // which is a decision rather than a fault
                'radio_off' => $radio && '1' === (string) $radio->getConfigDisabled(),
                'name' => $device->getName(),
                'ifname' => (string) $device->ifname(),
                'address' => (string) $device->getAddress(),
                'enabled' => $device->getIsEnabled(),
                'reported' => is_array($status) && is_array($status['ap_status'] ?? null)
                    ? (string) ($status['ap_status']['bssid'] ?? '') : '',
            ];
        }

        $running = [];
        foreach ($blocks as $b) {
            if (isset($b['cfg']['bssid'])) {
                $running[$b['ap']][$b['bss']] = strtolower($b['cfg']['bssid']);
            }
        }

        return self::addressFindings($rows, $running);
    }

    /**
     * Three questions, in the order of how much trouble a wrong answer causes:
     * does anything share an address with something else, is the address we
     * configured the one that is running, and is there a configured address at
     * all.
     *
     * The last is not pedantry. Without one the driver picks, so the address
     * changes with the hardware and with the order the interfaces come up, and
     * every mac filter and neighbour report built on it goes stale without
     * saying so.
     *
     * @param array $rows    one per bss, as addressRules() builds them
     * @param array $running ap => ifname => the bssid in the generated config
     */
    public static function addressFindings(array $rows, array $running): array
    {
        $out = [];

        $byAddress = [];
        foreach ($rows as $r) {
            $address = strtolower($r['address']);
            if ('' === $address) {
                continue;
            }
            $byAddress[$address][($r['ap'] ? $r['ap'].'/' : '').$r['name']] = true;
        }
        foreach ($byAddress as $address => $where) {
            if (count($where) > 1) {
                $out[] = [
                    'group' => 'bss addresses',
                    'option' => $address,
                    'values' => ['two bsses cannot share an address — it is the bssid a station '
                        .'stores, the identity roaming keys are held against, and what a mac '
                        .'filter matches' => array_keys($where)],
                    'roaming' => true,
                ];
            }
        }

        $unset = [];
        foreach ($rows as $r) {
            if (!$r['ap'] || !$r['productive']) {
                continue;
            }
            if ($r['radio_off'] ?? false) {
                continue;
            }
            $where = $r['ap'].'/'.$r['name'];
            $address = strtolower($r['address']);

            if ('' === $address) {
                if ($r['enabled']) {
                    $unset[$where] = true;
                }
                continue;
            }
            if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $address)) {
                $out[] = [
                    'group' => 'bss addresses',
                    'option' => $r['name'],
                    'values' => ['"'.$r['address'].'" is not an address, and uci will take it anyway'
                        => [$where]],
                    'roaming' => false,
                ];
                continue;
            }

            $onDevice = $running[$r['ap']][$r['ifname']] ?? null;
            if (null !== $onDevice && $onDevice !== $address) {
                $out[] = [
                    'group' => 'bss addresses',
                    'option' => $r['name'],
                    'values' => ['configured '.$address.', in the running configuration '.$onDevice
                        => [$where]],
                    'roaming' => true,
                ];
            }

            $reported = strtolower($r['reported']);
            if ('' !== $reported && $reported !== $address) {
                $out[] = [
                    'group' => 'bss addresses',
                    'option' => $r['name'],
                    'values' => ['configured '.$address.', on the air '.$reported
                        .' — the second one is what stations see' => [$where]],
                    'roaming' => true,
                ];
            }
        }

        if ($unset) {
            $out[] = [
                'group' => 'bss addresses',
                'option' => 'not configured',
                'values' => ['no address was chosen for these, so the driver picks one and it '
                    .'changes with the hardware and with the order the interfaces come up — mac '
                    .'filters and neighbour reports built on it go stale without warning'
                    => array_keys($unset)],
                'roaming' => false,
            ];
        }

        return $out;
    }

    /**
     * The name a bss was given, and the name it is running under.
     *
     * An interface name is the hostapd ubus object, the key file path, the mqtt
     * status topic, the owe partner a bss names and the neighbour reports. All
     * five follow it, so a name nobody chose — one the access point invents,
     * which moves with the order the interfaces come up — takes all of them
     * with it.
     *
     * Reads here, decides in ifnameFindings().
     */
    private function ifnameRules(array $blocks = [])
    {
        $rows = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')->findAll() as $device) {
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            $rows[] = [
                'ap' => $ap ? $ap->getName() : null,
                'productive' => $ap ? (bool) $ap->getIsProductive() : false,
                'radio_off' => $radio && '1' === (string) $radio->getConfigDisabled(),
                'name' => $device->getName(),
                'wanted' => (string) $device->getIfname(),
                'seen' => (string) $device->getIfnameSeen(),
                'enabled' => $device->getIsEnabled(),
            ];
        }
        $onDevice = [];
        foreach ($blocks as $b) {
            $onDevice[$b['ap']][$b['bss']] = true;
        }

        return self::ifnameFindings($rows, $onDevice);
    }

    /**
     * @param array $rows     one per bss, as ifnameRules() builds them
     * @param array $onDevice ap => the interface names the access point runs
     */
    public static function ifnameFindings(array $rows, array $onDevice): array
    {
        $out = [];
        $perAp = [];
        $unnamed = [];
        $unknown = $onDevice;

        foreach ($rows as $r) {
            $where = ($r['ap'] ?? '?').'/'.$r['name'];
            $wanted = $r['wanted'];
            $seen = $r['seen'];

            if ('' !== $wanted && strlen($wanted) > \ApManBundle\Library\IfnameScheme::MAX_LENGTH) {
                $out[] = [
                    'group' => 'interface names',
                    'option' => $wanted,
                    'values' => [strlen($wanted).' characters — the kernel takes at most '
                        .\ApManBundle\Library\IfnameScheme::MAX_LENGTH
                        .', and the uci add that fails reverts the whole access point' => [$where]],
                    'roaming' => false,
                ];
            }
            if ('' !== $wanted && '' !== $seen && $wanted !== $seen) {
                $out[] = [
                    'group' => 'interface names',
                    'option' => $r['name'],
                    'values' => ['configured '.$wanted.', running '.$seen => [$where]],
                    'roaming' => false,
                ];
            }

            if (!$r['ap'] || !$r['productive'] || ($r['radio_off'] ?? false)) {
                continue;
            }
            if ('' === $wanted && $r['enabled']) {
                $unnamed[$where.('' !== $seen ? ' (running as '.$seen.')' : '')] = true;
            }
            $name = '' !== $seen ? $seen : $wanted;
            if ('' === $name) {
                continue;
            }
            $perAp[$r['ap']][$name][$where] = true;
            unset($unknown[$r['ap']][$name]);
        }

        foreach ($perAp as $apName => $names) {
            foreach ($names as $name => $where) {
                if (count($where) > 1) {
                    $out[] = [
                        'group' => 'interface names',
                        'option' => $name,
                        'values' => ['two bsses of one access point under one name — the second '
                            .'uci add overwrites the first, and a provisioning run is one '
                            .'transaction' => array_keys($where)],
                        'roaming' => false,
                    ];
                }
            }
        }
        if ($unnamed) {
            $out[] = [
                'group' => 'interface names',
                'option' => 'not configured',
                'values' => ['no name was chosen for these, so the access point names them and the '
                    .'name moves with the order the interfaces come up — the hostapd ubus object, '
                    .'the key file and the status topic all follow it' => array_keys($unnamed)],
                'roaming' => false,
            ];
        }
        foreach ($unknown as $apName => $names) {
            $names = array_keys($names);
            if ($names) {
                $out[] = [
                    'group' => 'interface names',
                    'option' => 'not ours',
                    'values' => ['running on the access point and matching no bss we know — either '
                        .'somebody made it by hand, or we renamed one and left the old behind'
                        => array_map(function ($n) use ($apName) { return $apName.'/'.$n; }, $names)],
                    'roaming' => false,
                ];
            }
        }

        return $out;
    }

    private function missingBssRules(array $aps, array $blocks)
    {
        $running = [];
        foreach ($blocks as $b) {
            $running[$b['ap'].'/'.$b['bss']] = true;
        }
        $out = [];
        foreach ($aps as $ap) {
            $tree = $this->stateTree->ap($ap);
            if (in_array($tree['state'], [\ApManBundle\Library\NodeState::AP_OFFLINE,
                \ApManBundle\Library\NodeState::AP_UNKNOWN], true)) {
                // nothing was read from it, so nothing is missing on it
                continue;
            }
            foreach ($ap->getRadios() as $radio) {
                foreach ($radio->getDevices() as $device) {
                    $ssid = $device->getSsid();
                    $ifname = $device->ifname();
                    if (!$ssid || !$ifname || !$device->getIsEnabled() || !$radio->getIsEnabled()) {
                        continue;
                    }
                    if (isset($running[$ap->getName().'/'.$ifname])) {
                        continue;
                    }
                    $out[] = [
                        'group' => $ssid->getName().' / not running',
                        'option' => $ifname.' on '.$ap->getName(),
                        'values' => ['configured here, no bss of that name in the running configuration'
                            => [$ap->getName().'/'.$ifname]],
                        'roaming' => false,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Raw options that reach into what a feature already owns.
     *
     * hostapd_bss_options and custom_cfg are pasted into the interface section
     * verbatim, which makes them invisible to everything that reasons about
     * the configuration. On 2026-08-21 a leftover wpa_psk_radius=2 in one of
     * them survived every rewrite of the network and answered stations with a
     * setting nobody could find, because no page and no check ever showed that
     * table.
     */
    private function rawOptionRules()
    {
        // The options the security model and the iPSK feature set themselves.
        // A raw line for one of these is a second author for the same value,
        // and the loser is whoever reads the configuration later.
        //
        // sae_pwe is deliberately NOT in this list any more. It was, back when
        // the answer to it was "never" and the feature's job was to keep it
        // out. Since the patched hostapd it is a per network decision that
        // nothing sets on its own - ap.uc suppresses its default while ppsk is
        // set, and IpskFeatureService will not set it either, because the
        // feature is fleet-wide and the build is per access point. It is set
        // as the ordinary wifi-iface option it is; the rule below, which wants
        // a schema option rather than a raw line, is right about it.
        $owned = ['wpa_psk_radius', 'macaddr_acl', 'auth_server_addr',
            'auth_server_port', 'auth_server_shared_secret',
            'wpa_passphrase', 'wpa_psk', 'wpa_psk_file', 'sae_password',
            'sae_password_file', 'wpa_key_mgmt', 'ieee80211w', 'ppsk'];
        $out = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\SSID')->findAll() as $ssid) {
            foreach ($ssid->getConfigLists() as $list) {
                if (!in_array($list->getName(), ['hostapd_bss_options', 'custom_cfg'], true)) {
                    continue;
                }
                // custom_cfg is not a uci option and is in neither schema, so
                // ap.uc does not know it and none of it is written into the
                // generated configuration. Whatever is in it has never had an
                // effect — which is worse than a wrong value, because it looks
                // like a setting. hostapd_bss_options is the one that works.
                if ('custom_cfg' === $list->getName() && count($list->getOptions())) {
                    $lines = [];
                    foreach ($list->getOptions() as $option) {
                        $lines[] = trim((string) $option->getValue());
                    }
                    $out[] = [
                        'group' => $ssid->getName().' / raw options',
                        'option' => 'custom_cfg',
                        'values' => [implode(', ', $lines) => [
                            'custom_cfg reaches no access point — ap.uc does not know the option. '
                            .'Use hostapd_bss_options, or the schema option where there is one']],
                        'roaming' => false,
                    ];
                    continue;
                }
                foreach ($list->getOptions() as $option) {
                    $raw = trim((string) $option->getValue());
                    $name = trim(explode('=', $raw, 2)[0]);
                    if (in_array($name, $owned, true)) {
                        $out[] = [
                            'group' => $ssid->getName().' / raw options',
                            'option' => $list->getName().': '.$name,
                            'values' => [$raw => ['configured in the controller, not on an access point']],
                            'roaming' => false,
                        ];
                        continue;
                    }
                    // A raw line for something the schema models is a value
                    // hidden from the editor, from this check, and from the
                    // person who set the same option one field higher up.
                    // Worse when the schema puts it on the radio: written per
                    // bss it lands in the generated file once for every bss,
                    // and hostapd takes the last one. Eleven copies of
                    // bss_load_update_period=50 sat under one of 60 that ap.uc
                    // had written itself, on every bss of ap-av-attic, until
                    // 2026-08-22.
                    $section = $this->schema->sectionOf($name);
                    if (null === $section) {
                        continue;
                    }
                    $out[] = [
                        'group' => $ssid->getName().' / raw options',
                        'option' => $list->getName().': '.$name,
                        'values' => [$raw => [WirelessSchemaService::DEVICE === $section
                            ? 'the schema has this as a radio option — set it on the radio, not per bss'
                            : 'the schema has this as an option of its own — set it there, not as a raw line']],
                        'roaming' => false,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * An access list made of invented addresses.
     *
     * It blocks nobody and reads like a rule. kalinfra carried
     * 11:22:33:44:55:66 and :67 behind macfilter=deny until 2026-08-22, and
     * anyone reading the network page saw a MAC filter that was doing
     * something.
     *
     * Checked against what the controller holds rather than against the
     * running configuration: this is a value somebody typed, and the place it
     * comes back is the editor.
     */
    private function macListRules()
    {
        $out = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\SSID')->findAll() as $ssid) {
            $filter = null;
            foreach ($ssid->getConfigOptions() as $option) {
                if ('macfilter' === $option->getName()) {
                    $filter = (string) $option->getValue();
                }
            }
            if (null === $filter || '' === $filter) {
                continue;
            }
            foreach ($ssid->getConfigLists() as $list) {
                if ('maclist' !== $list->getName()) {
                    continue;
                }
                $entries = [];
                foreach ($list->getOptions() as $option) {
                    $entries[] = trim((string) $option->getValue());
                }
                if (!$entries) {
                    continue;
                }
                $placeholders = array_filter($entries, function ($mac) {
                    return (bool) preg_match('/^(11:22:33:44:55:|00:00:00:00:00:|de:ad:be:ef:)/i', $mac);
                });
                if (count($placeholders) !== count($entries)) {
                    continue;
                }
                $out[] = [
                    'group' => $ssid->getName().' / raw options',
                    'option' => 'maclist with macfilter='.$filter,
                    'values' => [implode(', ', $entries) => ['placeholder addresses — this filter blocks nobody']],
                    'roaming' => false,
                ];
            }
        }

        return $out;
    }

    private function fetchConfig($ap)
    {
        $opts = new \stdClass();
        $opts->command = '/bin/sh';
        // The key files come along, by size only. For a network on iPSK they
        // must be empty — the keys live in the access point's own RADIUS, and
        // anything still in the file is a leftover the config alone cannot
        // show. Two access points carried 2659 bytes of real keys in cleartext
        // on 2026-08-22 for exactly that reason: their radios were off, so
        // nothing regenerated the file when the network was switched over.
        $opts->params = ['-c',
            'for f in /var/run/hostapd-phy*.conf; do echo "###FILE $f"; cat "$f"; done;'
            .' for f in /var/run/hostapd-*.psk /var/run/hostapd-*.sae; do'
            .' [ -f "$f" ] && echo "###KEYFILE $f $(wc -c < "$f")"; done; true'];
        // A dump of eleven bss blocks takes a moment to produce and to carry,
        // which is longer than a status call and worth saying out loud
        $res = $this->ubus->call($ap, 'file', 'exec', $opts, 20);
        if (!$res->isOk()) {
            $this->logger->info('WlanConsistencyService: '.$ap->getName().' gave no configuration: '.$res->why());

            return null;
        }
        $stat = $res->data;

        return is_object($stat) && property_exists($stat, 'stdout') ? $stat->stdout
            : (is_array($stat) ? ($stat['stdout'] ?? null) : null);
    }

    /**
     * One file holds several bss blocks: the first starts at interface=, every
     * further one at bss=. Values must not leak across block boundaries, and
     * the radio level preamble is not a bss.
     */
    private function parse($text, &$keyfiles = null)
    {
        $out = [];
        $cur = null;
        $name = null;
        $preamble = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (0 === strpos($line, '###KEYFILE')) {
                $parts = preg_split('/\s+/', $line);
                if (null !== $keyfiles && isset($parts[1], $parts[2])) {
                    $keyfiles[$parts[1]] = (int) $parts[2];
                }
                continue;
            }
            if (0 === strpos($line, '###FILE')) {
                // Flush first. Without this the block still being read when
                // the next file starts is thrown away — the last bss of every
                // file but the last one, silently, for as long as this parser
                // existed. It cost nothing visible because the comparison only
                // ever saw the remaining members and found them consistent;
                // the rule about networks that are configured and not running
                // is what surfaced it.
                if (null !== $cur && null !== $name) {
                    $out[$name] = $cur;
                }
                // a new file means a new radio: hw_mode and op_class start over
                $preamble = [];
                $cur = null;
                $name = null;
                continue;
            }
            if ('' === $line || '#' === $line[0] || false === strpos($line, '=')) {
                continue;
            }
            list($k, $v) = explode('=', $line, 2);
            if ('interface' === $k || 'bss' === $k) {
                if (null !== $cur && null !== $name) {
                    $out[$name] = $cur;
                }
                // the radio level settings travel with every bss of that
                // radio: channel, colour and the log level are per phy, and a
                // rule about them has only a bss to hold on to
                $cur = ['_band' => $this->bandOf($preamble), '_radio' => $preamble];
                $name = $v;
                $cur[$k] = $v;
                continue;
            }
            if (null === $name) {
                // radio level settings before the first interface=
                $preamble[$k] = $v;
                continue;
            }
            $cur[$k] = $v;
        }
        if (null !== $cur && null !== $name) {
            $out[$name] = $cur;
        }

        return $out;
    }

    /** 6 GHz mandates pmf and forbids the sha1 akms, so it is its own group */
    private function bandOf(array $radio)
    {
        $op = $radio['op_class'] ?? null;
        if (null !== $op && ctype_digit($op) && (int) $op >= 131 && (int) $op <= 136) {
            return '6g';
        }

        return ($radio['hw_mode'] ?? '') === 'g' ? '2g' : '5g';
    }
}
