<?php

namespace App\Tests\Unit;

use ApManBundle\Service\ChannelPlanService;
use PHPUnit\Framework\TestCase;

/**
 * The parsing and the block arithmetic, on text captured from the fleet.
 *
 * The two things worth testing are the two things that were wrong: a block that
 * is not complete on this radio must not be offered, and the channel numbers
 * above 144 must not fall through a grid anchored at 36.
 */
class ChannelPlanTest extends TestCase
{
    /** ap-av-attic wap-kc1, 2026-08-22 — 19 channels, 144 and everything above missing */
    private const ATTIC_5G = <<<'TXT'
  5.180 GHz (Band: 5 GHz, Channel 36) [NO_HT40-, INDOOR_ONLY]
  5.200 GHz (Band: 5 GHz, Channel 40) [INDOOR_ONLY]
  5.220 GHz (Band: 5 GHz, Channel 44) [INDOOR_ONLY]
  5.240 GHz (Band: 5 GHz, Channel 48) [INDOOR_ONLY]
  5.260 GHz (Band: 5 GHz, Channel 52) [INDOOR_ONLY, RADAR_DETECTION]
  5.280 GHz (Band: 5 GHz, Channel 56) [INDOOR_ONLY, RADAR_DETECTION]
  5.300 GHz (Band: 5 GHz, Channel 60) [INDOOR_ONLY, RADAR_DETECTION]
  5.320 GHz (Band: 5 GHz, Channel 64) [NO_HT40+, INDOOR_ONLY, RADAR_DETECTION]
  5.500 GHz (Band: 5 GHz, Channel 100) [NO_HT40-, RADAR_DETECTION]
* 5.520 GHz (Band: 5 GHz, Channel 104) [RADAR_DETECTION]
  5.540 GHz (Band: 5 GHz, Channel 108) [RADAR_DETECTION]
  5.560 GHz (Band: 5 GHz, Channel 112) [RADAR_DETECTION]
  5.580 GHz (Band: 5 GHz, Channel 116) [RADAR_DETECTION]
  5.600 GHz (Band: 5 GHz, Channel 120) [RADAR_DETECTION]
  5.620 GHz (Band: 5 GHz, Channel 124) [RADAR_DETECTION]
  5.640 GHz (Band: 5 GHz, Channel 128) [RADAR_DETECTION]
  5.660 GHz (Band: 5 GHz, Channel 132) [RADAR_DETECTION]
  5.680 GHz (Band: 5 GHz, Channel 136) [NO_HT40+, NO_80MHZ, RADAR_DETECTION]
  5.700 GHz (Band: 5 GHz, Channel 140) [NO_HT40+, RADAR_DETECTION]
TXT;

    /** the upper end, where a grid anchored at 36 goes wrong */
    private const UNII3 = <<<'TXT'
  5.745 GHz (Band: 5 GHz, Channel 149)
  5.765 GHz (Band: 5 GHz, Channel 153)
  5.785 GHz (Band: 5 GHz, Channel 157)
  5.805 GHz (Band: 5 GHz, Channel 161)
  5.825 GHz (Band: 5 GHz, Channel 165)
TXT;

    /** ap-av-attic wap-knet2, all thirteen */
    private const TWO_FOUR = <<<'TXT'
* 2.412 GHz (Band: 2.4 GHz, Channel 1) [NO_HT40-, NO_80MHZ, NO_160MHZ]
  2.417 GHz (Band: 2.4 GHz, Channel 2) [NO_HT40-, NO_80MHZ, NO_160MHZ]
  2.422 GHz (Band: 2.4 GHz, Channel 3) [NO_HT40-, NO_80MHZ, NO_160MHZ]
  2.427 GHz (Band: 2.4 GHz, Channel 4) [NO_HT40-, NO_80MHZ, NO_160MHZ]
  2.432 GHz (Band: 2.4 GHz, Channel 5) [NO_80MHZ, NO_160MHZ]
  2.437 GHz (Band: 2.4 GHz, Channel 6) [NO_80MHZ, NO_160MHZ]
  2.442 GHz (Band: 2.4 GHz, Channel 7) [NO_80MHZ, NO_160MHZ]
  2.447 GHz (Band: 2.4 GHz, Channel 8) [NO_80MHZ, NO_160MHZ]
  2.452 GHz (Band: 2.4 GHz, Channel 9) [NO_80MHZ, NO_160MHZ]
  2.457 GHz (Band: 2.4 GHz, Channel 10) [NO_HT40+, NO_80MHZ, NO_160MHZ]
  2.462 GHz (Band: 2.4 GHz, Channel 11) [NO_HT40+, NO_80MHZ, NO_160MHZ]
  2.467 GHz (Band: 2.4 GHz, Channel 12) [NO_HT40+, NO_80MHZ, NO_160MHZ]
  2.472 GHz (Band: 2.4 GHz, Channel 13) [NO_HT40+, NO_80MHZ, NO_160MHZ]
TXT;

    private const REG = <<<'TXT'
global
country DE: DFS-ETSI
	(2400 - 2483 @ 40), (N/A, 20), (N/A)
	(5150 - 5250 @ 80), (N/A, 23), (N/A), NO-OUTDOOR, AUTO-BW
	(5250 - 5350 @ 80), (N/A, 20), (0 ms), NO-OUTDOOR, DFS, AUTO-BW
	(5470 - 5725 @ 160), (N/A, 26), (0 ms), DFS

phy#1 (self-managed)
country DE: DFS-ETSI
	(5170 - 5250 @ 80), (N/A, 23), (N/A), NO-OUTDOOR, AUTO-BW
	(5490 - 5590 @ 80), (N/A, 30), (0 ms), DFS, AUTO-BW
	(5590 - 5650 @ 40), (N/A, 30), (600000 ms), DFS, AUTO-BW
TXT;

