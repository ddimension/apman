<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:dbcleanup')]
class DbCleanupCommand extends Command
{
    /**
     * how long a RADIUS decision is kept.
     *
     * Long enough to answer "when did this device last get in", short enough
     * that the table stops growing: at the measured 2500 rows a day this holds
     * it near 75000 rows instead of a million a year.
     */
    public const RADIUS_KEEP_DAYS = 30;


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

        // radius_auth had nothing pruning it at all. Measured on 2026-08-24:
        // 33466 rows over seven days, seven megabytes, about 2500 rows a day in
        // the quiet case and 22570 on the day of the macaddr_acl incident —
        // growing without end because every authentication decision is kept
        // and none is ever removed.
        //
        // Thirty days rather than this method's two, because what the table is
        // for is answering "when did this device last get in, and with which
        // key", and two days cannot answer that. Nothing on the pages needs
        // more: they ask in twenty-four hour windows, and the one unbounded
        // query is ORDER BY id DESC LIMIT 200, which never reaches back that
        // far anyway.
        $oldest = new \Datetime();
        $oldest->SetTimeStamp(time() - self::RADIUS_KEEP_DAYS * 86400);
        $query = $em->createQuery(
            'DELETE FROM ApManBundle\Entity\RadiusAuth ra WHERE ra.created < :ts'
        );
        $query->setParameter('ts', $oldest);
        $gone = (int) $query->execute();
        if ($gone) {
            $output->writeln($gone.' radius decision(s) older than '
                .self::RADIUS_KEEP_DAYS.' days removed');
        }

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
