<?php

namespace App\Tests\Unit;

use ApManBundle\Service\ProvisioningService;
use PHPUnit\Framework\TestCase;

/**
 * What a live provisioning run is allowed to claim.
 *
 * The claim is that a bss can be added to a running radio, or taken off it,
 * without the others noticing — and the only evidence that carries is the
 * interface index. A bss that was torn down and built again comes back under
 * the same name, in the same lists, with the same address; the kernel gives it
 * a new index. So "kept" has to mean "same index", never "still present".
 */
class LiveProvisioningTest extends TestCase
{
    private function service(): ProvisioningService
    {
        // compareIndices() touches nothing it is constructed with.
        return (new \ReflectionClass(ProvisioningService::class))->newInstanceWithoutConstructor();
    }

    public function testAnAddedBssIsAddedAndTheRestAreKept(): void
    {
        $before = ['wap-kc1' => 11, 'wap-onet1' => 12, 'eth0' => 2];
        $after = ['wap-kc1' => 11, 'wap-onet1' => 12, 'wap-ktest-5g' => 16, 'eth0' => 2];
        $expected = ['wap-kc1', 'wap-onet1', 'wap-ktest-5g'];

        $out = $this->service()->compareIndices($before, $after, $expected, $expected);

        $this->assertSame(['wap-ktest-5g'], $out['added']);
        $this->assertSame(['wap-kc1', 'wap-onet1'], $out['kept']);
        $this->assertSame([], $out['removed']);
        $this->assertSame([], $out['restarted']);
        $this->assertTrue($out['known']);
    }

    /**
     * The case that made this method grow a fourth argument.
     *
     * A bss being switched off is not in the expected set any more by the time
     * the run finishes, so judging the expected set alone reported that nothing
     * had gone away — in the very report whose job is to confirm it did.
     */
    public function testARemovedBssIsReportedEvenThoughItIsNoLongerExpected(): void
    {
        $before = ['wap-kc1' => 11, 'wap-ktest-5g' => 16];
        $after = ['wap-kc1' => 11];
        $expected = ['wap-kc1'];
        $managed = ['wap-kc1', 'wap-ktest-5g'];

        $out = $this->service()->compareIndices($before, $after, $expected, $managed);

        $this->assertSame(['wap-ktest-5g'], $out['removed']);
        $this->assertSame(['wap-kc1'], $out['kept']);

        // and without the managed set it would be missed, which is the bug
        $blind = $this->service()->compareIndices($before, $after, $expected, $expected);
        $this->assertSame([], $blind['removed']);
    }

    public function testASameNamedInterfaceWithANewIndexCountsAsRestarted(): void
    {
        $before = ['wap-kc1' => 11, 'wap-onet1' => 12];
        $after = ['wap-kc1' => 11, 'wap-onet1' => 19];
        $expected = ['wap-kc1', 'wap-onet1'];

        $out = $this->service()->compareIndices($before, $after, $expected, $expected);

        $this->assertSame(['wap-onet1'], $out['restarted'],
            'an interface that came back under a new index was rebuilt, and its stations were dropped');
        $this->assertSame(['wap-kc1'], $out['kept']);
    }

    public function testInterfacesThatAreNotOursAreNotJudged(): void
    {
        $before = ['wap-kc1' => 11, 'eth0' => 2, 'br-lan' => 3];
        $after = ['wap-kc1' => 11, 'eth0' => 7];
        $expected = ['wap-kc1'];

        $out = $this->service()->compareIndices($before, $after, $expected, $expected);

        $this->assertSame([], $out['restarted'], 'eth0 changing index is none of our business');
        $this->assertSame([], $out['removed']);
        $this->assertSame(['wap-kc1'], $out['kept']);
    }

    /**
     * No answer is not the same as no movement.
     */
    public function testAnUnreadableIndexListSaysSoRatherThanClaimingNothingMoved(): void
    {
        $out = $this->service()->compareIndices(null, ['wap-kc1' => 11], ['wap-kc1'], ['wap-kc1']);

        $this->assertFalse($out['known']);
        $this->assertSame([], $out['kept']);
        $this->assertSame([], $out['restarted']);
    }
}
