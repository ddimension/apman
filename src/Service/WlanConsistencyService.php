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

    /** these carry key material, show the tail only */
    public const MASKED = ['r0kh', 'r1kh'];

    private $logger;
    private $doctrine;
    private $rpcService;
    private $cacheFactory;
    private $stateTree;
    private $schema;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \ApManBundle\Factory\CacheFactory $cacheFactory,
        StateTreeService $stateTree,
        WirelessSchemaService $schema
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->cacheFactory = $cacheFactory;
        $this->stateTree = $stateTree;
        $this->schema = $schema;
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
        foreach ($this->ifnameRules() as $f) {
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

        // sae_pwe=2 is hash-to-element only. Rolled out on 2026-08-21 it threw
        // an entire fleet of clients off with status 126 and they could not
        // come back; 6 GHz needs it, everything else must not have it.
        if ('2' === ($cfg['sae_pwe'] ?? '') && '6g' !== ($cfg['_band'] ?? '')) {
            $say('sae_pwe', '2 (hash-to-element only) — legacy clients cannot associate', true);
        }

        // A key that arrives over RADIUS has no PT, so it can do no H2E, and
        // 6 GHz permits nothing else. The combination cannot work at all — and
        // "cannot work" turned out to be literal. Measured on ap-av-grwz
        // 2026-08-22: wap-kc2 beacons on 6055 MHz with SAE FT-SAE,
        // wpa_psk_radius=2 and no sae_pwe, and has never had a station. It is
        // not a network configured wrongly, it is a network nobody can enter,
        // advertised in every scan.
        if ($radiusKeys && $sae && '6g' === ($cfg['_band'] ?? '')) {
            $say('wpa_psk_radius on 6 GHz SAE',
                'keys delivered over RADIUS carry no PT, and 6 GHz requires H2E — '
                .'this bss can never admit a station');
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
     * The name we asked for and the name that is running.
     *
     * Two columns since 2026-08-22, and a difference between them means an
     * access point did not take the name provisioning gave it — which used to
     * be invisible, because the status message wrote over the wish.
     */
    private function ifnameRules()
    {
        $out = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\Device')->findAll() as $device) {
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            $where = ($ap ? $ap->getName() : '?').'/'.$device->getName();
            $wanted = (string) $device->getIfname();
            $seen = (string) $device->getIfnameSeen();

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
                    'option' => $device->getName(),
                    'values' => ['configured '.$wanted.', running '.$seen => [$where]],
                    'roaming' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * A network that is configured here and is not running there.
     *
     * The comparison above only ever sees what runs, so a bss that never came
     * up is invisible to it — the group simply has one member fewer and looks
     * perfectly consistent. That is the failure that took a whole evening to
     * find: one access point where the network was absent while every other
     * one served it, and no page said so.
     *
     * A bss the tree calls DISABLED is not a finding. Somebody switched it
     * off, and reporting a decision back as drift is how a check teaches
     * people to ignore it.
     */
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
        // the options the security model and the iPSK feature set themselves
        $owned = ['wpa_psk_radius', 'macaddr_acl', 'auth_server_addr',
            'auth_server_port', 'auth_server_shared_secret', 'sae_pwe',
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
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            return null;
        }
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
        $stat = $session->call('file', 'exec', $opts);
        if (!is_object($stat) || !property_exists($stat, 'stdout')) {
            return null;
        }

        return $stat->stdout;
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
