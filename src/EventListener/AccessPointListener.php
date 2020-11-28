<?php

namespace ApManBundle\EventListener;

use Doctrine\ORM\Event\LifecycleEventArgs;
use ApManBundle\Service\wrtJsonRpc;
use ApManBundle\Service\SubscriptionService;

class AccessPointListener
{
     private $rpcService;
     private $ssrv;

     public function __construct(wrtJsonRpc $rpcService, SubscriptionService $ssrv) {
         $this->rpcService = $rpcService;
         $this->ssrv = $ssrv;
     }

     public function postLoad(LifecycleEventArgs $args)
     {
         $entity = $args->getEntity();
         if(method_exists($entity, 'setRpcService')) {
             $entity->setRpcService($this->rpcService);
         }
         if(method_exists($entity, 'setCache')) {
             $entity->setCache($this->ssrv);
         }
     }
}
