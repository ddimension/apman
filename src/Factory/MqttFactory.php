<?php

namespace ApManBundle\Factory;

class MqttFactory
{
    private $logger;
    private $client;

    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * A client for a web request or a console command: asynchronous underneath,
     * straight line to use.
     *
     * One MQTT library for the whole application — the daemon runs the same
     * client on its own loop. Cached per request, and reconnected when a
     * caller disconnected it and the next one needs it again.
     */
    public function getClient($id = null, $cleanSession = null)
    {
        if (isset($this->client) && $this->client instanceof \ApManBundle\Mqtt\SyncPublisher) {
            return $this->client;
        }
        $loop = \React\EventLoop\Loop::get();
        [$host, $port, $connection] = $this->getReactConnection(
            $id ?: 'apman-'.getmypid(),
            null === $cleanSession ? true : (bool) $cleanSession
        );
        $this->client = new \ApManBundle\Mqtt\SyncPublisher(
            $loop, $this->getReactClient($loop), $host, $port, $connection, $this->logger
        );

        return $this->client;
    }

    /**
     * The asynchronous client for the daemon.
     *
     * Not cached: it belongs to the event loop it was built for, and the
     * daemon builds a fresh one on every reconnect. Connecting is the caller's
     * job — the promise it returns is what tells them when to subscribe.
     */
    public function getReactClient(\React\EventLoop\LoopInterface $loop)
    {
        return new \BinSoul\Net\Mqtt\Client\React\ReactMqttClient(
            new \React\Socket\Connector($loop),
            $loop
        );
    }

    /**
     * Host, port, credentials and session settings from the environment, in
     * the form the asynchronous client wants them.
     *
     * @return array [host, port, \BinSoul\Net\Mqtt\Connection]
     */
    public function getReactConnection($id, $cleanSession = true)
    {
        if (empty($_SERVER['MQTT_PORT'])) {
            $_SERVER['MQTT_PORT'] = 1883;
        }

        return [
            $_SERVER['MQTT_HOST'],
            (int) $_SERVER['MQTT_PORT'],
            new \BinSoul\Net\Mqtt\DefaultConnection(
                (string) ($_SERVER['MQTT_USERNAME'] ?? ''),
                (string) ($_SERVER['MQTT_PASSWORD'] ?? ''),
                null,
                (string) $id,
                60,
                4,
                (bool) $cleanSession
            ),
        ];
    }

}
