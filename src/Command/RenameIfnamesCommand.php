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
                'Set a network\'s short name, as "SSID=abbrev". Repeatable. Stored on the network, so every access point uses it.');
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

        // --slug writes the network's short name, and it writes it once for the
        // whole fleet: the abbreviation belongs to the network, not to a bss.
        $write = $input->getOption('write') && !$input->getOption('dry-run');
        foreach ($input->getOption('slug') as $pair) {
            $parts = explode('=', $pair, 2);
            if (2 !== count($parts)) {
                $output->writeln('<error>--slug wants "SSID=abbrev", got: '.$pair.'</error>');

                return 1;
            }
            $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')
                ->findOneBy(['name' => $parts[0]]);
            if (!$ssid) {
                $output->writeln('<error>no such network: '.$parts[0].'</error>');

                return 1;
            }
            $slug = strtolower(trim($parts[1]));
            $why = IfnameScheme::reject(IfnameScheme::build($slug, '60g', 2));
            if ($why) {
                $output->writeln('<error>'.$slug.': '.$why.' — a short name has to fit the longest '
                    .'name it could end up in ('.IfnameScheme::slugBudget('60g', 2).' characters)</error>');

                return 1;
            }
            if (!$write) {
                $output->writeln('would set the short name of '.$ssid->getName().' to '.$slug.' (add --write)');
                continue;
            }
            $ssid->setShortName($slug);
            $em->persist($ssid);
            $em->flush();
            $output->writeln('<info>short name of '.$ssid->getName().' is now '.$slug.'</info>');
        }

        $radios = [];
        foreach ($ap->getRadios() as $radio) {
            $radios[] = $radio;
        }

        $rows = [];
        $problems = [];
        $taken = [];
        $needSlug = [];
        foreach ($radios as $radio) {
            foreach ($radio->getDevices() as $device) {
                $ssidName = $device->getSsid() ? $device->getSsid()->getName() : '';
                $result = IfnameScheme::forDevice($device, $radios);
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
                    if ($device->getSsid()) {
                        $needSlug[$ssidName] = $device->getSsid();
                    }
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
            if ($needSlug) {
                $output->writeln('');
                $output->writeln('What the network is called on the whole fleet today:');
                foreach ($needSlug as $ssidName => $ssid) {
                    // every bss of this network, not just the ones on this
                    // access point: the short name is one value for all of
                    // them, so counting one machine's names would repeat the
                    // mistake this column exists to fix
                    $counts = [];
                    foreach ($ssid->getDevices() as $other) {
                        $slug = IfnameScheme::slugFrom($other->getIfname())
                            ?? IfnameScheme::slugFrom($other->getIfnameSeen());
                        if ($slug) {
                            $counts[$slug] = ($counts[$slug] ?? 0) + 1;
                        }
                    }
                    if (!$counts) {
                        $output->writeln(sprintf('  --slug "%s=?"   # nothing to read out of any interface name', $ssidName));
                        continue;
                    }
                    arsort($counts);
                    $shown = [];
                    foreach ($counts as $slug => $n) {
                        $shown[] = $slug.' ('.$n.'x)';
                    }
                    $output->writeln(sprintf('  --slug %-26s # %s',
                        '"'.$ssidName.'='.array_key_first($counts).'"', implode(', ', $shown)));
                }
                $output->writeln('');
                $output->writeln('Where more than one abbreviation is listed the access points disagree,');
                $output->writeln('which is why this is one value on the network and not one per bss.');
            }

            return 1;
        }

        if (0 === $changing) {
            $output->writeln('');
            $output->writeln('every bss already carries the name this scheme would give it');

            return 0;
        }

        if (!$write) {
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
