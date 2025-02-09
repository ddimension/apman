<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AddAccessPointCommand extends Command
{
    protected static $defaultName = 'apman:add-ap';

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\wrtJsonRpc $rpcService, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
        $this->rpcService = $rpcService;
    }

    protected function configure()
    {
        $this
            ->setName('apman:add-ap')
            ->setDescription('Add Accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addArgument('username', InputArgument::REQUIRED, 'username')
            ->addArgument('password', InputArgument::REQUIRED, 'password')
            ->addArgument('ubus_url', InputArgument::REQUIRED, 'UBUS URL: http://firewall/ubus')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->doctrine->getManager();
        $ap = new \ApManBundle\Entity\AccessPoint();
        $ap->setName($input->getArgument('name'));
        $ap->setUsername($input->getArgument('username'));
        $ap->setPassword($input->getArgument('password'));
        $ap->setUbusUrl($input->getArgument('ubus_url'));
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $output->writeln('Cannot connect to AP '.$ap->getName());

            return false;
        }
        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-device';
        $stat = $session->call('uci', 'get', $opts);
        if (!isset($stat->values) || !count((array) $stat->values)) {
            $output->writeln('No radios found on AP '.$ap->getName());

            return false;
        }
        foreach ((array) $stat->values as $name => $cfg) {
            $output->writeln('Adding radio '.$name);
            $radio = new \ApManBundle\Entity\Radio();
            $radio->setAccessPoint($ap);
            $radio->setName($name);
            $radio->importConfig($cfg);
            $em->persist($radio);
        }
        $em->persist($ap);
        $em->flush();
        $output->writeln('Saved AP '.$ap->getName().' with id '.$ap->getId());
    }
}
