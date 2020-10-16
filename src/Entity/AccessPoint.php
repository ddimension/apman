<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * AccessPoint
 *
 * @ORM\Table(name="accesspoint")
 * @ORM\Entity
 */
class AccessPoint extends \ApManBundle\DynamicEntity\AccessPoint
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
     * @var string|null
     *
     * @ORM\Column(name="username", type="string", nullable=true)
     */
    private $username;

    /**
     * @var string|null
     *
     * @ORM\Column(name="password", type="string", nullable=true)
     */
    private $password;

    /**
     * @var string|null
     *
     * @ORM\Column(name="ubus_url", type="string", nullable=true)
     */
    private $ubus_url;

    /**
     * @var string|null
     *
     * @ORM\Column(name="ipv4", type="string", length=15, nullable=true)
     */
    private $ipv4;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ApManBundle\Entity\Radio", mappedBy="accesspoint", cascade={"persist"})
     */
    private $radios;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private $status = [];

    /**
     * Constructor
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
     * @return AccessPoint
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
     * Set username
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
     * Get username
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set password
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
     * Get password
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set ubusUrl
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
     * Get ubusUrl
     *
     * @return string
     */
    public function getUbusUrl()
    {
        return $this->ubus_url;
    }

    /**
     * Add radio
     *
     * @param \ApManBundle\Entity\Radio $radio
     *
     * @return AccessPoint
     */
    public function addRadio(\ApManBundle\Entity\Radio $radio)
    {
        $this->radios[] = $radio;

        return $this;
    }

    /**
     * Remove radio
     *
     * @param \ApManBundle\Entity\Radio $radio
     */
    public function removeRadio(\ApManBundle\Entity\Radio $radio)
    {
        $this->radios->removeElement($radio);
    }

    /**
     * Get radios
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRadios()
    {
        return $this->radios;
    }


    /**
     * Set ipv4
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
     * Get ipv4
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
}
