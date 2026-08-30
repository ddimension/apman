<?php

namespace ApManBundle\Service;

use ApManBundle\Factory\CacheFactory;

/**
 * What the controller can say about its own working.
 *
 * The subscriber is one long running process with one event loop, and until
 * now the only way to ask how it was doing was to read its log — which says
 * nothing at all while everything works, and on 2026-08-30 said nothing for
 * three and a half hours while it was in fact handling two thousand events an
 * hour. Numbers do not have that failure mode.
 *
 * Everything here is measured in the subscriber and written to the cache as a
 * snapshot, because the web request that renders it is a different process and
 * cannot see the daemon's memory. The snapshot carries its own window, so a
 * page can turn counts into rates without guessing when they were taken.
 *
 * The two measurements worth having are not the obvious ones:
 *
 *   - loop lag. A periodic timer knows when it should have run. Everything
 *     that blocks the loop — a synchronous ubus call, a slow query, a redis
 *     wait — shows up here and nowhere else. Both crashes of 2026-08-30 were
 *     preceded by exactly this and nobody could see it.
 *   - time per message class, not just count. A class that is 2% of the
 *     messages and 60% of the time is the one to look at, and the ranking is
 *     invisible in a log.
 */
class MetricsService
{
    /** How often the subscriber writes its snapshot. */
    public const FLUSH_INTERVAL = 10;
    /** How often the lag probe runs. Its period is also its resolution. */
    public const LAG_INTERVAL = 1.0;
    /** Snapshots kept, so a page can draw ten minutes of history. */
    public const SERIES_KEEP = 60;
    private const KEY = 'metrics.subscriber';
    /** Long enough that a stopped daemon still shows its last state. */
    private const TTL = 3600;

    /** @var array<string,array{n:int,ms:float,max:float,err:int}> */
    private array $classes = [];
    /** @var array<string,int> */
    private array $events = [];
    private float $started;
    private float $windowFrom;
    private float $lagMax = 0.0;
    private float $lagSum = 0.0;
    private int $lagN = 0;
    /** Lag buckets in milliseconds, upper bounds. The last one is "worse". */
    private array $lagBuckets = [];
    private bool $active = false;

    public function __construct(private readonly CacheFactory $cacheFactory)
    {
        $this->started = microtime(true);
        $this->windowFrom = $this->started;
    }

    /**
     * Called by the subscriber on itself. Outside it nothing is recorded, so a
     * web request does not write daemon metrics.
     */
    public function activate(): void
    {
        $this->active = true;
        $this->started = microtime(true);
        $this->windowFrom = $this->started;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * One handled message: which class, how long, and whether it threw.
     */
    public function message(string $class, float $seconds, bool $failed = false): void
    {
        if (!$this->active) {
            return;
        }
        $ms = $seconds * 1000;
        if (!isset($this->classes[$class])) {
            $this->classes[$class] = ['n' => 0, 'ms' => 0.0, 'max' => 0.0, 'err' => 0];
        }
        ++$this->classes[$class]['n'];
        $this->classes[$class]['ms'] += $ms;
        if ($ms > $this->classes[$class]['max']) {
            $this->classes[$class]['max'] = $ms;
        }
        if ($failed) {
            ++$this->classes[$class]['err'];
        }
    }

    /**
     * Something worth counting that is not a message: a reopened entity
     * manager, a refused synchronous call, a reconnect. Rare by nature, which
     * is why a counter beats a log line — the log line is gone by the time
     * somebody asks.
     */
    public function bump(string $event, int $by = 1): void
    {
        if (!$this->active) {
            return;
        }
        $this->events[$event] = ($this->events[$event] ?? 0) + $by;
    }

    /**
     * How late a timer that was due now actually ran.
     */
    public function lag(float $seconds): void
    {
        if (!$this->active) {
            return;
        }
        $ms = max(0.0, $seconds * 1000);
        $this->lagSum += $ms;
        ++$this->lagN;
        if ($ms > $this->lagMax) {
            $this->lagMax = $ms;
        }
        $b = self::bucketFor($ms);
        $this->lagBuckets[$b] = ($this->lagBuckets[$b] ?? 0) + 1;
    }

    /**
     * Which bucket a lag belongs in.
     *
     * Under 10 ms is a loop doing its job; a second means it was blocked, and
     * anything past that is the thing that used to end the process. The
     * boundaries are wide on purpose: this is for spotting a shape, not for
     * measuring a scheduler.
     */
    public static function bucketFor(float $ms): string
    {
        foreach ([10, 50, 250, 1000, 5000] as $bound) {
            if ($ms < $bound) {
                return '<'.$bound.'ms';
            }
        }

        return '>=5s';
    }

    /**
     * The topic, reduced to something there are few enough of to rank.
     *
     * The interface and the access point name come out — they are what makes
     * every topic unique, and a table with one row per bss says nothing about
     * where the time goes.
     */
    public static function classify(array $tp): string
    {
        if ('ap' !== ($tp[1] ?? null)) {
            return $tp[1] ?? '(none)';
        }
        $parts = array_slice($tp, 3);
        if (!$parts) {
            return '(bare)';
        }
        $out = [];
        foreach ($parts as $i => $p) {
            // an interface name never carries meaning for a ranking, and there
            // are dozens of them; the position it sits in does
            if ($i > 0 && preg_match('/^(wap-|wlan|phy|radio|kinfra)/', $p)) {
                continue;
            }
            $out[] = $p;
        }

        return implode('/', $out);
    }

    /**
     * Write the snapshot and start a new window.
     */
    public function flush(): void
    {
        if (!$this->active) {
            return;
        }
        $now = microtime(true);
        $snapshot = [
            'ts' => $now,
            'started' => $this->started,
            'window' => max(0.001, $now - $this->windowFrom),
            'classes' => $this->classes,
            'events' => $this->events,
            'lag' => [
                'max' => $this->lagMax,
                'avg' => $this->lagN ? $this->lagSum / $this->lagN : 0.0,
                'n' => $this->lagN,
                'buckets' => $this->lagBuckets,
            ],
            'memory' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'pid' => getmypid(),
        ];
        $this->cacheFactory->addCacheItem(self::KEY, $snapshot, self::TTL);

        $series = $this->cacheFactory->getCacheItemValue(self::KEY.'.series');
        $series = is_array($series) ? $series : [];
        $series[] = [
            'ts' => $now,
            'window' => $snapshot['window'],
            'n' => array_sum(array_column($this->classes, 'n')),
            'ms' => array_sum(array_column($this->classes, 'ms')),
            'lag_max' => $this->lagMax,
            'memory' => $snapshot['memory'],
        ];
        if (count($series) > self::SERIES_KEEP) {
            $series = array_slice($series, -self::SERIES_KEEP);
        }
        $this->cacheFactory->addCacheItem(self::KEY.'.series', $series, self::TTL);

        // A new window. The counters are per window on purpose: a rate over
        // the whole life of the process hides what is happening now, and
        // "since start" is what the series adds up to anyway.
        $this->classes = [];
        $this->events = [];
        $this->lagMax = 0.0;
        $this->lagSum = 0.0;
        $this->lagN = 0;
        $this->lagBuckets = [];
        $this->windowFrom = $now;
    }

    /** The last snapshot, or null when no subscriber has written one. */
    public function snapshot(): ?array
    {
        $s = $this->cacheFactory->getCacheItemValue(self::KEY);

        return is_array($s) ? $s : null;
    }

    /** @return array<int,array> oldest first */
    public function series(): array
    {
        $s = $this->cacheFactory->getCacheItemValue(self::KEY.'.series');

        return is_array($s) ? $s : [];
    }
}
