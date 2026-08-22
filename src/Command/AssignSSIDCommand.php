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
 * Put one network on every radio of one access point that has not said no.
 *
 * "That has not said no" is the new part. This used to create a bss on every
 * radio unconditionally, so a row somebody deleted on purpose came back on the
 * next run and there was nowhere to write down that the deletion was a
 * decision. Now there is, and this honours it.
 */
#[AsCommand(name: 'apman:assign-ssid')]
class AssignSSIDCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly RolloutService $rollout,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Assign SSID to an accesspoint, skipping the radios that opted out')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addArgument('ssid', InputArgument::REQUIRED, 'SSID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'also put it on radios that opted out')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'say what would happen')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findOneBy(['name' => $input->getArgument('name')]);
        if (!$ap) {
            $output->writeln('<error>no such access point: '.$input->getArgument('name').'</error>');

            return 1;
        }
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')
            ->findOneBy(['name' => $input->getArgument('ssid')]);
        if (!$ssid) {
            $output->writeln('<error>no such network: '.$input->getArgument('ssid').'</error>');

            return 1;
        }
        $radios = $ap->getRadios();
        if (!count($radios)) {
            $output->writeln('<error>'.$ap->getName().' has no radios — read them in first</error>');

            return 1;
        }

        return AssignAllSSIDsCommand::assign($output, $this->doctrine, $this->rollout, $ap, [$ssid],
            (bool) $input->getOption('force'), (bool) $input->getOption('dry-run'));
    }
}
