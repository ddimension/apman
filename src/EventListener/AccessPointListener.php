<?php

namespace ApManBundle\EventListener;

use Doctrine\ORM\Event\LifecycleEventArgs;
use ApManBundle\Service\wrtJsonRpc;

class AccessPointListener
{
     private $rpcService;

     public function __construct(wrtJsonRpc $rpcService) {
         $this->rpcService = $rpcService;
     }

     public function postLoad(LifecycleEventArgs $args)
     {
         $entity = $args->getEntity();
         if(method_exists($entity, 'setRpcService')) {
             $entity->setRpcService($this->rpcService);
         }
     }
}
