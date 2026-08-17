<?php

namespace ApManBundle\EventListener;

use ApManBundle\Factory\CacheFactory;
use ApManBundle\Service\wrtJsonRpc;
use Doctrine\ORM\Event\PostLoadEventArgs;

class AccessPointListener
{
    private $rpcService;
    private $cacheFactory;

    public function __construct(wrtJsonRpc $rpcService, CacheFactory $cacheFactory)
    {
        $this->rpcService = $rpcService;
        $this->cacheFactory = $cacheFactory;
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
    }
}
