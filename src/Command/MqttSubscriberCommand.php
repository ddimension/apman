<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

//declare(ticks=1);
class MqttSubscriberCommand extends Command
{
    protected static $defaultName = 'apman:subscriber';
    private $parentPID;

    public function __construct(
        \ApManBundle\Service\SubscriptionService $subs,
        $name = null)
    {
        parent::__construct($name);
        $this->subs = $subs;
    }

    protected function configure()
    {
        $this
            ->setName('apman:subscriber')
            ->setDescription('Run mqtt subscriber.')
//            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
//            ->addArgument('radio', InputArgument::REQUIRED, 'Radio Name')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(1800);

        return $this->subs->runMqttLoop();
    }
}
