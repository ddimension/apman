<?php

namespace ApManBundle\Command;

use ApManBundle\Library\IfnameScheme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Work out what the bsses of one access point should be called, and say so.
 *
 * Renaming an interface is not a field update. The name is the ubus object, the
 * key file path, the mqtt status topic and the neighbour a partner bss names in
 * owe_transition_ifname, so the rename tears the bss down and builds it again
 * under the new name — clients on it are dropped, and the transition partner
 * has to learn the new name in the same provisioning run or it points at
 * nothing.
 *
 * Which is why this changes the database and stops. Provisioning is a separate
 * decision and a separate command, one access point at a time:
 *
 *     apman:rename-ifnames --ap ap-av-attic            # says what it would do
 *     apman:rename-ifnames --ap ap-av-attic --write    # writes it
 *     apman:config-ap ap-av-attic                      # sends it, drops clients
 *
 * All or nothing per access point. A machine where half the bsses moved and
 * half did not is harder to reason about than one that was left alone, so a
 * single name that does not fit or collides stops the whole run.
 */
#[AsCommand(name: 'apman:rename-ifnames')]
class RenameIfnamesCommand extends Command
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
            ->setDescription('Give the bsses of one access point names that carry the band instead of the radio index')
            ->addOption('ap', null, InputOption::VALUE_REQUIRED, 'Access point name')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually write the names (default: only say what would change)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Explicit no-op; this is the default anyway')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Short name for one network, as ssid=slug. Repeatable. Without it the slug is read out of the interface name a bss already has.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManager();
        $apName = $input->getOption('ap');
        if (!$apName) {
            $output->writeln('<error>--ap is required: this runs one access point at a time on purpose</error>');

            return 1;
        }
        $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findOneBy(['name' => $apName]);
        if (!$ap) {
            $output->writeln('<error>no such access point: '.$apName.'</error>');

            return 1;
        }

        $slugs = [];
        foreach ($input->getOption('slug') as $pair) {
            $parts = explode('=', $pair, 2);
            if (2 !== count($parts)) {
                $output->writeln('<error>--slug wants ssid=slug, got: '.$pair.'</error>');

                return 1;
            }
            $slugs[$parts[0]] = strtolower(trim($parts[1]));
        }

        $radios = [];
        foreach ($ap->getRadios() as $radio) {
            $radios[] = $radio;
        }

        $rows = [];
        $problems = [];
        $taken = [];
        foreach ($radios as $radio) {
            foreach ($radio->getDevices() as $device) {
                $ssidName = $device->getSsid() ? $device->getSsid()->getName() : '';
                $result = IfnameScheme::forDevice($device, $radios, $slugs[$ssidName] ?? null);
                $row = [
                    'device' => $device,
                    'section' => $device->getName(),
                    'ssid' => $ssidName,
                    'band' => (string) $radio->getConfigBand(),
                    'from' => (string) $device->getIfname(),
                    'seen' => (string) $device->getIfnameSeen(),
                    'to' => $result['name'],
                    'why' => $result['why'],
                ];
                if (null === $result['name']) {
                    $problems[] = $row['section'].': '.$result['why'];
                } elseif (isset($taken[$result['name']])) {
                    $problems[] = $result['name'].': wanted by '.$taken[$result['name']].' and '.$row['section'];
                } else {
                    $taken[$result['name']] = $row['section'];
                }
                $rows[] = $row;
            }
        }

        if (!$rows) {
            $output->writeln($apName.': no bss configured');

            return 0;
        }

        $output->writeln($apName.':');
        $changing = 0;
        foreach ($rows as $row) {
            $to = $row['to'] ?? '—';
            $mark = ' ';
            if ($row['to'] && $row['to'] !== $row['from']) {
                $mark = '*';
                ++$changing;
            }
            $output->writeln(sprintf('  %s %-24s %-16s %-4s %-14s -> %s%s',
                $mark, $row['section'], $row['ssid'], $row['band'],
                '' === $row['from'] ? '(none)' : $row['from'], $to,
                $row['why'] ? '   <comment>'.$row['why'].'</comment>' : ''));
            if ('' !== $row['seen'] && $row['seen'] !== $row['from']) {
                $output->writeln(sprintf('    the access point reports %s, provisioning asked for %s',
                    $row['seen'], '' === $row['from'] ? '(nothing)' : $row['from']));
            }
        }

        if ($problems) {
            $output->writeln('');
            $output->writeln('<error>nothing written — '.count($problems).' name(s) cannot be used:</error>');
            foreach ($problems as $problem) {
                $output->writeln('  '.$problem);
            }
            $output->writeln('Give the missing ones a short name with --slug "SSID=abbrev" and run it again.');

            return 1;
        }

        if (0 === $changing) {
            $output->writeln('');
            $output->writeln('every bss already carries the name this scheme would give it');

            return 0;
        }

        if (!$input->getOption('write') || $input->getOption('dry-run')) {
            $output->writeln('');
            $output->writeln($changing.' name(s) would change. Nothing written — add --write.');
            $output->writeln('After writing, '.$apName.' has to be provisioned for the names to reach it,');
            $output->writeln('and every client on it is dropped when they do.');

            return 0;
        }

        foreach ($rows as $row) {
            if ($row['to'] && $row['to'] !== $row['from']) {
                $row['device']->setIfname($row['to']);
                $em->persist($row['device']);
            }
        }
        $em->flush();
        $output->writeln('');
        $output->writeln('<info>'.$changing.' name(s) written.</info>');
        $output->writeln('Nothing has reached '.$apName.' yet. To send them:');
        $output->writeln('  php bin/console apman:config-ap '.$apName);

        return 0;
    }
}
