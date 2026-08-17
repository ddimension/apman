<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One RADIUS request, as it was answered.
 *
 * Kept as history rather than as a counter because the interesting questions
 * are about single events: which station was turned away and why, which key a
 * WPA3 client used — the control channel cannot say that for SAE —, and how
 * long the controller took to answer.
 *
 * The SSID is stored by the name that came over the wire, not as a relation:
 * a request for a network nobody configured is exactly the case worth keeping.
 *
 * @ORM\Table(name="radius_auth", indexes={
 *     @ORM\Index(name="radius_auth_created", columns={"created"}),
 *     @ORM\Index(name="radius_auth_mac", columns={"mac"})
 * })
 * @ORM\Entity
 */
class RadiusAuth
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @ORM\Column(name="mac", type="string", length=17, nullable=true)
     */
    private $mac;

    /**
     * @ORM\Column(name="ssid_name", type="string", length=64, nullable=true)
     */
    private $ssidName;

    /** the access point that asked, by NAS-Identifier or its address */
    /**
     * @ORM\Column(name="nas", type="string", length=128, nullable=true)
     */
    private $nas;

    /** accept, fallback or reject */
    /**
     * @ORM\Column(name="result", type="string", length=16)
     */
    private $result;

    /**
     * @ORM\Column(name="reason", type="string", length=128, nullable=true)
     */
    private $reason;

    /**
     * @ORM\Column(name="keyid", type="string", length=32, nullable=true)
     */
    private $keyid;

    /**
     * The key that was handed out, as long as it exists. Deleting a key must
     * not delete the history of its use, so this is set to null instead.
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\Ppsk")
     * @ORM\JoinColumn(name="ppsk_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private $ppsk;

    /**
     * @ORM\Column(name="duration_ms", type="float", nullable=true)
     */
    private $durationMs;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    public function getMac()
    {
        return $this->mac;
    }

    public function setMac($mac)
    {
        $this->mac = $mac;

        return $this;
    }

    public function getSsidName()
    {
        return $this->ssidName;
    }

    public function setSsidName($ssidName)
    {
        $this->ssidName = $ssidName;

        return $this;
    }

    public function getNas()
    {
        return $this->nas;
    }

    public function setNas($nas)
    {
        $this->nas = $nas;

        return $this;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function setResult($result)
    {
        $this->result = $result;

        return $this;
    }

    public function getReason()
    {
        return $this->reason;
    }

    public function setReason($reason)
    {
        $this->reason = null === $reason ? null : mb_substr($reason, 0, 128);

        return $this;
    }

    public function getKeyid()
    {
        return $this->keyid;
    }

    public function setKeyid($keyid)
    {
        $this->keyid = $keyid;

        return $this;
    }

    public function getPpsk()
    {
        return $this->ppsk;
    }

    public function setPpsk($ppsk)
    {
        $this->ppsk = $ppsk;

        return $this;
    }

    public function getDurationMs()
    {
        return $this->durationMs;
    }

    public function setDurationMs($durationMs)
    {
        $this->durationMs = $durationMs;

        return $this;
    }
}
