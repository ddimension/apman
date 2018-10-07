<?php

namespace ApManBundle\Entity;

/**
 * SSIDConfigListOption
 */
class SSIDConfigListOption
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $value;

    /**
     * @var \ApManBundle\Entity\SSIDConfigList
     */
    private $ssid_config_list;


    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set value
     *
     * @param string $value
     *
     * @return SSIDConfigListOption
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get value
     *
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set ssidConfigList
     *
     * @param \ApManBundle\Entity\SSIDConfigList $ssidConfigList
     *
     * @return SSIDConfigListOption
     */
    public function setSsidConfigList(\ApManBundle\Entity\SSIDConfigList $ssidConfigList)
    {
        $this->ssid_config_list = $ssidConfigList;

        return $this;
    }

    /**
     * Get ssidConfigList
     *
     * @return \ApManBundle\Entity\SSIDConfigList
     */
    public function getSsidConfigList()
    {
        return $this->ssid_config_list;
    }
}
