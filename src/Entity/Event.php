<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Event.
 *
 * @ORM\Table(name="event")
 * @ORM\Entity
 */
class Event
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
     */
    private $device;

    /**
     * @var string
     *
     * @ORM\Column(name="type", type="text", length=4096, nullable=false)
     */
    private $type;

    /**
     * @var string
     *
     * @ORM\Column(name="address", type="string", length=17, nullable=false)
     */
    private $address;

    /**
     * @var string
     *
     * @ORM\Column(name="event", type="text", length=4096, nullable=false)
     */
    private $event;

    /**
     * @var int
     *
     * @ORM\Column(name="signalstr", type="integer", nullable=true)
     */
    private $signalstr;

    public function __toString()
    {
        return $this->getMessage();
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
     * Set ts.
     *
     * @param \DateTime $ts
     *
     * @return Event
     */
    public function setTs($ts)
    {
        $this->ts = $ts;

        return $this;
    }

    /**
     * Get ts.
     *
     * @return \DateTime
     */
    public function getTs()
    {
        return $this->ts;
    }

    /**
     * Set Device.
     *
     * @param \ApManBundle\Entity\Device $device
     *
     * @return Device
     */
    public function setDevice(Device $device)
    {
        $this->device = $device;

        return $this;
    }

    /**
     * Get radio.
     *
     * @return \ApManBundle\Entity\Device
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * Set type.
     *
     * @param string $type
     *
     * @return Event
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get type.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set address.
     *
     * @param string $address
     *
     * @return Event
     */
    public function setAddress($address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Get address.
     *
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Set event.
     *
     * @param string $event
     *
     * @return Event
     */
    public function setEvent($event)
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Get event.
     *
     * @return string
     */
    public function getEvent()
    {
        return $this->event;
    }

    /**
     * Set signalstr.
     *
     * @param int $signalstr
     *
     * @return Event
     */
    public function setSignalstr($signalstr)
    {
        $this->signalstr = $signalstr;

        return $this;
    }

    /**
     * Get signalstr.
     *
     * @return int
     */
    public function getSignalstr()
    {
        return $this->signalstr;
    }
}
