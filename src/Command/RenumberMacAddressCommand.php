<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RenumberMacAddressCommand extends Command
{
    protected static $defaultName = 'apman:renumber-mac';

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
    }

    protected function configure()
    {
        $this
            ->setName('apman:renumber-mac')
            ->setDescription('Renumber MAC addresses of all devices.')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $devices = $this->doctrine->getRepository('ApManBundle:Device')->findAll(
    );

        $em = $this->doctrine->getManager();
        foreach ($devices as $device) {
            $curMac = $device->getAddress();
            $macPrefix = sprintf('20:20:%02x', $device->getRadio()->getAccesspoint()->getId() % 256);
            if (0 === strpos($curMac, $macPrefix)) {
                $output->writeln('Skipping MAC '.$curMac.' on device '.$device->getId().' it matches prefix '.$macPrefix.
            ', accesspoint: '.$device->getRadio()->getAccesspoint()->getName());
                continue;
            }
            //$mac = exec($this->kernel->getProjectDir().'/bin/randmac.pl 22-20-20 2>&1 |tail -n1');
            //
            $mac = exec('./bin/randmac.pl '.$macPrefix.' 2>&1 |tail -n1');
            $output->writeln('Set MAC '.$mac.' to device '.$device->getId().', accesspoint: '.
            $device->getRadio()->getAccesspoint()->getName());
            $device->setAddress($mac);
            $device->setRrm(null);
            $em->persist($device);
        }
        $em->flush();
    }
}
