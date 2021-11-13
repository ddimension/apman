<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * SSIDConfigOption.
 *
 * @ORM\Table(name="ssid_config_option")
 * @ORM\Entity
 */
class SSIDConfigOption
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
     * @ORM\Column(name="value", type="string", nullable=true)
     */
    private $value;

    /**
     * @var \ApManBundle\Entity\SSID
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\SSID", inversedBy="config_options", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="ssid_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    private $ssid;

    public function __clone()
    {
        $this->id = null;
        $this->ssid = null;
    }

    public function __toString()
    {
        return $this->name;
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
     * @return SSIDConfigOption
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
     * Set value.
     *
     * @param string $value
     *
     * @return SSIDConfigOption
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get value.
     *
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set ssid.
     *
     * @param \ApManBundle\Entity\SSID $ssid
     *
     * @return SSIDConfigOption
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
}
