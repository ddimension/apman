<?php

namespace ApManBundle\Library;

class SyslogSocketServer {
    protected $socket;
    protected $clients = [];
    protected $changed;
    protected $logger;
    protected $em;
    protected $hb = [];
    protected $apsrv;
	
    
    function __construct($host = 'localhost', $port = 9000, \Psr\Log\LoggerInterface $logger, \Doctrine\Bundle\DoctrineBundle\Registry $doctrine, \ApManBundle\Service\AccessPointService $apsrv)
    {
	$this->em = $doctrine->getManager();
	$this->logger = $logger;
	$this->apsrv = $apsrv;
    }
    
    function initialize()
    {	    
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        //bind socket to specified host
        socket_bind($socket, 0, $port);
        //listen to port
	socket_listen($socket);
        $this->socket = $socket;
    }

    function close()
    {
        foreach($this->clients as $client) {
            socket_close($client);
        }
        socket_close($this->socket);
    }

    function run()
    {
	$this->logger->info('Created SyslogSockerServer on '.$host.':'.$port);
	$this->logger->info('Running SyslogSockerServer');
        while(true) {
            $this->waitForChange();
            $this->checkNewClients();
            $this->checkMessageReceived();
            $this->checkDisconnect();
        }
    }
    
    function checkDisconnect()
    {
        foreach ($this->changed as $changed_socket) {
            $buf = @socket_read($changed_socket, 4096, PHP_NORMAL_READ);
            if ($buf !== false) { // check disconnected client
                continue;
            }
            // remove client for $clients array
            $found_socket = array_search($changed_socket, $this->clients);
            socket_getpeername($changed_socket, $ip);
            unset($this->clients[$found_socket]);
	    $this->logger->info('Client ' . $ip . ' has disconnected');
        }
    }
    
    function checkMessageReceived()
    {
        foreach ($this->changed as $key => $socket) {
            $buffer = null;
            while(socket_recv($socket, $buffer, 4096, 0) >= 1) {
        	socket_getpeername($socket, $ip);
		$this->logger->info('Received from '.$ip.':'.trim($buffer));
		$this->saveMessage($ip, $buffer);
                unset($this->changed[$key]);
                break;
            }
        }
    }
    
    function waitForChange()
    {
        //reset changed
        $this->changed = array_merge([$this->socket], $this->clients);
        //variable call time pass by reference req of socket_select
        $null = null;
        //this next part is blocking so that we dont run away with cpu
        socket_select($this->changed, $null, $null, null);
    }
    
    function checkNewClients()
    {
        if (!in_array($this->socket, $this->changed)) {
            return; //no new clients
        }
        $socket_new = socket_accept($this->socket); //accept new socket
        $first_line = socket_read($socket_new, 4096);
        socket_getpeername($socket_new, $ip);
        $this->logger->info('a new client has connected from:'.$ip);
        $this->logger->info('the new client '.$ip.' says ' . trim($first_line) . PHP_EOL);
	$this->saveMessage($ip, $first_line);
        $this->clients[] = $socket_new;
        unset($this->changed[0]);
    }
    
    
    function saveMessage($source, $msg)
    {
	if (strlen($msg)<1) {
		return;
	}
	$lines = explode("\n", $msg);
	foreach ($lines as $index => $line) {
		if (($index+1) == count($lines)) {
			if (strlen($line)) {
				$this->hb[$source] = $line;
				$this->logger->info("Saving string, unfinished line");
			}
			break 1;
		}

		$full = '';
		if (isset($this->hb[$source])) {
			$full.= $this->hb[$source];
			unset($this->hb[$source]);
		}
		$full.= $line;

		$entry = new \ApManBundle\Entity\Syslog();
		$entry->setTs(new \DateTime('now'));
		$entry->setSource($source);
		$entry->setMessage($full);
		$this->em->persist($entry);

		// Check for Events
		$this->apsrv->processLogMessage($entry);
	}
	$this->em->flush();
    }
}

?>
