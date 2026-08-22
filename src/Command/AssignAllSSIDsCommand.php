<?php

namespace ApManBundle\Command;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Service\RolloutService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every network on every radio of one access point that has not said no.
 *
 * This command did not run at all. It called `$this->container` and
 * `getRootDir()` — both gone since Symfony 5 — to shell out to bin/randmac.pl
 * for an address, so it died on the first radio of the first network with an
 * undefined method. The address now comes from RolloutService, which is where
 * the web page gets it too, and the two paths cannot drift apart.
 */
#[AsCommand(name: 'apman:assign-all-ssids')]
class AssignAllSSIDsCommand extends Command
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
            ->setDescription('Assign all SSIDs to an accesspoint, skipping the radios that opted out')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'also put them on radios that opted out')
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
        if (!count($ap->getRadios())) {
            $output->writeln('<error>'.$ap->getName().' has no radios — read them in first</error>');

            return 1;
        }
        $ssids = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findAll();
        if (!$ssids) {
            $output->writeln('<error>there are no networks</error>');

            return 1;
        }

        return self::assign($output, $this->doctrine, $this->rollout, $ap, $ssids,
            (bool) $input->getOption('force'), (bool) $input->getOption('dry-run'));
    }

    /**
     * The loop both commands run, so there is one of it.
     */
    public static function assign(OutputInterface $output, \Doctrine\Persistence\ManagerRegistry $doctrine,
        RolloutService $rollout, AccessPoint $ap, array $ssids, bool $force, bool $dry): int
    {
        $added = 0;
        $skipped = 0;
        foreach ($ssids as $ssid) {
            foreach ($ap->getRadios() as $radio) {
                $where = $ap->getName().'/'.$radio->getName().' '.$ssid->getName();
                $device = $doctrine->getRepository('ApManBundle\Entity\Device')
                    ->findOneBy(['ssid' => $ssid, 'radio' => $radio]);
                if ($device) {
                    $output->writeln('  <comment>'.$where.'</comment> already carries it');
                    continue;
                }
                if (!$force && $rollout->isOptedOut($ssid, $radio)) {
                    ++$skipped;
                    $output->writeln('  <comment>'.$where.'</comment> deliberately without it — left alone');
                    continue;
                }
                if ($dry) {
                    ++$added;
                    $output->writeln('  '.$where.' would be added');
                    continue;
                }
                $res = $rollout->add($ssid, $radio);
                ++$added;
                $output->writeln('  <info>'.$where.'</info> added as '.$res['device']
                    .($res['ifname'] ? ' ('.$res['ifname'].')' : ' (named by the access point: '.$res['why_no_name'].')'));
            }
        }
        $output->writeln($dry
            ? $added.' would be added, '.$skipped.' left alone'
            : $added.' added, '.$skipped.' left alone. Nothing has reached the access point — '
                .'apman:config-ap '.$ap->getName().' --restart does that.');

        return 0;
    }
}
