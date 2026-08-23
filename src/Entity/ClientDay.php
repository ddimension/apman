<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One station, one day, how much it moved and where it was.
 *
 * The radio series answers "how busy was the fleet"; this answers the other
 * question a controller gets opened for, which is "who used all that". Per
 * station and per day rather than per station and per five minutes, because a
 * day is the unit anybody actually asks in and because the arithmetic is
 * different by two orders of magnitude: a hundred and fifteen stations a day is
 * forty thousand rows a year, and the same at five minute resolution is twelve
 * million.
 *
 * Keyed by the address and not by the Client row: a station that has never been
 * given a name has no Client, and leaving it out of the accounting because
 * nobody has named it would leave exactly the stations somebody is trying to
 * identify out of it.
 */
#[ORM\Table(name: 'client_day')]
#[ORM\UniqueConstraint(name: 'mac_day', columns: ['mac', 'day'])]
#[ORM\Index(name: 'day', columns: ['day'])]
#[ORM\Entity]
class ClientDay
{
    #[ORM\Column(name: 'id', type: 'bigint')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    #[ORM\Column(name: 'mac', type: 'string', length: 17)]
    private $mac;

    /** the day this belongs to, local time, because that is how people ask */
    #[ORM\Column(name: 'day', type: 'date')]
    private $day;

    /**
     * Bytes as the station moved them, added up out of the interface counters.
     *
     * Received and sent are from the access point's side, so rx is what the
     * station sent up and tx is what it was given — the same way round as
     * everywhere else in this application, and worth saying because it is the
     * opposite of what somebody looking at their own download expects.
     */
    #[ORM\Column(name: 'rx_bytes', type: 'bigint', options: ['unsigned' => true])]
    private $rxBytes = 0;

    #[ORM\Column(name: 'tx_bytes', type: 'bigint', options: ['unsigned' => true])]
    private $txBytes = 0;

    /** how many sampling runs found it associated, times the interval */
    #[ORM\Column(name: 'seconds_seen', type: 'integer', options: ['unsigned' => true])]
    private $secondsSeen = 0;

    /** where it was the last time this row was touched */
    #[ORM\Column(name: 'last_ifname', type: 'string', length: 32, nullable: true)]
    private $lastIfname;

    #[ORM\Column(name: 'last_ap', type: 'string', length: 64, nullable: true)]
    private $lastAp;

    /** the weakest and strongest it was heard at, which bounds where it was */
    #[ORM\Column(name: 'min_signal', type: 'smallint', nullable: true)]
    private $minSignal;

    #[ORM\Column(name: 'max_signal', type: 'smallint', nullable: true)]
    private $maxSignal;

    public function getId()
    {
        return $this->id;
    }

    public function getMac(): string
    {
        return (string) $this->mac;
    }

    public function setMac(string $mac): self
    {
        $this->mac = strtolower($mac);

        return $this;
    }

    public function getDay(): ?\DateTimeInterface
    {
        return $this->day;
    }

    public function setDay(\DateTimeInterface $day): self
    {
        $this->day = $day;

        return $this;
    }

    public function getRxBytes(): int
    {
        return (int) $this->rxBytes;
    }

    public function setRxBytes(int $bytes): self
    {
        $this->rxBytes = $bytes;

        return $this;
    }

    public function getTxBytes(): int
    {
        return (int) $this->txBytes;
    }

    public function setTxBytes(int $bytes): self
    {
        $this->txBytes = $bytes;

        return $this;
    }

    public function getTotalBytes(): int
    {
        return $this->getRxBytes() + $this->getTxBytes();
    }

    public function getSecondsSeen(): int
    {
        return (int) $this->secondsSeen;
    }

    public function setSecondsSeen(int $seconds): self
    {
        $this->secondsSeen = $seconds;

        return $this;
    }

    public function getLastIfname(): ?string
    {
        return $this->lastIfname;
    }

    public function setLastIfname(?string $ifname): self
    {
        $this->lastIfname = $ifname;

        return $this;
    }

    public function getLastAp(): ?string
    {
        return $this->lastAp;
    }

    public function setLastAp(?string $ap): self
    {
        $this->lastAp = $ap;

        return $this;
    }

    public function getMinSignal(): ?int
    {
        return null === $this->minSignal ? null : (int) $this->minSignal;
    }

    public function setMinSignal(?int $signal): self
    {
        $this->minSignal = $signal;

        return $this;
    }

    public function getMaxSignal(): ?int
    {
        return null === $this->maxSignal ? null : (int) $this->maxSignal;
    }

    public function setMaxSignal(?int $signal): self
    {
        $this->maxSignal = $signal;

        return $this;
    }
}
