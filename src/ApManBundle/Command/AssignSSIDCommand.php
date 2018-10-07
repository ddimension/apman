<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
 
class AssignSSIDCommand extends ContainerAwareCommand
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
            ->setName('apman:assign-ssid')
            ->setDescription('Assign SSID to an accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addArgument('ssid', InputArgument::REQUIRED, 'SSID')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doc = $this->container->get('doctrine');
	$em = $doc->getEntityManager();
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $input->getArgument('name')
	));
	if (is_null($ap)) {
		$this->output->writeln("Add this accesspoint. Cannot find it.");
		return false;
	}

	$radios = $doc->getRepository('ApManBundle:Radio')->findBy( array(
		'accesspoint' => $ap
	));
	if (!is_array($radios) or !count($radios)) {
		$this->output->writeln("Readd this accesspoint. No radios found");
		return false;
	}
	$ssid = $doc->getRepository('ApManBundle:SSID')->findOneBy( array(
		'name' => $input->getArgument('ssid')
	));
	if (is_null($ssid)) {
		$this->output->writeln("SSID not found.");
		return false;
	}

	$ssids = array($ssid);
	foreach ($ssids as $ssid) {
		$localConfigKeys = array(
			'macaddr',
			'nasid',
			'r1_key_holder',
			'disabled',
			'ifname'
		);
		$i = -1;
		foreach ($radios as $radio) {
			$i++;
			$device = $doc->getRepository('ApManBundle:Device')->findOneBy( array(
				'ssid' => $ssid,
				'radio' => $radio
			));
			if (!is_null($device)) {
				$this->output->writeln("Radio Device ".$device->getName()." for SSID ".$ssid->getName().' already exists.');
				continue;

			}

			$device = new \ApManBundle\Entity\Device();
			$device->setName($radio->getName().'_'.str_replace(array('-','+','/','*','$'),'_',$ssid->getName()));
			$device->setRadio($radio);
			$device->setSSID($ssid);

			$deviceConfig = array();
			$deviceConfig['macaddr'] = exec($this->container->get('kernel')->getRootDir().'/../bin/randmac.pl');
			if (!$deviceConfig['macaddr']) {
				return false;
			}
			$ssidConfig = $ssid->exportConfig();
			if (isset($ssidConfig->ieee80211r) && $ssidConfig->ieee80211r==1) {
				$deviceConfig['nasid'] = str_replace(':', '', $deviceConfig['macaddr']);
				$deviceConfig['r1_key_holder'] = str_replace(':', '', $deviceConfig['macaddr']);
			}
			if (isset($ssidConfig->ifname) && !empty($ssidConfig->ifname)) {
				$deviceConfig['ifname'] = $ssidConfig->ifname.$i;
			}
			$device->setConfig($deviceConfig);
			$em->persist($device);
			$this->output->writeln("Added Radio Device ".$device->getName()." for SSID ".$ssid->getName());
		}
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
