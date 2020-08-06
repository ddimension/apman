<?php
namespace ApManBundle\Command;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class ConfigureAccessPointCommand extends Command
{
    protected static $defaultName = 'apman:config-ap'; 

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
            ->setName('apman:config-ap')
            ->setDescription('Configure all SSIDs on an accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
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

	$radios = $this->doctrine->getRepository('ApManBundle:Radio')->findBy( array(
		'accesspoint' => $ap
	));
	if (!is_array($radios) or !count($radios)) {
		$this->output->writeln("Readd this accesspoint. No radios found");
		return false;
	}
	$this->apservice->publishConfig($ap);
	/*
	$logger = new class {
	    public function debug($msg) {
		echo $msg."\n";
	    }
	};
	$ap->publishConfig($logger);
	*/
    }
}
