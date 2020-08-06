<?php
namespace ApManBundle\Command;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class AssignNeighborsCommand extends Command
{
    protected static $defaultName = 'apman:assign-neighbors'; 

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
            ->setName('apman:assign-neighbors')
            ->setDescription('Assign Neighbors')
            ->addArgument('name', InputArgument::REQUIRED, 'SSID')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doc = $this->container->get('doctrine');
	$em = $this->doctrine->getManager();
	$ssid = $this->doctrine->getRepository('ApManBundle:SSID')->findOneBy( array(
		'name' => $input->getArgument('name')
	));
	echo "SSID : ".$ssid->getName()."\n";
	$neighors = array();
	foreach ($ssid->getDevices() as $device) {
		$radio = $device->getRadio();
		$ap = $radio->getAccesspoint();
		$cfg = $device->getConfig();
		if (!isset($cfg['ifname'])) {
			$this->output->writeln("ifname missing for ".$ap->getName().":".$radio->getName().":".$device->getName());
			continue;
		}
		$session = $ap->getSession();
		if ($session === false) {
			$this->output->writeln("Cannot connect to AP ".$ap->getName());
			continue;
		}

		$nr_own = $session->call('hostapd.'.$cfg['ifname'],'rrm_nr_get_own');
		if (is_object($nr_own) && property_exists($nr_own, 'value')) {
			$neighbors[] = $nr_own->value;
		}
	}
	if (!count($neighbors)) {
		$this->output->writeln("No neighors found.");
		return false;
	}
	foreach ($ssid->getDevices() as $device) {
		$radio = $device->getRadio();
		$ap = $radio->getAccesspoint();
		$cfg = $device->getConfig();
		if (!isset($cfg['ifname'])) {
			$this->output->writeln("ifname missing for ".$ap->getName().":".$radio->getName().":".$device->getName());
			continue;
		}
		$session = $ap->getSession();
		if ($session === false) {
			$this->output->writeln("Cannot connect to AP ".$ap->getName());
			continue;
		}

		$opts = new \stdClass();
		$opts->neighbor_report = true;
		$opts->beacon_report = true;
		$opts->bss_transition = true;
		$stat = $session->call('hostapd.'.$cfg['ifname'],'bss_mgmt_enable', $opts);

		$nr_own = $session->call('hostapd.'.$cfg['ifname'],'rrm_nr_get_own');
		if (!(is_object($nr_own) && property_exists($nr_own, 'value'))) {
			continue;
		}

		$own_neighbors = array();
		foreach ($neighbors as $neighbor) {
			if ($neighbor[0] == $nr_own->value[0]) {
				continue;
			}
			$own_neighbors[] = $neighbor;
		}
		$opts = new \stdClass();
		$opts->list = $own_neighbors;

		$stat = $session->call('hostapd.'.$cfg['ifname'],'rrm_nr_set', $opts);
	}
	$this->output->writeln("Pushed neighbors for SSID ".$ssid->getName());
	print_r($neighbors);
    }
}
