<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ask the radios that are not carrying traffic whether they are listening.
 *
 * A process of its own, and that is the whole point. probe() publishes a
 * command and waits for the answer, and the answer arrives as an MQTT message
 * that the subscriber loop delivers — so doing this inside that loop blocks it
 * waiting for something it is itself supposed to hand over. Done that way on
 * 2026-08-22 it took every access point's ubus path down at once: /bin/echo
 * timed out on all three tried, while the agents were answering in
 * milliseconds and the answers had nowhere to land.
 *
 * Meant for a timer, every ten to thirty seconds. It costs one control socket
 * question per radio that is *already* not active, so a healthy fleet costs
 * nothing at all.
 */
#[AsCommand(name: 'apman:dfs-check')]
class DfsCheckCommand extends Command
{
    public function __construct(
        private readonly \ApManBundle\Service\SubscriptionService $subscription,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Ask radios that are not active whether a channel availability check is running');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->subscription->checkListeningRadios();

        return 0;
    }
}
