<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:monitor')]
class MonitorCommand extends Command
{

    private $doctrine;
    private $logger;
    private $apservice;
    private $rpcService;

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\wrtJsonRpc $rpcService, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
        $this->rpcService = $rpcService;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Monitor')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();
        $aps = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findBy([
        'IsProductive' => true,
    ]);
        if (is_null($aps) || !is_array($aps) || !count($aps)) {
            $output->writeln('No productive Accesspoints found.');

            return 1;
        }
        $apsNotActive = [];
        $total = 0;
        foreach ($aps as $ap) {
            ++$total;
            $state = $ap->getState();
            if ('STATE_ACTIVE' != $state) {
                $apsNotActive[] = $ap;
            }
        }
        if (!count($apsNotActive)) {
            echo "OK - All APs online|online=$total offline=0\n";

            return 0;
        }
        echo 'Failure - '.count($apsNotActive).' APs offline|online='.($total - count($apsNotActive)).' offline='.count($apsNotActive)."\n";

        return 2;
    }
}
