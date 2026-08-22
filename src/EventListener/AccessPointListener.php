<?php

namespace ApManBundle\EventListener;

use ApManBundle\Factory\CacheFactory;
use ApManBundle\Service\ApUbusService;
use ApManBundle\Service\wrtJsonRpc;
use Doctrine\ORM\Event\PostLoadEventArgs;

class AccessPointListener
{
    private $rpcService;
    private $cacheFactory;
    private $ubus;

    public function __construct(wrtJsonRpc $rpcService, CacheFactory $cacheFactory, ApUbusService $ubus)
    {
        $this->rpcService = $rpcService;
        $this->cacheFactory = $cacheFactory;
        $this->ubus = $ubus;
        $this->cacheFactory->getCache();
    }

    /**
     * ORM 3 hands each lifecycle event its own argument class instead of one
     * shared LifecycleEventArgs, and getEntity() went with the old class —
     * getObject() says the same thing.
     */
    public function postLoad(PostLoadEventArgs $args)
    {
        $entity = $args->getObject();
        if (method_exists($entity, 'setRpcService')) {
            $entity->setRpcService($this->rpcService);
        }
        if (method_exists($entity, 'setCache')) {
            $entity->setCache($this->cacheFactory);
        }
        // An entity cannot ask the container for anything, so the one call path
        // is handed to it here the same way the raw rpc client always has been.
        if (method_exists($entity, 'setUbusService')) {
            $entity->setUbusService($this->ubus);
        }
    }
}
