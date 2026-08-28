<?php

namespace ApManBundle\Command;

use ApManBundle\Service\HostapdBuildService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Which hostapd each access point runs.
 *
 * The rollout in docs/hostapd-sae-radius.md is deliberately one access point at
 * a time, which means the fleet is mixed for as long as it takes — and while it
 * is mixed, "is sae_pwe allowed here" has a different answer per access point.
 * This is the question asked out loud.
 */
#[AsCommand(name: 'apman:hostapd-build')]
class HostapdBuildCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly HostapdBuildService $builds,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Say which hostapd build every access point runs')
            ->addArgument('name', InputArgument::OPTIONAL, 'one access point, instead of all of them')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'ask the devices again instead of using the hourly cache')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint');
        $name = $input->getArgument('name');
        $aps = $name ? $repo->findBy(['name' => $name]) : $repo->findBy([], ['name' => 'ASC']);
        if (!$aps) {
            $output->writeln('<error>'.($name ? 'no such access point: '.$name : 'no access points').'</error>');

            return 1;
        }

        $patched = 0;
        $unknown = 0;
        foreach ($aps as $ap) {
            if ($input->getOption('refresh')) {
                $this->builds->forget($ap);
            }
            $b = $this->builds->of($ap);
            if (!$b['known']) {
                ++$unknown;
                $output->writeln(sprintf('  %-14s <comment>%s</comment>', $ap->getName(),
                    'did not answer — '.($b['why'] ?: 'no reason given')));
                continue;
            }
            $isPatched = (bool) $b['patched'];
            if ($isPatched) {
                ++$patched;
            }
            $output->writeln(sprintf('  %-14s <%s>%-16s</> %-26s %s',
                $ap->getName(),
                $isPatched ? 'info' : 'comment',
                $b['package'],
                $b['version'] ?: '-',
                $isPatched ? 'SAE over RADIUS: yes' : 'SAE over RADIUS: no'));
        }

        $output->writeln('');
        $output->writeln(sprintf('%d of %d run %s%s', $patched, count($aps),
            HostapdBuildService::PATCHED_PACKAGE,
            $unknown ? ', '.$unknown.' did not answer' : ''));
        if ($patched && $patched < count($aps)) {
            $output->writeln('<comment>the fleet is mixed — sae_pwe is judged per access point '
                .'until it is not</comment>');
        }

        return 0;
    }
}
