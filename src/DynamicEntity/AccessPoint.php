<?php

namespace ApManBundle\DynamicEntity;

use Symfony\Component\Cache\Simple\FilesystemCache;

class AccessPoint
{
    /**
     * internal variable
     */ 
    private $rpcService;

    public function setRpcService($rpcService) {
	    $this->rpcService = $rpcService;
    }

    /**
     * dfg
     */
    public function getSession()
    {
	$cache = new FilesystemCache();
        $key = 'session_'.$this->getName(); 
	if ($cache->has($key)) {
		return $cache->get($key);
	}
	$session = $this->rpcService->login($this->getUbusUrl(), $this->getUsername(), $this->getPassword());
	if (!$session) {
		return false;
	}
	$cache->set($key, $session, $session->getExpires()-1);
	return $session;
    }

    /**
     * get model
     */
    public function getModel()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->model;
    }
    /**
     * get model
     */
    public function getSystem()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->system;
    }
    /**
     * get model
     */
    public function getKernel()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->kernel;
    }

    /**
     * get model
     */
    public function getCodeName()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->release->version;
    }

    /**
     * get Uptime
     * @return \DateTime
     */
    public function getUptime()
    {
        if (!($session = $this->getSession())) {
		$date = new \DateTime();
		$date->setTimestamp(0);
		return $date;
	}
	$data = $session->callCached('system','info', null, 10);
	if (!$data || !isset($data->uptime)) return '0';
	$date = new \DateTime();
	$date->setTimestamp(time()-$data->uptime);
	return $date;

	#return $data->uptime;
    }

    /**
     * get info
     * @return \string
     */
    public function getLoad()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','info', null, 10);
	if (!$data || !isset($data->load)) return '0';
	$d = array();
	foreach ($data->load as $load) {
		$d[] = sprintf('%0.02f', $load/100000);
	}
	return join(', ', $d);
    }
    
}
