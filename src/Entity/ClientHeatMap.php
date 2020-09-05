<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ClientHeatMap
 *
 * @ORM\Table(name="client_heatmap")
 * @ORM\Entity
 */
class ClientHeatMap
{
    /**
     * @var \DateTime
     *
     * @ORM\Column(name="ts", type="datetime")
     */
    private $ts;

    /**
     * @var \ApManBundle\Entity\Device
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\Device", inversedBy="events", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="device_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     * @ORM\Id
     */
    private $device;

    /**
     * @var string
     *
     * @ORM\Column(name="address", type="string", length=17, nullable=false)
     * @ORM\Id
     */
    private $address;

    /**
     * @var string
     *
     * @ORM\Column(name="event", type="text", length=4096, nullable=false)
     */
    private $event;

    /**
     * @var integer
     *
     * @ORM\Column(name="signalstr", type="integer", nullable=true)
     */
    private $signalstr;
    
    public function __toString() {
	return $this->getMessage();
    }

    /**
     * Set ts
     *
     * @param \DateTime $ts
     *
     * @return ClientHeatMap
     */
    public function setTs($ts)
    {
        $this->ts = $ts;

        return $this;
    }

    /**
     * Get ts
     *
     * @return \DateTime
     */
    public function getTs()
    {
        return $this->ts;
    }

    /**
     * Set Device
     *
     * @param \ApManBundle\Entity\Device $device
     *
     * @return Device
     */
    public function setDevice(\ApManBundle\Entity\Device $device)
    {
        $this->device = $device;

        return $this;
    }

    /**
     * Get radio
     *
     * @return \ApManBundle\Entity\Device
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * Set address
     *
     * @param string $address
     *
     * @return ClientHeatMap
     */
    public function setAddress($address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Get address
     *
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Set event
     *
     * @param string $event
     *
     * @return ClientHeatMap
     */
    public function setEvent($event)
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Get event
     *
     * @return string
     */
    public function getEvent()
    {
        return $this->event;
    }

    /**
     * Set signalstr
     *
     * @param int $signalstr
     *
     * @return ClientHeatMap
     */
    public function setSignalstr($signalstr)
    {
        $this->signalstr = $signalstr;

        return $this;
    }

    /**
     * Get signalstr
     *
     * @return int
     */
    public function getSignalstr()
    {
        return $this->signalstr;
    }

}
