<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;

class AccessPointService {

	private $logger;
	private $doctrine;

	function __construct($logger, $doctrine) {
		$this->logger = $logger;
		$this->doctrine = $doctrine;
	}

    /**
     * publish config
     * @return \boolean|\null 
     */
    public function publishConfig($ap)
    {
    	$logger = $this->logger;	    
	$changed = false;
	$session = $ap->getSession();

	// total clean up	
	$opts = new \stdClass();
	$opts->config = 'wireless';
	$opts->type = 'wifi-iface';
	// $opts->section = $device->getName();
	$session->call('uci','delete', $opts);

	foreach ($ap->getRadios() as $radio ) {
		$logger->debug($ap->getName().': Configuring radio '.$radio->getName());
		$opts = new \stdClass();
		$opts->config = 'wireless';
		$opts->section = $radio->getName();
		$opts->type = 'wifi-device';
		$opts->values = $radio->exportConfig();
		$stat = $session->call('uci','set', $opts);

		foreach ($radio->getDevices() as $device) {
			$logger->debug($ap->getName().': Configuring device '.$device->getName());
			
			$opts = new \stdClass();
			$opts->config = 'wireless';
			$opts->type = 'wifi-iface';
			$opts->name = $device->getName();

			$config = $device->getSsid()->exportConfig();
			foreach ($device->getConfig() as $n => $v) {
				$config->$n = $v;
			}

			if (count($device->getSsid()->getConfigFiles())) {
				foreach ($device->getSsid()->getConfigFiles() as $configFile) {
					$name = $configFile->getName();
					$content = $configFile->getContent()."\n";
					$content = str_replace("\r\n", "\n", $content);
					$md5 = hash('md5', $content);

					$logger->debug($ap->getName().': Verifying hash of file '.$configFile->getFileName());
					$o = new \stdClass();
					$o->path = $configFile->getFileName();
					$stat = $session->call('file','md5', $o);

					if (!$stat || !isset($stat->md5) || ($stat->md5 != $md5)) {
						$logger->debug($ap->getName().': Uploading file '.$configFile->getFileName());
						$o = new \stdClass();
						$o->path = $configFile->getFileName();
						$o->append = false;
						$o->base64 = false;
						$o->data = $content;
						$stat = $session->call('file','write', $o);
					}
					$config->$name = $configFile->getFileName();
				}
			}

			$config->device = $radio->getName();
			$opts->values = $config;
			$stat = $session->call('uci','add', $opts);
			
			$changed = true;
			$logger->debug($ap->getName().': Configured device '.$device->getName());
		}
	}

	// Commit
	$logger->debug($ap->getName().': Committing changes');
	$opts = new \stdClass();
	$opts->config = 'wireless';
	$stat = $session->call('uci','commit', $opts);
	return true;
    }
    
    /**
     * refresh radio config
     * @return \boolean
     */
    public function refreshRadios($ap)
    {
        $doc = $this->doctrine;
	$em = $this->doctrine->getEntityManager();
	$session = $ap->getSession();
	if ($session === false) {
		$this->logger->error("Cannot connect to AP ".$ap->getName());
		return false;
	}
	$opts = new \stdClass();
	$opts->config = 'wireless';
	$opts->type = 'wifi-device';
	$stat = $session->call('uci','get', $opts);
	if (!isset($stat->values) || !count($stat->values)) {
		$this->logger->warn("No radios found on AP ".$ap->getName());
		return false;
	}
	$radios = $ap->getRadios();
	$radioCount = count($radios);
	$validIds = array();
	foreach ($stat->values as $name => $cfg) {
		$this->logger->info("Checking radio ".$name);
		$radio = null;
		foreach ($radios as $tmpradio) {
			if ($tmpradio->getName() == $name) {
				$radio = $tmpradio;
				break 1;
			}
		}
		if (is_null($radio)) {
			$radio = new \ApManBundle\Entity\Radio(); 
			$radio->setAccessPoint($ap);
			$radio->setName($name);
		}
		$radio->importConfig($cfg);
		$em->persist($radio);
		$em->flush();
		$this->logger->info("Updated radio ".$name);
		$validIds[] = $radio->getId();
	}
	$em->persist($ap);
	$em->flush();
	$qb = $em->createQueryBuilder();
	$query = $em->createQuery(
		    'DELETE
		     FROM ApManBundle:Radio radio
		     WHERE
		     radio.accesspoint = :ap
		     AND radio.id NOT IN (:radios)'
        );
	$query->setParameter('ap', $ap);
	$query->setParameter('radios', $validIds);
	$configs = $query->getResult();
	$this->logger->info("Cleaned up radios.");
    }
    
