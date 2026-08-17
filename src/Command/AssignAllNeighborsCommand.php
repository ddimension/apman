<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:assign-all-neighbors')]
class AssignAllNeighborsCommand extends Command
{

    private $apservice;

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\wrtJsonRpc $rpcService, $name = null)
    {
        parent::__construct($name);
        $this->apservice = $apservice;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Assign All Neighbors')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->apservice->assignAllNeighbors();

        return 0;
    }
}
