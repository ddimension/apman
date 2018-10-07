<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
 
class LogTailCommand extends ContainerAwareCommand
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
            ->setName('apman:logtail')
            ->setDescription('Logtail')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doc = $this->container->get('doctrine');
	$em = $doc->getManager();
	
	$query = $em->createQuery(
		    'SELECT sl
		     FROM ApManBundle:Syslog sl
		     ORDER BY sl.ts DESC
			'
        );
	$query->setFirstResult(2000);
	$query->setMaxResults(1);
        $last = $query->getSingleResult();
	while (true) {
		sleep(1);
		$query = $em->createQuery(
			    'SELECT sl
			     FROM ApManBundle:Syslog sl
			     WHERE
			     sl.id>:id
			     ORDER BY sl.id ASC'
		);
		$query->setParameter('id', $last->getId());
		$entries = $query->getResult();
		foreach ($entries as $entry) {
			$this->output->writeln(sprintf("% 15s:%s", $entry->getSource(),$entry->getMessage()));
			$last = $entry;
		}
	}
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
