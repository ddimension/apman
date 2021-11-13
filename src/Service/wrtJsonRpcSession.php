<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;

class wrtJsonRpcSession
{
    private $session = [];
    private $url;
    private $user;
    private $password;
    private $rpcService;

    public function __construct($url, $session, $user, $password)
    {
        $this->url = $url;
        $this->session = $session;
        $this->user = $user;
        $this->password = $password;
    }

    public function setRpcService($rpcService)
    {
        $this->rpcService = $rpcService;
    }

    public function getSessionId()
    {
        return $this->session->ubus_rpc_session;
    }

    public function getExpires()
    {
        return $this->session->expires;
    }

    public function reConnect()
    {
        return $this->rpcService->login($this->url, $this->user, $this->password);
    }

    public function call($namespace, $procedure, $arguments = null)
    {
        return $this->rpcService->call($this->url, $this->session, $namespace, $procedure, $arguments);
    }

    public function callCached($namespace, $procedure, $arguments = null, $ttl = 300)
    {
        $cache = new FilesystemCache();
        $key = 'jsonrpccall.'.hash('sha256', serialize([$this->url, $namespace, $procedure, $arguments]));
        if ($cache->has($key)) {
            return $cache->get($key);
        }
        $result = $this->rpcService->call($this->url, $this->session, $namespace, $procedure, $arguments);
        $cache->set($key, $result, $ttl);

        return $result;
    }

    public function invalidateCache($namespace, $procedure, $arguments = null, $ttl = 300)
    {
        $cache = new FilesystemCache();
        $key = 'jsonrpccall.'.hash('sha256', serialize([$this->url, $namespace, $procedure, $arguments]));

        return $cache->deleteItem($key);
    }
}
