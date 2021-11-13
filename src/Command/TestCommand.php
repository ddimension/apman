<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends Command
{
    protected static $defaultName = 'apman:test';

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
            ->setName('apman:test')
            ->setDescription('Test')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->doctrine->getManager();
        /*
        $devices = $this->doctrine->getRepository('ApManBundle:Device')->findAll();
        foreach ($devices as $device) {
            $cfg = $device->getConfig();
            $status = $device->getStatus();
            print("Device Id: ".$device->getId()."\n");
            print_r($status);
        }
        $em->flush();
        exit;
        */
        $ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy([
        'name' => $input->getArgument('name'),
    ]);
        if (is_null($ap)) {
            $this->output->writeln('Add this accesspoint. Cannot find it.');

            return false;
        }
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $this->output->writeln('Failed to get session.');

            return false;
        }
        $opts = new \stdClass();
        //$opts->device = 'wmon0';
        $opts->path = '/etc/passwd';
        $stat = $session->call('file', 'read', $opts);
        print_r($stat);
        $data = $stat->data;
        $data = str_replace('/bin/bash', '/bin/ash', $data);
        print_r($data);
        $opts = new \stdClass();
        $opts->path = '/etc/passwd';
        $opts->data = $data;
        $opt->append = false;
        $stat = $session->call('file', 'write', $opts);
        exit;
        $opts->command = '/cat';
        $opts->params = ['/etc/passwd'];
        $stat = $session->call('file', 'exec', $opts);
        print_r($stat);
        exit;
        $opts = new \stdClass();
        $opts->device = 'wmon1';
        $stat = $session->call('iwinfo', 'scan', $opts);
        print_r($stat);
        exit;
        if (!isset($stat->results)) {
            $this->addFlash('sonata_flash_error', 'Failed to scan.: '.print_r($stat, true));

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
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
        $opts->values = new \stdClass();
        //	$opts->values->
        $opts->match = ['device' => $input->getArgument('radio'), 'mode' => 'ap'];
        $stat = $session->call('session', 'set', $opts);
        /*
            $opts = new \stdClass();
            $opts->config = 'wireless';
            $opts->type = 'wifi-iface';
            $opts->match = array('device' => $input->getArgument('radio'), 'mode' => 'ap');
            $stat = $session->call('uci','get', $opts);
        */
    }
}