    /**
     * stop radio
     * @return \boolean|\null 
     */
    public function stopRadio($ap)
    {
	
    	$logger = $this->logger;	    
	$changed = false;
	$session = $ap->getSession();

	// total clean up	
	$opts = new \stdClass();
	$opts->config = 'wireless';
	$opts->type = 'wifi-iface';
	// $opts->section = $device->getName();
	if ($session->call('network.wireless','down')) {
		return true;
	}
	return false;
    }

    /**
     * start radio
     * @return \boolean|\null 
     */
    public function startRadio($ap)
    {
	
    	$logger = $this->logger;	    
	$changed = false;
	$session = $ap->getSession();

	// total clean up	
	$opts = new \stdClass();
	$opts->config = 'wireless';
	$opts->type = 'wifi-iface';
	// $opts->section = $device->getName();
	if ($session->call('network.wireless','up')) {
		return true;
	}
	return false;
    }

    /**
     * get MacManufacturer
     * @return \string|\null 
     */
    public function getMacManufacturer($mac)
    {
	$cache = new FilesystemCache();
	$key = 'macdb';
	if (!$cache->has($key)) {
		if (!file_exists('/usr/share/nmap/nmap-mac-prefixes')) {
		     return ;
		}
		$handle = fopen('/usr/share/nmap/nmap-mac-prefixes', "r");
		if (!$handle) {
		     return ;
		}
		$macdb = array();;
		while (($line = fgets($handle)) !== false) {
			$line = trim($line);
			if (empty($line)) continue;
			if (substr($line,0,1) == '#') continue;
			$m = strtolower(substr($line,0,6));
			$manufacturer = substr($line,7);
			$macdb[$m] = $manufacturer;
		}
		fclose($handle);
		$cache->set($key, $macdb, 86400);
	} else {
		$macdb = $cache->get($key);
	}
	$mac_prefix = str_replace(':','', $mac);
	$mac_prefix = substr($mac_prefix,0 ,6);
	if (!array_key_exists($mac_prefix, $macdb)) return false;
	return $macdb[$mac_prefix];
    }

