<?php

namespace ApManBundle\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Amp;

class DefaultController extends AbstractController
{
    private $logger;
    private $apservice;
    private $doctrine;
    private $rpcService;
    private $ieparser;
    private $mqttFactory;
    private $cacheFactory;
    private $statusService;


    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \ApManBundle\Service\AccessPointService $apservice,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\wrtJsonRpc $rpcService,
        \ApManBundle\Service\WifiIeParser $ieparser,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Factory\CacheFactory $cacheFactory,
        \ApManBundle\Service\StatusService $statusService
    ) {
        $this->logger = $logger;
        $this->apservice = $apservice;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->ieparser = $ieparser;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
        $this->statusService = $statusService;
        $this->cacheFactory->getCache();
    }

    #[Route(path: '/')]
    public function indexAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
        return $this->render('default/grid.html.twig', [
    ]);
    }

    #[Route(path: '/oldStatus')]
    public function indexOldAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
        $status = $this->getStatusDump($rpc);

        return $this->render('default/clients.html.twig', $status);
    }


    /** IEEE 802.11 AKM suite selectors, as hostapd reports them */
    private const AKM_SUITES = [
        '00-0f-ac-1' => '802.1X (WPA-EAP)',
        '00-0f-ac-2' => 'PSK',
        '00-0f-ac-3' => 'FT-802.1X',
        '00-0f-ac-4' => 'FT-PSK',
        '00-0f-ac-5' => '802.1X-SHA256',
        '00-0f-ac-6' => 'PSK-SHA256',
        '00-0f-ac-8' => 'SAE',
        '00-0f-ac-9' => 'FT-SAE',
        '00-0f-ac-11' => '802.1X-SUITE-B',
        '00-0f-ac-12' => '802.1X-SUITE-B-192',
        '00-0f-ac-18' => 'OWE',
    ];

    /** and the cipher suite selectors */
    private const CIPHER_SUITES = [
        '00-0f-ac-1' => 'WEP-40',
        '00-0f-ac-2' => 'TKIP',
        '00-0f-ac-4' => 'CCMP-128',
        '00-0f-ac-5' => 'WEP-104',
        '00-0f-ac-6' => 'BIP-CMAC-128',
        '00-0f-ac-8' => 'GCMP-128',
        '00-0f-ac-9' => 'GCMP-256',
        '00-0f-ac-10' => 'CCMP-256',
    ];

    /** hostapd's WPA key handshake state machine */
    private const PTK_STATES = [
        0 => 'INITIALIZE', 1 => 'DISCONNECT', 2 => 'DISCONNECTED',
        3 => 'AUTHENTICATION', 4 => 'AUTHENTICATION2', 5 => 'INITPMK',
        6 => 'INITPSK', 7 => 'PTKSTART', 8 => 'PTKCALCNEGOTIATING',
        9 => 'PTKCALCNEGOTIATING2', 10 => 'PTKINITNEGOTIATING',
        11 => 'PTKINITDONE',
    ];

    public static function akmName($suite)
    {
        return self::AKM_SUITES[$suite] ?? $suite;
    }

    /**
     * The last events hostapd reported over its control channel, trimmed for
     * display. These are the ones ubus never delivers: refusals with a reason,
     * EAP server verdicts, the numeric answer to a steering request, and the
     * channel life cycle.
     */
    private function ctrlEvents($list, $limit = 10)
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach (array_slice($list, 0, $limit) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fields = [];
            foreach ($entry['fields'] ?? [] as $key => $value) {
                $fields[] = $key.'='.$value;
            }
            $out[] = [
                'event' => $entry['event'] ?? '?',
                'ts' => $entry['ts'] ?? null,
                'age' => isset($entry['ts']) ? max(0, time() - $entry['ts']) : null,
                'address' => $entry['address'] ?? null,
                'ifname' => $entry['ifname'] ?? null,
                'detail' => implode(' ', $fields),
                // anything that means a client did not get in, or the radio
                // moved, deserves to stand out
                'bad' => in_array($entry['event'] ?? '', [
                    'AP-STA-POSSIBLE-PSK-MISMATCH', 'AP-REJECTED-MAX-STA',
                    'AP-REJECTED-BLOCKED-STA', 'CTRL-EVENT-EAP-FAILURE2',
                    'CTRL-EVENT-EAP-TIMEOUT-FAILURE2', 'DFS-RADAR-DETECTED',
                    'ACS-FAILED', 'AP-DISABLED', 'OCV-FAILURE',
                ], true),
            ];
        }

        return $out;
    }

    /** keyid => key, built once per request */
    private $identities;

    /**
     * Which issued key a station authenticated with. hostapd reports the keyid
     * of the psk file entry that matched, and that is the only handle on a
     * client whose MAC is randomised and whose key is not bound to an address.
     */
    private function identityFor($keyid)
    {
        if (!$keyid) {
            return null;
        }
        if (null === $this->identities) {
            $this->identities = [];
            foreach ($this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->findAll() as $key) {
                if ($key->getKeyid()) {
                    $this->identities[$key->getKeyid()] = $key;
                }
            }
        }
        $key = $this->identities[$keyid] ?? null;

        return $key ? ['id' => $key->getId(), 'name' => $key->getName(), 'keyid' => $keyid]
            : ['id' => null, 'name' => null, 'keyid' => $keyid];
    }

    /**
     * What a station really negotiated, read from the hostapd control channel.
     * The bss config only says what is offered — this says which of the offered
     * AKMs the client picked, which cipher it uses, and whether its key
     * handshake ever completed.
     */
    private function securityDetail($staCtrl)
    {
        if (!is_array($staCtrl) || !$staCtrl) {
            return [];
        }
        $out = [];
        if (isset($staCtrl['keyid'])) {
            $identity = $this->identityFor($staCtrl['keyid']);
            $out['identity'] = $identity['name']
                ? $identity['name'].' ('.$staCtrl['keyid'].')'
                : $staCtrl['keyid'].' — no key with this identity in the database';
        }
        if (isset($staCtrl['AKMSuiteSelector'])) {
            $out['AKM'] = self::AKM_SUITES[$staCtrl['AKMSuiteSelector']] ?? $staCtrl['AKMSuiteSelector'];
        }
        if (isset($staCtrl['dot11RSNAStatsSelectedPairwiseCipher'])) {
            $cipher = $staCtrl['dot11RSNAStatsSelectedPairwiseCipher'];
            $out['pairwise cipher'] = self::CIPHER_SUITES[$cipher] ?? $cipher;
        }
        if (isset($staCtrl['hostapdWPAPTKState'])) {
            $state = (int) $staCtrl['hostapdWPAPTKState'];
            $out['key handshake'] = (self::PTK_STATES[$state] ?? ('state '.$state)).
                (11 === $state ? '' : ' - not completed');
        }
        if (isset($staCtrl['hostapdMFPR'])) {
            $out['MFP required'] = '1' === (string) $staCtrl['hostapdMFPR'] ? 'yes' : 'no';
        }
        if (isset($staCtrl['listen_interval'])) {
            $out['listen interval'] = $staCtrl['listen_interval'].' beacons';
        }
        if (isset($staCtrl['capability'])) {
            $out['capability'] = $staCtrl['capability'];
        }
        if (isset($staCtrl['supported_rates'])) {
            // hostapd prints the rate set in half mbit steps, hex, with the
            // top bit marking a basic rate
            $rates = [];
            foreach (preg_split('/\s+/', trim($staCtrl['supported_rates'])) as $hex) {
                if ('' === $hex) {
                    continue;
                }
                $rates[] = (hexdec($hex) & 0x7f) / 2;
            }
            if ($rates) {
                $out['supported rates'] = implode(', ', $rates).' Mbit/s';
            }
        }
        if (isset($staCtrl['timeout_next'])) {
            $out['inactivity action'] = $staCtrl['timeout_next'];
        }
        foreach (['dot11RSNAStatsTKIPLocalMICFailures' => 'local MIC failures',
            'dot11RSNAStatsTKIPRemoteMICFailures' => 'remote MIC failures'] as $key => $label) {
            if (isset($staCtrl[$key]) && '0' !== (string) $staCtrl[$key]) {
                $out[$label] = $staCtrl[$key];
            }
        }

        return $out;
    }

    /**
     * The MIB counters of one bss, grouped for display. The handshake failures
     * and the radius round trip are the two numbers worth watching.
     */
    private function mibSummary($mib)
    {
        if (!is_array($mib) || !$mib) {
            return null;
        }
        $out = ['security' => [], 'radius' => []];
        if (isset($mib['dot11RSNA4WayHandshakeFailures'])) {
            $out['security']['4-way handshake failures'] = (int) $mib['dot11RSNA4WayHandshakeFailures'];
        }
        if (isset($mib['dot11RSNATKIPCounterMeasuresInvoked'])) {
            $out['security']['TKIP countermeasures'] = (int) $mib['dot11RSNATKIPCounterMeasuresInvoked'];
        }
        if (isset($mib['dot11RSNAAuthenticationSuiteSelected'])) {
            $out['security']['AKM'] = self::AKM_SUITES[$mib['dot11RSNAAuthenticationSuiteSelected']]
                ?? $mib['dot11RSNAAuthenticationSuiteSelected'];
        }
        if (isset($mib['dot11RSNAPairwiseCipherSelected'])) {
            $out['security']['pairwise cipher'] = self::CIPHER_SUITES[$mib['dot11RSNAPairwiseCipherSelected']]
                ?? $mib['dot11RSNAPairwiseCipherSelected'];
        }

        if (isset($mib['radiusAuthServerAddress'])) {
            $out['radius']['server'] = $mib['radiusAuthServerAddress'].
                (isset($mib['radiusAuthClientServerPortNumber']) ? ':'.$mib['radiusAuthClientServerPortNumber'] : '');
            $out['radius']['round trip'] = ($mib['radiusAuthClientRoundTripTime'] ?? '?').' ms';
            $out['radius']['requests'] = (int) ($mib['radiusAuthClientAccessRequests'] ?? 0);
            $out['radius']['accepts'] = (int) ($mib['radiusAuthClientAccessAccepts'] ?? 0);
            $out['radius']['rejects'] = (int) ($mib['radiusAuthClientAccessRejects'] ?? 0);
            $out['radius']['timeouts'] = (int) ($mib['radiusAuthClientTimeouts'] ?? 0);
            $out['radius']['retransmissions'] = (int) ($mib['radiusAuthClientAccessRetransmissions'] ?? 0);
            $bad = (int) ($mib['radiusAuthClientBadAuthenticators'] ?? 0)
                + (int) ($mib['radiusAuthClientMalformedAccessResponses'] ?? 0);
            if ($bad) {
                $out['radius']['bad or malformed answers'] = $bad;
            }
        }
        if (!$out['radius']) {
            unset($out['radius']);
        }

        return $out['security'] || isset($out['radius']) ? $out : null;
    }

    /**
     * Drill down for one access point: everything the agent reports about it,
     * plus the actions that can be run against it with feedback.
     */
    #[Route(path: '/ap/{name}', name: 'ap_detail')]
    public function apDetailAction($name, \ApManBundle\Service\StateTreeService $stateTree)
    {
        $em = $this->doctrine->getManager();
        $cf = $this->cacheFactory;
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy(['name' => $name]);
        if (!$ap) {
            throw $this->createNotFoundException('No access point '.$name);
        }

        $devices = [];
        foreach ($ap->getRadios() as $radio) {
            foreach ($radio->getDevices() as $device) {
                $status = $cf->getCacheItemValue('status.device.'.$device->getId());
                $status = is_array($status) ? $status : [];
                $apStatus = isset($status['ap_status']) && is_array($status['ap_status']) ? $status['ap_status'] : [];
                $hostapd = isset($status['hostapd_status']) && is_array($status['hostapd_status']) ? $status['hostapd_status'] : [];
                $clients = [];
                if (isset($status['assoclist']['results']) && is_array($status['assoclist']['results'])) {
                    foreach ($status['assoclist']['results'] as $entry) {
                        if (!isset($entry['mac'])) {
                            continue;
                        }
                        $mac = strtolower($entry['mac']);
                        $ctrl = $status['sta_ctrl'][$mac] ?? null;
                        $identity = is_array($ctrl) && isset($ctrl['keyid'])
                            ? $this->identityFor($ctrl['keyid']) : null;
                        $clients[$mac] = [
                            'mac' => $mac,
                            'identity' => $identity ? ($identity['name'] ?: $identity['keyid']) : null,
                            'akm' => is_array($ctrl) && isset($ctrl['AKMSuiteSelector'])
                                ? self::akmName($ctrl['AKMSuiteSelector']) : null,
                            'signal' => $entry['signal'] ?? null,
                            'noise' => $entry['noise'] ?? null,
                            'inactive' => $entry['inactive'] ?? null,
                            'rx_rate' => $entry['rx']['rate'] ?? null,
                            'tx_rate' => $entry['tx']['rate'] ?? null,
                            'device' => $entry['device'] ?? $device->ifname(),
                        ];
                    }
                }
                $devices[] = [
                    'id' => $device->getId(),
                    'ifname' => $device->ifname(),
                    'radio' => $radio->getName(),
                    'band' => method_exists($radio, 'getConfigBand') ? $radio->getConfigBand() : null,
                    'ssid' => $device->getSsid() ? $device->getSsid()->getName() : null,
                    'age' => isset($status['received']) || isset($status['timestamp'])
                        ? max(0, (int) (time() - ($status['received'] ?? $status['timestamp'])))
                        : null,
                    'ap_status' => $apStatus,
                    'hostapd_status' => $hostapd,
                    'bss_info' => $cf->getCacheItemValue('status.device.'.$device->getId().'.bss_info'),
                    'survey' => $this->surveySummary(
                        $cf->getCacheItemValue('status.device.'.$device->getId().'.survey'),
                        $apStatus['channel'] ?? null
                    ),
                    'mib' => $this->mibSummary($status['mib'] ?? null),
                    // what hostapd itself reported through the control channel
                    'events' => $this->ctrlEvents(
                        $cf->getCacheItemValue('status.device['.$device->getId().'].ctrlevents'), 8),
                    'clients' => $clients,
                ];
            }
        }

        return $this->render('default/ap.html.twig', [
            'ap' => $ap,
            'agent' => $cf->getCacheItemValue('status.ap.'.$ap->getId().'.agent'),
            'board' => $cf->getCacheItemValue('status.ap.'.$ap->getId().'.board'),
            'sysinfo' => $cf->getCacheItemValue('status.ap.'.$ap->getId().'.info'),
            'state' => \ApManBundle\Library\AccessPointState::getStateName(
                $cf->getCacheItemValue('status.state['.$ap->getId().']')
            ),
            // the same access point as a tree: its radios, and under each of
            // them its bsses, every node with its own state
            'tree' => $stateTree->ap($ap),
            'devices' => $devices,
        ]);
    }

    /**
     * Runs one ubus call on an ap and waits briefly for the agent's answer,
     * which command channel v2 now delivers on command_result/<id>.
     */
    #[Route(path: '/ap/{name}/action', name: 'ap_action', methods: ['POST'])]
    public function apActionAction(Request $request, $name, \ApManBundle\Service\ClientCommandService $commands)
    {
        // a command aimed at a client should reach it wherever it currently is
        $addr = (string) $request->request->get('client', '');
        if ('' !== $addr) {
            $method = (string) $request->request->get('method', '');
            $args = json_decode((string) $request->request->get('args', '{}'), true);
            $scope = $request->request->get('scope', 'ssid');
            $sent = $commands->send($addr, $method, is_array($args) ? (object) $args : new \stdClass(),
                ['scope' => $scope]);
            if (isset($sent['error'])) {
                return $this->json(['ok' => false, 'error' => $sent['error']]);
            }

            return $this->json([
                'ok' => $sent['ok'],
                'result' => null,
                'executed_on' => $sent['executed'],
                'addressed' => $sent['addressed'],
                'absent' => count($sent['absent']),
                // a bss that is not running there is not the same answer as a
                // bss that simply does not hold the client
                'missing' => count($sent['missing']),
                'error' => $sent['ok'] ? null : 'no access point executed it (client not present anywhere)',
            ]);
        }

        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy(['name' => $name]);
        if (!$ap) {
            return $this->json(['ok' => false, 'error' => 'unknown access point'], 404);
        }
        $object = (string) $request->request->get('object', '');
        $method = (string) $request->request->get('method', '');
        $args = json_decode((string) $request->request->get('args', '{}'), true);
        if ('' === $object || '' === $method) {
            return $this->json(['ok' => false, 'error' => 'object and method required'], 400);
        }

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return $this->json(['ok' => false, 'error' => 'no mqtt connection'], 502);
        }
        $id = 'ui-'.bin2hex(random_bytes(4));
        $cmd = $this->rpcService->createRpcRequest(
            $id, 'call', null, $object, $method, is_array($args) ? (object) $args : new \stdClass()
        );
        $client->publish('apman/ap/'.$ap->getName().'/command', json_encode($cmd), 1);
        $client->disconnect();
        $this->logger->info('apAction(): '.$ap->getName().' '.$object.' '.$method, ['id' => $id]);

        // the subscriber caches every result under command.result.<host>.<id>
        $key = 'command.result.'.$ap->getName().'.'.$id;
        $deadline = microtime(true) + 4;
        while (microtime(true) < $deadline) {
            $result = $this->cacheFactory->getCacheItemValue($key);
            if (is_array($result)) {
                if (isset($result['error'])) {
                    return $this->json([
                        'ok' => false,
                        'error' => ($result['error']['message'] ?? 'failed').
                            ' ('.($result['error']['code'] ?? '?').')',
                    ]);
                }

                return $this->json(['ok' => true, 'result' => $result['result'] ?? null]);
            }
            usleep(200000);
        }

        return $this->json(['ok' => false, 'error' => 'no answer within 4s (old agent or ap offline)']);
    }

    /**
     * Everything known about one client: where it is now, where it was seen,
     * how it answered steering requests and what its probe advertises.
     */
    #[Route(path: '/client/{mac}', name: 'client_detail')]
    public function clientDetailAction($mac)
    {
        $mac = strtolower($mac);
        $em = $this->doctrine->getManager();
        $cf = $this->cacheFactory;

        $client = $this->doctrine->getRepository('ApManBundle\Entity\Client')->findOneBy(['mac' => $mac]);

        // where is it associated right now, and where was it seen probing
        $query = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a');
        $seen = [];
        $current = [];
        foreach ($query->getResult() as $device) {
            $status = $cf->getCacheItemValue('status.device.'.$device->getId());
            if (is_array($status) && isset($status['assoclist']['results'])) {
                foreach ($status['assoclist']['results'] as $entry) {
                    if (!isset($entry['mac']) || strtolower($entry['mac']) !== $mac) {
                        continue;
                    }
                    // three views of the same station, each carrying something
                    // the others do not: iwinfo has the negotiated rates and
                    // modulation, hostapd the flags and driver counters, and
                    // the parsed iw station dump the rest (retries, durations,
                    // dtim, expected throughput)
                    $station = isset($status['stations'][$mac]) && is_array($status['stations'][$mac])
                        ? $status['stations'][$mac] : [];
                    $hostapd = isset($status['clients']['clients'][$mac]) && is_array($status['clients']['clients'][$mac])
                        ? $status['clients']['clients'][$mac] : [];
                    $info = isset($status['info']) && is_array($status['info']) ? $status['info'] : [];

                    $current[] = [
                        'ap' => $device->getRadio()->getAccessPoint()->getName(),
                        'ifname' => $device->ifname(),
                        'device' => $entry['device'] ?? $device->ifname(),
                        'ssid' => $info['ssid'] ?? ($device->getSsid() ? $device->getSsid()->getName() : null),
                        'channel' => $info['channel'] ?? null,
                        'frequency' => $info['frequency'] ?? null,
                        'hwmode' => $info['hwmode'] ?? null,
                        'age' => isset($status['received']) || isset($status['timestamp'])
                            ? max(0, (int) (time() - ($status['received'] ?? $status['timestamp'])))
                            : null,
                        'signal' => $entry['signal'] ?? null,
                        'signal_avg' => $station['signal_avg'] ?? null,
                        'noise' => $entry['noise'] ?? null,
                        'rx_rate' => $entry['rx']['rate'] ?? null,
                        'tx_rate' => $entry['tx']['rate'] ?? null,
                        'rx' => $entry['rx'] ?? [],
                        'tx' => $entry['tx'] ?? [],
                        'inactive' => $entry['inactive'] ?? null,
                        'connected_time' => $entry['connected_time'] ?? null,
                        'flags' => array_filter([
                            'authorized' => $hostapd['authorized'] ?? null,
                            'authenticated' => $hostapd['auth'] ?? null,
                            'assoc' => $hostapd['assoc'] ?? null,
                            'wmm' => $hostapd['wmm'] ?? null,
                            'ht' => $hostapd['ht'] ?? null,
                            'vht' => $hostapd['vht'] ?? null,
                            'he' => $hostapd['he'] ?? null,
                            'mfp' => $hostapd['mfp'] ?? null,
                            'wds' => $hostapd['wds'] ?? null,
                            'wps' => $hostapd['wps'] ?? null,
                            'mbo' => $hostapd['mbo'] ?? null,
                        ], function ($v) { return null !== $v; }),
                        'counters' => array_filter([
                            'rx bytes' => $hostapd['bytes']['rx'] ?? ($station['rx_bytes'] ?? null),
                            'tx bytes' => $hostapd['bytes']['tx'] ?? ($station['tx_bytes'] ?? null),
                            'rx packets' => $hostapd['packets']['rx'] ?? ($station['rx_packets'] ?? null),
                            'tx packets' => $hostapd['packets']['tx'] ?? ($station['tx_packets'] ?? null),
                            'rx airtime' => $hostapd['airtime']['rx'] ?? ($station['rx_duration'] ?? null),
                            'tx airtime' => $hostapd['airtime']['tx'] ?? ($station['tx_duration'] ?? null),
                            'tx retries' => $station['tx_retries'] ?? null,
                            'tx failed' => $station['tx_failed'] ?? null,
                            'rx drop misc' => $station['rx_drop_misc'] ?? null,
                        ], function ($v) { return null !== $v; }),
                        'radio' => array_filter([
                            'expected throughput' => $station['expected_throughput'] ?? null,
                            'beacon interval' => $station['beacon_interval'] ?? null,
                            'DTIM period' => $station['DTIM_period'] ?? ($station['dtim_period'] ?? null),
                            'preamble' => $station['preamble'] ?? null,
                            'last ack signal' => $station['last_ack_signal'] ?? null,
                            'avg ack signal' => $station['avg_ack_signal'] ?? null,
                            'associated at' => $station['associated_at'] ?? null,
                        ], function ($v) { return null !== $v && '' !== $v; }),
                        'aid' => $hostapd['aid'] ?? null,
                        'signature' => $hostapd['signature'] ?? null,
                        'taxonomy' => $this->describeTaxonomy($hostapd['signature'] ?? null),
                        // what this station actually negotiated, straight from
                        // the hostapd control channel (agent >= 56-4)
                        'security' => $this->securityDetail($status['sta_ctrl'][$mac] ?? null),
                        'station' => $station,
                    ];
                }
            }
            $probe = $cf->getCacheItemValue('status.device['.$device->getId().'].probe.'.str_replace(':', '', $mac));
            if (is_object($probe)) {
                $seen[] = [
                    'ap' => $device->getRadio()->getAccessPoint()->getName(),
                    'ifname' => $device->ifname(),
                    'ts' => $probe->ts ?? null,
                    'signal' => $probe->signalstr ?? null,
                ];
            }
        }
        // A roaming client stays in the old access point's station list until
        // ap_max_inactivity expires, so the same mac legitimately shows up on
        // two APs. The one that heard from it most recently is the live one.
        usort($current, function ($a, $b) {
            return ($a['inactive'] ?? PHP_INT_MAX) <=> ($b['inactive'] ?? PHP_INT_MAX);
        });
        foreach ($current as $i => $entry) {
            $current[$i]['stale'] = ($entry['inactive'] ?? 0) > 60000;
            $current[$i]['primary'] = 0 === $i;
        }

        usort($seen, function ($a, $b) {
            return ($b['signal'] ?? -999) <=> ($a['signal'] ?? -999);
        });

        // how it reacted to steering
        $events = $em->createQuery("SELECT e FROM ApManBundle\Entity\Event e
                WHERE e.address = :mac ORDER BY e.ts DESC")
            ->setParameter('mac', $mac)
            ->setMaxResults(40)
            ->getResult();
        $steering = ['accepted' => 0, 'rejected' => 0];
        foreach ($events as $event) {
            if ('bss-transition-response' !== $event->getType()) {
                continue;
            }
            $data = json_decode($event->getEvent(), true);
            $code = is_array($data) && isset($data['status-code']) ? (int) $data['status-code'] : null;
            0 === $code ? ++$steering['accepted'] : ++$steering['rejected'];
        }

        $ies = null;
        $raw = $cf->getCacheItemValue('status.client['.str_replace(':', '', $mac).'].raw_elements');
        if (!empty($raw)) {
            try {
                $ies = $this->ieparser->getResolveIeNames($raw);
            } catch (\Throwable $e) {
                $this->logger->warning('clientDetail(): ie parsing failed: '.$e->getMessage());
            }
        }

        $manufacturer = null;
        try {
            $manufacturer = $this->apservice->getMacManufacturer($mac);
        } catch (\Throwable $e) {
            $this->logger->debug('clientDetail(): manufacturer lookup failed: '.$e->getMessage());
        }

        $ctrlEvents = $this->ctrlEvents(
            $this->cacheFactory->getCacheItemValue('status.client['.str_replace(':', '', $mac).'].ctrlevents'), 12);

        return $this->render('default/client.html.twig', [
            'manufacturer' => $manufacturer,
            'coverage' => $this->coverage($mac, $seen),
            'steering_state' => $this->apservice->getSteering()->getState($mac),
            'mac' => $mac,
            'client' => $client,
            'current' => $current,
            'seen' => $seen,
            'events' => $events,
            'ctrl_events' => $ctrlEvents,
            'steering' => $steering,
            'ies' => $ies,
        ]);
    }

    /**
     * Provision one access point: staged transaction, diff, apply with
     * rollback, confirm. ?dry=1 only reports what would change.
     */
    #[Route(path: '/ap/{name}/provision', name: 'ap_provision', methods: ['POST'])]
    public function apProvisionAction(Request $request, $name)
    {
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy(['name' => $name]);
        if (!$ap) {
            return $this->json(['ok' => false, 'error' => 'unknown access point'], 404);
        }
        $dry = (bool) $request->request->get('dry', false);

        return $this->json($this->apservice->applyConfig($ap, $dry));
    }

    /**
     * Both directions of coverage in one table.
     *
     * Probe requests tell us how well the access point hears the client,
     * beacon reports how well the client hears the access point. Neither alone
     * answers "is the client on the right ap", together they do.
     */
    private function coverage($mac, array $seen, $foreignLimit = 8)
    {
        // All bsses of one access point share a radio, so the client reports
        // the same RCPI for each of them. One row per access point instead of
        // one per bss turns a hundred lines into something readable.
        $rows = [];
        $add = function ($key, $defaults) use (&$rows) {
            if (!isset($rows[$key])) {
                $rows[$key] = $defaults + [
                    'ap' => null, 'foreign' => false, 'ssids' => [], 'bssids' => [],
                    'probe_signal' => null, 'probe_ifname' => null, 'probe_ts' => null,
                    'beacon_dbm' => null, 'beacon_rcpi' => null, 'beacon_rsni' => null,
                    'beacon_age' => null, 'beacon_flags' => [], 'channel' => null,
                ];
            }

            return $key;
        };

        foreach ($seen as $s) {
            $key = $add($s['ap'], ['ap' => $s['ap']]);
            if (null === $rows[$key]['probe_signal'] || ($s['signal'] ?? -999) > $rows[$key]['probe_signal']) {
                $rows[$key]['probe_signal'] = $s['signal'];
                $rows[$key]['probe_ifname'] = $s['ifname'];
                $rows[$key]['probe_ts'] = $s['ts'];
            }
        }

        $foreign = 0;
        foreach ($this->beaconReports($mac) as $report) {
            if ($report['known']) {
                $key = $add($report['known']['ap'], ['ap' => $report['known']['ap']]);
                if ($report['known']['ssid']) {
                    $rows[$key]['ssids'][$report['known']['ssid']] = true;
                }
            } else {
                if (++$foreign > $foreignLimit) {
                    continue;
                }
                $key = $add('foreign:'.$report['bssid'], [
                    'foreign' => true,
                    'info' => $this->apservice->describeForeignBssid($report['bssid']),
                ]);
            }
            $rows[$key]['bssids'][$report['bssid']] = true;
            // strongest report of this access point wins
            if (null === $rows[$key]['beacon_dbm'] || ($report['dbm'] ?? -999) > $rows[$key]['beacon_dbm']) {
                $rows[$key]['beacon_dbm'] = $report['dbm'];
                $rows[$key]['beacon_rcpi'] = $report['rcpi_raw'];
                $rows[$key]['beacon_rsni'] = $report['rsni_db'];
                $rows[$key]['channel'] = $report['channel'];
                $rows[$key]['beacon_flags'] = $report['flags'];
            }
            if (null === $rows[$key]['beacon_age'] || $report['age'] < $rows[$key]['beacon_age']) {
                $rows[$key]['beacon_age'] = $report['age'];
            }
        }

        foreach ($rows as &$row) {
            $row['ssids'] = array_keys($row['ssids']);
            $row['bssids'] = array_keys($row['bssids']);
        }
        unset($row);

        uasort($rows, function ($a, $b) {
            return [$b['beacon_dbm'] ?? -999, $b['probe_signal'] ?? -999]
               <=> [$a['beacon_dbm'] ?? -999, $a['probe_signal'] ?? -999];
        });

        return $rows;
    }

    /**
     * Scan the neighbourhood so foreign BSSIDs get a name.
     */
    #[Route(path: '/ap/{name}/scan', name: 'ap_scan', methods: ['POST'])]
    public function apScanAction($name)
    {
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy(['name' => $name]);
        if (!$ap) {
            return $this->json(['ok' => false, 'error' => 'unknown access point'], 404);
        }

        return $this->json($this->apservice->scanNeighbours($ap));
    }

    /**
     * Ask the client for a fresh beacon measurement and wait for the reports.
     */
    #[Route(path: '/client/{mac}/measure', name: 'client_measure', methods: ['POST'])]
    public function clientMeasureAction(Request $request, $mac, \ApManBundle\Service\ClientCommandService $cmd)
    {
        $mac = strtolower($mac);
        $em = $this->doctrine->getManager();
        $before = new \DateTime();

        $opts = new \stdClass();
        $opts->addr = $mac;
        $opts->op_class = (int) $request->request->get('op_class', 0);
        // 255 means every channel of the operating class
        $opts->channel = (int) $request->request->get('channel', 255);
        $opts->duration = 100;
        // 2 = beacon table, the variant nearly every client answers instantly
        $opts->mode = (int) $request->request->get('mode', 2);
        $opts->bssid = 'ff:ff:ff:ff:ff:ff';

        // the client may have roamed since the last status message, so ask
        // every bss of the ssid; only the one holding it will act
        $sent = $cmd->send($mac, 'rrm_beacon_req', $opts, ['scope' => 'ssid', 'wait' => 5]);
        if (isset($sent['error'])) {
            return $this->json(['ok' => false, 'error' => $sent['error']]);
        }

        // the reports arrive asynchronously as notifications and are stored as
        // events, so poll for rows newer than the request
        $found = 0;
        $deadline = microtime(true) + (float) $request->request->get('wait', 6);
        while (microtime(true) < $deadline) {
            $found = (int) $em->createQuery("SELECT count(e) FROM ApManBundle\Entity\Event e
                    WHERE e.address = :mac AND e.type = 'beacon-report' AND e.ts > :since")
                ->setParameter('mac', $mac)->setParameter('since', $before)
                ->getSingleScalarResult();
            if ($found > 0) {
                usleep(500000);   // let the remaining reports land
                break;
            }
            usleep(400000);
        }
        $em->clear(\ApManBundle\Entity\Event::class);

        return $this->json([
            'ok' => true,
            'via' => implode(', ', $sent['executed']) ?: 'nobody',
            'addressed' => $sent['addressed'],
            'absent' => count($sent['absent']),
            'reports' => $found,
            'coverage' => array_values($this->coverage($mac, [])),
        ]);
    }

    /**
     * 802.11k beacon reports: what the client itself hears.
     *
     * hostapd forwards the raw measurement, which is the client's own view of
     * the neighbourhood — the only signal strength the controller gets that is
     * measured at the client instead of at the access point. Values are
     * encoded per 802.11: RCPI is (dBm + 110) * 2, RSNI is (dB + 10) * 2, and
     * 255 means the client did not provide the value.
     */
    private function beaconReports($mac, $limit = 200)
    {
        $em = $this->doctrine->getManager();
        $events = $em->createQuery("SELECT e FROM ApManBundle\Entity\Event e
                WHERE e.address = :mac AND e.type = 'beacon-report'
                ORDER BY e.ts DESC")
            ->setParameter('mac', $mac)
            ->setMaxResults($limit)
            ->getResult();
        if (!$events) {
            return [];
        }

        // resolve the reported bssids to our own interfaces
        $known = [];
        $query = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a WHERE d.address IS NOT NULL');
        foreach ($query->getResult() as $device) {
            $known[strtolower($device->getAddress())] = [
                'ap' => $device->getRadio()->getAccessPoint()->getName(),
                'ifname' => $device->ifname(),
                'ssid' => $device->getSsid() ? $device->getSsid()->getName() : null,
            ];
        }

        $latest = [];
        foreach ($events as $event) {
            $d = json_decode($event->getEvent(), true);
            if (!is_array($d) || empty($d['bssid'])) {
                continue;
            }
            $bssid = strtolower($d['bssid']);
            if (isset($latest[$bssid])) {
                continue;   // events come newest first
            }
            $rcpi = isset($d['rcpi']) ? (int) $d['rcpi'] : 255;
            $rsni = isset($d['rsni']) ? (int) $d['rsni'] : 255;
            $mode = isset($d['rep-mode']) ? (int) $d['rep-mode'] : 0;
            $flags = [];
            if ($mode & 1) { $flags[] = 'late'; }
            if ($mode & 2) { $flags[] = 'incapable'; }
            if ($mode & 4) { $flags[] = 'refused'; }

            $latest[$bssid] = [
                'bssid' => $bssid,
                'known' => $known[$bssid] ?? null,
                'channel' => $d['channel'] ?? null,
                'rcpi_raw' => $rcpi,
                'dbm' => 255 === $rcpi ? null : round($rcpi / 2 - 110, 1),
                'rsni_db' => 255 === $rsni ? null : round($rsni / 2 - 10, 1),
                'flags' => $flags,
                'ts' => $event->getTs(),
                'age' => time() - $event->getTs()->getTimestamp(),
            ];
        }

        uasort($latest, function ($a, $b) {
            return ($b['dbm'] ?? -999) <=> ($a['dbm'] ?? -999);
        });

        return $latest;
    }

    /**
     * What the client announced about itself, decoded: generation, capability
     * bits, spatial streams, the information elements it sent and the
     * extended capabilities that matter for steering.
     */
    private function describeTaxonomy($signature)
    {
        if (!$signature) {
            return null;
        }
        try {
            $summary = $this->ieparser->describeSignature($signature);
            $tags = $this->ieparser->parseSignature($signature, 'assoc');
            if (!$tags) {
                $tags = $this->ieparser->parseSignature($signature, 'probe');
            }
            // vendor elements (221) resolve to a list of oui/type pairs, so
            // flatten everything to readable strings
            $elements = [];
            foreach (($tags ? $this->ieparser->getResolveIeNames($tags) : []) as $id => $name) {
                if (is_array($name)) {
                    foreach ($name as $entry) {
                        $elements[] = is_array($entry) ? implode('/', $entry) : (string) $entry;
                    }
                    continue;
                }
                $elements[] = (string) $name;
            }
            // the raw tag list still holds the vendor oui/type pairs
            if (isset($tags[221]) && is_array($tags[221])) {
                foreach ($tags[221] as $vendor) {
                    $elements[] = 'VENDOR('.$vendor.')';
                }
            }
            $summary['elements'] = array_values(array_unique($elements));
            $summary['extended'] = $tags ? $this->ieparser->getExtendedCapabilities($tags) : [];

            // the ones a controller actually acts on
            $summary['roaming'] = [];
            foreach ($summary['extended'] as $cap) {
                foreach (['BSS Transition', 'WNM', 'Interworking', 'Operating Mode', 'Multi Band'] as $needle) {
                    if (false !== stripos($cap, $needle)) {
                        $summary['roaming'][] = $cap;
                        break;
                    }
                }
            }

            return $summary;
        } catch (\Throwable $e) {
            $this->logger->debug('describeTaxonomy(): '.$e->getMessage());

            return null;
        }
    }

    /**
     * Cluster wide consistency of the running wlan configuration.
     */
    #[Route(path: '/consistency', name: 'consistency')]
    public function consistencyAction(Request $request, \ApManBundle\Service\WlanConsistencyService $check)
    {
        $maxAge = $request->query->has('refresh') ? 0 : 600;

        return $this->render('default/consistency.html.twig', [
            'result' => $check->check($maxAge),
        ]);
    }

    /**
     * Per device psk overview and the WPS enrolment that feeds it. The old
     * implementation scraped syslog for WPS-PIN-NEEDED and identified the
     * access point by source ip; this drives the registration instead.
     */
    #[Route(path: '/ppsk', name: 'ppsk')]
    public function ppskAction(\ApManBundle\Service\PpskService $ppsk)
    {
        $em = $this->doctrine->getManager();
        $ssids = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findAll();
        $rows = [];
        foreach ($ssids as $ssid) {
            $keys = $ppsk->getForSsid($ssid);
            $devices = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                    LEFT JOIN d.radio r LEFT JOIN r.accesspoint a WHERE d.ssid = :ssid')
                ->setParameter('ssid', $ssid)->getResult();
            $bss = [];
            foreach ($devices as $device) {
                $bss[] = [
                    'id' => $device->getId(),
                    'ap' => $device->getRadio()->getAccessPoint()->getName(),
                    'ifname' => $device->ifname(),
                ];
            }
            $rows[] = ['ssid' => $ssid, 'keys' => $keys, 'bss' => $bss,
                // an iPSK network has no psk file: no WPS enrolment, nothing
                // to import from uci, and the keys travel as a key set
                'ipsk' => $ppsk->usesOnApRadius($ssid)];
        }

        return $this->render('default/ppsk.html.twig', [
            'rows' => $rows,
            'pin' => $ppsk->generatePin(),
            'aps' => $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route(path: '/ppsk/{ssidId}/distribute', name: 'ppsk_distribute', methods: ['POST'])]
    public function ppskDistributeAction(\ApManBundle\Service\PpskService $ppsk, Request $request, $ssidId)
    {
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($ssidId);
        if (!$ssid) {
            return $this->json(['ok' => false, 'error' => 'unknown ssid'], 404);
        }
        // force rewrites and reloads even where nothing changed — the way to
        // repair an access point whose runtime file was lost or edited. On an
        // iPSK network there is no file to repair: the key set goes out as a
        // whole either way, so force changes nothing there.
        try {
            $res = $ppsk->distribute($ssid, (bool) $request->get('force'));
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => get_class($e).': '.$e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine()]);
        }

        $summary = $this->distributionSummary($res);

        return $this->json([
            'ok' => $summary['ok'],
            'confirmed' => $summary['confirmed'],
            'pending' => $summary['pending'],
            'skipped' => $summary['skipped'],
            'version' => $summary['version'],
            'detail' => $summary['detail'],
            'error' => $summary['error'],
            'result' => $res,
        ]);
    }

    /**
     * The networks, with the facts that decide whether one works.
     */
    #[Route(path: '/ssids', name: 'ssids')]
    public function ssidsAction(\ApManBundle\Service\WirelessSchemaService $schema)
    {
        $em = $this->doctrine->getManager();
        $rows = [];
        foreach ($em->createQuery('SELECT s FROM ApManBundle\Entity\SSID s ORDER BY s.name')->getResult() as $ssid) {
            $config = (array) $ssid->exportConfig();
            $devices = [];
            $aps = [];
            foreach ($ssid->getDevices() as $device) {
                $devices[] = $device;
                if ($device->getRadio() && $device->getRadio()->getAccessPoint()) {
                    $aps[$device->getRadio()->getAccessPoint()->getName()] = true;
                }
            }
            $keys = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->findBy(['ssid' => $ssid]);

            $rows[] = [
                'id' => $ssid->getId(),
                'name' => $ssid->getName(),
                'ssid' => $config['ssid'] ?? $ssid->getName(),
                'encryption' => $config['encryption'] ?? null,
                'mode' => $config['mode'] ?? null,
                'network' => $config['network'] ?? null,
                'disabled' => !empty($config['disabled']),
                'devices' => count($devices),
                'aps' => count($aps),
                'keys' => count($keys),
                'options' => count($ssid->getConfigOptions()),
                'lists' => count($ssid->getConfigLists()),
                // the handful of switches that tell you what kind of network
                // this is without opening it
                'traits' => array_values(array_filter([
                    !empty($config['ppsk']) ? 'per device keys' : null,
                    !empty($config['ieee80211r']) ? '802.11r' : null,
                    !empty($config['ieee80211k']) ? '802.11k' : null,
                    !empty($config['ieee80211v']) ? '802.11v' : null,
                    !empty($config['interworking']) ? 'passpoint' : null,
                    !empty($config['wps_pushbutton']) ? 'wps' : null,
                    !empty($config['dynamic_vlan']) ? 'dynamic vlan' : null,
                    isset($config['ieee80211w']) && '2' == $config['ieee80211w'] ? 'pmf required' : null,
                    !empty($config['auth_server']) || !empty($config['auth_server_addr']) ? 'radius' : null,
                    $ssid->getAutoPpsk() ? 'learns its keys' : null,
                    $ssid->getMovingPsk() ? 'moving key' : null,
                ])),
                'hints' => $schema->hints($config),
            ];
        }

        return $this->render('default/ssids.html.twig', ['ssids' => $rows]);
    }

    /**
     * One radio in full, the same way a network is shown.
     *
     * wifi-device.json describes 152 options and the admin form had nineteen
     * fields, hand written, with no documentation and no defaults. The schema
     * was in the repository the whole time and nothing opened it.
     */
    #[Route(path: '/radio/{id}', name: 'radio_detail')]
    public function radioDetailAction(\ApManBundle\Service\WirelessSchemaService $schema, \ApManBundle\Service\StateTreeService $stateTree, $id)
    {
        $radio = $this->doctrine->getRepository('ApManBundle\Entity\Radio')->find($id);
        if (!$radio) {
            throw $this->createNotFoundException('no such radio');
        }
        $values = [];
        $lists = [];
        foreach ((array) $radio->exportConfig() as $name => $value) {
            if (is_array($value)) {
                $lists[$name] = $value;
            } else {
                $values[$name] = $value;
            }
        }

        $bss = [];
        foreach ($radio->getDevices() as $device) {
            $node = $stateTree->bss($device);
            $bss[] = [
                'name' => $device->getName(),
                'ssid' => $device->getSsid() ? $device->getSsid()->getName() : null,
                'ifname' => $device->ifname(),
                'wanted' => $device->getIfname(),
                'state' => $node['state_name'],
            ];
        }

        return $this->render('default/radio.html.twig', [
            'radio' => $radio,
            'ap' => $radio->getAccessPoint(),
            'groups' => $schema->describe($values, $lists, \ApManBundle\Service\WirelessSchemaService::DEVICE),
            'titles' => $schema->groupTitles(\ApManBundle\Service\WirelessSchemaService::DEVICE),
            'columns' => array_keys(\ApManBundle\Entity\Radio::COLUMN_OPTIONS),
            'bss' => $bss,
        ]);
    }

    /**
     * Save one radio's options, each into the place that owns it.
     */
    #[Route(path: '/radio/{id}/save', name: 'radio_save', methods: ['POST'])]
    public function radioSaveAction(Request $request, $id)
    {
        $em = $this->doctrine->getManager();
        $radio = $this->doctrine->getRepository('ApManBundle\Entity\Radio')->find($id);
        if (!$radio) {
            throw $this->createNotFoundException('no such radio');
        }
        $posted = $request->request->all('opt');
        $lists = $request->request->all('list');
        $json = $radio->getConfig();
        // what the radio looks like before and after, so the answer can say
        // what moved rather than only that something did
        $before = (array) $radio->exportConfig();

        foreach ($posted as $name => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $setter = \ApManBundle\Entity\Radio::COLUMN_OPTIONS[$name] ?? null;
            if ($setter) {
                $radio->$setter('' === $value ? null : $value);
                continue;
            }
            // An empty field removes the option rather than writing an empty
            // one: uci does not distinguish "" from unset, and an option
            // written empty reads as a decision on the page next time.
            if ('' === $value || null === $value) {
                unset($json[$name]);
            } else {
                $json[$name] = $value;
            }
        }
        foreach ($lists as $name => $text) {
            $entries = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $text)), 'strlen'));
            $setter = \ApManBundle\Entity\Radio::COLUMN_OPTIONS[$name] ?? null;
            if ($setter) {
                $radio->$setter($entries ?: null);
                continue;
            }
            if ($entries) {
                $json[$name] = $entries;
            } else {
                unset($json[$name]);
            }
        }
        $radio->setConfig($json);
        $after = (array) $radio->exportConfig();
        $em->persist($radio);
        $em->flush();

        // The same shape the network editor answers with, because the script
        // that submits both forms is one script and reads exactly these keys.
        $changed = [];
        foreach ($before as $name => $value) {
            $now = $after[$name] ?? null;
            if ($now !== $value) {
                $changed[] = $name.': '.$this->sayValue($value).' → '.$this->sayValue($now);
            }
        }
        foreach ($after as $name => $value) {
            if (!array_key_exists($name, $before)) {
                $changed[] = $name.': '.$this->sayValue($value);
            }
        }
        sort($changed);

        return $this->json([
            'ok' => true,
            'changed' => $changed,
            'hint' => $changed
                ? 'Saved. This radio keeps running the old configuration until it is provisioned, '
                    .'and every bss on it is rebuilt when it is.'
                : 'Nothing changed.',
        ]);
    }

    /** A value as a person would read it in a one-line summary. */
    private function sayValue($value): string
    {
        if (null === $value) {
            return 'removed';
        }
        if (is_array($value)) {
            return $value ? implode(', ', $value) : 'empty';
        }

        return '' === (string) $value ? 'empty' : (string) $value;
    }

    /**
     * One network in full: every option OpenWrt knows, grouped, documented, and
     * with its default shown where nothing is set.
     */
    #[Route(path: '/ssid/{id}', name: 'ssid_detail')]
    public function ssidDetailAction(\ApManBundle\Service\WirelessSchemaService $schema, \ApManBundle\Service\PpskService $ppsk, \ApManBundle\Service\StateTreeService $stateTree, $id)
    {
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($id);
        if (!$ssid) {
            throw $this->createNotFoundException('no such ssid');
        }

        $values = [];
        foreach ($ssid->getConfigOptions() as $option) {
            if ($option->getName()) {
                $values[$option->getName()] = $option->getValue();
            }
        }
        $lists = [];
        foreach ($ssid->getConfigLists() as $list) {
            if (!$list->getName()) {
                continue;
            }
            $entries = [];
            foreach ($list->getOptions() as $entry) {
                $entries[] = $entry->getValue();
            }
            $lists[$list->getName()] = $entries;
        }

        // The state of every bss carrying this network, on one page.
        //
        // A network that is ABSENT on one access point and READY on the others
        // is precisely the failure that took an evening to find, and this is
        // the page that should have shown it. It is also why the count below
        // is worth having: "6 of 7 running" is a sentence somebody notices,
        // while a list of seven rows is one they scroll past.
        $devices = [];
        $bssStates = [];
        foreach ($ssid->getDevices() as $device) {
            $ap = $device->getRadio() ? $device->getRadio()->getAccessPoint() : null;
            $node = $stateTree->bss($device);
            $bssStates[$node['state_name']] = ($bssStates[$node['state_name']] ?? 0) + 1;
            $devices[] = [
                'name' => $device->getName(),
                'ifname' => $device->ifname(),
                'ap' => $ap ? $ap->getName() : null,
                'band' => $device->getRadio() ? $device->getRadio()->getConfigBand() : null,
                'address' => $device->getAddress(),
                'state' => $node['state_name'],
                'seen' => $node['seen'],
                'since' => $node['since'],
                'fresh' => $node['fresh'],
                'trouble' => in_array($node['state'], [
                    \ApManBundle\Library\NodeState::BSS_ABSENT,
                    \ApManBundle\Library\NodeState::BSS_UNKNOWN,
                ], true),
            ];
        }
        ksort($bssStates);

        return $this->render('default/ssid.html.twig', [
            'ssid' => $ssid,
            'groups' => $schema->describe($values, $lists),
            'titles' => $schema->groupTitles(),
            'hints' => $schema->hints($values),
            'devices' => $devices,
            'bssStates' => $bssStates,
            'values' => $values,
            'lists' => $lists,
            'keys' => $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->findBy(['ssid' => $ssid]),
            'radius' => $this->radiusSummary($ssid, $values, $ppsk),
            'features' => $this->apservice->previewFeatureOverrides($ssid),
        ]);
    }

    /**
     * How this network is authenticated, from the RADIUS side.
     *
     * The interesting part is what the paths for a per device key can do here.
     * Where the access points answer themselves (iPSK) both WPA2 and SAE work
     * and a key is live at the next association. Everywhere else the old split
     * applies: with WPA2 the key can travel in wpa_psk_file and takes effect
     * in seconds, with SAE it can only come from a RADIUS answer — an SSID
     * that runs SAE without pointing at a server cannot have per device keys
     * at all, and saying so here is cheaper than letting somebody find out by
     * handing out a key that never works.
     */
    private function radiusSummary(\ApManBundle\Entity\SSID $ssid, array $values, ?\ApManBundle\Service\PpskService $ppsk = null)
    {
        $encryption = strtolower((string) ($values['encryption'] ?? 'none'));
        $broadcast = $values['ssid'] ?? $ssid->getName();
        $server = $values['auth_server'] ?? ($values['auth_server_addr'] ?? null);
        // An iPSK network usually carries none of this in its own options: the
        // marker lives in the feature, and exportConfig() does not merge that.
        // Reading only the options made the page tell an SSID that is doing
        // per device SAE keys right now that it cannot have per device keys.
        $onAp = $ppsk && $ppsk->usesOnApRadius($ssid);
        if ($onAp) {
            $server = $server ?: '127.0.0.1';
        }

        $counts = [];
        try {
            $counts = $this->doctrine->getManager()->getConnection()->fetchAllAssociative(
                'SELECT result, COUNT(*) AS n, MAX(created) AS last FROM radius_auth'
                .' WHERE ssid_name = :ssid AND created > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
                .' GROUP BY result',
                ['ssid' => $broadcast]
            );
        } catch (\Throwable $e) {
            // the history table is young; a controller that has not seen it yet
            // should still render the page
        }

        $shared = 0;
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->findBy([
            'ssid' => $ssid, 'mac' => \ApManBundle\Entity\Ppsk::ANY_MAC, 'enabled' => true,
        ]) as $key) {
            if (!$key->isRegistration()) {
                ++$shared;
            }
        }

        return [
            'server' => $server,
            'onap' => $onAp,
            'ipsk' => $ppsk ? $ppsk->isIpsk($ssid) : false,
            'ppsk' => $onAp || (bool) ($values['ppsk'] ?? false),
            'sae' => (bool) preg_match('/sae|wpa3/', $encryption),
            'psk' => (bool) preg_match('/psk|wpa2/', $encryption),
            'fallback' => $ssid->getRadiusFallback(),
            'auto' => $ssid->getAutoPpsk(),
            'moving' => $ssid->getMovingPsk(),
            'shared' => $shared,
            'counts' => $counts,
            'broadcast' => $broadcast,
        ];
    }

    /**
     * Save one network.
     *
     * Only what differs from the default is stored: clearing a field removes
     * the row instead of writing an empty one, so the configuration stays a
     * list of decisions rather than a dump of every option that exists.
     */
    #[Route(path: '/ssid/{id}/save', name: 'ssid_save', methods: ['POST'])]
    public function ssidSaveAction(\ApManBundle\Service\WirelessSchemaService $schema, Request $request, $id)
    {
        $em = $this->doctrine->getManager();
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($id);
        if (!$ssid) {
            return $this->json(['ok' => false, 'error' => 'no such ssid'], 404);
        }

        // all(), not get(). InputBag::get() takes a scalar and throws on an
        // array in Symfony 7 — "Unexpected value for parameter \"opt\":
        // expecting \"string\", got \"array\"" — so every save from the network
        // editor has failed since the upgrade, with a 400 the page reported as
        // "failed" and nothing in it saying why.
        $posted = $request->request->all('opt');
        $postedLists = $request->request->all('list');
        $changed = [];

        // The RADIUS fallback is not a uci option — it decides what the
        // controller answers, not what the access point is configured with —
        // so it is saved here rather than travelling with the rest.
        if ($request->request->has('radius_fallback_present')) {
            $wanted = (bool) $request->request->get('radius_fallback');
            if ($ssid->getRadiusFallback() !== $wanted) {
                $ssid->setRadiusFallback($wanted);
                $changed[] = $wanted
                    ? 'unknown devices get the network passphrase'
                    : 'unknown devices are turned away';
            }
        }
        if ($request->request->has('auto_ppsk_present')) {
            $wanted = (bool) $request->request->get('auto_ppsk');
            if ($ssid->getAutoPpsk() !== $wanted) {
                $ssid->setAutoPpsk($wanted);
                $changed[] = $wanted
                    ? 'shared use becomes a key per device'
                    : 'shared keys stay shared';
            }
        }
        if ($request->request->has('moving_psk_present')) {
            $wanted = (bool) $request->request->get('moving_psk');
            if ($ssid->getMovingPsk() !== $wanted) {
                $ssid->setMovingPsk($wanted);
                $changed[] = $wanted
                    ? 'each enrolment replaces the shared key'
                    : 'the shared key stays put';
            }
        }

        $existing = [];
        foreach ($ssid->getConfigOptions() as $option) {
            if ($option->getName()) {
                $existing[$option->getName()] = $option;
            } else {
                // nameless leftovers do nothing but confuse every reader
                $em->remove($option);
                $changed[] = 'dropped a nameless option';
            }
        }

        foreach ($posted as $name => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $known = isset($existing[$name]);
            if ('' === $value || null === $value) {
                if ($known) {
                    $em->remove($existing[$name]);
                    $changed[] = $name.' cleared';
                }
                continue;
            }
            if ($known) {
                if ((string) $existing[$name]->getValue() !== (string) $value) {
                    $existing[$name]->setValue($value);
                    $changed[] = $name.' = '.$value;
                }
                continue;
            }
            $option = new \ApManBundle\Entity\SSIDConfigOption();
            $option->setName($name);
            $option->setValue($value);
            $option->setSsid($ssid);
            $em->persist($option);
            $changed[] = $name.' = '.$value;
        }

        $existingLists = [];
        foreach ($ssid->getConfigLists() as $list) {
            if ($list->getName()) {
                $existingLists[$list->getName()] = $list;
            }
        }
        foreach ($postedLists as $name => $raw) {
            $entries = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $raw)),
                function ($line) { return '' !== $line; }));
            $list = $existingLists[$name] ?? null;
            if (!$entries) {
                if ($list) {
                    $em->remove($list);
                    $changed[] = $name.' (list) cleared';
                }
                continue;
            }
            if (!$list) {
                $list = new \ApManBundle\Entity\SSIDConfigList();
                $list->setName($name);
                $list->setSsid($ssid);
                $em->persist($list);
            } else {
                foreach ($list->getOptions() as $entry) {
                    $em->remove($entry);
                }
                $list->getOptions()->clear();
            }
            foreach ($entries as $value) {
                $entry = new \ApManBundle\Entity\SSIDConfigListOption();
                $entry->setValue($value);
                $list->addOption($entry);
                $em->persist($entry);
            }
            $changed[] = $name.' (list, '.count($entries).' entries)';
        }

        $em->flush();
        $this->logger->notice('ssidSave('.$ssid->getName().'): '.implode(', ', $changed));

        // The fallback switch is answered by the controller itself, so it is in
        // force at the next request — saying "until they are provisioned" would
        // send somebody looking for a provisioning run they do not need.
        $ours = ['unknown devices', 'shared use becomes', 'shared keys stay',
            'each enrolment replaces', 'the shared key stays'];
        $onlyRadius = $changed && !array_filter($changed, function ($line) use ($ours) {
            foreach ($ours as $mark) {
                if (false !== strpos($line, $mark)) {
                    return false;
                }
            }

            return true;
        });

        return $this->json([
            'ok' => true,
            'changed' => $changed,
            'hint' => $changed
                ? ($onlyRadius
                    ? 'Saved — in force from the next request on, no provisioning needed.'
                    : 'Saved. The access points keep running the old configuration until they are provisioned.')
                : 'Nothing changed.',
        ]);
    }

    /**
     * Open an enrolment on a network that manages its own keys.
     *
     * Unlike handing out an iPSK, this one clears the field first: every
     * station currently connected is converted to a key of its own, then the
     * shared keys step aside, so the key issued here is the only thing an
     * unknown device can come in on. It pins itself to the first device that
     * uses it, and the shared keys come back the moment that happens.
     */
    #[Route(path: '/ipsk/register/{ssidId}', name: 'ipsk_register', methods: ['POST'])]
    public function ipskRegisterAction(\ApManBundle\Service\PpskService $ppsk, Request $request, $ssidId)
    {
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($ssidId);
        if (!$ssid) {
            return $this->json(['ok' => false, 'error' => 'unknown ssid'], 404);
        }
        $name = trim((string) $request->get('name'));
        if ('' === $name) {
            return $this->json(['ok' => false, 'error' => 'a name is required — it is the identity of this key']);
        }

        try {
            $res = $ppsk->startRegistration($ssid, $name, $request->get('vid'));
        } catch (\Throwable $e) {
            $this->logger->error('ipskRegister('.$ssid->getName().'): '.$e->getMessage());

            return $this->json(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
        }
        if (isset($res['error'])) {
            return $this->json(['ok' => false, 'error' => $res['error']]);
        }

        $key = $res['key'];
        $converted = count($res['converted']['created'] ?? []);
        $suspended = count($res['suspended']);

        return $this->json([
            'ok' => true,
            'id' => $key->getId(),
            'psk' => $key->getPsk(),
            'keyid' => $key->getKeyid(),
            'name' => $key->getName(),
            'ssid' => $ssid->getName(),
            'qr' => $this->wifiQrPayload($ssid, $key->getPsk()),
            'converted' => $converted,
            'suspended' => $suspended,
            'moving' => (bool) ($res['moving'] ?? false),
            'rotated' => (bool) ($res['rotated'] ?? false),
            'hint' => ($res['moving'] ?? false)
                ? sprintf(
                    'Enrolment open, and this key is the network passphrase from now on. %d station(s) '
                    .'were given a key of their own first, %d shared key(s) went out of service — every '
                    .'device that used one already holds a copy of its own, so nothing was locked out. '
                    .'The next enrolment replaces this key in turn.',
                    $converted, $suspended)
                : sprintf(
                    'Enrolment open. %d station(s) were given a key of their own first, %d shared key(s) '
                    .'stepped aside. This key is now the only way in for a device that has none — it binds '
                    .'itself to the first one that uses it, and then the shared keys come back.',
                    $converted, $suspended),
        ]);
    }

    /**
     * Give every station that is connected right now a key of its own.
     *
     * Without ?apply=1 it only reports what it would do, which is the way to
     * look at a production network before touching it.
     */
    #[Route(path: '/ppsk/{ssidId}/convert', name: 'ppsk_convert', methods: ['POST'])]
    public function ppskConvertAction(\ApManBundle\Service\PpskService $ppsk, Request $request, $ssidId)
    {
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($ssidId);
        if (!$ssid) {
            return $this->json(['ok' => false, 'error' => 'unknown ssid'], 404);
        }

        $apply = (bool) $request->get('apply');
        try {
            $result = $ppsk->convertConnected($ssid, $apply);
        } catch (\Throwable $e) {
            $this->logger->error('ppskConvert('.$ssid->getName().'): '.$e->getMessage());

            return $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        if (isset($result['error'])) {
            return $this->json(['ok' => false, 'error' => $result['error']]);
        }

        return $this->json([
            'ok' => true,
            'applied' => $apply,
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'hint' => $apply
                ? count($result['created']).' station(s) now have a key of their own — '
                    .'nothing had to be typed into any of them, they keep using the passphrase they already had'
                : count($result['created']).' station(s) would get a key of their own, '
                    .count($result['skipped']).' already have one',
        ]);
    }

    /**
     * What the controller's own RADIUS server was asked, and what it answered.
     *
     * Every association attempt on an SSID that points at us leaves a row here,
     * including the ones that were turned away — which is the half a psk file
     * can never show. For an SAE network it is the only place a key's use shows
     * up at all: hostapd reports no keyid there.
     */
    /**
     * The requests alone, as json, for the live view.
     *
     * The page used to keep itself current with location.reload() every five
     * seconds — a hundred kilobytes and two hundred rendered rows to learn that
     * two of them are new, and the reader's scroll position and text selection
     * gone with it. This answers the same question in a few kilobytes, and
     * "since" makes it a few hundred bytes once the page is warm.
     */
    #[Route(path: '/radius/requests', name: 'radius_requests')]
    public function radiusRequestsAction(Request $request)
    {
        $conn = $this->doctrine->getManager()->getConnection();

        $sql = 'SELECT id, created, mac, ssid_name, nas, result, reason, keyid, duration_ms'
            .' FROM radius_auth WHERE 1 = 1';
        $params = [];
        foreach (['ssid' => 'ssid_name', 'result' => 'result'] as $key => $column) {
            $value = trim((string) $request->get($key));
            if ('' !== $value) {
                $sql .= ' AND '.$column.' = :'.$key;
                $params[$key] = $value;
            }
        }
        $since = (int) $request->get('since');
        if ($since > 0) {
            $sql .= ' AND id > :since';
            $params['since'] = $since;
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';

        $rows = $conn->fetchAllAssociative($sql, $params);

        $flaky = $this->radiusFlaky($conn);
        foreach ($rows as &$row) {
            $row['flaky'] = in_array($row['mac'].'|'.(string) $row['ssid_name'], $flaky, true);
        }

        return new JsonResponse([
            'rows' => $rows,
            'newest' => $rows ? (int) $rows[0]['id'] : $since,
        ]);
    }

    /**
     * The keys (mac|ssid) of devices whose last 24 h hold both accepts and
     * rejects: they authenticate fine most of the time — which is why they
     * keep coming back — and sometimes they do not.
     *
     * @return string[]
     */
    private function radiusFlaky($conn)
    {
        $keys = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT mac, ssid_name FROM radius_auth'
            .' WHERE created > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            .' GROUP BY mac, ssid_name'
            ." HAVING SUM(result = 'accept') > 0 AND SUM(result <> 'accept') > 0"
        ) as $row) {
            $keys[] = $row['mac'].'|'.(string) $row['ssid_name'];
        }

        return $keys;
    }

    #[Route(path: '/radius', name: 'radius')]
    public function radiusAction(\ApManBundle\Service\PpskService $ppsk, \ApManBundle\Service\StateTreeService $stateTree, Request $request)
    {
        $em = $this->doctrine->getManager();
        $filterSsid = trim((string) $request->get('ssid'));
        $filterResult = trim((string) $request->get('result'));

        $dql = 'SELECT r FROM ApManBundle\Entity\RadiusAuth r WHERE 1 = 1';
        $params = [];
        if ('' !== $filterSsid) {
            $dql .= ' AND r.ssidName = :ssid';
            $params['ssid'] = $filterSsid;
        }
        if ('' !== $filterResult) {
            $dql .= ' AND r.result = :result';
            $params['result'] = $filterResult;
        }
        $query = $em->createQuery($dql.' ORDER BY r.id DESC')->setMaxResults(200);
        foreach ($params as $key => $value) {
            $query->setParameter($key, $value);
        }
        $rows = $query->getResult();

        // the numbers over the day, straight from the history: cheap enough at
        // this size and always in step with the table below it
        $stats = $em->getConnection()->fetchAllAssociative(
            'SELECT ssid_name, result, COUNT(*) AS n, ROUND(AVG(duration_ms), 1) AS ms,'
            .' MAX(created) AS last'
            .' FROM radius_auth WHERE created > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            .' GROUP BY ssid_name, result ORDER BY ssid_name, result'
        );
        $unknown = $em->getConnection()->fetchAllAssociative(
            'SELECT mac, ssid_name, COUNT(*) AS n, MAX(created) AS last FROM radius_auth'
            ." WHERE result <> 'accept' AND created > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            .' GROUP BY mac, ssid_name ORDER BY MAX(created) DESC LIMIT 20'
        );
        $flaky = $em->getConnection()->fetchAllAssociative(
            'SELECT mac, ssid_name, SUM(result = \'accept\') AS ok, SUM(result <> \'accept\') AS bad,'
            .' MAX(created) AS last FROM radius_auth'
            .' WHERE created > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            .' GROUP BY mac, ssid_name HAVING ok > 0 AND bad > 0 ORDER BY MAX(created) DESC'
        );
        $flakyKeys = array_map(function ($row) {
            return $row['mac'].'|'.(string) $row['ssid_name'];
        }, $flaky);
        $flaky = array_slice($flaky, 0, 20);

        // which SSIDs point their access points at us, and which of those run
        // SAE — where a per device key works over RADIUS and nowhere else
        $pointing = [];
        foreach ($em->getRepository('ApManBundle\Entity\SSID')->findAll() as $ssid) {
            $config = $ssid->exportConfig();
            $server = $config->auth_server ?? ($config->auth_server_addr ?? null);
            if (!$server) {
                continue;
            }
            $pointing[] = [
                'name' => $ssid->getName(),
                'broadcast' => $config->ssid ?? '',
                'server' => $server,
                'encryption' => $config->encryption ?? 'none',
                'onap' => '127.0.0.1' === (string) $server || $ppsk->usesOnApRadius($ssid),
                'fallback' => $ssid->getRadiusFallback(),
                'id' => $ssid->getId(),
            ];
        }

        return $this->render('default/radius.html.twig', [
            'agents' => $this->radiusAgents($stateTree),
            'rows' => $rows,
            'stats' => $stats,
            'unknown' => $unknown,
            'flaky' => $flaky,
            'flakyKeys' => $flakyKeys,
            'pointing' => $pointing,
            'filterSsid' => $filterSsid,
            'filterResult' => $filterResult,
        ]);
    }

    /**
     * Every access point's own RADIUS server, as it reports itself.
     *
     * The agent publishes properties/radius retained, so this is what the
     * server says about itself rather than what we infer from the requests it
     * happened to send. That distinction is the whole point: a server that
     * answers nothing looks exactly like one nobody asked, and only the server
     * itself can tell the two apart.
     *
     * The numbers next to it come from radius_auth grouped by nas — the same
     * requests the table below lists, seen per access point.
     */
    private function radiusAgents(\ApManBundle\Service\StateTreeService $stateTree)
    {
        $em = $this->doctrine->getManager();
        $perNas = [];
        foreach ($em->getConnection()->fetchAllAssociative(
            'SELECT nas, COUNT(*) AS n, SUM(result = \'accept\') AS ok,'
            .' SUM(result <> \'accept\') AS bad, ROUND(AVG(duration_ms), 2) AS ms,'
            .' ROUND(MAX(duration_ms), 2) AS msmax, MAX(created) AS last'
            .' FROM radius_auth WHERE created > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            .' AND nas IS NOT NULL GROUP BY nas'
        ) as $row) {
            $perNas[$row['nas']] = $row;
        }

        $out = [];
        foreach ($em->getRepository('ApManBundle\Entity\AccessPoint')->findAll() as $ap) {
            $info = $this->cacheFactory->getCacheItemValue('status.ap.'.$ap->getId().'.radius');
            $agent = $this->cacheFactory->getCacheItemValue('status.ap.'.$ap->getId().'.agent');
            $stats24 = $perNas[$ap->getName()] ?? null;
            // An access point with nothing to say about a radius server and no
            // requests to its name is not a radius server having a bad day —
            // it simply does not run one. Listing it would bury the ones that
            // do among the ones that never will.
            if (!is_array($info) && !$stats24) {
                continue;
            }
            $tree = $stateTree->ap($ap);
            $out[] = [
                'name' => $ap->getName(),
                'id' => $ap->getId(),
                'state' => $tree['state_name'],
                'seen' => $tree['seen'] ?? null,
                'agent_version' => is_array($agent) ? ($agent['version'] ?? null) : null,
                'has_feature' => is_array($agent) && in_array('radius_psk', $agent['features'] ?? [], true),
                'info' => is_array($info) ? $info : null,
                'stats24' => $stats24,
            ];
        }

        return $out;
    }

    /**
     * Hand out an iPSK: a generated key that is an identity in itself.
     *
     * It is bound to no MAC address, so anyone who has the key can use it on
     * any device — which is the point. What makes it an identity rather than a
     * shared secret is the keyid that travels with it into the psk file: the
     * access points report it back for every station that authenticated with
     * that key, so the controller can say who is on the network, and whether a
     * key that was handed out was ever used at all.
     */
    #[Route(path: '/ipsk', name: 'ipsk')]
    public function ipskAction(\ApManBundle\Service\PpskService $ppsk)
    {
        $em = $this->doctrine->getManager();
        $ssids = $em->createQuery('SELECT s FROM ApManBundle\Entity\SSID s ORDER BY s.name')->getResult();
        // only ssids that actually carry per device keys can take an ipsk
        $usable = [];
        foreach ($ssids as $ssid) {
            $usable[] = ['id' => $ssid->getId(), 'name' => $ssid->getName()];
        }

        $keys = $em->createQuery("SELECT p,s FROM ApManBundle\Entity\Ppsk p JOIN p.ssid s
                WHERE p.source = :source ORDER BY p.enabled DESC, p.created DESC")
            ->setParameter('source', \ApManBundle\Entity\Ppsk::SOURCE_IPSK)
            ->setMaxResults(100)
            ->getResult();

        return $this->render('default/ipsk.html.twig', [
            'ssids' => $usable,
            'keys' => $keys,
        ]);
    }

    #[Route(path: '/ipsk/create', name: 'ipsk_create', methods: ['POST'])]
    public function ipskCreateAction(\ApManBundle\Service\PpskService $ppsk, Request $request)
    {
        $name = trim((string) $request->get('name'));
        $ssidId = $request->get('ssid');
        if ('' === $name) {
            return $this->json(['ok' => false, 'error' => 'a name is required — it is the identity of this key']);
        }
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($ssidId);
        if (!$ssid) {
            return $this->json(['ok' => false, 'error' => 'unknown ssid']);
        }

        // A network holds one unbound key. An untouched one is replaced without
        // asking; one that has already been handed out and used is somebody's,
        // so say whose and how old it is and let the operator decide.
        $replace = (bool) $request->get('replace', false);
        $blocking = $ppsk->blockingUnboundKey($ssid);
        if ($blocking && !$replace) {
            $since = $blocking->getFirstSeen() ?: $blocking->getCreated();
            return $this->json([
                'ok' => false,
                'blocked' => [
                    'id' => $blocking->getId(),
                    'name' => $blocking->getName(),
                    'created' => $blocking->getCreated() ? $blocking->getCreated()->format('Y-m-d H:i') : null,
                    'first_seen' => $blocking->getFirstSeen() ? $blocking->getFirstSeen()->format('Y-m-d H:i') : null,
                    'last_seen' => $blocking->getLastSeen() ? $blocking->getLastSeen()->format('Y-m-d H:i') : null,
                    'age_days' => $since ? (int) $since->diff(new \DateTime())->days : null,
                ],
                'error' => sprintf('"%s" is still waiting for a device to claim it', $blocking->getName()),
            ]);
        }

        try {
            $key = $ppsk->createIpsk($ssid, $name, $request->get('vid'),
                (bool) $request->get('pin', false), $replace);
            $result = $ppsk->distribute($ssid);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
        }

        // How far did the distribution get? A key that exists in the database
        // but on no access point is not yet a key anybody can use, and saying
        // "live" there would be a lie.
        $summary = $this->distributionSummary($result);

        // An on-AP RADIUS network answers every station from the key store on
        // the spot — the key works at the next (re)association, for WPA2 and
        // SAE alike. Only a network without RADIUS still has the old SAE
        // limitation: hostapd reads sae_password_file only when it parses its
        // whole config, and that reload costs every client of the radio its
        // connection.
        $delivery = $ppsk->keyDelivery($ssid);
        $note = null;
        // On-AP RADIUS answers every station on the spot — WPA2 and SAE alike,
        // because such a network has no psk and no sae file at all (the
        // options are not written; pointing them at /dev/null does not work).
        // The reload limitation below only exists on networks without RADIUS.
        if (!$ppsk->usesOnApRadius($ssid)) {
            if ($delivery['sae'] && !$delivery['psk']) {
                $note = 'This network uses WPA3/SAE. The key is distributed to the access '.
                    'points now and takes effect at the next wireless reload.';
            } elseif ($delivery['sae']) {
                $note = 'This network offers WPA2 and WPA3. WPA2/PSK works at the next '.
                    'reload; WPA3/SAE takes effect at the next wireless reload.';
            }
        }

        return $this->json([
            'ok' => true,
            'id' => $key->getId(),
            'name' => $key->getName(),
            'keyid' => $key->getKeyid(),
            'note' => $note,
            'radius' => $ppsk->usesOnApRadius($ssid),
            'ssid' => $ssid->getName(),
            'psk' => $key->getPsk(),
            'qr' => $this->wifiQrPayload($ssid, $key->getPsk()),
            'distributed' => $summary['ok'],
            'confirmed' => $summary['confirmed'],
            'pending' => $summary['pending'],
            'skipped' => $summary['skipped'],
            'version' => $summary['version'],
            'detail' => $summary['detail'],
            'dist_error' => $summary['error'],
            'result' => $result,
        ]);
    }

    /**
     * What a distribution actually achieved — for both shapes it can have.
     *
     * An iPSK network answers one entry per access point, carrying the version
     * of the key set it now holds; the file based path answers a list of per
     * device rows carrying a reload result. The interface needs the same few
     * numbers from either, and reading only one of them (which is what this
     * used to do) made every iPSK distribution look like it confirmed nothing.
     *
     * @return array ['ok', 'confirmed', 'pending', 'version', 'detail', 'error']
     */
    private function distributionSummary($result)
    {
        $out = ['ok' => true, 'confirmed' => 0, 'pending' => 0, 'skipped' => 0,
            'version' => null, 'detail' => [], 'error' => null];
        if (!is_array($result)) {
            return ['ok' => false, 'confirmed' => 0, 'pending' => 0, 'skipped' => 0,
                'version' => null, 'detail' => [], 'error' => 'no answer'];
        }
        if (isset($result['error'])) {
            $out['ok'] = false;
            $out['error'] = (string) $result['error'];

            return $out;
        }
        foreach ($result as $apName => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            if (array_key_exists('ack', $rows)) {
                // key set: one answer per access point
                $out['version'] = $rows['version'] ?? $out['version'];
                $out['detail'][$apName] = (string) $rows['ack'];
                if ('ok' === $rows['ack']) {
                    ++$out['confirmed'];
                } elseif (!empty($rows['skipped'])) {
                    // Deliberately not waited for: the key set was published,
                    // the access point is known to be down, and nobody sat out
                    // the deadline for it. Counting that as a failure made a
                    // distribution to seven healthy access points report
                    // "failed" because the eighth had been off for a day.
                    ++$out['skipped'];
                } else {
                    ++$out['pending'];
                    $out['ok'] = false;
                }
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ('ok' === ($row['reload'] ?? null) || !empty($row['unchanged'])) {
                    ++$out['confirmed'];
                } elseif (isset($row['ifname'])) {
                    ++$out['pending'];
                    $out['ok'] = false;
                    $out['detail'][$apName.'/'.$row['ifname']] = (string) ($row['reload'] ?? 'not reloaded');
                }
            }
        }
        if (0 === $out['confirmed'] && 0 === $out['pending'] && 0 === $out['skipped']) {
            $out['ok'] = false;
            $out['error'] = $out['error'] ?: 'no access point carries this network';
        }
        // Every access point that carries this network was skipped: nothing was
        // confirmed by anybody, and saying "ok" would be saying the key set is
        // live when not one access point has said so.
        if (0 === $out['confirmed'] && 0 === $out['pending'] && $out['skipped'] > 0) {
            $out['ok'] = false;
            $out['error'] = $out['error'] ?: 'no access point was reachable to confirm the key set';
        }

        return $out;
    }

    /**
     * Has anybody used this key yet, and where is it right now?
     */
    #[Route(path: '/ipsk/{id}/status', name: 'ipsk_status')]
    public function ipskStatusAction($id)
    {
        $em = $this->doctrine->getManager();
        $key = $em->getRepository('ApManBundle\Entity\Ppsk')->find($id);
        if (!$key) {
            return $this->json(['ok' => false, 'error' => 'unknown key'], 404);
        }

        // Where the key is in use at this moment. Two ways to recognise it,
        // and a network needs the second one: the access points report a keyid
        // per station only for keys that came out of a psk file, and an iPSK
        // network has no such file. There the address the key is bound to (or
        // was last seen on) is the identity, so the station is matched by mac.
        $bound = strtolower((string) ($key->getMac() !== \ApManBundle\Entity\Ppsk::ANY_MAC
            ? $key->getMac() : $key->getLastMac()));
        $online = [];
        $query = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a');
        foreach ($query->getResult() as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status)) {
                continue;
            }
            $seen = [];
            foreach ((array) ($status['sta_ctrl'] ?? []) as $mac => $values) {
                if (is_array($values) && ($values['keyid'] ?? null) === $key->getKeyid()) {
                    $seen[strtolower((string) $mac)] = true;
                }
            }
            if ($bound && '' !== $bound && $key->getSsid() && $device->getSsid()
                && $device->getSsid()->getId() === $key->getSsid()->getId()) {
                foreach (array_keys((array) ($status['stations'] ?? [])) as $mac) {
                    if (strtolower((string) $mac) === $bound) {
                        $seen[$bound] = true;
                    }
                }
            }
            foreach (array_keys($seen) as $mac) {
                $signal = null;
                foreach ($status['assoclist']['results'] ?? [] as $entry) {
                    if (isset($entry['mac']) && strtolower($entry['mac']) === strtolower($mac)) {
                        $signal = $entry['signal'] ?? null;
                    }
                }
                $online[] = [
                    'mac' => $mac,
                    'ap' => $device->getRadio()->getAccessPoint()->getName(),
                    'ifname' => $device->ifname(),
                    'signal' => $signal,
                ];
            }
        }

        return $this->json([
            'ok' => true,
            // the key itself, so the interface can show it again later: it is
            // stored anyway, and a code that can only be seen once during
            // creation is of no use when somebody asks for it a day later
            'id' => $key->getId(),
            'name' => $key->getName(),
            'ssid' => $key->getSsid() ? $key->getSsid()->getName() : null,
            'psk' => $key->getPsk(),
            'keyid' => $key->getKeyid(),
            'qr' => $key->getSsid()
                ? $this->wifiQrPayload($key->getSsid(), $key->getPsk()) : null,
            'used' => $key->isUsed(),
            'first_seen' => $key->getFirstSeen() ? $key->getFirstSeen()->format('Y-m-d H:i:s') : null,
            'last_seen' => $key->getLastSeen() ? $key->getLastSeen()->format('Y-m-d H:i:s') : null,
            'last_mac' => $key->getLastMac(),
            'online' => $online,
        ]);
    }

    /**
     * Withdraw an issued key. The device using it loses its connection at once,
     * every other client keeps theirs.
     */
    #[Route(path: '/ipsk/{id}/revoke', name: 'ipsk_revoke', methods: ['POST'])]
    public function ipskRevokeAction(\ApManBundle\Service\PpskService $ppsk, Request $request, $id)
    {
        $key = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->find($id);
        if (!$key) {
            return $this->json(['ok' => false, 'error' => 'unknown key'], 404);
        }
        $purge = (bool) $request->get('purge');
        $name = $key->getName();
        $keyid = $key->getKeyid();

        try {
            $res = $ppsk->revoke($key, $purge);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
        }

        $summary = $this->distributionSummary($res['result'] ?? null);
        $reloaded = $summary['confirmed'];
        $ssid = $key->getSsid();
        $onAp = $ssid && $ppsk->usesOnApRadius($ssid);

        // What actually happened depends on how this network hands out keys,
        // and the difference is worth saying rather than hiding behind a
        // generic "saved".
        $disconnected = $res['disconnected'] ?? [];
        if ($disconnected) {
            $where = implode(', ', array_map(function ($e) {
                return $e['ap'].'/'.$e['ifname'];
            }, $disconnected));
            $hint = 'Withdrawn. The device was disconnected on '.$where
                .' and is turned away when it tries again.';
        } elseif ($onAp) {
            // No station was connected on this key, so nothing was kicked. The
            // access points already have the new key set; a device that shows
            // up again is refused as soon as they ask about it, which is at
            // its next association — and up to half a minute later if they
            // answered about it just before (hostapd caches that answer).
            $hint = 'Withdrawn on '.$summary['confirmed'].' access point(s). '
                .'No device was using it — one that returns is refused at its next '
                .'association, within about half a minute.';
        } elseif (isset($res['note'])) {
            $hint = 'Withdrawn — but '.$res['note'].'.';
        } elseif ($reloaded) {
            $hint = 'Withdrawn on '.$reloaded.' bss. A device still using this key lost its connection.';
        } else {
            $hint = 'Withdrawn. No device was using it.';
        }
        if (!$summary['ok'] && $summary['error']) {
            $hint .= ' The key set did not reach every access point: '.$summary['error'].'.';
        } elseif ($summary['pending']) {
            $hint .= ' '.$summary['pending'].' access point(s) did not confirm the new key set.';
        }
        if ($summary['skipped']) {
            $hint .= ' '.$summary['skipped'].' access point(s) were not waited for — they are '
                .'not reachable, and the key set is waiting for them at the broker.';
        }

        return $this->json([
            'ok' => true,
            'purged' => $purge,
            'name' => $name,
            'keyid' => $keyid,
            'reloaded' => $reloaded,
            'disconnected' => $disconnected,
            'hint' => $hint,
            'result' => $res['result'],
        ]);
    }

    /**
     * The payload of a "join this network" QR code, as every phone camera
     * understands it. The separators have to be escaped or a key containing
     * one would truncate the code.
     */
    private function wifiQrPayload($ssid, $psk, $hidden = false)
    {
        $escape = function ($value) {
            return preg_replace('/([\\\\;,:"])/', '\\\\$1', (string) $value);
        };

        // the ssid entity, not its name: the encryption decides the T field and
        // a caller that only had the name used to silently produce T:WPA
        $encryption = $ssid->exportConfig()->encryption ?? null;
        $ssid = $ssid->getName();

        // WPA3-only networks must advertise SAE: a T:WPA code makes clients
        // try WPA2, which a pure SAE network cannot answer. Mixed networks
        // stay T:WPA — WPA2 is available there.
        $type = 'WPA';
        if (null !== $encryption && '' !== $encryption) {
            $enc = strtolower((string) $encryption);
            if (preg_match('/sae|wpa3/', $enc) && !preg_match('/psk|wpa2|mixed/', $enc)) {
                $type = 'SAE';
            }
        }

        return 'WIFI:T:'.$type.';S:'.$escape($ssid).';P:'.$escape($psk).';'.
            ($hidden ? 'H:true;' : '').';';
    }

    /**
     * Adopt the wifi-station sections that already exist on an access point,
     * so the controller can take ownership without losing keys.
     */
    #[Route(path: '/ppsk/import/{apId}', name: 'ppsk_import', methods: ['POST'])]
    public function ppskImportAction(\ApManBundle\Service\PpskService $ppsk, $apId)
    {
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->find($apId);
        if (!$ap) {
            return $this->json(['ok' => false, 'error' => 'unknown access point'], 404);
        }
        $res = $ppsk->importFromUci($ap);

        return $this->json(['ok' => !isset($res['error'])] + $res);
    }

    /**
     * WPS always covers the whole SSID: the device being enrolled stands
     * somewhere, not next to one particular radio.
     */
    #[Route(path: '/ppsk/wps/{ssidId}', name: 'ppsk_wps', methods: ['POST'])]
    public function ppskWpsAction(Request $request, \ApManBundle\Service\PpskService $ppsk, $ssidId)
    {
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($ssidId);
        if (!$ssid) {
            return $this->json(['ok' => false, 'error' => 'unknown ssid'], 404);
        }
        $action = $request->request->get('action', 'start');

        if ('import' === $action) {
            $imported = $ppsk->importFromWps($ssid);
            if ($imported) {
                $ppsk->distribute($ssid);
                $ppsk->cancelWps($ssid);
            }

            return $this->json([
                'ok' => true,
                'imported' => count($imported),
                'macs' => array_map(function ($p) { return $p->getMac(); }, $imported),
            ]);
        }

        if ('cancel' === $action) {
            return $this->json($ppsk->cancelWps($ssid));
        }
        if ('status' === $action) {
            return $this->json($ppsk->wpsStatus($ssid));
        }
        if ('pin' === $action) {
            $pin = preg_replace('/[^0-9]/', '', (string) $request->request->get('pin', ''));
            if (8 !== strlen($pin)) {
                return $this->json(['ok' => false, 'error' => 'need an 8 digit pin'], 400);
            }

            return $this->json($ppsk->startWpsPin($ssid, $pin, (int) $request->request->get('timeout', 300)));
        }

        // push button over ubus: works without hostapd_cli and without a reload
        return $this->json($ppsk->startWps($ssid));
    }

    /**
     * Fleet overview built from what the apman agents report about themselves:
     * agent version and features, hostapd bss/mld topology, the bss config the
     * ap really runs (compared against ours), 802.11k/v counters and the
     * channel survey.
     */
    #[Route(path: '/aps', name: 'aps')]
    public function apsAction(\ApManBundle\Service\StateTreeService $stateTree)
    {
        $em = $this->doctrine->getManager();
        $cf = $this->cacheFactory;
        $aps = [];

        $query = $em->createQuery('SELECT a,r,d FROM ApManBundle\\Entity\\AccessPoint a
                LEFT JOIN a.radios r LEFT JOIN r.devices d ORDER BY a.name');
        foreach ($query->getResult() as $ap) {
            $agent = $cf->getCacheItemValue('status.ap.'.$ap->getId().'.agent');
            $online = $cf->getCacheItemValue('status.online['.$ap->getId().']');
            $state = $cf->getCacheItemValue('status.state['.$ap->getId().']');
            $row = [
                'name' => $ap->getName(),
                'productive' => $ap->getIsProductive(),
                'state' => \ApManBundle\Library\AccessPointState::getStateName($state),
                // this page builds plain rows rather than handing the entity to
                // the template, so the tree's verdict has to be fetched here
                'tree_state' => \ApManBundle\Library\NodeState::name(
                    \ApManBundle\Library\NodeState::TYPE_AP,
                    $stateTree->composedState(\ApManBundle\Library\NodeState::TYPE_AP, $ap->getId())),
                'online' => is_array($online) && isset($online['status']) ? $online['status'] : null,
                'agent' => is_array($agent) ? $agent : null,
                'devices' => [],
            ];

            foreach ($ap->getRadios() as $radio) {
                foreach ($radio->getDevices() as $device) {
                    $status = $cf->getCacheItemValue('status.device.'.$device->getId());
                    $bssInfo = $cf->getCacheItemValue('status.device.'.$device->getId().'.bss_info');
                    $survey = $cf->getCacheItemValue('status.device.'.$device->getId().'.survey');
                    $apStatus = is_array($status) && isset($status['ap_status']) && is_array($status['ap_status'])
                        ? $status['ap_status'] : [];
                    $hostapdStatus = is_array($status) && isset($status['hostapd_status']) && is_array($status['hostapd_status'])
                        ? $status['hostapd_status'] : [];

                    $ssid = $device->getSsid();
                    $row['devices'][] = [
                        'ifname' => $device->ifname(),
                        'ssid_configured' => $ssid ? $ssid->getName() : null,
                        'bss_info' => is_array($bssInfo) ? $bssInfo : null,
                        'drift' => $this->bssDrift($device, $bssInfo),
                        'security' => is_array($bssInfo) ? array_filter([
                            'wpa' => $bssInfo['wpa'] ?? null,
                            'key_mgmt' => $bssInfo['wpa_key_mgmt'] ?? null,
                            'pmf' => $bssInfo['ieee80211w'] ?? null,
                            'hw_mode' => $bssInfo['hw_mode'] ?? null,
                        ], function ($v) { return null !== $v; }) : null,
                        'mld' => isset($hostapdStatus['links']) ? $hostapdStatus['links'] : null,
                        'radio_idx' => isset($hostapdStatus['radio']) ? $hostapdStatus['radio'] : null,
                        'wiphy' => isset($hostapdStatus['wiphy']) ? $hostapdStatus['wiphy'] : null,
                        'running' => isset($hostapdStatus['running']) ? $hostapdStatus['running'] : null,
                        'channel' => isset($apStatus['channel']) ? $apStatus['channel'] : null,
                        'bss_color' => isset($apStatus['bss_color']) ? $apStatus['bss_color'] : null,
                        'rrm' => isset($apStatus['rrm']) ? $apStatus['rrm'] : null,
                        'wnm' => isset($apStatus['wnm']) ? $apStatus['wnm'] : null,
                        'survey' => $this->surveySummary($survey, isset($apStatus['channel']) ? $apStatus['channel'] : null),
                    ];
                }
            }
            $aps[] = $row;
        }

        return $this->render('default/aps.html.twig', [
            'aps' => $aps,
            'steering' => $this->steeringStats(),
        ]);
    }

    /**
     * What hostapd really runs versus what we configured. bss_info is only
     * published by agents on a ucode based hostapd.
     */
    private function bssDrift($device, $bssInfo)
    {
        if (!is_array($bssInfo)) {
            return null;
        }
        // only real mismatches count as drift; the running security settings
        // are informational and shown separately
        $drift = [];
        $ssid = $device->getSsid();
        if ($ssid && isset($bssInfo['ssid']) && $ssid->getName() != $bssInfo['ssid']) {
            $drift['ssid'] = ['configured' => $ssid->getName(), 'running' => $bssInfo['ssid']];
        }

        return $drift ?: null;
    }

    /**
     * Condense a channel survey into what a planner looks at: how busy the
     * channel we sit on is, and the quietest alternatives on the same band.
     */
    private function surveySummary($survey, $ownChannel)
    {
        if (!is_array($survey) || !isset($survey['results']) || !is_array($survey['results'])) {
            return null;
        }
        $rows = [];
        foreach ($survey['results'] as $r) {
            if (!isset($r['mhz']) || !isset($r['active_time']) || !$r['active_time']) {
                continue;
            }
            $busy = isset($r['busy_time']) ? $r['busy_time'] : 0;
            $rows[] = [
                'mhz' => $r['mhz'],
                'noise' => isset($r['noise']) ? $r['noise'] : null,
                'busy_pct' => round($busy * 100 / $r['active_time'], 1),
            ];
        }
        if (!$rows) {
            return null;
        }
        usort($rows, function ($a, $b) { return $a['busy_pct'] <=> $b['busy_pct']; });

        return ['best' => array_slice($rows, 0, 3), 'channels' => count($rows)];
    }

    /**
     * 802.11v steering effectiveness from the stored transition responses:
     * status-code 0 means the client accepted the move.
     */
    private function steeringStats()
    {
        $em = $this->doctrine->getManager();
        $query = $em->createQuery("SELECT e FROM ApManBundle\\Entity\\Event e
                WHERE e.type = 'bss-transition-response' AND e.ts > :since");
        $query->setParameter('since', new \DateTime('-24 hours'));
        $stats = ['total' => 0, 'accepted' => 0, 'rejected' => 0, 'by_client' => []];
        foreach ($query->getResult() as $event) {
            $data = json_decode($event->getEvent(), true);
            $code = is_array($data) && isset($data['status-code']) ? (int) $data['status-code'] : null;
            $mac = $event->getAddress();
            ++$stats['total'];
            if (!isset($stats['by_client'][$mac])) {
                $stats['by_client'][$mac] = ['accepted' => 0, 'rejected' => 0];
            }
            if (0 === $code) {
                ++$stats['accepted'];
                ++$stats['by_client'][$mac]['accepted'];
            } else {
                ++$stats['rejected'];
                ++$stats['by_client'][$mac]['rejected'];
            }
        }
        uasort($stats['by_client'], function ($a, $b) { return $b['rejected'] <=> $a['rejected']; });
        $stats['by_client'] = array_slice($stats['by_client'], 0, 15, true);

        return $stats;
    }

    #[Route(path: '/griddata')]
    public function gridDataAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
        $status = $this->getStatusDump($rpc, false);
        $s = [];
        foreach ($status['data'] as $apName => $apData) {
            foreach ($status['data'][$apName] as $ifName => $ifData) {
                $clients = [];
                if (isset($ifData['clients'])) {
                    foreach ($ifData['clients'] as $clientName => $clientData) {
                        $clients[$clientName] = true;
                    }
                }
                if (isset($ifData['assoclist'])) {
                    foreach ($ifData['assoclist'] as $clientName => $clientData) {
                        $clients[$clientName] = true;
                    }
                }
                if (isset($ifData['clientstats'])) {
                    foreach ($ifData['clientstats'] as $clientName => $clientData) {
                        $clients[$clientName] = true;
                    }
                }

                foreach ($clients as $clientName => $clientData) {
                    $key = $clientName.$apName.$ifName;
                    $client = [
                'ap' => $apName,
                'interface' => $ifName,
                'mac' => $clientName,
                'mac_private' => 'no',
                'interface_hardware_model' => null,
                'vlan_device' => null,
                'age' => null,
                'ssid' => null,
                'channel' => null,
                'frequency' => null,
                'authtype' => 'NONE',
                // which AKM the client really negotiated, not what the bss
                // offers — from the hostapd control channel
                'akm' => null,
                'cipher' => null,
                'identity' => null,
                'keyid' => null,
                'authenticated' => null,
                'associated' => null,
                'authorized' => null,
                'preauth' => null,
                'wds' => 'no',
                'wmm' => null,
                'mbo' => 'no',
                'ht_mode' => '',
                'wps' => null,
                'mfp' => null,
                'connected_time' => null,
                'inactive' => null,
                'rx_bytes' => null,
                'tx_bytes' => null,
                'rx_rate' => null,
                'tx_rate' => null,
                'signal' => null,
                'noise' => null,
                'ip' => null,
                'dnsname' => null,
                'manufacturer' => null,
                'authuser' => null,
                    ];
                    if (isset($ifData['clients'][$clientName]['signal'])) {
                        $client['signal'] = $ifData['clients'][$clientName]['signal'];
                    } elseif (isset($ifData['assoclist'][$clientName]['signal'])) {
                        $client['signal'] = $ifData['assoclist'][$clientName]['signal'];
                    }
                    $ctrl = $ifData['sta_ctrl'][$clientName] ?? null;
                    if (is_array($ctrl)) {
                        if (isset($ctrl['AKMSuiteSelector'])) {
                            $client['akm'] = self::AKM_SUITES[$ctrl['AKMSuiteSelector']]
                                ?? $ctrl['AKMSuiteSelector'];
                        }
                        if (isset($ctrl['dot11RSNAStatsSelectedPairwiseCipher'])) {
                            $client['cipher'] = self::CIPHER_SUITES[$ctrl['dot11RSNAStatsSelectedPairwiseCipher']]
                                ?? $ctrl['dot11RSNAStatsSelectedPairwiseCipher'];
                        }
                        if (isset($ctrl['keyid'])) {
                            $identity = $this->identityFor($ctrl['keyid']);
                            $client['keyid'] = $ctrl['keyid'];
                            $client['identity'] = $identity['name'] ?: $ctrl['keyid'];
                        }
                    }
                    // how stale is the state this row is built from? without
                    // this the grid happily shows clients of an ap that stopped
                    // reporting ten minutes ago
                    // prefer our own receive time: an access point without ntp
                    // stamps payloads from 1970
                    $stamp = $ifData['received'] ?? ($ifData['timestamp'] ?? null);
                    if ($stamp) {
                        $client['age'] = max(0, (int) (time() - $stamp));
                    }
                    // apman tags every assoclist entry with the interface it
                    // was seen on, which is the vlan slave for dynamic vlans
                    if (isset($ifData['assoclist'][$clientName]['device'])) {
                        $client['vlan_device'] = $ifData['assoclist'][$clientName]['device'];
                    }
                    if (isset($ifData['assoclist'][$clientName]['noise'])) {
                        $client['noise'] = $ifData['assoclist'][$clientName]['noise'];
                    }
                    if (isset($ifData['assoclist'][$clientName]['inactive'])) {
                        $client['inactive'] = intval($ifData['assoclist'][$clientName]['inactive'] / 1000);
                    }

                    if (isset($ifData['clients'][$clientName]['ht']) && intval($ifData['clients'][$clientName]['ht'])) {
                        $client['ht_mode'] = 'HT';
                    } elseif (isset($ifData['assoclist'][$clientName]['tx']['ht']) && $ifData['assoclist'][$clientName]['tx']['ht']) {
                        $client['ht_mode'] = 'HT';
                    }
                    if (isset($ifData['clients'][$clientName]['vht']) && intval($ifData['clients'][$clientName]['vht'])) {
                        $client['ht_mode'] = 'VHT';
                    } elseif (isset($ifData['assoclist'][$clientName]['tx']['vht']) && $ifData['assoclist'][$clientName]['tx']['vht']) {
                        $client['ht_mode'] = 'VHT';
                    }
                    if (isset($ifData['clients'][$clientName]['he']) && intval($ifData['clients'][$clientName]['he'])) {
                        $client['ht_mode'] = 'HE';
                    } elseif (isset($ifData['assoclist'][$clientName]['tx']['he']) && $ifData['assoclist'][$clientName]['tx']['he']) {
                        $client['ht_mode'] = 'HE';
                    }

                    if (isset($ifData['info']['hardware']['name'])) {
                        $client['interface_hardware_model'] = str_replace(
                            ['Qualcomm Atheros ', 'MediaTek ','/'],
                            ['','',' / '],
                            $ifData['info']['hardware']['name']
                        );
                    }
                    if (isset($ifData['info']['ssid']) && strlen($ifData['info']['ssid'])) {
                        $client['ssid'] = $ifData['info']['ssid'];
		    } elseif (isset($ifData['assoclist'][$clientName]['ssid']) && strlen($ifData['assoclist'][$clientName]['ssid'])) {
			    $client['ssid'] = $ifData['assoclist'][$clientName]['ssid'];
		    } else {
			    $client['ssid'] = 'xxx';
		    }
                    if (isset($ifData['info']['channel'])) {
                        $client['channel'] = $ifData['info']['channel'];
                    }
                    if (isset($ifData['info']['frequency'])) {
                        $client['frequency'] = $ifData['info']['frequency'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['authenticated'])) {
                        $client['authenticated'] = $ifData['clientstats'][$clientName]['authenticated'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['associated'])) {
                        $client['associated'] = $ifData['clientstats'][$clientName]['associated'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['authorized'])) {
                        $client['authorized'] = $ifData['clientstats'][$clientName]['authorized'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['preauth'])) {
                        $client['preauth'] = $ifData['clientstats'][$clientName]['preauth'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['wds'])) {
                        $client['wds'] = $ifData['clientstats'][$clientName]['wds'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['WMM_WME'])) {
                        $client['wmm'] = $ifData['clientstats'][$clientName]['WMM_WME'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['MFP'])) {
                        $client['mfp'] = $ifData['clientstats'][$clientName]['MFP'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['rx_bytes'])) {
                        $client['rx_bytes'] = $ifData['clientstats'][$clientName]['rx_bytes'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['tx_bytes'])) {
                        $client['tx_bytes'] = $ifData['clientstats'][$clientName]['tx_bytes'];
                    }
                    if (isset($ifData['clientstats'][$clientName]['tx_bitrate'])) {
                        $client['tx_rate'] = explode(' ', $ifData['clientstats'][$clientName]['tx_bitrate'], 2)[0];
                    }
                    if (isset($ifData['clientstats'][$clientName]['rx_bitrate'])) {
                        $client['rx_rate'] = explode(' ', $ifData['clientstats'][$clientName]['rx_bitrate'], 2)[0];
                    }
                    if (isset($ifData['clientstats'][$clientName]['connected_time'])) {
                        $client['connected_time'] = explode(' ', $ifData['clientstats'][$clientName]['connected_time'])[0];
                    }
                    if (isset($ifData['clientstats'][$clientName]['inactive_time'])) {
                        $client['inactive'] = explode(' ', $ifData['clientstats'][$clientName]['inactive_time'])[0]/1000;
                    }
                    if (isset($ifData['clients'][$clientName]['mbo']) && $ifData['clients'][$clientName]['mbo']) {
                        $client['mbo'] = 'yes';
                    }
                    if (isset($status['neighbors'][$clientName]['name'])) {
                        $client['dnsname'] = $status['neighbors'][$clientName]['name'];
                    }
                    if (isset($status['neighbors'][$clientName]['ip'])) {
                        $client['ip'] = $status['neighbors'][$clientName]['ip'];
                    }
                    if (isset($status['neighbors'][$clientName]['name'])) {
                        $client['dnsname'] = $status['neighbors'][$clientName]['name'];
                    }
                    if (isset($ifData['info']['encryption']['authentication'])) {
                        $client['authtype'] = join(' ', $ifData['info']['encryption']['authentication']);
                    }
                    $client['manufacturer'] = $status['apsrv']->getMacManufacturer($clientName);

                    if (preg_match('/^.[26AEae].*/', $clientName)) {
                        $client['mac_private'] = 'yes';
                    }

                    $auth = null;
                    $key = "client.authtablev2.".$client['ssid'].$clientName;
                    $value = $this->cacheFactory->getCacheItemValue($key);
                    if ($value && strlen($value)>2) {
                        if (is_string($value) && strlen($value)>2) {
                            $auth = @json_decode($value);
                        }
                    }
                    if (is_object($auth)) {
                        if (property_exists($auth, 'username')) {
                            $client['authuser'] = $auth->username;
                        }
                        if (property_exists($auth, 'auth')) {
			    if (property_exists($auth->auth, 'reply') and property_exists($auth->auth->reply, 'APMAN-PSK-Type') and $auth->auth->reply->{'APMAN-PSK-Type'} == 'ppsk') {
				if (isset($client['authtype'])) {
	                                $client['authtype'] .= ' ppsk';
				} else {
	                                $client['authtype'] = 'ppsk';
				}
                            }
                            if (property_exists($auth->auth, 'reply') and  property_exists($auth->auth->reply, 'APMAN-Client-Name') and strlen($auth->auth->reply->{'APMAN-Client-Name'})) {
                                $client['authuser'] = $auth->auth->reply->{'APMAN-Client-Name'};
                            }
                            if (property_exists($auth->auth, 'post_auth') and property_exists($auth->auth->post_auth, 'EAP-Type')) {
                                $client['authtype'] = 'EAP-'.$auth->auth->post_auth->{'EAP-Type'};
                            }
                        }
                    }
                    $client['authuser'] = str_replace('.kalnet.hooya.de', '', $client['authuser']);

                    $s[$key] = $client;
                }
            }
        }

        ksort($s);
        $s = array_values($s);
        $response = new JsonResponse(['results' => $s]);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    #[Route(path: '/disconnect')]
    public function disconnectAction(Request $request)
    {
        $doc = $this->doctrine;
        $system = $request->query->get('system', '');
        $device = $request->query->get('device', '');
        $mac = $request->query->get('mac', '');
        $ap = $doc->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy([
        'name' => $system,
    ]);
        $opts = new \stdClass();
        $opts->addr = $mac;
        $opts->reason = 5;
        $opts->deauth = false;
        $opts->ban_time = 10;
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$device, 'del_client', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    #[Route(path: '/deauth')]
    public function deauthAction(Request $request)
    {
        $doc = $this->doctrine;
        $system = $request->query->get('system', '');
        $device = $request->query->get('device', '');
        $ban_time = intval($request->query->get('ban_time', 0));
        $mac = $request->query->get('mac', '');
        $ap = $doc->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy([
        'name' => $system,
    ]);
        $opts = new \stdClass();
        $opts->addr = $mac;
        $opts->reason = 3;
        $opts->deauth = true;
        $opts->ban_time = $ban_time;
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$device, 'del_client', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    #[Route(path: '/wnm_disassoc_imminent_prepare')]
    public function wnmDisassocImminentPrepare(Request $request)
    {
        if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device'))) {
            return $this->redirect($this->generateUrl('apman_default_index'));
        }
        $query = $em->createQuery(
            'SELECT d
			FROM ApManBundle\Entity\Device d
			LEFT JOIN d.radio r
			LEFT JOIN r.accesspoint a
                        WHERE d.ifname = :ifname AND a.name = :ap'
        );
        $query->setParameter('ifname', $device);
        $query->setParameter('ap', $system);
        try {
            $tmp = $query->getSingleResult();
            $deviceId = $tmp->getId();
            $ssid = $tmp->getSSID();
        } catch (\Doctrine\Orm\NoResultException $e) {
            $this->logger->error('No device found.');
            exit();
        }

        return $this->render(
            'default/wnm_disassoc_imminent.html.twig',
            [
            'devices' => $ssid->getDevices(),
            'mac' => $request->get('mac'),
            'system' => $request->get('system'),
            'device' => $request->get('device'),
            'ssid' => $request->get('ssid'),
        ]
        );
    }

    /**
     * https://docs.samsungknox.com/admin/knox-platform-for-enterprise/kbas/kba-115013403768.htm
     */
    #[Route(path: '/wnm_disassoc_imminent')]
    public function wnmDisassocImminent(Request $request)
    {
        if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
            echo "Params missing.\n";
            exit();

            return $this->redirect($this->generateUrl('apman_default_index'));
        }
        $opts = new \stdClass();
        $opts->addr = $request->get('mac');
        $opts->duration = 1 * 20;
        $opts->neighbors = [];

        if (!empty($request->get('disassociation_imminent')) and !empty($request->get('disassociation_timer'))) {
            $opts->disassociation_imminent = $request->get('disassociation_imminent') > 0 ? true : false;
            $opts->disassociation_timer = $request->get('disassociation_timer');
        }

        /*
            Required:
            addr: String - MAC-address of the STA to send the request to (colon-seperated)

            Optional:
            abridged - Bool - Indicates if the abridged flag is set, meaning neighbors list should be preferred
            disassociation_imminent: Bool - Whether or not the disassoc_imminent
                                     flag is set
            disassociation_timer: I32 - number of TBTTs after which the client will
                                  be disassociated
            validity_period: I32 - number of TBTTs after which the beacon
                             candidate list (if included) will be invalid
            neighbors: blob-array - Array of strings containing neighbor reports as
                       hex-string

         */
        if ($request->get('target') > 0) {
            $targetDev = $this->doctrine->getRepository('ApManBundle\Entity\Device')->findOneBy([
            'id' => $request->get('target'),
        ]);
            $rrm = $targetDev->getRrm();
            $rrm = json_decode(json_encode($rrm));
            if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                $this->logger->error('wndDisassocImminent(): Failed to get rrm.');

                return $this->redirect($this->generateUrl('apman_default_index'));
            }
            $opts->neighbors = [$rrm->value[2]];
            $opts->abridged = true;
        }

        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy([
        'name' => $request->get('system'),
    ]);

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$request->get('device'), 'wnm_disassoc_imminent', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    #[Route(path: '/bss_transition_request_prepare')]
    public function wnmBssTransitionPrepare(Request $request, \ApManBundle\Service\SteeringService $steering)
    {
        $mac = strtolower((string) $request->get('mac'));
        if ('' === $mac) {
            return $this->redirect($this->generateUrl('apman_default_index'));
        }
        $em = $this->doctrine->getManager();

        // where is the client now, and what does it hear
        $current = null;
        $devices = [];
        $query = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a');
        foreach ($query->getResult() as $device) {
            $devices[strtolower((string) $device->getAddress())] = $device;
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status) || !isset($status['assoclist']['results'])) {
                continue;
            }
            foreach ($status['assoclist']['results'] as $entry) {
                if (isset($entry['mac']) && strtolower($entry['mac']) === $mac) {
                    $current = [
                        'device' => $device,
                        'signal' => $entry['signal'] ?? null,
                        'hostapd' => $status['clients']['clients'][$mac] ?? [],
                    ];
                }
            }
        }

        $heard = $steering->heardByClient($mac, 86400);
        $state = $steering->getState($mac);
        $preferred = [];
        foreach ($state['client_candidates'] ?? [] as $candidate) {
            if (!empty($candidate['bssid'])) {
                $preferred[strtolower($candidate['bssid'])] = true;
            }
        }
        $currentBssid = $current && $current['device']->getAddress()
            ? strtolower($current['device']->getAddress()) : null;
        $currentHeard = $currentBssid ? ($heard[$currentBssid] ?? null) : null;

        // every bss of this ssid as a candidate, ranked by what the client hears
        $candidates = [];
        $ssidEntity = $current ? $current['device']->getSsid() : null;
        if ($ssidEntity) {
            $q = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                    LEFT JOIN d.radio r LEFT JOIN r.accesspoint a
                    WHERE d.ssid = :ssid');
            $q->setParameter('ssid', $ssidEntity);
            foreach ($q->getResult() as $device) {
                $bssid = strtolower((string) $device->getAddress());
                $isCurrent = $currentBssid && $bssid === $currentBssid;
                $candidates[] = [
                    'id' => $device->getId(),
                    'ap' => $device->getRadio()->getAccessPoint()->getName(),
                    'ifname' => $device->ifname(),
                    'band' => $device->getRadio()->getConfigBand(),
                    'htmode' => method_exists($device->getRadio(), 'getConfigHtmode') ? $device->getRadio()->getConfigHtmode() : null,
                    'bssid' => $bssid ?: null,
                    'heard' => $heard[$bssid] ?? null,
                    'gain' => (null !== $currentHeard && isset($heard[$bssid])) ? round($heard[$bssid] - $currentHeard, 1) : null,
                    'current' => $isCurrent,
                    'preferred' => isset($preferred[$bssid]),
                    'blocked' => $bssid ? $steering->isBlocked($mac, $bssid) : false,
                    'has_rrm' => null !== $device->getRrm(),
                ];
            }
        }
        usort($candidates, function ($a, $b) {
            return [$b['preferred'], $b['heard'] ?? -999] <=> [$a['preferred'], $a['heard'] ?? -999];
        });

        return $this->render('default/bss_transition_request.html.twig', [
            'mac' => $mac,
            'system' => $request->get('system', $current ? $current['device']->getRadio()->getAccessPoint()->getName() : ''),
            'device' => $request->get('device', $current ? $current['device']->ifname() : ''),
            'ssid' => $ssidEntity ? $ssidEntity->getName() : $request->get('ssid'),
            'current' => $current,
            'current_heard' => $currentHeard,
            'candidates' => $candidates,
            'state' => $state,
            'taxonomy' => $current ? $this->describeTaxonomy($current['hostapd']['signature'] ?? null) : null,
            'min_gain' => \ApManBundle\Service\SteeringService::MIN_GAIN_DB,
        ]);
    }

    /**
     * Send a transition request and wait for the client's answer, decoded.
     */
    #[Route(path: '/bss_transition_request/send', name: 'bss_transition_send', methods: ['POST'])]
    public function wnmBssTransitionSend(Request $request, \ApManBundle\Service\SteeringService $steering, \ApManBundle\Service\ClientCommandService $commands)
    {
        $mac = strtolower((string) $request->request->get('mac'));
        $system = (string) $request->request->get('system');
        $ifname = (string) $request->request->get('device');
        if ('' === $mac || '' === $system || '' === $ifname) {
            return $this->json(['ok' => false, 'error' => 'mac, system and device are required'], 400);
        }
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy(['name' => $system]);
        if (!$ap) {
            return $this->json(['ok' => false, 'error' => 'unknown access point'], 404);
        }

        $opts = new \stdClass();
        $opts->addr = $mac;
        $opts->abridged = (bool) $request->request->get('abridged', true);
        $opts->disassociation_imminent = (bool) $request->request->get('disassociation_imminent', false);
        if ($opts->disassociation_imminent) {
            $opts->disassociation_timer = (int) $request->request->get('disassociation_timer', 150);
        }
        $opts->neighbors = [];

        $targetId = (int) $request->request->get('target', 0);
        $targetName = 'client decides';
        if ($targetId > 0) {
            $targetDev = $this->doctrine->getRepository('ApManBundle\Entity\Device')->find($targetId);
            if (!$targetDev) {
                return $this->json(['ok' => false, 'error' => 'unknown target'], 404);
            }
            $rrm = json_decode(json_encode($targetDev->getRrm()));
            if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                return $this->json(['ok' => false, 'error' => 'the target has no neighbour report yet, so it cannot be named']);
            }
            $opts->neighbors = [$rrm->value[2]];
            $targetName = $targetDev->getRadio()->getAccessPoint()->getName().'/'.$targetDev->ifname();
            if ($targetDev->getAddress()) {
                $steering->markPending($mac, $targetDev->getAddress());
            }
        }

        $before = new \DateTime();
        // fan out: whichever bss actually holds the client carries it out, the
        // others answer "not found". A client that roamed since the last status
        // message is still reached.
        $sent = $commands->send($mac, 'bss_transition_request', $opts, ['scope' => 'ssid', 'wait' => 5]);
        if (isset($sent['error'])) {
            return $this->json(['ok' => false, 'error' => $sent['error']]);
        }
        $this->logger->notice('bssTransition(): '.$mac.' to '.$targetName.
            ' — executed on '.(implode(', ', $sent['executed']) ?: 'nobody'));

        // wait for the answer; it arrives as a notification and is stored as an event
        $em = $this->doctrine->getManager();
        $answer = null;
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $events = $em->createQuery("SELECT e FROM ApManBundle\Entity\Event e
                    WHERE e.address = :mac AND e.type = 'bss-transition-response' AND e.ts > :since
                    ORDER BY e.ts DESC")
                ->setParameter('mac', $mac)->setParameter('since', $before)
                ->setMaxResults(1)->getResult();
            if ($events) {
                $answer = json_decode($events[0]->getEvent(), true);
                break;
            }
            usleep(400000);
        }
        $em->clear(\ApManBundle\Entity\Event::class);

        if (!$answer) {
            return $this->json([
                'ok' => true, 'sent' => true, 'target' => $targetName,
                'answered' => false,
                'executed_on' => $sent['executed'],
                'note' => $sent['executed']
                    ? 'sent via '.implode(', ', $sent['executed']).
                      ' — no answer within 8 s, the client ignored the request'
                    : 'no access point holds this client right now, nothing was sent',
            ]);
        }

        $parsed = $steering->parseCandidateList($answer['candidate-list'] ?? '');
        $accepted = empty($answer['status-code']);

        return $this->json([
            'ok' => true, 'sent' => true, 'target' => $targetName, 'answered' => true,
            'executed_on' => $sent['executed'],
            'accepted' => $accepted,
            // the numeric code is lost in transport, the mbo attribute carries the reason
            'reason' => $parsed['reason_text'],
            'candidates' => $parsed['candidates'],
            'state' => $steering->getState($mac),
        ]);
    }

    /**
     * https://docs.samsungknox.com/admin/knox-platform-for-enterprise/kbas/kba-115013403768.htm
     */
    #[Route(path: '/bss_transition_request')]
    public function wnmBssTransitionRequest(Request $request)
    {
        if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
            echo "Params missing.\n";
            exit();

            return $this->redirect($this->generateUrl('apman_default_index'));
        }
        $opts = new \stdClass();
        $opts->addr = $request->get('mac');
        $opts->abridged = true;
        $opts->disassociation_imminent = false;
        $opts->neighbors = [];

        if (intval($request->get('disassociation_imminent', 0))) {
            $opts->disassociation_imminent = true;
            $opts->disassociation_timer = 150;
        }

        if ($request->get('target') > 0) {
            $targetDev = $this->doctrine->getRepository('ApManBundle\Entity\Device')->findOneBy([
            'id' => $request->get('target'),
        ]);
            $rrm = $targetDev->getRrm();
            $rrm = json_decode(json_encode($rrm));
            if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                $this->logger->error('wnmBssTransitionRequest(): Failed to get rrm.');

                return $this->redirect($this->generateUrl('apman_default_index'));
            }
            $opts->neighbors = [$rrm->value[2]];
        }

        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy([
        'name' => $request->get('system'),
    ]);

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$request->get('device'), 'bss_transition_request', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    #[Route(path: '/rrm_beacon_req')]
    public function rrmBeaconRequest(Request $request)
    {
        if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
            echo "Params missing.\n";
            exit();

            return $this->redirect($this->generateUrl('apman_default_index'));
        }
        /* {"addr":"08:c5:e1:ad:ca:dd", "op_class":0, "channel":-1, "duration":2,"mode":2,"bssid":"ff:ff:ff:ff:ff:ff", "ssid":"kalnet"} */
        $opts = new \stdClass();
        $opts->addr = $request->get('mac');
        $opts->op_class = 0;
        $opts->channel = -1;
        $opts->duration = 20;
        $opts->mode = 2;
        $opts->bssid = 'ff:ff:ff:ff:ff:ff';
        $opts->ssid = $request->get('ssid');

        if ($request->get('target') > 0) {
            $targetDev = $this->doctrine->getRepository('ApManBundle\Entity\Device')->findOneBy([
            'id' => $request->get('target'),
        ]);
            $rrm = $targetDev->getRrm();
            $rrm = json_decode(json_encode($rrm));
            if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                $this->logger->error('rrmBeaconRequest(): Failed to get rrm.');

                return $this->redirect($this->generateUrl('apman_default_index'));
            }
            $opts->neighbors = [$rrm->value[2]];
            $opts->abridged = true;
        }

        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy([
        'name' => $request->get('system'),
    ]);

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$request->get('device'), 'rrm_beacon_req', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    #[Route(path: '/station')]
    public function stationAction(Request $request)
    {
        $doc = $this->doctrine;
        $em = $doc->getManager();
        $system = $request->query->get('system', '');
        $device = $request->query->get('device', '');
        $mac = $request->query->get('mac', '');
        $output = '';
        $output .= '<pre>';
        $output .= "System: '$system'\nDevice: '$device'\n";
        $output .= "\n";

        /*
        $key = 'status.client['.str_replace(':', '', $mac).'].raw_elements';
        $raw_elements = $this->cacheFactory->getCacheItemValue($key);
        if (strlen($raw_elements)) {
            //$output.=$raw_elements."\n";
            $ieTags = $this->ieparser->parseInformationElements(hex2bin($raw_elements));
            $output.="Information elements transmitted on probe:\n";
            $output.=print_r($this->ieparser->getResolveIeNames($ieTags),true);
            $output.="\n";
            $ieCaps = $this->ieparser->getExtendedCapabilities($ieTags);
            $output.="Capabilities on probe:\n";
            $output.=print_r($ieCaps,true);
        }
     */

        $query = $em->createQuery(
            'SELECT d
			FROM ApManBundle\Entity\Device d
			LEFT JOIN d.radio r
			LEFT JOIN r.accesspoint a
                        WHERE d.ifname = :ifname AND a.name = :ap'
        );
        $query->setParameter('ifname', $device);
        $query->setParameter('ap', $system);
        try {
            $tmp = $query->getSingleResult();
            $deviceId = $tmp->getId();
        } catch (\Doctrine\Orm\NoResultException $e) {
            $this->logger->error('No device found.');
            exit();
        }

        $status = $this->cacheFactory->getCacheItemValue('status.device.'.$deviceId);
        if (is_array($status) && is_array($status['clients']) && isset($status['clients']['clients'][$mac]) && isset($status['clients']['clients'][$mac]['signature'])) {
            $ieTags = $this->ieparser->parseSignature($status['clients']['clients'][$mac]['signature']);
            $output .= "Information elements on association:\n";
            $output .= json_encode($this->ieparser->getResolveIeNames($ieTags), JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
            $output .= "\n";
            $ieCaps = $this->ieparser->getExtendedCapabilities($ieTags);
            $output .= "Capabilities on association:\n";
            $output .= json_encode($ieCaps, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
        }

        //print("Device Stats: ".'status.device.'.$deviceId." \n");
        if (is_array($status) && is_array($status['stations'])) {
            if (isset($status['stations'][$mac])) {
                $output .= "Station Status:\n";
                $output .= json_encode($status['stations'][$mac], JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
            }
        }
        if (is_array($status) && is_array($status['clients'])) {
            if (isset($status['clients']['clients'][$mac])) {
                $output .= "Station hostapd Status:\n";
                $output .= json_encode($status['clients']['clients'][$mac], JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
            }
        }
        if (is_array($status) && isset($status['assoclist']) && is_array($status['assoclist'])) {
            if (isset($status['assoclist']['results']) && is_array($status['assoclist']['results'])) {
                foreach ($status['assoclist']['results'] as $r) {
                    if (isset($r['mac']) && strtolower($r['mac']) == strtolower($mac)) {
                        $output .= "Station Association List Entry:\n";
                        $output .= json_encode($r, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
                    }
                }
            }
        }
        if (is_array($status) && is_array($status['info'])) {
            $output .= "AP Device Status:\n";
            $output .= json_encode($status['info'], JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
        }

        if (is_array($status) && is_array($status['ap_status'])) {
            $output .= "AP hostapd Status:\n";
            $output .= json_encode($status['ap_status'], JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
        }

        // Get authtable from Cache
        $auth = null;
        $key = "client.authtablev2.".$status['ap_status']['ssid'].$mac;
        $value = $this->cacheFactory->getCacheItemValue($key);
        if ($value && strlen($value)>2) {
            $auth = @json_decode($value);
        }
        if (is_object($auth)) {
            $output .= "Radius Authentication Request:\n";
            $output .= json_encode($auth, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
        }

        /*
        $heatmap = [];
        $query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
            LEFT JOIN d.radio r
            LEFT JOIN r.accesspoint a
            ORDER by d.id DESC
        ");
        $devices = $query->getResult();
        $keys = [];
        $devById = [];
        foreach ($devices as $device) {
            $devById[ $device->getId() ] = $device;
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
                $heatmap[ $probe->address ] = array();
            }

            $hme = new \ApManBundle\Entity\ClientHeatMap();
            $hme->setTs($probe->ts);
            $hme->setAddress($probe->address);
            $hme->setDevice($devById[ $probe->device ]);
            $hme->setEvent($probe->event);
            if (property_exists($probe, 'signalstr')) {
                $hme->setSignalstr($probe->signalstr);
            }
            $heatmap[ $probe->address ][] = $hme;
        }
         */

        $query = $em->createQuery("SELECT e FROM \ApManBundle\Entity\Event e
		LEFT JOIN e.device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		WHERE e.address = :mac
		ORDER by e.ts DESC,e.id DESC
	");
        $query->setParameter('mac', $mac);
        $events = $query->getResult();
        $output .= '</pre>';
        $output .= "Events<br>\n";
        //$output.= print_r($events, true);
        $output .= "<table border=1>\n";
        foreach ($events as $event) {
            $output .= '<tr>';
            $output .= '<td>';
            $output .= $event->getTs()->format('Y-m-d H:i:s');
            $output .= '</td>';

            $output .= '<td>';
            $output .= $event->getDevice()->getRadio()->getAccessPoint()->getName();
            $output .= '</td>';

            $output .= '<td>';
            $output .= $event->getDevice()->getRadio()->getConfigBand();
            $output .= '</td>';

            $output .= '<td>';
            $output .= $event->getDevice()->getSsid();
            $output .= '</td>';

            $output .= '<td>';
            $output .= $event->getType();
            $output .= '</td>';

            $output .= '<td>';
            $output .= $event->getEvent();
            $output .= '</td>';
            $output .= '</tr>';
        }
        $output .= '</table>';

        return new Response($output);
    }

    #[Route(path: '/chtest')]
    public function chtest(Request $request)
    {
        $stdin = fopen('php://stdin', 'r');
        while (!feof($stdin)) {
            $buffer = fgets($stdin, 4096);
            echo "B: $buffer\n";
            ob_flush();
        }
        exit();
    }

    /**
     * Helper.
     */
    /**
     * @deprecated use StatusService directly; kept so existing callers work
     */
    public function getStatusDump(\ApManBundle\Service\wrtJsonRpc $rpc, $withHeatmap = true)
    {
        return $this->statusService->getStatusDump($rpc, $withHeatmap);
    }
}
