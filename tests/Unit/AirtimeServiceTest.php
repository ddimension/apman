<?php

namespace App\Tests\Unit;

use ApManBundle\Service\AirtimeService;
use PHPUnit\Framework\TestCase;

/**
 * The two parts of airtime handling that can be wrong without an access point.
 */
class AirtimeServiceTest extends TestCase
{
    private function service(): AirtimeService
    {
        return (new \ReflectionClass(AirtimeService::class))->newInstanceWithoutConstructor();
    }

    /**
     * Straight from ap-av-grwz, `iw dev wap-knet0 station dump`. Note the shape
     * of the line: one space after the colon where every neighbouring line has
     * a tab, which is exactly the sort of thing a regex gets wrong.
     */
    public function testTheWeightIsReadOutOfARealStationDump(): void
    {
        $dump = "Station 48:55:19:18:55:ae (on wap-knet0)\n"
            ."\tauthorized:\tyes\n"
            ."\tinactive time:\t1060 ms\n"
            ."\tsignal:  \t-68 [-69, -74] dBm\n"
            ."\tlast ack signal:-64 dBm\n"
            ."\tairtime weight: 700\n"
            ."\texpected throughput:\t35.687Mbps\n"
            ."\n"
            ."Station ec:fa:bc:6e:fd:31 (on wap-knet0)\n"
            ."\ttx retries:\t35\n"
            ."\tairtime weight: 256\n"
            ."\tconnected time:\t33 seconds\n";

        $this->assertSame([
            '48:55:19:18:55:ae' => 700,
            'ec:fa:bc:6e:fd:31' => 256,
        ], $this->service()->parseDump($dump));
    }

    public function testAStationWithNoWeightLineIsSimplyAbsent(): void
    {
        $dump = "Station 48:55:19:18:55:ae (on wap-knet0)\n\tauthorized:\tyes\n";

        $this->assertSame([], $this->service()->parseDump($dump));
    }

    public function testNoAnswerIsNotAnEmptyList(): void
    {
        $this->assertNull($this->service()->parseDump(''),
            'an unreadable dump has to be told apart from a bss with no stations');
    }

    /**
     * Zero is the trap. hostapd ignores it once the policy is on — measured —
     * so nothing here may ever produce it.
     */
    public function testTheWeightIsHeldInsideWhatTheDriverAccepts(): void
    {
        $s = $this->service();

        $this->assertSame(AirtimeService::MIN_WEIGHT, $s->clamp(0),
            'zero is ignored by hostapd rather than meaning normal, so it must never be sent');
        $this->assertSame(AirtimeService::MIN_WEIGHT, $s->clamp(-40));
        $this->assertSame(AirtimeService::MAX_WEIGHT, $s->clamp(999999));
        $this->assertSame(700, $s->clamp(700));
        $this->assertSame(AirtimeService::DEFAULT_WEIGHT, $s->clamp(AirtimeService::DEFAULT_WEIGHT));
    }
}
