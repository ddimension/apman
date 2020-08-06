<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
 
class ConfigureAccessPointCommand extends ContainerAwareCommand
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
            ->setName('apman:config-ap')
            ->setDescription('Configure all SSIDs on an accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        #$logger = $this->container->get('logger');
        $doc = $this->container->get('doctrine');
	$em = $doc->getManager();
	$this->container->get('apman.accesspointservice');
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
	$this->container->get('apman.accesspointservice')->publishConfig($ap);
	/*
	$logger = new class {
	    public function debug($msg) {
		echo $msg."\n";
	    }
	};
	$ap->publishConfig($logger);
	 */
    }

    private function logwrap($level, $message) {
	$message = $this->getName().': '.$message;
	$options = $this->input->getOptions();
	if (isset($options['verbose']) && $options['verbose'] == 1) {
		$this->output->writeln($message);
	}
	#call_user_func(array($this->logger,$level),$message);
    }

}
