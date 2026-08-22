<?php

namespace ApManBundle\Service;

use ApManBundle\Library\NodeState;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

class AccessPointService
{
    /** ubus answers this when the object or the section does not exist */
    private const UBUS_NOT_FOUND = 4;

    /**
     * Seconds between switching bss management on for 5 GHz and for 2.4 GHz.
     *
     * The staggering is what the removed "sleep 5" was for: give the band a
     * client should prefer a head start at announcing itself before the other
     * one starts advertising the same neighbours.
     */
    private const MGMT_STAGGER_SECONDS = 5;

    private $ppskService;
    private $steering;
    private $stateTree;
    private $logger;
    private $doctrine;
    private $rpcService;
    private $kernel;
    private $mqttFactory;
    private $cacheFactory;
    /** set by the subscriber; null in web and console context */
    private $publisher;
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
        SteeringService $steering,
        StateTreeService $stateTree
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
        $this->stateTree = $stateTree;
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

        // An SSID pointing its ppsk auth at the AP itself is answered by the
        // AP's own RADIUS server, whose secret is per AP and lives in
        // /etc/config/apman. The SSID config carries the marker (auth_server
        // 127.0.0.1) but no secret — this override is what gives every AP its
        // own, provisioned with the rest of the config.
        if ($this->ppskService->usesOnApRadius($device->getSsid())) {
            $ap = $device->getRadio()->getAccessPoint();
            if ($ap) {
                $config->auth_secret = $ap->getRadiusSecret() ?: '';
            }
        }

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
			     FROM ApManBundle\Entity\SSIDFeatureMap fm
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
        // 802.11r: nas_identifier (uci: nasid) is the R0KH-ID and has to be
        // unique per access point. Coming from the SSID config it is the same
        // row for the whole fleet, so every access point accepts every
        // broadcast PMK-R1 pull and the ones that never held the key answer
        // "No matching PMK-R0-Name found", racing the one real answer.
        // Measured 2026-08-21: with a unique value the transition completes
        // (auth_alg=ft), with the shared one it never did.
        //
        // Set last, after the features have had their say — ieee80211r often
        // comes from one of them. Derived from the access point name so it is
        // stable across runs, and cut to the 48 octets hostapd accepts.
        if (!empty($cfg['ieee80211r']) || !empty($cfg['mobility_domain'])) {
            $ap = $device->getRadio() ? $device->getRadio()->getAccessPoint() : null;
            if ($ap && $ap->getName()) {
                $cfg['nasid'] = substr($ap->getName(), 0, 48);
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
     * What the features do to this network's configuration, without doing it.
     *
     * The options edited on the network page are not the last word: every
     * enabled SSIDFeatureMap runs its implementation over the configuration on
     * the way to an access point, in priority order, and each may add, change
     * or remove keys. Editing "hidden" and then finding it set the other way on
     * the device is confusing until you know that; showing it here is the
     * cheaper answer.
     *
     * This runs the same chain as getDeviceConfig() with two deliberate
     * differences. It starts from the network's own options rather than a
     * device's, because a preview belongs to the network. And it never calls
     * applyConstraints(), which is not a read: StaticMACFeatureService assigns
     * a MAC address there and persists it. getConfig() is pure in every
     * implementation we have, but it runs behind a try/catch anyway — a feature
     * that cannot be previewed must not take the page down with it.
     *
     * @return array{maps: array, final: array, overridden: array}
     */
    public function previewFeatureOverrides(\ApManBundle\Entity\SSID $ssid)
    {
        $em = $this->doctrine->getManager();
        $query = $em->createQuery(
            'SELECT fm FROM ApManBundle\Entity\SSIDFeatureMap fm
             WHERE fm.ssid = :ssid
             ORDER by fm.priority ASC, fm.id ASC'
        );
        $query->setParameter('ssid', $ssid);
        $maps = $query->getResult();

        $cfg = json_decode(json_encode($ssid->exportConfig()), true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $own = $cfg;

        $rows = [];
        $overridden = [];
        foreach ($maps as $map) {
            $feature = $map->getFeature();
            $row = [
                'id' => $map->getId(),
                'name' => $map->getName(),
                'feature' => $feature ? $feature->getName() : null,
                'implementation' => $feature ? $this->shortImplementation($feature->getImplementation()) : null,
                'priority' => $map->getPriority(),
                'enabled' => (bool) $map->getEnabled(),
                'config' => $map->getConfig(),
                'featureConfig' => $feature ? $feature->getConfig() : [],
                'changes' => [],
                'error' => null,
            ];

            if (!$row['enabled'] || !$feature) {
                // a disabled mapping changes nothing, but it belongs on the
                // page: it is the difference between "not configured" and
                // "configured and switched off"
                $rows[] = $row;
                continue;
            }

            $before = $cfg;
            try {
                $implementation = $feature->getImplementation();
                if (!class_exists($implementation)) {
                    throw new \RuntimeException('no such implementation: '.$implementation);
                }
                $instance = new $implementation();
                $instance->setServices($this->logger, $this->doctrine, $this->rpcService, $this->mqttFactory, $this->kernel);
                $instance->setFeature($feature);
                $instance->setSSID($ssid);
                $instance->setSSIDFeatureMap($map);
                $after = $instance->getConfig($cfg);
                if (is_array($after)) {
                    $cfg = $after;
                }
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
                $rows[] = $row;
                continue;
            }

            $row['changes'] = $this->diffConfig($before, $cfg);
            foreach ($row['changes'] as $change) {
                // only what the network itself sets can be overridden; a key a
                // feature invents is an addition, not an override
                if (array_key_exists($change['key'], $own)) {
                    $overridden[$change['key']] = $row['name'] ?: $row['feature'];
                }
            }
            $rows[] = $row;
        }

        return ['maps' => $rows, 'final' => $cfg, 'overridden' => $overridden];
    }

    /**
     * Which keys one step changed, and how.
     */
    private function diffConfig(array $before, array $after)
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (!array_key_exists($key, $before)) {
                $changes[] = ['key' => $key, 'from' => null, 'to' => $value, 'kind' => 'added'];
            } elseif (!$this->sameValue($before[$key], $value)) {
                $changes[] = ['key' => $key, 'from' => $before[$key], 'to' => $value, 'kind' => 'changed'];
            }
        }
        foreach ($before as $key => $value) {
            if (!array_key_exists($key, $after)) {
                $changes[] = ['key' => $key, 'from' => $value, 'to' => null, 'kind' => 'removed'];
            }
        }
        usort($changes, function ($a, $b) {
            return strcmp($a['key'], $b['key']);
        });

        return $changes;
    }

    /**
     * Do these two mean the same thing on an access point?
     *
     * The options are strings when they come out of the database and whatever
     * the feature's json holds when they come out of a feature, so "20000" and
     * 20000 meet each other constantly. A strict comparison reports those as
     * changes and the page fills with "reassociation_deadline changes 20000 →
     * 20000", which is noise that hides the changes that are real.
     */
    private function sameValue($a, $b)
    {
        if (is_array($a) || is_array($b)) {
            return $a === $b;
        }
        if (null === $a || null === $b) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    private function shortImplementation($class)
    {
        $parts = explode('\\', (string) $class);

        return end($parts) ?: $class;
    }

    /**
     * A secret for the AP's own RADIUS server. Hex on purpose: it survives
     * every quoting layer between the database, uci and the agent's config
     * reader unchanged.
     */
    private function generateRadiusSecret()
    {
        return bin2hex(random_bytes(24));
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
        // Provisioning an access point nobody has heard from burns the whole
        // rollback window waiting for answers that cannot come, and reports
        // "no answer from the access point" — which is a different sentence
        // from "the access point is offline" for whoever reads it afterwards.
        // The tree already knows which one it is.
        $node = $this->stateTree->ap($ap);
        if (in_array($node['state'], [NodeState::AP_OFFLINE, NodeState::AP_UNKNOWN], true)) {
            $logger->warning($ap->getName().': not provisioning, the access point is '
                .$node['state_name'].($node['since'] ? ' since '.date('d.m. H:i', (int) $node['since']) : ''));

            return false;
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

        // An SSID answered by the AP's own RADIUS server needs the secret
        // generated before the device configs are built — they carry it as a
        // per device override (getDeviceConfig()).
        $onApRadius = false;
        foreach ($em->createQuery(
            'SELECT d FROM ApManBundle\Entity\Device d'
            .' JOIN d.radio r WHERE r.accesspoint = :ap'
        )->setParameter('ap', $ap)->getResult() as $device) {
            if ($device->getSsid() && $this->ppskService->usesOnApRadius($device->getSsid())) {
                $onApRadius = true;
                break;
            }
        }
        if ($onApRadius && !$ap->getRadiusSecret()) {
            $ap->setRadiusSecret($this->generateRadiusSecret());
            $em->flush();
            $logger->notice($ap->getName().': generated a RADIUS secret for the on-AP server');
        }

        // Total clean up — but only for the section types the access point
        // actually has. A delete for a type it has none of answers
        // "not found (4)", and even with cancel_on_error the staged session
        // does not come out of that intact: a section re-added under its own
        // name afterwards keeps the options the new values do not mention.
        //
        // That is not cosmetic. kalnet carried a wpa_psk_radius=2 that existed
        // nowhere in the controller any more and survived provisioning after
        // provisioning; the day its macaddr_acl finally went away with the
        // auth_server, hostapd refused the entire phy — "WPA-PSK using RADIUS
        // enabled, but no RADIUS checking (macaddr_acl=2) enabled" — and took
        // every other network on that radio down with it. Measured 2026-08-21.
        $types = ['wifi-iface', 'wifi-device', 'wifi-vlan', 'wifi-station'];
        $probe = $this->rpcService->getSession($ap);
        foreach ($types as $type) {
            if (false !== $probe) {
                $count = 0;
                try {
                    $probeOpts = new \stdClass();
                    $probeOpts->config = 'wireless';
                    $probeOpts->type = $type;
                    $found = $probe->call('uci', 'get', $probeOpts);
                    $values = $found->values ?? null;
                    $count = is_array($values) ? count($values)
                        : (is_object($values) ? count(get_object_vars($values)) : 0);
                } catch (\Exception $e) {
                    // unreachable mid-probe: fall back to asking for the delete
                    $count = 1;
                }
                if (!$count) {
                    $logger->debug($ap->getName().': no '.$type.' sections, skipping their delete');
                    continue;
                }
            }
            $opts = new \stdClass();
            $opts->config = 'wireless';
            $opts->type = $type;
            $commands['list'][] = $this->uciRequest('delete-'.$type, 'delete', $opts, $session);
        }

        // No commit here on purpose: delete and add belong to one staged
        // transaction. Committing the deletions on their own leaves the access
        // point with an empty, persisted wireless config whenever the run is
        // interrupted afterwards.

        $query = $em->createQuery(
            'SELECT r
			     FROM ApManBundle\Entity\Radio r
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
			     FROM ApManBundle\Entity\Device d
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
                // wpa_psk_file from these sections on every reload.
                // Not on an iPSK network: there the AP's RADIUS server is the
                // only key source, and without wifi-station sections
                // wifi-scripts renders neither wpa_psk_file nor
                // sae_password_file — which is what lets SAE keys go live
                // without a bss restart (the files must not be /dev/null,
                // hostapd chokes on that; they must simply not exist).
                if ($device->getSsid() && !$this->ppskService->usesOnApRadius($device->getSsid())) {
                    foreach ($this->ppskService->getStationSections($device) as $station) {
                        $commands['list'][] = $this->uciRequest('ppsk-'.$station->name, 'add', $station, $session);
                    }
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

        // The AP's own RADIUS server answers the per-station PSK queries from
        // the same wifi-station sections this config writes. Written with the
        // uci command line on purpose: it persists without a config change
        // event, and the agent picks the change up through its /etc/config/
        // apman digest watch — no restart command involved.
        if ($onApRadius) {
            foreach ([
                'radius_enabled' => '1',
                'radius_port' => '1812',
                'radius_secret' => $ap->getRadiusSecret(),
            ] as $name => $value) {
                $set = new \stdClass();
                $set->command = '/sbin/uci';
                $set->params = ['set', 'apman.main.'.$name.'='.$value];
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'apman-'.$name, 'call', null, 'file', 'exec', $set
                );
            }
            $commit = new \stdClass();
            $commit->command = '/sbin/uci';
            $commit->params = ['commit', 'apman'];
            $commands['list'][] = $this->rpcService->createRpcRequest(
                'apman-commit', 'call', null, 'file', 'exec', $commit
            );
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
        if (!$ap->getProvisioningEnabled()) {
            return ['ok' => false, 'ap' => $ap->getName(), 'error' => 'provisioning is disabled for this access point'];
        }
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
        $this->clearResults($ap, $this->commandIds($commands));
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
            if (!isset($res['error'])) {
                continue;
            }
            // The clean up deletes a whole section type at a time, and uci
            // answers "not found" when the access point has none of that type
            // — no wifi-vlan and no wifi-station is the normal case. Counting
            // that as a failure reverted every single provisioning run.
            if (self::UBUS_NOT_FOUND === ($res['error']['code'] ?? null) && str_starts_with((string) $id, 'delete-')) {
                continue;
            }
            $report['failed'][$id] = ($res['error']['message'] ?? 'failed').' ('.($res['error']['code'] ?? '?').')';
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
            $this->sendUci($client, $ap, 'revert-failed', 'revert', $session, $this->wirelessOpts());
            $client->disconnect();
            $report['ok'] = false;
            $report['error'] = 'staging failed, changes reverted';

            return $report;
        }

        if (0 === $changeCount) {
            $this->sendUci($client, $ap, 'revert-nochange', 'revert', $session, $this->wirelessOpts());
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
        $this->clearResults($ap, ['apply', 'confirm']);
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

    /**
     * @return array the ids of a bulk command list
     */
    private function commandIds(array $commands)
    {
        $ids = [];
        foreach ($commands['list'] as $cmd) {
            if (isset($cmd->id)) {
                $ids[] = $cmd->id;
            }
        }

        return $ids;
    }

    /**
     * Forget what an earlier run answered.
     *
     * The ids of a provisioning run are derived from the radio and device
     * names, so they are the same on every run, and the subscriber keeps every
     * answer under them for an hour. Without this, collectResults() finds the
     * previous run's answers the moment it starts looking and reports its diff
     * — before this run's commands have even reached the access point.
     */
    private function clearResults($ap, array $ids)
    {
        foreach ($ids as $id) {
            $this->cacheFactory->deleteCacheItem('command.result.'.$ap->getName().'.'.$id);
        }
    }

    /**
     * uci revert wants to know what to revert; without a config it answers
     * "invalid argument" and the staged transaction stays where it is.
     */
    private function wirelessOpts()
    {
        $opts = new \stdClass();
        $opts->config = 'wireless';

        return $opts;
    }

    private function sendUci($client, $ap, $id, $method, $session, ?\stdClass $opts = null)
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
        $ids = array_fill_keys($this->commandIds($commands), true);
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
		     FROM ApManBundle\Entity\Radio radio
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
        $cache = new Psr16Cache(new FilesystemAdapter());
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
	     FROM ApManBundle\Entity\AccessPoint ap
	     WHERE
	     ap.id = :id'
        );
        // setFetchMode() is gone in ORM 3, and it never took effect here:
        // "ApManBundle\AccessPoint" is not a mapped class — the entity lives in
        // ApManBundle\Entity — so the hint matched nothing.
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

    public function lifetimeMessageHandler($ap, \ApManBundle\Mqtt\Message $message, $deviceList, \ApManBundle\Mqtt\Publisher $client)
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
        // Nothing known yet is not the same as offline, even though intval()
        // turns both into 0. Keep them apart: an access point we have never
        // heard from has to be able to climb out of it, and the branches below
        // use $unknown to tell the two apart.
        $unknown = (null === $state);
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
            // stage one of the state tree: observe the same facts, decide nothing
            $this->stateTree->observeAp($ap, ['online' => 'online' == $msg['status']]);
            if ('online' == $msg['status']) {
                if ($unknown || \ApManBundle\Library\AccessPointState::STATE_OFFLINE == $state) {
                    $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_ONLINE);
                }
            } else {
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_OFFLINE);
            }
            // Handle status Message
        } elseif ('wireless' == $tp[3] && 'status' == $tp[4]) {
            $this->cacheFactory->addCacheItem('status.wireless['.$ap->getId().']', $msg);
            $this->logger->info('ApLifetimeHandler(): save wireless status (length: '.strlen($message->payload).') from '.$ap->getName());

            // A wireless status message is itself proof that the access point is
            // talking to us. Returning here was how an access point got stuck:
            // once the state had gone (or it had never been seen), every status
            // message bailed out before the state could be written back, and
            // only a fresh MQTT connect could ever lift it out again.
            if ($state < \ApManBundle\Library\AccessPointState::STATE_ONLINE) {
                $this->logger->info('ApLifetimeHandler(): '.$ap->getName().' sent wireless status while '.
                    ($unknown ? 'unknown' : \ApManBundle\Library\AccessPointState::getStateName($state)).
                    ', taking that as online');
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_ONLINE);
            }

            $pending = false;
            $failed = false;
            // Counted, not overwritten. This used to be a plain $up that every
            // radio reassigned, so the last one in the loop decided for the
            // whole access point — an access point with one radio up and one
            // down reported whatever the iteration order happened to hand over
            // last. $pending and $failed were accumulated all along; $up was
            // the odd one out.
            $radios = 0;
            $radiosUp = 0;

            // the tree wants the radios by their uci name, the same key the
            // status message is indexed by
            $radiosByName = [];
            foreach ($ap->getRadios() as $r) {
                $radiosByName[$r->getName()] = $r;
            }

            foreach ($msg as $name => $rstate) {
                //var_dump($rstate);
                if ('timestamp' == $name or !is_array($rstate)) {
                    continue;
                }

                // State tree, stage one. Deliberately before the "skip disabled
                // radios" jump below: a radio that is switched off is a fact
                // about it, not a reason to know nothing.
                if (isset($radiosByName[$name])) {
                    $radio = $radiosByName[$name];
                    $sections = [];
                    if (isset($rstate['interfaces']) && is_array($rstate['interfaces'])) {
                        foreach ($rstate['interfaces'] as $iface) {
                            if (isset($iface['section'])) {
                                $sections[$iface['section']] = true;
                            }
                        }
                    }
                    $this->stateTree->observeRadio($radio, [
                        'disabled' => (bool) ($rstate['config']['disabled'] ?? false),
                        'up' => (bool) ($rstate['up'] ?? false),
                        'pending' => (bool) ($rstate['pending'] ?? false),
                        'failed' => (bool) ($rstate['retry_setup_failed'] ?? false),
                    ]);
                    foreach ($radio->getDevices() as $rdev) {
                        $this->stateTree->observeBss($rdev, [
                            'present' => isset($sections[$rdev->getName()]),
                        ]);
                    }
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
                ++$radios;
                if ($rstate['up']) {
                    ++$radiosUp;
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
            // One radio up is enough to keep going. Demanding all of them would
            // park an access point with a single dead radio in PENDING, and
            // PENDING never reaches ACTIVE — so the radios that *are* working
            // would lose their beacons and neighbour reports too. The partial
            // case is worth knowing about, not worth stopping for.
            $up = $radiosUp > 0;
            if ($radios > 0 && $radiosUp < $radios) {
                $this->logger->warning('ApLifetimeHandler(): '.$ap->getName().' has '.
                    $radiosUp.' of '.$radios.' enabled radios up');
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
                // At first 5g, then 2g
                $dev2G = [];
                $dev5G = [];
                foreach ($deviceList as $device) {
                    // same reason as in assignAllNeighbors(): a bss the access
                    // point does not run answers every command with "not found"
                    if (!$this->isLive($device)) {
                        continue;
                    }
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
                // bss_mgmt_enable then update_beacon, in that order, per bss:
                // the beacon is regenerated from the flags the first call set,
                // so neither of these may be sent asynchronously.
                $batch = function (array $devices) use ($opts) {
                    $commands = ['list' => [], 'options' => ['cancel_on_error' => false]];
                    foreach ($devices as $device) {
                        $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'bss_mgmt_enable', $opts);
                        $commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'update_beacon', []);
                    }

                    return $commands;
                };
                $first = $batch($dev5G);
                if (count($first['list'])) {
                    $client->publish($topic, json_encode($first));
                    // stage one: this is the only place management is switched
                    // on today, so it is the only place the tree can learn it
                    foreach ($dev5G as $device) {
                        $this->stateTree->observeBss($device, ['managed' => true]);
                    }
                }
                $second = $batch($dev2G);
                if (count($second['list'])) {
                    // The gap between the two bands used to be a "file exec
                    // sleep 5" at the end of the first batch. It bought the
                    // delay at the access point's expense: the agent's ubus
                    // call turns its own event loop until the answer is there,
                    // so for those five seconds it served no mqtt, no hostapd
                    // control channel and no radius — and with macaddr_acl=2
                    // every station that tried to associate in the window was
                    // turned away (seen on ap-av-attic, 03:58:12 to 03:58:17).
                    // Waiting is the controller's job; the access point should
                    // be doing something else meanwhile.
                    if (count($first['list'])) {
                        $client->publishDelayed($topic, json_encode($second), self::MGMT_STAGGER_SECONDS);
                    } else {
                        $client->publish($topic, json_encode($second));
                    }
                    foreach ($dev2G as $device) {
                        $this->stateTree->observeBss($device, ['managed' => true]);
                    }
                }

                // Assign Neighbors, enable reports
                $this->logger->info('ApLifetimeHandler(): state '.\ApManBundle\Library\AccessPointState::getStateName($state).' on ap '.$ap->getName().' detected, start AssignAllNeighbors.');
                $this->assignAllNeighbors();
                $state = $this->changeLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_ACTIVE);
            }
        }

        // Cache update
        $state = $this->changeLifetimeState($ap, $state);

        // Stage one of the state tree: say what it would have concluded, so the
        // two can be compared before anything is moved over to it.
        $this->stateTree->compareWithFlat($ap,
            \ApManBundle\Library\AccessPointState::getStateName($state));
        $this->logger->debug("ApLifetimeHandler(): state '".\ApManBundle\Library\AccessPointState::getStateName($state)."' of ap ".$ap->getName());

        return true;
    }

    public function lifetimeHouseKeeping(array $aps, array $devicesByAp)
    {
        $em = $this->doctrine->getManager();
        // The caller passes what the subscriber has heard from, which is built
        // lazily as messages arrive. An access point that says nothing at all
        // never enters that list — so the one case this tick exists for, a
        // device that is simply gone, was the one case it could not see.
        // ap-hv-klwz was missing from it for a whole day. Take the productive
        // ones from the database and use the caller's list only to know which
        // of them have been talking.
        // All of them, not only the productive ones. An access point that is
        // not marked productive is still a device standing somewhere, and
        // being unreachable is worth a line about it either way — ap-hv-klwz
        // fell through exactly this gap and was silently gone for a day. What
        // the flag decides is how loud, not whether.
        $known = is_array($aps) ? $aps : [];
        $aps = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findAll();
        foreach ($known as $ap) {
            if (!in_array($ap, $aps, true)) {
                $aps[] = $ap;
            }
        }
        if (!$aps) {
            $this->logger->warning('No productive Accesspoints found.');

            return false;
        }
        $notHealthy = [];
        $total = 0;
        foreach ($aps as $ap) {
            // Re-compose the tree even when nothing arrived. The composed values
            // are written when a message comes in, and a page or a Sonata column
            // reads what was written — so an access point that falls silent
            // would keep showing whatever it was last, for as long as the entry
            // lives. Composing it here lets it decay: the nodes read as unknown
            // once their facts are stale, and that is what gets stored.
            $tree = $this->stateTree->refresh($ap);

            $this->escalate($ap, $tree);
            if (!$ap->getIsProductive()) {
                // it is escalated, it is just not part of the health count —
                // a lab device being off is not the fleet being unwell
                continue;
            }
            ++$total;
            if (!in_array($tree['state'], [NodeState::AP_ACTIVE, NodeState::AP_READY,
                NodeState::AP_CAC], true)) {
                $notHealthy[] = $ap->getName().' '.$tree['state_name'];
            }
        }
        if (!$notHealthy) {
            return;
        }
        $this->logger->error('Failure - '.count($notHealthy).' of '.$total.' access points not healthy: '
            .implode(', ', $notHealthy).'|online='.($total - count($notHealthy)).' offline='.count($notHealthy));

        return;
    }

    /**
     * Say it once, when it happens, and name the level it happened at.
     *
     * This used to count and log a line every tick, at the same level whether
     * one access point was in a channel availability check or the whole site
     * had gone. ap-hv-klwz was offline for a day and nothing said so louder on
     * the day it went than on the six ticks before it came back.
     *
     * Once means once per episode: the marker carries the state and the moment
     * it started, so an access point that goes, comes back and goes again is
     * three lines, and one that simply stays away is one.
     */
    private function escalate(\ApManBundle\Entity\AccessPoint $ap, array $tree)
    {
        $fire = function ($type, $id, $name, $state, $stateName, $since, $level, $what) {
            $key = 'state.escalated.'.$type.'.'.$id;
            $mark = $state.'@'.(int) $since;
            if ($this->cacheFactory->getCacheItemValue($key) === $mark) {
                return;
            }
            $this->cacheFactory->addCacheItem($key, $mark, 30 * 86400);
            $this->logger->log($level, 'state: '.$what.' — '.$name.' is '.$stateName
                .($since ? ' since '.date('d.m. H:i', (int) $since) : ''));
        };

        if (in_array($tree['state'], [NodeState::AP_OFFLINE, NodeState::AP_UNKNOWN], true)) {
            $fire(NodeState::TYPE_AP, $ap->getId(), $ap->getName(), $tree['state'],
                $tree['state_name'], $tree['since'],
                $ap->getIsProductive() ? \Psr\Log\LogLevel::CRITICAL : \Psr\Log\LogLevel::WARNING,
                'access point unreachable'.($ap->getIsProductive() ? '' : ' (not productive)'));

            // Its radios are unknown because it is, not on their own account.
            // Escalating them too would turn one outage into five lines.
            return;
        }
        foreach ($tree['children'] as $radio) {
            if (in_array($radio['state'], [NodeState::RADIO_FAILED, NodeState::RADIO_DEGRADED], true)) {
                $fire(NodeState::TYPE_RADIO, $radio['id'], $ap->getName().'/'.$radio['name'],
                    $radio['state'], $radio['state_name'], $radio['since'],
                    NodeState::RADIO_FAILED === $radio['state']
                        ? \Psr\Log\LogLevel::CRITICAL : \Psr\Log\LogLevel::WARNING,
                    'radio '.(NodeState::RADIO_FAILED === $radio['state'] ? 'failed' : 'degraded'));
            }
        }
    }

    /**
     * How long a state is remembered. Deliberately far longer than anything the
     * access points do: the default 30 seconds meant the state expired between
     * two status messages, and since a missing entry reads back as 0 —
     * STATE_OFFLINE — a perfectly healthy access point was reported offline for
     * no reason other than a gap in the traffic. Offline is something we are
     * told (the agent's MQTT last will) or something we conclude from the age
     * of the last message, never something a cache eviction decides.
     */
    private const STATE_TTL = 7 * 86400;

    private function changeLifetimeState($ap, int $state)
    {
        $stateKey = 'status.state['.$ap->getId().']';
        $stateOld = $this->cacheFactory->getCacheItemValue($stateKey);
        if ($state !== $stateOld) {
            $this->logger->notice("changeLifetimeState(): changing state from '".\ApManBundle\Library\AccessPointState::getStateName($stateOld).
            "' to '".\ApManBundle\Library\AccessPointState::getStateName($state)."'  of ap ".$ap->getName());
            // when it changed, so "how long has it been failing" is answerable
            $this->cacheFactory->addCacheItem('status.state.since['.$ap->getId().']', time(), self::STATE_TTL);
        }
        $this->cacheFactory->addCacheItem($stateKey, $state, self::STATE_TTL);

        return $state;
    }

    /**
     * The connection to publish through when this service runs inside the
     * subscriber.
     *
     * Without it every call here opened a second MQTT connection of its own —
     * one that nobody's event loop services, so it goes quiet and the next
     * publish throws its exception into the middle of message handling. Inside
     * the daemon there is exactly one connection, and it belongs to the loop.
     */
    public function setPublisher(?\ApManBundle\Mqtt\Publisher $publisher = null)
    {
        $this->publisher = $publisher;
    }

    /**
     * Whether the access point is really running this bss right now.
     *
     * This used to hand roll its own 300 second window over the device status
     * cache, next to two other places that asked the same question with two
     * other windows. It is a bss node now: READY means hostapd reports the
     * interface enabled, ACTIVE means its management is on as well, and
     * anything else — absent, disabled, starting, never heard from — is not
     * something to send a command to.
     */
    public function isLive(\ApManBundle\Entity\Device $device, $maxAge = 300)
    {
        $bss = $this->stateTree->bss($device);

        return $bss['fresh'] && in_array($bss['state'], [
            \ApManBundle\Library\NodeState::BSS_READY,
            \ApManBundle\Library\NodeState::BSS_ACTIVE,
        ], true);
    }

    public function assignAllNeighbors()
    {
        $em = $this->doctrine->getManager();
        $client = $this->publisher ?: $this->mqttFactory->getClient();

        $cmds = [];
        $ssids = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findall();
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
                // A bss the access point is not running — switched off there,
                // or a row left over from a rename — has no hostapd object, and
                // every command sent to it comes back as "not found". The same
                // signal the lifetime handler counts interfaces with says
                // whether it is on the air.
                if (!$this->isLive($device)) {
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

                $apname = $ap->getName();
                if (!isset($cmds[$apname])) {
                    $cmds[$apname] = [];
                }
                // One neighbour list per bss, and no command in the batch is
                // built on another: an access point with a dozen bsses has no
                // reason to stop answering for the length of all of them.
                $cmds[$apname][] = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$device->getIfname(), 'rrm_nr_set', $opts);
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
			 FROM ApManBundle\Entity\Client c
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

        // also reachable from the subscriber, so the same rule applies: publish
        // through the loop's connection when there is one
        $mclient = $this->publisher ?: $this->mqttFactory->getClient();
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
            // Nothing waits on the answer but this line of code, and a station
            // that has already wandered off keeps hostapd busy until it gives
            // up on it.
            $cmd = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$device->getIfname(), 'del_client', $opts);
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
            // A transition request runs until the station answers it or the
            // disassociation timer expires — seconds, for a station that has
            // stopped listening. The access point has better things to do.
            $cmd = $this->rpcService->createRpcRequest(1, $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$device->getIfname(), 'bss_transition_request', $opts);
            $this->logger->warning('steerClient('.$mac.'): Sending bss_transition_request message to topic '.$topic.': '.json_encode($cmd), ['wnm_capable' => $wnm_capable]);
            $res = $mclient->publish($topic, json_encode($cmd));
            $this->steeringState['state'][$mac] = ['client' => $client, 'last_sent' => time(), 'timeout' => time() + $opts->disassociation_timer / 10, 'try' => $try];

            return true;
        }
    }
}
