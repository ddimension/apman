<?php

namespace ApManBundle\DynamicEntity;

use Symfony\Component\Cache\Simple\FilesystemCache;

class AccessPoint
{
    /**
     * internal variables
     */ 
    private $stateCache = null;
    private $rpcService;

    public function setRpcService($rpcService) {
	    $this->rpcService = $rpcService;
    }

    private $cache;

    public function setCache($cache) {
	    $this->cache = $cache;
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
	if (is_null($this->stateCache)) {
		$key = 'status.ap.'.$this->getId();
		$status = $this->cache->getCacheItemValue($key);
		$this->stateCache = $status;
	} else {
		$status = $this->stateCache;
	}
	if (!is_array($status)) {
            return null;
        }
	if (!array_key_exists('board', $status)) {
	    return null;
	}
	if (!is_array($status['board'])) {
	    return null;
	}
        if (!array_key_exists('model', $status['board'])) {
            return null;
            return '';
        }
	return $status['board']['model'];
    }
    
    /**
     * get model
     */
    public function getKernel()
    {
	if (is_null($this->stateCache)) {
		$key = 'status.ap.'.$this->getId();
		$status = $this->cache->getCacheItemValue($key);
		$this->stateCache = $status;
	} else {
		$status = $this->stateCache;
	}
        if (!is_array($status)) {
            return null;
        }
	if (!array_key_exists('board', $status)) {
	    return null;
	}
	if (!is_array($status['board'])) {
	    return null;
	}
        if (!array_key_exists('kernel', $status['board'])) {
            return null;
        }
	return $status['board']['kernel'];
    }

    /**
     * get model
     */
    public function getCodeName()
    {
	if (is_null($this->stateCache)) {
		$key = 'status.ap.'.$this->getId();
		$status = $this->cache->getCacheItemValue($key);
		$this->stateCache = $status;
	} else {
		$status = $this->stateCache;
	}
        if (!is_array($status)) {
            return null;
        }
	if (!array_key_exists('board', $status)) {
	    return null;
	}
	if (!is_array($status['board'])) {
	    return null;
	}
        if (!array_key_exists('release', $status['board'])) {
            return null;
        }
	return $status['board']['release']['version'];
    }

    /**
     * get system
     */
    public function getSystem()
    {
	if (is_null($this->stateCache)) {
		$key = 'status.ap.'.$this->getId();
		$status = $this->cache->getCacheItemValue($key);
		$this->stateCache = $status;
	} else {
		$status = $this->stateCache;
	}
        if (!is_array($status)) {
            return null;
        }
	if (!array_key_exists('board', $status)) {
	    return null;
	}
	if (!is_array($status['board'])) {
	    return null;
	}
        if (!array_key_exists('system', $status['board'])) {
            return null;
        }
	return $status['board']['system'];
    }

    /**
     * get Uptime
     * @return \DateTime
     */
    public function getUptime()
    {
	if (is_null($this->stateCache)) {
		$key = 'status.ap.'.$this->getId();
		$status = $this->cache->getCacheItemValue($key);
		$this->stateCache = $status;
	} else {
		$status = $this->stateCache;
	}
        if (!is_array($status)) {
            return null;
        }
	if (!array_key_exists('info', $status)) {
	    return null;
	}
	if (!is_array($status['info'])) {
	    return null;
	}
        if (!array_key_exists('uptime', $status['info'])) {
            return null;
        }
	$date = new \DateTime();
	$date->setTimestamp(time()-$status['info']['uptime']);
	return $date;
    }

    /**
     * get info
     * @return \string
     */
    public function getLoad()
    {
	if (is_null($this->stateCache)) {
		$key = 'status.ap.'.$this->getId();
		$status = $this->cache->getCacheItemValue($key);
		$this->stateCache = $status;
	} else {
		$status = $this->stateCache;
	}
        if (!is_array($status)) {
            return null;
        }
	if (!array_key_exists('info', $status)) {
	    return null;
	}
	if (!is_array($status['info'])) {
	    return null;
	}
        if (!array_key_exists('uptime', $status['info'])) {
            return null;
        }
	$d = array();
	foreach ($status['info']['load'] as $load) {
		$d[] = sprintf('%0.02f', $load/100000);
	}
	return join(', ', $d);
    }
}
