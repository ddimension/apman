<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;

/**
 * A channel availability check, from beginning to end, with a deadline.
 *
 * Before this, CAC was a boolean. `cac_active` was true or it was not, the
 * state tree turned it into a node state, and nothing knew how long it had been
 * running or how long it was supposed to. So a radio stuck in CAC and a radio
 * two seconds into a normal one looked identical, and the only thing that could
 * tell them apart was a person watching the number go down.
 *
 * hostapd reports the expectation itself, per radio, in `dfs.cac_seconds` — and
 * it is not a constant. Measured on 2026-08-22, both access points on channel
 * 116:
 *
 *     ap-outdoor    cac_seconds 600
 *     ap-outdoor2   cac_seconds  60
 *
 * The difference is the regulatory rules their phys carry. 116 at 80 MHz spans
 * 5570–5650 and crosses 5590, where ETSI puts the weather radar range and its
 * ten minute check; a phy whose rules describe that range separately gets the
 * ten minutes, one whose rules cover 5470–5725 in a single block does not. So
 * the expectation has to be read from the radio and cannot be a table in here.
 *
 * What this adds: when it started, what it is waiting for, whether it has run
 * over, and what happened to the last one.
 *
 * ## Where to look while it is running
 *
 * ubus is no good for it. Provoked on ap-av-attic on 2026-08-22 by moving
 * radio1 to channel 116 and then to 52:
 *
 *     hostapd: wap-kc1: DFS-CAC-START freq=5260 chan=52 cac_time=60s
 *     ubus list                    → no hostapd.wap-kc1 at all, only the phy2 objects
 *     hostapd.wap-kc1 get_status   → not found (4), for the whole check
 *     network.wireless status      → radio1 up:true pending:false, as always
 *
 * hostapd registers its ubus object when the interface is enabled, and during a
 * check it is not. **The control socket is there the whole time**, which is the
 * thing to use:
 *
 *     /var/run/hostapd/wap-kc1     → exists throughout
 *     hostapd_cli -i wap-kc1 status → state=DFS, freq=5260,
 *                                     cac_time_seconds, cac_time_left_seconds
 *
 * So `probe()` asks the socket and gets the present tense; `observe()` keeps the
 * episode from the status cycle, which covers a radar move on a running bss;
 * and `expectFor()` remembers the duration for when neither can be reached.
 *
 * One thing found on the way and not fixed here, because it is the agent's:
 * the agent's control channel monitors — the apman-mon-* sockets — exist only
 * for the bsses whose ubus object exists. It attached to wap-kc1 at 13:36:25
 * and did not come back after the interface went down at 13:40:17, so
 * DFS-CAC-START reaches nobody. Attaching to the socket rather than following
 * the ubus object would deliver it.
 */
class DfsService
{
    /**
     * How far past the expectation counts as overdue.
     *
     * The status cycle is what tells us the check has finished, so the answer
     * arrives one cycle late at best. Fifteen seconds covers that, and a tenth
     * of the expectation covers the ten minute case, where a minute of slack is
     * noise rather than a fault.
     */
    public const GRACE_SECONDS = 15;

    /** if hostapd names no expectation, this is the ETSI floor for a dfs channel */
    public const DEFAULT_SECONDS = 60;

