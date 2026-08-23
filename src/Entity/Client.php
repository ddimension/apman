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
     * Until when this client is not allowed on, or null if it is welcome.
     *
     * hostapd bans a station with `del_client` and a `ban_time` in
     * milliseconds — measured on ap-av-grwz: 15000 kept it out for about
     * fifteen seconds and `list_bans` listed it for exactly that long. The ban
     * lives in the running hostapd and in nothing else: it is per bss, it is
     * forgotten on restart, and it has no idea that the same station is welcome
     * or unwelcome on the other ten bsses of the fleet.
     *
     * So this column is the decision and the access points hold only its
     * current consequence. A blocked station that manages to associate — after
     * a ban expired, on a bss that restarted, on an access point that was
     * rebooted — is thrown off again by the control channel handler and banned
     * anew. It gets in for a moment each time; there is no way to make that
     * moment zero without a mac address filter in the configuration, and that
     * option has taken a whole radio down in this fleet before.
     */
    #[ORM\Column(name: 'blocked_until', type: 'datetime', nullable: true)]
    private $blockedUntil;

    /**
     * Why, because a block nobody can explain later gets undone by guesswork.
     *
     * @var string|null
     */
    #[ORM\Column(name: 'blocked_reason', type: 'string', length: 255, nullable: true)]
    private $blockedReason;

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

    public function getBlockedUntil(): ?\DateTimeInterface
    {
        return $this->blockedUntil;
    }

    public function setBlockedUntil(?\DateTimeInterface $until): self
    {
        $this->blockedUntil = $until;

        return $this;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function setBlockedReason(?string $reason): self
    {
        $this->blockedReason = $reason;

        return $this;
    }

    /**
     * Whether the block is still standing right now.
     */
    public function isBlocked(): bool
    {
        return null !== $this->blockedUntil && $this->blockedUntil > new \DateTime();
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
