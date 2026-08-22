<?php

namespace App\Tests\Unit;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;
use ApManBundle\Service\DfsService;
use PHPUnit\Framework\TestCase;

/**
 * A channel availability check with a beginning, an expectation and a deadline.
 *
 * It used to be a boolean, so a radio stuck in it and a radio two seconds into
 * one looked the same. What makes the difference measurable is that hostapd
 * names the expectation itself, per radio — 600 seconds on ap-outdoor channel
 * 116 and 60 on ap-outdoor2 on the same channel, because their phys carry
 * different regulatory rules.
 */
class DfsServiceTest extends TestCase
{
    private array $store = [];

    private function service(): DfsService
    {
        $cache = $this->createMock(\ApManBundle\Factory\CacheFactory::class);
        $cache->method('getCacheItemValue')->willReturnCallback(
            fn ($key) => $this->store[$key] ?? null);
        $cache->method('addCacheItem')->willReturnCallback(
            function ($key, $data, $ttl = null) { $this->store[$key] = $data; });

        return new DfsService(new \Psr\Log\NullLogger(), $cache,
            $this->createMock(\ApManBundle\Service\ApUbusService::class));
    }

    private function device(): Device
    {
        $ap = new AccessPoint();
        $ap->setName('ap-outdoor');
        $radio = new Radio();
        $radio->setName('radio1');
        $radio->setAccessPoint($ap);
        $device = new Device();
        $device->setRadio($radio);

        return $device;
    }

    private function status(bool $active, int $expected, ?int $left, int $freq = 5580, string $state = 'ENABLED'): array
    {
        return [
            'freq' => $freq, 'channel' => 116, 'status' => $state,
            'dfs' => ['cac_active' => $active, 'cac_seconds' => $expected, 'cac_seconds_left' => $left],
        ];
    }

    /** Backdate a running episode, which is the only way to test a deadline. */
    private function backdate(Radio $radio, int $seconds): void
    {
        $key = 'dfs.radio.'.$radio->getId();
        $this->store[$key]['started'] -= $seconds;
    }

    public function testARadioThatIsNotCheckingHasNothingToSay(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(false, 600, 0));

        $this->assertNull($dfs->state($device->getRadio()));
        $this->assertFalse($dfs->isChecking($device->getRadio()));
    }

    public function testAStartedCheckKnowsWhatItIsWaitingFor(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(true, 600, 598));

        $state = $dfs->state($device->getRadio());
        $this->assertTrue($state['active']);
        $this->assertSame(600, $state['expected']);
        $this->assertSame(598, $state['left']);
        $this->assertFalse($state['overdue']);
        // ten percent of the expectation, because a minute of slack in ten is noise
        $this->assertSame(660, $state['deadline']);
        $this->assertTrue($dfs->isChecking($device->getRadio()));
    }

    /** The short check gets the flat grace, not a tenth of a minute. */
    public function testTheShortCheckGetsTheFlatGrace(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(true, 60, 58));

        $this->assertSame(75, $dfs->state($device->getRadio())['deadline']);
    }

    public function testACheckPastItsDeadlineSaysSo(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(true, 60, 58));
        $this->backdate($device->getRadio(), 200);
        $dfs->observe($device, $this->status(true, 60, 0));

        $state = $dfs->state($device->getRadio());
        $this->assertTrue($state['overdue']);
        $this->assertGreaterThan(60, $state['elapsed']);
    }

    public function testACheckThatFinishesOnItsOwnChannelCompleted(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(true, 60, 58));
        $dfs->observe($device, $this->status(false, 60, 0));

        $state = $dfs->state($device->getRadio());
        $this->assertFalse($state['active']);
        $this->assertSame('completed', $state['outcome']);
    }

    /** Finishing on another frequency is radar having moved it, not success. */
    public function testACheckThatEndsElsewhereWasMovedByRadar(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(true, 60, 58, 5580));
        $dfs->observe($device, $this->status(false, 60, 0, 5500));

        $this->assertSame('moved', $dfs->state($device->getRadio())['outcome']);
    }

    /** Stopping without the radio carrying traffic is not success either. */
    public function testACheckThatStopsWithTheRadioDisabled(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(true, 60, 58));
        $dfs->observe($device, $this->status(false, 60, 0, 5580, 'DISABLED'));

        $this->assertSame('stopped', $dfs->state($device->getRadio())['outcome']);
    }

    /** A check on a new frequency is a new check, not the old one overrunning. */
    public function testACheckOnAnotherFrequencyStartsAgain(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $dfs->observe($device, $this->status(true, 60, 58, 5580));
        $this->backdate($device->getRadio(), 500);
        $dfs->observe($device, $this->status(true, 600, 599, 5500));

        $state = $dfs->state($device->getRadio());
        $this->assertFalse($state['overdue']);
        $this->assertSame(5500, $state['freq']);
        $this->assertSame(600, $state['expected']);
    }

    /**
     * The reason this exists at all: a provisioning run must not call a radio
     * that is lawfully listening a radio that failed to come up.
     */
    public function testTheWaitBudgetFollowsTheCheck(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $radio = $device->getRadio();

        $this->assertSame(40, $dfs->waitBudget($radio, 40), 'nothing known, no extra wait');

        $dfs->observe($device, $this->status(true, 600, 599));
        $this->assertGreaterThan(600, $dfs->waitBudget($radio, 40));
    }

    /**
     * The case that matters and cannot be watched: the check that runs while
     * the bss is starting. ubus has no hostapd object to ask then, so the wait
     * has to come from what the radio said last time it was up.
     */
    public function testTheExpectationSurvivesTheBssGoingAway(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $radio = $device->getRadio();

        $dfs->observe($device, $this->status(false, 600, 0, 5580));
        $this->assertSame(600, $dfs->expectFor($radio));
        // 600 plus a tenth, which is what a run waiting for this radio gets
        $this->assertSame(660, $dfs->waitBudget($radio, 40));
    }

    /**
     * Escaping onto the channel it is already checking would start the same
     * check again — ten minutes traded for ten minutes.
     */
    public function testItRefusesToEscapeOntoTheChannelItIsCheckingOn(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $radio = $device->getRadio();
        $radio->setConfigChannel('116');
        $dfs->observe($device, $this->status(true, 600, 590, 5580));

        $out = $dfs->escape($radio);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('would start the same check again', $out['error']);
    }

    /** A radio configured for auto has nothing to be put back to. */
    public function testARadioOnAutoHasNowhereToGoBackTo(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $radio = $device->getRadio();
        $radio->setConfigChannel('auto');
        $dfs->observe($device, $this->status(true, 600, 590, 5580));

        $out = $dfs->escape($radio);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('nothing to put back', $out['error']);
    }

    /** A radio that needs no check gets no extra wait, whatever hostapd names. */
    public function testAChannelOutsideTheDfsRangesNeedsNoWait(): void
    {
        $device = $this->device();
        $dfs = $this->service();
        $radio = $device->getRadio();

        $dfs->observe($device, $this->status(false, 60, 0, 5180));
        $this->assertSame(0, $dfs->expectFor($radio));
        $this->assertSame(40, $dfs->waitBudget($radio, 40));
    }
}
