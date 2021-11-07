<?php
namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class MonitorCommand extends Command
{
    protected static $defaultName = 'apman:monitor'; 

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\wrtJsonRpc $rpcService, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
	$this->logger = $logger;
	$this->apservice = $apservice;
	$this->rpcService = $rpcService;
    }

    protected function configure()
    {
        $this
            ->setName('apman:monitor')
            ->setDescription('Monitor')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
	$em = $this->doctrine->getManager();
	$aps = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findBy( array(
		'IsProductive' => true
	));
	if (is_null($aps) || !is_array($aps) || !count($aps)) {
		$this->output->writeln("No productive Accesspoints found.");
		return false;
	}
	$apsNotActive=[];
	$total = 0;
	foreach ($aps as $ap) {
		$total++;
		$state = $ap->getState();
		if ($state != 'STATE_ACTIVE') {
			$apsNotActive[] = $ap;
		} 
	}
	if (!count($apsNotActive)) {
		echo "OK - All APs online|online=$total offline=0\n";
		return 0;
	}
	echo "Failure - Some APs offline|online=".($total-count($apsNotActive))." offline=".count($apsNotActive)."\n";
	return 2;
    }
}
