<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * End to end exercise of the iPSK path against real hardware.
 *
 * Temporary: written for the 2026-08-21 test bed (see docs/ipsk-test.md).
 */
#[AsCommand(name: 'apman:ipsk-test')]
class IpskTestCommand extends \Symfony\Component\Console\Command\Command
{
    private $doctrine;
    private $ppsk;
    private $radius;
    private $apService;

    public function __construct(
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\PpskService $ppsk,
        \ApManBundle\Service\RadiusAuthService $radius,
        \ApManBundle\Service\AccessPointService $apService
    ) {
        parent::__construct();
        $this->doctrine = $doctrine;
        $this->ppsk = $ppsk;
        $this->radius = $radius;
        $this->apService = $apService;
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED,
            'setup|teardown|flags|create|distribute|show|pin|revoke|provision-dry|provision-apply')
            ->addArgument('arg', InputArgument::OPTIONAL)
            ->addOption('ssid', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,
                'test ssid name', 'apman-tsae')
            ->addOption('ap', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,
                'access point carrying the test ssids (setup)', 'ap-av-attic')
            ->addOption('radio', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,
                'radio on that access point (setup)', 'radio0');
    }

    /** the two test networks, one per key delivery */
    private const NETWORKS = [
        'apman-tpsk' => ['section' => 'tpsk', 'ifname' => 'wap-tp0', 'encryption' => 'psk2'],
        'apman-tsae' => ['section' => 'tsae', 'ifname' => 'wap-ts0', 'encryption' => 'sae'],
    ];

    /**
     * The controller side of the bed: one SSID per key delivery, the iPSK
     * feature on both, and a device pointing at the uci section the access
     * point carries. The device name IS that section name — the key set names
     * it, and the access point looks its keys up by it.
     */
    private function setup(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->doctrine->getManager()->getConnection();
        $radio = $db->fetchAssociative(
            'SELECT r.id FROM radio r JOIN accesspoint a ON a.id = r.accesspoint_id'
            .' WHERE a.name = :ap AND r.name = :radio',
            ['ap' => $input->getOption('ap'), 'radio' => $input->getOption('radio')]
        );
        if (!$radio) {
            $output->writeln('<error>no radio '.$input->getOption('radio').' on '.$input->getOption('ap').'</error>');

            return 1;
        }
        $feature = $db->fetchOne(
            'SELECT id FROM feature WHERE implementation = :impl ORDER BY id LIMIT 1',
            ['impl' => 'ApManBundle\\Service\\IpskFeatureService']
        );
        if (!$feature) {
            $output->writeln('<error>no iPSK feature in the catalog</error>');

            return 1;
        }
        $this->teardown($output);
        foreach (self::NETWORKS as $name => $net) {
            $db->executeStatement('INSERT INTO ssid (name,setup_order,radius_fallback,auto_ppsk,moving_psk)'
                .' VALUES (:n,100,1,1,0)', ['n' => $name]);
            $sid = (int) $db->lastInsertId();
            foreach (['ssid' => $name, 'encryption' => $net['encryption'],
                'key' => 'netzwerk-passphrase', 'mode' => 'ap'] as $k => $v) {
                $db->executeStatement('INSERT INTO ssid_config_option (ssid_id,name,value) VALUES (:s,:n,:v)',
                    ['s' => $sid, 'n' => $k, 'v' => $v]);
            }
            $db->executeStatement('INSERT INTO ssid_feature_map (ssid_id,feature_id,name,config,priority,enabled)'
                ." VALUES (:s,:f,'iPSK','[]',0,1)", ['s' => $sid, 'f' => $feature]);
            $db->executeStatement('INSERT INTO device (radio_id,ssid_id,name,ifname) VALUES (:r,:s,:n,:i)',
                ['r' => $radio['id'], 's' => $sid, 'n' => $net['section'], 'i' => $net['ifname']]);
            $output->writeln($name.': ssid='.$sid.' device='.$db->lastInsertId().' ('.$net['encryption'].')');
        }

        return 0;
    }

    /** leaves the database as it was found */
    private function teardown(OutputInterface $output): int
    {
        $db = $this->doctrine->getManager()->getConnection();
        foreach (array_keys(self::NETWORKS) as $name) {
            $id = $db->fetchOne('SELECT id FROM ssid WHERE name = :n', ['n' => $name]);
            if (!$id) {
                continue;
            }
            // off the access points first, while the devices are still known;
            // the key store also holds the sets of real networks, so it must
            // not simply be deleted on the access point
            $ssid = $this->doctrine->getRepository('ApManBundle\\Entity\\SSID')->find($id);
            if ($ssid) {
                $output->writeln('  key set: '.json_encode($this->ppsk->removeKeystore($ssid)));
            }
            foreach (['ppsk', 'device', 'ssid_config_option', 'ssid_feature_map'] as $table) {
                $db->executeStatement('DELETE FROM '.$table.' WHERE ssid_id = :i', ['i' => $id]);
            }
            $db->executeStatement('DELETE FROM ssid WHERE id = :i', ['i' => $id]);
            $output->writeln('removed '.$name.' (ssid '.$id.')');
        }

        return 0;
    }

    /**
     * What provisioning would write, without writing it: publishConfig() with
     * $return builds the command list and publishes nothing.
     */
    private function provisionDry($apName, OutputInterface $output): int
    {
        $ap = $this->doctrine->getRepository('ApManBundle\\Entity\\AccessPoint')->findOneBy(['name' => $apName]);
        if (!$ap) {
            $output->writeln('<error>no access point '.$apName.'</error>');

            return 1;
        }
        $commands = $this->apService->publishConfig($ap, true);
        if (!is_array($commands) || empty($commands['list'])) {
            $output->writeln('<error>no configuration generated</error>');

            return 1;
        }
        $counts = [];
        foreach ($commands['list'] as $cmd) {
            $p = $cmd->params ?? [];
            $object = $p[1] ?? '?';
            $method = $p[2] ?? '?';
            $args = $p[3] ?? null;
            $line = ($cmd->id ?? '?').'  '.$object.' '.$method;
            if ('uci' === $object && is_object($args)) {
                $line .= '  '.($args->config ?? '').'.'.($args->type ?? ($args->section ?? ''))
                    .(isset($args->name) ? ' name='.$args->name : '');
                // values is an array for wifi-iface (the feature merge turns
                // it into one) and an object elsewhere — read both
                $values = $args->values ?? null;
                if (is_object($values)) {
                    $values = (array) $values;
                }
                if (is_array($values)) {
                    foreach (['ssid', 'encryption', 'ppsk', 'auth_server', 'ifname', 'key', 'mac', 'iface',
                        'nasid', 'mobility_domain', 'ieee80211r', 'hostapd_bss_options'] as $k) {
                        if (isset($values[$k])) {
                            $v = $values[$k];
                            $line .= ' '.$k.'='.(is_array($v) ? implode(',', $v) : $v);
                        }
                    }
                }
            } elseif ('file' === $object && is_object($args) && isset($args->command)) {
                $line .= '  '.$args->command.' '.implode(' ', (array) ($args->params ?? []));
            }
            $key = ('uci' === $object && is_object($args)) ? ($args->type ?? $args->section ?? $method) : $object;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $output->writeln($line);
        }
        $output->writeln('');
        foreach ($counts as $k => $n) {
            $output->writeln(sprintf('  %-16s %d', $k, $n));
        }

        return 0;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('ssid');
        $action = $input->getArgument('action');
        if ('setup' === $action) {
            return $this->setup($input, $output);
        }
        if ('teardown' === $action) {
            return $this->teardown($output);
        }
        if ('provision-dry' === $action) {
            return $this->provisionDry($input->getArgument('arg'), $output);
        }
        if ('provision-apply' === $action) {
            $ap = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
                ->findOneBy(['name' => $input->getArgument('arg')]);
            if (!$ap) {
                $output->writeln('<error>no such access point</error>');

                return 1;
            }
            $report = $this->apService->applyConfig($ap);
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return !empty($report['ok']) ? 0 : 1;
        }
        $ssid = $this->doctrine->getRepository('ApManBundle\Entity\SSID')->findOneBy(['name' => $name]);
        if (!$ssid) {
            $output->writeln('<error>test ssid '.$name.' not in the database</error>');

            return 1;
        }

        if ('flags' === $action) {
            $output->writeln('usesRadius='.var_export($this->ppsk->usesRadius($ssid), true)
                .' usesOnApRadius='.var_export($this->ppsk->usesOnApRadius($ssid), true)
                .' managesOwnKeys='.var_export($this->ppsk->managesOwnKeys($ssid), true)
                .' isIpsk='.var_export($this->ppsk->isIpsk($ssid), true)
                .' delivery='.json_encode($this->ppsk->keyDelivery($ssid)));

            return 0;
        }
        if ('create' === $action) {
            $key = $this->ppsk->createIpsk($ssid, $input->getArgument('arg') ?: 'Teststation');
            $output->writeln('created id='.$key->getId().' keyid='.$key->getKeyid()
                .' psk='.$key->getPsk().' mac='.$key->getMac()
                .' pinPending='.var_export($key->isPinPending(), true));

            return 0;
        }
        if ('distribute' === $action) {
            $output->writeln(json_encode($this->ppsk->distribute($ssid, true), JSON_PRETTY_PRINT));

            return 0;
        }
        if ('show' === $action) {
            foreach ($this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->findBy(['ssid' => $ssid]) as $p) {
                $output->writeln(sprintf('id=%d keyid=%s mac=%s psk=%s enabled=%s pin_mac=%s first=%s last=%s lastmac=%s',
                    $p->getId(), $p->getKeyid(), $p->getMac(), $p->getPsk(),
                    var_export($p->getEnabled(), true), var_export($p->isPinPending(), true),
                    $p->getFirstSeen() ? $p->getFirstSeen()->format('H:i:s') : '-',
                    $p->getLastSeen() ? $p->getLastSeen()->format('H:i:s') : '-',
                    $p->getLastMac() ?: '-'));
            }

            return 0;
        }
        if ('pin' === $action) {
            // exactly what SubscriptionService::handleRadiusAuthEvent does with
            // an accept from the agent's radius server
            list($section, $mac, $ap) = explode(',', $input->getArgument('arg'));
            $key = $this->ppsk->resolveBySectionName($section, $ssid->getName());
            if (!$key) {
                $output->writeln('<error>section '.$section.' resolved to no key</error>');

                return 1;
            }
            $this->radius->recordAgentAuth($mac, $ssid->getName(), $ap, 'accept', 'own key', $key,
                ['key' => $section, 'bssid' => null]);
            $pending = $this->ppsk->recordUsed($key, $mac, $ap);
            $output->writeln('resolved keyid='.$key->getKeyid().' -> recordUsed, redistribute='.var_export($pending, true));
            if ($pending) {
                $output->writeln(json_encode($this->ppsk->distribute($ssid, true), JSON_PRETTY_PRINT));
            }

            return 0;
        }
        if ('revoke' === $action) {
            $key = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->find((int) $input->getArgument('arg'));
            $output->writeln(json_encode($this->ppsk->revoke($key), JSON_PRETTY_PRINT));

            return 0;
        }
        $output->writeln('<error>unknown action</error>');

        return 1;
    }
}
