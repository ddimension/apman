<?php

namespace ApManBundle\Command;

use ApManBundle\Library\NodeState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The nagios check.
 *
 * It used to ask each productive access point for its flat state and call
 * everything that was not STATE_ACTIVE "offline" — an access point in the
 * middle of a channel availability check, one still configuring and one that
 * had genuinely gone away all produced the same word and the same CRITICAL.
 * There was no WARNING at all, and nothing said which access point or why.
 *
 * It reads the state tree now, so it can say what is actually wrong and at
 * which level. A radio in CAC is normal and stays OK until it has been there
 * far longer than a check takes; a bss that is missing while its siblings run
 * makes its radio degraded and that is a warning, not an outage.
 */
#[AsCommand(name: 'apman:monitor')]
class MonitorCommand extends Command
{
    /** nagios exit codes */
    private const OK = 0;
    private const WARNING = 1;
    private const CRITICAL = 2;
    private const UNKNOWN = 3;

    /** what an access point state means for the check */
    private const SEVERITY = [
        NodeState::AP_ACTIVE => self::OK,
        NodeState::AP_READY => self::OK,
        NodeState::AP_CAC => self::OK,
        NodeState::AP_ONLINE => self::WARNING,
        NodeState::AP_CONFIGURING => self::WARNING,
        NodeState::AP_DEGRADED => self::WARNING,
        NodeState::AP_UNKNOWN => self::WARNING,
        NodeState::AP_FAILED => self::CRITICAL,
        NodeState::AP_OFFLINE => self::CRITICAL,
    ];

    private $doctrine;
    private $stateTree;

    public function __construct(
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\StateTreeService $stateTree,
        $name = null
    ) {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->stateTree = $stateTree;
    }

    protected function configure(): void
    {
        $this->setDescription('Nagios check: the access point / radio / bss state tree')
            ->addOption('cac-age', null, InputOption::VALUE_REQUIRED,
                'seconds a radio may sit in CAC before it is reported', 300)
            ->addOption('all', null, InputOption::VALUE_NONE,
                'include access points that are not productive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cacAge = (int) $input->getOption('cac-age');
        $aps = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findBy($input->getOption('all') ? [] : ['IsProductive' => true]);
        if (!$aps) {
            $output->writeln('UNKNOWN - no access points to check');

            return self::UNKNOWN;
        }

        $worst = self::OK;
        $detail = [];
        $counts = [self::OK => 0, self::WARNING => 0, self::CRITICAL => 0];
        $radiosBad = 0;
        $bssBad = 0;
        $offline = [];

        foreach ($aps as $ap) {
            $tree = $this->stateTree->ap($ap);
            $sev = self::SEVERITY[$tree['state']] ?? self::WARNING;

            // A radio stuck in CAC is the one case where the state alone says
            // too little: sixty seconds of it is the channel doing its job,
            // an hour of it is a radio that never came back.
            foreach ($tree['children'] as $radio) {
                if (NodeState::RADIO_CAC === $radio['state']
                    && $radio['since'] && (time() - (int) $radio['since']) > $cacAge) {
                    $sev = max($sev, self::WARNING);
                    $detail[] = sprintf('%s/%s in CAC for %s',
                        $ap->getName(), $radio['name'], $this->age($radio['since']));
                }
                if (in_array($radio['state'], [NodeState::RADIO_FAILED,
                    NodeState::RADIO_DEGRADED, NodeState::RADIO_UNKNOWN], true)) {
                    ++$radiosBad;
                }
                foreach ($radio['children'] as $bss) {
                    if (in_array($bss['state'], [NodeState::BSS_ABSENT,
                        NodeState::BSS_UNKNOWN], true)) {
                        ++$bssBad;
                    }
                }
            }

            if (in_array($tree['state'], [NodeState::AP_OFFLINE, NodeState::AP_UNKNOWN], true)) {
                $offline[] = $ap->getName().($tree['since'] ? ' ('.$this->age($tree['since']).')' : '');
            }

            ++$counts[$sev];
            if (self::OK !== $sev) {
                $detail[] = sprintf('%s %s%s', $ap->getName(), $tree['state_name'],
                    $tree['since'] ? ' for '.$this->age($tree['since']) : '');
                // name the level that is actually wrong, not just the top
                foreach ($tree['children'] as $radio) {
                    if (in_array($radio['state'], [NodeState::RADIO_FAILED,
                        NodeState::RADIO_DEGRADED, NodeState::RADIO_PENDING], true)) {
                        $bad = [];
                        foreach ($radio['children'] as $bss) {
                            if (in_array($bss['state'], [NodeState::BSS_ABSENT,
                                NodeState::BSS_STARTING], true)) {
                                $bad[] = $bss['name'].' '.$bss['state_name'];
                            }
                        }
                        $detail[] = sprintf('  %s %s%s', $radio['name'], $radio['state_name'],
                            $bad ? ': '.implode(', ', $bad) : '');
                    }
                }
            }
            $worst = max($worst, $sev);
        }

        $perf = sprintf('ok=%d warning=%d critical=%d offline=%d radios_bad=%d bss_bad=%d',
            $counts[self::OK], $counts[self::WARNING], $counts[self::CRITICAL],
            count($offline), $radiosBad, $bssBad);

        $word = [self::OK => 'OK', self::WARNING => 'WARNING', self::CRITICAL => 'CRITICAL'][$worst];
        $summary = self::OK === $worst
            ? sprintf('all %d access points healthy', count($aps))
            : sprintf('%d of %d access points not healthy', count($aps) - $counts[self::OK], count($aps));

        // Name them in the first line. A count tells whoever gets paged how
        // bad it is; the names tell them whether it is the one access point
        // that has been dead for a day or the whole site, and that is the
        // difference between reading on and getting up. Nagios shows the
        // first line and often nothing else — ap-hv-klwz was offline for a
        // whole day behind a summary that only counted.
        if ($offline) {
            $summary .= ' — offline: '.implode(', ', $offline);
        }

        $output->writeln($word.' - '.$summary.'|'.$perf);
        foreach ($detail as $line) {
            $output->writeln($line);
        }

        return $worst;
    }

    private function age(int $since): string
    {
        $s = time() - $since;
        if ($s < 90) {
            return $s.'s';
        }
        if ($s < 5400) {
            return intdiv($s, 60).'m';
        }

        return intdiv($s, 3600).'h';
    }
}
