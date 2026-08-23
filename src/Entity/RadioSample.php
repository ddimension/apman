<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One radio, one moment, the handful of numbers worth keeping.
 *
 * Everything the controller knows about a radio lives in a cache that holds ten
 * to thirty minutes and then forgets. That is enough to answer "is it busy now"
 * and nothing else — not "was last Tuesday evening worse than this one", not
 * "when did this radio change channel", not "has it been getting slowly
 * busier". A commercial controller's whole visible advantage is that it
 * remembers, and remembering is the cheap part: these numbers already pass
 * through the controller every ten seconds and are thrown away.
 *
 * Nothing here is asked of an access point. `apman:sample` reads the same cache
 * the pages read and writes one row per radio per run, so the fleet pays
 * nothing for the memory.
 *
 * The byte counters are cumulative, exactly as the netdev reports them, summed
 * over the bsses of the radio. Storing the difference instead would look
 * tidier and would be wrong: an interval that is missed, because the controller
 * was restarted or an access point was away, would silently vanish rather than
 * showing up as a gap. Differences are worked out when the numbers are drawn,
 * and a negative one — a bss was rebuilt and its counter went back to zero — is
 * a gap and not a negative throughput.
 */
#[ORM\Table(name: 'radio_sample')]
#[ORM\Index(name: 'radio_ts', columns: ['radio_id', 'ts'])]
#[ORM\Index(name: 'ts', columns: ['ts'])]
#[ORM\Entity]
class RadioSample
{
    #[ORM\Column(name: 'id', type: 'bigint')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    #[ORM\ManyToOne(targetEntity: 'Radio')]
    #[ORM\JoinColumn(name: 'radio_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private $radio;

    /** unix seconds, because every other timestamp in the caches is one */
    #[ORM\Column(name: 'ts', type: 'integer')]
    private $ts;

    #[ORM\Column(name: 'stations', type: 'smallint', options: ['unsigned' => true])]
    private $stations = 0;

    /** as the netdev counts them, summed over the bsses of this radio */
    #[ORM\Column(name: 'rx_bytes', type: 'bigint', options: ['unsigned' => true])]
    private $rxBytes = 0;

    #[ORM\Column(name: 'tx_bytes', type: 'bigint', options: ['unsigned' => true])]
    private $txBytes = 0;

    /**
     * How much of the medium was in use, in percent.
     *
     * hostapd's own figure, from `ap_status.airtime.utilization`, converted:
     * that field is a fraction of 255 like the BSS Load element and not a
     * percentage, and reading it as one gives numbers that cannot exist — 123
     * was measured on ap-av-grwz radio0. It counts everybody's traffic on the
     * channel and not only ours, which is what makes it the number that answers
     * "is this channel worth being on".
     */
    #[ORM\Column(name: 'utilization', type: 'smallint', nullable: true)]
    private $utilization;

    #[ORM\Column(name: 'noise', type: 'smallint', nullable: true)]
    private $noise;

    /**
     * hostapd's airtime counters, cumulative, in milliseconds.
     *
     * These are what the busy figure is actually worked out from, and the
     * reason is measured: `utilization` above is not to be trusted. On
     * ap-outdoor2 channel 7 it read 0 three times in a row while these counters
     * put the channel at 15.8 and 17.5 % busy; on ap-av-klwz channel 11 it read
     * 255 and then 0 while they said 7.8 and 9.9 %. Only on ap-av-grwz did it
     * track them at all. Both are kept: the difference between what a radio
     * says about itself and what its own counters say is worth being able to
     * see.
     *
     * The unit was measured too. Two samples twenty seconds apart differ by
     * 20005, so this counts milliseconds and not the microseconds the name
     * suggests.
     */
    #[ORM\Column(name: 'airtime_time', type: 'bigint', nullable: true, options: ['unsigned' => true])]
    private $airtimeTime;

    #[ORM\Column(name: 'airtime_busy', type: 'bigint', nullable: true, options: ['unsigned' => true])]
    private $airtimeBusy;

    /** kept per sample so that a channel change is visible as one */
    #[ORM\Column(name: 'channel', type: 'smallint', nullable: true)]
    private $channel;

    public function getId()
    {
        return $this->id;
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

    public function getTs(): int
    {
        return (int) $this->ts;
    }

    public function setTs(int $ts): self
    {
        $this->ts = $ts;

        return $this;
    }

    public function getStations(): int
    {
        return (int) $this->stations;
    }

    public function setStations(int $stations): self
    {
        $this->stations = $stations;

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

    public function getUtilization(): ?int
    {
        return null === $this->utilization ? null : (int) $this->utilization;
    }

    public function setUtilization(?int $utilization): self
    {
        $this->utilization = $utilization;

        return $this;
    }

    public function getNoise(): ?int
    {
        return null === $this->noise ? null : (int) $this->noise;
    }

    public function setNoise(?int $noise): self
    {
        $this->noise = $noise;

        return $this;
    }

    public function getAirtimeTime(): ?int
    {
        return null === $this->airtimeTime ? null : (int) $this->airtimeTime;
    }

    public function setAirtimeTime(?int $ms): self
    {
        $this->airtimeTime = $ms;

        return $this;
    }

    public function getAirtimeBusy(): ?int
    {
        return null === $this->airtimeBusy ? null : (int) $this->airtimeBusy;
    }

    public function setAirtimeBusy(?int $ms): self
    {
        $this->airtimeBusy = $ms;

        return $this;
    }

    public function getChannel(): ?int
    {
        return null === $this->channel ? null : (int) $this->channel;
    }

    public function setChannel(?int $channel): self
    {
        $this->channel = $channel;

        return $this;
    }
}
