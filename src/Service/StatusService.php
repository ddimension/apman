<?php

namespace ApManBundle\Service;

/**
 * Assembles the fleet status that the views render: the cached per device state
 * published by the apman agents, enriched with dhcp/neighbour data, reverse dns
 * names and, on demand, the probe heatmap.
 *
 * Extracted from DefaultController so grid, clients view, ap detail and any
 * future api build on the same data instead of each assembling their own.
 */
class StatusService
{
    private $logger;
    private $doctrine;
    private $cacheFactory;
    private $apservice;
    private $firewallUrl;
    private $firewallUser;
    private $firewallPassword;

    /** reverse lookups are slow here, so cache them and spread the misses */
    private const PTR_TTL = 86400;
    private const PTR_TTL_NEGATIVE = 900;
    private const PTR_RESOLVE_PER_REQUEST = 4;
    private const NEIGHBOR_TTL = 30;

    private static function ptrCacheKey($ip)
    {
        return 'dns.ptr.'.str_replace([':', '.'], '_', $ip);
    }

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Factory\CacheFactory $cacheFactory,
        AccessPointService $apservice,
        $firewallUrl,
        $firewallUser,
        $firewallPassword
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->cacheFactory = $cacheFactory;
        $this->apservice = $apservice;
        $this->firewallUrl = $firewallUrl;
        $this->firewallUser = $firewallUser;
        $this->firewallPassword = $firewallPassword;
    }

    private function ptrQuery($ip, $mac)
    {
        return ['mac' => $mac, 'name' => \Amp\Dns\query($ip, \Amp\Dns\DnsRecord::PTR)];
    }

    /** The neighbour table, read only: dhcp leases plus firewall neighbours. */
    private function neighborsFromCache(): array
    {
        $cached = $this->cacheFactory->getCacheItemValue('status.neighbors');

        return is_array($cached) ? $cached : [];
    }

    /**
     * Rebuild the neighbour table when its entry has expired. Runs from
     * apman:grid-upkeep, a process of its own: the dhcp-lease-list exec and
     * the two rpc round trips to the firewall must never sit inside a page
     * load or the subscriber loop.
     */
    public function refreshNeighbors(\ApManBundle\Service\wrtJsonRpc $rpc): void
    {
        $neighborCacheKey = 'status.neighbors';
        if (is_array($this->cacheFactory->getCacheItemValue($neighborCacheKey))) {
            return;
        }
        $neighbors = [];
        $result = null;
        // read dhcpd leases
        exec('dhcp-lease-list  --parsable', $lines, $result);
        if (0 == $result) {
            foreach ($lines as $line) {
                if ('MAC ' != substr($line, 0, 4)) {
                    continue;
                }
                $data = explode(' ', $line);
                $neighbors[$data[1]]['ip'] = $data[3];
                if ('-NA-' != $data[5]) {
                    $neighbors[$data[1]]['name'] = $data[5];
                }
            }
        }
        // The firewall, not an access point: it has no apman agent and no
        // transport column, so this is one of the two places that stay on HTTP
        // by necessity rather than by choice. The other is the LuCI login in
        // CustomActionsController, where the session has to end up in the
        // user's own browser.
        if ($this->firewallUrl) {
            $session = $rpc->login($this->firewallUrl, $this->firewallUser, $this->firewallPassword);
            if (false !== $session) {
                // Read dnsmasq leases
                $opts = new \stdclass();
                $opts->command = 'cat';
                $opts->params = ['/tmp/dhcp.leases'];
                $stat = $session->call('file', 'exec', $opts);
                if (is_object($stat) && property_exists($stat, 'stdout') && is_array($stat->stdout)) {
                    $lines = explode("\n", $stat->stdout);
                    foreach ($lines as $line) {
                        $ds = explode(' ', $line);
                        if (!array_key_exists(3, $ds)) {
                            continue;
                        }
                        $mac = strtolower($ds[1]);
                        if (strlen($mac)) {
                            if (array_key_exists($mac, $neighbors) && array_key_exists('name', $neighbors[$mac])) {
                                continue;
                            }
                            $neighbors[$mac] = ['ip' => $ds[2], 'name' => $ds[3]];
                        }
                    }
                }
                // Read neighbor information
                $opts = new \stdclass();
                $opts->command = '/sbin/ip';
                $opts->params = ['-j', '-4', 'neighb'];
                $stat = $session->call('file', 'exec', $opts);
                if (is_object($stat)) {
                    $rows = json_decode($stat->stdout, true);
                    foreach ($rows as $row) {
                        if (!isset($row['lladdr'])) {
                            continue;
                        }
                        $mac = strtolower($row['lladdr']);
                        if (strlen($mac)) {
                            if (!isset($neighbors[$mac])) {
                                $neighbors[$mac] = [];
                            }
                            $neighbors[$mac]['ip'] = $row['dst'];
                        }
                    }
                }
            }
        }
        $this->cacheFactory->addCacheItem($neighborCacheKey, $neighbors, self::NEIGHBOR_TTL);
    }

    /**
     * Reverse lookups, budgeted: each costs up to a second, so a run resolves
     * at most $budget unknowns and the rest wait for the next one. Runs from
     * apman:grid-upkeep; the views only read the results.
     */
    public function resolvePtrs(int $budget = self::PTR_RESOLVE_PER_REQUEST): void
    {
        $neighbors = $this->neighborsFromCache();
        $pending = [];
        foreach ($neighbors as $mac => $neighbor) {
            if (empty($neighbor['ip'])) {
                continue;
            }
            if (null !== $this->cacheFactory->getCacheItemValue(self::ptrCacheKey($neighbor['ip']))) {
                continue;
            }
            if (count($pending) < $budget) {
                $pending[$mac] = $neighbor['ip'];
            }
        }
        if (!$pending) {
            return;
        }
        $ips = [];
        foreach ($pending as $mac => $ip) {
            $ips[$mac] = \Amp\async(fn () => $this->ptrQuery($ip, $mac));
        }
        $rres = \Amp\Future\awaitAll($ips);
        foreach (($rres[1] ?? []) as $mac => $result) {
            $name = '';
            if (isset($result['name'][0]) && is_object($result['name'][0])) {
                $name = (string) $result['name'][0]->getValue();
            }
            $this->cacheFactory->addCacheItem(
                self::ptrCacheKey($pending[$mac]),
                $name,
                '' === $name ? self::PTR_TTL_NEGATIVE : self::PTR_TTL
            );
        }
        // no reverse zone, NXDOMAIN or timeout: remember that too, an
        // unresolvable address costs the same second on every run
        foreach (($rres[0] ?? []) as $mac => $error) {
            $this->cacheFactory->addCacheItem(
                self::ptrCacheKey($pending[$mac]),
                '',
                self::PTR_TTL_NEGATIVE
            );
        }
    }

    public function getStatusDump(\ApManBundle\Service\wrtJsonRpc $rpc, $withHeatmap = true)
{
        $logger = $this->logger;
        $apsrv = $this->apservice;
        $doc = $this->doctrine;
        $em = $doc->getManager();

        // The neighbour table is built by apman:grid-upkeep, never on request:
        // a cache miss here would mean dhcp-lease-list plus two rpc round
        // trips to the firewall inside a page load. A miss renders without
        // names; the next upkeep run fills them.
        $neighbors = $this->neighborsFromCache();

        $aps = $doc->getRepository('ApManBundle\Entity\AccessPoint')->findAll();
        // one redis round trip for all device states instead of one per device
        $statusKeys = [];
        foreach ($aps as $ap) {
            foreach ($ap->getRadios() as $radio) {
                foreach ($radio->getDevices() as $device) {
                    $statusKeys[] = 'status.device.'.$device->getId();
                }
            }
        }
        $deviceStatus = $statusKeys ? $this->cacheFactory->getMultipleCacheItemValues($statusKeys) : [];
        #$logger->debug('Cache', ['cache' => $cache]);
        #$logger->debug('Logging in to all APs');d
        $sessions = [];
        $data = [];
        $history = [];
        $macs = [];
        foreach ($aps as $ap) {
            $sessionId = $ap->getName();
            $data[$sessionId] = [];
            $history[$sessionId] = [];
            foreach ($ap->getRadios() as $radio) {
                foreach ($radio->getDevices() as $device) {
                    $delat = 0;
                    $status = $deviceStatus['status.device.'.$device->getId()] ?? null;
                    $ifname = $device->ifname();
                    if (null === $status) {
                        continue;
                    }
                    if (!isset($status['info'])) {
                        continue;
                    }
		
		    //$ssid = ap_status']['ssid']; 

                    $data[$sessionId][$ifname] = [];
                    $data[$sessionId][$ifname]['board'] = $this->cacheFactory->getCacheItemValue('status.ap.'.$ap->getId());
                    $data[$sessionId][$ifname]['info'] = $status['info'];
                    $data[$sessionId][$ifname]['assoclist'] = [];
                    $data[$sessionId][$ifname]['deviceId'] = $device->getId();
                    if (array_key_exists('assoclist', $status)) {
                        foreach ($status['assoclist']['results'] as $entry) {
                            $mac = strtolower($entry['mac']);
			    $entry['ssid'] = $device->getSSID()->getName();
                            $data[$sessionId][$ifname]['assoclist'][$mac] = $entry;
                            $macs[$mac] = true;
                        }
                    }
                    $data[$sessionId][$ifname]['clients'] = [];
                    if (array_key_exists('clients', $status)) {
                        if (array_key_exists('clients', $status['clients'])) {
                            $data[$sessionId][$ifname]['clients'] = $status['clients']['clients'];
                        }
                    }
                    $data[$sessionId][$ifname]['clientstats'] = $status['stations'];
                    // what the client actually negotiated, from the hostapd
                    // control channel (agent >= 56-4)
                    $data[$sessionId][$ifname]['sta_ctrl'] = $status['sta_ctrl'] ?? [];
                    $data[$sessionId][$ifname]['mib'] = $status['mib'] ?? [];
                    // carried through so the views can tell how old the state is
                    $data[$sessionId][$ifname]['timestamp'] = $status['timestamp'] ?? null;
                    // measured against our clock, not the access point's
                    $data[$sessionId][$ifname]['received'] = $status['received'] ?? null;

                    if (array_key_exists('history', $status) and is_array($status['history']) and array_key_exists(0, $status['history'])) {
                        $currentStatus = $status;
                        $status = $currentStatus['history'][0];
                        if (null === $status) {
                            continue;
                        }

                        $history[$sessionId][$ifname] = [];
                        $history[$sessionId][$ifname]['board'] = $ap->getStatus();
                        $history[$sessionId][$ifname]['info'] = $status['info'];
                        $history[$sessionId][$ifname]['assoclist'] = [];
                        if (array_key_exists('timestamp', $status) && array_key_exists('timestamp', $currentStatus)) {
                            $deltat = $currentStatus['timestamp'] - $status['timestamp'];
                            $history[$sessionId][$ifname]['timedelta'] = $deltat;
                        }
                        foreach ($status['assoclist']['results'] as $entry) {
			    $mac = strtolower($entry['mac']);
                            $history[$sessionId][$ifname]['assoclist'][$mac] = $entry;
                        }
                        $history[$sessionId][$ifname]['clients'] = [];
                        if (array_key_exists('clients', $status)) {
                            if (array_key_exists('clients', $status['clients'])) {
                                $history[$sessionId][$ifname]['clients'] = $status['clients']['clients'];
                            }
                        }
                        $history[$sessionId][$ifname]['clientstats'] = $status['stations'];
                    }
                }
            }
        }

        // Resolve names. The lookups themselves run in apman:grid-upkeep, on
        // a budget: here every address is either already resolved and cached,
        // or waits for the next run — a page load never pays for one.
        foreach ($neighbors as $mac => $neighbor) {
            if (empty($neighbor['ip'])) {
                continue;
            }
            $cached = $this->cacheFactory->getCacheItemValue(self::ptrCacheKey($neighbor['ip']));
            if (null !== $cached && '' !== $cached) {
                $neighbors[$mac]['name'] = $cached;
            }
        }

        // Build heatmap
        $heatmap = [];
        if ($withHeatmap) {
        $query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		ORDER by d.id DESC
	");
        $devices = $query->getResult();
        $keys = [];
        $devById = [];
        foreach ($devices as $device) {
            $devById[$device->getId()] = $device;
            foreach ($macs as $mac => $v) {
                $keys[] = 'status.device['.$device->getId().'].probe.'.$mac;
            }
        }

        $probes = $this->cacheFactory->getMultipleCacheItemValues($keys);
        foreach ($probes as $key => $probe) {
            if (is_null($probe) or !is_object($probe)) {
                continue;
            }
            if (!array_key_exists($probe->address, $heatmap)) {
                $heatmap[$probe->address] = [];
            }

            $hme = new \ApManBundle\Entity\ClientHeatMap();
            $hme->setTs($probe->ts);
            $hme->setAddress($probe->address);
            $hme->setDevice($devById[$probe->device]);
            $hme->setEvent($probe->event);
            if (property_exists($probe, 'signalstr')) {
                $hme->setSignalstr($probe->signalstr);
            }
            $heatmap[$probe->address][] = $hme;
        }
        foreach ($heatmap as $pa => $ps) {
            usort($ps, function ($a, $b) {
                return $a->getTs() < $b->getTs();
            });
            $heatmap[$pa] = $ps;
        }
        }

        return [
        'data' => $data,
        'historical_data' => $history,
        'neighbors' => $neighbors,
        'apsrv' => $apsrv,
        'heatmap' => $heatmap,
    ];
    }
}
