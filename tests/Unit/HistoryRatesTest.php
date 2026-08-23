<?php

namespace App\Tests\Unit;

use ApManBundle\Service\HistoryService;
use PHPUnit\Framework\TestCase;

/**
 * Counters into rates, and the three cases where there is no honest rate.
 */
class HistoryRatesTest extends TestCase
{
    private function service(): HistoryService
    {
        return (new \ReflectionClass(HistoryService::class))->newInstanceWithoutConstructor();
    }

    private function sample(int $ts, int $rx, int $tx): array
    {
        return ['ts' => $ts, 'rx' => $rx, 'tx' => $tx,
            'stations' => 3, 'utilization' => 40, 'noise' => -91, 'channel' => 36];
    }

    public function testAnOrdinaryIntervalBecomesBitsPerSecond(): void
    {
        $out = $this->service()->rates([
            $this->sample(1000, 0, 0),
            // 300 seconds, 375000 bytes: 3 000 000 bits over 300 s is 10 kbit/s
            $this->sample(1300, 375000, 37500),
        ]);

        $this->assertNull($out[0]['rx_bps'], 'the first sample has nothing to be a difference from');
        $this->assertSame(10000, $out[1]['rx_bps']);
        $this->assertSame(1000, $out[1]['tx_bps']);
        $this->assertSame(36, $out[1]['channel'], 'the rest of the sample comes through untouched');
    }

    public function testACounterThatWentBackwardsIsAGapAndNotANegativeRate(): void
    {
        $out = $this->service()->rates([
            $this->sample(1000, 900000000, 900000000),
            $this->sample(1300, 4000, 4000),
            $this->sample(1600, 379000, 41500),
        ]);

        $this->assertNull($out[1]['rx_bps'], 'the interface was rebuilt; how much it carried is unknowable');
        $this->assertSame(10000, $out[2]['rx_bps'], 'and the interval after it is ordinary again');
    }

    public function testALongGapIsNotSpreadOverItself(): void
    {
        $far = HistoryService::INTERVAL * 4 + 1;
        $out = $this->service()->rates([
            $this->sample(1000, 0, 0),
            $this->sample(1000 + $far, 375000000, 375000000),
        ]);

        $this->assertNull($out[1]['rx_bps'],
            'the bytes are real but saying they were spread evenly across an outage is an invention');
    }

    public function testAnIntervalRightAtTheEdgeIsStillDrawn(): void
    {
        $edge = HistoryService::INTERVAL * 4;
        $out = $this->service()->rates([
            $this->sample(1000, 0, 0),
            $this->sample(1000 + $edge, 1200 * $edge / 8, 0),
        ]);

        $this->assertSame(1200, $out[1]['rx_bps']);
    }

    public function testAnEmptySeriesIsAnEmptySeries(): void
    {
        $this->assertSame([], $this->service()->rates([]));
    }

    /**
     * The field is a fraction of 255, not a percentage. Reading it as one gave
     * ap-av-grwz radio0 a channel that was 123 % in use.
     */
    public function testChannelUtilisationIsScaledOutOfTwoHundredAndFiftyFive(): void
    {
        // measured together in one status message: utilization 58 alongside
        // time_busy 261478 of time 1191459, which is 21.9 %
        $this->assertSame(23, HistoryService::utilizationPercent(58));
        $this->assertSame(48, HistoryService::utilizationPercent(123));
        $this->assertSame(0, HistoryService::utilizationPercent(0));
        $this->assertSame(100, HistoryService::utilizationPercent(255));
        $this->assertSame(100, HistoryService::utilizationPercent(300),
            'a radio cannot be more than entirely busy');
        $this->assertNull(HistoryService::utilizationPercent(null));
    }
}
