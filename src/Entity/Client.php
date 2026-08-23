<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Client.
 */
#[ORM\Table(name: 'client')]
#[ORM\Entity]
class Client
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
    #[ORM\Column(name: 'mac', type: 'string', length: 17, nullable: true, unique: true)]
    private $mac;

    /**
     * @var bool|null
     */
    #[ORM\Column(name: 'mode_g', type: 'boolean', nullable: true)]
    private $mode_g = false;

    /**
     * @var bool|null
     */
    #[ORM\Column(name: 'mode_a', type: 'boolean', nullable: true)]
    private $mode_a = false;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private $name;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private $SteeringDisabled = false;

    /**
     * How much of the medium this client may take, relative to the others.
     *
     * mac80211 gives every station a weight of 256 and shares airtime in that
     * proportion, so 512 is twice a normal client's share and 128 is half. The
     * number is not a rate and not a cap — a client alone on a radio gets all
     * of it whatever this says; the weight only decides who yields when two of
     * them want the medium at the same moment.
     *
     * Three things were measured on ap-av-grwz on 23.08.2026 and all three
     * shape how this is used:
     *
     *   - Without `airtime_mode` on the radio, hostapd accepts `update_airtime`
     *     and answers success while the driver value does not move. Setting a
     *     weight against a radio that has no airtime policy is a no-op that
     *     looks like a success, which is why the pages say so out loud.
     *   - A weight of 0 is ignored rather than treated as "back to normal".
     *     Resetting means sending 256, and AirtimeService::DEFAULT_WEIGHT is
     *     that number for exactly this reason.
     *   - It does not survive a reassociation. The station was set to 700,
     *     deauthenticated, and came back at 256. So this column is the
     *     intention and the access point is never the record of it: the weight
     *     is put back on every AP-STA-CONNECTED.
     *
     * Null means we have no opinion and the station keeps whatever the radio
     * gives it.
     */
    #[ORM\Column(name: 'airtime_weight', type: 'integer', nullable: true)]
    private $airtimeWeight;

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
     * Set mac.
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
     * Get mac.
     *
     * @return string
     */
    public function getMac()
    {
        return $this->mac;
    }

    /**
     * Set modeG.
     *
     * @param bool $modeG
     *
     * @return Client
     */
    public function setModeG($modeG)
    {
        $this->mode_g = $modeG;

        return $this;
    }

    /**
     * Get modeG.
     *
     * @return bool
     */
    public function getModeG()
    {
        return $this->mode_g;
    }

    /**
     * Set modeA.
     *
     * @param bool $modeA
     *
     * @return Client
     */
    public function setModeA($modeA)
    {
        $this->mode_a = $modeA;

        return $this;
    }

    /**
     * Get modeA.
     *
     * @return bool
     */
    public function getModeA()
    {
        return $this->mode_a;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return Client
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

    public function getSteeringDisabled(): ?bool
    {
        return $this->SteeringDisabled;
    }

    public function getAirtimeWeight(): ?int
    {
        return $this->airtimeWeight;
    }

    public function setAirtimeWeight(?int $weight): self
    {
        $this->airtimeWeight = $weight;

        return $this;
    }

    public function setSteeringDisabled(?bool $SteeringDisabled): self
    {
        $this->SteeringDisabled = $SteeringDisabled;

        return $this;
    }
}
