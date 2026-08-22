<?php

namespace ApManBundle\Service;

/**
 * Deliver a command to a client no matter which access point it is on.
 *
 * The controller's picture of where a client sits is up to one status interval
 * old, a roaming client does not deauthenticate (so it lingers in the old
 * station list until ap_max_inactivity expires), and a client can move between
 * two status messages. Addressing a single bss therefore misses the client
 * often enough to matter.
 *
 * So the command goes to every bss that could plausibly hold it — the one we
 * believe it is on first, then the remaining bsses of the same SSID. hostapd
 * answers with "not found" where the station is absent, which the command
 * channel now reports back, so the caller learns exactly which access point
 * carried it out.
 *
 * Not every method may be fanned out: del_client sends the disassociation
 * frame before it looks the station up and maintains a per bss ban list, so
 * broadcasting it would lock the client out everywhere. Methods are classified
 * below.
 */
class ClientCommandService
{
    /** safe to send everywhere: they report "not found" and change nothing */
    public const FANOUT_SAFE = [
        'rrm_beacon_req', 'bss_transition_request', 'wnm_disassoc_imminent',
        'link_measurement_req', 'get_clients', 'get_sta_ies',
    ];

    private $logger;
    private $doctrine;
    private $rpcService;
    private $mqttFactory;
    private $cacheFactory;
    private $stateTree;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Factory\CacheFactory $cacheFactory,
        StateTreeService $stateTree
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
        $this->stateTree = $stateTree;
    }

    /**
     * Every bss that could hold this client, the most likely one first.
     *
     * @return array [['device' => Device, 'inactive' => int|null, 'live' => bool], ...]
     */
    public function candidates($mac, $scope = 'ssid')
    {
        $mac = strtolower($mac);
        $em = $this->doctrine->getManager();
        $query = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a');

        $seen = [];
        $ssids = [];
        $all = [];
        foreach ($query->getResult() as $device) {
            if (!$device->getIfname()) {
                continue;
            }
            $all[] = $device;
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status) || !isset($status['assoclist']['results'])) {
                continue;
            }
            foreach ($status['assoclist']['results'] as $entry) {
                if (!isset($entry['mac']) || strtolower($entry['mac']) !== $mac) {
                    continue;
                }
                $seen[$device->getId()] = [
                    'device' => $device,
                    'inactive' => $entry['inactive'] ?? null,
                    'live' => true,
                ];
                if ($device->getSsid()) {
                    $ssids[$device->getSsid()->getId()] = true;
                }
            }
        }

        // the access point that heard from it most recently is the best guess
        uasort($seen, function ($a, $b) {
            return ($a['inactive'] ?? PHP_INT_MAX) <=> ($b['inactive'] ?? PHP_INT_MAX);
        });

        if ('live' === $scope) {
            return array_values($seen);
        }

        // everything else of the same ssid, because the client may have moved
        // since the last status message
        foreach ($all as $device) {
            if (isset($seen[$device->getId()])) {
                continue;
            }
            if (!$device->getSsid() || !isset($ssids[$device->getSsid()->getId()])) {
                continue;
            }
            // A bss that is not currently reporting cannot answer and would
            // only use up the wait budget. The reasoning was right and used to
            // be hand rolled here with its own 120 second window; the tree
            // keeps the same question in one place, and its answer decays with
            // the access point that stopped talking rather than with a
            // timestamp this method looked up for itself.
            $bss = $this->stateTree->bss($device);
            if (!$bss['fresh'] || in_array($bss['state'], [
                \ApManBundle\Library\NodeState::BSS_ABSENT,
                \ApManBundle\Library\NodeState::BSS_DISABLED,
                \ApManBundle\Library\NodeState::BSS_UNKNOWN,
            ], true)) {
                continue;
            }
            $seen[$device->getId()] = ['device' => $device, 'inactive' => null, 'live' => false];
        }

        return array_values($seen);
    }

    /**
     * Pull a fresh client list for the given bsses and fold it into the cached
     * status, so the interface does not show a stale picture after an action.
     */
    public function refreshDevices($client, array $ids, array $expect)
    {
        if (!$ids || !$client) {
            return;
        }
        $budget = 3;
        $wait = [];
        foreach ($ids as $id) {
            $where = $expect[$id];
            $rid = 'refresh-'.$where['device'].'-'.bin2hex(random_bytes(3));
            // A driver query, so usually quick — but it goes out to every
            // access point that just carried the command out, and this runs
            // while somebody is waiting for a page to finish loading. There is
            // nothing for it to be in order with.
            $cmd = $this->rpcService->createRpcRequest($rid,
                $this->rpcService->asyncMethod($where['bss']->getRadio()->getAccessPoint()),
                null, 'iwinfo', 'assoclist', (object) ['device' => $where['ifname']]);
            $client->publish('apman/ap/'.$where['ap'].'/command',
                json_encode($this->rpcService->setTimeout($cmd, $budget)), 1);
            $wait[$rid] = ['ap' => $where['ap'], 'device' => $where['device']];
        }

        $deadline = microtime(true) + $budget;
        while ($wait && microtime(true) < $deadline) {
            $ids2 = [];
            foreach ($wait as $rid => $w) {
                $ids2[$rid] = $w['ap'];
            }
            $hit = $this->cacheFactory->waitForAnyResult($ids2, max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($wait[$hit['id']])) {
                break;
            }
            $w = $wait[$hit['id']];
            unset($wait[$hit['id']]);
            $res = $hit['data'];
            if (!is_array($res) || !isset($res['result']['results'])) {
                continue;
            }
            $key = 'status.device.'.$w['device'];
            $status = $this->cacheFactory->getCacheItemValue($key);
            if (!is_array($status)) {
                continue;
            }
            $status['assoclist'] = $res['result'];
            $status['received'] = time();
            $status['refreshed'] = true;
            $this->cacheFactory->addCacheItem($key, $status);
            $this->logger->info('clientCommand(): refreshed station list of device '.$w['device']);
        }
    }

    /**
     * Send one hostapd method for a client to every candidate bss and report
     * which one carried it out.
     *
     * @return array summary
     */
    public function send($mac, $method, \stdClass $args, array $options = [])
    {
        $scope = $options['scope'] ?? 'ssid';
        $wait = $options['wait'] ?? 5;

        if ('live' !== $scope && !in_array($method, self::FANOUT_SAFE, true)) {
            $this->logger->info('clientCommand(): '.$method.' is not safe to fan out, addressing the live bss only');
            $scope = 'live';
        }

        $candidates = $this->candidates($mac, $scope);
        if (!$candidates) {
            return ['ok' => false, 'error' => 'this client is not associated on any known bss'];
        }

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return ['ok' => false, 'error' => 'no mqtt connection'];
        }

        // a deterministic id would let the cache hand back the answer of the
        // previous identical command instead of waiting for this one
        $run = bin2hex(random_bytes(3));
        $expect = [];
        $byAp = [];
        foreach ($candidates as $candidate) {
            $device = $candidate['device'];
            $apName = $device->getRadio()->getAccessPoint()->getName();
            $byAp[$apName][] = $device;
        }
        foreach ($byAp as $apName => $devices) {
            $commands = ['list' => []];
            foreach ($devices as $device) {
                $id = 'cc-'.$method.'-'.$device->getId().'-'.$run;
                // The whole point of this class is to ask several bsses at
                // once, and most of them do not have the station: they answer
                // "not found" and are done. The one that does have it may sit
                // there for seconds — a beacon request waits for the station's
                // report — and a synchronous call would take the access point
                // off the air for that long. Nothing here depends on the order
                // the answers come back in; they are collected by id.
                $cmd = $this->rpcService->createRpcRequest(
                    $id,
                    $this->rpcService->asyncMethod($device->getRadio()->getAccessPoint()),
                    null, 'hostapd.'.$device->getIfname(), $method, $args
                );
                // and it should stop caring when we do, rather than answering
                // into an empty room half a minute later
                $commands['list'][] = $this->rpcService->setTimeout($cmd, $wait);
                $expect[$id] = [
                    'ap' => $apName,
                    'ifname' => $device->getIfname(),
                    'device' => $device->getId(),
                    // kept so an answer can be reported back to the tree, which
                    // wants the bss itself and not its id
                    'bss' => $device,
                ];
            }
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
        }

        $result = [
            'ok' => true,
            'method' => $method,
            'scope' => $scope,
            'addressed' => count($expect),
            'executed' => [],
            'absent' => [],
            // the bss is not on the air there at all, which is a different
            // answer from "the client is not on it"
            'missing' => [],
            'failed' => [],
            'silent' => [],
        ];

        $pending = $expect;
        $deadline = microtime(true) + $wait;
        while ($pending && microtime(true) < $deadline) {
            $ids = [];
            foreach ($pending as $id => $where) {
                $ids[$id] = $where['ap'];
            }
            // one blocking wait across every outstanding answer
            $hit = $this->cacheFactory->waitForAnyResult($ids, max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($pending[$hit['id']])) {
                break;
            }
            $id = $hit['id'];
            $res = $hit['data'];
            $where = $pending[$id];
            unset($pending[$id]);
            $label = $where['ap'].'/'.$where['ifname'];
            if (!is_array($res)) {
                continue;
            }
            if (!isset($res['error'])) {
                $result['executed'][] = $label;
                // the client can only be on one bss; the rest just confirms
                // absence, so do not wait out the full budget for it
                $deadline = min($deadline, microtime(true) + 1.5);
            } elseif (4 === ($res['error']['code'] ?? null)) {
                // Status 4 says two different things. hostapd answering "I do
                // not have this station" is the ordinary case and the reason
                // this class fans out at all. But the same code comes back
                // when the ubus object hostapd.<ifname> does not exist, because
                // the bss is not running there — and reading that as "the
                // client is elsewhere" hides a bss that is simply gone behind
                // a perfectly normal looking search result.
                //
                // The agent marks the second case with stage=lookup: the
                // call never reached an object. Both paths say it, deferred
                // and queued alike — only an agent old enough to still block
                // cannot tell the two apart, and where the field is absent the
                // old reading is the only one available and also the right one.
                if ('lookup' === ($res['error']['stage'] ?? null)) {
                    $result['missing'][] = $label;
                    $this->stateTree->observeBss($where['bss'], ['present' => false]);
                } else {
                    // not found: the station is simply not on this bss
                    $result['absent'][] = $label;
                }
            } else {
                $result['failed'][$label] = ($res['error']['message'] ?? 'failed').
                    ' ('.($res['error']['code'] ?? '?').')';
            }
        }
        foreach ($pending as $where) {
            $result['silent'][] = $where['ap'].'/'.$where['ifname'];
        }

        $result['ok'] = count($result['executed']) > 0;

        // The status tick stays at ten seconds, so without this every action is
        // followed by a blind window of up to that long. Ask the access points
        // that carried it out for a fresh picture right away.
        if ($result['executed'] && ($options['refresh'] ?? true)) {
            $this->refreshDevices($client, array_keys(array_filter($expect, function ($w) use ($result) {
                return in_array($w['ap'].'/'.$w['ifname'], $result['executed'], true);
            })), $expect);
        }
        $client->disconnect();
        $this->logger->notice('clientCommand(): '.$method.' for '.$mac.' — '.
            count($result['executed']).' executed, '.count($result['absent']).' absent, '.
            count($result['missing']).' bss gone, '.
            count($result['failed']).' failed, '.count($result['silent']).' silent');

        return $result;
    }
}
