<?php

namespace ApManBundle\EventListener;

use ApManBundle\Factory\MqttFactory;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Close the MQTT connection when a command or a request is done.
 *
 * Without this a process that published anything never ends: React's
 * Loop::get() registers a shutdown function that runs the event loop, and the
 * loop still holds the client's connection, so the script finishes its work and
 * then blocks in stream_select() indefinitely. apman:config-ap showed it
 * plainly — it wrote and committed the whole configuration of an access point,
 * the agent reported every call as done, and the process sat there afterwards
 * until it was killed.
 *
 * It cannot be a shutdown function of our own, because React registered its
 * first and shutdown functions run in order. Both events used here fire while
 * the process is still running normally: console.terminate after the command
 * returned, kernel.terminate after the response was sent.
 *
 * The daemon is unaffected: it runs the loop itself and only reaches
 * console.terminate when it is ending anyway.
 */
class MqttShutdownSubscriber implements EventSubscriberInterface
{
    private $mqttFactory;

    public function __construct(MqttFactory $mqttFactory)
    {
        $this->mqttFactory = $mqttFactory;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::TERMINATE => 'onTerminate',
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    /**
     * @param ConsoleTerminateEvent|TerminateEvent $event
     */
    public function onTerminate($event): void
    {
        // asking the factory for a client would open the very connection we are
        // here to close, so only an existing one is touched
        $client = $this->mqttFactory->getExistingClient();
        if ($client) {
            $client->shutdown();
        }
    }
}
