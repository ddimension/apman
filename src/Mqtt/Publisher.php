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
}
