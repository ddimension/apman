<?php
namespace ApManBundle\Command;
 
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpProcess;


//declare(ticks=1);
class DaemonCommand extends ContainerAwareCommand
{
    private $parentPID;

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
            ->setName('apman:daemon')
            ->setDescription('Poll stats in background')
#            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
#            ->addArgument('radio', InputArgument::REQUIRED, 'Radio Name')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doc = $this->container->get('doctrine');
        $ssidService = $this->container->get('apman.ssidservice');
	$em = $doc->getEntityManager();
	$this->parentPID = getmypid();
	//pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
	$loop = true;
	$childs = array();
	while ($loop) {
		$aps = $doc->getRepository('ApManBundle:AccessPoint')->findAll();
		if (!count($aps)) {
			$this->output->writeln("No APs found..");
			return false;
		}
		$apIds = array();
		foreach ($aps as $ap) {
			$apIds[] = $ap->getId();
		}
		unset($aps);
		$em->getConnection()->close();
		#echo "Fork them\n";
		foreach ($apIds as $apId) {
			$start = microtime(true);
			$pid = pcntl_fork();
			if ($pid == -1) {
				break 1;
			} else if ($pid) {
				// Parent
				$childs [] = $pid;
				continue;
			}
			// Do the work
			set_time_limit(3);
			$em->getConnection()->connect();
			$qb = $em->createQueryBuilder();
			$query = $em->createQuery(
			    'SELECT ap
			     FROM ApManBundle:AccessPoint ap
			     WHERE
			     ap.id = :id'
			);
			$query->setFetchMode("ApManBundle\AccessPoint", "ap", "EAGER");
			$query->setParameter('id', $apId);
			$ap = $query->getSingleResult();
			$session = $ap->getSession();
			$opts = new \stdclass();
			$opts->command = 'ip';
			$opts->params = array('-s', 'link', 'show');
			$opts->env = array('LC_ALL' => 'C');
			$stat = $session->callCached('file','exec', $opts, 15);

			$data = $session->callCached('network.device','status', null, 15);
			$data = $session->callCached('iwinfo','devices', null, 15);
			$data = $session->callCached('system','info', null, 15);
			$data = $session->callCached('system','board', null, 15);
			foreach ($ap->getRadios() as $radio) {
				$p = new \stdClass();
				$p->device = $radio->getName();
				$data = $session->callCached('iwinfo','info', $p , 15);
				foreach ($radio->getDevices() as $device) {
					$config = $device->getConfig();
					if (!array_key_exists('ifname', $config)) continue;
					$o = new \stdClass();
					$o->device = $config['ifname'];
					$data = $session->callCached('iwinfo','info', $o , 15);
					$data = $session->callCached('iwinfo','assoclist', $o , 15);
#print_r($data);
					if (is_object($data) && property_exists($data, 'results') && is_array($data->results)) {
						$ssidService->applyLocationConstraints($data->results, $device);
						$session->invalidateCache('iwinfo','assoclist', $o , 15);
					}

					
				}
			} 
			$stop = microtime(true);
			#echo "Polled ".$ap->getName().", took ".sprintf('%0.3f',$stop-$start)."s\n";
			exit(0);
		}
		$em->getConnection()->connect();
		sleep(5);
		if(count($childs) > 0) {
		    foreach($childs as $key => $pid) {
        		    $res = pcntl_waitpid($pid, $status, WNOHANG);
        
			    // If the process has already exited
	    		    if($res == -1 || $res > 0)
				    unset($childs[$key]);
			    // Else kill
			    posix_kill($pid, SIGTERM);
			    //echo "Killed old child $pid\n";
		    }
		}
	}
    }

    public function childSignalHandler($signo, $pid=null, $status=null) {
	if(!$pid){ 
		$pid = pcntl_waitpid(-1, $status, WNOHANG); 
	}
	while($pid > 0){
		$pid = pcntl_waitpid(-1, $status, WNOHANG);
	}
	return true;
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
