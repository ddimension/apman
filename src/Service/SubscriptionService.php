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
    private $radiusAuthService;
    private $stateTree;
    /** the AP the message currently being handled came from */
    private $apContext;
    /** ssid ids whose keys changed and have to go out again */
    private $ppskPending = [];
    private const CACHE_REFRESH_INTERVAL = 60;
    private const HOUSEKEEPING_INTERVAL = 10;
    /** the RADIUS server sharing this loop, null when it is switched off */
    private $reconnecting = false;
    private $reconnectDelay = 0;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        AccessPointService $apService,
        MqttFactory $mqttFactory,
        CacheFactory $cacheFactory,
        PpskService $ppskService,
        RadiusAuthService $radiusAuthService,
        ApContextService $apContext,
        StateTreeService $stateTree
    ) {
        $this->ppskService = $ppskService;
        $this->radiusAuthService = $radiusAuthService;
        $this->apContext = $apContext;
        $this->stateTree = $stateTree;
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->apService = $apService;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
    }

    /**
     * Telemetry is replaced every interval, so QoS 0 is enough and saves an
     * acknowledgement per message in both directions. Command results and
     * retained properties are worth QoS 1. Overlapping filters are fine: the
     * broker delivers one copy at the highest matching QoS (verified against
     * this mosquitto).
     */
    private const SUBSCRIPTIONS = [
        'apman/#' => 0,
        'apman/+/+/command_result/#' => 1,
        'apman/+/+/properties/#' => 1,
        'apman/+/+/online' => 1,
        'apman/+/+/booted' => 1,
        // the agent publishes its radius decisions at QoS 1; the apman/# QoS 0
        // filter would downgrade them (the broker takes the highest match)
        'apman/ap/+/radius/auth/#' => 1,
    ];

    /**
     * The daemon's one event loop.
     *
     * It used to be a blocking `Mosquitto\Client::loop(10000)` inside a while
     * loop — which meant everything else in this process had to wait up to ten
     * seconds for its turn. Now MQTT, the RADIUS socket and the housekeeping
     * tick all hang off the same select, and each of them gets served the
     * moment there is something to do.
     */
    public function runMqttLoop()
    {
        $this->logger->info('Starting MqttLoop');
        $this->cache = $this->cacheFactory->getCache();
        $loop = \React\EventLoop\Loop::get();

        $this->connectMqtt($loop);
        $loop->addPeriodicTimer(self::HOUSEKEEPING_INTERVAL, function () {
            try {
                $this->doHouseKeeping();
            } catch (\Throwable $e) {
                $this->logger->error('Housekeeping failed: '.$e->getMessage());
                $this->reviveDatabase();
            }
        });


        $loop->run();

        return 0;
    }

    /**
     * Build a client, connect it, subscribe. Every reconnect starts here again
     * with a fresh client — the old one is done once its stream is closed.
     */
    private function connectMqtt(\React\EventLoop\LoopInterface $loop)
    {
        $client = $this->mqttFactory->getReactClient($loop);
        // false: keep the session, so the subscriptions and any in flight QoS 1
        // messages survive a short disconnect — same as before.
        [$host, $port, $connection] = $this->mqttFactory->getReactConnection('apmanserver', false);
        $this->client = new \ApManBundle\Mqtt\ReactPublisher($client, $this->logger, $loop);
        // Everything this process publishes goes through the one connection the
        // loop services — a service that opens its own would have nobody to
        // read its socket, and its next publish would throw into the middle of
        // message handling.
        $this->apService->setPublisher($this->client);

        $client->on('message', function (\BinSoul\Net\Mqtt\Message $message) {
            $this->dispatch(new \ApManBundle\Mqtt\Message(
                $message->getTopic(),
                $message->getPayload(),
                $message->getQosLevel(),
                $message->isRetained()
            ));
        });
        $client->on('warning', function (\Throwable $e) {
            $this->logger->warning('Mqtt: '.$e->getMessage());
        });
        $client->on('error', function (\Throwable $e) use ($loop) {
            $this->logger->error('Mqtt: '.$e->getMessage());
            $this->scheduleReconnect($loop);
        });
        $client->on('close', function () use ($loop) {
            $this->logger->warning('Mqtt: connection closed');
            $this->scheduleReconnect($loop);
        });

        $client->connect($host, $port, $connection)->then(
            function () use ($client) {
                $this->reconnectDelay = 0;
                $this->logger->info('Connected');
                foreach (self::SUBSCRIPTIONS as $filter => $qos) {
                    $client->subscribe(new \BinSoul\Net\Mqtt\DefaultSubscription($filter, $qos))->then(
                        null,
                        function (\Throwable $e) use ($filter) {
                            $this->logger->error('Mqtt: subscribe '.$filter.' failed: '.$e->getMessage());
                        }
                    );
                }
            },
            function (\Throwable $e) use ($loop) {
                $this->logger->error('Mqtt: connect failed: '.$e->getMessage());
                $this->scheduleReconnect($loop);
            }
        );
    }

    /**
     * Reconnect with a growing delay, but never faster than once a second and
     * never slower than every half minute. A single pending timer at a time:
     * 'error' and 'close' usually arrive together and would otherwise start
     * two clients.
     */
    private function scheduleReconnect(\React\EventLoop\LoopInterface $loop)
    {
        if ($this->reconnecting) {
            return;
        }
        $this->reconnecting = true;
        $this->reconnectDelay = min(30, max(1, $this->reconnectDelay * 2));
        $this->logger->info('Mqtt: reconnecting in '.$this->reconnectDelay.'s');
        $loop->addTimer($this->reconnectDelay, function () use ($loop) {
            $this->reconnecting = false;
            $this->reviveDatabase();
            $this->connectMqtt($loop);
        });
    }

    /**
     * A connection that died while the process kept running leaves Doctrine
     * with a handle that throws on first use. The old loop caught that as an
     * exception around the whole loop body; here it is checked where it can
     * actually be repaired.
     */
    private function reviveDatabase()
    {
        // DBAL 3 dropped ping(): the way to find out whether a handle still
        // works is to use it. The cheapest query the platform knows is enough,
        // and closing is all that is needed afterwards — the next query opens a
        // fresh connection by itself.
        try {
            $connection = $this->doctrine->getManager()->getConnection();
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (\Throwable $e) {
            $this->logger->warning('Database handle is stale, closing it: '.$e->getMessage());
            try {
                $connection->close();
            } catch (\Throwable $ignored) {
                // nothing left to close
            }
        }
    }

    /**
     * One message, with the failure of a single message kept local: a station
     * report that trips over its own payload must not take the loop down.
     */
    private function dispatch(\ApManBundle\Mqtt\Message $message)
    {
        $tp = explode('/', $message->topic);
        // From here on, every log line written while this message is handled
        // carries the AP it came from. The raw hostname, before the lookup:
        // messages from hosts the controller does not know get stamped too.
        $this->apContext->setAp(('ap' === ($tp[1] ?? null)) ? ($tp[2] ?? null) : null);
        try {
            if (!$this->handleMessage($message)) {
                $this->logger->debug('Failed to handle message. '.$message->topic);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to handle message. '.$e.' '.$e->getTraceAsString());
        } finally {
            $this->apContext->clearAp();
        }
    }

    private function handleMessage($message)
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
        if ('ap' == $tp[1]) {
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
            } elseif ('radius' == $tp[3] && 'auth' == ($tp[4] ?? null)) {
                // go on
            } else {
                return false;
            }
            //	            $this->logger->info('handleMessage(): AP Message.');
        } else {
            return false;
        }

        if (false !== strpos($device, '.')) {
            $device = substr($device, strpos($device, '.') + 1);
        }
        //$this->logger->info('handleMessage(): AP Message.', [$hostname, $device, $message->topic]);

        /*
        // Cache Expiration
        if (isset($this->cacheCreated)) {
            if ($this->cacheCreated+10 < time()) {
                    $this->logger->notice('handleMessage(): Expire local database object cache.');
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
                $devname = $row->ifname();
                if (!isset($this->cacheLocal['dev-by-ap-ifname'][$apname])) {
                    $this->cacheLocal['dev-by-ap-ifname'][$apname] = [];
                }
                // no name, nothing to look it up by: the topics this index
                // serves all carry one
                if (null !== $devname) {
                    $this->cacheLocal['dev-by-ap-ifname'][$apname][$devname] = $row;
                }
            }
        }

        if (!isset($this->cacheLocal['ap-by-name'][$hostname])) {
            $this->logger->info('handleMessage(): ap not found '.$hostname);

            return true;
        }
        $ap = $this->cacheLocal['ap-by-name'][$hostname];
        if (is_null($ap)) {
            $this->logger->info('handleMessage(): ap not found '.$hostname);

            return true;
        }
        if ('properties' == $tp[3] && 'agent' == $tp[4]) {
            $agent = json_decode($message->payload, true);
            if (!is_array($agent)) {
                return false;
            }
            $agent['received'] = time();
            $this->cacheFactory->addCacheItem('status.ap.'.$ap->getId().'.agent', $agent, 180 * 86400);
            $this->logger->info('handleMessage(): agent '.($agent['version'] ?? '?').' on '.$hostname);

            return true;
        }
        if ('properties' == $tp[3] && 'radius' == $tp[4]) {
            $radius = json_decode($message->payload, true);
            if (!is_array($radius)) {
                return false;
            }
            $radius['received'] = time();
            // 7 days: long enough that an access point which has been off for
            // a weekend still shows what its server was doing when it went,
            // short enough that a decommissioned one stops claiming to run.
            $this->cacheFactory->addCacheItem('status.ap.'.$ap->getId().'.radius', $radius, 7 * 86400);

            return true;
        }
        if ('properties' == $tp[3] && 'system' == $tp[4]) {
            $this->logger->info('handleMessage(): saved system.'.$tp[5].' for '.$hostname);
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
                $this->logger->info('handleMessage(): stored ubus session for '.$hostname);
            }

            return true;
        } elseif ('notifications' == $tp[3] && 'hostapd' == $tp[4] && 'bss.add' == $tp[5]) {
            $bssmsg = json_decode($message->payload, true);
            if (!is_array($bssmsg)) {
                $this->logger->error('handleMessage(): bss add notification is not an array. '.$hostname.' '.print_r($bssmsg, true));

                return false;
            }
            if (!isset($bssmsg['name'])) {
                $this->logger->error('handleMessage(): missing name property in bss add notification from '.$hostname);

                return false;
            }
            $device = $bssmsg['name'];
        //$this->logger->info('handleMessage(): prehandled accesspoint bss add notification '.$tp[5].' for device '.$device.' from '.$hostname,(array)$message);
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
        } elseif ('radius' == $tp[3] && 'auth' == $tp[4]) {
            return $this->handleRadiusAuthEvent($ap, $message);
            /*
            } elseif ($tp[3] == 'properties') {
            $this->logger->info('handleMessage(): implement properties handler for message from '.$hostname,(array)$message);
            return false;
            */
        }
        //echo "XX: ".$message->topic.' '.$message->payload."\n";
        //$this->logger->info('handleMessage(): debug '.$hostname, [$message->topic]);

        // Handle device specific messages
        if (!isset($this->cacheLocal['dev-by-ap-ifname'][$hostname])) {
            $this->logger->info('handleMessage(): ap not found '.$hostname);

            return true;
        }
        if (!isset($this->cacheLocal['dev-by-ap-ifname'][$hostname][$device])) {
            $this->logger->info('handleMessage(): device '.$device.' not found '.$hostname);

            return true;
        }
        $device = $this->cacheLocal['dev-by-ap-ifname'][$hostname][$device];
        if (is_null($device)) {
            $this->logger->error('handleMessage(): device not found ', ['apname' => $hostname, 'topic' => $message->topic]);

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
            $this->logger->info('handleMessage(): accesspoint bss.add notification '.$tp[5].' for '.$hostname, (array) $message);
            /* This is now done via the ApLifetimeHandler
            $opts = new \stdClass();
            $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->ifname(), 'update_beacon', $opts);
            $topic = 'apman/ap/'.$ap->getName().'/command';
            $this->client->publish($topic, json_encode($cmd));
            $this->logger->info('handleMessage(): sent update_beacon command because of notification '.$tp[5].' for '.$hostname,(array)$message);
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
                if (property_exists($data, 'signal')) {
                    $this->recordProbeSignal($device, $data->address, (int) $data->signal);
                }
                if (property_exists($data, 'raw_elements')) {
                    $key = 'status.client['.str_replace(':', '', $data->address).'].raw_elements';
                    $this->cacheFactory->addCacheItem($key, $data->raw_elements, 86400);
                }

                $this->logger->info(
                    "handleMessage(): saved $event as ClindHeatMap.",
                    [
                    'data' => json_encode($data),
                    'ap' => $ap->getName(),
                    'ifName' => $device->ifname(),
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
                    "handleMessage(): saved $event as Event.",
                    [
                    'data' => json_encode($data),
                    'ap' => $ap->getName(),
                    'ifName' => $device->ifname(),
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
    /**
     * How strongly this station is heard here, kept per station rather than per
     * probe.
     *
     * `rssi_ignore_probe_request` and `rssi_reject_assoc_rssi` are only as good
     * as the number somebody puts in them, and that number is guessed. The
     * probes have been arriving all along — one cache entry per station per
     * bss, a day long — but they can only be read back by asking for a station
     * by name, so there was no way to ask "how many stations are below -75 dBm
     * here". This keeps the answer.
     *
     * Best and last, because they answer different questions: the best is how
     * well the station can be heard when it is where it usually is, and a
     * threshold set above it turns that station away for good. The last is
     * where it was a moment ago.
     */
    private function recordProbeSignal($device, string $address, int $signal): void
    {
        $key = 'status.device['.$device->getId().'].probe_signals';
        $map = $this->cacheFactory->getCacheItemValue($key);
        if (!is_array($map)) {
            $map = [];
        }
        $mac = strtolower($address);
        $now = time();
        $best = isset($map[$mac]['best']) ? max((int) $map[$mac]['best'], $signal) : $signal;
        $map[$mac] = ['best' => $best, 'last' => $signal, 'ts' => $now,
            'n' => 1 + (int) ($map[$mac]['n'] ?? 0)];

        // A bss that has heard six hundred stations in a week is a bss next to
        // a road. Keep the ones seen most recently rather than growing without
        // end; the oldest entries are the least useful for choosing a threshold
        // anyway.
        if (count($map) > 400) {
            uasort($map, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
            $map = array_slice($map, 0, 300, true);
        }
        $this->cacheFactory->addCacheItem($key, $map, 7 * 86400);
    }

    /**
     * Every control channel event, counted per bss.
     *
     * The ring buffer next to this keeps the last forty, which answers "what
     * just happened" and nothing about how often. Counting every name rather
     * than a chosen few means an event nobody thought of appears by itself —
     * which is how OCV-FAILURE will show up the day OCV is switched on, without
     * a line of code being added for it.
     */
    private function countCtrlEvent($device, string $name): void
    {
        $key = 'status.device['.$device->getId().'].ctrlcounts';
        $counts = $this->cacheFactory->getCacheItemValue($key);
        if (!is_array($counts)) {
            $counts = [];
        }
        $counts[$name] = ['n' => 1 + (int) ($counts[$name]['n'] ?? 0), 'last' => time(),
            'first' => $counts[$name]['first'] ?? time()];
        $this->cacheFactory->addCacheItem($key, $counts, 7 * 86400);
    }

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
            'ifname' => $data['ifname'] ?? ($device ? $device->ifname() : null),
            'address' => $address,
            'fields' => $fields,
            'raw' => $data['raw'] ?? null,
        ];

        if ($device) {
            $this->pushCtrlEvent('status.device['.$device->getId().'].ctrlevents', $entry, 40);
            $this->countCtrlEvent($device, $name);
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
                    $this->stampIpsk($fields['keyid'], $address, $ap ? $ap->getName() : null);
                } elseif ($address) {
                    // no keyid: either an ordinary station on the network
                    // passphrase, or one whose key came from RADIUS — the
                    // second case is worth learning from
                    $this->learnFromRadius($device, $address);
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

            case 'OCV-FAILURE':
                // Operating Channel Validation said the handshake claimed a
                // channel it did not happen on. Either an attack or a client
                // that gets it wrong, and the frame says which handshake.
                // hostapd writes this one as addr=, not address=, so the
                // station is in the fields where every other event has it at
                // the top level.
                $this->logger->warning('ctrlEvent(): ocv rejected '
                    .($address ?: ($fields['addr'] ?? 'a station')).' on '.$entry['ifname']
                    .' — frame '.($fields['frame'] ?? '?').', '.($fields['error'] ?? 'no reason given'));
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
    private function stampIpsk($keyid, $mac, $apName = null)
    {
        $ppsk = $this->doctrine->getManager()
            ->getRepository('ApManBundle\Entity\Ppsk')->findOneBy(['keyid' => $keyid]);
        if (!$ppsk) {
            $this->logger->warning('stampIpsk(): '.($apName ? '['.$apName.'] ' : '').
                'unknown keyid '.$keyid.' used by '.$mac);

            return;
        }
        $pending = $this->ppskService->recordUsed($ppsk, $mac, $apName);
        if ($pending) {
            $this->ppskPending[$pending] = true;
        }
    }

    /**
     * The same learning for a network whose keys come from RADIUS.
     *
     * There is no keyid to go by there — that is a property of the psk file,
     * and a RADIUS answer carries none. What we do know is that the station
     * connected, that it has no key of its own, and which shared key it can
     * only have used: if exactly one is in service, the answer is unambiguous.
     */
    private function learnFromRadius($device, $mac)
    {
        if (!$device || !$device->getSsid() || !$mac) {
            return;
        }
        $ssid = $device->getSsid();
        if (!$this->ppskService->managesOwnKeys($ssid) || !$this->ppskService->usesRadius($ssid)) {
            return;
        }
        $repo = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk');
        $mac = strtolower($mac);
        if ($repo->findOneBy(['ssid' => $ssid, 'mac' => $mac])) {
            return;
        }
        $shared = $repo->findBy([
            'ssid' => $ssid,
            'mac' => \ApManBundle\Entity\Ppsk::ANY_MAC,
            'enabled' => true,
        ]);
        if (1 !== count($shared)) {
            // none, or more than one — which key it used cannot be told from
            // here, and guessing would bind the wrong secret to the device
            return;
        }
        if ($this->ppskService->learnFromShared($shared[0], $mac)) {
            $this->ppskPending[$ssid->getId()] = true;
        }
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
            $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->find($id);
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
    private function recordIpskUse(array $data, $apName = null)
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

            $this->stampIpsk($keyid, $mac, $apName);
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
        // State tree, stage one. hostapd reports the channel availability check
        // per bss even though the channel belongs to the radio — the tree rolls
        // it up there rather than to the access point, which is where the old
        // machine put it and why "no data" and "CAC running" became the same
        // thing.
        $this->stateTree->observeBss($device, [
            'status' => $data['ap_status']['status'] ?? null,
            'cac' => isset($data['ap_status']['dfs']['cac_active'])
                ? (bool) $data['ap_status']['dfs']['cac_active'] : null,
        ]);
        $this->recordIpskUse($data, $ap->getName());
        $updated[] = $ap->getName().' '.$device->ifname();
        $this->logger->info('Updated status.', ['status' => 0, 'devices_updated' => $updated]);
        // handle station updates
        $this->apService->handleStationUpdates($device, $data);

        return true;
    }

    /**
     * One accept/reject from the agent's on-AP RADIUS server.
     *
     * The agent answers hostapd's per-station PSK queries from the uci
     * wifi-station sections this controller wrote, and reports every decision
     * on apman/ap/<host>/radius/auth[/<bssid>]. This is the authoritative
     * identity trace for RADIUS networks: the control channel reports no
     * keyid there, and for SAE it reports nothing at all.
     */
    private function handleRadiusAuthEvent($ap, $message)
    {
        $data = json_decode($message->payload, true);
        if (!is_array($data) || !in_array($data['decision'] ?? null, ['accept', 'reject'], true)) {
            $this->logger->warning('handleRadiusAuthEvent(): unusable radius event from '.
                $ap->getName());

            return false;
        }
        // the agent sends the mac as 12 bare hex chars — normalise before
        // the strict check, which rejects anything that is not a mac
        $mac = $this->radiusAuthService->normaliseMac((string) ($data['mac'] ?? ''));
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
            $this->logger->warning('handleRadiusAuthEvent(): bad mac in radius event from '.
                $ap->getName());

            return false;
        }
        $ssid = isset($data['ssid']) ? mb_substr((string) $data['ssid'], 0, 64) : null;

        // The key field names the uci section the answer came from,
        // ppsk_<device>_<id> — resolveBySectionName() knows the naming.
        $ppsk = null;
        if (!empty($data['key'])) {
            $ppsk = $this->ppskService->resolveBySectionName((string) $data['key'], $ssid);
        }

        // Rejections are recorded for the history and the flaky picture, but
        // nothing is stamped: no key was used. Accepts carry the identity.
        $this->radiusAuthService->recordAgentAuth(
            $mac, $ssid, $ap->getName(), $data['decision'], $data['reason'] ?? null, $ppsk, $data
        );
        if ('accept' === $data['decision'] && $ppsk) {
            $pending = $this->ppskService->recordUsed($ppsk, $mac, $ap->getName());
            if ($pending) {
                $this->ppskPending[$pending] = true;
            }
        }

        return true;
    }
}
