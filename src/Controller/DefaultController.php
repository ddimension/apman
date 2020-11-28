<?php

namespace ApManBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DefaultController extends Controller
{
    private $logger;
    private $apservice;
    private $doctrine;
    private $rpcService;
    private $ssrv;

    public function __construct(
	    \Psr\Log\LoggerInterface $logger,
	    \ApManBundle\Service\AccessPointService $apservice,
	    \Doctrine\Persistence\ManagerRegistry $doctrine,
	    \ApManBundle\Service\wrtJsonRpc $rpcService,
	    \ApManBundle\Service\SubscriptionService $ssrv
    )
    {
	    $this->logger = $logger;
	    $this->apservice = $apservice;
	    $this->doctrine = $doctrine;
	    $this->rpcService = $rpcService;
	    $this->ssrv = $ssrv;
    }

    /**
     * @Route("/")
     */
    public function indexAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
	$logger = $this->logger;
	$apsrv = $this->apservice;
        $doc = $this->doctrine;
	$em = $doc->getManager();

	$neighbors = array();
	$firewall_host = $this->container->getParameter('firewall_url');
	$firewall_user = $this->container->getParameter('firewall_user');
	$firewall_pwd = $this->container->getParameter('firewall_password');

	// read dhcpd leases
	$output = array();
	$result = NULL;
	exec('dhcp-lease-list  --parsable', $lines, $result);
	if ($result == 0) {
		foreach ($lines as $line) {
			if (substr($line,0,4) != 'MAC ') continue;
			$data = explode(' ', $line);
			$neighbors[ $data[1] ]['ip'] = $data[3];
			if ($data[5] != '-NA-') {
				$neighbors[ $data[1] ]['name'] = $data[5];
			} else {
				$name = gethostbyaddr($data[3]);
				if ($name == $data[3]) continue;
				$neighbors[ $data[1] ]['name'] = $name;
			}
		}
	}

	$query = $em->createQuery("SELECT c FROM ApManBundle\Entity\Client c");
	$result = $query->getResult();
	foreach ($result as $client) {
		$mac = $client->getMac();
		$neighbors[ $mac ] = array();
		$neighbors[ $mac ]['name'] = $client->getName();
	}

