<?php

namespace ApManBundle\DynamicEntity;

use ApManBundle\Library\AccessPointState;
use Symfony\Component\Cache\Simple\FilesystemCache;

class AccessPoint
{
    /**
     * internal variables.
     */
    private $stateCache = null;
    private $rpcService;

    public function setRpcService($rpcService)
    {
        $this->rpcService = $rpcService;
    }

    private $cache;

    public function setCache($cache)
    {
        $this->cache = $cache;
        $this->stateCache = $this->cache->getMultipleCacheItemValues([
        'status.state['.$this->getId().']',
        'status.ap.'.$this->getId().'.board',
        'status.ap.'.$this->getId().'.info',
        ]);
    }

    /**
     * dfg.
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
        $cache->set($key, $session, $session->getExpires() - 1);

        return $session;
    }

    /**
     * get model.
     */
    public function getModel()
    {
        $key = 'status.ap.'.$this->getId().'.board';
        if (!is_array($this->stateCache) or !isset($this->stateCache[$key])) {
            return null;
        }
        $status = $this->stateCache[$key];

        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('model', $status)) {
            return null;

            return '';
        }

        return $status['model'];
    }

    /**
     * get model.
     */
    public function getKernel()
    {
        $key = 'status.ap.'.$this->getId().'.board';
        if (!is_array($this->stateCache) or !isset($this->stateCache[$key])) {
            return null;
        }
        $status = $this->stateCache[$key];
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('kernel', $status)) {
            return null;
        }

        return $status['kernel'];
    }

    /**
     * get model.
     */
    public function getCodeName()
    {
        $key = 'status.ap.'.$this->getId().'.board';
        if (!is_array($this->stateCache) or !isset($this->stateCache[$key])) {
            return null;
        }
        $status = $this->stateCache[$key];
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('release', $status)) {
            return null;
        }
        if (!array_key_exists('description', $status['release'])) {
            return null;
        }

        return $status['release']['description'];
    }

    /**
     * get system.
     */
    public function getSystem()
    {
        $key = 'status.ap.'.$this->getId().'.board';
        if (!is_array($this->stateCache) or !isset($this->stateCache[$key])) {
            return null;
        }
        $status = $this->stateCache[$key];
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('system', $status)) {
            return null;
        }

        return $status['system'];
    }

    /**
     * get Uptime.
     *
     * @return \DateTime
     */
    public function getUptime()
    {
        $key = 'status.ap.'.$this->getId().'.info';
        if (!is_array($this->stateCache) or !isset($this->stateCache[$key])) {
            return null;
        }
        $status = $this->stateCache[$key];
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('uptime', $status)) {
            return null;
        }
        $date = new \DateTime();
        $date->setTimestamp(time() - $status['uptime']);

        return $date;
    }

    /**
     * get info.
     *
     * @return \string
     */
    public function getLoad()
    {
        $key = 'status.ap.'.$this->getId().'.info';
        if (!is_array($this->stateCache) or !isset($this->stateCache[$key])) {
            return null;
        }
        $status = $this->stateCache[$key];
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('uptime', $status)) {
            return null;
        }
        $d = [];
        foreach ($status['load'] as $load) {
            $d[] = sprintf('%0.02f', $load / 100000);
        }

        return join(', ', $d);
    }

    /**
     * get info.
     *
     * @return \?string
     */
    public function getState()
    {
        $key = 'status.state['.$this->getId().']';
        if (!is_array($this->stateCache) or !isset($this->stateCache[$key])) {
            return 'Unknown';
        }
        $state = $this->stateCache[$key];

        return AccessPointState::getStateName($state);
    }
}
