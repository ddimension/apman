<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LogTailCommand extends Command
{
    protected static $defaultName = 'apman:logtail';

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
            ->setName('apman:logtail')
            ->setDescription('Logtail')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();

        $query = $em->createQuery(
            'SELECT sl
		     FROM ApManBundle\Entity\Syslog sl
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
			     FROM ApManBundle\Entity\Syslog sl
			     WHERE
			     sl.id>:id
			     ORDER BY sl.id ASC'
            );
            $query->setParameter('id', $last->getId());
            $entries = $query->getResult();
            foreach ($entries as $entry) {
                $this->output->writeln(sprintf('% 15s:%s', $entry->getSource(), $entry->getMessage()));
                $last = $entry;
            }
        }

        return 0;
    }
}
