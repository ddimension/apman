<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use karpy47\PhpMqttClient\MQTTClient;

class SubscriptionService {

	private $logger;
	private $doctrine;
	private $rpcService;
	private $ch;
	private $device;
	private $cache;
	private $cacheLocal = array( 'ap-by-name' => array(), 'dev-by-ap-ifname' => array() );

	function __construct(\Psr\Log\LoggerInterface $logger, \Doctrine\Persistence\ManagerRegistry $doctrine, \ApManBundle\Service\wrtJsonRpc $rpcService) {
		$this->logger = $logger;
		$this->doctrine = $doctrine;
		$this->rpcService = $rpcService;
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

    public function getMqttClient() {
	if (isset($this->client)) { 
		$this->logger->info('Reusing Mqtt client');
		return $this->client;
	}
	$this->logger->info('Starting Mqtt client');
	$srv = $this;
	$this->client = new \Mosquitto\Client('apmanwebclient', true);
	if (!empty($_SERVER['MQTT_USERNAME']) and !empty($_SERVER['MQTT_PASSWORD'])) {
		$success = $this->client->setCredentials($_SERVER['MQTT_USERNAME'], $_SERVER['MQTT_PASSWORD']);
	}
	if (empty($_SERVER['MQTT_PORT'])) {
		$_SERVER['MQTT_PORT'] = 1883;
	}
	$success = $this->client->connect($_SERVER['MQTT_HOST'], $_SERVER['MQTT_PORT']);
	if ($success) {
		$this->logger->info('Failed to connected.');
		return null;
	}
	$this->logger->info('Connected');
	return $this->client;
    }

    public function runMqttLoop() {
	$this->logger->info('Starting MqttLoop');
	$this->cache = $this->getCache();
        $loop = true;
	while ($loop) {
		$srv = $this;
		$this->client = new \Mosquitto\Client('apmanserver', false);
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
		try {
			$this->client->loopForever();
		} catch (\Exception $e) {
			$this->logger->info('Exception occured: '.$e->getMessage());
			sleep(10);
		}
		$this->logger->info('Disconnected.');
	}
    }

    private function handleMosquittoMessage($message) {
	    $em = $this->doctrine->getManager();
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

	    // Setup cache	    
	    if (!isset($this->cacheLocal['ap-by-name'][$hostname])) {
		    $query = $em->createQuery("SELECT a FROM ApManBundle\Entity\AccessPoint a");
		    foreach ($query->getResult() as $row) {
			    $this->cacheLocal['ap-by-name'][ $row->getName() ] = $row;
		    }
	    }
	    if (!isset($this->cacheLocal['dev-by-ap-ifname'][$hostname])) {
		    $query = $em->createQuery("SELECT d,r,a FROM ApManBundle\Entity\Device d
				LEFT JOIN d.radio r
				LEFT JOIN r.accesspoint a
		    ");
		    foreach ($query->getResult() as $row) {
			    $apname = $row->getRadio()->getAccessPoint()->getName();
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
			$this->addCacheItem('status.ap.'.$ap->getId(), $data);
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
    		return $this->ApLifetimeHandler($ap, $message);
	    } elseif ($tp[3] == 'online') {
		return $this->ApLifetimeHandler($ap, $message);
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

			$this->addCacheItem('status.device['.$device->getId().'].probe.'.$data->address, $obj, 86400);
			if (property_exists($data, 'raw_elements')) {
				$key = 'status.client['.str_replace(':', '', $data->address).'].raw_elements';
				$this->addCacheItem($key, $data->raw_elements, 86400);
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
	$last_status = $this->getCacheItemValue($key);
	if (is_array($last_status)) {
		$last_status['history'] = array();
		$data->history = array();
		$data->history[0] = $last_status;
	}
	$data = json_decode(json_encode($data), true);
	$this->addCacheItem($key, $data, 30);
	$updated[] = $ap->getName().' '.$device->getIfname();
        $this->logger->info('Updated status.', ['status' => 0, 'devices_updated' => $updated]);
	return true;
    }

    private function ApLifetimeHandler($ap, $message, $deviceList = null) {
	$em = $this->doctrine->getManager();
	$tp = explode('/', $message->topic);
	$msg = json_decode($message->payload, true);

	$stateKey = 'status.state['.$ap->getId().']';
	$state = $this->getCacheItemValue($stateKey);
	if (!is_int($state)) $state = null;
	$stateOld = $state;
	$state = intval($state);
	$cif = 0;
	// Handle online message
	if ($tp[3] == 'online') {
		if (!isset($msg['status'])) {
			return false;
		}
		$this->addCacheItem('status.online['.$ap->getId().']', $msg, 86400);
		$this->logger->info('ApLifetimeHandler(): save online status from '.$ap->getName(), $msg);
		if ($msg['status'] == 'online') {
			//
			if ($state == \ApManBundle\Library\AccessPointState::STATE_OFFLINE) {
				$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_ONLINE);
			}
		} else {
			$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_OFFLINE);
			// Reset device cache in offline case
			/*
			echo "Resetting Cache ".$ap->getName()."\n";
			if (is_array($this->cacheLocal['dev-by-ap-ifname']) && isset($this->cacheLocal['dev-by-ap-ifname'][$ap->getName()])) {
				foreach ($this->cacheLocal['dev-by-ap-ifname'][$ap->getName()] as $device) {
					$key = 'status.device.'.$device->getId();
					echo "Resetting Cache Key $key\n";
					$this->deleteCacheItem($key);
				}
			}
			 */
		}
	// Handle status Message
	} elseif ($tp[3] == 'wireless' && $tp[4] == 'status') {
		$this->addCacheItem('status.wireless['.$ap->getId().']', $msg, 86400);
		$this->logger->info('ApLifetimeHandler(): save wireless status (length: '.strlen($message->payload).') from '.$ap->getName());

		if ($state < \ApManBundle\Library\AccessPointState::STATE_ONLINE) {
			return false;
		}

		$pending = false;
		$up = false;
		$failed = false;
		foreach ($msg as $name => $rstate) {
			#var_dump($rstate);
			//print_r($rstate);
			if ($name == 'timestamp' or !is_array($rstate)) continue;

			if ($rstate['pending']) $pending = true;
			if ($rstate['retry_setup_failed']) $failed = true;
			if ($rstate['up']) {
				$up = true;
			} else {
				$up = false;
			}
			if (isset($rstate['interfaces']) && is_array($rstate['interfaces'])) $cif+=count($rstate['interfaces']);
			// Hook to update interface names of devices
			if (isset($rstate['interfaces']) && is_array($rstate['interfaces']) && is_array($this->cacheLocal['dev-by-ap-ifname']) && isset($this->cacheLocal['dev-by-ap-ifname'][$ap->getName()])) {
				foreach ($rstate['interfaces'] as $interface) {
					if (!isset($interface['ifname']) or !isset($interface['section'])) {
						continue;
					}
					foreach ($this->cacheLocal['dev-by-ap-ifname'][$ap->getName()] as $ldev) {
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
			$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_FAILED);
		} elseif ($pending) {
			$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_PENDING);
		} elseif ($up && $state < \ApManBundle\Library\AccessPointState::STATE_CONFIGURED) {
			$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_CONFIGURED);
		} elseif ($up) {
			// keep
		} else {
			$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_PENDING);
		}
	}

	/*
	 * Items to watch on every time a wireless status update comes in
	 */
	if (is_array($this->cacheLocal['dev-by-ap-ifname']) && isset($this->cacheLocal['dev-by-ap-ifname'][$ap->getName()])) {
		// Handle CAC / DFS
		if ($cif && $state >= \ApManBundle\Library\AccessPointState::STATE_CONFIGURED) {
			$cac_active = false;
			$found = 0;
			foreach ($this->cacheLocal['dev-by-ap-ifname'][$ap->getName()] as $device) {
				$key = 'status.device.'.$device->getId();
				$ds = $this->getCacheItemValue($key);
				if ($ds !== null) $found++;
				if (!is_array($ds) || !isset($ds['ap_status']) || !isset($ds['ap_status']['dfs']) || !isset($ds['ap_status']['dfs']['cac_active'])) {
					continue;
				}
#				if ($ap->getName() == 'ap-outdoor2.kalnet.hooya.de') echo "K $key V".substr(json_encode($ds),0,130)."\n";
				#var_dump($ds['ap_status']['dfs']);
				if ($ds['ap_status']['dfs']['cac_active']) {
					$cac_active = true;
				}
			}
			$this->logger->notice("ApLifetimeHandler Monitoring ".$ap->getName()." Interfaces, DFS CAC Active: ".($cac_active?1:0).", Interfaces found: $found, configured Interfaces: $cif\n");
			if ($found >= $cif) {
				if ($cac_active && $state >= \ApManBundle\Library\AccessPointState::STATE_CONFIGURED) {
					$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_DFS_RUNNING);
				}
				if (!$cac_active && $state >= \ApManBundle\Library\AccessPointState::STATE_CONFIGURED && $state <= \ApManBundle\Library\AccessPointState::STATE_DFS_RUNNING) {

					$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_DFS_READY);
				}
			} else {
				$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_DFS_RUNNING);
			}
		}

		// After DFS is ready, AP is also ready for activation
		if ($state == \ApManBundle\Library\AccessPointState::STATE_DFS_READY) {
			// Enable Beacons and BSS management
			$this->logger->info("ApLifetimeHandler(): state $state on ap ".$ap->getName().' detected, updating beacon.' );
			$topic = 'apman/ap/'.$ap->getName().'/command/bulk';
			$commands = [
				'list' => [],
				'options' => [
					'cancel_on_error' => false
				]
			];
			// At first 5g, then 2g	
			$dev2G = [];		
			$dev5G = [];		
			foreach ($this->cacheLocal['dev-by-ap-ifname'][$ap->getName()] as $device) {
				if ($device->getRadio()->getConfigBand() == '5g') {
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
				$eopts->params = array('5');
				$commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'file', 'exec', $eopts);
			}
			foreach ($dev2G as $device) {
				$commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'bss_mgmt_enable', $opts);
				$commands['list'][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'update_beacon', []);
			}
			if (count($commands['list'])) {
				$this->client->publish($topic, json_encode($commands));
			}

			// Assign Neighbors, enable reports
			$this->logger->info("ApLifetimeHandler(): state $state on ap ".$ap->getName().' detected, start AssignAllNeighbors.' );
			$this->assignAllNeighbors();
			$state = $this->changeApLifetimeState($ap, \ApManBundle\Library\AccessPointState::STATE_ACTIVE);
		}	
	}
	$this->logger->debug("ApLifetimeHandler(): state '$state' of ap ".$ap->getName());
    }

    private function changeApLifetimeState($ap, int $state) {
	$stateKey = 'status.state['.$ap->getId().']';
	$stateOld = $this->getCacheItemValue($stateKey);
	if ($state === $stateOld) return $state;
	$this->logger->info("changeApLifetimeState(): changing state from '$stateOld' to '$state'  of ap ".$ap->getName());
	$this->addCacheItem($stateKey, $state, 86400);
	return $state;
    }	    

    public function assignAllNeighbors()
    {
	$em = $this->doctrine->getManager();

	$cmds = [];
	$ssids = $this->doctrine->getRepository('ApManBundle:SSID')->findall();
	foreach ($ssids as $ssid) {
		$neighbors = array();
		foreach ($ssid->getDevices() as $device) {
			$radio = $device->getRadio();
			$ap = $radio->getAccesspoint();
			if (empty($device->getIfname())) {
				$this->logger->error("assignAllNeighbors: ifname missing for ".$ap->getName().":".$radio->getName().":".$device->getName());
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
				$this->logger->error("assignAllNeighbors(): ifname missing for ".$ap->getName().":".$radio->getName().":".$device->getName()."\n");
				continue;
			}


			$nr_own = $device->getRrm();
			if (!(is_array($nr_own) && array_key_exists('value', $nr_own))) {
				continue;
			}

			$own_neighbors = array();
			foreach ($neighbors as $neighbor) {
				if ($neighbor[0] == $nr_own['value'][0]) {
					continue;
				}
				$own_neighbors[] = $neighbor;
			}
			$opts = new \stdClass();
			$opts->list = $own_neighbors;

			$ap = $device->getRadio()->getAccessPoint()->getName();
			if (!isset($cmds[ $ap ])) {
				$cmds[ $ap ] = [];
			}
			$cmds[ $ap ][] = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'rrm_nr_set', $opts);
		}
		//print_r($neighbors);
	}
	foreach ($cmds as $apname => $apcmds) {
		$topic = 'apman/ap/'.$apname.'/command/bulk';
		$commands = [
			'list' => $apcmds,
			'options' => [
				'cancel_on_error' => false
			]
		];
		if (count($commands['list'])) {
			$this->logger->info("assignAllNeighbors(): send rrm commands to $apname\n");
			$this->client->publish($topic, json_encode($commands));
		}
	}
    }

    /**
     * get CacheClientInstance
     * @return Memcached
     */
    private function getCacheClient() {
	    /*
	$client = MemcachedAdapter::createConnection(
	    $_SERVER['MEMCACHE']
	);
	     */
	$client = RedisAdapter::createConnection(
	    'redis://localhost'
	);

	return $client;
    }

    /**
     * get Cache
     * @return MemcachedAdapter
     */
    private function getCache() {
	$client = $this->getCacheClient();
	#return new MemcachedAdapter($client, 'apman', 87600);
	return new RedisAdapter($client, 'apman', 87600);
    }

    private function addCacheItem($key, $data, $expires = null) {
	if (is_null($this->cache)) {
		$this->cache = $this->getCache();
	}

	$key = str_replace(':', '', $key);
	$item = $this->cache->getItem($key);
	if (!is_null($expires)) {
		$item->expiresAfter($expires);
	} else {
		$item->expiresAfter(30);
	}
	$item->set($data);
	$this->cache->save($item);
    }

    public function getCacheItemValue($key) {
	if (is_null($this->cache)) {
		$this->cache = $this->getCache();
	}

	$key = str_replace(':', '', $key);
	if (is_null($this->cache)) {
		$this->cache = $this->getCache();
        }

	$item = $this->cache->getItem($key);
	$value = $item->get();
	return $value;
    }

    public function getMultipleCacheItemValues($keys) {
	if (is_null($this->cache)) {
		$this->cache = $this->getCache();
	}

	$tkeys = [];
	$ti = [];
	foreach ($keys as $key) {
		$nkey = str_replace(':', '', $key);
		$tkeys[] = $nkey;
		$ti[ $nkey ] = $key;
	}
	$items = $this->cache->getItems($tkeys);
	$res = [];
	foreach ($items as $key => $item) {
		$value = $item->get();
		$res[ $ti[ $key ] ] = $value;
	}
	return $res;
    }

    private function deleteCacheItem($key) {
	if (is_null($this->cache)) {
		$this->cache = $this->getCache();
	}

	$key = str_replace(':', '', $key);
	$this->cache->deleteItem($key);
	return;
    }

}
