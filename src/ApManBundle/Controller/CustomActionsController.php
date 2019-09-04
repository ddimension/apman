<?php

namespace ApManBundle\Controller;

use Sonata\AdminBundle\Controller\CRUDController;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CustomActionsController extends CRUDController
{

    public function batchActionConfigure(ProxyQueryInterface $selectedModelQuery, Request $request)
    {

	$selectedModels = $selectedModelQuery->execute();
        foreach ($selectedModels as $ap) {
        	$this->container->get('apman.accesspointservice')->publishConfig($ap);
	}
	$this->addFlash('sonata_flash_success', 'Reconfigured.');
	return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }	    

    public function batchActionConfigureAndRestart(ProxyQueryInterface $selectedModelQuery, Request $request)
    {

	$selectedModels = $selectedModelQuery->execute();
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
		$session = $ap->getSession();
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

	$selectedModels = $selectedModelQuery->execute();
        foreach ($selectedModels as $ap) {
		$session = $ap->getSession();
		if ($session === false) {
			$this->addFlash('sonata_flash_error', "Cannot connect to AP ".$ap->getName());
			return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
		}
		$opts = new \stdClass();
		$opts->command = 'reboot';
		$opts->params = array();
		$stat = $session->call('file','exec', $opts);
	}
	$this->addFlash('sonata_flash_success', 'Reboot initiated.');
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
	$session = $ap->getSession();
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
	$session = $ap->getSession();
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
	$session = $ap->getSession();
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
	$session = $object->getAccessPoint()->getSession();
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
	$session = $object->getAccessPoint()->getSession();
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
	return $this->render('ApManBundle:Default:neighbors.html.twig', array('neighbors' => $stat->results));
    }

}
