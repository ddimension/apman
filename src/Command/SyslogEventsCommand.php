<?php

namespace ApManBundle\Command;

use ApManBundle\Service\SyslogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The log events kept past an access point's own ring buffer.
 */
#[AsCommand(name: 'apman:syslog-events')]
class SyslogEventsCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly SyslogService $syslog,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show the kept log events, or read them out of the rings again')
            ->addOption('rescan', null, InputOption::VALUE_NONE,
                'pick up what is still in the ring buffers — for after a detector changed')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $aps = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findBy([], ['name' => 'ASC']);

        if ($input->getOption('rescan')) {
            $total = 0;
            foreach ($aps as $ap) {
                $n = $this->syslog->rescan($ap);
                $total += $n;
                if ($n) {
                    $output->writeln(sprintf('  %-14s %d picked up', $ap->getName(), $n));
                }
            }
            $output->writeln($total ? $total.' event(s) taken out of the rings'
                : 'nothing in the rings that is not already kept');
        }

        $fleet = $this->syslog->fleetEvents(25);
        if (!$fleet['total']) {
            $output->writeln('no events kept — which is the good case');

            return 0;
        }
        foreach ($fleet['recent'] as $e) {
            $output->writeln(sprintf('  %s  %-14s <%s>%-26s</> %s',
                date('d.m. H:i', (int) $e['ts']), $e['ap'],
                $e['bad'] ? 'error' : 'info', $e['label'],
                substr((string) $e['text'], 0, 70)));
        }
        $output->writeln('');
        foreach ($fleet['counts'] as $key => $n) {
            $output->writeln(sprintf('  %-22s %d', $key, $n));
        }

        return 0;
    }
}
