<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;
use karpy47\PhpMqttClient\MQTTClient;

class SubscriptionService {

	private $logger;
	private $doctrine;
	private $rpcService;
	private $ch;
	private $device;

	function __construct(\Psr\Log\LoggerInterface $logger, \Doctrine\Persistence\ManagerRegistry $doctrine, \ApManBundle\Service\wrtJsonRpc $rpcService) {
		$this->logger = $logger;
		$this->doctrine = $doctrine;
		$this->rpcService = $rpcService;
	}

	public function subcribeRadioEvents(\ApManBundle\Entity\Device $device) {
		$this->device = $device;
		$ap = $device->getRadio()->getAccessPoint();
		$session = $this->rpcService->getSession($ap, false);
		if ($session == false) {
			$this->logger->info("ApService:SubscribeRadioEents(): Failed to get session for ap.",
				[ 	
					'ap' => $ap->getName(),
					'ifName' => $device->getIfname(), 
				]
			);
			return false;
		}
		$this->logger->info("ApService:SubscribeRadioEents(): Add subscribtion",
			[ 	
				'ap' => $ap->getName(),
				'ifName' => $device->getIfname(), 
			]
		);

		$ifname = $device->getIfname();
		$ubus_url = $ap->getUbusUrl();
		if (substr($ubus_url, -1) != '/') {
			$ubus_url.= '/';
		}
		$url = $ubus_url.'subscribe/hostapd.'.$ifname;
		$ch = curl_init();
		$this->logger->info("ApService:SubscribeRadioEents(): Connecting to ".$url,
			[ 	
				'ap' => $ap->getName(),
				'ifName' => $device->getIfname(), 
			]
		);
		$writer = new NotificationReader($this->logger, $this->doctrine, $device);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, array($this,'writer'));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		    'Authorization: Bearer '.$session->getSessionId()
		));
		$result = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		//curl_close($ch);
		
		$this->logger->info("ApService:SubscribeRadioEents(): Connection closed to ".$url,
			[ 	
				'code', $code,
				'ap' => $ap->getName(),
				'ifName' => $device->getIfname()
			]
		);
		return $result;
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

	public function writer($ch, $str) {
		$this->ch = $ch;
		$em = $this->doctrine->getManager();
		$ap = $this->device->getRadio()->getAccessPoint();
                $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		#		$json = json_decode($s
/*		
                $this->logger->info("NotificationReader:writer(): read data", 
			[       
				'code' => $code,
                                'data' => $str,
                                'ap' => $ap->getName(),
                                'ifName' => $this->device->getIfname()
                        ]
		);
 */		
		$result = $this->parseMessage($code, $str);
		if (!$result) {
			$this->logger->info("NotificationReader:writer(): failed to parse message.", 
				[       
					'code' => $code,
					'data' => $str,
					'ap' => $ap->getName(),
					'ifName' => $this->device->getIfname()
				]
			);
		}
                return strlen($str);
	}

	private function parseMessage($code, $str) {
		$em = $this->doctrine->getManager();
		$device = $this->device;
		$ap = $device->getRadio()->getAccessPoint();
		$lines = explode("\n", $str);
		if (count($lines) == 1) {
			$data = json_decode($lines[0]);
			if (is_null($data)) {
				sleep(2);
				$this->logger->info("NotificationReader:writer(): unparsable ".$lines[0]); 
                		return false;
			}
			sleep(2);
			if (property_exists($data, 'code')) {
				if ($data->code == 4)  {
					$this->logger->info("NotificationReader:writer(): stop. ".json_encode($data));
					exit(0);
				}
			} else {
				$this->logger->info("NotificationReader:writer(): received single line json: ".json_encode($data));
				return true;
			}
		} elseif (count($lines) == 2) {
			if (substr($lines[0],0,7) == 'retry: ') {
				$retry = substr($lines[0],7);
				$this->logger->info("NotificationReader:writer(): WOULD setting timeout", [
					'timeout' => $retry,
					'code' => $code,
					'data' => $str,
					'ap' => $ap->getName(),
					'ifName' => $this->device->getIfname()
				]);
				curl_setopt($this->ch, CURLOPT_TIMEOUT, $retry);
                		return true;
			}
		} elseif (count($lines) == 4) {
			if (substr($lines[0],0,7) === 'event: ' && substr($lines[1],0,6) == 'data: ') {
				/*		
				    [0] => event: inactive-deauth
				    [1] => data: {"address":"c0:f4:e6:a5:22:cc"}
				 */    
				$event = substr($lines[0],7);
				$data_raw = substr($lines[1],6);
				$data = json_decode($data_raw);
				if (is_null($data)) {
					$this->logger->warn("NotificationReader:writer(): failed to decode json data in line 2."); 
                			return false;
				}
/*				
				$this->logger->info("NotificationReader:writer(): decoded data to array", 
					[       
						'code' => $code,
						'event' => $event,
						'data' => json_encode($data),
						'ap' => $ap->getName(),
						'ifName' => $this->device->getIfname()
					]
				);
*/				
				if ($event == 'probe') {
					$conn = $em->getConnection();
					$signal = NULL;
					if (property_exists($data, 'signal')) {
						$signal = $data->signal;
					}
					$ts = date('Y-m-d H:i:s');
					$sql = 'REPLACE INTO client_heatmap 
						(`address`,`device_id`,`ts`,`event`,`signalstr`) 
						VALUES
						(:address, :device_id, :ts, :event, :signal)';
					$conn->executeUpdate($sql, array (
						'address' => $data->address,
						'device_id' => $this->device->getId(),
						'ts' => $ts,
						'event' => json_encode($data,true),
						'signal' => $signal
					));
					/*
					$che = new \ApManBundle\Entity\ClientHeatMap();
					$che->setAddress($data->address);
					$che->setDevice($this->device);
					$che->setTs(new \DateTime('now'));
					$che->setEvent(json_encode($data, true));
					if (property_exists($data, 'signal')) {
						$che->setSignalstr($data->signal);
					}
					$em->merge($che);
					*/					
					$this->logger->info("NotificationReader:writer(): saved $event as ClindHeatMap.", 
						[       
							'code' => $code,
							'data' => json_encode($data),
							'ap' => $ap->getName(),
							'ifName' => $this->device->getIfname()
						]
					);
					$em->flush();
					return true;
				} else {
					$devent = new \ApManBundle\Entity\Event();
					$devent->setTs(new \DateTime('now'));
					$devent->setType($event);
					$devent->setAddress($data->address);
					$devent->setEvent(json_encode($data, true));
					$devent->setDevice($this->device);
					if (property_exists($data, 'signal')) {
						$devent->setSignalstr($data->signal);
					}
					$em->persist($devent);
					$this->logger->info("NotificationReader:writer(): saved $event as Event.", 
						[       
							'code' => $code,
							'data' => json_encode($data),
							'ap' => $ap->getName(),
							'ifName' => $this->device->getIfname()
						]
					);
					$em->flush();
					return true;
				}
			}
		}
                return false;
	}

 
    public function loop()
    {
	$em = $this->doctrine->getManager();
	$this->parentPID = getmypid();
	//pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));

	$em->getConnection()->connect();
	$query = $em->createQuery("SELECT d,r,a,ssid FROM ApManBundle\Entity\Device d
			LEFT JOIN d.radio r
			LEFT JOIN d.ssid ssid
			LEFT JOIN r.accesspoint a
			GROUP BY d.id, r.id, ssid.id, a.id
			ORDER BY a.id, d.id ASC
	");
	$devices = $query->getResult();
	$devsByAp = array();
	foreach ($devices as $device) {
		if (!$device->getIsEnabled() or !$device->getRadio()->getIsEnabled() or !$device->getSsid()->getIsEnabled()) {
			$this->logger->info('Skipping disabled device '.$device->getId()." on ap ".$device->getRadio()->getAccessPoint());
		}
		$apId = $device->getRadio()->getAccessPoint()->getId();
		#if ($apId != 63) continue;
		if (!array_key_exists($apId, $devsByAp)) {
			$devsByAp[ $apId ] = array();
		}
		$devsByAp[ $apId ][] = $device->getId();
	}
	print_r($devsByAp);
	$em->getConnection()->close();
	foreach ($devsByAp as $apId => $devices) {
		foreach ($devices as $deviceId) {
			$this->logger->info("Starting process for AP $apId device $deviceId");
			$start = microtime(true);
			$pid = pcntl_fork();
			if ($pid == -1) {
				break 1;
			} else if ($pid) {
				// Parent
				$childs [] = $pid;
				continue;
			}
			// Child
			set_time_limit(300);
			$em->getConnection()->connect();
			$qb = $em->createQueryBuilder();
			$query = $em->createQuery("SELECT d,r,a FROM ApManBundle\Entity\Device d
					LEFT JOIN d.radio r
					LEFT JOIN r.accesspoint a
					WHERE d.id = :did
			");
			$query->setParameter('did', $deviceId);
			$device = $query->getSingleResult();
			cli_set_process_title("apman subscriptionservice ap:".$device->getRadio()->getAccesspoint()->getName()." device:".$device->getIfname());
			$loop = true;
			while ($loop) {
				try {
					$result = $this->subcribeRadioEvents($device);
				} catch (\Exception $e) {
					$ouput->writeln('Exception occured: '.$e->getMessage());
				}
				sleep(1);
			}
			$em->getConnection()->close();
			exit(0);
		}
	}
	$em->getConnection()->connect();
	while (pcntl_waitpid(-1, $status)) {
		$this->logger->info('Wait for next child.');
		sleep(1);
	}
	/*
	if(count($childs) > 0) {
	    foreach($childs as $key => $pid) {
		    $res = pcntl_waitpid($pid, $status, WNOHANG);

		    // If the process has already exited
		    if($res == -1 || $res > 0)
			    unset($childs[$key]);
		    // Else kill
		    posix_kill($pid, SIGTERM);
		    //echo "Killed old child $pid\n";
		    sleep(1);
	    }

	}
	 */
	return 0;
    }

    public function childSignalHandler($signo, $pid=null, $status=null) {
	if(!$pid){ 
		$pid = pcntl_waitpid(-1, $status, WNOHANG); 
	}
	while($pid > 0){
		$pid = pcntl_waitpid(-1, $status, WNOHANG);
	}
	return true;
    }

    public function runMqttLoop() {
	$this->logger->info('Starting MqttLoop');
        $loop = true;
	while ($loop) {
		$srv = $this;
		$client = new \Mosquitto\Client('apmanserver', false);
		$client->onConnect(function() use ($srv,$client) {
			$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'system', 'info', null);
			$client->publish('apman/command', json_encode($cmd), 2);
		});
		$client->onMessage(function($msg) use ($srv) {
			if (!$srv->handleMosquittoMessage($msg)) {
				$this->logger->error('Failed to handle message. '.json_encode($msg));
			}
		});
		$success = $client->connect('192.168.203.38', 1883);
		if ($success) {
			$this->logger->info('Failed to connected.');
			continue;
		}
		$client->onDisconnect(function() use ($srv,$client) {
			$this->logger->warn("Disconnected, reconnect\n");
			$success = $client->connect('192.168.203.38', 1883);
			if ($success) {
				$this->logger->info('Failed to connect.');
			}
		});
		$this->logger->info('Connected');
		$client->subscribe('/apman/#', 0);
		try {
			$client->loopForever();
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
	    $this->logger->info('handleMosquittoMessage(): handle message.', [
		    'topic' => $message->topic,
	    	    'payload' => $message->payload
	    ]);
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
			    //
		    } else {
			    return false;
		    }
//	            $this->logger->info('handleMosquittoMessage(): AP Message.');

	    } else {
		    return false;
	    }
	    $shortname = $hostname;
	    if (strpos($hostname, '.') !== false) {
		    $shortname = substr($hostname, 0, strpos($hostname, '.'));
	    }
	    if (strpos($device, '.') !== false) {
		    $device = substr($device,strpos($device, '.')+1);
	    }
	    #$this->logger->info('handleMosquittoMessage(): hostname: '.$shortname.' device:'.$device);
	    $qb = $em->createQueryBuilder();
	    $query = $em->createQuery("SELECT a FROM ApManBundle\Entity\AccessPoint a
				WHERE a.name = :apname
	    ");
	    $query->setParameter('apname', $shortname);
	    $ap = $query->getOneOrNullResult();
	    if (is_null($ap)) {
	                $this->logger->info('handleMoqsquittoMessage(): ap not found '.$shortname);
			return true;
            }
	    if ($tp[3] == 'properties' && $tp[4] == 'system' && $tp[5] == 'board') {
		        $data = json_decode($message->payload, true);
			$ap->setStatus($data);
			$em->persist($ap);
			$em->flush();
			return true; 
	    }
	    $qb = $em->createQueryBuilder();
	    $query = $em->createQuery("SELECT d,r,a FROM ApManBundle\Entity\Device d
				LEFT JOIN d.radio r
				LEFT JOIN r.accesspoint a
				WHERE a.name = :apname
				AND d.ifname = :ifname
	    ");
	    $query->setParameter('apname', $shortname);
      	    $query->setParameter('ifname', $device);
	    $device = $query->getOneOrNullResult();
	    if (is_null($device)) {
	                $this->logger->info('handleMosquittoMessage(): not found');
			return true;
	    }
	    if ($tp[3] == 'device' && $tp[5] == 'status') {
		    $this->statusHandler($device, $message);
		    return true;
	    } elseif ($tp[3] == 'notifications' and $tp[4] == 'hostapd') {
		    return false;
	    } elseif ($tp[3] == 'notifications'){
		$event = $tp[5];
		$data = json_decode($message->payload);
		if ($event == 'probe') {
			$conn = $em->getConnection();
			if (property_exists($data, 'signal')) {
				$signal = $data->signal;
			}
			$ts = date('Y-m-d H:i:s');
			$sql = 'REPLACE INTO client_heatmap 
				(`address`,`device_id`,`ts`,`event`,`signalstr`) 
				VALUES
				(:address, :device_id, :ts, :event, :signal)';
			$conn->executeUpdate($sql, array (
				'address' => $data->address,
				'device_id' => $device->getId(),
				'ts' => $ts,
				'event' => json_encode($data,true),
				'signal' => $signal
			));
			$this->logger->info("handleMoqsquittoMessage(): saved $event as ClindHeatMap.", 
				[       
					'data' => json_encode($data),
					'ap' => $ap->getName(),
					'ifName' => $device->getIfname()
				]
			);
			$em->flush();
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
	$last_status = $device->getStatus();
	if (is_array($last_status)) {
		$last_status['history'] = array();
		$data->history = array();
		$data->history[0] = $last_status;
	}
	$device->setStatus(json_decode(json_encode($data), true));
	$em->persist($device);
	$updated[] = $ap->getName().' '.$device->getIfname();
	$em->flush();
        $this->logger->info('Updated status.', ['status' => 0, 'devices_updated' => $updated]);
	return true;
    }

}
