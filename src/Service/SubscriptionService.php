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

    public function __construct(\Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        AccessPointService $apService,
        MqttFactory $mqttFactory,
        CacheFactory $cacheFactory)
    {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->apService = $apService;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
    }

    public static function checkResult($result)
    {
        if (!is_object($result)) {
            return false;
        }
        if (!property_exists($result, 'jsonrpc')) {
            return false;
        }
        if ('2.0' != $result->jsonrpc) {
            return false;
        }
        if (!property_exists($result, 'result')) {
            return false;
        }
        if (!is_array($result->result)) {
            return false;
        }

        return true;
    }

    public function runMqttLoop()
    {
        $this->logger->info('Starting MqttLoop');
        $this->cache = $this->cacheFactory->getCache();
        $loop = true;
        while ($loop) {
            $srv = $this;
            unset($this->client);
            $this->client = $this->mqttFactory->getClient('apmanserver', false);
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
            $this->client->subscribe('apman/#', 0);
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
                sleep(10);
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
        if ('command_result' == $tp[1]) {
            $this->logger->info('handleMosquittoMessage(): command result.', [
                'topic' => $message->topic,
                'payload' => $message->payload,
            ]);

            return false;
        } elseif ('ap' == $tp[1]) {
            $hostname = $tp[2];
            if ('device' == $tp[3]) {
                $device = $tp[4];
            } elseif ('notifications' == $tp[3]) {
                $device = $tp[4];
            } elseif ('properties' == $tp[3]) {
                if ('hostapd.' == substr($tp[4], 0, 8)) {
                    $device = $tp[4];
                }
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
        // Setup cache
        if (!isset($this->cacheLocal['ap-by-name'][$hostname]) or !isset($this->cacheLocal['dev-by-ap-ifname'][$hostname])) {
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
        if ('properties' == $tp[3] && 'system' == $tp[4]) {
            $this->logger->info('handleMoqsquittoMessage(): saved system.'.$tp[5].' for '.$hostname);
            $data = [];
            $data[$tp[5]] = json_decode($message->payload, true);
            $this->cacheFactory->addCacheItem('status.ap.'.$ap->getId(), $data);
            $this->cacheFactory->addCacheItem('status.ap.'.$ap->getId().'.'.$tp[5], $data[$tp[5]], 86400);

            return true;
        } elseif ('properties' == $tp[3] && 'session' == $tp[4] && 'create' == $tp[5]) {
            $this->logger->info('handleMoqsquittoMessage(): save session for '.$hostname);
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
            return $this->apService->lifetimeMessageHandler($ap, $message,
                $this->cacheLocal['dev-by-ap-ifname'][$hostname],
                    $this->client);
        } elseif ('online' == $tp[3]) {
            return $this->apService->lifetimeMessageHandler($ap, $message,
                $this->cacheLocal['dev-by-ap-ifname'][$hostname],
                    $this->client);
            /*
            } elseif ($tp[3] == 'properties') {
            $this->logger->info('handleMoqsquittoMessage(): implement properties handler for message from '.$hostname,(array)$message);
            return false;
            */
        }
        //echo "XX: ".$message->topic.' '.$message->payload."\n";

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
        if ('device' == $tp[3] && 'status' == $tp[5]) {
            $this->statusHandler($device, $message);

            return true;
        } elseif ('properties' == $tp[3] and 'rrm_nr_get_own' == $tp[5]) {
            $device->setRrm(json_decode($message->payload, true));
            $em->persist($device);
            $em->flush();

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

                $this->logger->info("handleMoqsquittoMessage(): saved $event as ClindHeatMap.",
                [
                    'data' => json_encode($data),
                    'ap' => $ap->getName(),
                    'ifName' => $device->getIfname(),
                ]
            );

                return true;
            } else {
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
                $this->logger->info("handleMoqsquittoMessage(): saved $event as Event.",
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

    private function doHouseKeeping()
    {
        $this->logger->debug('doHouseKeeping()');
        $this->apService->lifetimeHouseKeeping($this->cacheLocal['ap-by-name'], $this->cacheLocal['dev-by-ap-ifname']);
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

        if (property_exists($data, 'stations')) {
            $raw = $data->stations;
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
                $stations[$mac][$key] = $val;
            }
        }
        $data->stations = $stations;
        $data->history = [];
        $key = 'status.device.'.$device->getId();
        $last_status = $this->cacheFactory->getCacheItemValue($key);
        if (is_array($last_status)) {
            $last_status['history'] = [];
            $data->history = [];
            $data->history[0] = $last_status;
        }
        $data = json_decode(json_encode($data), true);
        $this->cacheFactory->addCacheItem($key, $data);
        $updated[] = $ap->getName().' '.$device->getIfname();
        $this->logger->info('Updated status.', ['status' => 0, 'devices_updated' => $updated]);

        return true;
    }
}
