<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * AccessPoint.
 */
#[ORM\Table(name: 'accesspoint')]
#[ORM\Entity]
class AccessPoint extends \ApManBundle\DynamicEntity\AccessPoint
{
    /**
     * @var int
     */
    /** through the apman agent, over the message bus */
    public const TRANSPORT_MQTT = 'mqtt';
    /** straight to the access point's own json-rpc endpoint, with a session */
    public const TRANSPORT_HTTP = 'http';

    public const TRANSPORTS = [self::TRANSPORT_MQTT, self::TRANSPORT_HTTP];

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
     * @var string|null
     */
    #[ORM\Column(name: 'username', type: 'string', nullable: true)]
    private $username;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'password', type: 'string', nullable: true)]
    private $password;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'ubus_url', type: 'string', nullable: true)]
    private $ubus_url;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'ipv4', type: 'string', length: 15, nullable: true)]
    private $ipv4;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ORM\OneToMany(targetEntity: \ApManBundle\Entity\Radio::class, mappedBy: 'accesspoint', cascade: ['persist'])]
    private $radios;

    #[ORM\Column(type: 'json', nullable: true)]
    private $status = [];

    #[ORM\Column(type: 'boolean', nullable: true)]
    private $ProvisioningEnabled;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private $IsProductive;

    /**
     * The secret this AP's own RADIUS server answers with. Set when an SSID on
     * this AP points its ppsk auth at 127.0.0.1, and provisioned into
     * /etc/config/apman — it is per AP on purpose, the SSID config is shared
     * by every AP of the network and must not carry it.
     *
     * @var string|null
     */
    /**
     * Which way the controller talks to this access point.
     *
     * Both ways reach the same ubus. MQTT goes through the apman agent, which
     * is subscribed and answers on a topic; HTTP logs in to the access point's
     * own json-rpc endpoint and holds a session. MQTT is the default and the
     * one everything new uses — it needs no session, survives the access point
     * being briefly unreachable, and does not put a password on the wire per
     * call.
     *
     * HTTP stays for the access point that has no agent, or has one that is
     * not answering, and this column is where that is said instead of being
     * decided by which piece of code happens to be running.
     */
    #[ORM\Column(name: 'transport', type: 'string', length: 8, nullable: true, options: ['default' => 'mqtt'])]
    private $transport = self::TRANSPORT_MQTT;

    #[ORM\Column(name: 'radius_secret', type: 'string', nullable: true)]
    private $radiusSecret;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->radios = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function __toString()
    {
        if ($this->getName()) {
            return $this->getName();
        }

        return '-';
    }

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
     * @return AccessPoint
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
     * Set username.
     *
     * @param string $username
     *
     * @return AccessPoint
     */
    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Get username.
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set password.
     *
     * @param string $password
     *
     * @return AccessPoint
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get password.
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set ubusUrl.
     *
     * @param string $ubusUrl
     *
     * @return AccessPoint
     */
    public function setUbusUrl($ubusUrl)
    {
        $this->ubus_url = $ubusUrl;

        return $this;
    }

    /**
     * Get ubusUrl.
     *
     * @return string
     */
    public function getUbusUrl()
    {
        return $this->ubus_url;
    }

    /**
     * Add radio.
     *
     * @param \ApManBundle\Entity\Radio $radio
     *
     * @return AccessPoint
     */
    public function addRadio(Radio $radio)
    {
        $this->radios[] = $radio;

        return $this;
    }

    /**
     * Remove radio.
     *
     * @param \ApManBundle\Entity\Radio $radio
     */
    public function removeRadio(Radio $radio)
    {
        $this->radios->removeElement($radio);
    }

    /**
     * Get radios.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRadios()
    {
        return $this->radios;
    }

    /**
     * Set ipv4.
     *
     * @param string $ipv4
     *
     * @return AccessPoint
     */
    public function setIpv4($ipv4)
    {
        $this->ipv4 = $ipv4;

        return $this;
    }

    /**
     * Get ipv4.
     *
     * @return string
     */
    public function getIpv4()
    {
        return $this->ipv4;
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

    public function getProvisioningEnabled(): ?bool
    {
        return $this->ProvisioningEnabled;
    }

    public function setProvisioningEnabled(?bool $ProvisioningEnabled): self
    {
        $this->ProvisioningEnabled = $ProvisioningEnabled;

        return $this;
    }

    public function getIsProductive(): ?bool
    {
        return $this->IsProductive;
    }

    public function setIsProductive(?bool $IsProductive): self
    {
        $this->IsProductive = $IsProductive;

        return $this;
    }

    public function getRadiusSecret(): ?string
    {
        return $this->radiusSecret;
    }

    public function setRadiusSecret(?string $radiusSecret): self
    {
        $this->radiusSecret = $radiusSecret;

        return $this;
    }

    /**
     * Which way to talk to this access point. Never null to a caller: an empty
     * column means nobody has chosen, and the choice for that is mqtt.
     */
    public function getTransport(): string
    {
        return in_array($this->transport, self::TRANSPORTS, true)
            ? $this->transport : self::TRANSPORT_MQTT;
    }

    public function setTransport(?string $transport): self
    {
        $this->transport = in_array($transport, self::TRANSPORTS, true)
            ? $transport : self::TRANSPORT_MQTT;

        return $this;
    }

    public function usesMqtt(): bool
    {
        return self::TRANSPORT_MQTT === $this->getTransport();
    }
}
