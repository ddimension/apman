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
			if (!$srv->handleMosquittoMessage($msg)) {
				$this->logger->error('Failed to handle message. '.json_encode($msg));
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
			    if (substr($tp[4],0,8) == 'hostapd.') {
				    $device = $tp[4];
			    }
		    } elseif ($tp[3] == 'booted') {
			    $this->assignAllNeighbors();
			    return true;
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
	    #$this->logger->info('handleMosquittoMessage(): hostname: '.$shortname.' device:'.$device);
	    $qb = $em->createQueryBuilder();
	    $query = $em->createQuery("SELECT a FROM ApManBundle\Entity\AccessPoint a
				WHERE a.name = :apname
	    ");
	    $query->setParameter('apname', $hostname);
	    $ap = $query->getOneOrNullResult();
	    if (is_null($ap)) {
	                $this->logger->info('handleMoqsquittoMessage(): ap not found '.$hostname);
			return true;
            }
	    if ($tp[3] == 'properties' && $tp[4] == 'system') {
	                $this->logger->info('handleMoqsquittoMessage(): saved system.'.$tp[5].' for '.$hostname);
		        $data = $ap->getStatus();
		        if (!is_array($data)) {
				$data = array();
			}
			$data[ $tp[5] ] = json_decode($message->payload, true);
			$ap->setStatus($data);
			$em->persist($ap);
			$em->flush();
			return true; 
	    } elseif ($tp[3] == 'properties' && $tp[4] == 'session' && $tp[5] == 'create') {
	                $this->logger->info('handleMoqsquittoMessage(): save session for '.$hostname);
	    }
	    $qb = $em->createQueryBuilder();
	    $query = $em->createQuery("SELECT d,r,a FROM ApManBundle\Entity\Device d
				LEFT JOIN d.radio r
				LEFT JOIN r.accesspoint a
				WHERE a.name = :apname
				AND d.ifname = :ifname
	    ");
	    $query->setParameter('apname', $hostname);
      	    $query->setParameter('ifname', $device);
	    $device = $query->getOneOrNullResult();
	    if (is_null($device)) {
		    $this->logger->info('handleMosquittoMessage(): not found');
			return true;
	    }
	    if ($tp[3] == 'device' && $tp[5] == 'status') {
		    $this->statusHandler($device, $message);
		    return true;
	    } elseif ($tp[3] == 'properties' and $tp[5] == 'rrm_nr_get_own') {
		    $device->setRrm(json_decode($message->payload, true));
		    $em->persist($device);
		    $em->flush();
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

    public function assignAllNeighbors()
    {
	$em = $this->doctrine->getManager();

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

			$opts = new \stdClass();
			$opts->neighbor_report = true;
			$opts->beacon_report = true;
			$opts->bss_transition = true;

			$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'bss_mgmt_enable', $opts);

			$topic = 'apman/ap/'.$ap->getName().'/command';
			$this->client->publish($topic, json_encode($cmd));

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

			$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'rrm_nr_set', $opts);

			$topic = 'apman/ap/'.$ap->getName().'/command';
			$this->client->publish($topic, json_encode($cmd));
		}
		//print_r($neighbors);
	}
    }

}
