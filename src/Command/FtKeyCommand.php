<?php

namespace ApManBundle\Command;

use ApManBundle\Service\FtKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The 802.11r key a network's access points share.
 *
 * Without one, a network whose keys come from RADIUS cannot roam: ap.uc falls
 * back to deriving the FT key from the per access point RADIUS secret, so no
 * two access points agree. See FtKeyService for the whole of it.
 */
#[AsCommand(name: 'apman:ft-key')]
class FtKeyCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly FtKeyService $ftKeys,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show, create or rotate the 802.11r key of a network')
            ->addArgument('ssid', InputArgument::OPTIONAL, 'one network, instead of all of them')
            ->addOption('create', null, InputOption::VALUE_NONE, 'give a network that has none a key')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'replace the key a network already has')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->doctrine->getRepository('ApManBundle\Entity\SSID');
        $name = $input->getArgument('ssid');
        $ssids = $name ? $repo->findBy(['name' => $name]) : $repo->findBy([], ['name' => 'ASC']);
        if (!$ssids) {
            $output->writeln('<error>'.($name ? 'no such network: '.$name : 'there are no networks').'</error>');

            return 1;
        }

        $create = (bool) $input->getOption('create');
        $rotate = (bool) $input->getOption('rotate');
        if (($create || $rotate) && !$name) {
            $output->writeln('<error>name the network — creating or rotating keys for all of them at '
                .'once would take every roaming network down together</error>');

            return 1;
        }

        foreach ($ssids as $ssid) {
            if ($create || $rotate) {
                $res = $this->ftKeys->ensure($ssid, $rotate);
                $output->writeln(sprintf('  <info>%-12s</info> %s  %s', $ssid->getName(),
                    substr($res['key'], 0, 16).'…',
                    $res['rotated'] ? 'rotated' : ($res['created'] ? 'created' : 'already had one, unchanged')));
                if ($res['created'] || $res['rotated']) {
                    $output->writeln('');
                    $output->writeln('<comment>Nothing has reached an access point yet. Until every access '
                        .'point of this network is provisioned they disagree about the key, and a '
                        .'transition between a provisioned and an unprovisioned one cannot be '
                        .'validated — provision them together.</comment>');
                }
                continue;
            }

            $key = $this->ftKeys->keyOf($ssid);
            $output->writeln(sprintf('  %-12s %s', $ssid->getName(),
                $key ? substr($key, 0, 16).'… ('.strlen($key).' hex)' : '<comment>no key</comment>'));
        }

        return 0;
    }
}
