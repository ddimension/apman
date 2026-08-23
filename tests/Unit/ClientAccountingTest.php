<?php

namespace App\Tests\Unit;

use ApManBundle\Service\HistoryService;
use PHPUnit\Framework\TestCase;

/**
 * Three ways to read a station's byte counters, and only one of them is a
 * subtraction.
 */
class ClientAccountingTest extends TestCase
{
    private const NOW = 1787484000;

    private function mark(string $if, int $rx, int $tx, int $age = 300): array
    {
        return ['if' => $if, 'rx' => $rx, 'tx' => $tx, 'ts' => self::NOW - $age];
    }

    public function testAStationThatStayedPutIsASubtraction(): void
    {
        $out = HistoryService::since($this->mark('wap-kc1', 1000, 500), 'wap-kc1', 4000, 1500, self::NOW);

        $this->assertSame('continued', $out['how']);
        $this->assertSame(3000, $out['rx']);
        $this->assertSame(1000, $out['tx']);
    }

    /**
     * The case that was throwing traffic away. A station on a new bss has
     * counters that began at zero when it got there, so the counter is the
     * answer rather than one end of a difference.
     */
    public function testAStationThatRoamedContributesItsWholeCounter(): void
    {
        $out = HistoryService::since($this->mark('wap-kc1', 900000, 800000), 'wap-kc2', 4000, 1500, self::NOW);

        $this->assertSame('restarted', $out['how']);
        $this->assertSame(4000, $out['rx']);
        $this->assertSame(1500, $out['tx']);
    }

    public function testAStationThatReassociatedOnTheSameBssDoesTheSame(): void
    {
        $out = HistoryService::since($this->mark('wap-kc1', 900000, 800000), 'wap-kc1', 4000, 1500, self::NOW);

        $this->assertSame('restarted', $out['how'], 'counters that went down mean it started again');
        $this->assertSame(4000, $out['rx']);
    }

    /**
     * A counter that may hold a day of traffic is not today's traffic.
     */
    public function testAMarkTooOldToReasonFromContributesNothing(): void
    {
        $stale = $this->mark('wap-kc1', 1000, 500, HistoryService::INTERVAL * 4 + 1);
        $out = HistoryService::since($stale, 'wap-kc1', 900000000, 900000000, self::NOW);

        $this->assertSame('unknown', $out['how']);
        $this->assertSame(0, $out['rx']);
        $this->assertSame(0, $out['tx']);
    }

    public function testTheFirstSightingOfAStationContributesNothing(): void
    {
        $out = HistoryService::since(null, 'wap-kc1', 900000, 900000, self::NOW);

        $this->assertSame('unknown', $out['how']);
        $this->assertSame(0, $out['rx']);
    }

    public function testAnIdleStationContributesZeroWithoutBeingCalledRestarted(): void
    {
        $out = HistoryService::since($this->mark('wap-kc1', 4000, 1500), 'wap-kc1', 4000, 1500, self::NOW);

        $this->assertSame('continued', $out['how'], 'equal counters are not a counter that went down');
        $this->assertSame(0, $out['rx']);
    }
}
