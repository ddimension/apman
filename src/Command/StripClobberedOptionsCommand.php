<?php

namespace ApManBundle\Command;

use ApManBundle\Service\WlanConsistencyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove the options ucode writes over, because they say something untrue.
 *
 * They change nothing on the air — that is the point: they never reached an
 * access point, so taking them out is a no-op there and a correction here. What
 * goes is the belief that something is switched off when it is not.
 *
 * The list is WlanConsistencyService::CLOBBERED, so the thing that reports them
 * and the thing that removes them cannot disagree about which they are.
 *
 * Dry run by default. Nothing about this needs to happen quickly.
 */
#[AsCommand(name: 'apman:strip-clobbered')]
class StripClobberedOptionsCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove options that ucode overwrites, from networks and from bsses')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'actually remove them')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'one option name instead of all of them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('only');
        $names = array_keys(WlanConsistencyService::CLOBBERED);
        if ($only) {
            if (!in_array($only, $names, true)) {
                $output->writeln('<error>'.$only.' is not one of: '.implode(', ', $names).'</error>');

                return 1;
            }
            $names = [$only];
        }
        $force = (bool) $input->getOption('force');
        $em = $this->doctrine->getManager();
        $removed = 0;

        foreach ($this->doctrine->getRepository('ApManBundle\Entity\SSID')->findAll() as $ssid) {
            foreach ($ssid->getConfigOptions() as $option) {
                if (!in_array($option->getName(), $names, true)) {
                    continue;
                }
                ++$removed;
                $output->writeln(sprintf('  network %-18s %s=%s%s', $ssid->getName(),
                    $option->getName(), (string) $option->getValue(), $force ? '  <info>removed</info>' : ''));
                if ($force) {
                    $ssid->removeConfigOption($option);
                    $em->remove($option);
                }
            }
        }

        foreach ($this->doctrine->getRepository('ApManBundle\Entity\Device')->findAll() as $device) {
            $config = $device->getConfig();
            if (!is_array($config)) {
                continue;
            }
            $touched = false;
            foreach ($names as $name) {
                if (!array_key_exists($name, $config)) {
                    continue;
                }
                ++$removed;
                $radio = $device->getRadio();
                $ap = $radio ? $radio->getAccessPoint() : null;
                $output->writeln(sprintf('  bss     %-18s %s=%s%s',
                    ($ap ? $ap->getName().'/' : '').$device->getName(),
                    $name, (string) $config[$name], $force ? '  <info>removed</info>' : ''));
                unset($config[$name]);
                $touched = true;
            }
            if ($touched && $force) {
                $device->setConfig($config);
                $em->persist($device);
            }
        }

        if ($force) {
            $em->flush();
        }

        if (!$removed) {
            $output->writeln('<info>nothing to remove</info>');

            return 0;
        }
        $output->writeln($force
            ? $removed.' removed. Nothing has changed on any access point — these never reached one.'
              .' Provision when convenient so the sections stop carrying them.'
            : $removed.' would be removed. Run again with --force.');

        return 0;
    }
}
