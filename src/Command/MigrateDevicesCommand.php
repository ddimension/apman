<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:migrate-deviced')]
class MigrateDevicesCommand extends Command
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
            ->setName('apman:migrate-devices')
            ->setDescription('Migrate devices (use with care)')
//            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();
        $devices = $this->doctrine->getRepository('ApManBundle\Entity\Device')->findAll();
        foreach ($devices as $device) {
            $cfg = $device->getConfig();
            unset($cfg['nasid']);
            unset($cfg['r1_key_holder']);
            $device->setIfname($cfg['ifname']);
            $device->setAddress($cfg['macaddr']);
            unset($cfg['ifname']);
            unset($cfg['macaddr']);
            $device->setConfig($cfg);
            $em->persist($device);
        }
        $em->flush();

        return 0;
    }
}
