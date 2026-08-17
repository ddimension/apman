<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Take the per device keys out of the FreeRADIUS users file and into the
 * controller.
 *
 * The RADIUS way and our way answer the same question with different means:
 * there, hostapd asks the server for every station and gets a Tunnel-Password
 * back; here, the key stands in wpa_psk_file and hostapd decides on its own.
 * The second way survives the server being away, answers in the handshake
 * instead of a round trip, and carries a keyid, so we learn which identity a
 * station used.
 *
 * Not every entry can make that move, and the command says so per entry
 * instead of quietly dropping it:
 *
 *  - An SSID that runs SAE never reads wpa_psk_file. hostapd takes the SAE
 *    password from sae_password_file or from the RADIUS answer, so such an
 *    entry has to stay where it is.
 *  - A key that equals the network passphrase is not a per device key at all.
 *    It carries no secret of its own — the catch all rule hands the same
 *    passphrase to any station — so there is nothing to take over.
 *  - A key several stations share cannot become one row per station: a key is
 *    unique per SSID here, on purpose. It moves as one MAC agnostic entry that
 *    both stations use, which is what the shared key already meant.
 */
class PpskImportRadiusCommand extends Command
{
    protected static $defaultName = 'apman:ppsk-import-radius';

    private $doctrine;
    private $ppsk;
    private $logger;

    public function __construct(
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\PpskService $ppsk,
        \Psr\Log\LoggerInterface $logger,
        $name = null
    ) {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->ppsk = $ppsk;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setName('apman:ppsk-import-radius')
            ->setDescription('Take the per device keys of a FreeRADIUS users file into the controller')
            ->addArgument('file', InputArgument::REQUIRED, 'the FreeRADIUS users file to read')
            ->addOption('apply', null, InputOption::VALUE_NONE,
                'actually write the keys; without it nothing is changed')
            ->addOption('distribute', null, InputOption::VALUE_NONE,
                'write the new keys to the access points afterwards')
            ->addOption('removable', null, InputOption::VALUE_REQUIRED,
                'write the RADIUS user names that are covered here to this file')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('file');
        if (!is_readable($path)) {
            $output->writeln('<error>cannot read '.$path.'</error>');

            return 1;
        }

        $records = $this->parse(file_get_contents($path));
        if (!$records) {
            $output->writeln('<error>no entries found in '.$path.'</error>');

            return 1;
        }

        $em = $this->doctrine->getManager();
        $ssids = $this->ssidsByBroadcastName();
        $apply = (bool) $input->getOption('apply');

        // group by SSID and key: a key used by several stations becomes one
        // entry, not several
        $groups = [];
        foreach ($records as $rec) {
            $groups[$rec['ssid'].'|'.$rec['psk']][] = $rec;
        }

        $done = [];
        $skipped = [];
        $touched = [];
        foreach ($groups as $key => $members) {
            [$ssidName, $psk] = explode('|', $key, 2);
            $first = $members[0];
            $who = implode(', ', array_map(function ($m) {
                return $m['name'] ?: $m['mac'];
            }, $members));

            $verdict = $this->verdict($ssidName, $psk, $ssids);
            if (null !== $verdict) {
                $skipped[] = [$ssidName, $who, $verdict];
                continue;
            }

            $ssid = $ssids[$ssidName]['ssid'];
            $shared = count($members) > 1;
            $name = $first['name'] ?: $first['mac'];
            $mac = $shared ? \ApManBundle\Entity\Ppsk::ANY_MAC : $this->macColons($first['mac']);

            if ($apply) {
                $entry = new \ApManBundle\Entity\Ppsk();
                $entry->setSsid($ssid);
                $entry->setName($name);
                $entry->setMac($mac);
                $entry->setPsk($psk);
                $entry->setSource(\ApManBundle\Entity\Ppsk::SOURCE_RADIUS);
                // the RADIUS entry already named its station, so there is
                // nothing left to learn on the first login
                $entry->setPinMac(false);
                $entry->setComment('taken over from RADIUS ('.basename($path).'), '
                    .($shared ? 'shared by '.count($members).' stations: ' : 'station ')
                    .implode(', ', array_column($members, 'mac')));
                $em->persist($entry);
                $em->flush();
                $entry->setKeyid($entry->buildKeyid());
                $em->flush();
                $touched[$ssid->getId()] = $ssid;
                $this->logger->notice('PpskImportRadius: took over '.$entry->getKeyid()
                    .' for '.$ssid->getName());
            }

            foreach ($members as $m) {
                // the user name alone is not unique — the same MAC appears
                // twice, once with its own key and once with the network
                // passphrase, so the key has to identify the entry
                $done[] = $m['user']."\t".$m['ssid']."\t".$psk;
            }
            $output->writeln(sprintf('  %-12s %-26s %-18s %s', $ssidName, $who, $mac,
                $shared ? 'MAC agnostic, shared key' : 'bound to its MAC'));
        }

        if ($skipped) {
            $output->writeln('');
            $output->writeln('<comment>not taken over</comment>');
            foreach ($skipped as [$ssidName, $who, $why]) {
                $output->writeln(sprintf('  %-12s %-26s %s', $ssidName, $who, $why));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('%d of %d entries %s, %d left where they are',
            count($done), count($records), $apply ? 'taken over' : 'would be taken over',
            count($records) - count($done)));

        if ($input->getOption('removable')) {
            // only what really arrived here may be removed there
            file_put_contents($input->getOption('removable'),
                $done ? implode("\n", $done)."\n" : '');
            $output->writeln('names covered here written to '.$input->getOption('removable'));
        }

        if ($apply && $input->getOption('distribute')) {
            foreach ($touched as $ssid) {
                $output->writeln('distributing '.$ssid->getName().' …');
                $result = $this->ppsk->distribute($ssid, true);
                if (isset($result['error'])) {
                    $output->writeln('<error>'.$result['error'].'</error>');

                    return 1;
                }
                $output->writeln('  '.$this->summarise($result));
            }
        }

        if (!$apply) {
            $output->writeln('<comment>nothing was written — run again with --apply</comment>');
        }

        return 0;
    }

    /**
     * Why this key cannot move, or null when it can.
     */
    private function verdict($ssidName, $psk, array $ssids)
    {
        if (!isset($ssids[$ssidName])) {
            return 'no SSID of this name in the controller';
        }
        $entry = $ssids[$ssidName];
        // SAE and wpa_psk_file never met — but SAE and a RADIUS answer do, and
        // that is the whole point of the controller answering itself. So the
        // question is no longer "does this SSID read a psk file" but "does
        // anything here reach the station at all".
        $radius = $this->ppsk->usesRadius($entry['ssid']);
        if (!$entry['delivery']['psk'] && !$radius) {
            return 'the SSID runs '.$entry['encryption'].', which never reads wpa_psk_file, '
                .'and it asks no RADIUS server either — nothing would carry the key';
        }
        if ($entry['delivery']['sae'] && !$radius) {
            return 'the SSID also offers SAE, where the key would only work for the WPA2 half';
        }
        if (strlen($psk) < 8 || strlen($psk) > 63) {
            return 'key length '.strlen($psk).' is outside what WPA accepts';
        }
        if ($psk === $entry['key']) {
            return 'the key is the network passphrase, not a key of its own';
        }
        // disabled keys count too: the key is unique per SSID in the database,
        // whether it currently reaches the access points or not
        $known = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')
            ->findOneBy(['ssid' => $entry['ssid'], 'psk' => $psk]);
        if ($known) {
            return 'already here as '.($known->getKeyid() ?: $known->getMac())
                .($known->getEnabled() ? '' : ' (disabled)');
        }

        return null;
    }

    /**
     * SSIDs by the name they broadcast, which is what the RADIUS entries match
     * on — the name in the controller is a different thing.
     */
    private function ssidsByBroadcastName()
    {
        $out = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\SSID')->findAll() as $ssid) {
            $config = $ssid->exportConfig();
            $name = $config->ssid ?? null;
            if (!$name) {
                continue;
            }
            $out[$name] = [
                'ssid' => $ssid,
                'key' => $config->key ?? null,
                'encryption' => $config->encryption ?? 'none',
                'delivery' => $this->ppsk->keyDelivery($ssid),
            ];
        }

        return $out;
    }

