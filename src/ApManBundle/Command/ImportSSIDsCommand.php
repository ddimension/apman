<?php
namespace ApManBundle\Command;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class ImportSSIDsCommand extends Command
{
    protected static $defaultName = 'apman:import-ssids'; 

    public function __construct(\Doctrine\Bundle\DoctrineBundle\Registry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\wrtJsonRpc $jsonrpc, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
	$this->logger = $logger;
	$this->apservice = $apservice;
	$this->jsonrpc = $jsonrpc;
    }

    protected function configure()
    {
 
        $this
            ->setName('apman:import-ssids')
            ->setDescription('Import SSIDs from an AP radio')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addArgument('radio', InputArgument::REQUIRED, 'Radio Name')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
	$em = $this->doctrine->getManager();
	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $input->getArgument('name')
	));
	if (is_null($ap)) {
		$this->output->writeln("Add this accesspoint. Cannot find it.");
		return false;
	}

	$radio = $this->doctrine->getRepository('ApManBundle:Radio')->findOneBy( array(
		'name' => $input->getArgument('radio'),
		'accesspoint' => $ap
	));
	if (is_null($radio)) {
		$this->output->writeln("Readd this accesspoint. The given radio is missing.");
		return false;
	}

        $rpcService = $this->jsonrpc;
	$session = $rpcService->login($ap->getUbusUrl(), $ap->getUsername(), $ap->getPassword());
	if ($session === false) {
		$this->output->writeln("Cannot connect to AP ".$ap->getName());
		return false;
	}

	$opts = new \stdClass();
	$opts->config = 'wireless';
	$opts->type = 'wifi-iface';
	#$opts->match = array('device' => $input->getArgument('radio'), 'mode' => 'ap');
	$opts->match = array('device' => $input->getArgument('radio'));
	$stat = $session->call('uci','get', $opts);
	if (!count(get_object_vars($stat->values))) {
		$this->output->writeln("No SSIDs/Devices found on AP ".$ap->getName());
		return false;
	}
	$localConfigKeys = array(
		'macaddr',
		'nasid',
		'r1_key_holder',
		'disabled',
		'ifname'
	);
	foreach ($stat->values as $name => $cfg) {
		foreach ($cfg as $cfgname => $cfgvalue)  {
			if (substr($cfgname,0,1) == '.') {
				unset($cfg->$cfgname);
				continue;
			}
		}
		unset($cfg->device);

		$device = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy( array(
			'name' => $name,
			'radio' => $radio
		));
		if (is_null($device)) {
			$device = new \ApManBundle\Entity\Device();
			$device->setName($name);
			$device->setRadio($radio);
			$this->output->writeln("Added Radio Device ".$name);
		}
		$deviceConfig = array();
		foreach ($localConfigKeys as $lck) {
			if (isset($cfg->$lck)) {
				$deviceConfig[$lck] = $cfg->$lck;
				unset($cfg->$lck);
			}
		}
		$ssid = $this->doctrine->getRepository('ApManBundle:SSID')->findOneBy( array(
			'name' => $cfg->ssid
		));
		if (is_null($ssid)) {
			// Add SSID
			$ssid = new \ApManBundle\Entity\SSID();
			$ssid->setName($cfg->ssid);
			$this->output->writeln("Created SSID ".$ssid->getName());
		} else {
			$this->output->writeln("Updating SSID ".$ssid->getName());
		}
		$em->persist($ssid);
		$em->flush();
		$ssid->importConfig($doc, $cfg);

		$device->setSSID($ssid);
		$device->setConfig($deviceConfig);
		$em->persist($device);
	}
	$em->flush();
    }
}
