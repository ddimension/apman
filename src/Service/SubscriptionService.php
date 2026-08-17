<?php

namespace ApManBundle\Service;

use ApManBundle\Factory\CacheFactory;
use ApManBundle\Factory\MqttFactory;

class SubscriptionService
{
    private $logger;
    private $doctrine;
    private $rpcService;
    private $apService;
    private $mqttFactory;
    private $cacheFactory;
    private $ch;
    private $device;
    private $cache;
    private $cacheLocal = ['ap-by-name' => [], 'dev-by-ap-ifname' => []];
    private $cacheRefreshed = 0;
    private $ppskService;
    /** ssid ids whose keys changed and have to go out again */
    private $ppskPending = [];
    private const CACHE_REFRESH_INTERVAL = 60;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        AccessPointService $apService,
        MqttFactory $mqttFactory,
        CacheFactory $cacheFactory,
        PpskService $ppskService
    ) {
        $this->ppskService = $ppskService;
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->apService = $apService;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
    }

    public function runMqttLoop()
    {
        $this->logger->info('Starting MqttLoop');
        $this->cache = $this->cacheFactory->getCache();
        $loop = true;
        while ($loop) {
            $srv = $this;
            unset($this->client);
            $this->client = $this->mqttFactory->getClientMosquitto('apmanserver', false);
            $client = $this->client;
            /*
            $this->client->onConnect(function() use ($srv,$client) {
                $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'system', 'info', null);
                $client->publish('apman/command', json_encode($cmd), 2);
            });
             */
            $this->client->onMessage(function ($msg) use ($srv) {
                try {
                    if (!$srv->handleMosquittoMessage($msg)) {
                        $this->logger->debug('Failed to handle message. '.json_encode($msg));
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to handle message. '.$e.' '.$e->getTraceAsString());
                }
            });
            if (!empty($_SERVER['MQTT_USERNAME']) and !empty($_SERVER['MQTT_PASSWORD'])) {
                $success = $this->client->setCredentials($_SERVER['MQTT_USERNAME'], $_SERVER['MQTT_PASSWORD']);
            }
            if (empty($_SERVER['MQTT_PORT'])) {
                $_SERVER['MQTT_PORT'] = 1883;
            }
            $success = $this->client->connect($_SERVER['MQTT_HOST'], $_SERVER['MQTT_PORT']);
            if ($success) {
                $this->logger->info('Failed to connected.');
                continue;
            }
            $this->client->onDisconnect(function () {
                $this->logger->warn("Disconnected, reconnect\n");
                $success = $this->client->connect('192.168.203.38', 1883);
                if ($success) {
                    $this->logger->info('Failed to connect.');
                }
            });
            $this->logger->info('Connected');
            // Telemetry is replaced every interval, so QoS 0 is enough and
            // saves an acknowledgement per message in both directions. Command
            // results and retained properties are worth QoS 1. Overlapping
            // filters are fine: the broker delivers one copy at the highest
            // matching QoS (verified against this mosquitto).
            $this->client->subscribe('apman/#', 0);
            $this->client->subscribe('apman/+/+/command_result/#', 1);
            $this->client->subscribe('apman/+/+/properties/#', 1);
            $this->client->subscribe('apman/+/+/online', 1);
            $this->client->subscribe('apman/+/+/booted', 1);
            $this->client->subscribe('radius/#', 0);
            $loopTime = 10;
            try {
                $lStart = time();
                while (true) {
                    $this->client->loop($loopTime * 1000);
                    if (($lStart + $loopTime) <= time()) {
                        $this->doHouseKeeping();
                        $lStart = time();
                    }
                }
            } catch (\Exception $e) {
                $this->logger->info('Exception occured: '.$e->getMessage());
                //if (strpos($e->getMessage(), 'PDOException: SQLSTATE[HY000]') !== false) {
                // reconnect
                $this->logger->warn('Database Connection Exception occured.');
                $em = $this->doctrine->getManager();
                if (false === $em->getConnection()->ping()) {
                    $this->logger->warn('Database ping failed, reconnect.');
                    $em->getConnection()->close();
                    $em->getConnection()->connect();
                }
                //}
                sleep(1);
                continue;
            }
            $this->logger->info('Disconnected.');
        }
    }

    private function handleMosquittoMessage($message)
    {
        $em = $this->doctrine->getManager();
        if (!$em->isOpen()) {
            $em = $em->create(
                $em->getConnection(),
                $em->getConfiguration()
            );
        }
        /*
                if (strpos($message->topic, 'ap-outdoor.kalnet.hooya.de') !== false) {
        echo "XX ";
            print_r($message);
        }
        */
        $tp = explode('/', $message->topic);
        $length = count($tp);
        $device = '';
        if ('radius' == $tp[0]) {
            $this->handleRadiusMessage($message);
            return true;
        } elseif ('ap' == $tp[1]) {
            $hostname = $tp[2];
            if ('device' == $tp[3]) {
                if ('hostapd' == $tp[4]) {
                    $device = $tp[5];
                } else {
                    $device = $tp[4];
                }
            } elseif ('notifications' == $tp[3]) {
                if ('hostapd' == $tp[4]) {
                    $device = $tp[5];
                } else {
                    $device = $tp[4];
                }
            } elseif ('properties' == $tp[3]) {
                if ('hostapd' == $tp[4]) {
                    $device = $tp[5];
                }
            } elseif ('survey' == $tp[3]) {
                $device = $tp[4];
            } elseif ('command_result' == $tp[3]) {
                return $this->handleCommandResult($hostname, $tp, $message);
            } elseif ('booted' == $tp[3]) {
                //$this->assignAllNeighbors();
                return true;
            } elseif ('wireless' == $tp[3]) {
                // go on
            } elseif ('online' == $tp[3]) {
                // go on
            } else {
                return false;
            }
            //	            $this->logger->info('handleMosquittoMessage(): AP Message.');
        } else {
            return false;
        }

        if (false !== strpos($device, '.')) {
            $device = substr($device, strpos($device, '.') + 1);
        }
        //$this->logger->info('handleMosquittoMessage(): AP Message.', [$hostname, $device, $message->topic]);

        /*
        // Cache Expiration
        if (isset($this->cacheCreated)) {
            if ($this->cacheCreated+10 < time()) {
                    $this->logger->notice('handleMosquittoMessage(): Expire local database object cache.');
                unset($this->cacheLocal['ap-by-name']);
                unset($this->cacheLocal['dev-by-ap-ifname']);
            }
        }
         */
        // Setup cache. Rebuilding it is a full join over all devices, so for a
        // host that is not in the database at all this used to run on every
        // single message. Rebuild at most once per interval, which also picks
        // up newly added access points without a restart.
        if ((!isset($this->cacheLocal['ap-by-name'][$hostname]) or !isset($this->cacheLocal['dev-by-ap-ifname'][$hostname]))
            and (time() - $this->cacheRefreshed) >= self::CACHE_REFRESH_INTERVAL) {
            $this->cacheRefreshed = time();
            //$this->logger->notice("SubscribtionService: Flushing pid local cache.");
            $query = $em->createQuery("SELECT d,r,a FROM ApManBundle\Entity\Device d
				LEFT JOIN d.radio r
				LEFT JOIN r.accesspoint a
		    ");
            foreach ($query->getResult() as $row) {
                $apname = $row->getRadio()->getAccessPoint()->getName();
                $this->cacheLocal['ap-by-name'][$apname] = $row->getRadio()->getAccessPoint();
                $devname = $row->getIfname();
                if (!isset($this->cacheLocal['dev-by-ap-ifname'][$apname])) {
                    $this->cacheLocal['dev-by-ap-ifname'][$apname] = [];
                }
                $this->cacheLocal['dev-by-ap-ifname'][$apname][$devname] = $row;
            }
        }

        if (!isset($this->cacheLocal['ap-by-name'][$hostname])) {
            $this->logger->info('handleMoqsquittoMessage(): ap not found '.$hostname);

            return true;
        }
        $ap = $this->cacheLocal['ap-by-name'][$hostname];
        if (is_null($ap)) {
            $this->logger->info('handleMoqsquittoMessage(): ap not found '.$hostname);

            return true;
        }
        if ('properties' == $tp[3] && 'agent' == $tp[4]) {
            $agent = json_decode($message->payload, true);
            if (!is_array($agent)) {
                return false;
            }
            $agent['received'] = time();
            $this->cacheFactory->addCacheItem('status.ap.'.$ap->getId().'.agent', $agent, 180 * 86400);
            $this->logger->info('handleMoqsquittoMessage(): agent '.($agent['version'] ?? '?').' on '.$hostname);

            return true;
        } elseif ('properties' == $tp[3] && 'system' == $tp[4]) {
            $this->logger->info('handleMoqsquittoMessage(): saved system.'.$tp[5].' for '.$hostname);
            $data = [];
            $data[$tp[5]] = json_decode($message->payload, true);
            $this->cacheFactory->addCacheItem('status.ap.'.$ap->getId(), $data);
            $this->cacheFactory->addCacheItem('status.ap.'.$ap->getId().'.'.$tp[5], $data[$tp[5]], 180 * 86400);

            return true;
        } elseif ('properties' == $tp[3] && 'session' == $tp[4] && 'create' == $tp[5]) {
            // rpcd stages uci changes per session and refuses "uci apply"
            // without one, so this is what makes a rollback safe apply possible
            $session = json_decode($message->payload, true);
            if (is_array($session) && !empty($session['ubus_rpc_session'])) {
                $this->cacheFactory->addCacheItem(
                    'status.ap.'.$ap->getId().'.session',
                    ['id' => $session['ubus_rpc_session'], 'received' => time()],
                    180 * 86400
                );
                $this->logger->info('handleMoqsquittoMessage(): stored ubus session for '.$hostname);
            }

            return true;
        } elseif ('notifications' == $tp[3] && 'hostapd' == $tp[4] && 'bss.add' == $tp[5]) {
            $bssmsg = json_decode($message->payload, true);
            if (!is_array($bssmsg)) {
                $this->logger->error('handleMoqsquittoMessage(): bss add notification is not an array. '.$hostname.' '.print_r($bssmsg, true));

                return false;
            }
            if (!isset($bssmsg['name'])) {
                $this->logger->error('handleMoqsquittoMessage(): missing name property in bss add notification from '.$hostname);

                return false;
            }
            $device = $bssmsg['name'];
        //$this->logger->info('handleMoqsquittoMessage(): prehandled accesspoint bss add notification '.$tp[5].' for device '.$device.' from '.$hostname,(array)$message);
        } elseif ('wireless' == $tp[3] && 'status' == $tp[4]) {
            return $this->apService->lifetimeMessageHandler(
                $ap,
                $message,
                $this->cacheLocal['dev-by-ap-ifname'][$hostname],
                $this->client
            );
        } elseif ('online' == $tp[3]) {
            return $this->apService->lifetimeMessageHandler(
                $ap,
                $message,
                $this->cacheLocal['dev-by-ap-ifname'][$hostname],
                $this->client
            );
            /*
            } elseif ($tp[3] == 'properties') {
            $this->logger->info('handleMoqsquittoMessage(): implement properties handler for message from '.$hostname,(array)$message);
            return false;
            */
        }
        //echo "XX: ".$message->topic.' '.$message->payload."\n";
        //$this->logger->info('handleMoqsquittoMessage(): debug '.$hostname, [$message->topic]);

        // Handle device specific messages
        if (!isset($this->cacheLocal['dev-by-ap-ifname'][$hostname])) {
            $this->logger->info('handleMoqsquittoMessage(): ap not found '.$hostname);

            return true;
        }
        if (!isset($this->cacheLocal['dev-by-ap-ifname'][$hostname][$device])) {
            $this->logger->info('handleMoqsquittoMessage(): device '.$device.' not found '.$hostname);

            return true;
        }
        $device = $this->cacheLocal['dev-by-ap-ifname'][$hostname][$device];
        if (is_null($device)) {
            $this->logger->error('handleMosquittoMessage(): device not found ', ['apname' => $hostname, 'topic' => $message->topic]);

            return false;
        }
        if ('device' == $tp[3] && 'status' == $tp[6]) {
            $this->statusHandler($device, $message);

            return true;
        } elseif ('survey' == $tp[3]) {
            $survey = json_decode($message->payload, true);
            if (is_array($survey)) {
                $this->cacheFactory->addCacheItem('status.device.'.$device->getId().'.survey', $survey, 7 * 86400);
            }

            return true;
        } elseif ('properties' == $tp[3] and 'bss_info' == end($tp)) {
            $info = json_decode($message->payload, true);
            if (is_array($info)) {
                $this->cacheFactory->addCacheItem('status.device.'.$device->getId().'.bss_info', $info, 180 * 86400);
            }

            return true;
        } elseif ('properties' == $tp[3] and 'rrm_nr_get_own' == end($tp)) {
            // the neighbour report is republished on every resubscribe but
            // hardly ever changes, so do not write the database for nothing
            $rrm = json_decode($message->payload, true);
            if ($device->getRrm() != $rrm) {
                $device->setRrm($rrm);
                $em->persist($device);
                $em->flush();
            }

            return true;
        } elseif ('notifications' == $tp[3] and 'hostapd' == $tp[4] and 'bss.add' == $tp[5]) {
            $this->logger->info('handleMoqsquittoMessage(): accesspoint bss.add notification '.$tp[5].' for '.$hostname, (array) $message);
            /* This is now done via the ApLifetimeHandler
            $opts = new \stdClass();
            $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'update_beacon', $opts);
            $topic = 'apman/ap/'.$ap->getName().'/command';
            $this->client->publish($topic, json_encode($cmd));
            $this->logger->info('handleMoqsquittoMessage(): sent update_beacon command because of notification '.$tp[5].' for '.$hostname,(array)$message);
             */
            return true;
        } elseif ('notifications' == $tp[3]) {
            $event = $tp[5];
            if ($tp[6]) {
                $event = $tp[6];
            }
            // the hostapd control channel stream sits one level deeper:
            // notifications/hostapd/<ifname>/ctrl/<EVENT>
            if ('ctrl' === ($tp[6] ?? null) && isset($tp[7])) {
                return $this->handleCtrlEvent($ap, $device, $tp[7], json_decode($message->payload, true));
            }
            $data = json_decode($message->payload);
            if ('probe' == $event) {
                $obj = new \stdClass();
                $obj->ts = new \DateTime('now');
                $obj->address = $data->address;
                $obj->device = $device->getId();
                $obj->event = $data;
                if (property_exists($data, 'signal')) {
                    $obj->signalstr = $data->signal;
                }

                $this->cacheFactory->addCacheItem('status.device['.$device->getId().'].probe.'.$data->address, $obj, 86400);
                if (property_exists($data, 'raw_elements')) {
                    $key = 'status.client['.str_replace(':', '', $data->address).'].raw_elements';
                    $this->cacheFactory->addCacheItem($key, $data->raw_elements, 86400);
                }

                $this->logger->info(
                    "handleMoqsquittoMessage(): saved $event as ClindHeatMap.",
                    [
                    'data' => json_encode($data),
                    'ap' => $ap->getName(),
                    'ifName' => $device->getIfname(),
                    ]
                );

                return true;
            } else {
                if ('bss-transition-response' == $event && is_object($data) && property_exists($data, 'address')) {
                    // the numeric status code is lost in transport (hostapd
                    // renders it through blobmsg u8, so it arrives as a
                    // boolean); the candidate list carries the real reason
                    $this->apService->getSteering()->recordResponse(
                        $data->address, json_decode(json_encode($data), true)
                    );
                }
                $devent = new \ApManBundle\Entity\Event();
                $devent->setTs(new \DateTime('now'));
                $devent->setType($event);
                $devent->setAddress($data->address);
                $devent->setEvent(json_encode($data, true));
                $devent->setDevice($device);
                if (property_exists($data, 'signal')) {
                    $devent->setSignalstr($data->signal);
                }
                $em->persist($devent);
                $this->logger->info(
                    "handleMoqsquittoMessage(): saved $event as Event.",
                    [
                    'data' => json_encode($data),
                    'ap' => $ap->getName(),
                    'ifName' => $device->getIfname(),
                    ]
                );
                $em->flush();

                return true;
            }
        }

        return false;
    }

    /**
     * Command results carry either a result plus ubus_status 0 or an error
     * object (apman command channel v2). Older agents send neither, then only
     * the presence of a result key can be evaluated.
     */
    private function handleCommandResult($hostname, array $tp, $message)
    {
        $data = json_decode($message->payload, true);
        if (!is_array($data)) {
            return false;
        }
        $bulk = isset($tp[4]) && 'bulk' == $tp[4];
        $responses = $bulk ? $data : [$data];
        $failed = 0;
        foreach ($responses as $response) {
            if (!is_array($response)) {
                continue;
            }
            if (isset($response['error'])) {
                ++$failed;
                $this->logger->error('commandResult(): '.$hostname.' command failed.', [
                    'id' => $response['id'] ?? null,
                    'error' => $response['error'],
                ]);
            }
            if (isset($response['id'])) {
                $this->cacheFactory->addCacheItem(
                    'command.result.'.$hostname.'.'.$response['id'],
                    $response,
                    3600
                );
                // a web request waiting for this answer can block on the list
                // instead of polling the cache every 250 ms
                $this->cacheFactory->pushResult($hostname, $response['id'], $response);
            }
        }
        if (!$failed) {
            $this->logger->debug('commandResult(): '.$hostname.' '.count($responses).' ok.');
        }

        return true;
    }

    /** 802.11v BSS transition status codes, as the client reports them */
    private const BTM_STATUS = [
        0 => 'accepted',
        1 => 'rejected, unspecified',
        2 => 'rejected, too few beacon or probe responses received',
        3 => 'rejected, no candidate has capacity',
        4 => 'rejected, bss termination undesired',
        5 => 'rejected, bss termination delay requested',
        6 => 'rejected, the client offered its own candidate list',
        7 => 'rejected, no suitable candidate',
        8 => 'rejected, leaving the ess',
    ];

    /**
     * One event from the hostapd control channel.
     *
     * These are the things ubus does not deliver: why a station was refused,
     * how the EAP server answered, the numeric status of a steering answer,
     * and the identity a station authenticated with at the moment it connects.
     * They are kept per device and per station so the interface can show a
     * short history without a database table that would grow forever.
     */
    private function handleCtrlEvent($ap, $device, $name, $data)
    {
        if (!is_array($data)) {
            return false;
        }
        $address = $data['address'] ?? null;
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $entry = [
            'event' => $name,
            'ts' => time(),
            'ap' => $ap ? $ap->getName() : null,
            'ifname' => $data['ifname'] ?? ($device ? $device->getIfname() : null),
            'address' => $address,
            'fields' => $fields,
            'raw' => $data['raw'] ?? null,
        ];

        if ($device) {
            $this->pushCtrlEvent('status.device['.$device->getId().'].ctrlevents', $entry, 40);
        }
        if ($address) {
            $this->pushCtrlEvent('status.client['.str_replace(':', '', $address).'].ctrlevents', $entry, 20);
        }

        switch ($name) {
            case 'AP-STA-CONNECTED':
                // the identity travels with the connect event, so a key that
                // was handed out is recognised the moment it is used instead
                // of on the next poll
                if (!empty($fields['keyid']) && $address) {
                    $this->stampIpsk($fields['keyid'], $address);
                }
                break;

            case 'BSS-TM-RESP':
                // the real status code, which the ubus path renders as a
                // boolean and loses
                if ($address && isset($fields['status_code'])) {
                    $code = (int) $fields['status_code'];
                    $this->apService->getSteering()->recordResponse($address, [
                        'status-code' => $code,
                        'target-bssid' => $fields['target_bssid'] ?? null,
                        'candidate-list' => '',
                        'status-text' => self::BTM_STATUS[$code] ?? ('status '.$code),
                    ]);
                    $this->logger->notice('ctrlEvent(): '.$address.' answered the transition request: '.
                        (self::BTM_STATUS[$code] ?? ('status '.$code)));
                }
                break;

            case 'AP-STA-POSSIBLE-PSK-MISMATCH':
                $this->logger->warning('ctrlEvent(): '.$address.' tried to join '.
                    $entry['ifname'].' with a key that does not match');
                break;

            case 'AP-REJECTED-MAX-STA':
            case 'AP-REJECTED-BLOCKED-STA':
                $this->logger->warning('ctrlEvent(): '.$entry['ifname'].' refused '.$address.' ('.$name.')');
                break;

            case 'CTRL-EVENT-EAP-FAILURE2':
            case 'CTRL-EVENT-EAP-TIMEOUT-FAILURE2':
                $this->logger->warning('ctrlEvent(): eap failed for '.$address.' on '.$entry['ifname']);
                break;

            case 'DFS-RADAR-DETECTED':
            case 'DFS-NEW-CHANNEL':
            case 'ACS-COMPLETED':
            case 'ACS-FAILED':
            case 'AP-DISABLED':
            case 'AP-ENABLED':
                $this->logger->notice('ctrlEvent(): '.$entry['ifname'].' '.$name.' '.json_encode($fields));
                break;
        }

        return true;
    }

    /** keep a short, bounded history under one cache key */
    private function pushCtrlEvent($key, array $entry, $keep)
    {
        $list = $this->cacheFactory->getCacheItemValue($key);
        if (!is_array($list)) {
            $list = [];
        }
        array_unshift($list, $entry);
        if (count($list) > $keep) {
            $list = array_slice($list, 0, $keep);
        }
        $this->cacheFactory->addCacheItem($key, $list, 7 * 86400);
    }

    /** mark an issued key as used, from whichever source reported it */
    private function stampIpsk($keyid, $mac)
    {
        $em = $this->doctrine->getManager();
        $ppsk = $em->getRepository('ApManBundle:Ppsk')->findOneBy(['keyid' => $keyid]);
        if (!$ppsk) {
            $this->logger->warning('stampIpsk(): unknown keyid '.$keyid.' used by '.$mac);

            return;
        }
        $now = new \DateTime();
        $mac = strtolower($mac);
        if (!$ppsk->getFirstSeen()) {
            $ppsk->setFirstSeen($now);
            $this->logger->notice('stampIpsk(): ipsk '.$keyid.' used for the first time by '.$mac);
        }
        $ppsk->setLastSeen($now);
        $ppsk->setLastMac($mac);

        // Bind the key to the device that just claimed it. From here on the
        // key only works for this address: a copy of the code on a second
        // phone is refused, and the refusal is visible as
        // AP-STA-POSSIBLE-PSK-MISMATCH. The station itself keeps its
        // connection — RELOAD_WPA_PSK only drops stations whose key stopped
        // matching, and for this one it still does.
        if ($ppsk->isPinPending()) {
            $ppsk->setMac($mac);
            $this->logger->notice('stampIpsk(): ipsk '.$keyid.' pinned to '.$mac.
                ', redistributing');
            if ($ppsk->getSsid()) {
                $this->ppskPending[$ppsk->getSsid()->getId()] = true;
            }
        }
        $em->flush();
    }

    /**
     * Send out key sets that changed while handling a message.
     *
     * This does not run in the message handler itself: a distribution talks to
     * every access point of the SSID and waits for the answers, which would
     * stall the ingestion for ten seconds. The housekeeping tick is the right
     * place — it already runs every ten seconds and nothing else depends on it
     * finishing quickly.
     */
    private function flushPpskPending()
    {
        if (!$this->ppskPending) {
            return;
        }
        $ids = array_keys($this->ppskPending);
        $this->ppskPending = [];
        foreach ($ids as $id) {
            $ssid = $this->doctrine->getRepository('ApManBundle:SSID')->find($id);
            if (!$ssid) {
                continue;
            }
            // Started as its own process on purpose: distributing waits for
            // the access points to answer, and those answers arrive as MQTT
            // messages that only this loop can process. Doing it inline would
            // block waiting for messages it is itself supposed to receive, and
            // every read would time out.
            $console = dirname(__DIR__, 2).'/bin/console';
            $cmd = sprintf('%s %s --env=prod apman:ppsk-distribute %d --force > /dev/null 2>&1 &',
                escapeshellarg(PHP_BINARY), escapeshellarg($console), $ssid->getId());
            $this->logger->notice('flushPpskPending(): redistributing '.$ssid->getName().
                ' after a key was pinned');
            exec($cmd);
        }
    }

    /**
     * An iPSK has a wildcard MAC, so the key itself is the identity — and the
     * only thing that tells us which client used which key is the keyid
     * hostapd reports for the station (agent >= 56-5, from the control
     * channel).
     *
     * This is what turns "a key was handed out" into "the key was actually
     * used", which is what the interface shows as success.
     */
    private function recordIpskUse(array $data)
    {
        if (empty($data['sta_ctrl']) || !is_array($data['sta_ctrl'])) {
            return;
        }
        foreach ($data['sta_ctrl'] as $mac => $values) {
            $keyid = is_array($values) ? ($values['keyid'] ?? null) : null;
            if (!$keyid) {
                continue;
            }
            // the status arrives every ten seconds per bss; one database write
            // per key and minute is plenty to keep "last seen" meaningful
            $guard = 'ipsk.seen.'.$keyid;
            if ($this->cacheFactory->getCacheItemValue($guard)) {
                continue;
            }
            $this->cacheFactory->addCacheItem($guard, time(), 60);

            $this->stampIpsk($keyid, $mac);
        }
    }

    private function doHouseKeeping()
    {
        $this->logger->debug('doHouseKeeping()');
        $this->apService->lifetimeHouseKeeping($this->cacheLocal['ap-by-name'], $this->cacheLocal['dev-by-ap-ifname']);
        $this->flushPpskPending();
        $this->resetLogger();
    }

    /**
     * Production logging runs through fingers_crossed: nothing is written until
     * an error occurs, then the buffer is flushed and the handler *stays open*.
     * That is meant for a request, which ends. This process does not — so the
     * first error of the day turned every later debug line into a log entry and
     * produced a 455 MB file in nineteen hours.
     *
     * Symfony resets the logger between requests and between messenger
     * messages; the housekeeping tick is this daemon's equivalent.
     */
    private function resetLogger()
    {
        if ($this->logger instanceof \Symfony\Contracts\Service\ResetInterface
            || $this->logger instanceof \Monolog\ResettableInterface) {
            $this->logger->reset();
        }
    }

    public function statusHandler($device, $message)
    {
        $em = $this->doctrine->getManager();
        $data = json_decode($message->payload);
        $ap = $device->getRadio()->getAccessPoint();
        if (null === $data) {
            return false;
        }
        if (property_exists($data, 'booted')) {
            if ($data->booted) {
                //$this->apservice->assignAllNeighbors();
                return false;
            }
        }

        $updated = [];
        $host = $ap->getName();
        $stations = [];
        $record = false;

        if (property_exists($data, 'stations') && is_array($data->stations)) {
            // payload version 2: the agent already delivers a station map
            $data->stations = json_decode(json_encode($data->stations), true);
        } elseif (property_exists($data, 'stations') && is_object($data->stations)) {
            $data->stations = json_decode(json_encode($data->stations), true);
        } elseif (property_exists($data, 'stations')) {
            $raw = $data->stations;
            $mac = '';
            $added = false;
            foreach (explode("\n", $raw) as $value) {
                $line = trim($value);
                $search = strtolower('station ');
                if (substr(strtolower($line), 0, strlen($search)) == $search) {
                    $record = true;
                    $mac = substr($line, strlen($search), 17);
                    continue;
                }
                if (!$record) {
                    continue;
                }
                if ('' == $line) {
                    continue;
                }
                list($key, $val) = explode(':', $line);
                $key = trim($key);
                $key = str_replace([' ', ',', '.', '-', '/'], '_', $key);
                $val = trim($val);
                if (!array_key_exists($mac, $stations)) {
                    $stations[$mac] = [];
                }
                $added = true;
                $stations[$mac][$key] = $val;
            }
            $data->stations = $stations;
        }
        $data->history = [];
        $key = 'status.device.'.$device->getId();
        $last_status = $this->cacheFactory->getCacheItemValue($key);
        if (is_array($last_status)) {
            $last_status['history'] = [];
            $data->history = [];
            $data->history[0] = $last_status;
        }
        $data = json_decode(json_encode($data), true);
        // The age of a state is measured against our own clock from now on: an
        // access point that has not synchronised its time yet stamps payloads
        // from 1970, which made every age reading nonsense right after a boot.
        $data['received'] = time();
        $this->cacheFactory->addCacheItem($key, $data);
        $this->recordIpskUse($data);
        $updated[] = $ap->getName().' '.$device->getIfname();
        $this->logger->info('Updated status.', ['status' => 0, 'devices_updated' => $updated]);
        // handle station updates
        $this->apService->handleStationUpdates($device, $data);

        return true;
    }

    private function handleRadiusMessage($message)
    {
        $this->logger->info('handleRadiusMessage(): radius', [
                'topic' => $message->topic,
                'payload' => $message->payload,
        ]);
        $attribs = new \stdclass();
        $data = json_decode($message->payload);
        if (isset($data->request)) {
            $attribs->request = new \stdclass();
            foreach ($data->request as $value) {
                $attribs->request->{$value[0]} = $value[1];
            }
        }
        if (isset($data->reply)) {
            $attribs->reply = new \stdclass();
            foreach ($data->reply as $key => $value) {
                $attribs->reply->{$value[0]} = $value[1];
            }
        }
        $expires = 7*86400;

        $mac = null;
        $username = null;
        $ssid = null;
        if (property_exists($attribs, 'request')) {
            if (property_exists($attribs->request, 'Calling-Station-Id')) {
                $mac = $attribs->request->{'Calling-Station-Id'};
                $mac = strtolower($mac);
                $mac = str_replace('-', ':', $mac);
            }
            if (property_exists($attribs->request, 'Called-Station-SSID')) {
                $ssid = $attribs->request->{'Called-Station-SSID'};
            }
            if (property_exists($attribs->request, 'User-Name')) {
                $username = $attribs->request->{'User-Name'};
            }

            $data = [
            'mac' => $mac,
            'ssid' => $ssid,
            'username' => $username,
            'auth' => [ 'reply' => $attribs->reply, 'post_auth' => $attribs->request ],
            'timestamp' => time()
            ];
            $key = "client.authtablev2.".$ssid.$mac;
            $this->cacheFactory->addCacheItem($key, json_encode($data), $expires);
            $this->logger->info('handleRadiusMessage(): sending radius message to '.$key, $data);
        }
    }
}
