<?php

namespace ApManBundle\Mqtt;

/**
 * An incoming message, independent of the client that delivered it.
 *
 * The handlers in SubscriptionService and AccessPointService read `->topic`
 * and `->payload` and occasionally cast the whole thing to an array for a log
 * line — that is the entire surface they ever used of \Mosquitto\Message. This
 * class is that surface, so the client underneath can be exchanged without
 * touching seven hundred lines of message handling.
 */
class Message
{
    /** @var string */
    public $topic;

    /** @var string */
    public $payload;

    /** @var int */
    public $qos;

    /** @var bool */
    public $retain;

    public function __construct($topic, $payload, $qos = 0, $retain = false)
    {
        $this->topic = $topic;
        $this->payload = $payload;
        $this->qos = $qos;
        $this->retain = $retain;
    }
}
