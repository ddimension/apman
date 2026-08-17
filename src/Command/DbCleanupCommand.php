<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DbCleanupCommand extends Command
{
    protected static $defaultName = 'apman:dbcleanup';

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
    }

    protected function configure(): void
    {
        $this
            ->setName('apman:dbcleanup')
            ->setDescription('Remove entries ager 1day')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();

        $oldest = new \Datetime();
        $oldest->SetTimeStamp(time() - 86400);
        $query = $em->createQuery(
            'DELETE
		     FROM ApManBundle\Entity\Syslog sl
		     WHERE sl.ts<:ts
			'
        );
        $query->setParameter('ts', $oldest);
        $last = $query->getResult();

        $oldest = new \Datetime();
        $oldest->SetTimeStamp(time() - (2 * 86400));
        $query = $em->createQuery(
            'DELETE
		     FROM ApManBundle\Entity\ClientHeatMap ch
		     WHERE ch.ts<:ts
			'
        );
        $query->setParameter('ts', $oldest);
        $last = $query->getResult();

        $query = $em->createQuery(
            'DELETE
		     FROM ApManBundle\Entity\Event ev
		     WHERE ev.ts<:ts
			'
        );
        $query->setParameter('ts', $oldest);
        $last = $query->getResult();

        return 0;
    }
}
