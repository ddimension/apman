<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Distribute the per device keys of one SSID.
 *
 * This exists as its own process for a reason: distributing waits for the
 * access points to answer, and those answers arrive through the subscriber.
 * A distribution started inside the subscriber would wait for messages that
 * only the subscriber itself could process — it would block until the timeout
 * and report that nobody answered. So the subscriber starts this command
 * instead and keeps ingesting.
 */
#[AsCommand(name: 'apman:ppsk-distribute')]
class PpskDistributeCommand extends Command
{

    private $doctrine;
    private $ppsk;
    private $logger;

    public function __construct(
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\PpskService $ppsk,
        \Psr\Log\LoggerInterface $logger,
        $name = null
    ) {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->ppsk = $ppsk;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Write the per device keys of one SSID to every access point that carries it')
            ->addArgument('ssid', InputArgument::REQUIRED, 'SSID id or name')
            ->addOption('force', 'f', InputOption::VALUE_NONE,
                'Rewrite and reload even where nothing changed')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $needle = $input->getArgument('ssid');
        $repo = $this->doctrine->getRepository('ApManBundle\Entity\SSID');
        $ssid = ctype_digit((string) $needle) ? $repo->find($needle) : $repo->findOneBy(['name' => $needle]);
        if (!$ssid) {
            $output->writeln('<error>no such ssid: '.$needle.'</error>');

            return 1;
        }

        $result = $this->ppsk->distribute($ssid, (bool) $input->getOption('force'));
        if (isset($result['error'])) {
            $output->writeln('<error>'.$result['error'].'</error>');

            return 1;
        }

        $reloaded = 0;
        $failed = 0;
        foreach ($result as $apName => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ('ok' === ($row['reload'] ?? null)) {
                    ++$reloaded;
                } elseif (isset($row['ifname']) && !($row['unchanged'] ?? false)) {
                    ++$failed;
                }
                $output->writeln(sprintf('  %-16s %-10s %s', $apName,
                    $row['ifname'] ?? '', $row['error'] ?? ($row['unchanged'] ?? false ? 'unchanged' : ($row['reload'] ?? '?'))),
                    OutputInterface::VERBOSITY_VERBOSE);
            }
        }
        $output->writeln(sprintf('%s: %d bss reloaded, %d without confirmation',
            $ssid->getName(), $reloaded, $failed));

        return 0;
    }
}
