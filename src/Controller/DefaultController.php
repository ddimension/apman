<?php

namespace ApManBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    public function __construct(
	    \Psr\Log\LoggerInterface $logger,
	    \ApManBundle\Service\AccessPointService $apservice,
	    \Doctrine\Persistence\ManagerRegistry $doctrine
    )
    {
	    $this->logger = $logger;
	    $this->apservice = $apservice;
	    $this->doctrine = $doctrine;
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
	    $session = $ap->getSession();
	    if ($session === false) {
	    	$logger->debug('Failed to log in to: '.$ap->getName());
		    continue;
	    }
	    $sessionId = $ap->getName();
	    $logger->debug('Logged in to: '.$ap->getName());

	    if (!array_key_exists($sessionId, $data)) 
		    $data[$sessionId] = array();
	    $logger->debug('Gathering from '.$sessionId.' '.time());
	    $res = $session->callCached('system','board', null, 30);
	    $board = null;
            if (is_object($res) && property_exists($res, 'model')) {
		    $board = $res;
	    }

	    $res = $session->callCached('iwinfo','devices', null, 10);
	    foreach ($res->devices as $device) {
		if (!array_key_exists($device, $data[$sessionId]))
			$data[$sessionId][$device] = array('board' => $board);
	     	
		$data[$sessionId][$device]['info'] = new \stdClass;
		$opts = new \stdClass();
		$opts->device = $device;
		$stat = $session->callCached('iwinfo','info', $opts, 5);
		if ($stat !== false)
			$data[$sessionId][$device]['info'] = $stat;

		$data[$sessionId][$device]['assoclist'] = array();
		$stat = $session->call('iwinfo','assoclist', $opts);
		if ($stat !== false && property_exists($stat, 'results')) {
			foreach ($stat->results as $entry) {
				if (!property_exists($entry, 'mac')) {
					continue;
				}
				$data[$sessionId][$device]['assoclist'][ strtolower($entry->mac) ] = $entry;
			}	
		}
		$data[$sessionId][$device]['clients'] = array();
		$stat = $session->call('hostapd.'.$device, 'get_clients');
		if ($stat !== false && property_exists($stat, 'clients')) {
			foreach ((array) $stat->clients as $clientName => $clientValue) {
				$data[$sessionId][$device]['clients'][$clientName] = $clientValue;
			}
		}
	        $data[$sessionId][$device]['clientstats'] = $apsrv->getDeviceClientStats($ap, $device);
	    }
	}
	return $this->render('default/clients.html.twig', array('data' => $data, 'neighbors' => $neighbors, 'apsrv' => $apsrv));

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
	$session = $ap->getSession();
	if ($session === false) {
	    print_r($session);
	    exit();
	    return $this->redirect('/');
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
	$session = $ap->getSession();
	if ($session === false) {
	    print_r($session);
	    exit();
	    return $this->redirect('/');
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
     * @Route("/wnm_disassoc_imminent")
     */
    public function wnmDisassocImminent(Request $request) {
        $doc = $this->doctrine;
	$system = $request->query->get('system','');
	$device = $request->query->get('device','');
	$ban_time = intval($request->query->get('ban_time',0));
	$mac = $request->query->get('mac','');
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $system
	));
	$session = $ap->getSession();
	if ($session === false) {
	    print_r($session);
	    exit();
	    return $this->redirect('/');
	}
	$opts = new \stdClass();
	$opts->addr = $mac;
	$opts->duration = 10;
	$opts->neighbors = array();
	$stat = $session->call('hostapd.'.$device, 'wnm_disassoc_imminent', $opts);
	return $this->redirect('/');
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
	$session = $ap->getSession();
	if ($session === false) {
	    print_r($session);
	    exit();
	    return $this->redirect('/');
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
	return $this->redirect('/');
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
	$session = $ap->getSession();
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
