<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowAcessPointConfigCommand extends Command
{
    protected static $defaultName = 'apman:show-ap-config';

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
            ->setName('apman:show-ap-config')
            ->setDescription('Show AccessPoint wifi-iface configuration.')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
        'name' => $input->getArgument('name'),
    ]);
        if (is_null($ap)) {
            $output->writeln('Add this accesspoint. Cannot find it.');

            return 1;
        }
        foreach ($ap->getRadios() as $radio) {
            foreach ($radio->getDevices() as $device) {
                $result = $this->apservice->getDeviceConfig($device);
                $output->writeln('Device '.$device->getIfname());
                print_r($result);
            }
        }
    }
}
