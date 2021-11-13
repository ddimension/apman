<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AssignAllSSIDsCommand extends Command
{
    protected static $defaultName = 'apman:assign-all-ssids';

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
            ->setName('apman:assign-all-ssids')
            ->setDescription('Assign all SSIDs to an accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->doctrine->getManager();
        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
        'name' => $input->getArgument('name'),
    ]);
        if (is_null($ap)) {
            $this->output->writeln('Add this accesspoint. Cannot find it.');

            return false;
        }

        $radios = $this->doctrine->getRepository('ApManBundle:Radio')->findBy([
        'accesspoint' => $ap,
    ]);
        if (!is_array($radios) or !count($radios)) {
            $this->output->writeln('Readd this accesspoint. No radios found');

            return false;
        }
        $ssids = $this->doctrine->getRepository('ApManBundle:SSID')->findAll();
        if (!count($ssids)) {
            $this->output->writeln('No SSIDs not found.');

            return false;
        }
        foreach ($ssids as $ssid) {
            $localConfigKeys = [
            'macaddr',
            'nasid',
            'r1_key_holder',
            'disabled',
            'ifname',
        ];
            $i = -1;
            foreach ($radios as $radio) {
                ++$i;
                $device = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy([
                'ssid' => $ssid,
                'radio' => $radio,
            ]);
                if (!is_null($device)) {
                    $this->output->writeln('Radio Device '.$device->getName().' for SSID '.$ssid->getName().' already exists.');
                    continue;
                }

                $device = new \ApManBundle\Entity\Device();
                $device->setName($radio->getName().'_'.str_replace(['-', '+', '/', '*', '$'], '_', $ssid->getName()));
                $device->setRadio($radio);
                $device->setSSID($ssid);

                $deviceConfig = [];
                $deviceConfig['macaddr'] = exec($this->container->get('kernel')->getRootDir().'/../bin/randmac.pl');
                if (!$deviceConfig['macaddr']) {
                    return false;
                }
                $ssidConfig = $ssid->exportConfig();
                if (isset($ssidConfig->ieee80211r) && 1 == $ssidConfig->ieee80211r) {
                    $deviceConfig['nasid'] = str_replace(':', '', $deviceConfig['macaddr']);
                    $deviceConfig['r1_key_holder'] = str_replace(':', '', $deviceConfig['macaddr']);
                }
                if (isset($ssidConfig->ifname) && !empty($ssidConfig->ifname)) {
                    $device->setIfname($ssidConfig->ifname.$i);
                }
                $device->setConfig($deviceConfig);
                $em->persist($device);
                $this->output->writeln('Added Radio Device '.$device->getName().' for SSID '.$ssid->getName());
            }
        }
        $em->flush();
    }
}
