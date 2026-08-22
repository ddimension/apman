<?php

namespace ApManBundle\Mqtt;

use BinSoul\Net\Mqtt\Client\React\ReactMqttClient;
use BinSoul\Net\Mqtt\Connection;
use BinSoul\Net\Mqtt\DefaultMessage;
use BinSoul\Net\Mqtt\DefaultSubscription;
use BinSoul\Net\Mqtt\Message;
use React\EventLoop\LoopInterface;

use function React\Async\await;

/**
 * The daemon's MQTT client, used from a web request or a console command.
 *
 * Those places want a straight line — publish this, then carry on — while the
 * client underneath is asynchronous. React\Async\await() bridges the two: it
 * runs the event loop until the promise settles and hands back the result, so
 * the caller reads as if nothing were asynchronous at all.
 *
 * The point of using it here is that there is now one MQTT client in the whole
 * application instead of two libraries with different semantics, different
 * failure modes and two sets of bugs to know about.
 *
 * Connecting is lazy: a request that never publishes never opens a connection.
 */
class SyncPublisher implements Publisher
{
    private $loop;
    private $client;
    private $logger;
    private $host;
    private $port;
    private $connection;
    private $connected = false;

    public function __construct(
        LoopInterface $loop,
        ReactMqttClient $client,
        $host,
        $port,
        Connection $connection,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->loop = $loop;
        $this->client = $client;
        $this->host = $host;
        $this->port = (int) $port;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    public function publish($topic, $payload, $qos = 0, $retain = false)
    {
        if (!$this->connect()) {
            return false;
        }
        try {
            await($this->client->publish(new DefaultMessage($topic, (string) $payload, (int) $qos, (bool) $retain)));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Mqtt: publish to '.$topic.' failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * A synchronous publisher has no later: it waits.
     *
     * The loop is run for the delay rather than slept through, so the client
     * keeps its connection alive and any subscription set up beforehand keeps
     * receiving. The caller pays the delay in wall clock time — which is the
     * honest price here, and the reason the staggered sends live in the
     * daemon, where the loop is already turning.
     */
    public function publishDelayed($topic, $payload, $delay, $qos = 0, $retain = false)
    {
        if (!$this->connect()) {
            return false;
        }
        $this->wait((float) $delay);

        return $this->publish($topic, $payload, $qos, $retain);
    }

    /**
     * Subscribe and hand every message to the callback.
     *
     * Nothing is delivered until wait() runs the loop — which is what lets a
     * caller subscribe first, then publish, then wait, without a race.
     *
     * @param array<string,int> $filters topic filter => qos
     */
    public function subscribe(array $filters, callable $onMessage)
    {
        if (!$this->connect()) {
            return false;
        }
        $this->client->on('message', function (Message $message) use ($onMessage) {
            $onMessage(new \ApManBundle\Mqtt\Message(
                $message->getTopic(),
                $message->getPayload(),
                $message->getQosLevel(),
                $message->isRetained()
            ));
        });
        foreach ($filters as $filter => $qos) {
            try {
                await($this->client->subscribe(new DefaultSubscription($filter, (int) $qos)));
            } catch (\Throwable $e) {
                $this->logger->error('Mqtt: subscribe '.$filter.' failed: '.$e->getMessage());
            }
        }

        return true;
    }

    /**
     * Let the loop deliver until the caller has what it came for, or until the
     * time is up.
     *
     * @param callable|null $done returns true when there is nothing left to wait for
     */
    public function wait($seconds, ?callable $done = null)
    {
        $timers = [];
        $timers[] = $this->loop->addTimer($seconds, function () {
            $this->loop->stop();
        });
        if ($done) {
            $timers[] = $this->loop->addPeriodicTimer(0.05, function () use ($done) {
                if ($done()) {
                    $this->loop->stop();
                }
            });
        }
        $this->loop->run();
        foreach ($timers as $timer) {
            $this->loop->cancelTimer($timer);
        }

        return true;
    }

    /**
     * Subscribe and listen for a fixed time — the simple case.
     *
     * @param array<string,int> $filters topic filter => qos
     */
    public function listen(array $filters, callable $onMessage, $seconds)
    {
        if (!$this->subscribe($filters, $onMessage)) {
            return false;
        }

        return $this->wait($seconds);
    }

    public function isConnected()
    {
        return $this->connected && $this->client->isConnected();
    }

    public function disconnect()
    {
        if (!$this->connected) {
            return true;
        }
        try {
            await($this->client->disconnect());
        } catch (\Throwable $e) {
            // a connection that is already gone is not a problem worth raising
            $this->logger->debug('Mqtt: disconnect said '.$e->getMessage());
        }
        $this->connected = false;

        return true;
    }

    /**
     * Close down so the process can actually end.
     *
     * React's Loop::get() registers a shutdown function that runs the loop when
     * the script ends. A short lived process that published something therefore
     * does its work, reaches the end — and then sits in the event loop forever,
     * because the client still has a connection or a reconnect timer on it.
     * Registering our own shutdown function would be too late: React's runs
     * first. So this has to be called while the process is still doing things,
     * which is what MqttShutdownSubscriber is for.
     */
    public function shutdown()
    {
        $this->disconnect();
        $this->loop->stop();

        return true;
    }

    private function connect()
    {
        if ($this->isConnected()) {
            return true;
        }
        try {
            await($this->client->connect($this->host, $this->port, $this->connection));
            $this->connected = true;

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Mqtt: connect to '.$this->host.':'.$this->port.' failed: '.$e->getMessage());

            return false;
        }
    }
}
