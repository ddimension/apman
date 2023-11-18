<?php

namespace ApManBundle\Service;

class DefaultFeatureService implements iFeatureService
{
    public $name = 'default';
    private $logger;
    private $doctrine;
    private $rpcService;
    private $mqttFactory;
    private $kernel;

    private $map;
    private $feature;

    /**
     * set Services.
     *
     * @return \boolean|\null
     */
    public function setServices(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
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
     * set Feature.
     *
     * @return \boolean|\null
     */
    public function setFeature(\ApManBundle\Entity\Feature $feature)
    {
        $this->feature = $feature;
    }

    /**
     * set SSID.
     *
     * @return \boolean|\null
     */
    public function setSSID(\ApManBundle\Entity\SSID $ssid)
    {
        $this->ssid = $ssid;
    }

    /**
     * set Device.
     *
     * @return \boolean|\null
     */
    public function setDevice(\ApManBundle\Entity\Device $device)
    {
        $this->device = $device;
    }

    /**
     * set SSIDFeatureMap.
     *
     * @return \boolean|\null
     */
    public function setSSIDFeatureMap(\ApManBundle\Entity\SSIDFeatureMap $map)
    {
        $this->map = $map;
        $this->feature = $map->getFeature();
    }

    /**
     * get Config.
     *
     * @return \array|\null
     */
    public function getConfig(array $config)
    {
        $this->logger->info('DefaultFeatureService:getConfig(): called.');

        $fcfg = $this->feature->getConfig();
        foreach ($fcfg as $key => $value) {
            if (is_array($value) and array_key_exists($key, $config) and is_array($config[$key])) {
                foreach ($value as $listKey => $listValue) {
                    $config[$key][$listKey] = $listValue;
                }
            } else {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * apply implementation specific constraints.
     *
     * @return \boolean|\null
     */
    public function applyConstraints()
    {
        $this->logger->info('DefaultFeatureService:applyConstraints(): called.');

        return;
        $em = $this->doctrine->getManager();
        $qb = $em->createQueryBuilder();
        $query = $em->createQuery(
            'SELECT m
			FROM ApManBundle:SSIDFeatureMap m
			WHERE m.feature = :feature
			AND m.id != :mapid'
        );
        $query->setParameter('feature', $this->feature);
        $query->setParameter('mapid', $this->map->getId());
        $maps = $query->getResult();
        if (!count($maps)) {
            $this->logger->info('DefaultFeatureService:applyConstraints(): owe map missing');
            $this->setupOweSsid();
        }

        foreach ($maps as $map) {
            $this->logger->info('DefaultFeatureService:applyConstraints(): loop.');
        }

        $this->logger->info('DefaultFeatureService:applyConstraints(): finished.');
    }

    public function getAdditionalConfig(array $config)
    {
        return null;
    }
}
