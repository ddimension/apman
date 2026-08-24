<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;

/**
 * The access points' system log, as far as it reaches the controller.
 *
 * Not an archive. Every access point already forwards its full log to a syslog
 * server over tcp/514 and that copy is the one to keep. What it cannot answer
 * is which agent version produced a line, whether anything was missed, and how
 * a line lines up with the control channel events the controller already holds
 * per bss — so this keeps a short ring per access point and answers those.
 *
 * The agent filters before it publishes, and that is visible here rather than
 * hidden: logd numbers every record it writes, so two consecutive lines whose
 * numbers are not consecutive say exactly how many went by in between. Most of
 * those are the agent's filter doing its job — its own counters, published on
 * properties/syslog and shown next to the lines, say how many — and the rest
 * would be loss. Without both numbers a gap is unreadable; with them it is
 * arithmetic.
 */
class SyslogService
{
    /** lines kept per access point */
    public const KEEP = 500;

    /** and for how long, which is what makes this a window and not a store */
    public const TTL = 7 * 86400;

    /** syslog(3) severities, lowest number is worst */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error',
        'warning', 'notice', 'info', 'debug'];

    public const FACILITIES = [
        0 => 'kernel', 1 => 'user', 2 => 'mail', 3 => 'daemon', 4 => 'auth',
        5 => 'syslog', 6 => 'lpr', 7 => 'news', 8 => 'uucp', 9 => 'cron',
        10 => 'authpriv', 11 => 'ftp', 16 => 'local0', 17 => 'local1',
        18 => 'local2', 19 => 'local3', 20 => 'local4', 21 => 'local5',
        22 => 'local6', 23 => 'local7',
    ];

    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
        private readonly ApUbusService $ubus,
    ) {
    }

    /** the uci options the agent reads its filter out of */
    public const OPTIONS = ['syslog_enabled', 'syslog_all', 'syslog_kernel',
        'syslog_allow', 'syslog_deny', 'syslog_allow_re'];

    /** which of those are lists rather than single values */
    public const LISTS = ['syslog_allow', 'syslog_deny', 'syslog_allow_re'];

    /**
     * Put a filter onto an access point, now.
     *
     * No agent change was needed for this and that is worth writing down: the
     * agent already watches /etc/config/apman by content digest and re-applies
     * when it changes — measured, the line it prints is "apman config changed,
     * re-applying" — and re-applying runs the syslog module's configure(). So
     * the controller writes uci and the device picks it up on its own, within a
     * watch tick, without a restart and without dropping the log stream.
     *
     * The lists are deleted before they are written. uci's add_list appends,
     * so writing a list twice without deleting it first gives an access point
     * every ident it has ever been told about, and the second write looks like
     * it worked.
     *
     * @param array $filter enabled, all, kernel, allow[], deny[], allow_re[]
     */
    public function pushFilter(AccessPoint $ap, array $filter, bool $commit = true): array
    {
        $calls = [];
        $describe = [];

        // delete first, every time: an option that is now unset must go away
        // rather than keep its old value, and a list must not accumulate
        foreach (self::OPTIONS as $option) {
            $calls[] = $this->uciExec(['delete', 'apman.main.'.$option]);
        }

        foreach ([
            'syslog_enabled' => $filter['enabled'] ?? null,
            'syslog_all' => $filter['all'] ?? null,
            'syslog_kernel' => $filter['kernel'] ?? null,
        ] as $option => $value) {
            if (null === $value) {
                continue;
            }
            $calls[] = $this->uciExec(['set', 'apman.main.'.$option.'='.($value ? '1' : '0')]);
            $describe[] = $option.'='.($value ? '1' : '0');
        }

        foreach ([
            'syslog_allow' => $filter['allow'] ?? [],
            'syslog_deny' => $filter['deny'] ?? [],
            'syslog_allow_re' => $filter['allow_re'] ?? [],
        ] as $option => $values) {
            foreach ($this->clean($values) as $value) {
                $calls[] = $this->uciExec(['add_list', 'apman.main.'.$option.'='.$value]);
            }
            if ($this->clean($values)) {
                $describe[] = $option.'='.count($this->clean($values));
            }
        }

        if ($commit) {
            $calls[] = $this->uciExec(['commit', 'apman']);
        }

        $answers = $this->ubus->callMany($ap, $calls, 30);
        $failed = [];
        foreach ($answers as $i => $res) {
            // a delete of an option that was never set answers "not found",
            // which is the ordinary case on a first push and not a failure
            if ($res && $res->isOk()) {
                continue;
            }
            if ($i < count(self::OPTIONS)) {
                continue;
            }
            $failed[] = $i.': '.($res ? $res->why() : 'no answer');
        }

        if ($failed) {
            $this->logger->warning('syslog: could not put the filter on '.$ap->getName()
                .': '.implode('; ', $failed));

            return ['ok' => false, 'ap' => $ap->getName(), 'error' => implode('; ', $failed)];
        }

        $this->logger->notice('syslog: '.$ap->getName().' filter set — '
            .(implode(', ', $describe) ?: 'everything cleared, the agent falls back to its default')
            .'. It re-reads /etc/config/apman on its own; no restart.');

        return ['ok' => true, 'ap' => $ap->getName(), 'set' => $describe,
            'calls' => count($calls)];
    }

    /**
     * The filter an access point should have: its own, or nothing.
     */
    public function intended(AccessPoint $ap): ?array
    {
        return $ap->getSyslogFilter();
    }

    /**
     * What it is actually running, from the agent's own report.
     */
    public function running(AccessPoint $ap): ?array
    {
        $c = $this->counters($ap);
        if (null === $c) {
            return null;
        }

        return [
            'enabled' => (bool) ($c['enabled'] ?? false),
            'all' => (bool) ($c['allow_all'] ?? false),
            'kernel' => (bool) ($c['kernel_enabled'] ?? false),
            'allow' => is_array($c['allow'] ?? null) ? $c['allow'] : [],
            'allow_re' => is_array($c['allow_re'] ?? null) ? array_values($c['allow_re']) : [],
        ];
    }

    /**
     * Where the intention and the device disagree.
     *
     * deny is left out on purpose: it is an instruction to remove idents from
     * the built-in defaults, so it never appears in the running list — what it
     * did is visible as an absence there, and comparing it directly would
     * report a difference on every access point that has one.
     *
     * @return string[] one line per disagreement, empty when they match
     */
    public function drift(AccessPoint $ap): array
    {
        return $this->driftBetween($this->intended($ap), $this->running($ap));
    }

    /**
     * The comparison itself, with nothing to fetch.
     *
     * @param array|null $want what the controller asked for
     * @param array|null $have what the agent reports it is running
     */
    public function driftBetween(?array $want, ?array $have): array
    {
        if (null === $want || null === $have) {
            return [];
        }
        $out = [];
        foreach (['enabled', 'all', 'kernel'] as $flag) {
            if (!array_key_exists($flag, $want)) {
                continue;
            }
            if ((bool) $want[$flag] !== (bool) $have[$flag]) {
                $out[] = $flag.': asked for '.($want[$flag] ? 'on' : 'off')
                    .', running '.($have[$flag] ? 'on' : 'off');
            }
        }
        foreach (['allow_re'] as $list) {
            $a = $this->clean($want[$list] ?? []);
            $b = $this->clean($have[$list] ?? []);
            sort($a);
            sort($b);
            if ($a !== $b) {
                $out[] = $list.': asked for ['.implode(' ', $a).'], running ['.implode(' ', $b).']';
            }
        }
        // allow is additive over the agent's defaults, so every asked-for ident
        // has to be present; extra ones on the device are its defaults and not
        // a disagreement
        $missing = array_diff($this->clean($want['allow'] ?? []), $this->clean($have['allow'] ?? []));
        if ($missing) {
            $out[] = 'allow: asked for '.implode(', ', $missing).' and they are not running';
        }

        return $out;
    }

    private function uciExec(array $params): array
    {
        $opts = new \stdClass();
        $opts->command = '/sbin/uci';
        $opts->params = $params;

        return ['object' => 'file', 'method' => 'exec', 'args' => $opts];
    }

    /**
     * @return string[] trimmed, no blanks, no duplicates, order kept
     */
    public function clean($values): array
    {
        $out = [];
        foreach ((array) $values as $value) {
            $value = trim((string) $value);
            if ('' === $value || in_array($value, $out, true)) {
                continue;
            }
            $out[] = $value;
        }

        return $out;
    }

    public function linesKey(AccessPoint $ap): string
    {
        return 'syslog.ap.'.$ap->getId().'.lines';
    }

    public function markKey(AccessPoint $ap): string
    {
        return 'syslog.ap.'.$ap->getId().'.last';
    }

    public function countersKey(AccessPoint $ap): string
    {
        return 'syslog.ap.'.$ap->getId().'.counters';
    }

    /**
     * One line from one access point.
     *
     * @param array $line as the agent publishes it: id, ts, source, facility,
     *                    level, ident, text
     */
    public function record(AccessPoint $ap, array $line): bool
    {
        $id = isset($line['id']) ? (int) $line['id'] : null;
        if (null === $id) {
            return false;
        }

        $entry = $this->normalise($ap, $line);
        $last = $this->cacheFactory->getCacheItemValue($this->markKey($ap));
        $entry['missed'] = $this->missedBefore(is_array($last) ? $last : null, $id);
        $this->cacheFactory->addCacheItem($this->markKey($ap),
            ['id' => $id, 'ts' => time()], self::TTL);

        $key = $this->linesKey($ap);
        $lines = $this->cacheFactory->getCacheItemValue($key);
        if (!is_array($lines)) {
            $lines = [];
        }
        array_unshift($lines, $entry);
        if (count($lines) > self::KEEP) {
            $lines = array_slice($lines, 0, self::KEEP);
        }
        $this->cacheFactory->addCacheItem($key, $lines, self::TTL);

        return true;
    }

    /**
     * How many records the access point numbered between the last one we saw
     * and this one.
     *
     * Pure, and worth being: the three cases that are not a gap all look like
     * one from the wrong angle. A first line has nothing to be a gap from. A
     * number that went backwards means logd was restarted and began again, not
     * that four billion records happened. And a mark old enough that the ring
     * has turned over says nothing useful either — an access point that was
     * away for a day did not miss the day's difference, it missed the day.
     *
     * @param array|null $last ['id' => int, 'ts' => int]
     */
    public function missedBefore(?array $last, int $id): ?int
    {
        if (null === $last || !isset($last['id'])) {
            return null;
        }
        if (isset($last['ts']) && time() - (int) $last['ts'] > 3600) {
            return null;
        }
        $previous = (int) $last['id'];
        if ($id <= $previous) {
            return null;
        }
        $gap = $id - $previous - 1;

        return $gap > 0 ? $gap : 0;
    }

    /**
     * The agent's own account of what it read and what it dropped.
     */
    public function counters(AccessPoint $ap): ?array
    {
        $value = $this->cacheFactory->getCacheItemValue($this->countersKey($ap));

        return is_array($value) ? $value : null;
    }

    public function setCounters(AccessPoint $ap, array $counters): void
    {
        $counters['received'] = time();
        $this->cacheFactory->addCacheItem($this->countersKey($ap), $counters, self::TTL);
    }

    /**
     * The lines of one access point, newest first.
     */
    public function lines(AccessPoint $ap): array
    {
        $lines = $this->cacheFactory->getCacheItemValue($this->linesKey($ap));

        return is_array($lines) ? $lines : [];
    }

    /**
     * The whole fleet in one list, newest first.
     *
     * Merged at read time out of the per access point rings rather than kept as
     * one: seven short lists sorted together costs nothing, and one shared ring
     * would let a chatty access point push everybody else's lines out of it.
     *
     * @param array $filter ap, level (worst to keep), ident, text
     */
    public function recent(array $filter = [], int $limit = 200): array
    {
        $aps = $this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findBy([], ['name' => 'ASC']);

        $out = [];
        foreach ($aps as $ap) {
            if (!empty($filter['ap']) && $ap->getName() !== $filter['ap']) {
                continue;
            }
            foreach ($this->lines($ap) as $line) {
                if (!$this->matches($line, $filter)) {
                    continue;
                }
                $out[] = $line;
            }
        }
        usort($out, fn ($a, $b) => [$b['ts'] ?? 0, $b['id'] ?? 0] <=> [$a['ts'] ?? 0, $a['id'] ?? 0]);

        return array_slice($out, 0, $limit);
    }

    /**
     * Whether one line survives a filter. Pure.
     */
    public function matches(array $line, array $filter): bool
    {
        if (isset($filter['level']) && '' !== $filter['level']
            && null !== ($line['level'] ?? null)
            && (int) $line['level'] > (int) $filter['level']) {
            // syslog counts down: 0 is an emergency and 7 is debug, so
            // "warning and worse" is level <= 4
            return false;
        }
        if (!empty($filter['ident']) && ($line['ident'] ?? '') !== $filter['ident']) {
            return false;
        }
        if (!empty($filter['text'])
            && false === stripos((string) ($line['text'] ?? ''), (string) $filter['text'])) {
            return false;
        }

        return true;
    }

    /**
     * Every ident that has been seen, with how often — for the filter box.
     */
    public function idents(): array
    {
        $counts = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')->findAll() as $ap) {
            foreach ($this->lines($ap) as $line) {
                $ident = $line['ident'] ?? ('kernel' === ($line['source'] ?? '') ? 'kernel' : null);
                if (null === $ident) {
                    continue;
                }
                $counts[$ident] = ($counts[$ident] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * What every access point's agent says about its own stream.
     *
     * The lines alone cannot tell a quiet access point from one whose filter is
     * eating everything, or from one that never attached. These numbers can,
     * and an access point with no entry at all has not published any — which is
     * the answer for "the feature is off" and for "the agent is too old" alike.
     */
    public function fleetCounters(): array
    {
        $out = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findBy([], ['name' => 'ASC']) as $ap) {
            $counters = $this->counters($ap);
            $lines = $this->lines($ap);
            $out[$ap->getName()] = [
                'ap' => $ap,
                'productive' => $ap->getIsProductive(),
                'counters' => $counters,
                'kept' => count($lines),
                'newest' => $lines[0]['ts'] ?? null,
                'age' => isset($counters['received']) ? time() - (int) $counters['received'] : null,
            ];
        }

        return $out;
    }

    /**
     * The agent's payload in the shape the pages read.
     */
    private function normalise(AccessPoint $ap, array $line): array
    {
        $level = isset($line['level']) ? (int) $line['level'] : null;
        $facility = isset($line['facility']) ? (int) $line['facility'] : null;

        return [
            'id' => (int) $line['id'],
            // the agent sends milliseconds; seconds are what every other
            // timestamp in this application is
            'ts' => isset($line['ts']) ? (int) round(((float) $line['ts']) / 1000) : time(),
            'ap' => $ap->getName(),
            'ap_id' => $ap->getId(),
            'source' => (string) ($line['source'] ?? 'syslog'),
            'facility' => $facility,
            'facility_name' => self::FACILITIES[$facility] ?? null,
            'level' => $level,
            'level_name' => (null !== $level && isset(self::LEVELS[$level]))
                ? self::LEVELS[$level] : null,
            'ident' => $line['ident'] ?? null,
            'text' => (string) ($line['text'] ?? ''),
            'truncated' => !empty($line['truncated']),
        ];
    }
}
