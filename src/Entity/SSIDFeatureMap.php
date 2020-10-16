<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * SSIDFeatureMap
 *
 * @ORM\Table(name="ssid_feature_map")
 * @ORM\Entity
 */
class SSIDFeatureMap
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string|null
     *
     * @ORM\Column(name="name", type="string", length=64, nullable=false)
     */
    private $name;

    /**
     * @var array|null
     *
     * @ORM\Column(name="config", type="array", nullable=false)
     */
    private $config = array();

    /**
     * @var \ApManBundle\Entity\SSID
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\SSID", inversedBy="feature_maps", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="ssid_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    private $ssid;

    /**
     * @var \ApManBundle\Entity\Feature
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\Feature", inversedBy="feature_maps", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="feature_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    private $feature;

    /**
     * @var integer|null
     *
     * @ORM\Column(name="priority", type="integer", length=64, nullable=false)
     */
    private $priority = 0;


    /**
     * Constructor
     */
    public function __construct()
    {
    }

    /**
     * @return string
     */
    public function __toString() {
	    return (string)$this->getName();
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
     * @return SSIDFeatureMap
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
     * Add config
     *
     * @param array $config
     *
     * @return SSIDFeatureMap
     */
    public function setConfig(array $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get configOptions
     *
     * @return \array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Set ssid
     *
     * @param \ApManBundle\Entity\SSID $ssid
     *
     * @return SSIDFeatureMap
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

    /**
     * Set feature
     *
     * @param \ApManBundle\Entity\Feature $feature
     *
     * @return SSIDFeatureMap
     */
    public function setFeature(\ApManBundle\Entity\Feature $feature)
    {
        $this->feature = $feature;

        return $this;
    }

    /**
     * Get feature
     *
     * @return \ApManBundle\Entity\Feature
     */
    public function getFeature()
    {
        return $this->feature;
    }

    /**
     * Set priority
     *
     * @param integer $priority
     *
     * @return SSIDFeatureMap
     */
    public function setPriority($priority)
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Get priority
     *
     * @return integer
     */
    public function getPriority()
    {
        return $this->priority;
    }
}
