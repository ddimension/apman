<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
 
class AddAccessPointCommand extends ContainerAwareCommand
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
            ->setName('apman:add-ap')
            ->setDescription('Add Accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addArgument('username', InputArgument::REQUIRED, 'username')
            ->addArgument('password', InputArgument::REQUIRED, 'password')
            ->addArgument('ubus_url', InputArgument::REQUIRED, 'UBUS URL: http://firewall/ubus')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doc = $this->container->get('doctrine');
	$em = $doc->getEntityManager();
	$ap = new \ApManBundle\Entity\AccessPoint();
	$ap->setName($input->getArgument('name'));
	$ap->setUsername($input->getArgument('username'));
	$ap->setPassword($input->getArgument('password'));
	$ap->setUbusUrl($input->getArgument('ubus_url'));
	$session = $ap->getSession();
	if ($session === false) {
		$this->output->writeln("Cannot connect to AP ".$ap->getName());
		return false;
	}
	$opts = new \stdClass();
	$opts->config = 'wireless';
	$opts->type = 'wifi-device';
	$stat = $session->call('uci','get', $opts);
	if (!isset($stat->values) || !count($stat->values)) {
		$this->output->writeln("No radios found on AP ".$ap->getName());
		return false;
	}
	foreach ($stat->values as $name => $cfg) {
		$this->output->writeln("Adding radio ".$name);
		$radio = new \ApManBundle\Entity\Radio(); 
		$radio->setAccessPoint($ap);
		$radio->setName($name);
		$radio->importConfig($cfg);
		$em->persist($radio);
	}
	$em->persist($ap);
	$em->flush();
	$this->output->writeln("Saved AP ".$ap->getName()." with id ".$ap->getId());
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
