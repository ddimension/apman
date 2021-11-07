<?php

namespace ApManBundle\Service;

class StaticMACFeatureService implements iFeatureService {

	public $name = 'static_mac';
	private $logger;
	private $doctrine;
	private $rpcService;
	private $mqttFactory;
	private $kernel;

	private $map;
	private $feature;

	/**
	* set Services
	* @return \boolean|\null 
	*/
	public function setServices(
		\Psr\Log\LoggerInterface $logger, 
		\Doctrine\Persistence\ManagerRegistry $doctrine, 
		\ApManBundle\Service\wrtJsonRpc $rpcService, 
		\ApManBundle\Factory\MqttFactory $mqttFactory,
		\Symfony\Component\HttpKernel\KernelInterface $kernel
       	) {
		$this->logger = $logger;
		$this->doctrine = $doctrine;
		$this->rpcService = $rpcService;
		$this->mqttFactory = $mqttFactory;
		$this->kernel = $kernel;
	}

	/**
	* set Feature
	* @return \boolean|\null 
	*/
	public function setFeature( \ApManBundle\Entity\Feature $feature) {
		$this->feature = $feature;
	}

	/**
	* set SSID
	* @return \boolean|\null 
	*/
	public function setSSID( \ApManBundle\Entity\SSID $ssid) {
		$this->ssid = $ssid;
	}

	/**
	* set Device
	* @return \boolean|\null 
	*/
	public function setDevice( \ApManBundle\Entity\Device $device) {
		$this->device = $device;
	}

        /**
        * set SSIDFeatureMap
        * @return \boolean|\null 
        */
	public function setSSIDFeatureMap( \ApManBundle\Entity\SSIDFeatureMap $map) {
		$this->map = $map;
		$this->feature = $map->getFeature();
	}

	/**
	* get Config
	* @return \array|\null 
	*/
	public function getConfig(array $config) {
		$this->logger->info('StaticMACFeatureService:getConfig(): called.');
		return $config;
	}

	/**
        * apply implementation specific constraints
        * @return \boolean|\null
        */
	public function applyConstraints() {
		$this->logger->info('StaticMACFeatureService:applyConstraints(): called.');
		$em = $this->doctrine->getManager();
                if (empty($this->device->getAddress())) {
                        $this->device->setAddress(exec($this->kernel->getProjectDir().'/bin/randmac.pl'));
			$this->logger->info('StaticMACFeatureService:applyConstraints(): MAC assigned to device.', ['device_id' => $this->device->getId()]);
			$em->persist($this->device);
			$em->flush();
                }
		$this->logger->info('StaticMACFeatureService:applyConstraints(): finished.');
	}

}
