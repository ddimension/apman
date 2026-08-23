<?php

namespace ApManBundle\Command;

use ApManBundle\Service\HistoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Write down what every radio is doing, for cron.
 *
 * Reads the same cache the pages read and asks no access point anything, so it
 * costs the fleet nothing and can be run as often as makes sense — every five
 * minutes, from cron. A run that comes too soon after the last one is refused
 * per radio rather than doubling the series, so a run by hand next to the cron
 * is harmless.
 */
#[AsCommand(name: 'apman:sample')]
class SampleCommand extends Command
{
    public function __construct(
        private readonly HistoryService $history,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Record what every radio is doing, for the long term series')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'also throw away what is too old to keep')
            ->addOption('quiet-skips', null, InputOption::VALUE_NONE, 'do not list the radios that were passed over')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->history->sample();
        $output->writeln($report['written'].' radio(s) recorded'
            .($report['skipped'] ? ', '.count($report['skipped']).' passed over' : ''));
        if (!$input->getOption('quiet-skips')) {
            foreach ($report['skipped'] as $what => $why) {
                $output->writeln('  <comment>'.$what.'</comment> '.$why);
            }
        }
        if ($input->getOption('prune')) {
            $gone = $this->history->prune();
            $output->writeln($gone.' sample(s) older than '.HistoryService::KEEP_DAYS.' days removed');
        }

        return 0;
    }
}
