<?php
namespace ApManBundle\Command;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
 
class AddAccessPointCommand extends Command
{
    protected static $defaultName = 'apman:add-ap'; 

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
	$em = $this->doctrine->getManager();
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

}
