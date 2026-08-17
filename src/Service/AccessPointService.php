<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;

class AccessPointService
{
    private $ppskService;
    private $steering;
    private $logger;
    private $doctrine;
    private $rpcService;
    private $kernel;
    private $mqttFactory;
    private $cacheFactory;
    private $steeringState = ['clients' => [], 'state' => []];
    private $ieparser;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \Symfony\Component\HttpKernel\KernelInterface $kernel,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Factory\CacheFactory $cacheFactory,
        WifiIeParser $ieparser,
        PpskService $ppskService,
        SteeringService $steering
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->kernel = $kernel;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
        $this->ieparser = $ieparser;
        $this->ppskService = $ppskService;
        $this->steering = $steering;
    }

    public function getSteering()
    {
        return $this->steering;
    }

    /**
     * get Configuration for a device.
     *
     * @return string|\null
     */
    /**
     * rpcd stages uci changes per session and rejects "uci apply" without one,
     * so every uci call of a provisioning run has to carry the same session.
     * The agent publishes it on properties/session/create.
     */
    public function getApSession($ap)
    {
        $session = $this->cacheFactory->getCacheItemValue('status.ap.'.$ap->getId().'.session');

        return is_array($session) && !empty($session['id']) ? $session['id'] : null;
    }

    private function uciRequest($id, $method, $opts, $session)
    {
        // feature services hand back plain arrays, the rest are objects
        if (is_array($opts)) {
            $opts = (object) $opts;
        }
        if ($session) {
            $opts->ubus_rpc_session = $session;
        }

        return $this->rpcService->createRpcRequest($id, 'call', null, 'uci', $method, $opts);
    }

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
        $additionalCfgs = [];
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
            $additionalCfg = $instance->getAdditionalConfig($cfg);
            if (is_array($additionalCfg) and count($additionalCfg)) {
                $additionalCfgs = array_merge($additionalCfgs, $additionalCfg);
            }
        }
        $configObject = new \stdClass();
        $configObject->config = 'wireless';
        $configObject->type = 'wifi-iface';
        $configObject->name = $device->getName();
        $configObject->values = json_decode(json_encode($cfg), true);

        return [$configObject, $additionalCfgs];
    }

    /**
     * publish config.
     *
     * @return \boolean|\object|\null
     */
    public function publishConfig($ap, $return = false)
    {
        $logger = $this->logger;
        $em = $this->doctrine->getManager();
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

        $session = $this->getApSession($ap);
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
        $commands['list'][] = $this->uciRequest('delete-wifi-iface', 'delete', $opts, $session);

        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-device';
        $commands['list'][] = $this->uciRequest('delete-wifi-device', 'delete', $opts, $session);

        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-vlan';
        $commands['list'][] = $this->uciRequest('delete-wifi-vlan', 'delete', $opts, $session);

        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-station';
        $commands['list'][] = $this->uciRequest('delete-wifi-station', 'delete', $opts, $session);

        // No commit here on purpose: delete and add belong to one staged
        // transaction. Committing the deletions on their own leaves the access
        // point with an empty, persisted wireless config whenever the run is
        // interrupted afterwards.

        $query = $em->createQuery(
            'SELECT r
			     FROM ApManBundle:Radio r
			     WHERE r.accesspoint = :ap
			     ORDER by r.name ASC'
        );
        $query->setParameter('ap', $ap);
        $radios = $query->getResult();
        foreach ($radios as $radio) {
            $logger->debug($ap->getName().': Configuring radio '.$radio->getName());
            $opts = new \stdClass();
            $opts->config = 'wireless';
            $opts->section = $radio->getName();
            $opts->name = $radio->getName();
            $opts->type = 'wifi-device';
            $opts->values = $radio->exportConfig();
            $commands['list'][] = $this->uciRequest('radio-'.$radio->getName(), 'add', $opts, $session);
            $query = $em->createQuery(
                'SELECT d
			     FROM ApManBundle:Device d
			     LEFT JOIN d.ssid s
			     WHERE d.radio = :radio
			     ORDER by s.setup_order ASC'
            );
            $query->setParameter('radio', $radio);
            $devices = $query->getResult();

            foreach ($devices as $device) {
                $logger->debug($ap->getName().': Configuring device '.$device->getName());

                list($config, $extraConfigs) = $this->getDeviceConfig($device);

                $logger->debug($ap->getName().': Configuring device '.$device->getName().' EX: '.print_r($extraConfigs, true));
                $commands['list'][] = $this->uciRequest('dev-'.$device->getName(), 'add', $config, $session);
                if (is_array($extraConfigs) && count($extraConfigs)) {
                    foreach ($extraConfigs as $extraConfig) {
                        $logger->debug($ap->getName().': Configuring device '.$device->getName().' EX2: '.json_encode($extraConfig));
                        $commands['list'][] = $this->uciRequest('extra-'.$device->getName().'-'.count($commands['list']), 'add', $extraConfig, $session);
                    }
                }

                // per device psks ride along with the wireless config: this is
                // the persistent half, wifi-scripts renders the runtime
                // wpa_psk_file from these sections on every reload
                foreach ($this->ppskService->getStationSections($device) as $station) {
                    $commands['list'][] = $this->uciRequest('ppsk-'.$station->name, 'add', $station, $session);
                }

                $changed = true;
                $logger->debug($ap->getName().': Configured device '.$device->getName());

                continue;
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

        // Let the access point report what would actually change. The staged
        // changes stay uncommitted until applyConfig() decides, so a run that
        // changes nothing costs no commit and no wifi reload.
        $logger->debug($ap->getName().': Requesting staged diff');
        $opts = new \stdClass();
        $opts->config = 'wireless';
        $commands['list'][] = $this->uciRequest('changes', 'changes', $opts, $session);

        if (!$session) {
            // no session known yet: fall back to the old behaviour, which
            // cannot roll back but at least applies the configuration
            $logger->warning($ap->getName().': no ubus session known, committing without rollback');
            $commands['list'][] = $this->uciRequest('commit', 'commit', $opts, null);
        }

        if ($return) {
            $logger->debug($ap->getName().':Configuring radio, returned commaneds: '.json_encode($commands));
            return $commands;
        }

        $res = $client->publish($topic, json_encode($commands));
        $logger->debug($ap->getName().':Configuring radio, publishing to topic '.$topic.': '.json_encode($commands));

        //$client->loop(1);

        return true;
    }

    /**
     * Provision one access point and verify it.
     *
     * Three phases, because a wlan configuration is changed over the very wlan
     * it configures:
     *   1. stage the whole configuration in one uci transaction and ask the
     *      access point for the resulting diff
     *   2. nothing changed -> revert the staging, no commit, no reload
     *   3. something changed -> uci apply with rollback armed, then confirm
     *      once the access point answered. If the controller never confirms
     *      (bad config, ap unreachable), rpcd reverts on its own.
     *
     * @return array report
     */
    public function applyConfig($ap, $dryRun = false, $timeout = 90)
    {
        $logger = $this->logger;
        $session = $this->getApSession($ap);
        $commands = $this->publishConfig($ap, true);
        if (!is_array($commands) || empty($commands['list'])) {
            return ['ok' => false, 'error' => 'no configuration generated'];
        }
        $report = [
            'ap' => $ap->getName(),
            'commands' => count($commands['list']),
            'session' => (bool) $session,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            $report['ok'] = true;
            $report['staged'] = $this->summarise($commands);

            return $report;
        }

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return ['ok' => false, 'error' => 'no mqtt connection'];
        }
        $topic = 'apman/ap/'.$ap->getName().'/command/bulk';
        $client->publish($topic, json_encode($commands), 1);

        if (!$session) {
            $client->disconnect();
            $report['ok'] = true;
            $report['note'] = 'committed without rollback, no ubus session known for this ap';

            return $report;
        }

        // collect the per command answers the agent publishes back
        $results = $this->collectResults($ap, $commands, 8);
        $report['answered'] = count($results);
        $report['failed'] = [];
        foreach ($results as $id => $res) {
            if (isset($res['error'])) {
                $report['failed'][$id] = ($res['error']['message'] ?? 'failed').' ('.($res['error']['code'] ?? '?').')';
            }
        }

        if (!$results) {
            $client->disconnect();
            $report['ok'] = false;
            $report['error'] = 'no answer from the access point, nothing was applied';

            return $report;
        }

        $changes = isset($results['changes']['result']) ? $results['changes']['result'] : null;
        $changeCount = 0;
        if (is_array($changes) && isset($changes['changes']) && is_array($changes['changes'])) {
            foreach ($changes['changes'] as $config => $list) {
                $changeCount += is_array($list) ? count($list) : 0;
            }
            $report['changes'] = $changes['changes'];
        }
        $report['change_count'] = $changeCount;

        if ($report['failed']) {
            // do not apply a half staged configuration
            $this->sendUci($client, $ap, 'revert-failed', 'revert', $session);
            $client->disconnect();
            $report['ok'] = false;
            $report['error'] = 'staging failed, changes reverted';

            return $report;
        }

        if (0 === $changeCount) {
            $this->sendUci($client, $ap, 'revert-nochange', 'revert', $session);
            $client->disconnect();
            $report['ok'] = true;
            $report['note'] = 'configuration already up to date, nothing applied';

            return $report;
        }

        // apply with the rollback timer armed
        $opts = new \stdClass();
        $opts->rollback = true;
        $opts->timeout = $timeout;
        $opts->ubus_rpc_session = $session;
        $cmd = $this->rpcService->createRpcRequest('apply', 'call', null, 'uci', 'apply', $opts);
        $client->publish('apman/ap/'.$ap->getName().'/command', json_encode($cmd), 1);
        $logger->notice($ap->getName().': applied '.$changeCount.' change(s), rollback armed for '.$timeout.'s');

        $applied = $this->collectResults($ap, ['list' => [$cmd]], 10);
        if (!isset($applied['apply']) || isset($applied['apply']['error'])) {
            $client->disconnect();
            $report['ok'] = false;
            $report['error'] = 'apply failed: '.
                ($applied['apply']['error']['message'] ?? 'no answer').' — the access point rolls back on its own';

            return $report;
        }

        // the access point answered after applying, so it is still reachable
        $confirm = new \stdClass();
        $confirm->ubus_rpc_session = $session;
        $cmd = $this->rpcService->createRpcRequest('confirm', 'call', null, 'uci', 'confirm', $confirm);
        $client->publish('apman/ap/'.$ap->getName().'/command', json_encode($cmd), 1);
        $client->disconnect();

        $confirmed = $this->collectResults($ap, ['list' => [$cmd]], 10);
        $report['ok'] = isset($confirmed['confirm']) && !isset($confirmed['confirm']['error']);
        if (!$report['ok']) {
            $report['error'] = 'could not confirm, the access point will roll back in '.$timeout.'s';
        }

        return $report;
    }

    private function sendUci($client, $ap, $id, $method, $session, \stdClass $opts = null)
    {
        $opts = $opts ?: new \stdClass();
        $opts->ubus_rpc_session = $session;
        $cmd = $this->rpcService->createRpcRequest($id, 'call', null, 'uci', $method, $opts);
        $client->publish('apman/ap/'.$ap->getName().'/command', json_encode($cmd), 1);
    }

    /**
     * The subscriber caches every command result under
     * command.result.<host>.<id>, so a provisioning run can be verified
     * instead of being fired blindly.
     */
    private function collectResults($ap, array $commands, $seconds)
    {
        $ids = [];
        foreach ($commands['list'] as $cmd) {
            if (isset($cmd->id)) {
                $ids[$cmd->id] = true;
            }
        }
        $results = [];
        $deadline = microtime(true) + $seconds;
        while ($ids && microtime(true) < $deadline) {
            foreach (array_keys($ids) as $id) {
                $res = $this->cacheFactory->getCacheItemValue('command.result.'.$ap->getName().'.'.$id);
                if (is_array($res)) {
                    $results[$id] = $res;
                    unset($ids[$id]);
                }
            }
            if ($ids) {
                usleep(250000);
            }
        }

        return $results;
    }

    private function summarise(array $commands)
    {
        $summary = [];
        foreach ($commands['list'] as $cmd) {
            $p = $cmd->params ?? null;
            if (!is_array($p) || !isset($p[2])) {
                continue;
            }
            $summary[] = ($cmd->id ?? '?').': '.$p[1].' '.$p[2];
        }

        return $summary;
    }

    /**
     * Scan the neighbourhood from one access point and remember which BSSID
     * belongs to which network.
     *
     * Beacon reports only carry the BSSID, so without this the foreign entries
     * in a coverage view stay anonymous. An off channel scan briefly interrupts
     * traffic on that radio, which is why this only runs on request.
     *
     * @return array summary
     */
    public function scanNeighbours($ap, $ttl = 604800)
    {
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            return ['ok' => false, 'error' => 'cannot log in to '.$ap->getName()];
        }

        // one interface per radio is enough, they share the antenna
        $perRadio = [];
        foreach ($ap->getRadios() as $radio) {
            foreach ($radio->getDevices() as $device) {
                if ($device->getIfname() && !isset($perRadio[$radio->getName()])) {
                    $perRadio[$radio->getName()] = $device->getIfname();
                }
            }
        }

        $found = 0;
        $errors = [];
        foreach ($perRadio as $radioName => $ifname) {
            $opts = new \stdClass();
            $opts->device = $ifname;
            $result = $session->call('iwinfo', 'scan', $opts);
            if (!is_object($result) || !property_exists($result, 'results')) {
                $errors[] = $radioName.'/'.$ifname;
                continue;
            }
            foreach ($result->results as $entry) {
                $entry = (array) $entry;
                if (empty($entry['bssid'])) {
                    continue;
                }
                $bssid = strtolower($entry['bssid']);
                $this->cacheFactory->addCacheItem('neighbour.'.str_replace(':', '', $bssid), [
                    'bssid' => $bssid,
                    'ssid' => ($entry['ssid'] ?? '') !== '' ? $entry['ssid'] : null,
                    'channel' => $entry['channel'] ?? null,
                    'band' => $entry['band'] ?? null,
                    'signal' => $entry['signal'] ?? null,
                    'seen_by' => $ap->getName().'/'.$ifname,
                    'ts' => time(),
                ], $ttl);
                ++$found;
            }
        }
        $this->logger->notice('scanNeighbours(): '.$ap->getName().' found '.$found.' bss on '.count($perRadio).' radio(s)');

        return ['ok' => true, 'found' => $found, 'radios' => count($perRadio), 'failed' => $errors];
    }

    /**
     * What we know about a BSSID that is not one of ours: the network name from
     * the last scan, otherwise at least the vendor behind the address.
     */
    public function describeForeignBssid($bssid)
    {
        $bssid = strtolower($bssid);
        $out = ['bssid' => $bssid, 'ssid' => null, 'vendor' => null, 'local' => false, 'seen_by' => null];

        $known = $this->cacheFactory->getCacheItemValue('neighbour.'.str_replace(':', '', $bssid));
        if (is_array($known)) {
            $out['ssid'] = $known['ssid'];
            $out['seen_by'] = $known['seen_by'];
            $out['scan_signal'] = $known['signal'] ?? null;
            $out['scan_age'] = isset($known['ts']) ? time() - $known['ts'] : null;
        }

        // second nibble bit 1 marks a locally administered address, which is
        // what a virtual bss or a phone hotspot uses; its oui means nothing
        $first = hexdec(substr(str_replace(':', '', $bssid), 0, 2));
        $out['local'] = (bool) ($first & 0x02);
        if (!$out['local']) {
            try {
                $out['vendor'] = $this->getMacManufacturer($bssid);
            } catch (\Throwable $e) {
                $out['vendor'] = null;
            }
        }

        return $out;
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
            $this->logger->warning('No radios found on AP '.$ap->getName());

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
     * @return \boolean|\object|\null
     */
    public function stopRadio($ap, $return = false)
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
        if ($return) {
            $logger->debug($ap->getName().':Stopping radio, returned: '.json_encode($cmd));
            return $cmd;
        }
        $client->publish($topic, json_encode($cmd));
        $logger->debug($ap->getName().': Stopping radio, publishing to topic '.$topic.': '.json_encode($cmd));

        return true;
    }

    /**
     * start radio.
     *
     * @return \boolean|\object|\null
     */
    public function startRadio($ap, $return = false)
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
        if ($return) {
            $logger->debug($ap->getName().':Start radio, returned: '.json_encode($cmd));
            return $cmd;
        }
        $client->publish($topic, json_encode($cmd));
        $logger->debug($ap->getName().':Start radio, publishing to topic '.$topic.': '.json_encode($cmd));

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
     * processLogMessage.
     *
     * @return \array|\boolean
     */
    public function processLogMessage($syslog)
    {
        // WPS enrolment used to be scraped out of syslog here. It is driven by
        // the controller now (PpskService), which starts the registration and
        // adopts the generated key afterwards, so nothing has to be parsed out
        // of log lines any more.
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

    public function lifetimeMessageHandler($ap, \ApManBundle\Mqtt\Message $message, $deviceList = null, \ApManBundle\Mqtt\Publisher $client)
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

        return true;
    }

    public function lifetimeHouseKeeping(array $aps, array $devicesByAp)
    {
        $em = $this->doctrine->getManager();
        if (is_null($aps) || !is_array($aps) || !count($aps)) {
            $this->logger->warn('No productive Accesspoints found.');

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

    public function handleStationUpdates(\ApManBundle\Entity\Device $device, array $data)
    {
        if (!array_key_exists('clients', $data)) {
            return false;
        }
        if (!is_array($data['clients'])) {
            return false;
        }
        if (!array_key_exists('clients', $data['clients'])) {
            return false;
        }
        if (!is_array($data['clients']['clients'])) {
            return false;
        }
        $clients = $data['clients']['clients'];
        if (0 == count($clients)) {
            return false;
        }
        if (!isset($data['assoclist'])) {
            return false;
        }
        if (!is_array($data['assoclist'])) {
            return false;
        }
        if (!isset($data['assoclist']['results'])) {
            return false;
        }
        if (!is_array($data['assoclist']['results'])) {
            return false;
        }
        if (0 == count($data['assoclist']['results'])) {
            return false;
        }

        foreach ($data['assoclist']['results'] as $r) {
            $mac = strtolower($r['mac']);
            if (isset($clients[$mac])) {
                $this->stationRunner($device, $mac, $r, $clients[$mac]);
            }
        }
    }

    private function stationRunner(\ApManBundle\Entity\Device $device, string $mac, array $assocProps, array $apData)
    {
        $em = $this->doctrine->getManager();

        $band = $device->getRadio()->getConfigBand();
        $updated = false;
        if (!array_key_exists($mac, $this->steeringState['clients'])) {
            $qb = $em->createQueryBuilder();
            $query = $em->createQuery(
                'SELECT c
			 FROM ApManBundle:Client c
			 WHERE c.mac=:mac'
            );
            $query->setParameter('mac', $mac);
            try {
                $client = $query->getSingleResult();
            } catch (\Doctrine\ORM\NoResultException $e) {
                $this->logger->warning('stationRunner('.$mac.'): Client not found, create it.');
                $client = new \ApManBundle\Entity\Client();
                $client->setMac($mac);
                $updated = true;
            }
            $this->steeringState['clients'][$mac] = $client;
        }
        $client = $this->steeringState['clients'][$mac];

        // Check if band updates are needed
        if ('5g' == $band) {
            if (!$client->getModeA()) {
                $client->setModeA(true);
                $updated = true;
            }
        } elseif ('2g' == $band) {
            if (!$client->getModeG()) {
                $client->setModeG(true);
                $updated = true;
            }
        }
        if ($updated) {
            $em->persist($client);
            $em->flush();
        }

        return $this->steerClient($client, $device, $mac, $assocProps, $apData);
    }

    private function steerClient(\ApManBundle\Entity\Client $client, \ApManBundle\Entity\Device $device, string $mac, array $assocProps, array $apData)
    {
        $em = $this->doctrine->getManager();
        $ap = $device->getRadio()->getAccesspoint();
        $ssid = $device->getSsid();
        $band = $device->getRadio()->getConfigBand();
        $try = 1;
        $type = 'del_client';
        $transition_timeout = 30;
        $wnm_capable = false;

        if ($client->getSteeringDisabled()) {
            //$this->logger->warning('steerClient('.$mac.'): Steering disabled for client.');
            return false;
        }
        if ('kalnet' !== $ssid->getName()) {
            //$this->logger->warning('steerClient('.$mac.'): Steering disabled for SSID.', [ 'ssid' => $ssid->getName() ] );
            return false;
        }

        $connected = intval($assocProps['connected_time']);
        if ($connected < 100) {
            // freshly associated: if a transition was pending, this is the
            // result. Previously this check sat above the evaluation below,
            // which made that code unreachable.
            if (array_key_exists($mac, $this->steeringState['state'])) {
                $this->logger->info('steerClient('.$mac.'): client reconnected while a transition was pending.');
            }

            return false;
        }

        // the client answered "no" often enough; asking again only costs frames
        if ($this->steering->isGivenUp($mac)) {
            $state = $this->steering->getState($mac);
            $this->logger->info('steerClient('.$mac.'): giving up, '.($state['rejects'] ?? 0).
                ' rejections in a row'.(isset($state['last']['reason']) ? ' ('.$state['last']['reason'].')' : ''));

            return false;
        }

        $signal = intval($assocProps['signal']);

        if (isset($apData['signature']) and !empty($apData['signature'])) {
            $ieTags = $this->ieparser->parseSignature($apData['signature']);
            if (is_array($ieTags)) {
                $ieCaps = $this->ieparser->getExtendedCapabilities($ieTags);
                if (in_array('BSS Transition', $ieCaps)) {
                    //$this->logger->warning('steerClient('.$mac.'): Capable of wnm notification.');
                    $wnm_capable = true;
                    $type = 'bss_transition_request';
                }
            }
        }

        if ('5g' == $band) {
            // Already connected via 5g, no need.
            if (array_key_exists($mac, $this->steeringState['state'])) {
                // transition was successfull
                unset($this->steeringState['state'][$mac]);
                $this->logger->warning('steerClient('.$mac.'): successfull.');
            }

            return false;
        }

        if ('2g' == $band and $connected < 100) {
            // The last transition failed, do not retry.
            if (array_key_exists($mac, $this->steeringState['state'])) {
                $this->steeringState['state'][$mac]['try'] = 99;
                $this->logger->error('steerClient('.$mac.'): Last transition failed, blocking further.');

                return false;
            }
        }

        if ('2g' == $band and $signal > -65 and $connected > 100) {
            if (!$client->getModeA()) {
                return false;
            }
        } else {
            return false;
        }

        if (array_key_exists($mac, $this->steeringState['state'])) {
            if (time() <= $this->steeringState['state'][$mac]['timeout'] + $transition_timeout) {
                $this->logger->error('steerClient('.$mac.'): ignoring request, another steering process is ongoing.', $this->steeringState['state'][$mac]);

                return false;
            }

            if ($this->steeringState['state'][$mac]['try'] > 0) {
                // totally ignore
                return false;
            }
            $this->logger->error('steerClient('.$mac.'): Timeout reached, start new request.', $this->steeringState['state'][$mac]);
            $try = $this->steeringState['state'][$mac]['try'] + 1;
            unset($this->steeringState['state'][$mac]);
        }

        $mclient = $this->mqttFactory->getClient();
        if (!$mclient) {
            $this->logger->error('steerClient('.$mac.'): Failed to get mqtt client.');

            return false;
        }

        if ('del_client' == $type) {
            $opts = new \stdClass();
            $opts->addr = $mac;
            $opts->reason = 5;
            $opts->deauth = false;
            $opts->ban_time = 10;

            $topic = 'apman/ap/'.$ap->getName().'/command';
            $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'del_client', $opts);
            $this->logger->warning('steerClient('.$mac.'): Sending del_client message to topic '.$topic.': '.json_encode($cmd), ['wnm_capable' => $wnm_capable]);
            $res = $mclient->publish($topic, json_encode($cmd));
            $this->steeringState['state'][$mac] = ['client' => $client, 'last_sent' => time(), 'timeout' => time() + $opts->ban_time, 'try' => $try];

            return true;
        } elseif ('bss_transition_request' == $type) {
            // Pick a target the client itself reported hearing well. Steering
            // used to look for any 5g bss on the same access point, which is
            // why the fleet answered nearly every request with "low RSSI".
            $target = $this->steering->pickTarget($mac, $device, true);
            if (!$target) {
                $this->logger->info('steerClient('.$mac.'): no target the client hears well enough.', [
                    'device' => $device->getId(), 'ap' => $ap->getName(),
                ]);

                return false;
            }
            $targetDev = $target['device'];
            $this->logger->notice('steerClient('.$mac.'): target '.$target['ap'].'/'.$targetDev->getIfname().
                ' — client hears it at '.$target['heard'].' dBm'.
                (null !== $target['gain'] ? ' ('.sprintf('%+.1f', $target['gain']).' dB)' : '').
                ' via '.$target['source']);

            $opts = new \stdClass();
            $opts->addr = $mac;
            $opts->abridged = true;
            $opts->neighbors = [];
            $opts->disassociation_imminent = false;
            $opts->disassociation_timer = 150;

            $rrm = $targetDev->getRrm();
            $rrm = json_decode(json_encode($rrm));
            if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                $this->logger->error('steerClient('.$mac.'): target has no neighbour report.', ['device' => $targetDev->getId()]);

                return false;
            }
            $opts->neighbors = [$rrm->value[2]];
            $this->steering->markPending($mac, $target['bssid']);

            $topic = 'apman/ap/'.$ap->getName().'/command';
            $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'bss_transition_request', $opts);
            $this->logger->warning('steerClient('.$mac.'): Sending bss_transition_request message to topic '.$topic.': '.json_encode($cmd), ['wnm_capable' => $wnm_capable]);
            $res = $mclient->publish($topic, json_encode($cmd));
            $this->steeringState['state'][$mac] = ['client' => $client, 'last_sent' => time(), 'timeout' => time() + $opts->disassociation_timer / 10, 'try' => $try];

            return true;
        }
    }
}
