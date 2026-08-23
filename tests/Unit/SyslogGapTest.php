<?php

namespace App\Tests\Unit;

use ApManBundle\Service\SyslogService;
use PHPUnit\Framework\TestCase;

/**
 * The gap between two log lines, and the three things that look like one.
 *
 * logd numbers every record it writes, so two consecutive lines whose numbers
 * are not consecutive say how many went by in between. Most of those are the
 * agent's filter working — but only if the number means what it appears to,
 * and there are three cases where it does not.
 */
class SyslogGapTest extends TestCase
{
    private function service(): SyslogService
    {
        return (new \ReflectionClass(SyslogService::class))->newInstanceWithoutConstructor();
    }

    public function testConsecutiveNumbersAreNoGap(): void
    {
        $this->assertSame(0, $this->service()->missedBefore(['id' => 10791, 'ts' => time()], 10792));
    }

    /**
     * The measurement this was built from: 10791 arrived, 10792 was dropped by
     * the filter, 10793 arrived.
     */
    public function testASkippedNumberIsCounted(): void
    {
        $this->assertSame(1, $this->service()->missedBefore(['id' => 10791, 'ts' => time()], 10793));
        $this->assertSame(11, $this->service()->missedBefore(['id' => 100, 'ts' => time()], 112));
    }

    public function testTheFirstLineHasNothingToBeAGapFrom(): void
    {
        $this->assertNull($this->service()->missedBefore(null, 10791));
        $this->assertNull($this->service()->missedBefore(['ts' => time()], 10791),
            'a mark without a number is no mark');
    }

    /**
     * logd was restarted and began counting again. Four billion records did
     * not happen.
     */
    public function testANumberThatWentBackwardsIsNotAGap(): void
    {
        $this->assertNull($this->service()->missedBefore(['id' => 10791, 'ts' => time()], 5));
        $this->assertNull($this->service()->missedBefore(['id' => 10791, 'ts' => time()], 10791),
            'the same number twice is a repeat, not a gap');
    }

    /**
     * An access point that was away for a day did not miss the day's
     * difference in records — it missed the day, and saying otherwise puts a
     * number on something nobody measured.
     */
    public function testAMarkTooOldToReasonFromSaysNothing(): void
    {
        $this->assertNull($this->service()->missedBefore(['id' => 100, 'ts' => time() - 7200], 5000));
    }

    /**
     * The filter on the page. syslog counts down — 0 is an emergency and 7 is
     * debug — so "warning and worse" keeps everything at or below 4.
     */
    public function testTheLevelFilterKeepsTheWorseOnes(): void
    {
        $s = $this->service();
        $error = ['level' => 3, 'ident' => 'hostapd', 'text' => 'Interface initialization failed'];
        $info = ['level' => 6, 'ident' => 'netifd', 'text' => 'link is up'];

        $this->assertTrue($s->matches($error, ['level' => 4]));
        $this->assertFalse($s->matches($info, ['level' => 4]));
        $this->assertTrue($s->matches($info, ['level' => '']), 'an empty filter keeps everything');
        $this->assertTrue($s->matches($info, []));
    }

    public function testTheIdentAndTextFiltersAreExactAndLooseRespectively(): void
    {
        $s = $this->service();
        $line = ['level' => 3, 'ident' => 'hostapd', 'text' => 'Interface initialization failed'];

        $this->assertTrue($s->matches($line, ['ident' => 'hostapd']));
        $this->assertFalse($s->matches($line, ['ident' => 'hostap']), 'an ident is matched whole');
        $this->assertTrue($s->matches($line, ['text' => 'INITIALIZATION']), 'text is not case sensitive');
        $this->assertFalse($s->matches($line, ['text' => 'radar']));
    }

    /**
     * A line with no level at all must not be filtered out by a level filter:
     * unknown is not the same as fine.
     */
    public function testALineWithoutALevelSurvivesALevelFilter(): void
    {
        $this->assertTrue($this->service()->matches(['text' => 'no level here'], ['level' => 0]));
    }
}
