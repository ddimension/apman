<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The fleet's state as the tree it is: access point → radio → bss.
 *
 * Until the pages read it (stage two), this is the only way to look at what
 * StateTreeService composes. It reads and prints; it changes nothing.
 */
#[AsCommand(name: 'apman:state')]
class StateTreeCommand extends \Symfony\Component\Console\Command\Command
{
    private $doctrine;
    private $stateTree;

    public function __construct(
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\StateTreeService $stateTree
    ) {
        parent::__construct();
        $this->doctrine = $doctrine;
        $this->stateTree = $stateTree;
    }

    protected function configure(): void
    {
        $this->addOption('ap', null, InputOption::VALUE_REQUIRED, 'only this access point')
            ->addOption('all', null, InputOption::VALUE_NONE, 'include disabled radios and bsses')
            ->setDescription('the access point / radio / bss state tree');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('ap');
        $all = (bool) $input->getOption('all');
        $aps = $this->doctrine->getManager()
            ->getRepository('ApManBundle\Entity\AccessPoint')->findAll();

        $counts = [];
        foreach ($aps as $ap) {
            if ($only && $ap->getName() !== $only) {
                continue;
            }
            $tree = $this->stateTree->ap($ap);
            $output->writeln(sprintf('%-16s %-12s %s',
                $ap->getName(), $tree['state_name'], $this->age($tree)));
            $counts[$tree['state_name']] = ($counts[$tree['state_name']] ?? 0) + 1;

            foreach ($tree['children'] as $radio) {
                if (!$all && 'DISABLED' === $radio['state_name']) {
                    continue;
                }
                $output->writeln(sprintf('   %-13s %-12s %s',
                    $radio['name'], $radio['state_name'], $this->age($radio)));
                foreach ($radio['children'] as $bss) {
                    if (!$all && 'DISABLED' === $bss['state_name']) {
                        continue;
                    }
                    $output->writeln(sprintf('      %-24s %-10s %s',
                        $bss['name'], $bss['state_name'], $this->age($bss)));
                }
            }
            $output->writeln('');
        }

        $line = [];
        foreach ($counts as $name => $n) {
            $line[] = $n.'× '.$name;
        }
        $output->writeln('access points: '.implode(', ', $line));

        return 0;
    }

    /** how long in this state, and how long since anything was heard */
    private function age(array $node): string
    {
        $out = [];
        if ($node['since']) {
            $out[] = 'since '.$this->short(time() - (int) $node['since']);
        }
        if ($node['seen']) {
            $seen = time() - (int) $node['seen'];
            $out[] = 'seen '.$this->short($seen).' ago'.($node['fresh'] ? '' : ' (stale)');
        }

        return $out ? '('.implode(', ', $out).')' : '(never heard from)';
    }

    private function short(int $s): string
    {
        if ($s < 90) {
            return $s.'s';
        }
        if ($s < 5400) {
            return intdiv($s, 60).'m';
        }

        return intdiv($s, 3600).'h'.str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);
    }
}
