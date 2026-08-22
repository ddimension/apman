<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apman:config-ap')]
class ConfigureAccessPointCommand extends Command
{

    private $doctrine;
    private $logger;
    private $apservice;

    public function __construct(\Doctrine\Persistence\ManagerRegistry $doctrine, \Psr\Log\LoggerInterface $logger, \ApManBundle\Service\AccessPointService $apservice, $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->apservice = $apservice;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Configure all SSIDs on an accesspoint')
            ->addArgument('name', InputArgument::REQUIRED, 'Acesspoint Name')
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
        $report = $this->apservice->applyConfig($ap);
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
