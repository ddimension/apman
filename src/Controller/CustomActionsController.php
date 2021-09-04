<?php

namespace ApManBundle\Controller;

use Sonata\AdminBundle\Controller\CRUDController;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CustomActionsController extends CRUDController
{
    private $rpcService;
    private $ssrv;

    function __construct(\ApManBundle\Service\wrtJsonRpc $rpcService, \ApManBundle\Service\SubscriptionService $ssrv, \Psr\Log\LoggerInterface $logger) 
    {
    	    $this->rpcService = $rpcService;
	    $this->ssrv = $ssrv;
	    $this->logger = $logger;
    }
    

    public function batchActionConfigure(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
	$selectedModels = $selectedModelQuery->execute();
        $client = $this->ssrv->getMqttClient();
	if ($client) {
		$deadline = 2;
		$haveClients = false;
		foreach ($selectedModels as $ap) {
			foreach ($ap->getRadios() as $radio) {
				foreach ($radio->getDevices() as $device) {
					$status = $this->ssrv->getCacheItemValue('status.device.'.$device->getId());
					if (is_array($status) && isset($status['assoclist']) && is_array($status['assoclist']) && count($status['assoclist']) && isset($status['assoclist']['results'])) {
						foreach ($status['assoclist']['results'] as $c) {
							if (!count($c)) continue;
							$haveClients = true;
							$opts = new \stdClass();
							$opts->addr = $c['mac'];
							$opts->duration = $deadline*10;
							$opts->abridged = true;
							$opts->neighbors = array();
							foreach ($device->getSsid()->getDevices() as $neighbor) {
								if ($neighbor->getRadio()->getAccessPoint() == $ap) continue;
								$rrm = $neighbor->getRrm();
								$rrm = json_decode(json_encode($rrm));
								if (is_object($rrm) && property_exists($rrm, 'value') && is_array($rrm->value) && isset($rrm->value[2])) {
									$opts->neighbors[] = $rrm->value[2];
								}
							}
							$topic = 'apman/ap/'.$ap->getName().'/command';
							$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'wnm_disassoc_imminent', $opts);
							$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
						
							$res = $client->publish($topic, json_encode($cmd));
						}
					}
				}
			}
		}
		$client->loop(100);
		// Wait for evacuation
		if ($haveClients) sleep($deadline);
	}

	foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->publishConfig($ap);
	}

	$this->addFlash('sonata_flash_success', 'Reconfigured.');
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    

    public function batchActionConfigureAndRestart(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
	$selectedModels = $selectedModelQuery->execute();

        $client = $this->ssrv->getMqttClient();
	if ($client) {
		$deadline = 2;
		$haveClients = false;
		foreach ($selectedModels as $ap) {
			foreach ($ap->getRadios() as $radio) {
				foreach ($radio->getDevices() as $device) {
					$status = $this->ssrv->getCacheItemValue('status.device.'.$device->getId());
					if (is_array($status) && isset($status['assoclist']) && is_array($status['assoclist']) && count($status['assoclist']) && isset($status['assoclist']['results'])) {
						foreach ($status['assoclist']['results'] as $c) {
							if (!count($c)) continue;
							$haveClients = true;
							$opts = new \stdClass();
							$opts->addr = $c['mac'];
							$opts->duration = $deadline*10;
							$opts->abridged = true;
							$opts->neighbors = array();
							foreach ($device->getSsid()->getDevices() as $neighbor) {
								if ($neighbor->getRadio()->getAccessPoint() == $ap) continue;
								$rrm = $neighbor->getRrm();
								$rrm = json_decode(json_encode($rrm));
								if (is_object($rrm) && property_exists($rrm, 'value') && is_array($rrm->value) && isset($rrm->value[2])) {
									$opts->neighbors[] = $rrm->value[2];
								}
							}
							$topic = 'apman/ap/'.$ap->getName().'/command';
							$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'wnm_disassoc_imminent', $opts);
							$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
						
							$res = $client->publish($topic, json_encode($cmd));
						}
					}
				}
			}
		}
		$client->loop(100);
		// Wait for evacuation
		if ($haveClients) sleep($deadline);
	}

        foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->stopRadio($ap);
	}
        foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->publishConfig($ap);
	}
        foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->startRadio($ap);
	}
	$this->addFlash('sonata_flash_success', 'Reconfigured and Restarted successfully');
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    

    public function batchActionStopRadio(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
	$selectedModels = $selectedModelQuery->execute();

	$client = $this->ssrv->getMqttClient();
	if ($client) {
		$deadline = 2;
		$haveClients = false;
		foreach ($selectedModels as $ap) {
			foreach ($ap->getRadios() as $radio) {
				foreach ($radio->getDevices() as $device) {
					$status = $this->ssrv->getCacheItemValue('status.device.'.$device->getId());
					if (is_array($status) && isset($status['assoclist']) && is_array($status['assoclist']) && count($status['assoclist']) && isset($status['assoclist']['results'])) {
						foreach ($status['assoclist']['results'] as $c) {
							if (!count($c)) continue;
							$haveClients = true;
							$opts = new \stdClass();
							$opts->addr = $c['mac'];
							$opts->duration = $deadline*10;
							$opts->abridged = true;
							$opts->neighbors = array();
							foreach ($device->getSsid()->getDevices() as $neighbor) {
								if ($neighbor->getRadio()->getAccessPoint() == $ap) continue;
								$rrm = $neighbor->getRrm();
								$rrm = json_decode(json_encode($rrm));
								if (is_object($rrm) && property_exists($rrm, 'value') && is_array($rrm->value) && isset($rrm->value[2])) {
									$opts->neighbors[] = $rrm->value[2];
								}
							}
							$topic = 'apman/ap/'.$ap->getName().'/command';
							$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device->getIfname(), 'wnm_disassoc_imminent', $opts);
							$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
						
							$res = $client->publish($topic, json_encode($cmd));
						}
					}
				}
			}
		}
		$client->loop(100);
		// Wait for evacuation
		if ($haveClients) sleep($deadline);
	}


        foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->stopRadio($ap);
	}
	$this->addFlash('sonata_flash_success', 'Stopped radios.');
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    

    public function batchActionStartRadio(ProxyQueryInterface $selectedModelQuery, Request $request)
    {

	$selectedModels = $selectedModelQuery->execute();
        foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->startRadio($ap);
	}
	$this->addFlash('sonata_flash_success', 'Started radios.');
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    

    public function batchActionWiFiRestart(ProxyQueryInterface $selectedModelQuery, Request $request)
    {

	$selectedModels = $selectedModelQuery->execute();
        foreach ($selectedModels as $ap) {
		$session = $this->rpcService->getSession($ap);
		if ($session === false) {
			$this->addFlash('sonata_flash_error', "Cannot connect to AP ".$ap->getName());
			return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
		}
		$opts = new \stdClass();
		$opts->command = 'wifi';
		$opts->params = array('reload');
		$stat = $session->call('file','exec', $opts);
	}
	$this->addFlash('sonata_flash_success', 'Called \"wifi restart\".');
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    
   
    public function batchActionReboot(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
	$client = $this->ssrv->getMqttClient();
	if (!$client) {
		$this->logger->error($ap->getName().': Failed to get mqtt client.');
		$this->addFlash('sonata_flash_error', "Cannot connect to mqtt for ".$ap->getName());
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}

	$selectedModels = $selectedModelQuery->execute();
	foreach ($selectedModels as $ap) {
		$topic = 'apman/ap/'.$ap->getName().'/command';
		$opts = new \stdClass();
	        $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'system', 'reboot', $opts);
		$this->logger->info($ap->getName().': Sent reboot command.');
        	$res = $client->publish($topic, json_encode($cmd));
	        $client->loop(1);
	}
	$this->addFlash('sonata_flash_success', 'Reboot initiated.');
	$client->disconnect();
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    


    public function batchActionRefreshRadios(ProxyQueryInterface $selectedModelQuery, Request $request)
    {

	$selectedModels = $selectedModelQuery->execute();
        foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->refreshRadios($ap);
	}
	$this->addFlash('sonata_flash_success', 'Refreshed initiated.');
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    

    /**
     * @param $id
     */
    public function syslogAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
	$ap = $object;
	header('Content-Type: text/plain');
	$session = $this->rpcService->getSession($ap);
	if ($session === false) {
		$this->addFlash('sonata_flash_error', 'Failed to get session.');
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}
	$opts = new \stdClass();
	$opts->command = 'logread';
	$opts->params = array('-l','1000');
	$stat = $session->call('file','exec', $opts);
	if (isset($stat->stdout)) 
		echo $stat->stdout;
	exit();
        #return new RedirectResponse($this->admin->generateUrl('list'));

    }

    /**
     * @param $id
     */
    public function loginAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
	$ap = $object;
	$session = $this->rpcService->getSession($ap);
	if ($session === false) {
		$this->addFlash('sonata_flash_error', 'Failed to get session.');
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}
	$opts = new \stdClass();
	$opts->values = new \stdClass();
	$opts->values->user = 'root';
	$opts->values->token = substr(hash('sha256', uniqid().microtime()),0,32);
	$opts->values->section = substr(hash('sha256', uniqid().microtime()),0,32);
	$stat = $session->call('session','set', $opts);
	$url = $ap->getUbusUrl();
	$url = str_replace('/ubus','/cgi-bin/luci/?sysauth='.$session->getSessionId(), $url);
        return new RedirectResponse($url);
    }

    /**
     * @param $id
     */
    public function lldpAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
	$ap = $object;
	header('Content-Type: text/plain');
	$session = $this->rpcService->getSession($ap);
	if ($session === false) {
		$this->addFlash('sonata_flash_error', 'Failed to get session.');
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}
	$opts = new \stdClass();
	$opts->command = 'lldpcli';
	$opts->params = array('show','neighbors');
	$stat = $session->call('file','exec', $opts);
	if (isset($stat->stdout)) 
		echo $stat->stdout;
	exit();
        #return new RedirectResponse($this->admin->generateUrl('list'));

    }

    /**
     * @param $id
     */
    public function radioStatusAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
	header('Content-Type: text/plain');
	$session = $this->rpcService->getSession($object->getAccessPoint());
	if ($session === false) {
		$this->addFlash('sonata_flash_error', 'Failed to get session.');
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}
	$opts = new \stdClass();
	$opts->device = $object->getName();
	$stat = $session->call('iwinfo','info', $opts);
	print_r($stat);

	echo "\n";
	echo "#########################\n";
        echo "# Phy Info ".$stat->phy."\n";
	echo "#########################\n";
	echo "\n";
	$opts = new \stdClass();
	$opts->command = 'iw';
	$opts->params = array('phy',$stat->phy,'info');
	$stat = $session->call('file','exec', $opts);
	if (isset($stat->stdout)) 
		echo $stat->stdout;
	exit();
    }

    /**
     * @param $id
     */
    public function radioNeighborsAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
	header('Content-Type: text/plain');
	$session = $this->rpcService->getSession($object->getAccessPoint());
	if ($session === false) {
		$this->addFlash('sonata_flash_error', 'Failed to get session.');
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}
	$opts = new \stdClass();
	$opts->device = $object->getName();
	$stat = $session->call('iwinfo','scan', $opts);
	if (!isset($stat->results)) {
		$this->addFlash('sonata_flash_error', 'Failed to scan.: '.print_r($stat,true));
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}
	return $this->render('default/neighbors.html.twig', array('neighbors' => $stat->results));
    }

}
