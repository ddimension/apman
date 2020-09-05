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

    public function __construct(
	    \Psr\Log\LoggerInterface $logger,
	    \ApManBundle\Service\AccessPointService $apservice,
	    \Doctrine\Persistence\ManagerRegistry $doctrine,
	    \ApManBundle\Service\wrtJsonRpc $rpcService
    )
    {
	    $this->logger = $logger;
	    $this->apservice = $apservice;
	    $this->doctrine = $doctrine;
	    $this->rpcService = $rpcService;
    }

    /**
     * @Route("/")
     */
    public function indexAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
	$logger = $this->logger;
	$apsrv = $this->apservice;

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
        $doc = $this->doctrine;
	$em = $doc->getManager();
	$aps = $doc->getRepository('ApManBundle:AccessPoint')->findAll();
	$logger->debug('Logging in to all APs');
	$sessions = array();
	$data = array();
	foreach ($aps as $ap) {
		$sessionId = $ap->getName();
		$data[$sessionId] = array();
		foreach ($ap->getRadios() as $radio) {
			foreach ($radio->getDevices() as $device) {
				$status = $device->getStatus();
				$ifname = $device->getIfname();
				if ($status === NULL) continue;

				$data[$sessionId][$ifname] = array();
				$data[$sessionId][$ifname]['board'] = $ap->getStatus();
				$data[$sessionId][$ifname]['info'] = $status['info'];
				$data[$sessionId][$ifname]['assoclist'] = array();
				foreach ($status['assoclist']['results'] as $entry) {
					$mac = strtolower($entry['mac']);
					$data[$sessionId][$ifname]['assoclist'][$mac] = $entry;
				}
				$data[$sessionId][$ifname]['clients'] = array();
				if (array_key_exists('clients', $status)) {
					if (array_key_exists('clients', $status['clients'])) {
						$data[$sessionId][$ifname]['clients'] = $status['clients']['clients'];
					}
				}
				$data[$sessionId][$ifname]['clientstats'] = $status['stations'];
			}
		}
	}
	$query = $em->createQuery("SELECT h FROM ApManBundle\Entity\ClientHeatMap h
		LEFT JOIN h.device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		ORDER by h.signalstr DESC
	");
	$hm = $query->getResult();
	$heatmap = [];
	foreach ($hm as $entry) {
		$address = $entry->getAddress();
		if (!array_key_exists($address, $heatmap)) {
			$heatmap[ $address ] = array();
		}
		$heatmap[ $address ][] = $entry;
	}
	return $this->render('default/clients.html.twig', array(
		'data' => $data,
		'neighbors' => $neighbors,
		'apsrv' => $apsrv,
		'heatmap' => $heatmap
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
	$session = $this->rpcService->getSession($ap);	
	if ($session === false) {
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	$opts = new \stdClass();
	$opts->addr = $mac;
	$opts->reason = 5;
	$opts->deauth = false;
	$opts->ban_time = 10;
	$stat = $session->call('hostapd.'.$device, 'del_client', $opts);
	return $this->redirect('/');
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
	$session = $this->rpcService->getSession($ap);	
	if ($session === false) {
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	$opts = new \stdClass();
	$opts->addr = $mac;
	$opts->reason = 3;
	$opts->deauth = true;
	$opts->ban_time = $ban_time;
	$stat = $session->call('hostapd.'.$device, 'del_client', $opts);
	return $this->redirect('/');
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
	$opts->duration = 1*1000;
	$opts->abridged = false;
	$opts->neighbors = array();

	if ($request->get('target') > 0) {
		$targetDev = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy( array(
			'id' => $request->get('target')
		));
		$sessionTarget = $this->rpcService->getSession($targetDev->getRadio()->getAccesspoint());
		if ($sessionTarget === false) {
			return $this->redirect($this->generateUrl('apman_default_index'));
		}
		$rrm = $sessionTarget->call('hostapd.'.$targetDev->getIfname(), 'rrm_nr_get_own');

		if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
			return $this->redirect($this->generateUrl('apman_default_index'));
		}
		$opts->neighbors = array($rrm->value[2]);
		$opts->abridged = true;
	}

	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $request->get('system')
	));
	$session = $this->rpcService->getSession($ap);	
	if ($session === false) {
		echo "Failed to load session.\n";
		exit();
		return $this->redirect($this->generateUrl('apman_default_index'));
	}

	$stat = $session->call('hostapd.'.$request->get('device'), 'wnm_disassoc_imminent', $opts);
	var_dump($stat);
	exit();
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
}
