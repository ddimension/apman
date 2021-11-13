<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;

class AccessPointService
{
    private $logger;
    private $doctrine;
    private $rpcService;
    private $kernel;
    private $mqttFactory;
    private $cacheFactory;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \Symfony\Component\HttpKernel\KernelInterface $kernel,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Factory\CacheFactory $cacheFactory
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->kernel = $kernel;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
    }

    /**
     * get Configuration for a device.
     *
     * @return string|\null
     */
    public function getDeviceConfig(\ApManBundle\Entity\Device $device)
    {
        $this->logger->info('AccessPointService:getDeviceConfig('.$device->getName().'): Configuring device '.$device->getName());
        $em = $this->doctrine->getManager();

        $config = $device->getSsid()->exportConfig();
        foreach ($device->getConfig() as $n => $v) {
            $config->$n = $v;
        }
        $ifname = $device->getIfname();
        if (!empty($ifname)) {
            $config->ifname = $ifname;
        }
        $address = $device->getAddress();
        if (!empty($address)) {
            $config->macaddr = $address;
        }
        $config->device = $device->getRadio()->getName();

        if (false and count($device->getSsid()->getConfigFiles())) {
            foreach ($device->getSsid()->getConfigFiles() as $configFile) {
                $name = $configFile->getName();
                $content = $configFile->getContent()."\n";
                $content = str_replace("\r\n", "\n", $content);
                $md5 = hash('md5', $content);

                /*
                $logger->debug($ap->getName().': Verifying hash of file '.$configFile->getFileName());
                $o = new \stdClass();
                $o->path = $configFile->getFileName();
                $stat = $session->call('file','md5', $o);

                if (!$stat || !isset($stat->md5) || ($stat->md5 != $md5)) {
                */
                $logger->debug('AccessPointService:getDeviceConfig('.$device->getName().'): Uploading file '.$configFile->getFileName());
                $o = new \stdClass();
                $o->path = $configFile->getFileName();
                $o->append = false;
                $o->base64 = false;
                $o->data = $content;
                //$stat = $session->call('file','write', $o);
                $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'file', 'write', $o);
                /*
                }
                */
                $config->$name = $configFile->getFileName();
            }
        }
        $maps = $device->getSsid()->getSSIDFeatureMaps();
        $qb = $em->createQueryBuilder();
        $query = $em->createQuery(
                'SELECT fm
			     FROM ApManBundle:SSIDFeatureMap fm
			     WHERE fm.ssid = :ssid
			     AND fm.enabled = true
			     ORDER by fm.priority ASC, fm.id ASC'
        );
        $query->setParameter('ssid', $device->getSsid());
        $maps = $query->getResult();
        $this->logger->info('AccessPointService:getDeviceConfig('.$device->getName().'): SSIDFeatureMap count: '.count($maps));
        // transform to array
        $cfg = json_decode(json_encode($config), true);
        foreach ($maps as $map) {
            $feature = $map->getFeature();
            $implementation = $feature->getImplementation();
            $this->logger->info('AccessPointService:getDeviceConfig('.$device->getName().'): Implementation '.$implementation);
            $instance = new $implementation();
            $instance->setServices($this->logger, $this->doctrine, $this->rpcService, $this->mqttFactory, $this->kernel);
            $instance->setSsid($device->getSsid());
            $instance->setDevice($device);
            $instance->setSSIDFeatureMap($map);
            $instance->applyConstraints();
            $this->logger->info('AccessPointService:getDeviceConfig('.$device->getName().'): Implementation '.$implementation.' instance created.');
            $cfg = $instance->getConfig($cfg);
        }

        return json_decode(json_encode($cfg));
    }

    /**
     * publish config.
     *
     * @return \boolean|\null
     */
    public function publishConfig($ap)
    {
        $logger = $this->logger;
        if (!$ap->getProvisioningEnabled()) {
            $logger->notice($ap->getName().': Ignore request to publish config sice ProvisioningEnabled is false.');

            return true;
        }
        $changed = false;
        $client = $this->mqttFactory->getClient();
        $topic = 'apman/ap/'.$ap->getName().'/command/bulk';
        if (!$client) {
            $logger->debug($ap->getName().': Failed to get mqtt client.');

            return false;
        }

        $commands = [
        'list' => [],
        'options' => [
            'cancel_on_error' => false,
        ],
    ];
        // total clean up
        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-iface';
        // $opts->section = $device->getName();
        $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'delete', $opts);
        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-device';
        // $opts->section = $device->getName();
        $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'delete', $opts);
        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-vlan';
        // $opts->section = $device->getName();
        $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'delete', $opts);
        //$logger->debug($ap->getName().': Configuring radio, publishing to topic '.$topic.': '.json_encode($cmd));
        $client->loop(1);

        foreach ($ap->getRadios() as $radio) {
            $logger->debug($ap->getName().': Configuring radio '.$radio->getName());
            $opts = new \stdClass();
            $opts->config = 'wireless';
            $opts->section = $radio->getName();
            $opts->name = $radio->getName();
            $opts->type = 'wifi-device';
            $opts->values = $radio->exportConfig();
            $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'add', $opts);
            //$commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'set', $opts);

            foreach ($radio->getDevices() as $device) {
                $logger->debug($ap->getName().': Configuring device '.$device->getName());

                $opts = new \stdClass();
                $opts->config = 'wireless';
                $opts->type = 'wifi-iface';
                $opts->name = $device->getName();

                $config = $this->getDeviceConfig($device);
                $vlans = [];
                if (property_exists($config, 'vlans')) {
                    $vlans = $config->vlans;
                    unset($config->vlans);
                }

                $opts->values = $config;
                $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'add', $opts);

                $changed = true;
                $logger->debug($ap->getName().': Configured device '.$device->getName());

                if (!is_array($vlans)) {
                    continue;
                }
                if (!count($vlans)) {
                    continue;
                }
                foreach ($vlans as $vlan) {
                    $opts = new \stdClass();
                    $opts->config = 'wireless';
                    $opts->type = 'wifi-vlan';
                    // $opts->name = 'vlan_'.$vlan->vid;
                    $opts->name = $device->getName().'_'.$vlan->vid;
                    $vlan->iface = $device->getName();
                    $opts->values = $vlan;
                    $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'add', $opts);
                    $changed = true;
                    $logger->debug($ap->getName().': Configured device '.$device->getName().' vlan '.$vlan->vid);
                }
            }
        }

        // Commit
        $logger->debug($ap->getName().': Committing changes');
        $opts = new \stdClass();
        $opts->config = 'wireless';

        $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'changes', $opts);
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'uci', 'commit', $opts);

        $commands['list'][] = $cmd;
        $res = $client->publish($topic, json_encode($commands));
        $logger->debug($ap->getName().': '.$res.' Configuring radio, publishing to topic '.$topic.': '.json_encode($commands));

        $client->loop(1);

        return true;
    }

    /**
     * refresh radio config.
     *
     * @return \boolean
     */
    public function refreshRadios($ap)
    {
        $doc = $this->doctrine;
        $em = $this->doctrine->getManager();
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $this->logger->error('Cannot connect to AP '.$ap->getName());

            return false;
        }
        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-device';
        $stat = $session->call('uci', 'get', $opts);
        if (!isset($stat->values) || !count(get_object_vars($stat->values))) {
            $this->logger->warn('No radios found on AP '.$ap->getName());

            return false;
        }
        $radios = $ap->getRadios();
        $radioCount = count($radios);
        $validIds = [];
        foreach ($stat->values as $name => $cfg) {
            $this->logger->info('Checking radio '.$name);
            $radio = null;
            foreach ($radios as $tmpradio) {
                if ($tmpradio->getName() == $name) {
                    $radio = $tmpradio;
                    break;
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
            $this->logger->info('Updated radio '.$name);
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
        $this->logger->info('Cleaned up radios.');
    }

    /**
     * stop radio.
     *
     * @return \boolean|\null
     */
    public function stopRadio($ap)
    {
        $logger = $this->logger;
        $changed = false;
        $client = $this->mqttFactory->getClient();
        $topic = 'apman/ap/'.$ap->getName().'/command';
        if (!$client) {
            $logger->debug($ap->getName().': Failed to get mqtt client.');

            return false;
        }
        $opts = [];
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'network.wireless', 'down', $opts);
        $client->publish($topic, json_encode($cmd));
        $logger->debug($ap->getName().': Configuring radio, publishing to topic '.$topic.': '.json_encode($cmd));

        return true;
    }

    /**
     * start radio.
     *
     * @return \boolean|\null
     */
    public function startRadio($ap)
    {
        $logger = $this->logger;
        $changed = false;
        $client = $this->mqttFactory->getClient();
        $topic = 'apman/ap/'.$ap->getName().'/command';
        if (!$client) {
            $logger->debug($ap->getName().': Failed to get mqtt client.');

            return false;
        }
        $opts = [];
        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'network.wireless', 'up', $opts);
        $client->publish($topic, json_encode($cmd));
        $logger->debug($ap->getName().': Configuring radio, publishing to topic '.$topic.': '.json_encode($cmd));

        return true;
    }

    /**
     * get MacManufacturer.
     *
     * @return \string|\null
     */
    public function getMacManufacturer($mac)
    {
        $cache = new FilesystemCache();
        $key = 'macdb';
        if (!$cache->has($key)) {
            if (!file_exists('/usr/share/nmap/nmap-mac-prefixes')) {
                return;
            }
            $handle = fopen('/usr/share/nmap/nmap-mac-prefixes', 'r');
            if (!$handle) {
                return;
            }
            $macdb = [];
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                if ('#' == substr($line, 0, 1)) {
                    continue;
                }
                $m = strtolower(substr($line, 0, 6));
                $manufacturer = substr($line, 7);
                $macdb[$m] = $manufacturer;
            }
            fclose($handle);
            $cache->set($key, $macdb, 86400);
        } else {
            $macdb = $cache->get($key);
        }
        $mac_prefix = str_replace(':', '', $mac);
        $mac_prefix = substr($mac_prefix, 0, 6);
        if (!array_key_exists($mac_prefix, $macdb)) {
            return false;
        }

        return $macdb[$mac_prefix];
    }

    /**
     * get Pending WPS PIN Requests.
     *
     * @return \array|\boolean
     */
    public function getPendingWpsPinRequests()
    {
        $ts = new \DateTime();
        $ts->setTimestamp(time() - 120);
        $ts->setTimestamp(time() - 1800);
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
        $requests = [];
        if (is_array($list)) {
            foreach ($list as $ent) {
                $msg = $ent->getMessage();
                preg_match('/hostapd: ([a-z0-9\-].*)\: WPS-PIN-NEEDED ([a-z0-9\-].*) ([a-z0-9\:].*) \[(.*)\]/', $msg, $m);
                if (5 != count($m)) {
                    continue;
                }
                $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
                'ipv4' => $ent->getSource(),
            ]);
                if (!$ap) {
                    continue;
                }

                $req = [];
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
     * processLogMessage.
     *
     * @return \array|\boolean
     */
    public function processLogMessage($syslog)
    {
        // Mar  6 19:41:11 hostapd: wap-knet0: WPS-REG-SUCCESS 60:f1:89:89:9e:c8 ac998afb-1cea-5cd7-a63c-2f817e3f466b
        if (false !== strpos($syslog->getMessage(), ' WPS-REG-SUCCESS ')) {
            $this->processLogWpsRegSuccess($syslog);
        }
    }

    /**
     * processLogMessage.
     *
     * @return \array|\boolean
     */
    public function processLogWpsRegSuccess($syslog)
    {
        preg_match('/hostapd: ([a-z0-9\-].*)\: WPS-REG-SUCCESS ([a-z0-9\-].*) ([a-z0-9\:].*)/', $syslog->getMessage(), $m);
        print_r($m);
        $if = $m[1];
        $mac = $m[2];
        $uuid = $m[3];
        if (4 != count($m)) {
            return;
        }
        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
        'ipv4' => $syslog->getSource(),
    ]);
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $logger->debug('Failed to log in to: '.$ap->getName());

            return false;
        }

        $o = new \stdClass();
        $o->path = '/etc/hostapd.kalnet.psk';
        $o->base64 = false;
        $stat = $session->call('file', 'read', $o);
        if (!is_object($stat)) {
            return;
        }
        if (!property_exists($stat, 'data')) {
            return;
        }

        $lines = explode("\n", $stat->data);
        $key = null;
        foreach ($lines as $line) {
            $fields = explode(' ', $line);
            if (2 != count($fields)) {
                continue;
            }
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
        if (!is_array($list)) {
            return;
        }
        $dev = null;
        foreach ($list as $device) {
            echo $device->getId()."\n";
            print_r($device->getConfig());
            $cfg = $device->getConfig();
            if (empty($device->getIfname())) {
                continue;
            }
            if ($device->getIfname() == $if) {
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
            foreach ($list as $ent) {
                $content = $ent->getContent()."\n";
                $content .= '#'.($syslog->getMessage())."\n";
                $content .= $mac.' '.$key."\n";
                $ent->setContent($content);
                echo $content;
                $em->persist($ent);
            }
        }
        $em->flush();

        foreach ($dev->getSSID()->getDevices() as $device) {
            $session = $this->rpcService->getSession($device->getRadio()->getAccesspoint());
            if (false === $session) {
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
                    $stat = $session->call('file', 'md5', $o);

                    if (!$stat || !isset($stat->md5) || ($stat->md5 != $md5)) {
                        $this->logger->debug($ap->getName().': Uploading file '.$configFile->getFileName());
                        $o = new \stdClass();
                        $o->path = $configFile->getFileName();
                        $o->append = false;
                        $o->base64 = false;
                        $o->data = $content;
                        $stat = $session->call('file', 'write', $o);
                    }
                }
            }
        }
    }

    public function fetchDynamicProperties(\ApManBundle\Entity\AccessPoint $ap)
    {
        $em = $this->doctrine->getManager();
        $qb = $em->createQueryBuilder();
        $query = $em->createQuery(
        'SELECT ap
	     FROM ApManBundle:AccessPoint ap
	     WHERE
	     ap.id = :id'
    );
        $query->setFetchMode("ApManBundle\AccessPoint", 'ap', 'EAGER');
        $query->setParameter('id', $apId);
        $ap = $query->getSingleResult();
        $session = $this->rpcService->getSession($ap);
        $opts = new \stdclass();
        $opts->command = 'ip';
        $opts->params = ['-s', 'link', 'show'];
        $opts->env = ['LC_ALL' => 'C'];
        $stat = $session->callCached('file', 'exec', $opts, 15);

        $data = $session->callCached('network.device', 'status', null, 15);
        $data = $session->callCached('iwinfo', 'devices', null, 15);
        $data = $session->callCached('system', 'info', null, 15);
        $data = $session->callCached('system', 'board', null, 15);
        foreach ($ap->getRadios() as $radio) {
            $p = new \stdClass();
            $p->device = $radio->getName();
            $data = $session->callCached('iwinfo', 'info', $p, 15);
            foreach ($radio->getDevices() as $device) {
                $config = $device->getConfig();
                if (empty($device->getIfname())) {
                    continue;
                }
                $o = new \stdClass();
                $o->device = $device->getIfname();
                $data = $session->callCached('iwinfo', 'info', $o, 15);
                $data = $session->callCached('iwinfo', 'assoclist', $o, 15);
                //print_r($data);
            /*
            if (is_object($data) && property_exists($data, 'results') && is_array($data->results)) {
                $this->ssidService->applyLocationConstraints($data->results, $device);
                $session->invalidateCache('iwinfo','assoclist', $o , 15);
            }
             */
            }
        }
        //$stop = microtime(true);
    //echo "Polled ".$ap->getName().", took ".sprintf('%0.3f',$stop-$start)."s\n";
    }

    public function lifetimeMessageHandler($ap, \Mosquitto\Message $message, $deviceList = null, \Mosquitto\Client $client)
    {
        //    var_dump($message);
        $cache = $this->cacheFactory->getCache();
        $em = $this->doctrine->getManager();
        $tp = explode('/', $message->topic);
        $msg = json_decode($message->payload, true);

        $stateKey = 'status.state['.$ap->getId().']';
        $state = $this->cacheFactory->getCacheItemValue($stateKey);
        if (!is_int($state)) {
            $state = null;
        }
        $stateOld = $state;
        $state = intval($state);
        $cif = 0;
        // Handle online message
        if ('online' == $tp[3]) {
            if (!isset($msg['status'])) {
                return false;
            }
            //echo $message->topic.' '.$message->payload."\n";

            $this->cacheFactory->addCacheItem('status.online['.$ap->getId().']', $msg);
            $this->logger->info('ApLifetimeHandler(): save online status from '.$ap->getName(), $msg);
            if ('online' == $msg['status']) {
                if (\ApManBundle\Library\AccessPointState::STATE_OFFLINE == $state) {
                    $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_ONLINE);
                }
            } else {
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_OFFLINE);
            }
            // Handle status Message
        } elseif ('wireless' == $tp[3] && 'status' == $tp[4]) {
            $this->cacheFactory->addCacheItem('status.wireless['.$ap->getId().']', $msg);
            $this->logger->info('ApLifetimeHandler(): save wireless status (length: '.strlen($message->payload).') from '.$ap->getName());

            if ($state < \ApManBundle\Library\AccessPointState::STATE_ONLINE) {
                return false;
            }

            $pending = false;
            $up = false;
            $failed = false;
            foreach ($msg as $name => $rstate) {
                //var_dump($rstate);
                if ('timestamp' == $name or !is_array($rstate)) {
                    continue;
                }

                if (isset($rstate['config']) && is_array($rstate['config']) && isset($rstate['config']['disabled']) && $rstate['config']['disabled']) {
                    // Skip disabled radios
                    continue;
                }
                if ($rstate['pending']) {
                    $pending = true;
                }
                if ($rstate['retry_setup_failed']) {
                    $failed = true;
                }
                if ($rstate['up']) {
                    $up = true;
                } else {
                    $up = false;
                }
                if (isset($rstate['interfaces']) && is_array($rstate['interfaces'])) {
                    $cif += count($rstate['interfaces']);
                }
                // Hook to update interface names of devices
                if (isset($rstate['interfaces']) && is_array($rstate['interfaces']) && is_array($deviceList) && isset($deviceList)) {
                    foreach ($rstate['interfaces'] as $interface) {
                        if (!isset($interface['ifname']) or !isset($interface['section'])) {
                            continue;
                        }
                        foreach ($deviceList as $ldev) {
                            if ($ldev->getName() !== $interface['section']) {
                                continue;
                            }
                            if ($ldev->getIfname() !== $interface['ifname']) {
                                $this->logger->info('ApLifetimeHandler(): assigned ifname '.$interface['ifname'].' to device '.$ldev->getName().' on '.$ap->getName());
                                $ldev->setIfname($interface['ifname']);
                                $em->persist($ldev);
                                $em->flush();
                            }
                        }
                    }
                }
            }
            if ($failed) {
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_FAILED);
            } elseif ($pending) {
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_PENDING);
            } elseif ($up && $state < \ApManBundle\Library\AccessPointState::STATE_CONFIGURED) {
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_CONFIGURED);
            } elseif ($up) {
                // keep
            } else {
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_PENDING);
            }
        }

        /*
         * Items to watch on every time a wireless status update comes in
         */
        if (is_array($deviceList)) {
            // Handle CAC / DFS
            if ($cif && $state >= \ApManBundle\Library\AccessPointState::STATE_CONFIGURED) {
                $cac_active = false;
                $found = 0;
                foreach ($deviceList as $device) {
                    $key = 'status.device.'.$device->getId();
                    $ds = $this->cacheFactory->getCacheItemValue($key);
                    if (null !== $ds) {
                        ++$found;
                    }
                    if (!is_array($ds) || !isset($ds['ap_status']) || !isset($ds['ap_status']['dfs']) || !isset($ds['ap_status']['dfs']['cac_active'])) {
                        continue;
                    }
                    //				if ($ap->getName() == 'ap-outdoor2.kalnet.hooya.de') echo "K $key V".substr(json_encode($ds),0,130)."\n";
                    //var_dump($ds['ap_status']['dfs']);
                    if ($ds['ap_status']['dfs']['cac_active']) {
                        $cac_active = true;
                    }
                }
                if ($found >= $cif) {
                    if ($cac_active && $state >= \ApManBundle\Library\AccessPointState::STATE_CONFIGURED) {
                        $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_DFS_RUNNING);
                    }
                    if (!$cac_active && $state >= \ApManBundle\Library\AccessPointState::STATE_CONFIGURED && $state <= \ApManBundle\Library\AccessPointState::STATE_DFS_RUNNING) {
                        $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_DFS_READY);
                    }
                } else {
                    $this->logger->warning('ApLifetimeHandler Monitoring '.$ap->getName().' Interfaces, DFS CAC Active: '.($cac_active ? 1 : 0).", Interfaces found: $found, configured Interfaces: $cif\n");
                    $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_DFS_RUNNING);
                }
            }

            // After DFS is ready, AP is also ready for activation
            if (\ApManBundle\Library\AccessPointState::STATE_DFS_READY == $state) {
                // Enable Beacons and BSS management
                $this->logger->info("ApLifetimeHandler(): state $state on ap ".$ap->getName().' detected, updating beacon.');
                $topic = 'apman/ap/'.$ap->getName().'/command/bulk';
                $commands = [
                'list' => [],
                'options' => [
                    'cancel_on_error' => false,
                ],
            ];
                // At first 5g, then 2g
                $dev2G = [];
                $dev5G = [];
                foreach ($deviceList as $device) {
                    if ('5g' == $device->getRadio()->getConfigBand()) {
                        $dev5G[] = $device;
                    } else {
                        $dev2G[] = $device;
                    }
                }
                $opts = new \stdClass();
                $opts->neighbor_report = true;
                $opts->beacon_report = true;
                $opts->bss_transition = true;
                foreach ($dev5G as $device) {
                    $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'bss_mgmt_enable', $opts);
                    $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'update_beacon', []);
                }
                if (count($commands['list'])) {
                    $eopts = new \stdclass();
                    $eopts->command = 'sleep';
                    $eopts->params = ['5'];
                    $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'file', 'exec', $eopts);
                }
                foreach ($dev2G as $device) {
                    $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'bss_mgmt_enable', $opts);
                    $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'update_beacon', []);
                }
                if (count($commands['list'])) {
                    $client->publish($topic, json_encode($commands));
                }

                // Assign Neighbors, enable reports
                $this->logger->info('ApLifetimeHandler(): state '.\ApManBundle\Library\AccessPointState::getStateName($state).' on ap '.$ap->getName().' detected, start AssignAllNeighbors.');
                $this->assignAllNeighbors();
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_ACTIVE);
            }
        }

        // Cache update
        $state = $this->changeLifetimeState($ap, $state);
        $this->logger->debug("ApLifetimeHandler(): state '".\ApManBundle\Library\AccessPointState::getStateName($state)."' of ap ".$ap->getName());
    }

    public function lifetimeHouseKeeping(array $aps, array $devicesByAp)
    {
        $em = $this->doctrine->getManager();
        if (is_null($aps) || !is_array($aps) || !count($aps)) {
            $this->output->writeln('No productive Accesspoints found.');

            return false;
        }
        $apsNotActive = [];
        $productive = 0;
        $total = 0;
        foreach ($aps as $ap) {
            if (!$ap->getIsProductive()) {
                // ignore others;
                continue;
            }
            ++$total;
            $stateKey = 'status.state['.$ap->getId().']';
            $state = $this->cacheFactory->getCacheItemValue($stateKey);
            $state = \ApManBundle\Library\AccessPointState::getStateName($state);
            if ('STATE_ACTIVE' != $state) {
                $apsNotActive[] = $ap;
            }
        }
        if (!count($apsNotActive)) {
            return;
        }
        $this->logger->error('Failure - '.count($apsNotActive).' APs offline|online='.($total - count($apsNotActive)).' offline='.count($apsNotActive));

        return;
    }

    private function changeLifetimeState($ap, int $state)
    {
        $stateKey = 'status.state['.$ap->getId().']';
        $stateOld = $this->cacheFactory->getCacheItemValue($stateKey);
        if ($state != $stateOld) {
            $this->logger->notice("changeLifetimeState(): changing state from '".\ApManBundle\Library\AccessPointState::getStateName($stateOld).
            "' to '".\ApManBundle\Library\AccessPointState::getStateName($state)."'  of ap ".$ap->getName());
        }
        $this->cacheFactory->addCacheItem($stateKey, $state);

        return $state;
    }

    public function assignAllNeighbors()
    {
        $em = $this->doctrine->getManager();
        $client = $this->mqttFactory->getClient();

        $cmds = [];
        $ssids = $this->doctrine->getRepository('ApManBundle:SSID')->findall();
        foreach ($ssids as $ssid) {
            $neighbors = [];
            foreach ($ssid->getDevices() as $device) {
                $radio = $device->getRadio();
                $ap = $radio->getAccesspoint();
                if (empty($device->getIfname())) {
                    $this->logger->error('assignAllNeighbors: ifname missing for '.$ap->getName().':'.$radio->getName().':'.$device->getName());
                    continue;
                }

                $nr_own = $device->getRrm();
                if (is_array($nr_own) && array_key_exists('value', $nr_own)) {
                    $neighbors[] = $nr_own['value'];
                }
            }
            if (!count($neighbors)) {
                continue;
            }
            foreach ($ssid->getDevices() as $device) {
                $radio = $device->getRadio();
                $ap = $radio->getAccesspoint();
                if (empty($device->getIfname())) {
                    $this->logger->error('assignAllNeighbors(): ifname missing for '.$ap->getName().':'.$radio->getName().':'.$device->getName()."\n");
                    continue;
                }

                $nr_own = $device->getRrm();
                if (!(is_array($nr_own) && array_key_exists('value', $nr_own))) {
                    continue;
                }

                $own_neighbors = [];
                foreach ($neighbors as $neighbor) {
                    if ($neighbor[0] == $nr_own['value'][0]) {
                        continue;
                    }
                    $own_neighbors[] = $neighbor;
                }
                $opts = new \stdClass();
                $opts->list = $own_neighbors;

                $ap = $device->getRadio()->getAccessPoint()->getName();
                if (!isset($cmds[$ap])) {
                    $cmds[$ap] = [];
                }
                $cmds[$ap][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'rrm_nr_set', $opts);
            }
            //print_r($neighbors);
        }
        foreach ($cmds as $apname => $apcmds) {
            $topic = 'apman/ap/'.$apname.'/command/bulk';
            $commands = [
            'list' => $apcmds,
            'options' => [
                'cancel_on_error' => false,
            ],
        ];
            if (count($commands['list'])) {
                $this->logger->info("assignAllNeighbors(): send rrm commands to $apname\n");
                $client->publish($topic, json_encode($commands));
            }
        }
    }
}
