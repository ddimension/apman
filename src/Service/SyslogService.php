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
    /**
     * The lines worth keeping longer than the access point keeps them.
     *
     * An access point's own log is a ring in tmpfs: a few hours, and gone
     * entirely at the next boot. That is exactly wrong for the two things you
     * want a history of — a firmware fault that ends in a reboot erases its own
     * evidence, and a radar event is interesting precisely weeks later, when
     * somebody asks why that radio is not on the channel it was configured for.
     *
     * So these are lifted out of the stream as they pass and kept centrally.
     * Nothing here acts: the reboot on a firmware fault stays in the cron job
     * on the access point, where it still works when the controller, the broker
     * or the network in between is the thing that is broken.
     *
     * @var array<string,array{re:string,label:string,bad:bool}>
     */
    public const EVENTS = [
        'ath11k_fault' => [
            're' => '/failed to send WMI_PDEV_BSS_CHAN_INFO_REQUEST cmd|too many connected already/',
            'label' => 'ath11k firmware fault', 'bad' => true,
        ],
        'dfs_radar' => [
            're' => '/DFS-RADAR-DETECTED/',
            'label' => 'radar detected', 'bad' => true,
        ],
        'dfs_new_channel' => [
            're' => '/DFS-NEW-CHANNEL/',
            'label' => 'moved off a radar channel', 'bad' => false,
        ],
        'dfs_cac_completed' => [
            're' => '/DFS-CAC-COMPLETED/',
            'label' => 'channel check finished', 'bad' => false,
        ],
    ];

    /** how many of them to keep per access point */
    public const EVENTS_KEEP = 100;

    /** and for how long — long enough to answer "has this been happening" */
    public const EVENTS_TTL = 30 * 86400;

    public function eventsKey(AccessPoint $ap): string
    {
        return 'syslog.events.'.$ap->getId();
    }

    /**
     * Does this line report something worth keeping, and as what?
     *
     * Pure, and it has to be: the trap here is that a line can quote the
     * pattern rather than report the thing. The reboot cron on the access
     * points greps for these very strings, and crond logs the whole command
     * text once a minute — so its own line matches every pattern below. The
     * job guards against that with grep -v crond and this does the same. It is
     * not theoretical: measuring without that guard reported 314 firmware
     * faults on ap-av-klwz on 2026-08-30, of which the real number was zero.
     */
    public static function classify(array $entry): ?string
    {
        $ident = (string) ($entry['ident'] ?? '');
        if ('crond' === $ident || 'cron' === $ident) {
            return null;
        }
        $text = (string) ($entry['text'] ?? '');
        if ('' === $text || str_contains($text, 'crond')) {
            return null;
        }
        foreach (self::EVENTS as $key => $event) {
            if (preg_match($event['re'], $text)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Fill the event store from the lines still in the ring.
     *
     * For the moment a detector is added or changed: up to KEEP lines per
     * access point are sitting there already, and some of them are the events
     * this is meant to keep. Without this the store starts empty and stays
     * that way until the next radar hit, which may be months.
     *
     * Idempotent by timestamp and text, so running it twice does not double
     * anything.
     */
    public function rescan(AccessPoint $ap): int
    {
        $kept = $this->events($ap);
        $seen = [];
        foreach ($kept as $event) {
            $seen[($event['ts'] ?? 0).'|'.($event['text'] ?? '')] = true;
        }
        $added = 0;
        foreach ($this->lines($ap) as $entry) {
            $key = self::classify($entry);
            if (null === $key) {
                continue;
            }
            $mark = ($entry['ts'] ?? 0).'|'.($entry['text'] ?? '');
            if (isset($seen[$mark])) {
                continue;
            }
            $seen[$mark] = true;
            $kept[] = [
                'event' => $key,
                'label' => self::EVENTS[$key]['label'],
                'bad' => self::EVENTS[$key]['bad'],
                'ts' => $entry['ts'],
                'ident' => $entry['ident'],
                'text' => $entry['text'],
            ];
            ++$added;
        }
        if ($added) {
            usort($kept, fn ($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
            $this->cacheFactory->addCacheItem($this->eventsKey($ap),
                array_slice($kept, 0, self::EVENTS_KEEP), self::EVENTS_TTL);
        }

        return $added;
    }

    /** The kept events of one access point, newest first. */
    public function events(AccessPoint $ap): array
    {
        $out = $this->cacheFactory->getCacheItemValue($this->eventsKey($ap));

        return is_array($out) ? $out : [];
    }

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

        $event = self::classify($entry);
        if (null !== $event) {
            $kept = $this->events($ap);
            array_unshift($kept, [
                'event' => $event,
                'label' => self::EVENTS[$event]['label'],
                'bad' => self::EVENTS[$event]['bad'],
                'ts' => $entry['ts'],
                'ident' => $entry['ident'],
                'text' => $entry['text'],
            ]);
            if (count($kept) > self::EVENTS_KEEP) {
                $kept = array_slice($kept, 0, self::EVENTS_KEEP);
            }
            $this->cacheFactory->addCacheItem($this->eventsKey($ap), $kept, self::EVENTS_TTL);
        }

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
    /**
     * The kept events of the whole fleet, newest first, with a tally.
     *
     * For the dashboard, which is where these belong: a firmware fault or a
     * radar hit is not a property of one access point you happen to be looking
     * at, it is something you want to see without going looking.
     */
    public function fleetEvents(int $limit = 12): array
    {
        $all = [];
        $counts = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\AccessPoint')
            ->findBy([], ['name' => 'ASC']) as $ap) {
            foreach ($this->events($ap) as $event) {
                $event['ap'] = $ap->getName();
                $all[] = $event;
                $key = $event['event'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        usort($all, fn ($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));

        return [
            'recent' => array_slice($all, 0, $limit),
            'counts' => $counts,
            'total' => count($all),
            'bad' => count(array_filter($all, fn ($e) => !empty($e['bad']))),
        ];
    }

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
