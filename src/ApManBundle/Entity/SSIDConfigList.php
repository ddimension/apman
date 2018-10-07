<?php

namespace ApManBundle\Entity;

/**
 * SSIDConfigList
 */
class SSIDConfigList
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $options;

    /**
     * @var \ApManBundle\Entity\SSID
     */
    private $ssid;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->options = new \Doctrine\Common\Collections\ArrayCollection();
    }

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
     * Set name
     *
     * @param string $name
     *
     * @return SSIDConfigList
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Add option
     *
     * @param \ApManBundle\Entity\SSIDConfigListOption $option
     *
     * @return SSIDConfigList
     */
    public function addOption(\ApManBundle\Entity\SSIDConfigListOption $option)
    {
        $this->options[] = $option;

        return $this;
    }

    /**
     * Remove option
     *
     * @param \ApManBundle\Entity\SSIDConfigListOption $option
     */
    public function removeOption(\ApManBundle\Entity\SSIDConfigListOption $option)
    {
        $this->options->removeElement($option);
    }

    /**
     * Get options
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set ssid
     *
     * @param \ApManBundle\Entity\SSID $ssid
     *
     * @return SSIDConfigList
     */
    public function setSsid(\ApManBundle\Entity\SSID $ssid)
    {
        $this->ssid = $ssid;

        return $this;
    }

    /**
     * Get ssid
     *
     * @return \ApManBundle\Entity\SSID
     */
    public function getSsid()
    {
        return $this->ssid;
    }
}
