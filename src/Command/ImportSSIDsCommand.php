<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:import-ssids')]
class ImportSSIDsCommand extends Command
{

    private $doctrine;
    private $logger;
    private $apservice;
    private $jsonrpc;

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\wrtJsonRpc $jsonrpc, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
        $this->jsonrpc = $jsonrpc;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Import SSIDs from an AP radio')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addArgument('radio', InputArgument::REQUIRED, 'Radio Name')
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

        $radio = $this->doctrine->getRepository('ApManBundle\Entity\Radio')->findOneBy([
        'name' => $input->getArgument('radio'),
        'accesspoint' => $ap,
    ]);
        if (is_null($radio)) {
            $output->writeln('Readd this accesspoint. The given radio is missing.');

            return 1;
        }

        $rpcService = $this->jsonrpc;
        $session = $rpcService->login($ap->getUbusUrl(), $ap->getUsername(), $ap->getPassword());
        if (false === $session) {
            $output->writeln('Cannot connect to AP '.$ap->getName());

            return 1;
        }

        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-iface';
        //$opts->match = array('device' => $input->getArgument('radio'), 'mode' => 'ap');
        $opts->match = ['device' => $input->getArgument('radio')];
        $stat = $session->call('uci', 'get', $opts);
        if (!count(get_object_vars($stat->values))) {
            $output->writeln('No SSIDs/Devices found on AP '.$ap->getName());

            return 1;
        }
        $localConfigKeys = [
        'macaddr',
        'nasid',
        'r1_key_holder',
        'disabled',
        'ifname',
    ];
        foreach ($stat->values as $name => $cfg) {
            foreach ($cfg as $cfgname => $cfgvalue) {
                if ('.' == substr($cfgname, 0, 1)) {
                    unset($cfg->$cfgname);
                    continue;
                }
            }
            unset($cfg->device);

            $device = $this->doctrine->getRepository('ApManBundle\Entity\Device')->findOneBy([
            'name' => $name,
            'radio' => $radio,
        ]);
            if (is_null($device)) {
                $device = new \ApManBundle\Entity\Device();
                $device->setName($name);
                $device->setRadio($radio);
                $output->writeln('Added Radio Device '.$name);
            }
            $deviceConfig = [];
            foreach ($localConfigKeys as $lck) {
                if (isset($cfg->$lck)) {
                    $deviceConfig[$lck] = $cfg->$lck;
                    unset($cfg->$lck);
                }
            }
            $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findOneBy([
            'name' => $cfg->ssid,
        ]);
            if (is_null($ssid)) {
                // Add SSID
                $ssid = new \ApManBundle\Entity\SSID();
                $ssid->setName($cfg->ssid);
                $output->writeln('Created SSID '.$ssid->getName());
            } else {
                $output->writeln('Updating SSID '.$ssid->getName());
            }
            $em->persist($ssid);
            $em->flush();
            $ssid->importConfig($doc, $cfg);

            $device->setSSID($ssid);
            $device->setConfig($deviceConfig);
            $em->persist($device);
        }
        $em->flush();

        return 0;
    }
}
