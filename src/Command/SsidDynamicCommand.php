<?php

namespace ApManBundle\Command;

use ApManBundle\Service\RolloutService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move a network on to changes under running radios, or back off them.
 *
 *     apman:ssid-dynamic                 where every network stands
 *     apman:ssid-dynamic kaltest         what it would take for this one
 *     apman:ssid-dynamic kaltest --on    make the change
 *     apman:ssid-dynamic kaltest --off   and back
 *
 * Without --on or --off nothing is written; the check runs and reports, which
 * is the same check the switch itself runs. Asking is meant to be free.
 */
#[AsCommand(name: 'apman:ssid-dynamic')]
class SsidDynamicCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly RolloutService $rollout,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Whether a network is changed under running radios, and the way there and back')
            ->addArgument('ssid', InputArgument::OPTIONAL, 'the network; omit to list them all')
            ->addOption('on', null, InputOption::VALUE_NONE, 'change it under running radios from now on')
            ->addOption('off', null, InputOption::VALUE_NONE, 'go back to changing it with a radio restart')
            ->addOption('force', null, InputOption::VALUE_NONE, 'switch it on even where it is not ready')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'run the check, write nothing')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('ssid');
        if (null === $name) {
            return $this->list($output);
        }

        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findOneBy(['name' => $name]);
        if (!$ssid) {
            $output->writeln('<error>no such network: '.$name.'</error>');

            return 1;
        }

        $on = (bool) $input->getOption('on');
        $off = (bool) $input->getOption('off');
        if ($on && $off) {
            $output->writeln('<error>--on and --off at the same time</error>');

            return 1;
        }

        // No direction asked for: report where it stands and what it would
        // take, and touch nothing.
        $target = $on ? true : ($off ? false : $ssid->isDynamic());
        $report = $this->rollout->migrate($ssid, $target,
            (bool) $input->getOption('dry-run') || (!$on && !$off),
            (bool) $input->getOption('force'));

        $output->writeln($ssid->getName().': '.($ssid->isDynamic()
            ? '<info>changed under the running radios</info>'
            : 'changed with a radio restart'));

        if (!$report['aps']) {
            $output->writeln('  it is on no radio anywhere, so there is nothing to check');
        }
        foreach ($report['aps'] as $apName => $entry) {
            $output->writeln(sprintf('  <comment>%-14s</comment> %s  %d bss on %s',
                $apName,
                $entry['ready'] ? '<info>ready</info>' : '<error>not ready</error>',
                $entry['devices'], implode(', ', $entry['radios'])));
            foreach ($entry['blockers'] as $blocker) {
                $output->writeln('      <error>·</error> '.$blocker);
            }
            foreach ($entry['notes'] as $note) {
                $output->writeln('      · '.$note);
            }
        }

        if (!$on && !$off) {
            $output->writeln($report['blocking']
                ? '<comment>not ready on '.implode(', ', $report['blocking']).'</comment>'
                : '<info>ready everywhere it runs</info>');

            return 0;
        }

        if (!$report['ok']) {
            $output->writeln('<error>'.($report['error'] ?? 'refused').'</error>');

            return 1;
        }
        if ($report['unchanged']) {
            $output->writeln('<info>it was already '.$report['to'].', nothing to do</info>');

            return 0;
        }
        if ($input->getOption('dry-run')) {
            $output->writeln('<info>would become '.$report['to'].' — this was a dry run</info>');

            return 0;
        }

        $output->writeln($target
            ? '<info>'.$ssid->getName().' is now added to and taken off radios while they run. '
                .'Nothing has changed on any access point: both ways write the same configuration, '
                .'and the difference is only whether the radios come down on the way.</info>'
            : '<info>'.$ssid->getName().' goes back to changing with a radio restart. '
                .'Nothing has changed on any access point either — the next change is what costs, '
                .'and it will cost a restart of every radio that carries this network.</info>');

        return 0;
    }

    private function list(OutputInterface $output): int
    {
        $ssids = $this->doctrine->getRepository('ApManBundle\Entity\SSID')
            ->findBy([], ['name' => 'ASC']);
        if (!$ssids) {
            $output->writeln('<error>there are no networks</error>');

            return 1;
        }
        foreach ($ssids as $ssid) {
            $output->writeln(sprintf('  %-20s %s', $ssid->getName(),
                $ssid->isDynamic() ? '<info>under running radios</info>' : 'with a radio restart'));
        }
        $output->writeln('');
        $output->writeln('apman:ssid-dynamic <name> says what it would take to change one of them.');

        return 0;
    }
}
