<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Device.
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
     * @var string|null
     *
     * @ORM\Column(name="ifname", type="string", nullable=true)
     */
    private $ifname;

    /**
     * @var string
     *
     * @ORM\Column(name="address", type="string", length=17, nullable=true)
     */
    private $address;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private $status = [];

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private $rrm = [];

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
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
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set config.
     *
     * @param array $config
     *
     * @return Device
     */
    public function setConfig($config)
    {
        unset($config['ifname']);
        unset($config['macaddr']);
        unset($config['macaddress']);
        $this->config = $config;

        return $this;
    }

    /**
     * Get config.
     *
     * @return array
     */
    public function getConfig()
    {
        $config = $this->config;
        if (!empty($this->ifname)) {
            $config['ifname'] = $this->ifname;
        }
        if (!empty($config['ifname'])) {
            $config['ifname'] = 'wlan-d'.$this->getId();
        }
        if (!empty($this->address)) {
            $config['macaddress'] = $this->address;
        }

        return $config;
    }

    /**
     * Set radio.
     *
     * @param \ApManBundle\Entity\Radio $radio
     *
     * @return Device
     */
    public function setRadio(Radio $radio)
    {
        $this->radio = $radio;

        return $this;
    }

    /**
     * Get radio.
     *
     * @return \ApManBundle\Entity\Radio
     */
    public function getRadio()
    {
        return $this->radio;
    }

    /**
     * Set ssid.
     *
     * @param \ApManBundle\Entity\SSID $ssid
     *
     * @return Device
     */
    public function setSsid(SSID $ssid)
    {
        $this->ssid = $ssid;

        return $this;
    }

    /**
     * Get ssid.
     *
     * @return \ApManBundle\Entity\SSID
     */
    public function getSsid()
    {
        return $this->ssid;
    }

    /**
     * Set ifname.
     *
     * @param string $ifname
     *
     * @return Device
     */
    public function setIfname($ifname)
    {
        $this->ifname = $ifname;

        return $this;
    }

    /**
     * Get ifname.
     *
     * @return string
     */
    public function getIfname()
    {
        return $this->ifname;
    }

    /**
     * Set address.
     *
     * @param string $address
     *
     * @return Event
     */
    public function setAddress($address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Get address.
     *
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * get IsEnabled.
     *
     * @return \boolean
     */
    public function getIsEnabled()
    {
        if (!$this->getSSID()->getIsEnabled()) {
            return false;
        }
        $config = $this->getConfig();
        if (!isset($config['disabled'])) {
            return true;
        }
        if (intval($config['disabled'])) {
            return false;
        }

        return true;
    }

    public function getStatus(): ?array
    {
        return $this->status;
    }

    public function setStatus(?array $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRrm(): ?array
    {
        return $this->rrm;
    }

    public function setRrm(?array $rrm): self
    {
        $this->rrm = $rrm;
        if (is_array($rrm) && isset($rrm['value']) && is_array($rrm['value']) and count($rrm['value'])) {
            $mac = $rrm['value'][0];
            if (strlen($mac)) {
                $this->setAddress($mac);
            }
        }

        return $this;
    }

    /*
     *
     *
     *
     *
     *
     *
     * ! Virtual Properties start here !
     *
     *
     *
     *
     *
     *
     */
}
