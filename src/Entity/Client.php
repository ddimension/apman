<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Client
 *
 * @ORM\Table(name="client")
 * @ORM\Entity
 */
class Client
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
     * @ORM\Column(name="mac", type="string", length=17, nullable=true)
     */
    private $mac;

    /**
     * @var bool|null
     *
     * @ORM\Column(name="mode_g", type="boolean", nullable=true)
     */
    private $mode_g = false;

    /**
     * @var bool|null
     *
     * @ORM\Column(name="mode_a", type="boolean", nullable=true)
     */
    private $mode_a = false;

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
     * Set mac
     *
     * @param string $mac
     *
     * @return Client
     */
    public function setMac($mac)
    {
        $this->mac = $mac;

        return $this;
    }

    /**
     * Get mac
     *
     * @return string
     */
    public function getMac()
    {
        return $this->mac;
    }

    /**
     * Set modeG
     *
     * @param boolean $modeG
     *
     * @return Client
     */
    public function setModeG($modeG)
    {
        $this->mode_g = $modeG;

        return $this;
    }

    /**
     * Get modeG
     *
     * @return boolean
     */
    public function getModeG()
    {
        return $this->mode_g;
    }

    /**
     * Set modeA
     *
     * @param boolean $modeA
     *
     * @return Client
     */
    public function setModeA($modeA)
    {
        $this->mode_a = $modeA;

        return $this;
    }

    /**
     * Get modeA
     *
     * @return boolean
     */
    public function getModeA()
    {
        return $this->mode_a;
    }

}