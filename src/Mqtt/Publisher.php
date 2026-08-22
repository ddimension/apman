<?php

namespace ApManBundle\Mqtt;

/**
 * The one thing message handlers do with a client: send something back.
 *
 * Deliberately the same argument order as \Mosquitto\Client::publish() and
 * PhpMqtt\Client\MqttClient::publish(), so callers read the same either way.
 */
interface Publisher
{
    /**
     * @param string $topic
     * @param string $payload
     * @param int    $qos
     * @param bool   $retain
     *
     * @return bool true when the message was handed to the client
     */
    public function publish($topic, $payload, $qos = 0, $retain = false);

    /**
     * The same, but not yet.
     *
     * Where a sequence has to be spread over time — switch this on, and the
     * rest of it a few seconds later — the waiting belongs on this side. The
     * alternative, sending the access point a command that sleeps, buys the
     * same delay by taking the agent out of service for its duration.
     *
     * @param float $delay seconds
     *
     * @return bool true when the message was accepted for sending
     */
    public function publishDelayed($topic, $payload, $delay, $qos = 0, $retain = false);
}
