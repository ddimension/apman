<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureAccessPointCommand extends Command
{
    protected static $defaultName = 'apman:config-ap';

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
            ->setName('apman:config-ap')
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
        $this->apservice->publishConfig($ap);
        /*
        $logger = new class {
            public function debug($msg) {
            echo $msg."\n";
            }
        };
        $ap->publishConfig($logger);
        */

        return 0;
    }
}
