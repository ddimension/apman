<?php
namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class TestCommand extends Command
{
    protected static $defaultName = 'apman:test'; 

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
            ->setName('apman:test')
            ->setDescription('Test')
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
	$session = $ap->getSession();
	if ($session === false) {
		$this->output->writeln("Failed to get session.");
		return false;
	}
	print_r($session);
	$opts = new \stdClass();
	$opts->device = 'wmon0';
	$stat = $session->call('iwinfo','scan', $opts);
	print_r($stat);
	$opts = new \stdClass();
	$opts->device = 'wmon1';
	$stat = $session->call('iwinfo','scan', $opts);
	print_r($stat);
	exit;
	if (!isset($stat->results)) {
		$this->addFlash('sonata_flash_error', 'Failed to scan.: '.print_r($stat,true));
		return new RedirectResponse($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
	}

	foreach ($ap->getRadios() as $radio) {
		print_r($radio->getConfig());
		$radio->importConfig($radio->getConfig());
		$em->persist($radio);
	}
	$em->flush();
	exit;
	$session = $ap->getSession();
	print_r($session);
	$sid = $session->getSessionId();
	echo "SID $sid\n";
	exit;
	$opts = new \stdClass();
	$opts->ubus_rpc_session = $sid;
	$opts->values = new \stdClass;
#	$opts->values->
	$opts->match = array('device' => $input->getArgument('radio'), 'mode' => 'ap');
	$stat = $session->call('session','set', $opts);
/*
	$opts = new \stdClass();
	$opts->config = 'wireless';
	$opts->type = 'wifi-iface';
	$opts->match = array('device' => $input->getArgument('radio'), 'mode' => 'ap');
	$stat = $session->call('uci','get', $opts);
*/
    }
}
