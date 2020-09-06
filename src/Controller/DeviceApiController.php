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
     * @Route("/api/device/status")
     */
    public function statusHandler(Request $request) {
	$em = $this->doctrine->getManager();
	$data = json_decode($request->getContent());
	if ($data === NULL) {
            return new Response('Invalid json');
	}
	if (!property_exists($data, 'message') || empty($data->message)) {
            return new Response('Empty Message');
	}
	if (!property_exists($data, 'hostname',) || empty($data->hostname)) {
            return new Response('Empty hostname');
	}

	$query = $em->createQuery("SELECT a FROM ApManBundle\Entity\AccessPoint a
		WHERE a.name = :apname OR a.name = :apname_short
	");
	$query->setParameter('apname', $data->hostname);
	$query->setParameter('apname_short', substr($data->hostname, 0, strpos($data->hostname, '.')));
	$ap = $query->getOneOrNullResult();
	if ($ap === NULL) {
		$this->logger->error('AP '.$data->hostname.' not found.');
		throw $this->createNotFoundException('The AP '.$data->hostname.' is unknown.');
	}
	if (property_exists($data->message, 'booted')) {
		if ($data->message->booted) {
			$this->apservice->assignAllNeighbors();
			return new Response(json_encode(['status' => 0, 'message' => 'Sent neighbor data']));
		}
	}

	if (property_exists($data->message, 'board')) {
		$ap->setStatus(json_decode(json_encode($data->message->board), true));
		$em->persist($ap);
	}

	$updated = [];
	if (property_exists($data->message, 'devices')) {
		foreach ($data->message->devices as $name => $device) {
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
			$updated[] = $dev->getIfname();
		}
	}
	$em->flush();
        return new Response(json_encode(['status' => 0, 'devices_updated' => $updated]));
    }

    /**
     * @Route("/api/device/event")
     */
    public function eventHandler(Request $request) {
	$em = $this->doctrine->getManager();
	$data = json_decode($request->getContent());
	if ($data === NULL) {
            return new Response('Empty Message');
	}
	if (!property_exists($data, 'message') || empty($data->message)) {
            return new Response('Empty Message');
	}
	if (!property_exists($data, 'hostname') || empty($data->hostname)) {
            return new Response('Empty hostname');
	}
	if (!property_exists($data, 'instance') || empty($data->instance)) {
            return new Response('Empty instance');
	}
	if (!property_exists($data, 'method') || empty($data->method)) {
            return new Response('Empty method');
	}
	$ifname = substr($data->instance, strpos($data->instance, '.')+1);
	$query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		WHERE d.ifname = :ifname
		AND (a.name = :apname OR a.name = :apname_short)
	");
	$query->setParameter('apname', $data->hostname);
	$query->setParameter('apname_short', substr($data->hostname, 0, strpos($data->hostname, '.')));
	$query->setParameter('ifname', $ifname);
	$device = $query->getOneOrNullResult();
	if ($device === NULL) {
		throw $this->createNotFoundException('The AP name or device is unknown.');
        }

	if ($data->method == 'probe') {
		$che = new \ApManBundle\Entity\ClientHeatMap();
		$che->setAddress($data->message->address);
		$che->setDevice($device);
		$che->setTs(new \DateTime('now'));
		$che->setEvent(json_encode($data->message, true));
		if (property_exists($data->message, 'signal')) {
			$che->setSignalstr($data->message->signal);
		}
		$em->merge($che);
	} else {
		$devent = new \ApManBundle\Entity\Event();
		$devent->setTs(new \DateTime('now'));
		$devent->setType($data->method);
		$devent->setAddress($data->message->address);
		$devent->setEvent(json_encode($data->message, true));
		$devent->setDevice($device);
		if (property_exists($data->message, 'signal')) {
			$devent->setSignalstr($data->message->signal);
		}
		$em->persist($devent);
	}
	$em->flush();
        return new Response(json_encode(['status' => 0]));
    }
}
