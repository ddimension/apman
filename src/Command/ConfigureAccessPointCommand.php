<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:config-ap')]
class ConfigureAccessPointCommand extends Command
{

    private $doctrine;
    private $logger;
    private $apservice;
    private $provisioning;

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, \ApManBundle\Service\ProvisioningService $provisioning, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
        $this->provisioning = $provisioning;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Configure all SSIDs on an accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
            ->addOption('restart', null, InputOption::VALUE_NONE,
                'take the radios down first and wait until they are down, then bring them back')
            ->addOption('live', null, InputOption::VALUE_NONE,
                'apply under the running radios, refusing if that would take a phy down')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'with --live: apply even though a radio level option changed')
            ->addOption('classify', null, InputOption::VALUE_NONE,
                'only say what the next run would cost, and change nothing')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'stage the configuration and report the diff without applying it')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy([
        'name' => $input->getArgument('name'),
    ]);
        if (is_null($ap)) {
            // $this->output was never assigned, so the error path used to die
            // on an undefined property instead of saying what was wrong; and
            // "false" becomes exit code 0, which reports success on failure.
            $output->writeln('<error>Add this accesspoint. Cannot find it.</error>');

            return 1;
        }

        $radios = $this->doctrine->getRepository('ApManBundle\Entity\Radio')->findBy([
        'accesspoint' => $ap,
    ]);
        if (!is_array($radios) or !count($radios)) {
            $output->writeln('<error>Readd this accesspoint. No radios found</error>');

            return 1;
        }
        // applyConfig(), not publishConfig(). The latter stages the whole
        // wireless transaction into the rpcd session and stops there — and a
        // session's staged changes are not in /etc/config, so they are not
        // even visible to a "uci changes" on the device; they simply expire
        // with the session. This command reported success and changed nothing,
        // on every run, for as long as it has existed. The same bug was found
        // and fixed in the admin batch action (CustomActionsController::
        // batchActionConfigure) and in apman:ipsk-migrate; this was the copy
        // nobody came back to.
        $dry = (bool) $input->getOption('dry-run');

        if ($input->getOption('classify')) {
            $verdict = $this->provisioning->classify($ap);
            if (!($verdict['ok'] ?? false)) {
                $output->writeln('<error>'.$ap->getName().': '.($verdict['error'] ?? 'no answer').'</error>');

                return 1;
            }
            foreach ($verdict['radios'] as $name => $radio) {
                $output->writeln(sprintf('  %-10s <%s>%s</> %s',
                    $name,
                    'live' === $radio['mode'] ? 'info' : 'comment',
                    $radio['mode'],
                    'live' === $radio['mode']
                        ? $radio['devices'].' network(s) stay up'
                        : 'takes '.$radio['devices'].' network(s) down'));
                foreach ($radio['reasons'] as $reason) {
                    $output->writeln('      '.$reason);
                }
            }
            $output->writeln('live' === $verdict['mode']
                ? '<info>'.$ap->getName().': the next run changes only networks and disturbs nothing</info>'
                : '<comment>'.$ap->getName().': the next run restarts '
                    .implode(', ', $verdict['restarting']).'</comment>');

            return 0;
        }

        if ($input->getOption('live')) {
            $report = $this->provisioning->live($ap, $dry, (bool) $input->getOption('force'));
            foreach ($report['steps'] ?? [] as $step) {
                $output->writeln(sprintf('  %-22s %6d ms  %s%s', $step['step'], $step['ms'],
                    $step['ok'] ? 'ok' : '<error>failed</error>',
                    isset($step['detail']) ? '  '.$step['detail'] : ''));
            }
            if ($report['ok'] ?? false) {
                $output->writeln('<info>'.$ap->getName().': '
                    .($dry ? 'nothing applied, this was a dry run'
                        : 'applied under the running radios').'</info>');

                return 0;
            }
            $output->writeln('<error>'.$ap->getName().': '.($report['error'] ?? 'provisioning failed').'</error>');

            return 1;
        }

        if ($input->getOption('restart')) {
            // The order matters: down, wait for the netdevs to be gone, settle,
            // configure, up. Without --restart the configuration is applied
            // underneath running interfaces, which is fine for most changes and
            // races for the ones that rebuild a bss.
            $report = $this->provisioning->restart($ap, $dry);
            foreach ($report['steps'] ?? [] as $step) {
                $output->writeln(sprintf('  %-20s %6d ms  %s%s', $step['step'], $step['ms'],
                    $step['ok'] ? 'ok' : '<error>failed</error>',
                    isset($step['detail']) ? '  '.$step['detail'] : ''));
            }
            if ($report['ok'] ?? false) {
                $output->writeln('<info>'.$ap->getName().': '
                    .($dry ? 'nothing applied, this was a dry run' : 'radios restarted with the new configuration').'</info>');

                return 0;
            }
            $output->writeln('<error>'.$ap->getName().': '.($report['error'] ?? 'provisioning failed').'</error>');

            return 1;
        }

        $report = $this->apservice->applyConfig($ap, $dry);
        if ($report['ok'] ?? false) {
            $output->writeln($ap->getName().': '.($report['note'] ?? (($report['change_count'] ?? 0).' change(s) applied')));

            return 0;
        }
        $output->writeln('<error>'.$ap->getName().': '.($report['error'] ?? 'provisioning failed').'</error>');
        foreach (array_slice($report['failed'] ?? [], 0, 5, true) as $id => $why) {
            $output->writeln('  '.$id.': '.$why);
        }

        return 1;
    }
}
