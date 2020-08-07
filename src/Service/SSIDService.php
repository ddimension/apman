<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;

class SSIDService {

	private $logger;
	private $doctrine;
	private $apservice;

	function __construct(\Psr\Log\LoggerInterface $logger, \Doctrine\Persistence\ManagerRegistry $doctrine, \ApManBundle\Service\AccessPointService $apservice) {
		$this->logger = $logger;
		$this->doctrine = $doctrine;
		$this->apservice = $apservice;
	}

    /**
     * apply Location Constraints
     * @param \Array $assocList
     * @param ApmanBundle\Entity\Device $device
     * @return \boolean|\null 
     */
    public function applyLocationConstraints($assocList, $device) {

	$session = $device->getRadio()->getAccesspoint()->getSession();
	if (!$session) {
		$this->logger->error('Failed to establish session');
		return null;
	}
	$config = $device->getConfig();

	$res = $session->callCached('hostapd.'.$config['ifname'],'get_clients', null , 1);
	$hostapd_clients = array();
	if (is_object($res) and property_exists($res,'clients')) {
		$hostapd_clients = (Array)$res->clients;
	}

	// Apply Band Steering
	$hwmode = $device->getRadio()->getConfigHwmode();
	if (strpos($hwmode, 'g') === false) {
		$this->logger->debug('HW Mode is already '.$hwmode.', do not change mode.');
		return null;
	}
	$macs = array();
	$al = array();
	$limit = -60;
	foreach ($assocList as $index => $assocClient) {
		$mac = strtolower($assocClient->mac);
		if (!is_object($assocClient)) {
			continue;
		}
		$data = $session->callCached('hostapd.'.$config['ifname'],'get_clients', null , 1);
		if (isset($hostapd_clients[ $mac ])) {
			if (!$hostapd_clients[$mac]->assoc) {
				$this->logger->info('Client '.$mac.' not associated, skip LocationConstraint');
				continue;
			}
		}

		if (!property_exists($assocClient, 'signal')) {
			$this->logger->warn('Missing signal field in assoc data of client '.$mac);
			continue;
		}
		if ($assocClient->signal<$limit) {
			$this->logger->debug('Client '.$mac.' Signal to below '.$limit.', skipping band steering');
			continue;
		}
		if (is_object($assocClient) && property_exists($assocClient, 'mac')) {
			$macs[] = $mac;
			$al[$mac] = $assocClient;
		}
	}
	if (!count($macs)) {
		return null;
	}
//	echo "MACS: ".join(' ',$macs)."\n";
	$em = $this->doctrine->getManager();
	$query = $em->createQuery(
		    'SELECT cl
		     FROM ApManBundle:Client cl
		     WHERE cl.mac IN(:macs)
			'
        );
	$query->setParameter('macs', $macs);
        $clients = $query->getResult();
	if (!count($clients)) {
		#echo "NO CL FO\n";
		return null;
	}

	foreach ($clients as $client) {
		// Check Client for 11a capability
		if ($client->getModeA()) {
			$this->logger->info('Client '.$client->getMac().' is 11a capable and connected via 11g, move him to 11a.');
			print('Client '.$client->getMac().' is 11a capable and connected via 11g, move him to 11a.'."\n");
			$this->moveClientTo11a($client, $device);
		}
	}
	
	return false;
    }
    
    /**
     * move Client to 11a
     * @param ApmanBundle\Entity\Client $client
     * @param ApmanBundle\Entity\Device $device
     * @return \boolean|\null 
     */
    public function moveClientTo11a($client, $device) {
	$ssid = $device->getSSID();
	print('moveClientTo11a(): Client '.$client->getMac()."\n");
	$ap = $device->getRadio()->getAccessPoint();
	$query = $this->doctrine->getManager()->createQuery("SELECT d FROM ApmanBundle\Entity\Device d 
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		WHERE d.ssid = :ssid
		AND r.accesspoint = :ap
		AND r.config_hwmode like '%a%'
		AND (r.config_disabled != '1' OR r.config_disabled IS NULL)
	");
	$query->setParameter('ssid', $device->getSSID());
	$query->setParameter('ap', $ap);
	$query->setMaxResults(1);
	$targetDevices = $query->getResult();
	if (!count($targetDevices)) {
		return null;
	}
	return $this->wnmDisassocImminent($client, $device, 200, $targetDevices);
    }


    /**
     * call wnm_disassoc_imminent
     * @param ApmanBundle\Entity\Client $client
     * @param ApmanBundle\Entity\Device $device
     * @param \Integer $timeout
     * @param $targetDevice
     * @return \boolean|\null 
     */
    public function wnmDisassocImminent($client, $device, $timeout, $targetDevice = null) {
	/*
		hostapd.wap-knet0 wnm_disassoc_imminent '{"addr":"60:f1:89:89:9e:c8","duration":400,"neighbors":["92daf93c3d05ff1900007a7c090603017e00"]}'
	*/
	$cache = new FilesystemCache();
	$cacheKey = 'ssid.wnm_disassoc_imminent.'.str_replace(':','', $client->getMac());
	if ($cache->has($cacheKey)) {
		$this->logger->info('wnmDisassocImminent Process alreadyrunning');
		return;
	}
	$cache->set($cacheKey, true, 30+$timeout/10);
	$session = $device->getRadio()->getAccesspoint()->getSession();
	if (!$session) {
		$this->logger->info('Failed to establish session to '.$device->getRadio()->getAccesspoint()->getName());
		return false;
	}

	$cfg = $device->getConfig();
	if (!isset($cfg['ifname'])) {
		$this->logger->info('Missing ifname');
		return false;
	}
	$opts = new \stdClass();
	$opts->addr = $client->getMac();
	$opts->duration = 200;
	$opts->neighbors = array();
	if (is_array($targetDevice)) {
		foreach ($targetDevice as $dev) {
			$rrmOwn = $dev->getRrmOwn();
			if (isset($rrmOwn[2])) {
				$opts->neighbors[] = $rrmOwn[2];
			}
		}	
	} elseif (is_object($targetDevice)) {
		$rrmOwn = $dev->getRrmOwn();
		if (isset($rrmOwn[2])) {
			$opts->neighbors[] = $rrmOwn[2];
		}
	}
	$this->logger->info('Sending wnm_disassoc_imminent request to '.print_r($opts,true));
	$session->call('hostapd.'.$cfg['ifname'],'wnm_disassoc_imminent', $opts);
    }
}
