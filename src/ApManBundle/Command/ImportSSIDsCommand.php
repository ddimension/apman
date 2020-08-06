<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
 
class ImportSSIDsCommand extends ContainerAwareCommand
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
            ->setName('apman:import-ssids')
            ->setDescription('Import SSIDs from an AP radio')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addArgument('radio', InputArgument::REQUIRED, 'Radio Name')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doc = $this->container->get('doctrine');
	$em = $doc->getManager();
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $input->getArgument('name')
	));
	if (is_null($ap)) {
		$this->output->writeln("Add this accesspoint. Cannot find it.");
		return false;
	}

	$radio = $doc->getRepository('ApManBundle:Radio')->findOneBy( array(
		'name' => $input->getArgument('radio'),
		'accesspoint' => $ap
	));
	if (is_null($radio)) {
		$this->output->writeln("Readd this accesspoint. The given radio is missing.");
		return false;
	}

        $rpcService = $this->container->get('ApManBundle\Service\wrtJsonRpc');
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

		$device = $doc->getRepository('ApManBundle:Device')->findOneBy( array(
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
		$ssid = $doc->getRepository('ApManBundle:SSID')->findOneBy( array(
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

    private function logwrap($level, $message) {
	$message = $this->getName().': '.$message;
	$options = $this->input->getOptions();
	if (isset($options['verbose']) && $options['verbose'] == 1) {
		$this->output->writeln($message);
	}
	call_user_func(array($this->logger,$level),$message);
    }

}
