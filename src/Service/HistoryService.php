<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Radio;
use ApManBundle\Entity\RadioSample;

/**
 * Keeping the numbers longer than the cache does.
 *
 * The controller sees every radio every ten seconds and remembers half an hour
 * of it. Everything a person actually asks — was yesterday evening worse than
 * this one, when did this radio move channel, has it been getting busier since
 * the neighbours moved in — needs weeks, and weeks is cheap: seventeen radios
 * at one row every five minutes is under a million rows a year.
 *
 * Nothing here talks to an access point. It reads the same cache the pages
 * read, so the memory costs the fleet nothing at all.
 */
class HistoryService
{
    /** how far apart samples are meant to be, in seconds */
    public const INTERVAL = 300;

    /** how long they are kept */
    public const KEEP_DAYS = 400;

    /**
     * A sample is refused if one was taken this recently, so that a cron that
     * fires twice, or a run by hand next to the cron, does not double the
     * series.
     */
    public const MIN_GAP = 240;

    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
    ) {
    }

    /**
     * Take one sample of every radio in the fleet.
     *
     * @return array what was written and what was not
     */
    public function sample(?int $now = null): array
    {
        $now ??= time();
        $em = $this->doctrine->getManager();
        $radios = $em->createQuery('SELECT r,a,d FROM ApManBundle\Entity\Radio r
                JOIN r.accesspoint a LEFT JOIN r.devices d ORDER BY a.name, r.name')->getResult();

        $written = 0;
        $skipped = [];
        foreach ($radios as $radio) {
            $reading = $this->read($radio);
            if (null === $reading) {
                $skipped[$this->label($radio)] = 'nothing in the cache — the access point has not '
                    .'reported since the controller last started, or it is away';
                continue;
            }
            $last = $this->lastTs($radio);
            if (null !== $last && $now - $last < self::MIN_GAP) {
                $skipped[$this->label($radio)] = 'sampled '.($now - $last).'s ago';
                continue;
            }

            $sample = new RadioSample();
            $sample->setRadio($radio);
            $sample->setTs($now);
            $sample->setStations($reading['stations']);
            $sample->setRxBytes($reading['rx']);
            $sample->setTxBytes($reading['tx']);
            $sample->setUtilization($reading['utilization']);
            $sample->setAirtimeTime($reading['airtime_time']);
            $sample->setAirtimeBusy($reading['airtime_busy']);
            $sample->setNoise($reading['noise']);
            $sample->setChannel($reading['channel']);
            $em->persist($sample);
            ++$written;
        }
        $em->flush();

        return ['ok' => true, 'written' => $written, 'skipped' => $skipped, 'ts' => $now];
    }

    /**
     * One radio as the cache currently has it, summed over its bsses.
     *
     * @return array|null null when no bss of this radio has anything cached
     */
    public function read(Radio $radio): ?array
    {
        $rx = 0;
        $tx = 0;
        $stations = 0;
        $utilization = null;
        $airtimeTime = null;
        $airtimeBusy = null;
        $noise = null;
        $channel = null;
        $seen = false;

        foreach ($radio->getDevices() as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status)) {
                continue;
            }
            $seen = true;

            // The netdev counters and not the sum of the stations': a station
            // that leaves takes its bytes with it, and a total that goes down
            // when somebody walks out of the building is not a total.
            $stats = $status['status']['statistics'] ?? null;
            if (is_array($stats)) {
                $rx += (int) ($stats['rx_bytes'] ?? 0);
                $tx += (int) ($stats['tx_bytes'] ?? 0);
            }
            $results = $status['assoclist']['results'] ?? null;
            if (is_array($results)) {
                $stations += count($results);
            }

            // These belong to the radio and every bss on it reports the same
            // ones, so the first bss that has them answers for all of them.
            $ap = $status['ap_status'] ?? null;
            if (is_array($ap)) {
                $utilization ??= self::utilizationPercent($ap['airtime']['utilization'] ?? null);
                $airtimeTime ??= isset($ap['airtime']['time']) ? (int) $ap['airtime']['time'] : null;
                $airtimeBusy ??= isset($ap['airtime']['time_busy']) ? (int) $ap['airtime']['time_busy'] : null;
                $channel ??= isset($ap['channel']) ? (int) $ap['channel'] : null;
            }
            $info = $status['info'] ?? null;
            if (is_array($info) && isset($info['noise'])) {
                $noise ??= (int) $info['noise'];
            }
        }

        return $seen
            ? ['rx' => $rx, 'tx' => $tx, 'stations' => $stations,
                'utilization' => $utilization, 'noise' => $noise, 'channel' => $channel,
                'airtime_time' => $airtimeTime, 'airtime_busy' => $airtimeBusy]
            : null;
    }

    /**
     * hostapd's channel utilisation, as a percentage.
     *
     * It is not one to begin with. The field carries the same scale as the BSS
     * Load element, a fraction of 255, and reading it as a percentage produces
     * numbers that cannot exist: ap-av-grwz radio0 reported 123 in one sample
     * and 58 in the next. 58 of 255 is 22.7 %, and the same status message put
     * time_busy at 261478 of time 1191459, which is 21.9 % — so the scale is
     * settled by the raw counters and not by assumption.
     *
     * Stored as a percentage rather than raw, because every reader of this
     * column wants the percentage and the one that forgets to divide gets an
     * answer that looks plausible for any value under 100.
     */
    public static function utilizationPercent($raw): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $value = (int) $raw;
        if ($value < 0) {
            return null;
        }

        return (int) min(100, round($value * 100 / 255));
    }

    /**
     * The series for one radio, with the byte counters turned into rates.
     *
     * A counter that went backwards means the interface was rebuilt, and the
     * honest answer for that interval is "no idea" rather than a negative or an
     * enormous rate — so it comes back as null and a chart draws a gap.
     *
     * @return array one entry per sample, oldest first
     */
    public function series(Radio $radio, int $seconds = 86400): array
    {
        $rows = $this->doctrine->getManager()->createQuery(
            'SELECT s FROM ApManBundle\Entity\RadioSample s
             WHERE s.radio = :radio AND s.ts >= :from ORDER BY s.ts ASC'
        )->setParameter('radio', $radio)->setParameter('from', time() - $seconds)->getResult();

        $plain = [];
        foreach ($rows as $row) {
            $plain[] = [
                'ts' => $row->getTs(),
                'stations' => $row->getStations(),
                'utilization' => $row->getUtilization(),
                'noise' => $row->getNoise(),
                'channel' => $row->getChannel(),
                'rx' => $row->getRxBytes(),
                'tx' => $row->getTxBytes(),
                'airtime_time' => $row->getAirtimeTime(),
                'airtime_busy' => $row->getAirtimeBusy(),
            ];
        }

        return $this->rates($plain);
    }

    /**
     * Cumulative counters turned into rates, with the awkward cases left blank.
     *
     * Pure, because this is where it can be wrong and there are three ways for
     * it to be:
     *
     * A counter that went **backwards** means the interface was rebuilt and
     * started again from zero. The traffic since the last sample is unknowable,
     * and a negative rate or an absurd positive one would both be inventions.
     *
     * A **gap** much longer than the interval — the controller was down, or the
     * access point was — carries real bytes, but spreading an hour of them
     * evenly across that hour says the radio was busy the whole time when it
     * may have been busy for five minutes of it.
     *
     * The **first** sample has nothing to be a difference from.
     *
     * All three come back as null, and a chart draws a gap. Nothing is worse
     * here than a line that looks like a measurement and is not.
     *
     * @param array $samples oldest first, each with ts, rx, tx and the rest
     */
    public function rates(array $samples): array
    {
        $out = [];
        $prev = null;
        foreach ($samples as $s) {
            $entry = [
                'ts' => (int) $s['ts'],
                'stations' => $s['stations'] ?? null,
                // what the radio says about itself, kept because it is often
                // wrong and being able to see that is worth a column
                'reported' => $s['utilization'] ?? null,
                'busy' => null,
                'noise' => $s['noise'] ?? null,
                'channel' => $s['channel'] ?? null,
                'rx_bps' => null,
                'tx_bps' => null,
            ];
            if (null !== $prev) {
                $span = (int) $s['ts'] - (int) $prev['ts'];
                $rx = (int) $s['rx'] - (int) $prev['rx'];
                $tx = (int) $s['tx'] - (int) $prev['tx'];
                $usable = $span > 0 && $span <= self::INTERVAL * 4;
                if ($usable && $rx >= 0 && $tx >= 0) {
                    $entry['rx_bps'] = (int) round($rx * 8 / $span);
                    $entry['tx_bps'] = (int) round($tx * 8 / $span);
                }
                // How busy the channel was over this interval, from the radio's
                // own counters rather than from its opinion of them. Same rules
                // as the bytes: an interval that cannot be measured is left
                // blank rather than filled in.
                $elapsed = (int) ($s['airtime_time'] ?? 0) - (int) ($prev['airtime_time'] ?? 0);
                $busy = (int) ($s['airtime_busy'] ?? 0) - (int) ($prev['airtime_busy'] ?? 0);
                if ($usable && $elapsed > 0 && $busy >= 0
                    && null !== ($s['airtime_time'] ?? null) && null !== ($prev['airtime_time'] ?? null)) {
                    $entry['busy'] = (int) min(100, round($busy * 100 / $elapsed));
                }
            }
            $out[] = $entry;
            $prev = $s;
        }

        return $out;
    }

    /**
     * The whole fleet over time, one column per sampling run.
     *
     * The runs are what makes this cheap to line up: one cron writes every
     * radio within the same second or two, so rounding the timestamp to the
     * interval puts them in the same column without any interpolation. A radio
     * that was away for a run is simply not in that column, which is why the
     * busy figure is an average of what answered and the station count is a sum
     * of it — a sum of an average would be a different number every time a
     * radio blinked.
     *
     * Split by band, because 2.4 and 5 GHz are not the same question and a
     * single line averaging them answers neither.
     *
     * @return array one entry per column, oldest first
     */
    public function fleetSeries(int $seconds = 86400): array
    {
        $from = time() - $seconds;
        $rows = $this->doctrine->getManager()->createQuery(
            'SELECT s, r, a FROM ApManBundle\Entity\RadioSample s
             JOIN s.radio r JOIN r.accesspoint a
             WHERE s.ts >= :from ORDER BY r.id ASC, s.ts ASC'
        )->setParameter('from', $from)->getResult();

        // Grouped per radio first, so that the differences that make a rate are
        // taken between two samples of the same radio and never across two.
        $perRadio = [];
        foreach ($rows as $row) {
            $radio = $row->getRadio();
            if (!$radio) {
                continue;
            }
            $perRadio[$radio->getId()]['band'] = (string) $radio->getConfigBand();
            $perRadio[$radio->getId()]['rows'][] = $row;
        }

        $columns = [];
        foreach ($perRadio as $entry) {
            $band = '' === $entry['band'] ? '?' : $entry['band'];
            $plain = [];
            foreach ($entry['rows'] as $row) {
                $plain[] = [
                    'ts' => $row->getTs(),
                    'stations' => $row->getStations(),
                    'utilization' => $row->getUtilization(),
                    'noise' => $row->getNoise(),
                    'channel' => $row->getChannel(),
                    'rx' => $row->getRxBytes(),
                    'tx' => $row->getTxBytes(),
                    'airtime_time' => $row->getAirtimeTime(),
                    'airtime_busy' => $row->getAirtimeBusy(),
                ];
            }
            foreach ($this->rates($plain) as $point) {
                $bucket = (int) (round($point['ts'] / self::INTERVAL) * self::INTERVAL);
                $columns[$bucket] ??= ['ts' => $bucket, 'stations' => 0,
                    'rx_bps' => null, 'tx_bps' => null, 'bands' => []];
                $columns[$bucket]['stations'] += (int) $point['stations'];
                foreach (['rx_bps', 'tx_bps'] as $k) {
                    if (null !== $point[$k]) {
                        $columns[$bucket][$k] = (int) $columns[$bucket][$k] + $point[$k];
                    }
                }
                if (null !== $point['busy']) {
                    $columns[$bucket]['bands'][$band][] = $point['busy'];
                }
            }
        }

        ksort($columns);
        $out = [];
        foreach ($columns as $column) {
            $busy = [];
            foreach ($column['bands'] as $band => $values) {
                $busy[$band] = (int) round(array_sum($values) / count($values));
            }
            ksort($busy);
            $column['busy'] = $busy;
            unset($column['bands']);
            $out[] = $column;
        }

        return $out;
    }

    /**
     * Throw away what is older than KEEP_DAYS.
     *
     * @return int rows removed
     */
    public function prune(?int $olderThan = null): int
    {
        $cutoff = $olderThan ?? (time() - self::KEEP_DAYS * 86400);

        return (int) $this->doctrine->getManager()->createQuery(
            'DELETE FROM ApManBundle\Entity\RadioSample s WHERE s.ts < :cutoff'
        )->setParameter('cutoff', $cutoff)->execute();
    }

    private function lastTs(Radio $radio): ?int
    {
        $rows = $this->doctrine->getManager()->createQuery(
            'SELECT MAX(s.ts) AS ts FROM ApManBundle\Entity\RadioSample s WHERE s.radio = :radio'
        )->setParameter('radio', $radio)->getScalarResult();
        $ts = $rows[0]['ts'] ?? null;

        return null === $ts ? null : (int) $ts;
    }

    private function label(Radio $radio): string
    {
        $ap = $radio->getAccessPoint();

        return ($ap ? $ap->getName() : '?').'/'.$radio->getName();
    }
}