    private function planner(): ChannelPlanService
    {
        return new ChannelPlanService(
            $this->createMock(\ApManBundle\Service\ApUbusService::class),
            $this->createMock(\ApManBundle\Factory\CacheFactory::class),
            new \Psr\Log\NullLogger(),
        );
    }

    public function testReadsChannelsFlagsAndTheActiveOne(): void
    {
        $ch = $this->planner()->parseFreqlist(self::ATTIC_5G);
        $this->assertCount(19, $ch);
        $this->assertSame(5520, $ch[104]['mhz']);
        $this->assertTrue($ch[104]['active']);
        $this->assertFalse($ch[100]['active']);
        $this->assertTrue($ch[104]['dfs']);
        $this->assertTrue($ch[36]['indoor']);
        $this->assertFalse($ch[100]['indoor']);
        $this->assertSame(['no_ht40-', 'indoor_only'], $ch[36]['flags']);
    }

    /**
     * 140 is listed and usable at 20 MHz, and cannot be anything wider, because
     * 144 is missing from this radio and every wider block containing 140 needs
     * it. This is the case a free text field gets wrong.
     */
    public function testAWidthNeedsEveryChannelOfItsBlock(): void
    {
        $p = $this->planner();
        $w = $p->widths($p->parseFreqlist(self::ATTIC_5G));
        $this->assertContains(140, $w[20]['ok']);
        $this->assertNotContains(140, $w[40]['ok']);
        $this->assertNotContains(140, $w[80]['ok']);
        $this->assertStringContainsString('not complete', $w[80]['no'][140]);

        // and the block that is complete is offered
        $this->assertContains(116, $w[80]['ok']);
        $this->assertContains(128, $w[80]['ok']);
    }

    public function testTheRadioSayingNoBeatsTheBlockTable(): void
    {
        $p = $this->planner();
        $w = $p->widths($p->parseFreqlist(self::ATTIC_5G));
        // 132-136-140-144 is a real block, but 136 carries NO_80MHZ
        $this->assertArrayHasKey(136, $w[80]['no']);
        $this->assertStringContainsString('no 80 MHz', $w[80]['no'][136]);
        // 64 may only extend downwards, and 60-64 is its pair, so it stays
        $this->assertContains(64, $w[40]['ok']);
        // 36 may only extend upwards, and 36-40 is its pair, so it stays too
        $this->assertContains(36, $w[40]['ok']);
    }

    /**
     * 5745 is not on a 20 MHz grid anchored at 5180. A version that computed
     * the block from the frequency dropped all of U-NII-3 out of every width
     * above 20 without saying so.
     */
    public function testTheChannelsAbove144DoNotFallThroughTheGrid(): void
    {
        $p = $this->planner();
        $w = $p->widths($p->parseFreqlist(self::UNII3));
        $this->assertContains(149, $w[80]['ok']);
        $this->assertContains(161, $w[80]['ok']);
        $this->assertContains(149, $w[40]['ok']);
        // 165 belongs to 165-177, and 169 upwards are not here
        $this->assertNotContains(165, $w[80]['ok']);
    }

    /**
     * 2.4 GHz pairs by flag, not by grid: channel 1 may only extend upwards and
     * channel 13 only downwards, and every one of the thirteen has a partner.
     * Nothing here is wider than 40, whatever a block table would say.
     */
    public function testTwoPointFourPairsByFlagAndHasNothingWiderThanForty(): void
    {
        $p = $this->planner();
        $w = $p->widths($p->parseFreqlist(self::TWO_FOUR));
        $this->assertSame([20, 40], array_keys($w));
        $this->assertCount(13, $w[40]['ok']);
        $this->assertSame([], $w[40]['no']);
    }

    /** A channel with no partner on either side is 20 MHz and says so. */
    public function testAChannelWithNoPartnerIsTwentyOnly(): void
    {
        $p = $this->planner();
        $w = $p->widths($p->parseFreqlist(
            "  2.412 GHz (Band: 2.4 GHz, Channel 1) [NO_HT40-]\n"));
        $this->assertSame([1], $w[20]['ok']);
        $this->assertSame([], $w[40]['ok']);
        $this->assertStringContainsString('no neighbour', $w[40]['no'][1]);
    }

    public function testTheSelfManagedPhysRulesWinOverTheGlobalOnes(): void
    {
        $p = $this->planner();
        $global = $p->parseReg(self::REG, null);
        $this->assertSame('global', $global['from']);
        $this->assertCount(4, $global['rules']);

        $phy1 = $p->parseReg(self::REG, 'phy1');
        $this->assertSame('phy1', $phy1['from']);
        $this->assertSame('DE', $phy1['country']);
        $this->assertCount(3, $phy1['rules']);
        $this->assertTrue($phy1['rules'][1]['dfs']);
        $this->assertTrue($phy1['rules'][1]['auto_bw']);
    }

    /**
     * 5150–5350 is two rules of 80 MHz each. Joined they carry 160 MHz of
     * spectrum and still not a 160 MHz channel, and a planner that only checked
     * whether the channels exist would offer one.
     */
    public function testRegulationCapsAWidthTheChannelsWouldAllow(): void
    {
        $p = $this->planner();
        $ch = $p->parseFreqlist(self::ATTIC_5G);
        $rules = $p->parseReg(self::REG, null)['rules'];
        $w = $p->widths($ch, $rules);

        $this->assertContains(36, $w[80]['ok']);
        $this->assertArrayHasKey(36, $w[160]['no']);
        $this->assertStringContainsString('at most 80 MHz', $w[160]['no'][36]);
    }
}
