<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:dbcleanup')]
class DbCleanupCommand extends Command
{

    private $doctrine;
    private $logger;
    private $apservice;

    private $history;

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\HistoryService $history, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
        $this->history = $history;
    }

    protected function configure(): void
    {
        $this
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

        // The radio series is kept far longer than a day — that is its whole
        // point — so it has its own horizon rather than this method's.
        $gone = $this->history->prune();
        if ($gone) {
            $output->writeln($gone.' radio sample(s) older than '
                .\ApManBundle\Service\HistoryService::KEEP_DAYS.' days removed');
        }

        return 0;
    }
}
