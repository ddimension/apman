<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use karpy47\PhpMqttClient\MQTTClient;
use ApManBundle\Service\AccessPointService;
use ApManBundle\Factory\MqttFactory;
use ApManBundle\Factory\CacheFactory;

class SubscriptionService {

	private $logger;
	private $doctrine;
	private $rpcService;
	private $apService;
	private $mqttFactory;
	private $cacheFactory;
	private $ch;
	private $device;
	private $cache;
	private $cacheLocal = array( 'ap-by-name' => array(), 'dev-by-ap-ifname' => array() );

	function __construct(\Psr\Log\LoggerInterface $logger,
		\Doctrine\Persistence\ManagerRegistry $doctrine,
		\ApManBundle\Service\wrtJsonRpc $rpcService,
		AccessPointService $apService,
		MqttFactory $mqttFactory,
		CacheFactory $cacheFactory) {
		$this->logger = $logger;
		$this->doctrine = $doctrine;
		$this->rpcService = $rpcService;
		$this->apService = $apService;
		$this->mqttFactory = $mqttFactory;
		$this->cacheFactory = $cacheFactory;
	}

	public static function checkResult($result) {
		if (!is_object($result)) {
			return false;
		}
		if (!property_exists($result, 'jsonrpc')) {
			return false;
		}
		if ($result->jsonrpc != '2.0') {
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

    public function runMqttLoop() {
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
		$this->client->onMessage(function($msg) use ($srv) {
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
		$this->client->onDisconnect(function() use ($srv,$client) {
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

    private function handleMosquittoMessage($message) {
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
	    if ($tp[1] == 'command_result') {
		    $this->logger->info('handleMosquittoMessage(): command result.', [
			    'topic' => $message->topic,
			    'payload' => $message->payload
		    ]);
		    return false;
	    } elseif ($tp[1] == 'ap') {
		    $hostname = $tp[2];
		    if ($tp[3] == 'device') {
			    $device = $tp[4];
		    } elseif ($tp[3] == 'notifications') {
			    $device = $tp[4];
		    } elseif ($tp[3] == 'properties') {
			    if (substr($tp[4],0,8) == 'hostapd.') {
				    $device = $tp[4];
			    }
		    } elseif ($tp[3] == 'booted') {
			    #$this->assignAllNeighbors();
			    return true;
		    } elseif ($tp[3] == 'wireless') {
			    // go on
		    } elseif ($tp[3] == 'online') {
			    // go on
		    } else {
			    return false;
		    }
//	            $this->logger->info('handleMosquittoMessage(): AP Message.');

	    } else {
		    return false;
	    }

	    if (strpos($device, '.') !== false) {
		    $device = substr($device,strpos($device, '.')+1);
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
			    $this->cacheLocal['ap-by-name'][ $apname ] = $row->getRadio()->getAccessPoint();
			    $devname = $row->getIfname();
			    if (!isset($this->cacheLocal['dev-by-ap-ifname'][ $apname ])) {
				    $this->cacheLocal['dev-by-ap-ifname'][ $apname ] = [];
			    }
			    $this->cacheLocal['dev-by-ap-ifname'][ $apname ][ $devname ] = $row;
		    }
	    }

	    if (!isset($this->cacheLocal['ap-by-name'][$hostname])) {
	                $this->logger->info('handleMoqsquittoMessage(): ap not found '.$hostname);
			return true;
            }
	    $ap = $this->cacheLocal['ap-by-name'][ $hostname ];
	    if (is_null($ap)) {
	                $this->logger->info('handleMoqsquittoMessage(): ap not found '.$hostname);
			return true;
            }
	    if ($tp[3] == 'properties' && $tp[4] == 'system') {
	                $this->logger->info('handleMoqsquittoMessage(): saved system.'.$tp[5].' for '.$hostname);
			$data = array();
			$data[ $tp[5] ] = json_decode($message->payload, true);
			$this->cacheFactory->addCacheItem('status.ap.'.$ap->getId(), $data);
			$this->cacheFactory->addCacheItem('status.ap.'.$ap->getId().'.'.$tp[5], $data[ $tp[5] ], 86400);
			return true; 
	    } elseif ($tp[3] == 'properties' && $tp[4] == 'session' && $tp[5] == 'create') {
	                $this->logger->info('handleMoqsquittoMessage(): save session for '.$hostname);
	    } elseif ($tp[3] == 'notifications' && $tp[4] == 'hostapd' && $tp[5] == 'bss.add') {
		    $bssmsg = json_decode($message->payload, true);
		    if (!is_array($bssmsg)) {
			    $this->logger->error('handleMoqsquittoMessage(): bss add notification is not an array. '.$hostname.' '.print_r($bssmsg,true));
			    return false;
		    }
                    if (!isset($bssmsg['name'])) {
			    $this->logger->error('handleMoqsquittoMessage(): missing name property in bss add notification from '.$hostname);
			    return false;
		    }
		    $device = $bssmsg['name'];
		    //$this->logger->info('handleMoqsquittoMessage(): prehandled accesspoint bss add notification '.$tp[5].' for device '.$device.' from '.$hostname,(array)$message);
	    } elseif ($tp[3] == 'wireless' && $tp[4] == 'status') {
    		    return $this->apService->lifetimeMessageHandler($ap, $message, 
			    $this->cacheLocal['dev-by-ap-ifname'][ $hostname ],
		    	    $this->client);
	    } elseif ($tp[3] == 'online') {
    		    return $this->apService->lifetimeMessageHandler($ap, $message, 
			    $this->cacheLocal['dev-by-ap-ifname'][ $hostname ],
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
	    $device = $this->cacheLocal['dev-by-ap-ifname'][ $hostname ][ $device ];
	    if (is_null($device)) {
		    $this->logger->error('handleMosquittoMessage(): device not found ', ['apname' => $hostname, 'topic' => $message->topic ]);
			return false;
	    }
	    if ($tp[3] == 'device' && $tp[5] == 'status') {
		    $this->statusHandler($device, $message);
		    return true;
	    } elseif ($tp[3] == 'properties' and $tp[5] == 'rrm_nr_get_own') {
		    $device->setRrm(json_decode($message->payload, true));
		    $em->persist($device);
		    $em->flush();
		    return true;
	    } elseif ($tp[3] == 'notifications' and $tp[4] == 'hostapd' and $tp[5] == 'bss.add') {
		    $this->logger->info('handleMoqsquittoMessage(): accesspoint bss.add notification '.$tp[5].' for '.$hostname,(array)$message);
		    /* This is now done via the ApLifetimeHandler 
		    $opts = new \stdClass();
		    $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'update_beacon', $opts);
		    $topic = 'apman/ap/'.$ap->getName().'/command';
		    $this->client->publish($topic, json_encode($cmd));
		    $this->logger->info('handleMoqsquittoMessage(): sent update_beacon command because of notification '.$tp[5].' for '.$hostname,(array)$message);
		     */
		    return true;
	    } elseif ($tp[3] == 'notifications') {
		$event = $tp[5];
		$data = json_decode($message->payload);
		if ($event == 'probe') {
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
					'ifName' => $device->getIfname()
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
					'ifName' => $device->getIfname()
				]
			);
			$em->flush();
			return true;
		}
	    }
	    return false;
    }

    private function doHouseKeeping() {
	    $this->logger->debug('doHouseKeeping()');
	    $this->apService->lifetimeHouseKeeping($this->cacheLocal['ap-by-name'], $this->cacheLocal['dev-by-ap-ifname'] );
    }


    public function statusHandler($device, $message) {
	$em = $this->doctrine->getManager();
	$data = json_decode($message->payload);
	$ap = $device->getRadio()->getAccessPoint();
	if ($data === NULL) {
		return false;
	}
	if (property_exists($data, 'booted')) {
		if ($data->booted) {
			#$this->apservice->assignAllNeighbors();
			return false;
		}
	}

	$updated = [];
	$host = $ap->getName();
        $stations = array();
        $record = false;

	if (property_exists($data, 'stations')) {
		$raw = $data->stations;
		foreach (explode("\n", $raw) as $value) {
			$line = trim($value);
			$search = strtolower('station ');
			if (substr(strtolower($line),0,strlen($search)) == $search) {
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
			if (!array_key_exists($mac, $stations)) {
				$stations[$mac] = array();
			}
			$stations[$mac][$key] = $val;
		}
	}
	$data->stations = $stations;
	$data->history = array();
	$key = 'status.device.'.$device->getId();
	$last_status = $this->cacheFactory->getCacheItemValue($key);
	if (is_array($last_status)) {
		$last_status['history'] = array();
		$data->history = array();
		$data->history[0] = $last_status;
	}
	$data = json_decode(json_encode($data), true);
	$this->cacheFactory->addCacheItem($key, $data);
	$updated[] = $ap->getName().' '.$device->getIfname();
        $this->logger->info('Updated status.', ['status' => 0, 'devices_updated' => $updated]);
	return true;
    }
}
