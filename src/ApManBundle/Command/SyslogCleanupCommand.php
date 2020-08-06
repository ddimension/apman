<?php
namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class SyslogCleanupCommand extends Command
{
    protected static $defaultName = 'apman:syslog-cleanup'; 

    public function __construct(\Doctrine\Bundle\DoctrineBundle\Registry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
	$this->logger = $logger;
	$this->apservice = $apservice;
    }
 
    protected function configure()
    {
        $this
            ->setName('apman:syslog-cleanup')
            ->setDescription('Remove entries ager 1day')
            ;
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
	$em = $this->doctrine->getManager();
	
	$oldest = new \Datetime();
	$oldest->SetTimeStamp(time()-86400);
	$query = $em->createQuery(
		    'DELETE
		     FROM ApManBundle:Syslog sl
		     WHERE sl.ts<:ts
			'
        );
	$query->setParameter('ts',$oldest);
        $last = $query->getResult();
    }

}
