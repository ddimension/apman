<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

//declare(ticks=1);
#[AsCommand(name: 'apman:subscriber')]
class MqttSubscriberCommand extends Command
{
    private $parentPID;

    private $subs;

    public function __construct(
        \ApManBundle\Service\SubscriptionService $subs,
        $name = null
    )
    {
        parent::__construct($name);
        $this->subs = $subs;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run mqtt subscriber.')
//            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
//            ->addArgument('radio', InputArgument::REQUIRED, 'Radio Name')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // No limit. bin/console already sets 0; this line put a 1800 second
        // cap back on a daemon that is meant to run until it is stopped.
        // PHP counts only the script's own execution here, not the time the
        // loop spends waiting on the broker, so the cap took days to fill and
        // then killed the subscriber mid-call with a fatal error - twice a day,
        // every day, always inside a redis read. systemd restarted it, so it
        // looked like nothing was wrong; what it cost was the in-memory state
        // and a replay of every retained message on the broker.
        set_time_limit(0);

        return $this->subs->runMqttLoop();
    }
}
