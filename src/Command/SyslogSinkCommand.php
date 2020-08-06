<?php
namespace ApManBundle\Command;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class SyslogSinkCommand extends Command
{
    protected static $defaultName = 'apman:syslog'; 

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Library\SyslogSocketServer $syslog, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
	$this->logger = $logger;
	$this->syslog = $syslog;
    }

    protected function configure()
    {
        $this
            ->setName('apman:syslog')
            ->setDescription('Syslog Sink Server')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
	$this->syslog->initialize();
	$this->syslog->run();
	$this->syslog->close();
    }
}
