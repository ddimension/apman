<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-shot migration of managed ppsk networks to iPSK: the SSIDs that manage
 * their own keys and still point at an external RADIUS server get the iPSK
 * feature assigned, and their access points are provisioned (per-AP secret,
 * /etc/config/apman, wpa_psk_file=/dev/null). One BSS restart per AP is the
 * price of the flip — it happens on the provisioning that follows.
 *
 * Not meant to run forever: once every eligible SSID carries the feature,
 * the command has nothing left to do. New SSIDs get the feature assigned in
 * the admin like every other feature.
 */
#[AsCommand(name: 'apman:ipsk-migrate')]
class IpskMigrateCommand extends Command
{
    private $doctrine;
    private $ppsk;
    private $apService;

    public function __construct(
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Service\PpskService $ppsk,
        \ApManBundle\Service\AccessPointService $apService,
        $name = null
    ) {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->ppsk = $ppsk;
        $this->apService = $apService;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Assign the iPSK feature to managed ppsk SSIDs that still point at an external RADIUS server, and provision their access points')
            ->addOption('ssid', 's', InputOption::VALUE_REQUIRED, 'Only this SSID (id or name)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change without changing anything')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->doctrine->getManager()->getConnection();
        $sql = 'SELECT DISTINCT s.id, s.name FROM ssid s'
            ." WHERE s.auto_ppsk = 1"
            ." AND EXISTS (SELECT 1 FROM ssid_config_option p WHERE p.ssid_id = s.id"
            ." AND p.name = 'ppsk' AND p.value IN ('1','true','on','yes'))"
            ." AND EXISTS (SELECT 1 FROM ssid_config_option a WHERE a.ssid_id = s.id"
            ." AND a.name IN ('auth_server','auth_server_addr') AND a.value <> '' AND a.value <> '127.0.0.1')";
        $params = [];
        if ($needle = $input->getOption('ssid')) {
            $repo = $this->doctrine->getRepository('ApManBundle\Entity\SSID');
            $ssid = ctype_digit((string) $needle) ? $repo->find($needle) : $repo->findOneBy(['name' => $needle]);
            if (!$ssid) {
                $output->writeln('<error>no such ssid: '.$needle.'</error>');

                return 1;
            }
            $sql .= ' AND s.id = :ssid';
            $params['ssid'] = $ssid->getId();
        }
        $rows = $conn->fetchAllAssociative($sql, $params);
        if (!$rows) {
            $output->writeln('<info>nothing to migrate</info>');

            return 0;
        }

        $dry = (bool) $input->getOption('dry-run');
        $featureId = $dry ? null : $this->ensureFeatureRow($conn);
        $mapped = 0;
        $migrated = [];
        foreach ($rows as $row) {
            $migrated[(int) $row['id']] = $row['name'];
            if (!$dry && $this->ensureFeatureMap($conn, (int) $row['id'], $featureId)) {
                ++$mapped;
            }
        }

        // the access points of every affected SSID, provisioned once each
        $apRows = $conn->fetchAllAssociative(
            'SELECT DISTINCT a.id FROM device d JOIN radio r ON r.id = d.radio_id'
            .' JOIN accesspoint a ON a.id = r.accesspoint_id'
            .' WHERE d.ssid_id IN ('.implode(',', array_keys($migrated)).')'
        );

        foreach ($migrated as $name) {
            $output->writeln('<info>iPSK</info> '.$name);
        }
        $output->writeln(($dry ? 'dry-run: ' : 'feature row: ok, ').
            'newly assigned: '.($dry ? count($migrated) : $mapped)
            .', access points to provision: '.count($apRows));

        if ($dry) {
            return 0;
        }

        // The keys first, the configuration second. Provisioning removes the
        // wifi-station sections of an iPSK network (the access point answers
        // from its own key store from then on), so a run in the other order
        // leaves the network without keys until the distribution catches up.
        $ssidRepo = $this->doctrine->getRepository('ApManBundle\Entity\SSID');
        foreach (array_keys($migrated) as $id) {
            $ssid = $ssidRepo->find($id);
            if (!$ssid) {
                continue;
            }
            $result = $this->ppsk->distribute($ssid, true);
            $output->writeln('  keys for '.$ssid->getName().': '.json_encode($result));
        }

        $apRepo = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint');
        foreach ($apRows as $apRow) {
            $ap = $apRepo->find($apRow['id']);
            if (!$ap) {
                continue;
            }
            $output->writeln('provisioning '.$ap->getName().' — one BSS restart per flipped SSID follows');
            // applyConfig stages, diffs and applies with rollback — a bare
            // publishConfig would leave the changes staged but uncommitted
            $report = $this->apService->applyConfig($ap);
            $output->writeln('  '.$ap->getName().': '.($report['ok'] ? 'applied'
                : 'FAILED — '.($report['error'] ?? ($report['note'] ?? 'unknown'))));
        }

        return 0;
    }

    /**
     * @return int the catalog row id (created when missing)
     */
    private function ensureFeatureRow($conn)
    {
        // Parameterised on purpose: as an inline literal MySQL reads the
        // backslashes of the class name as escapes, the lookup never matches
        // and every run inserts another catalog row (found ten of them).
        $row = $conn->fetchAssociative(
            'SELECT id FROM feature WHERE implementation = :impl ORDER BY id LIMIT 1',
            ['impl' => 'ApManBundle\\Service\\IpskFeatureService']
        );
        if ($row) {
            return (int) $row['id'];
        }
        $conn->executeStatement(
            'INSERT INTO feature (name, implementation, config) VALUES (:name, :impl, :config)',
            ['name' => 'iPSK', 'impl' => 'ApManBundle\\Service\\IpskFeatureService',
                'config' => json_encode(['ppsk' => '1', 'auth_server' => '127.0.0.1', 'auth_server_port' => 1812]), ]
        );

        return (int) $conn->lastInsertId();
    }

    /**
     * @return bool true when a new map row was created
     */
    private function ensureFeatureMap($conn, $ssidId, $featureId)
    {
        $row = $conn->fetchAssociative(
            'SELECT id FROM ssid_feature_map WHERE ssid_id = :ssid AND feature_id = :feature',
            ['ssid' => $ssidId, 'feature' => $featureId]
        );
        if ($row) {
            return false;
        }
        $conn->executeStatement(
            'INSERT INTO ssid_feature_map (name, config, ssid_id, feature_id, priority, enabled)'
            .' VALUES (:name, :config, :ssid, :feature, :priority, :enabled)',
            ['name' => 'iPSK', 'config' => json_encode([]), 'ssid' => $ssidId,
                'feature' => $featureId, 'priority' => 0, 'enabled' => 1, ]
        );

        return true;
    }
}
