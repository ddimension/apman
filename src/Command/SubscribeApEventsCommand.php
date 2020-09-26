<?php
namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class SubscribeApEventsCommand extends Command
{
    protected static $defaultName = 'apman:subscribe'; 

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
            ->setName('apman:subscribe')
            ->setDescription('Subscribe AP Events')
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
	$session = $this->rpcService->getSession($ap);
	if ($session === false) {
		$output->writeln("Failed to get session.");
		return false;
	}
	$output->writeln('Session ID: '.$session->getSessionID());
	$chs = [];
	foreach ($ap->getRadios() as $radio) {
		foreach ($radio->getDevices() as $device) {
			$output->writeln("Add subscribtion for ".$device->getIfname());

			$ch = null;
			$ch = curl_init();
			$ifname = $device->getIfname();
			$ubus_url = $ap->getUbusUrl();
			if (substr($ubus_url, -1) != '/') {
				$ubus_url.= '/';
			}
			$url = $ubus_url.'subscribe/hostapd.'.$ifname;
			$output->writeln("URL ".$url);
			$obj = new \stdClass();
			$obj->ifname = $ifname;
			$obj->writer = function ($ch, $str) {
			    $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
			    print('Read Buffer '.basename($url).': '.$str);
			    return strlen($str);
			};
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_VERBOSE, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
			curl_setopt($ch, CURLOPT_WRITEFUNCTION, $obj->writer);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			    'Authorization: Bearer '.$session->getSessionId()
		        ));
		        $chs[] = $ch;
		}	
	}
	$mh = curl_multi_init();
	foreach ($chs as $ch) {
		curl_multi_add_handle($mh,$ch);
	}
	//execute the multi handle
	do {
	    $status = curl_multi_exec($mh, $active);
	    if ($active) {
		curl_multi_select($mh);
	    }
	    $info = curl_multi_info_read($mh);
	    var_dump($info);
	    if (is_array($info)) {
		if ($info['result'] == 999) {
			$output->writeln("Closing handle");
			curl_multi_remove_handle($mh,$info['handle']);
			curl_multi_add_handle($mh,$info['handle']);
		}
	    }
	    $output->writeln("Status ".$status.', active: '.($active?'1':'0'));

	} while ($active && $status == CURLM_OK);
	foreach ($chs as $ch) {
		curl_multi_remove_handle($mh,$ch);
	}
	curl_multi_close($mh);
	exit;
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
	$session = $this->rpcService->getSession($ap);
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
