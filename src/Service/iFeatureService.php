<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;

interface iFeatureService {

	/**
	* set Services
	* @return \boolean|\null 
	*/
	public function setServices(
		\Psr\Log\LoggerInterface $logger, 
		\Doctrine\Persistence\ManagerRegistry $doctrine, 
		\ApManBundle\Service\wrtJsonRpc $rpcService, 
		\ApManBundle\Service\SubscriptionService $ssrv,
		\Symfony\Component\HttpKernel\KernelInterface $kernel
       	);

	/**
	* set Feature
	* @return \boolean|\null 
	*/
	public function setFeature( \ApManBundle\Entity\Feature $feature);

	/**
	* set SSID
	* @return \boolean|\null 
	*/
	public function setSSID( \ApManBundle\Entity\SSID $ssid);

	/**
	* set Device
	* @return \boolean|\null 
	*/
	public function setDevice( \ApManBundle\Entity\Device $device);

	/**
	* set SSIDFeatureMap
	* @return \boolean|\null 
	*/
	public function setSSIDFeatureMap( \ApManBundle\Entity\SSIDFeatureMap $featuremap);

	/**
	* apply implementation specific constraints
	* @return \boolean|\null 
	*/
	public function applyConstraints();

	/**
	* get Config
	* @return \array|\null 
	*/
	public function getConfig(array $config);
}
