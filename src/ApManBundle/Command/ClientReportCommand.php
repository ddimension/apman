<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
 
class ClientReportCommand extends ContainerAwareCommand
{

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output); //initialize parent class methods
	$this->container = $this->getContainer();
	$this->logger = $this->container->get('logger');
	$this->input = $input;
	$this->output = $output;
    }

    protected function configure()
    {
 
        $this
            ->setName('apman:clientreport')
            ->setDescription('Get Beacon Reports of all clients')
            ->addArgument('ssid', InputArgument::REQUIRED, 'SSID')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doc = $this->container->get('doctrine');
	$em = $doc->getManager();
	$ssid = $doc->getRepository('ApManBundle:SSID')->findOneBy( array(
		'name' => $input->getArgument('ssid')
	));
	if (is_null($ssid)) {
		$this->output->writeln("SSID not found.");
		return false;
	}
	$startTime = new \DateTime('now');
	foreach ($ssid->getDevices() as $device) {
		$radio = $device->getRadio();
		$ap = $radio->getAccesspoint();
		$cfg = $device->getConfig();
		$clients = $device->getClients(true);
		//$this->output->writeln("Clients: ".print_r($clients,true));
		if (!count($clients)) {
			continue;
		}
		if (!isset($cfg['ifname'])) {
			$this->output->writeln("ifname missing for ".$ap->getName().":".$radio->getName().":".$device->getName());
			continue;
		}
		$session = $ap->getSession();
		if ($session === false) {
			$this->output->writeln("Cannot connect to AP ".$ap->getName());
			continue;
		}

		
		// 1s base
		$duration = 400;
		$duration = 10;
		foreach ($clients as $client) {
			$this->output->writeln("Requesting Report for Client ".$client);
			$opts = new \stdClass();
			$opts->addr = $client;
			$opts->mode = 0;
			$opts->op_class = 0;
			$opts->channel = 0;
			// base 100ms
			$opts->duration = $duration*10;
			$opts->bssid = 'ff:ff:ff:ff:ff:ff';
			$opts->ssid = $ssid->getName();
			$stat = $session->call('hostapd.'.$cfg['ifname'],'rrm_beacon_req', $opts);
			usleep(250000);
		}
		
	}
	sleep(10);
	$query = $em->createQuery(
		    'SELECT sl
		     FROM ApManBundle:Syslog sl
		     WHERE
		     sl.ts>:ts
		     AND sl.message LIKE :ptr
		     ORDER BY sl.ts ASC'
        );
        $query->setParameter('ts', $startTime);
        $query->setParameter('ptr', '%beacon%');
        $entries = $query->getResult();
	foreach ($entries as $entry) {
		$this->output->writeln(sprintf("% 15s:%s", $entry->getSource(),$entry->getMessage()));
	}
    }

    private function logwrap($level, $message) {
	$message = $this->getName().': '.$message;
	$options = $this->input->getOptions();
	if (isset($options['verbose']) && $options['verbose'] == 1) {
		$this->output->writeln($message);
	}
	call_user_func(array($this->logger,$level),$message);
    }

}