    /**
     * The users file is a pairlist: an entry starts in the first column and
     * its attributes are indented below it.
     */
    private function parse($text)
    {
        $records = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if ('' === trim($line) || '#' === substr(ltrim($line), 0, 1)) {
                continue;
            }
            if (!preg_match('/^\s/', $line)) {
                if ($current) {
                    $records[] = $current;
                }
                $parts = preg_split('/\s+/', trim($line), 2);
                $current = ['user' => $parts[0], 'body' => $parts[1] ?? ''];
                continue;
            }
            if ($current) {
                $current['body'] .= ' '.trim($line);
            }
        }
        if ($current) {
            $records[] = $current;
        }

        $out = [];
        foreach ($records as $rec) {
            $ssid = $this->attr($rec['body'], 'Called-Station-SSID');
            $psk = $this->attr($rec['body'], 'Tunnel-Password');
            if (!$ssid || !$psk) {
                continue;
            }
            $out[] = [
                'user' => $rec['user'],
                'mac' => $rec['user'],
                'ssid' => $ssid,
                'psk' => $psk,
                'name' => $this->attr($rec['body'], 'APMAN-Client-Name'),
            ];
        }

        return $out;
    }

    /**
     * A pairlist knows three operators — ":=" sets, "==" compares, "=" sets if
     * unset — and the file uses all three for the same attributes.
     */
    private function attr($body, $name)
    {
        $op = '\s*(?::=|==|=)\s*';
        if (preg_match('/'.preg_quote($name, '/').$op.'"([^"]*)"/', $body, $m)) {
            return $m[1];
        }
        if (preg_match('/'.preg_quote($name, '/').$op.'([^,\s]+)/', $body, $m)) {
            return $m[1];
        }

        return null;
    }

    private function macColons($mac)
    {
        $raw = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac));
        if (12 !== strlen($raw)) {
            return $mac;
        }

        return implode(':', str_split($raw, 2));
    }

    private function summarise(array $result)
    {
        $ok = 0;
        $bad = 0;
        foreach ($result as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ('ok' === ($row['reload'] ?? null)) {
                    ++$ok;
                } elseif (isset($row['ifname']) && !($row['unchanged'] ?? false)) {
                    ++$bad;
                }
            }
        }

        return $ok.' bss reloaded, '.$bad.' without confirmation';
    }
}
