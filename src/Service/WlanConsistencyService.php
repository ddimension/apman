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

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \ApManBundle\Factory\CacheFactory $cacheFactory
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->cacheFactory = $cacheFactory;
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
        $aps = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findBy(['IsProductive' => true]);
        $blocks = [];
        $errors = [];
        foreach ($aps as $ap) {
            $conf = $this->fetchConfig($ap);
            if (null === $conf) {
                $errors[] = $ap->getName().': config not readable';
                continue;
            }
            foreach ($this->parse($conf) as $bss => $cfg) {
                $blocks[] = ['ap' => $ap->getName(), 'bss' => $bss, 'cfg' => $cfg];
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
            // secrets are compared, never shown
            foreach (self::SECRETS as $opt) {
                $seen = [];
                foreach ($members as $m) {
                    if (!isset($m['cfg'][$opt])) {
                        continue;
                    }
                    $seen[substr(hash('sha256', $m['cfg'][$opt]), 0, 8)][] = $m['ap'].'/'.$m['bss'];
                }
                if (count($seen) > 1) {
                    $findings[] = ['group' => $key, 'option' => $opt.' (hash)', 'values' => $seen, 'roaming' => true];
                }
            }
        }

        usort($findings, function ($a, $b) {
            return ($b['roaming'] <=> $a['roaming']) ?: strcmp($a['group'], $b['group']);
        });

        return ['ts' => time(), 'findings' => $findings, 'coverage' => $coverage, 'errors' => $errors];
    }

    private function fetchConfig($ap)
    {
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            return null;
        }
        $opts = new \stdClass();
        $opts->command = '/bin/sh';
        $opts->params = ['-c', 'for f in /var/run/hostapd-phy*.conf; do echo "###FILE $f"; cat "$f"; done'];
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
    private function parse($text)
    {
        $out = [];
        $cur = null;
        $name = null;
        $preamble = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (0 === strpos($line, '###FILE')) {
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
                $cur = ['_band' => $this->bandOf($preamble)];
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
