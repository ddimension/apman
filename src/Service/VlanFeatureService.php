<?php

namespace ApManBundle\Service;

class VlanFeatureService implements iFeatureService
{
    public $name = 'vlan';
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
        $this->logger->info('VlanFeatureService:getConfig(): called.');
        $config['dynamic_vlan'] = 1;
        $fcfg = $this->feature->getConfig();

        return $config;
    }

    public function getAdditionalConfig(array $config)
    {
        $this->logger->info('VlanFeatureService:getAdditionalConfig(): called.');

        /* Example Structure
        $res = [];
        $cfg = new \stdClass();
            $cfg->config = 'wireless';
            $cfg->type = 'wifi-vlan';
        #$cfg->name = $device->getName();
        $cfg->values = [];
        $cfg->values['name'] ='ops';
        $cfg->values['iface'] = $this->device->getName();
        $cfg->values['vid'] = 22;
        $cfg->values['network'] = 'opennet';
        $res[] = $cfg;

        $cfg = new \stdClass();
            $cfg->config = 'wireless';
            $cfg->type = 'wifi-station';
        #$cfg->name = $device->getName();
        $cfg->values = [];
        $cfg->values['iface'] = $this->device->getName();
        $cfg->values['vid'] = 22;
        $cfg->values['mac'] = '00:00:00:00:00:00';
        $cfg->values['key'] = 'sdg3w46q34tysdfgggg54weardsyg3<355';
        $res[] = $cfg;
        echo json_encode($res);
         */

        $fcfg = $this->feature->getConfig();
        foreach ($fcfg as $i => $entry) {
            if (!array_key_exists('values', $entry)) {
                continue;
            }
            if (!is_array($entry['values'])) {
                continue;
            }
            $fcfg[$i]['values']['iface'] = $this->device->getName();
        }

        return $fcfg;
    }

    /**
     * apply implementation specific constraints.
     *
     * @return \boolean|\null
     */
    public function applyConstraints()
    {
        $this->logger->info('VlanFeatureService:applyConstraints(): called.');
    }
}
