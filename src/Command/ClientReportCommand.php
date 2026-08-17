<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:clientreport')]
class ClientReportCommand extends Command
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
            ->setDescription('Get Beacon Reports of all clients')
            ->addArgument('ssid', InputArgument::REQUIRED, 'SSID')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findOneBy([
        'name' => $input->getArgument('ssid'),
    ]);
        if (is_null($ssid)) {
            $output->writeln('SSID not found.');

            return 1;
        }
        $startTime = new \DateTime('now');
        foreach ($ssid->getDevices() as $device) {
            $radio = $device->getRadio();
            $ap = $radio->getAccesspoint();
            $cfg = $device->getConfig();
            $clients = $device->getClients(true);
            //$output->writeln("Clients: ".print_r($clients,true));
            if (!count($clients)) {
                continue;
            }
            if (empty($device->getIfname())) {
                $output->writeln('ifname missing for '.$ap->getName().':'.$radio->getName().':'.$device->getName());
                continue;
            }
            $session = $this->rpcService->getSession($ap);
            if (false === $session) {
                $output->writeln('Cannot connect to AP '.$ap->getName());
                continue;
            }

            // 1s base
            $duration = 400;
            $duration = 10;
            foreach ($clients as $client) {
                $output->writeln('Requesting Report for Client '.$client);
                $opts = new \stdClass();
                $opts->addr = $client;
                $opts->mode = 0;
                $opts->op_class = 0;
                $opts->channel = 0;
                // base 100ms
                $opts->duration = $duration * 10;
                $opts->bssid = 'ff:ff:ff:ff:ff:ff';
                $opts->ssid = $ssid->getName();
                $stat = $session->call('hostapd.'.$device->getIfname(), 'rrm_beacon_req', $opts);
                usleep(250000);
            }
        }
        sleep(10);
        $query = $em->createQuery(
            'SELECT sl
		     FROM ApManBundle\Entity\Syslog sl
		     WHERE
		     sl.ts>:ts
		     AND sl.message LIKE :ptr
		     ORDER BY sl.ts ASC'
        );
        $query->setParameter('ts', $startTime);
        $query->setParameter('ptr', '%beacon%');
        $entries = $query->getResult();
        foreach ($entries as $entry) {
            $output->writeln(sprintf('% 15s:%s', $entry->getSource(), $entry->getMessage()));
        }

        return 0;
    }
}
