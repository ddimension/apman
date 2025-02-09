<?php

namespace ApManBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends Controller
{
    private $logger;
    private $apservice;
    private $doctrine;
    private $rpcService;
    private $ieparser;
    private $mqttFactory;
    private $cacheFactory;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \ApManBundle\Service\AccessPointService $apservice,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\wrtJsonRpc $rpcService,
        \ApManBundle\Service\WifiIeParser $ieparser,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Factory\CacheFactory $cacheFactory
    ) {
        $this->logger = $logger;
        $this->apservice = $apservice;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->ieparser = $ieparser;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
        $this->cacheFactory->getCache();
    }

    /**
     * @Route("/")
     */
    public function indexAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
        return $this->render('default/grid.html.twig', [
    ]);
    }

    /**
     * @Route("/oldStatus")
     */
    public function indexOldAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
        $status = $this->getStatusDump($rpc);

        return $this->render('default/clients.html.twig', $status);
    }

    /**
     * @Route("/griddata")
     */
    public function gridDataAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
        $status = $this->getStatusDump($rpc);
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
			    'ssid' => null,
			    'channel' => null,
			    'frequency' => null,
			    'authtype' => 'NONE',
			    'authenticated' => null,
			    'associated' => null,
			    'authorized' => null,
			    'preauth' => null,
			    'wds' => null,
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
                    ];
                    if (isset($ifData['clients'][$clientName]['signal'])) {
                        $client['signal'] = $ifData['clients'][$clientName]['signal'];
                    } elseif (isset($ifData['assoclist'][$clientName]['signal'])) {
                        $client['signal'] = $ifData['assoclist'][$clientName]['signal'];
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
                        $ifData['info']['hardware']['name']);
                    }
                    if (isset($ifData['info']['ssid'])) {
                        $client['ssid'] = $ifData['info']['ssid'];
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
                        $client['inactive'] = explode(' ', $ifData['clientstats'][$clientName]['inactive_time'])[0];
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

    /**
     * @Route("/disconnect")
     */
    public function disconnectAction(Request $request)
    {
        $doc = $this->doctrine;
        $system = $request->query->get('system', '');
        $device = $request->query->get('device', '');
        $mac = $request->query->get('mac', '');
        $ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy([
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
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device, 'del_client', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    /**
     * @Route("/deauth")
     */
    public function deauthAction(Request $request)
    {
        $doc = $this->doctrine;
        $system = $request->query->get('system', '');
        $device = $request->query->get('device', '');
        $ban_time = intval($request->query->get('ban_time', 0));
        $mac = $request->query->get('mac', '');
        $ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy([
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
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device, 'del_client', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    /**
     * @Route("/wnm_disassoc_imminent_prepare")
     */
    public function wnmDisassocImminentPrepare(Request $request)
    {
        if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device'))) {
            return $this->redirect($this->generateUrl('apman_default_index'));
        }
        $query = $em->createQuery(
            'SELECT d
			FROM ApManBundle:Device d
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

        return $this->render('default/wnm_disassoc_imminent.html.twig',
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
     * @Route("/wnm_disassoc_imminent")
     * https://docs.samsungknox.com/admin/knox-platform-for-enterprise/kbas/kba-115013403768.htm
     */
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
            $targetDev = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy([
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

        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
        'name' => $request->get('system'),
    ]);

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$request->get('device'), 'wnm_disassoc_imminent', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    /**
     * @Route("/bss_transition_request_prepare")
     */
    public function wnmBssTransitionPrepare(Request $request)
    {
        if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
            return $this->redirect($this->generateUrl('apman_default_index'));
        }
        $ssid = $this->doctrine->getRepository('ApManBundle:SSID')->findOneBy([
        'name' => $request->get('ssid'),
    ]);

        return $this->render('default/bss_transition_request.html.twig',
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
     * @Route("/bss_transition_request")
     * https://docs.samsungknox.com/admin/knox-platform-for-enterprise/kbas/kba-115013403768.htm
     */
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
            $targetDev = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy([
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

        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
        'name' => $request->get('system'),
    ]);

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$request->get('device'), 'bss_transition_request', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    /**
     * @Route("/rrm_beacon_req")
     */
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
            $targetDev = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy([
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

        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
        'name' => $request->get('system'),
    ]);

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error($ap->getName().': Failed to get mqtt client.');

            return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$request->get('device'), 'rrm_beacon_req', $opts);
        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        $res = $client->publish($topic, json_encode($cmd), 1);
        $client->disconnect();

        return $this->redirect($this->generateUrl('apman_default_index'));
    }

    /**
     * @Route("/station")
     */
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
			FROM ApManBundle:Device d
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

    /**
     * @Route("/wps_pin_requests")
     */
    public function wpsPinRequests(Request $request)
    {
        $apsrv = $this->apservice;
        $wpsPendingRequests = $apsrv->getPendingWpsPinRequests();

        return $this->render('ApManBundle:Default:wps_pin_requests.html.twig', [
        'wpsPendingRequests' => $wpsPendingRequests,
        ]);
    }

    /**
     * @Route("/wps_pin_requests_ack")
     */
    public function wpsPinRequestAck(Request $request)
    {
        // client_uuid=ac998afb-1cea-5cd7-a63c-2f817e3f466b&ap_id=24&ap_if=wap-knet0&wps_pin=XXXX

        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->find(
        $request->query->get('ap_id')
    );
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $logger->debug('Failed to log in to: '.$ap->getName());

            return false;
        }
        $opts = new \stdClass();
        $opts->command = 'hostapd_cli';
        $opts->params = [
        '-i',
        $request->query->get('ap_if'),
        'wps_pin',
        $request->query->get('client_uuid'),
        $request->query->get('wps_pin'),
    ];
        $opts->env = ['LC_ALL' => 'C'];
        $stat = $session->callCached('file', 'exec', $opts, 5);
        print_r($stat);
        if (!is_object($stat) and !property_exists($stat, 'code')) {
            return false;
        }

        return $this->render('ApManBundle:Default:wps_pin_requests.html.twig', [
//	    'wpsPendingRequests' => $wpsPendingRequests
        ]);
    }

    /**
     * @Route("/radius/user//mac/{called}")
     * @Route("/radius/user/{username}/mac/{called}")
     * @Route("/radius/user//sessions/")
     * @Route("/radius/user/{username}/sessions/")
     */
    public function receiveRadius(Request $request, $caller = null, $called = null)
    {
        //$this->logger->info($request->request->all());
        $response = new JsonResponse();
        $response->setStatusCode(JsonResponse::HTTP_NO_CONTENT);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
        //@Route("/radius/user/{caller}")
    }

    /**
     * @Route("/chtest")
     */
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
    public function getStatusDump(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
        $logger = $this->logger;
        $apsrv = $this->apservice;
        $doc = $this->doctrine;
        $em = $doc->getManager();

        $neighbors = [];
        $firewall_host = $this->container->getParameter('firewall_url');
        $firewall_user = $this->container->getParameter('firewall_user');
        $firewall_pwd = $this->container->getParameter('firewall_password');

        // read dhcpd leases
        $output = [];
        $result = null;
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
                } else {
                    $name = gethostbyaddr($data[3]);
                    if ($name == $data[3]) {
                        continue;
                    }
                    $neighbors[$data[1]]['name'] = $name;
                }
            }
        }

        $query = $em->createQuery("SELECT c FROM ApManBundle\Entity\Client c");
        $result = $query->getResult();
        foreach ($result as $client) {
            $mac = $client->getMac();
            $neighbors[$mac] = [];
            $neighbors[$mac]['name'] = $client->getName();
        }

        if ($firewall_host) {
            $logger->debug('Building MAC cache');
            $session = $rpc->login($firewall_host, $firewall_user, $firewall_pwd);
            $logger->debug('Result of firewall login:', ['session' => $session, 'host' => $firewall_host, 'user' => $firewall_user]);
            if (false !== $session) {
                // Read dnsmasq leases
                $opts = new \stdclass();
                $opts->command = 'cat';
                $opts->params = ['/tmp/dhcp.leases'];
                $stat = $session->call('file', 'exec', $opts);

                $logger->debug('L0', ['stat' => $stat]);
                if (property_exists($stat, 'stdout') && is_array($stat->stdout)) {
                    $logger->debug('L1');
                    $lines = explode("\n", $stat->stdout);
                    foreach ($lines as $line) {
                        $logger->debug('L', ['line' => $line]);
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
                $opts->command = 'ip';
                $opts->params = ['-4', 'neighb'];
                $stat = $session->call('file', 'exec', $opts);
                $logger->debug('L1', ['stat' => $stat]);
                $lines = explode("\n", $stat->stdout);
                foreach ($lines as $line) {
                    $ds = explode(' ', $line);
                    if (!array_key_exists(4, $ds)) {
                        continue;
                    }
                    $mac = strtolower($ds[4]);
                    if (strlen($mac)) {
                        if (array_key_exists($mac, $neighbors) && array_key_exists('name', $neighbors[$mac])) {
                            //continue;
                        }
                        $neighbors[$mac] = ['ip' => $ds[0]];
                        $cache = $this->get('session')->get('name_cache', null);
                        if (!is_array($cache)) {
                            $cache = [];
                        }
                        if (array_key_exists($mac, $cache)) {
                            $name = $cache[$mac];
                            if (false === $name) {
                                $logger->debug('skipping because of negative entry: '.$name);
                                continue;
                            }
                            $logger->debug('found '.$name);
                        } else {
                            $name = gethostbyaddr($ds[0]);
                            if ($name == $ds[0]) {
                                $name = '';
                            }
                            if (empty($name)) {
                                $cache[$mac] = false;
                            } else {
                                $cache[$mac] = $name;
                            }
                        }
                        if ($name) {
                            $neighbors[$mac]['name'] = $name;
                        }
                        $this->get('session')->set('name_cache', $cache);
                    }
                }
            }
            $logger->debug('MAC cache complete');
        }
        $aps = $doc->getRepository('ApManBundle:AccessPoint')->findAll();
        $logger->debug('Cache', ['cache' => $cache]);
        $logger->debug('Logging in to all APs');
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
                    $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
                    $ifname = $device->getIfname();
                    if (null === $status) {
                        continue;
                    }
                    if (!isset($status['info'])) {
                        continue;
                    }

                    $data[$sessionId][$ifname] = [];
                    $data[$sessionId][$ifname]['board'] = $this->cacheFactory->getCacheItemValue('status.ap.'.$ap->getId());
                    $data[$sessionId][$ifname]['info'] = $status['info'];
                    $data[$sessionId][$ifname]['assoclist'] = [];
                    $data[$sessionId][$ifname]['deviceId'] = $device->getId();
                    if (array_key_exists('assoclist', $status)) {
                        foreach ($status['assoclist']['results'] as $entry) {
                            $mac = strtolower($entry['mac']);
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
            usort($ps, function ($a, $b) {return $a->getTs() < $b->getTs(); });
            $heatmap[$pa] = $ps;
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