	if ($firewall_host) {
		$logger->debug('Building MAC cache');
		$session = $rpc->login($firewall_host,$firewall_user,$firewall_pwd);
		if ($session !== false) {
			// Read dnsmasq leases
			$opts = new \stdclass();
			$opts->command = 'cat';
			$opts->params = array('/tmp/dhcp.leases');
			$stat = $session->call('file','exec', $opts);
			$lines = explode("\n", $stat->stdout);
			foreach ($lines as $line) {
				$ds = explode(" ", $line);
				if (!array_key_exists(3, $ds)) {
					continue;
				}
				$mac = strtolower($ds[1]);
				if (strlen($mac)) {
					if (array_key_exists($mac, $neighbors)  && array_key_exists('name', $neighbors[$mac])) continue;
					$neighbors[ $mac ] = array('ip' => $ds[2], 'name' => $ds[3]);
				}
			}

			// Read neighbor information
			$opts = new \stdclass();
			$opts->command = 'ip';
			$opts->params = array('-4','neighb');
			$stat = $session->call('file','exec', $opts);
			$lines = explode("\n", $stat->stdout);
			foreach ($lines as $line) {
				$ds = explode(" ", $line);
				if (!array_key_exists(4, $ds)) {
					continue;
				}
				$mac = strtolower($ds[4]);
				if (strlen($mac)) {
					if (array_key_exists($mac, $neighbors)  && array_key_exists('name', $neighbors[$mac])) continue;
					$neighbors[ $mac ] = array('ip' => $ds[0]);
					$cache = $this->get('session')->get('name_cache', null);
					if (!is_array($cache)) {
						$cache = array();
					}
					if (array_key_exists($mac, $cache)) {
						$name = $cache[ $mac ];
						if ($name === false) {
							$logger->debug('skipping because of negative entry: '.$name);
							continue;
						}
						$logger->debug('found '.$name);
					} else {
						$name = gethostbyaddr($ds[0]);
						if ($name == $ds[0]) $name = '';
						if (empty($name)) {
							$cache[ $mac ] = false;
						} else {
							$cache[ $mac ] = $name;
						}

					}
					if ($name) {
						$neighbors[ $mac ]['name'] = $name;
					}
					$this->get('session')->set('name_cache', $cache);
				}
			}
		}
		$logger->debug('MAC cache complete');
	}	
	$aps = $doc->getRepository('ApManBundle:AccessPoint')->findAll();
	$logger->debug('Logging in to all APs');
	$sessions = array();
	$data = array();
	$history = array();
	$macs = array();
	foreach ($aps as $ap) {
		$sessionId = $ap->getName();
		$data[$sessionId] = array();
		$history[$sessionId] = array();
		foreach ($ap->getRadios() as $radio) {
			foreach ($radio->getDevices() as $device) {
				$delat = 0;
				$status = $this->ssrv->getCacheItemValue('status.device.'.$device->getId());
				$ifname = $device->getIfname();
				if ($status === NULL) continue;
				if (!isset($status['info'])) continue;

				$data[$sessionId][$ifname] = array();
				$data[$sessionId][$ifname]['board'] = $this->ssrv->getCacheItemValue('status.ap.'.$ap->getId());
				$data[$sessionId][$ifname]['info'] = $status['info'];
				$data[$sessionId][$ifname]['assoclist'] = array();
				if (array_key_exists('assoclist', $status)) {
					foreach ($status['assoclist']['results'] as $entry) {
						$mac = strtolower($entry['mac']);
						$data[$sessionId][$ifname]['assoclist'][$mac] = $entry;
						$macs[$mac] = true;
					}
				}
				$data[$sessionId][$ifname]['clients'] = array();
				if (array_key_exists('clients', $status)) {
					if (array_key_exists('clients', $status['clients'])) {
						$data[$sessionId][$ifname]['clients'] = $status['clients']['clients'];
					}
				}
				$data[$sessionId][$ifname]['clientstats'] = $status['stations'];

				if (array_key_exists('history', $status) and is_array($status['history']) and array_key_exists(0, $status['history'])) {
					$currentStatus = $status;
					$status = $currentStatus['history'][0];
					if ($status === NULL) continue;
					

					$history[$sessionId][$ifname] = array();
					$history[$sessionId][$ifname]['board'] = $ap->getStatus();
					$history[$sessionId][$ifname]['info'] = $status['info'];
					$history[$sessionId][$ifname]['assoclist'] = array();
					if (array_key_exists('timestamp', $status) && array_key_exists('timestamp', $currentStatus)) {
						$deltat = $currentStatus['timestamp']-$status['timestamp'];
						$history[$sessionId][$ifname]['timedelta'] = $deltat;
					}
					foreach ($status['assoclist']['results'] as $entry) {
						$mac = strtolower($entry['mac']);
						$history[$sessionId][$ifname]['assoclist'][$mac] = $entry;
					}
					$history[$sessionId][$ifname]['clients'] = array();
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
		$devById[ $device->getId() ] = $device;
		foreach ($macs as $mac => $v) {
			$keys[] = 'status.device['.$device->getId().'].probe.'.$mac;
		}
	}

	$probes = $this->ssrv->getMultipleCacheItemValues($keys);
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

	return $this->render('default/clients.html.twig', array(
		'data' => $data,
		'historical_data' => $history,
		'neighbors' => $neighbors,
		'apsrv' => $apsrv,
		'heatmap' => $heatmap,
		'number_format_bps'
	));
    }

    /**
     * @Route("/disconnect")
     */
    public function disconnectAction(Request $request) {
        $doc = $this->doctrine;
	$system = $request->query->get('system','');
	$device = $request->query->get('device','');
	$mac = $request->query->get('mac','');
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $system
	));
	$opts = new \stdClass();
	$opts->addr = $mac;
	$opts->reason = 5;
	$opts->deauth = false;
	$opts->ban_time = 10;
        $client = $this->ssrv->getMqttClient();
        if (!$client) {
                $this->logger->error($ap->getName().': Failed to get mqtt client.');
		return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
	$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device, 'del_client', $opts);
	$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
	$res = $client->publish($topic, json_encode($cmd));
	$client->loop(1);
        $client->disconnect();
	
	return $this->redirect($this->generateUrl('apman_default_index'));
    }	    

