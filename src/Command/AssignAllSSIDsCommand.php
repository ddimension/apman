<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:assign-all-ssids')]
class AssignAllSSIDsCommand extends Command
{

    private $doctrine;
    private $logger;
    private $apservice;

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Assign all SSIDs to an accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy([
        'name' => $input->getArgument('name'),
    ]);
        if (is_null($ap)) {
            $output->writeln('Add this accesspoint. Cannot find it.');

            return 1;
        }

        $radios = $this->doctrine->getRepository('ApManBundle\Entity\Radio')->findBy([
        'accesspoint' => $ap,
    ]);
        if (!is_array($radios) or !count($radios)) {
            $output->writeln('Readd this accesspoint. No radios found');

            return 1;
        }
        $ssids = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findAll();
        if (!count($ssids)) {
            $output->writeln('No SSIDs not found.');

            return 1;
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
                $device = $this->doctrine->getRepository('ApManBundle\Entity\Device')->findOneBy([
                'ssid' => $ssid,
                'radio' => $radio,
            ]);
                if (!is_null($device)) {
                    $output->writeln('Radio Device '.$device->getName().' for SSID '.$ssid->getName().' already exists.');
                    continue;
                }

                $device = new \ApManBundle\Entity\Device();
                $device->setName($radio->getName().'_'.str_replace(['-', '+', '/', '*', '$'], '_', $ssid->getName()));
                $device->setRadio($radio);
                $device->setSSID($ssid);

                $deviceConfig = [];
                $deviceConfig['macaddr'] = exec($this->container->get('kernel')->getRootDir().'/../bin/randmac.pl');
                if (!$deviceConfig['macaddr']) {
                    return 1;
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
                $output->writeln('Added Radio Device '.$device->getName().' for SSID '.$ssid->getName());
            }
        }
        $em->flush();

        return 0;
    }
}
