<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Device.
 */
#[ORM\Table(name: 'device')]
#[ORM\Entity]
class Device extends \ApManBundle\DynamicEntity\Device
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'name', type: 'string', nullable: true)]
    private $name;

    /**
     * @var array|null
     */
    #[ORM\Column(name: 'config', type: 'json', nullable: true)]
    private $config;

    /**
     * @var \ApManBundle\Entity\Radio
     */
    #[ORM\JoinColumn(name: 'radio_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: \ApManBundle\Entity\Radio::class, inversedBy: 'devices', cascade: ['persist'])]
    private $radio;

    /**
     * @var \ApManBundle\Entity\SSID
     */
    #[ORM\JoinColumn(name: 'ssid_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: \ApManBundle\Entity\SSID::class, inversedBy: 'devices', cascade: ['persist'])]
    private $ssid;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'ifname', type: 'string', nullable: true)]
    private $ifname;

    /**
     * @var string
     */
    #[ORM\Column(name: 'address', type: 'string', length: 17, nullable: true)]
    private $address;

    #[ORM\Column(type: 'json', nullable: true)]
    private $status = [];

    #[ORM\Column(type: 'json', nullable: true)]
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
        // The name IS the uci section name, and uci only accepts letters,
        // digits and underscores there. Everything else makes the "uci add"
        // of this device fail with "invalid argument", and because a
        // provisioning run is one transaction, that reverts the whole access
        // point — one SSID with a space in its name took every other network
        // on that access point down with it.
        $this->name = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $name);

        return $this;
    }

    /**
     * The uci section name for one network on one radio.
     *
     * Callers used to spell this out with a str_replace() blacklist of their
     * own, and the three copies had drifted apart — the one in
     * apman:assign-all-ssids was missing the space, which is how "OpenNet
     * Secure" ended up unprovisionable on the access points set up with it.
     */
    public static function sectionName(Radio $radio, SSID $ssid)
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $radio->getName().'_'.$ssid->getName());
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
        // Same precedence the provisioning uses: AccessPointService::
        // getDeviceConfig() starts from the ssid config and lets the device's
        // own config write over it, so a device that says disabled=0 runs even
        // though its ssid is switched off. This used to ask the ssid first and
        // return on the spot, which made it disagree with what actually gets
        // provisioned — kalinfra is disabled as an ssid and re-enabled on three
        // access points, and those three bsses are up while this said they were
        // not.
        $config = $this->getConfig();
        if (isset($config['disabled'])) {
            return !intval($config['disabled']);
        }

        return (bool) $this->getSSID()->getIsEnabled();
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
