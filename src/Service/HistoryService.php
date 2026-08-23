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
     * where the running byte counters of each station are remembered between
     * runs, so that a difference can be taken
     */
    public const STATION_MARK = 'history.sta.';

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
     * Add what every associated station has moved since the last run to its day.
     *
     * The counters come from the station dump the agent already publishes, and
     * they are the station's own, so they start again from zero whenever it
     * reassociates and they mean nothing across a roam to another bss. Both
     * cases are the same case, and neither loses anything: the mark records
     * which bss the numbers came from, and when they do not line up the counter
     * is read as an absolute rather than as one end of a difference. A station
     * that has just arrived on a bss has counters that started at zero when it
     * did, so what they read now is exactly what it has moved since.
     *
     * Only a mark too old to reason from is given up on, and then the run just
     * sets a new one: the counter might hold a day of traffic from before the
     * controller was restarted, and putting that on today would be worse than
     * missing it.
     *
     * @return array how many stations were counted, and how many started over
     */
    public function sampleClients(?int $now = null): array
    {
        $now ??= time();
        $em = $this->doctrine->getManager();
        $devices = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                JOIN d.radio r JOIN r.accesspoint a')->getResult();

        $day = (new \DateTime())->setTimestamp($now)->setTime(0, 0);
        $rows = [];
        $counted = 0;
        $restarted = 0;

        foreach ($devices as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            $stations = is_array($status) ? ($status['stations'] ?? null) : null;
            if (!is_array($stations)) {
                continue;
            }
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            $ifname = (string) $device->ifname();

            foreach ($stations as $mac => $sta) {
                if (!is_array($sta)) {
                    continue;
                }
                $mac = strtolower((string) $mac);
                $rx = (int) ($sta['rx_bytes'] ?? 0);
                $tx = (int) ($sta['tx_bytes'] ?? 0);

                $key = self::STATION_MARK.str_replace(':', '', $mac);
                $mark = $this->cacheFactory->getCacheItemValue($key);
                $step = self::since($mark, $ifname, $rx, $tx, $now);
                $dRx = $step['rx'];
                $dTx = $step['tx'];
                if ('restarted' === $step['how']) {
                    ++$restarted;
                }
                $this->cacheFactory->addCacheItem($key,
                    ['if' => $ifname, 'rx' => $rx, 'tx' => $tx, 'ts' => $now], 2 * 86400);

                $row = $rows[$mac] ??= $this->dayRow($mac, $day);
                $row->setRxBytes($row->getRxBytes() + $dRx);
                $row->setTxBytes($row->getTxBytes() + $dTx);
                $row->setSecondsSeen($row->getSecondsSeen() + self::INTERVAL);
                $row->setLastIfname($ifname);
                $row->setLastAp($ap ? $ap->getName() : null);

                $signal = isset($sta['signal']) ? (int) $sta['signal'] : null;
                if (null !== $signal && $signal < 0) {
                    if (null === $row->getMinSignal() || $signal < $row->getMinSignal()) {
                        $row->setMinSignal($signal);
                    }
                    if (null === $row->getMaxSignal() || $signal > $row->getMaxSignal()) {
                        $row->setMaxSignal($signal);
                    }
                }
                ++$counted;
            }
        }
        $em->flush();

        return ['ok' => true, 'stations' => $counted, 'restarted' => $restarted,
            'rows' => count($rows)];
    }

    /**
     * How much this station has moved since the last run, and how we know.
     *
     * Pure, because this is the whole of the accounting and every one of its
     * three answers is a decision that can be wrong.
     *
     * **It continued.** Same bss, counters no lower than they were, mark recent
     * enough: the difference is the traffic.
     *
     * **It restarted.** A different bss, or counters that went down. Its
     * counters on the bss it is on now began at zero when it got there, so what
     * they read is what it has moved since — all of it, and not nothing.
     * Reading this as zero threw away about a sixth of a fleet's stations on
     * every run.
     *
     * **We cannot say.** No mark, or one too old to reason from. The counter
     * might hold a day of traffic from before the controller was restarted, and
     * putting that on today would be worse than missing it, so the run only
     * leaves a new mark behind.
     *
     * @param array|null $mark what the last run wrote down, if anything
     */
    public static function since($mark, string $ifname, int $rx, int $tx, int $now): array
    {
        if (!is_array($mark) || !isset($mark['ts'])
            || $now - (int) $mark['ts'] > self::INTERVAL * 4) {
            return ['rx' => 0, 'tx' => 0, 'how' => 'unknown'];
        }
        if (($mark['if'] ?? null) === $ifname
            && $rx >= (int) ($mark['rx'] ?? 0) && $tx >= (int) ($mark['tx'] ?? 0)) {
            return ['rx' => $rx - (int) $mark['rx'], 'tx' => $tx - (int) $mark['tx'],
                'how' => 'continued'];
        }

        return ['rx' => $rx, 'tx' => $tx, 'how' => 'restarted'];
    }

    /**
     * Today's row for this station, made if it is the first sighting today.
     */
    private function dayRow(string $mac, \DateTimeInterface $day): \ApManBundle\Entity\ClientDay
    {
        $em = $this->doctrine->getManager();
        $row = $em->getRepository('ApManBundle\Entity\ClientDay')
            ->findOneBy(['mac' => $mac, 'day' => $day]);
        if ($row) {
            return $row;
        }
        $row = new \ApManBundle\Entity\ClientDay();
        $row->setMac($mac);
        $row->setDay($day);
        $em->persist($row);

        return $row;
    }

    /**
     * What every station moved over the last few days, busiest first.
     *
     * @return array one entry per station
     */
    public function busiestClients(int $days = 7, int $limit = 25): array
    {
        $from = (new \DateTime())->modify('-'.max(0, $days - 1).' days')->setTime(0, 0);
        $rows = $this->doctrine->getManager()->createQuery(
            'SELECT c.mac AS mac, SUM(c.rxBytes) AS rx, SUM(c.txBytes) AS tx,
                    SUM(c.secondsSeen) AS seen, MAX(c.day) AS last_day
             FROM ApManBundle\Entity\ClientDay c
             WHERE c.day >= :from
             GROUP BY c.mac ORDER BY SUM(c.rxBytes) + SUM(c.txBytes) DESC'
        )->setParameter('from', $from)->setMaxResults($limit)->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'mac' => $row['mac'],
                'rx' => (int) $row['rx'],
                'tx' => (int) $row['tx'],
                'total' => (int) $row['rx'] + (int) $row['tx'],
                'seen' => (int) $row['seen'],
                'last_day' => $row['last_day'],
            ];
        }

        return $out;
    }

    /**
     * One station, day by day.
     *
     * @return \ApManBundle\Entity\ClientDay[]
     */
    public function clientDays(string $mac, int $days = 30): array
    {
        $from = (new \DateTime())->modify('-'.max(0, $days - 1).' days')->setTime(0, 0);

        return $this->doctrine->getManager()->createQuery(
            'SELECT c FROM ApManBundle\Entity\ClientDay c
             WHERE c.mac = :mac AND c.day >= :from ORDER BY c.day ASC'
        )->setParameter('mac', strtolower($mac))->setParameter('from', $from)->getResult();
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

        $gone = (int) $this->doctrine->getManager()->createQuery(
            'DELETE FROM ApManBundle\Entity\RadioSample s WHERE s.ts < :cutoff'
        )->setParameter('cutoff', $cutoff)->execute();

        $day = (new \DateTime())->setTimestamp($cutoff)->setTime(0, 0);
        $gone += (int) $this->doctrine->getManager()->createQuery(
            'DELETE FROM ApManBundle\Entity\ClientDay c WHERE c.day < :day'
        )->setParameter('day', $day)->execute();

        return $gone;
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
