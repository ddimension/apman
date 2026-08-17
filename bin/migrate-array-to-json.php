<?php

/**
 * Turn the four serialised-PHP columns into json.
 *
 * DBAL 4 removed the "array" type, which stored values through serialize().
 * The payloads here are plain nested arrays — no objects, no non-sequential
 * integer keys — so json carries them without loss; the round trip was checked
 * on every row before this script was written, and it is checked again here per
 * row before anything is written.
 *
 * Run with --apply to write. Without it, nothing changes and the script only
 * reports what it would do. A row that is already json is left alone, so the
 * script can be run twice without harm.
 */
require dirname(__DIR__).'/config/bootstrap.php';

$apply = in_array('--apply', $argv, true);

$kernel = new ApManBundle\Kernel('prod', false);
$kernel->boot();
$conn = $kernel->getContainer()->get('doctrine')->getConnection();

$columns = [
    ['device', 'config'],
    ['feature', 'config'],
    ['ssid_feature_map', 'config'],
    ['radio', 'config_ht_capab'],
];

$total = 0;
$written = 0;
$already = 0;
$failed = [];

foreach ($columns as [$table, $column]) {
    $rows = $conn->fetchAllAssociative(
        "SELECT id, `$column` AS v FROM `$table` WHERE `$column` IS NOT NULL AND `$column` <> ''"
    );
    $n = 0;
    foreach ($rows as $row) {
        ++$total;
        $raw = $row['v'];

        // already migrated? then leave it
        json_decode($raw, true);
        if (JSON_ERROR_NONE === json_last_error() && !str_starts_with($raw, 'a:') && !str_starts_with($raw, 's:')) {
            ++$already;
            continue;
        }

        $value = @unserialize($raw);
        if (false === $value && 'b:0;' !== $raw) {
            $failed[] = "$table#{$row['id']}: not unserialisable";
            continue;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            $failed[] = "$table#{$row['id']}: not encodable — ".json_last_error_msg();
            continue;
        }

        // the check that matters: does it come back as the very same value?
        if (json_decode($json, true) !== $value) {
            $failed[] = "$table#{$row['id']}: round trip changed the value";
            continue;
        }

        if ($apply) {
            $conn->executeStatement(
                "UPDATE `$table` SET `$column` = ? WHERE id = ?",
                [$json, $row['id']]
            );
        }
        ++$written;
        ++$n;
    }
    printf("  %-18s %-16s %d zeilen, %d zu wandeln\n", $table, $column, count($rows), $n);
}

echo "\n";
printf("%d zeilen gesehen, %d %s, %d schon json, %d fehler\n",
    $total, $written, $apply ? 'gewandelt' : 'zu wandeln', $already, count($failed));

foreach ($failed as $f) {
    echo "  FEHLER $f\n";
}

if (!$apply) {
    echo "\nnichts geschrieben — mit --apply wiederholen\n";
}

exit($failed ? 1 : 0);
