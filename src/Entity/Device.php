<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Device
 *
 * @ORM\Table(name="device")
 * @ORM\Entity
 */
class Device extends \ApManBundle\DynamicEntity\Device
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
     * @ORM\Column(name="name", type="string", nullable=true)
     */
    private $name;

    /**
     * @var array|null
     *
     * @ORM\Column(name="config", type="array", nullable=true)
     */
    private $config;

    /**
     * @var \ApManBundle\Entity\Radio
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\Radio", inversedBy="devices", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="radio_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    private $radio;

    /**
     * @var \ApManBundle\Entity\SSID
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\SSID", inversedBy="devices", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="ssid_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    private $ssid;

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
     * @return Device
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
     * Set config
     *
     * @param array $config
     *
     * @return Device
     */
    public function setConfig($config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get config
     *
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Set radio
     *
     * @param \ApManBundle\Entity\Radio $radio
     *
     * @return Device
     */
    public function setRadio(\ApManBundle\Entity\Radio $radio)
    {
        $this->radio = $radio;

        return $this;
    }

    /**
     * Get radio
     *
     * @return \ApManBundle\Entity\Radio
     */
    public function getRadio()
    {
        return $this->radio;
    }

    /**
     * Set ssid
     *
     * @param \ApManBundle\Entity\SSID $ssid
     *
     * @return Device
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
