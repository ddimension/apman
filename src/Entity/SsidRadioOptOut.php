<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A radio that deliberately does not carry a network.
 *
 * Without this a radio has two states — it carries the network or it does not —
 * and the second one cannot be told apart from "nobody has got round to it".
 * So `apman:assign-ssid` creates a bss on every radio of an access point,
 * unconditionally, and a row somebody deleted on purpose comes back on the next
 * run. kalclients does not belong on 6 GHz, and there was nowhere to write that
 * down.
 *
 * With it there are three: it carries, it deliberately does not, or nobody has
 * decided. The third is the only one an assignment run may act on.
 */
#[ORM\Table(name: 'ssid_radio_opt_out')]
#[ORM\UniqueConstraint(name: 'ssid_radio', columns: ['ssid_id', 'radio_id'])]
#[ORM\Entity]
class SsidRadioOptOut
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    #[ORM\ManyToOne(targetEntity: 'ApManBundle\Entity\SSID')]
    #[ORM\JoinColumn(name: 'ssid_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private $ssid;

    #[ORM\ManyToOne(targetEntity: 'ApManBundle\Entity\Radio')]
    #[ORM\JoinColumn(name: 'radio_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private $radio;

    /** why, in whatever words the person had — the value of this row is the sentence */
    #[ORM\Column(name: 'reason', type: 'string', length: 255, nullable: true)]
    private $reason;

    #[ORM\Column(name: 'created', type: 'datetime', nullable: true)]
    private $created;

    public function __construct(?SSID $ssid = null, ?Radio $radio = null, ?string $reason = null)
    {
        $this->ssid = $ssid;
        $this->radio = $radio;
        $this->reason = $reason;
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getSsid(): ?SSID
    {
        return $this->ssid;
    }

    public function setSsid(?SSID $ssid): self
    {
        $this->ssid = $ssid;

        return $this;
    }

    public function getRadio(): ?Radio
    {
        return $this->radio;
    }

    public function setRadio(?Radio $radio): self
    {
        $this->radio = $radio;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(?\DateTimeInterface $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function __toString(): string
    {
        return ($this->ssid ? $this->ssid->getName() : '?').' not on '
            .($this->radio ? $this->radio->getName() : '?');
    }
}
