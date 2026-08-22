<?php

namespace ApManBundle\Command;

use ApManBundle\Service\ChannelPlanService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What one radio may use, asked of the radio.
 *
 *     apman:channel-plan ap-av-attic radio1 --refresh
 *
 * Exists to be held against `iwinfo <if> freqlist` on the device: if the two
 * disagree, the parser is wrong and the page built on it is wrong with it.
 */
#[AsCommand(name: 'apman:channel-plan')]
class ChannelPlanCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly ChannelPlanService $planner,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('The channels and widths a radio may use')
            ->addArgument('ap', InputArgument::REQUIRED, 'Access point name')
            ->addArgument('radio', InputArgument::OPTIONAL, 'Radio name, or all of them')
            ->addOption('refresh', 'r', InputOption::VALUE_NONE, 'ask the device instead of the cache')
            ->addOption('json', null, InputOption::VALUE_NONE, 'print the whole plan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findOneBy(['name' => $input->getArgument('ap')]);
        if (!$ap) {
            $output->writeln('<error>no such access point</error>');

            return 1;
        }
        $want = $input->getArgument('radio');
        $found = 0;
        foreach ($ap->getRadios() as $radio) {
            if ($want && $radio->getName() !== $want) {
                continue;
            }
            ++$found;
            $plan = $this->planner->plan($radio, (bool) $input->getOption('refresh'));
            $output->writeln('');
            $output->writeln('<info>'.$ap->getName().' '.$radio->getName().'</info>');
            if (!$plan) {
                $output->writeln('  <comment>no answer — no interface up on this radio?</comment>');
                continue;
            }
            if ($input->getOption('json')) {
                $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                continue;
            }
            $output->writeln('  '.$plan['ifname'].' / '.($plan['phy'] ?? '?')
                .'  country '.($plan['country'] ?? '?')
                .'  '.count($plan['channels']).' channels'
                .(null !== ($plan['own_airtime'] ?? null) ? '  own airtime '.$plan['own_airtime'].'%' : ''));
            $busy = [];
            foreach ($plan['channels'] as $ch => $c) {
                if (null !== $c['busy_pct'] || $c['bss']) {
                    $busy[] = $ch.':'.(null !== $c['busy_pct'] ? $c['busy_pct'].'%' : '?')
                        .($c['bss'] ? '/'.$c['bss'].'bss' : '');
                }
            }
            if ($busy) {
                $output->writeln('  busy      '.implode('  ', $busy));
            }
            foreach ($plan['widths'] as $w => $r) {
                if (!$r['ok'] && 20 !== $w) {
                    continue;
                }
                $output->writeln(sprintf('  %4d MHz  %s', $w, $r['ok'] ? implode(' ', $r['ok']) : '—'));
            }
            $notes = [];
            foreach ($plan['channels'] as $ch => $c) {
                $tags = array_filter([$c['dfs'] ? 'dfs' : null, $c['indoor'] ? 'indoor' : null,
                    $c['no_ir'] ? 'no-ir' : null, $c['active'] ? 'in use' : null]);
                if ($tags) {
                    $notes[] = $ch.' ('.implode(', ', $tags).')';
                }
            }
            if ($notes) {
                $output->writeln('  notes     '.implode('  ', $notes));
            }
        }
        if (!$found) {
            $output->writeln('<error>no such radio on this access point</error>');

            return 1;
        }

        return 0;
    }
}
