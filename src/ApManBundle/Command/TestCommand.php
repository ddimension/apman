<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
 
class TestCommand extends ContainerAwareCommand
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
            ->setName('apman:test')
            ->setDescription('Test')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
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

    private function logwrap($level, $message) {
	$message = $this->getName().': '.$message;
	$options = $this->input->getOptions();
	if (isset($options['verbose']) && $options['verbose'] == 1) {
		$this->output->writeln($message);
	}
	call_user_func(array($this->logger,$level),$message);
    }

}
