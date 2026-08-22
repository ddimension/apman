<?php

namespace ApManBundle\Command;

use ApManBundle\Service\ApUbusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ask one access point one ubus question, over MQTT, and print the answer.
 *
 *     apman:ubus ap-av-attic iwinfo freqlist '{"device":"wap-kc1"}'
 *     apman:ubus ap-av-attic hostapd.wap-kc1 get_status
 *
 * The same transport everything else uses, so what this prints is what the
 * controller sees — including the ubus status when the answer is "no".
 */
#[AsCommand(name: 'apman:ubus')]
class UbusCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly ApUbusService $ubus,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('One ubus call to one access point, over mqtt')
            ->addArgument('ap', InputArgument::REQUIRED, 'Access point name')
            ->addArgument('object', InputArgument::REQUIRED, 'ubus object, e.g. iwinfo or hostapd.wap-kc1')
            ->addArgument('method', InputArgument::REQUIRED, 'ubus method')
            ->addArgument('args', InputArgument::OPTIONAL, 'arguments as json', '{}')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'seconds to wait', (string) ApUbusService::DEFAULT_TIMEOUT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findOneBy(['name' => $input->getArgument('ap')]);
        if (!$ap) {
            $output->writeln('<error>no such access point: '.$input->getArgument('ap').'</error>');

            return 1;
        }
        try {
            $args = json_decode((string) $input->getArgument('args'), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln('<error>arguments are not json: '.$e->getMessage().'</error>');

            return 1;
        }

        $started = microtime(true);
        $res = $this->ubus->call($ap, $input->getArgument('object'), $input->getArgument('method'),
            $args, (float) $input->getOption('timeout'));
        $took = sprintf('%.0f ms', (microtime(true) - $started) * 1000);

        if (!$res->isOk()) {
            $output->writeln('<error>'.$res->why().'</error>  <comment>('.$took.')</comment>');

            return 1;
        }
        $output->writeln('<comment>'.$took.'</comment>');
        $output->writeln(json_encode($res->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
