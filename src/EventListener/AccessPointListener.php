<?php

namespace ApManBundle\EventListener;

use ApManBundle\Factory\CacheFactory;
use ApManBundle\Service\wrtJsonRpc;
use Doctrine\ORM\Event\LifecycleEventArgs;

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

    public function postLoad(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if (method_exists($entity, 'setRpcService')) {
            $entity->setRpcService($this->rpcService);
        }
        if (method_exists($entity, 'setCache')) {
            $entity->setCache($this->cacheFactory);
        }
    }
}