    /** an episode is kept this long after it ends, so the page can say what happened */
    public const KEEP_SECONDS = 3600;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
        private readonly ApUbusService $ubus,
    ) {
    }

    /**
     * What one bss just said about its radio's channel check.
     *
     * Called for every status message. CAC belongs to the radio and hostapd
     * reports it per bss, so several bsses say the same thing about one
     * episode; the first to say it starts it and the rest keep it current.
     */
    public function observe(Device $device, array $apStatus): void
    {
        $radio = $device->getRadio();
        if (!$radio || !isset($apStatus['dfs']) || !is_array($apStatus['dfs'])) {
            return;
        }
        $dfs = $apStatus['dfs'];
        $active = (bool) ($dfs['cac_active'] ?? false);
        if ($active) {
            $this->logger->debug('dfs observe: '.$device->ifname().' reports a check running, '
                .json_encode($dfs));
        }
        $expected = (int) ($dfs['cac_seconds'] ?? 0) ?: self::DEFAULT_SECONDS;
        $left = isset($dfs['cac_seconds_left']) ? (int) $dfs['cac_seconds_left'] : null;
        $freq = isset($apStatus['freq']) ? (int) $apStatus['freq'] : null;
        $now = time();

        // Remembered whether or not a check is running: this is the only place
        // the expectation is ever visible, and it is needed most when it is
        // not — while the bss is down and nothing can be asked.
        if ($freq && $expected) {
            $this->cacheFactory->addCacheItem('dfs.expect.radio.'.$radio->getId(),
                ['freq' => $freq, 'channel' => $apStatus['channel'] ?? null,
                    'seconds' => $expected, 'seen' => $now], 30 * 86400);
        }

        $key = $this->key($radio);
        $episode = $this->cacheFactory->getCacheItemValue($key);
        $episode = is_array($episode) ? $episode : null;

        if ($active) {
            // A new episode, or the same one continuing. The frequency decides:
            // a check that starts on another channel is another check, and
            // treating it as a continuation would make the first one look
            // eternally overdue.
            if (!$episode || !empty($episode['ended']) || ($episode['freq'] ?? null) !== $freq) {
                $episode = [
                    'radio' => $radio->getName(),
                    'ap' => $radio->getAccessPoint() ? $radio->getAccessPoint()->getName() : null,
                    'started' => $now,
                    'freq' => $freq,
                    'channel' => $apStatus['channel'] ?? null,
                    'expected' => $expected,
                    'ended' => null,
                    'outcome' => null,
                    'reported_overdue' => false,
                ];
                $this->logger->notice('dfs: '.$this->where($episode).' started a channel '
                    .'availability check on '.$freq.' MHz, '.$expected.' seconds expected');
            }
            $episode['expected'] = $expected;
            $episode['left'] = $left;
            $episode['seen'] = $now;

            $elapsed = $now - (int) $episode['started'];
            if (!$episode['reported_overdue'] && $elapsed > $this->deadline($expected)) {
                $episode['reported_overdue'] = true;
                $this->logger->error('dfs: '.$this->where($episode).' has been checking '
                    .$freq.' MHz for '.$elapsed.' seconds and '.$expected.' were expected — '
                    .'the radio is not carrying traffic and something is keeping it there');
            }
            $this->cacheFactory->addCacheItem($key, $episode, self::KEEP_SECONDS);

            return;
        }

        // Not checking. If one was running, it has just finished — and whether
        // that is a success is a different question from whether it stopped.
        if ($episode && empty($episode['ended'])) {
            $episode['ended'] = $now;
            $episode['left'] = 0;
            $took = $now - (int) $episode['started'];
            $episode['took'] = $took;
            $enabled = 'ENABLED' === ($apStatus['status'] ?? null);
            $moved = null !== $freq && ($episode['freq'] ?? null) !== $freq;
            $episode['outcome'] = $moved ? 'moved' : ($enabled ? 'completed' : 'stopped');
            $episode['ended_freq'] = $freq;

            $this->logger->notice('dfs: '.$this->where($episode).' finished after '.$took
                .' seconds of '.$episode['expected'].' expected — '
                .($moved ? 'and the radio is on '.$freq.' MHz now — radar, or somebody moved it'
                    : ($enabled ? 'the radio is carrying traffic'
                        : 'and the radio is not enabled, so it is not carrying traffic')));
            $this->cacheFactory->addCacheItem($key, $episode, self::KEEP_SECONDS);
        }
    }

    /**
     * Turn a probe into the same episode observe() keeps.
     *
     * The status cycle cannot see a check that runs while the bss is down, so
     * whoever notices the silence asks the socket and hands the answer here.
     * From the episode's point of view it makes no difference where the fact
     * came from — it still has a start, an expectation and a deadline.
     */
    public function noteProbe(Radio $radio, array $probe): void
    {
        $now = time();
        $key = $this->key($radio);
        $episode = $this->cacheFactory->getCacheItemValue($key);
        $episode = is_array($episode) ? $episode : null;
        $freq = $probe['freq'] ?? null;
        $expected = (int) ($probe['expected'] ?? 0) ?: self::DEFAULT_SECONDS;

        if (!($probe['checking'] ?? false)) {
            if ($episode && empty($episode['ended'])) {
                $episode['ended'] = $now;
                $episode['took'] = $now - (int) $episode['started'];
                $episode['left'] = 0;
                $moved = null !== $freq && ($episode['freq'] ?? null) !== $freq;
                $episode['outcome'] = $moved ? 'moved'
                    : ('ENABLED' === ($probe['state'] ?? null) ? 'completed' : 'stopped');
                $this->logger->notice('dfs: '.$this->where($episode).' finished after '
                    .$episode['took'].'s of '.$episode['expected'].' expected — '.$episode['outcome']);
                $this->cacheFactory->addCacheItem($key, $episode, self::KEEP_SECONDS);
            }

            return;
        }

        if (!$episode || !empty($episode['ended']) || ($episode['freq'] ?? null) !== $freq) {
            $episode = [
                'radio' => $radio->getName(),
                'ap' => $radio->getAccessPoint() ? $radio->getAccessPoint()->getName() : null,
                // The socket counts down, so the start can be worked out rather
                // than guessed at: a check with 42 of 60 seconds left began 18
                // seconds ago, whether or not anybody was watching then.
                'started' => (null !== ($probe['left'] ?? null))
                    ? $now - max(0, $expected - (int) $probe['left']) : $now,
                'freq' => $freq,
                'channel' => $probe['channel'] ?? null,
                'expected' => $expected,
                'ended' => null,
                'outcome' => null,
                'reported_overdue' => false,
                'from' => 'socket',
            ];
            $this->logger->notice('dfs: '.$this->where($episode).' is listening on '.$freq
                .' MHz, '.$expected.'s expected'
                .(null !== ($probe['left'] ?? null) ? ', '.$probe['left'].'s left' : '')
                .' — the control socket said so, there is no bss to ask');
        }
        $episode['expected'] = $expected;
        $episode['left'] = $probe['left'] ?? null;
        $episode['seen'] = $now;
        $elapsed = $now - (int) $episode['started'];
        if (!$episode['reported_overdue'] && $elapsed > $this->deadline($expected)) {
            $episode['reported_overdue'] = true;
            $this->logger->error('dfs: '.$this->where($episode).' has been listening on '.$freq
                .' MHz for '.$elapsed.'s and '.$expected.'s were expected — no traffic passes '
                .'and something is keeping it there');
        }
        $this->cacheFactory->addCacheItem($key, $episode, self::KEEP_SECONDS);
    }

    /**
     * Where one radio stands, for a page or a check to read.
     *
     * Null when nothing is known — a radio that has never been seen checking on
     * a band that does not need it.
     */
    public function state(Radio $radio): ?array
    {
        $episode = $this->cacheFactory->getCacheItemValue($this->key($radio));
        if (!is_array($episode)) {
            return null;
        }
        $now = time();
        $active = empty($episode['ended']);
        $elapsed = $now - (int) $episode['started'];
        $expected = (int) ($episode['expected'] ?: self::DEFAULT_SECONDS);

        return $episode + [
            'active' => $active,
            'elapsed' => $active ? $elapsed : (int) ($episode['took'] ?? $elapsed),
            'deadline' => $this->deadline($expected),
            'overdue' => $active && $elapsed > $this->deadline($expected),
            // What the radio itself counts down, where it disagrees with the
            // clock. hostapd restarts its own counter on a channel switch, so
            // the two coming apart is itself worth seeing.
            'left' => $episode['left'] ?? null,
            'age' => $active ? null : $now - (int) $episode['ended'],
        ];
    }

    /** Is this radio unable to carry traffic right now because of a check? */
    public function isChecking(Radio $radio): bool
    {
        $state = $this->state($radio);

        return (bool) ($state['active'] ?? false);
    }

    /**
     * Ask the radio what it is doing, now, through hostapd's control socket.
     *
     * The socket answers while the ubus object does not exist, which is exactly
     * the window this whole class is about. `state` is `DFS` while a check is
     * running and `ENABLED` when the radio is carrying traffic, and the two cac
     * fields come with it.
     *
     * Costs one round trip — measured at 175 ms over MQTT — so it is for the
     * moments that need the present tense, not for every page view.
     *
     * @return array|null null when the access point did not answer at all
     */
    public function probe(\ApManBundle\Entity\AccessPoint $ap, string $ifname): ?array
    {
        $opts = new \stdClass();
        $opts->command = '/usr/sbin/hostapd_cli';
        $opts->params = ['-p', '/var/run/hostapd', '-i', $ifname, 'status'];
        $started = microtime(true);
        $res = $this->ubus->call($ap, 'file', 'exec', $opts, 10);
        $ms = round((microtime(true) - $started) * 1000, 1);
        if (!$res->isOk()) {
            $this->logger->debug('dfs probe: '.$ap->getName().'/'.$ifname.' did not answer in '
                .$ms.' ms: '.$res->why());

            return null;
        }
        $data = $res->data;
        $stdout = is_object($data) ? (string) ($data->stdout ?? '') : '';
        if ('' === $stdout) {
            $this->logger->debug('dfs probe: '.$ap->getName().'/'.$ifname
                .' answered in '.$ms.' ms with nothing on stdout — no control socket for it?');

            return null;
        }

        $fields = [];
        foreach (explode("\n", $stdout) as $line) {
            if (false === strpos($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $fields[trim($k)] = trim($v);
        }
        if (!isset($fields['state'])) {
            $this->logger->debug('dfs probe: '.$ap->getName().'/'.$ifname.' answered in '.$ms
                .' ms without a state field: '.substr(str_replace("\n", ' ', $stdout), 0, 120));

            return null;
        }

        $left = $fields['cac_time_left_seconds'] ?? 'N/A';
        $this->logger->debug('dfs probe: '.$ap->getName().'/'.$ifname.' is '.$fields['state']
            .' on '.($fields['freq'] ?? '?').' MHz after '.$ms.' ms'
            .(isset($fields['cac_time_seconds']) ? ', cac '.$fields['cac_time_seconds'].'s' : '')
            .(is_numeric($left) ? ', '.$left.'s left' : ''));

        return [
            'state' => $fields['state'],
            'checking' => 'DFS' === $fields['state'],
            'freq' => isset($fields['freq']) ? (int) $fields['freq'] : null,
            'channel' => isset($fields['channel']) ? (int) $fields['channel'] : null,
            'expected' => isset($fields['cac_time_seconds']) ? (int) $fields['cac_time_seconds'] : null,
            'left' => is_numeric($left) ? (int) $left : null,
        ];
    }

    /**
     * How long this radio would need to listen before it may transmit, on the
     * channel it was last seen on. Zero when the channel needs no check.
     *
     * Read from the last status that named it, because during the check itself
     * there is nothing to ask. A radio that has never been seen on a dfs
     * channel returns zero rather than a guess.
     */
    public function expectFor(Radio $radio): int
    {
        $known = $this->cacheFactory->getCacheItemValue('dfs.expect.radio.'.$radio->getId());
        if (!is_array($known)) {
            return 0;
        }
        $freq = (int) ($known['freq'] ?? 0);
        // 5250-5350 and 5470-5730 are the dfs ranges in ETSI and FCC alike;
        // outside them hostapd names a duration it never uses
        if ($freq < 5250 || ($freq > 5350 && $freq < 5470) || $freq > 5730) {
            return 0;
        }

        return (int) ($known['seconds'] ?? 0);
    }

    /**
     * How long to wait for this radio, when waiting for it at all.
     *
     * A bss on a radio doing a ten minute check is not a bss that failed to
     * come up, and a provisioning run that gives it forty seconds says it is.
     * The check itself cannot be watched while the bss is down, so the wait
     * comes from the expectation instead — which is the whole reason the
     * expectation is remembered.
     */
    public function waitBudget(Radio $radio, int $floor): int
    {
        $state = $this->state($radio);
        if ($state['active'] ?? false) {
            $remaining = max(0, (int) $state['deadline'] - (int) $state['elapsed']);

            return max($floor, $remaining + self::GRACE_SECONDS);
        }
        $expected = $this->expectFor($radio);

        return $expected ? max($floor, $this->deadline($expected)) : $floor;
    }

    private function deadline(int $expected): int
    {
        return $expected + max(self::GRACE_SECONDS, (int) ceil($expected / 10));
    }

    private function key(Radio $radio): string
    {
        return 'dfs.radio.'.$radio->getId();
    }

    private function where(array $episode): string
    {
        return ($episode['ap'] ?? '?').'/'.($episode['radio'] ?? '?');
    }
}
