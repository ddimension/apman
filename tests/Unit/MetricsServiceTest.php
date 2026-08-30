<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Service\MetricsService;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of the measurement that can be wrong without anyone noticing.
 *
 * classify() decides how many rows the ranking has. Leave the interface name in
 * and every bss is its own class: the table grows to a hundred rows, each one a
 * fiftieth of the truth, and the ranking it exists for stops working. Fold too
 * much away and unrelated work lands in one row.
 *
 * bucketFor() decides what a lag looks like. Its boundaries are the difference
 * between "the loop is fine" and "the loop was blocked", and an off-by-one at
 * the edge would put a healthy tick in the alarming bucket for ever.
 */
class MetricsServiceTest extends TestCase
{
    private function classify(string $topic): string
    {
        return MetricsService::classify(explode('/', $topic));
    }

    public function testInterfaceNamesAreFoldedAway(): void
    {
        // three interfaces, one class: this is the whole point
        $this->assertSame('notifications/hostapd/probe',
            $this->classify('apman/ap/ap-av-attic/notifications/hostapd/wap-kc1/probe'));
        $this->assertSame('notifications/hostapd/probe',
            $this->classify('apman/ap/ap-outdoor/notifications/hostapd/wap-fl2/probe'));
        $this->assertSame('notifications/hostapd/probe',
            $this->classify('apman/ap/ap-hv-grwz/notifications/hostapd/kinfra1/probe'));
    }

    public function testTheAccessPointNameIsNotAClass(): void
    {
        $a = $this->classify('apman/ap/ap-av-attic/properties/system/info');
        $b = $this->classify('apman/ap/ap-outdoor2/properties/system/info');
        $this->assertSame($a, $b, 'seven access points must not make seven classes');
        $this->assertSame('properties/system/info', $a);
    }

    public function testDistinctWorkStaysDistinct(): void
    {
        $seen = [];
        foreach ([
            'apman/ap/x/properties/session/create',
            'apman/ap/x/properties/radius',
            'apman/ap/x/command_result',
            'apman/ap/x/command_result/bulk',
            'apman/ap/x/booted',
            'apman/ap/x/online',
            'apman/ap/x/device/hostapd/wap-kc1/status',
            'apman/ap/x/notifications/syslog',
        ] as $t) {
            $seen[] = $this->classify($t);
        }
        $this->assertSame(count($seen), count(array_unique($seen)),
            'folding must not merge things that are different work: '.implode(', ', $seen));
    }

    public function testATopicThatIsNotAboutAnAccessPointStillGetsAName(): void
    {
        $this->assertSame('(none)', MetricsService::classify(['apman']));
        $this->assertSame('(bare)', $this->classify('apman/ap/ap-av-attic'));
    }

    public function testTheBucketBoundariesAreWhereTheyClaimToBe(): void
    {
        $this->assertSame('<10ms', MetricsService::bucketFor(0.0));
        $this->assertSame('<10ms', MetricsService::bucketFor(9.999));
        // exactly at the bound belongs to the worse bucket, or a busy loop
        // would report itself as idle
        $this->assertSame('<50ms', MetricsService::bucketFor(10.0));
        $this->assertSame('<1000ms', MetricsService::bucketFor(999.9));
        $this->assertSame('<5000ms', MetricsService::bucketFor(1000.0));
        $this->assertSame('>=5s', MetricsService::bucketFor(5000.0));
        $this->assertSame('>=5s', MetricsService::bucketFor(120000.0));
    }
}