    /**
     * @Route("/deauth")
     */
    public function deauthAction(Request $request) {
        $doc = $this->doctrine;
	$system = $request->query->get('system','');
	$device = $request->query->get('device','');
	$ban_time = intval($request->query->get('ban_time',0));
	$mac = $request->query->get('mac','');
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $system
	));
	$opts = new \stdClass();
	$opts->addr = $mac;
	$opts->reason = 3;
	$opts->deauth = true;
	$opts->ban_time = $ban_time;
        $client = $this->ssrv->getMqttClient();
        if (!$client) {
                $this->logger->error($ap->getName().': Failed to get mqtt client.');
		return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
	$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device, 'del_client', $opts);
	$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
	$res = $client->publish($topic, json_encode($cmd));
	$client->loop(1);
        $client->disconnect();
	return $this->redirect($this->generateUrl('apman_default_index'));
    }

    /**
     * @Route("/wnm_disassoc_imminent_prepare")
     */
    public function wnmDisassocImminentPrepare(Request $request) {
	if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	$ssid = $this->doctrine->getRepository('ApManBundle:SSID')->findOneBy( array(
		'name' => $request->get('ssid')
	));

	return $this->render('default/wnm_disassoc_imminent.html.twig', 
		array(
			'devices' => $ssid->getDevices(),
			'mac' => $request->get('mac'),
			'system' => $request->get('system'),
			'device' => $request->get('device'),
			'ssid' => $request->get('ssid')
		)
	);
    }	    

    /**
     * @Route("/wnm_disassoc_imminent")
     */
    public function wnmDisassocImminent(Request $request) {
	if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
		echo "Params missing.\n";
		exit();
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	$opts = new \stdClass();
	$opts->addr = $request->get('mac');
	$opts->duration = 1*20;
	$opts->abridged = false;
	$opts->neighbors = array();

	if ($request->get('target') > 0) {
		$targetDev = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy( array(
			'id' => $request->get('target')
		));
		$rrm = $targetDev->getRrm();
		$rrm = json_decode(json_encode($rrm));
		if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                	$this->logger->error('wndDisassocImminent(): Failed to get rrm.');
			return $this->redirect($this->generateUrl('apman_default_index'));
		}
		$opts->neighbors = array($rrm->value[2]);
		$opts->abridged = true;
	}

	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $request->get('system')
	));

        $client = $this->ssrv->getMqttClient();
        if (!$client) {
                $this->logger->error($ap->getName().': Failed to get mqtt client.');
		return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
	$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$request->get('device'), 'wnm_disassoc_imminent', $opts);
	$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
	$res = $client->publish($topic, json_encode($cmd));
	$client->loop(1);
        $client->disconnect();
	return $this->redirect($this->generateUrl('apman_default_index'));
    }	    

    /**
     * @Route("/station")
     */
    public function stationAction(Request $request) {
        $doc = $this->doctrine;
	$system = $request->query->get('system','');
	$device = $request->query->get('device','');
	$mac = $request->query->get('mac','');
	echo "<pre>";
	echo "System: '$system'\nDevice: '$device'\n";
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $system
	));
	$session = $this->rpcService->getSession($ap);	
	if ($session === false) {
	    print_r($session);
	    exit();
	    return $this->redirect('/');
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	$opts = new \stdClass();
	$opts->command = 'iw';
	$opts->params = array('dev',$device,"station","dump");
#	$opts->params = array('dev',$device,"station","get",$mac);
	$stat = $session->callCached('file','exec', $opts, 1);
	if (!$stat) {
		return $this->redirect('/');
	}
	$record = false;
	$client = array();
	foreach (explode("\n", $stat->stdout) as $value) {
		$line = trim($value);
		$search = strtolower('station '.$mac);
		if (substr(strtolower($line),0,strlen($search)) == $search) {
			$record = true;
			continue;
		}
		$search = strtolower('station ');
		if (substr(strtolower($line),0,strlen($search)) == $search) {
			$record = false;
			continue;
		}
		if (!$record) continue;
		if ($line == '') {
			continue;
		}
		list($key, $val) = explode(':', $line);
		$key = trim($key);
		$key = str_replace(array(' ',',','.','-','/'),'_', $key);
		$val = trim($val);
		$client[$key] = $val;
		#echo "Key: $key\n";
		#echo "Val: $val\n";
		#echo $line."\n";
	}
	print_r($client);
	exit;
    }	    

    /**
     * @Route("/wps_pin_requests")
     */
    public function wpsPinRequests(Request $request) {
	$apsrv = $this->apservice;
	$wpsPendingRequests = $apsrv->getPendingWpsPinRequests();
	return $this->render('ApManBundle:Default:wps_pin_requests.html.twig', array(
	    'wpsPendingRequests' => $wpsPendingRequests
        ));
    }

   /**
     * @Route("/wps_pin_requests_ack")
     */
    public function wpsPinRequestAck(Request $request) {
	// client_uuid=ac998afb-1cea-5cd7-a63c-2f817e3f466b&ap_id=24&ap_if=wap-knet0&wps_pin=XXXX
	
	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->find(
		$request->query->get('ap_id')
	);
	$session = $this->rpcService->getSession($ap);	
	if ($session === false) {
	    	$logger->debug('Failed to log in to: '.$ap->getName());
		return false;
	}
	$opts = new \stdClass();
        $opts->command = 'hostapd_cli';
        $opts->params = array(
		'-i', 
		$request->query->get('ap_if'), 
		'wps_pin', 
		$request->query->get('client_uuid'),
		$request->query->get('wps_pin')
	);
        $opts->env = array('LC_ALL' => 'C');
        $stat = $session->callCached('file','exec', $opts, 5);
	print_r($stat);
	if (!is_object($stat) and !property_exists($stat, 'code')) {
		return false;
	}


	return $this->render('ApManBundle:Default:wps_pin_requests.html.twig', array(
//	    'wpsPendingRequests' => $wpsPendingRequests
        ));
    }
 
    /**
     * @Route("/chtest")
     */
    public function chtest(Request $request) {
	$stdin = fopen('php://stdin', 'r');
	while(!feof($stdin)) {
		$buffer = fgets($stdin,4096);
		print("B: $buffer\n");
		ob_flush();
	}
	exit();
   }

}
