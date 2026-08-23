<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Library\NodeState;

/**
 * What each access point is plugged into, and on which port.
 *
 * The controller has always been able to run `lldpcli show neighbors` on an
 * access point — one admin action did, printed the text and called exit(). The
 * text was never parsed, never stored and never shown anywhere a person would
 * look, so the one question it answers, "which switch port is this access point
 * on", has been a matter of walking to the rack.
 *
 * `-f json ... details` answers rather more than that. Measured across the
 * fleet on 23.08.2026: the switch's name, model and management address, the
 * port number and its description, the negotiated link — and where the switch
 * feeds the access point, the PoE class and how many milliwatts it has set
 * aside for it. ap-av-attic hangs on port 6 of a GS1900-10HP at 1000BaseTFD
 * with 31.2 W allocated as class 4.
 *
 * Cached for an hour: cabling does not move on its own, and an access point
 * that has just been moved is one somebody is standing next to, who can ask
 * for it again.
 */
class TopologyService
{
    public const TTL = 3600;

    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly ApUbusService $ubus,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
        private readonly StateTreeService $stateTree,
    ) {
    }

    /**
     * The uplinks of one access point, from the cache or from the access point.
     */
    public function of(AccessPoint $ap, bool $fresh = false): array
    {
        $key = 'topology.'.$ap->getName();
        if (!$fresh) {
            $hit = $this->cacheFactory->getCacheItemValue($key);
            if (is_array($hit)) {
                return $hit;
            }
        }

        // An access point nobody has heard from cannot answer, and asking it
        // anyway is ten seconds of waiting to be told what the state tree
        // already knew. One offline access point was the entire cost of this
        // page: seven cached answers and one timeout.
        $node = $this->stateTree->ap($ap);
        if (in_array($node['state'], [NodeState::AP_OFFLINE, NodeState::AP_UNKNOWN], true)) {
            return ['ok' => false, 'ap' => $ap->getName(), 'links' => [],
                'error' => 'it is '.$node['state_name']
                    .($node['since'] ? ' since '.date('d.m. H:i', (int) $node['since']) : '')
                    .', so it cannot say what it is plugged into'];
        }

        $opts = new \stdClass();
        $opts->command = '/usr/sbin/lldpcli';
        $opts->params = ['-f', 'json', 'show', 'neighbors', 'details'];
        $res = $this->ubus->call($ap, 'file', 'exec', $opts, 10);
        if (!$res->isOk()) {
            // Not cached: a failure is a moment, and caching it for an hour
            // would turn one busy access point into an hour of not knowing.
            return ['ok' => false, 'ap' => $ap->getName(), 'error' => $res->why(), 'links' => []];
        }
        $stdout = is_object($res->data) ? (string) ($res->data->stdout ?? '') : '';
        $out = $this->parse($stdout) + ['ap' => $ap->getName(), 'ts' => time()];
        if ($out['ok']) {
            $this->cacheFactory->addCacheItem($key, $out, self::TTL);
        }

        return $out;
    }

    /**
     * lldpcli's json, flattened to the handful of things worth showing.
     *
     * Pure, because lldpcli's shape is the part that will change under us: the
     * capability block is an object for a switch that is only a bridge and an
     * array for one that also routes, which is the kind of thing that is much
     * cheaper to pin down with a fixture than with an access point.
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['lldp']['interface'])) {
            return ['ok' => false, 'error' => 'lldpcli said nothing this could be read out of',
                'links' => []];
        }
        $interfaces = $data['lldp']['interface'];
        // One neighbour comes back as an object, several as a list of objects.
        if (isset($interfaces[0])) {
            $merged = [];
            foreach ($interfaces as $entry) {
                if (is_array($entry)) {
                    $merged += $entry;
                }
            }
            $interfaces = $merged;
        }

        $links = [];
        foreach ($interfaces as $local => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $chassis = $entry['chassis'] ?? [];
            $name = is_array($chassis) ? (string) array_key_first($chassis) : '';
            $box = is_array($chassis) ? ($chassis[$name] ?? []) : [];
            $port = $entry['port'] ?? [];

            $power = is_array($port['power'] ?? null) ? $port['power'] : [];
            $allocated = isset($power['allocated']) ? (int) $power['allocated'] : null;

            $links[] = [
                'local' => (string) $local,
                'switch' => '' === $name ? null : $name,
                'switch_mac' => isset($box['id']['value']) ? strtolower((string) $box['id']['value']) : null,
                'model' => isset($box['descr']) ? (string) $box['descr'] : null,
                'mgmt_ip' => isset($box['mgmt-ip']) ? (string) $box['mgmt-ip'] : null,
                'port' => isset($port['id']['value']) ? (string) $port['id']['value'] : null,
                'port_descr' => isset($port['descr']) ? (string) $port['descr'] : null,
                'link' => isset($port['auto-negotiation']['current'])
                    ? (string) $port['auto-negotiation']['current'] : null,
                // milliwatts, which is what lldp carries; 31200 is 31.2 W
                'poe_mw' => $allocated ?: null,
                'poe_class' => isset($power['class']) ? (string) $power['class'] : null,
                'poe_from' => isset($power['device-type']) ? (string) $power['device-type'] : null,
                'age' => isset($entry['age']) ? (string) $entry['age'] : null,
            ];
        }

        usort($links, fn ($a, $b) => [$a['switch'], $a['local']] <=> [$b['switch'], $b['local']]);

        return ['ok' => true, 'links' => $links];
    }

    /**
     * The whole fleet, grouped by what it hangs on.
     *
     * An access point that could not be asked is kept in a group of its own
     * rather than dropped: "we do not know where this one is plugged in" is the
     * answer somebody is looking for as often as the port number.
     */
    public function fleet(bool $fresh = false): array
    {
        $aps = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findBy([], ['name' => 'ASC']);

        $switches = [];
        $unknown = [];
        foreach ($aps as $ap) {
            $report = $this->of($ap, $fresh);
            if (!$report['ok'] || !$report['links']) {
                $unknown[] = ['ap' => $ap, 'name' => $ap->getName(),
                    'productive' => $ap->getIsProductive(),
                    'why' => $report['error'] ?? 'it has no lldp neighbour on any interface'];
                continue;
            }
            foreach ($report['links'] as $link) {
                $key = $link['switch'] ?: ($link['switch_mac'] ?: 'unnamed');
                if (!isset($switches[$key])) {
                    $switches[$key] = [
                        'name' => $link['switch'],
                        'mac' => $link['switch_mac'],
                        'model' => $link['model'],
                        'mgmt_ip' => $link['mgmt_ip'],
                        'ports' => [],
                        'watts' => 0.0,
                    ];
                }
                $switches[$key]['ports'][] = ['ap' => $ap, 'name' => $ap->getName(),
                    'productive' => $ap->getIsProductive(), 'age' => $report['ts'] ?? null] + $link;
                if ($link['poe_mw']) {
                    $switches[$key]['watts'] += $link['poe_mw'] / 1000;
                }
            }
        }
        foreach ($switches as &$sw) {
            usort($sw['ports'], function ($a, $b) {
                // Port numbers where they are numbers, names where they are not:
                // "6" and "two-gigabitEthernet 1/0/19" are both port ids here.
                $an = is_numeric($a['port']) ? (int) $a['port'] : null;
                $bn = is_numeric($b['port']) ? (int) $b['port'] : null;

                return null !== $an && null !== $bn
                    ? $an <=> $bn
                    : (string) $a['port'] <=> (string) $b['port'];
            });
        }
        unset($sw);
        ksort($switches);

        return ['switches' => $switches, 'unknown' => $unknown];
    }
}
