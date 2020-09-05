<?php

namespace ApManBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeviceApiController extends Controller
{
    private $logger;
    private $apservice;
    private $doctrine;
    private $rpcService;

    public function __construct(
	    \Psr\Log\LoggerInterface $logger,
	    \ApManBundle\Service\AccessPointService $apservice,
	    \Doctrine\Persistence\ManagerRegistry $doctrine,
	    \ApManBundle\Service\wrtJsonRpc $rpcService
    )
    {
	    $this->logger = $logger;
	    $this->apservice = $apservice;
	    $this->doctrine = $doctrine;
	    $this->rpcService = $rpcService;
    }

    /**
     * @Route("/event")
     */
    public function eventHandlerOld(Request $request) {
	$em = $this->doctrine->getManager();
	$event = json_decode($request->get('event'));
	$host = $request->get('host');
	$instance = $request->get('instance');
	if ($event === NULL || empty($host) || empty($instance)) {
            return new Response();
	}

	$query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		WHERE d.ifname = :ifname
		AND (a.name = :apname OR a.name = :apname_short)
	");
	$query->setParameter('apname', $host);
	$query->setParameter('apname_short', substr($host, 0, strpos($host, '.')));
	$query->setParameter('ifname', $instance);
	try {
		$device = $query->getSingleResult();
	} catch (Exception $e) {
		throw $this->createNotFoundException('The AP name or device is unknown.');
        }

	$list = get_object_vars($event);
	foreach ($list as $key => $ev) {
		if ($key == 'probe') {
			$che = new \ApManBundle\Entity\ClientHeatMap();
			$che->setAddress($ev->address);
			$che->setDevice($device);
			$che->setTs(new \DateTime('now'));
			$che->setEvent(json_encode($ev));
			if (property_exists($ev, 'signal')) {
				$che->setSignalstr($ev->signal);
			}
			$em->merge($che);
		} else {
			$devent = new \ApManBundle\Entity\Event();
			$devent->setTs(new \DateTime('now'));
			$devent->setType($key);
			$devent->setAddress($ev->address);
			$devent->setEvent(json_encode($ev));
			$devent->setDevice($device);
			if (property_exists($ev, 'signal')) {
				$devent->setSignalstr($ev->signal);
			}
			$em->persist($devent);
		}
	}
	$em->flush();
        return new Response();
    }

    /**
     * @Route("/event-lua")
     */
    public function eventHandler(Request $request) {
	$em = $this->doctrine->getManager();
	$message = json_decode($request->get('message'));
	$method = $request->get('method');
	$host = trim($request->get('hostname'));
	$instance = $request->get('instance');
	$instance = str_replace('hostapd.','', $instance);
	if ($message === NULL) {
            return new Response('Empty Message');
	}
	if (empty($host)) {
            return new Response('Empty hostname');
	}
	if (empty($instance)) {
            return new Response('Empty instance');
	}
	if (empty($method)) {
            return new Response('Empty method');
	}

	$query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		WHERE d.ifname = :ifname
		AND (a.name = :apname OR a.name = :apname_short)
	");
	$query->setParameter('apname', $host);
	$query->setParameter('apname_short', substr($host, 0, strpos($host, '.')));
	$query->setParameter('ifname', $instance);
	$device = $query->getOneOrNullResult();
	if ($device === NULL) {
		throw $this->createNotFoundException('The AP name or device is unknown.');
        }

	if ($method == 'probe') {
		$che = new \ApManBundle\Entity\ClientHeatMap();
		$che->setAddress($message->address);
		$che->setDevice($device);
		$che->setTs(new \DateTime('now'));
		$che->setEvent(json_encode($message));
		if (property_exists($message, 'signal')) {
			$che->setSignalstr($message->signal);
		}
		$em->merge($che);
	} else {
		$devent = new \ApManBundle\Entity\Event();
		$devent->setTs(new \DateTime('now'));
		$devent->setType($method);
		$devent->setAddress($message->address);
		$devent->setEvent(json_encode($message));
		$devent->setDevice($device);
		if (property_exists($message, 'signal')) {
			$devent->setSignalstr($message->signal);
		}
		$em->persist($devent);
	}
	$em->flush();
        return new Response();
    }

    /**
     * @Route("/status-lua")
     */
    public function statusHandler(Request $request) {
	$em = $this->doctrine->getManager();
	$message = json_decode($request->get('message'));
	$host = trim($request->get('hostname'));
	if ($message === NULL) {
            return new Response('Empty Message');
	}
	if (empty($host)) {
            return new Response('Empty hostname');
	}

	$query = $em->createQuery("SELECT a FROM ApManBundle\Entity\AccessPoint a
		WHERE a.name = :apname OR a.name = :apname_short
	");
	$query->setParameter('apname', $host);
	$query->setParameter('apname_short', substr($host, 0, strpos($host, '.')));
	$ap = $query->getOneOrNullResult();
	if ($ap === NULL) {
		$this->logger->error('AP '.$host.' not found.');
		throw $this->createNotFoundException('The AP '.$host.' is unknown.');
	}
	if (property_exists($message, 'booted')) {
		if ($message->booted) {
			$this->apservice->assignAllNeighbors();
			return;
		}
	}

	if (property_exists($message, 'board')) {
		$ap->setStatus(json_decode(json_encode($message->board), true));
		$em->persist($ap);
	}

	if (property_exists($message, 'devices')) {
		foreach ($message->devices as $name => $device) {
			$query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
				LEFT JOIN d.radio r
				LEFT JOIN r.accesspoint a
				WHERE d.ifname = :ifname
				AND a.id = :aid
			");
			$query->setParameter('aid', $ap->getId());
			$query->setParameter('ifname', $name);
			$dev = $query->getOneOrNullResult();
			if ($dev === NULL) {
				$this->logger->error('AP '.$host.', device '.$name.' not found.');
				continue;
			}
			$stations = array();
			$record = false;
			if (property_exists($device, 'stations')) {
				foreach (explode("\n", $device->stations) as $value) {
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
			$device->stations = $stations;
			$dev->setStatus(json_decode(json_encode($device), true));
			$em->persist($dev);
		}
	}
	$em->flush();
        return new Response();
    }
}
