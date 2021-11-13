<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AssignAllNeighborsCommand extends Command
{
    protected static $defaultName = 'apman:assign-all-neighbors';

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\wrtJsonRpc $rpcService, $name = null)
    {
        parent::__construct($name);
        $this->apservice = $apservice;
    }

    protected function configure()
    {
        $this
            ->setName('apman:assign-all-neighbors')
            ->setDescription('Assign All Neighbors')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->apservice->assignAllNeighbors();
    }
}
