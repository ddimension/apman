<?php

namespace ApManBundle\DynamicEntity;

use ApManBundle\Library\AccessPointState;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

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

    private $ubusService;

    public function setUbusService($ubusService)
    {
        $this->ubusService = $ubusService;
    }

    /**
     * One ubus call, whichever way this access point is reached.
     *
     * The entity used to log in itself — getSession() below — and ask over
     * HTTP, which meant an admin list page opened a session to every access
     * point in it. This goes through the same switch as everything else, so an
     * access point set to mqtt is asked over mqtt from here too.
     *
     * Answers are cached, because the six getters underneath ask `iwinfo info`
     * six times for six properties of one radio.
     */
    public function ubus(string $object, string $method, $args = null, int $ttl = 300)
    {
        if (!$this->ubusService) {
            return false;
        }

        return $this->ubusService->callCached($this, $object, $method, $args, $ttl);
    }

    private $cache;

    public function setCache($cache)
    {
        $this->cache = $cache;
        $this->stateCache = $this->cache->getMultipleCacheItemValues([
        'status.state['.$this->getId().']',
        'state.ap.composed['.$this->getId().']',
        'status.ap.'.$this->getId().'.board',
        'status.ap.'.$this->getId().'.info',
        ]);
    }

    /**
     * dfg.
     */
    public function getSession()
    {
        $cache = new Psr16Cache(new FilesystemAdapter());
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

    /**
     * What the state tree makes of this access point — its radios and their
     * bsses composed. Sits next to getState() while the two are compared;
     * getState() is the flat machine and goes when the tree replaces it.
     */
    public function getTreeState()
    {
        $key = 'state.ap.composed['.$this->getId().']';
        $node = is_array($this->stateCache) ? ($this->stateCache[$key] ?? null) : null;
        if (!is_array($node)) {
            return 'Unknown';
        }

        return \ApManBundle\Library\NodeState::name(
            \ApManBundle\Library\NodeState::TYPE_AP, $node['state'] ?? null);
    }

    /** how long it has been in that state, in seconds, or null */
    public function getTreeStateSince()
    {
        $key = 'state.ap.composed['.$this->getId().']';
        $node = is_array($this->stateCache) ? ($this->stateCache[$key] ?? null) : null;
        if (!is_array($node) || empty($node['since'])) {
            return null;
        }

        return time() - (int) $node['since'];
    }
}