    /**
     * get DeviceClientStats
     * @return \string|\null 
     */
    public function getDeviceClientStats($ap, $deviceName)
    {
	$session = $ap->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->command = 'iw';
	$opts->params = array('dev',$deviceName,"station","dump");
	$stat = $session->callCached('file','exec', $opts, 1);
	if (!$stat) {
		return false;
	}
	if (!property_exists($stat, 'stdout')) {
		return false;
	}
	$record = false;
	$clients = array();
	$mac = '';
	foreach (explode("\n", $stat->stdout) as $value) {
		$line = trim($value);
		$search = strtolower('station ');
		if (substr(strtolower($line),0,strlen($search)) == $search) {
			#echo "FXXXX\n";
			$record = true;
			$mac = substr($line,strlen($search),17);
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
		if (!array_key_exists($mac, $clients)) {
			$clients[$mac] = array();
		}
		$clients[$mac][$key] = $val;
	}
	return $clients;
    }

    /**
     * get Pending WPS PIN Requests
     * @return \array|\boolean
     */
    public function getPendingWpsPinRequests()
    {
	$ts = new \DateTime();
	$ts->setTimestamp(time()-120);
	$ts->setTimestamp(time()-1800);
	$em = $this->doctrine->getManager();
	
	$query = $em->createQuery(
		    'SELECT sl
		     FROM ApManBundle:Syslog sl
		     WHERE sl.ts > :ts
		     AND sl.message LIKE :msg_filter
		     ORDER BY sl.id DESC
			'
        );
	$query->setParameter('ts', $ts);
	$query->setParameter('msg_filter', '% hostapd: % WPS-PIN-NEEDED %');
        $list = $query->getResult();

	// <29>Mar  6 16:05:47 hostapd: wap-knet0: WPS-PIN-NEEDED ac998afb-1cea-5cd7-a63c-2f817e3f466b 60:f1:89:89:9e:c8 [hero2ltexx|samsung|SM-G935F|SM-G935F|988633533958354552|10-0050F204-5]
	$requests = array();
	if (is_array($list)) {
		foreach ($list as $ent) {
			$msg = $ent->getMessage();
			preg_match('/hostapd: ([a-z0-9\-].*)\: WPS-PIN-NEEDED ([a-z0-9\-].*) ([a-z0-9\:].*) \[(.*)\]/', $msg, $m);
			if (count($m) != 5) {
				continue;
			}
			$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
				'ipv4' => $ent->getSource()
			));
			if (!$ap) continue;

			$req = array();
			$req['if'] = $m[1];
			$req['client_uuid'] = $m[2];
			$req['client_mac'] = $m[3];
			$req['client_info'] = $m[4];
			$req['ap'] = $ap;
			$req['log'] = $ent;
			$requests[] = $req;
		}
	}
	return $requests;
    }

    /**
     * processLogMessage
     * @return \array|\boolean
     */
    public function processLogMessage($syslog) {
	// Mar  6 19:41:11 hostapd: wap-knet0: WPS-REG-SUCCESS 60:f1:89:89:9e:c8 ac998afb-1cea-5cd7-a63c-2f817e3f466b
	if (strpos($syslog->getMessage(), ' WPS-REG-SUCCESS ') !== false) {
		$this->processLogWpsRegSuccess($syslog);
	}
    }


    /**
     * processLogMessage
     * @return \array|\boolean
     */
    public function processLogWpsRegSuccess($syslog) {
	preg_match('/hostapd: ([a-z0-9\-].*)\: WPS-REG-SUCCESS ([a-z0-9\-].*) ([a-z0-9\:].*)/', $syslog->getMessage(), $m);
	print_r($m);
	$if = $m[1];
	$mac = $m[2];
	$uuid = $m[3];
	if (count($m) != 4) {
		return;
	}
	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'ipv4' => $syslog->getSource()
	));
	$session = $ap->getSession();
	if ($session === false) {
		$logger->debug('Failed to log in to: '.$ap->getName());
		return false;
	}

	$o = new \stdClass();
	$o->path = '/etc/hostapd.kalnet.psk';
	$o->base64 = false;
	$stat = $session->call('file','read', $o);
	if (!is_object($stat)) {
		return;
	}
	if (!property_exists($stat,'data')) {
		return;
	}
	
	$lines = explode("\n", $stat->data);
	$key = null;
	foreach ($lines as $line ) {
		$fields = explode(" ", $line);
		if (count($fields) != 2) continue;
		if (strtolower($fields[0]) == strtolower($mac)) {
			$key = $fields[1];
		}
		// Looping trhough all simulates hostapds behaviour
	}
	$key = trim($key);
	if (!$key) {
		return;
		//echo "Found key for new device $mac: $key\n";
	}
	$em = $this->doctrine->getManager();
	
	$query = $em->createQuery(
		    'SELECT d
		     FROM ApManBundle:Device d
		     LEFT JOIN d.radio r
		     WHERE r.accesspoint = :ap
			'
        );
	$query->setParameter('ap', $ap);
        $list = $query->getResult();
	if (!is_array($list)) return;
	$dev = null;
	foreach ($list as $device) {
		echo $device->getId()."\n";
		print_r($device->getConfig());
		$cfg = $device->getConfig();
		if (!isset($cfg['ifname'])) continue;
		if ($cfg['ifname'] == $if) {
			$dev = $device;
		}
	}
	if (is_null($dev)) {
		return;
	}

	$query = $em->createQuery(
		    'SELECT f
		     FROM ApManBundle:SSIDConfigFile f
		     WHERE f.name = :name
		     AND f.ssid = :ssid
			'
        );
	$query->setParameter('name', 'wpa_psk_file');
	$query->setParameter('ssid', $dev->getSSID());
        $list = $query->getResult();
	if (count($list)) {
		foreach($list as $ent) {
			$content = $ent->getContent()."\n";
			$content.= '#'.($syslog->getMessage())."\n";
			$content.= $mac.' '.$key."\n";
			$ent->setContent($content);
			echo $content;
			$em->persist($ent);
		}
	}
	$em->flush();

	foreach ($dev->getSSID()->getDevices() as $device) {
		$session = $device->getRadio()->getAccesspoint()->getSession();
		if ($session === false) {
			continue;
		}
		if (count($device->getSsid()->getConfigFiles())) {
			foreach ($device->getSsid()->getConfigFiles() as $configFile) {
				$name = $configFile->getName();
				$content = $configFile->getContent()."\n";
				$content = str_replace("\r\n", "\n", $content);
				$md5 = hash('md5', $content);

				$this->logger->debug($ap->getName().': Verifying hash of file '.$configFile->getFileName());
				$o = new \stdClass();
				$o->path = $configFile->getFileName();
				$stat = $session->call('file','md5', $o);

				if (!$stat || !isset($stat->md5) || ($stat->md5 != $md5)) {
					$this->logger->debug($ap->getName().': Uploading file '.$configFile->getFileName());
					$o = new \stdClass();
					$o->path = $configFile->getFileName();
					$o->append = false;
					$o->base64 = false;
					$o->data = $content;
					$stat = $session->call('file','write', $o);
				}
			}
		}
	}

    }

}
