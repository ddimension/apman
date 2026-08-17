<?php

namespace ApManBundle\Mqtt;

use BinSoul\Net\Mqtt\Client\React\ReactMqttClient;
use BinSoul\Net\Mqtt\DefaultMessage;

/**
 * Publishing through the asynchronous client.
 *
 * The promise is not awaited: a handler that answers an access point must not
 * block the event loop that also serves the RADIUS socket. A rejected promise
 * is logged — for the fire-and-forget commands this carries, that is the right
 * trade, and the access point repeats its report anyway.
 */
class ReactPublisher implements Publisher
{
    private $client;
    private $logger;

    public function __construct(ReactMqttClient $client, \Psr\Log\LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function publish($topic, $payload, $qos = 0, $retain = false)
    {
        if (!$this->client->isConnected()) {
            $this->logger->warning('ReactPublisher: not connected, dropped message for '.$topic);

            return false;
        }
        $this->client
            ->publish(new DefaultMessage($topic, (string) $payload, (int) $qos, (bool) $retain))
            ->then(null, function (\Throwable $e) use ($topic) {
                $this->logger->warning('ReactPublisher: publish to '.$topic.' failed: '.$e->getMessage());
            });

        return true;
    }
}
