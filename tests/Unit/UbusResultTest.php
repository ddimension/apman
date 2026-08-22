<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Library\UbusResult;
use PHPUnit\Framework\TestCase;

/**
 * The reason a call failed, which used to be thrown away.
 */
class UbusResultTest extends TestCase
{
    public function testTheOldContractStillReads(): void
    {
        $this->assertSame(['a' => 1], UbusResult::ok(['a' => 1])->orFalse());
        $this->assertFalse(UbusResult::failed(UbusResult::NOT_FOUND)->orFalse());
        $this->assertFalse(UbusResult::failed(UbusResult::PERMISSION_DENIED)->orFalse());
    }

    /**
     * The distinction the provisioning transaction needs: a "not found" on a
     * uci delete is the normal answer for a section that is not there, and
     * anything else on the same call is a reason to stop.
     */
    public function testNotFoundIsTellableFromDenied(): void
    {
        $missing = UbusResult::failed(UbusResult::NOT_FOUND);
        $denied = UbusResult::failed(UbusResult::PERMISSION_DENIED);

        $this->assertNotSame($missing->status, $denied->status);
        $this->assertStringContainsString('not found', $missing->why());
        $this->assertStringContainsString('permission denied', $denied->why());
    }

    /**
     * A call that never reached ubus is a different kind of failure from one
     * ubus answered with "no" — the first says nothing about the access point's
     * configuration, the second says everything.
     */
    public function testATransportFailureIsNotAUbusAnswer(): void
    {
        $this->assertTrue(UbusResult::failed(UbusResult::NOT_FOUND)->reachedUbus());
        $this->assertFalse(UbusResult::failed(UbusResult::TRANSPORT_FAILED, 'connection refused')->reachedUbus());
        $this->assertStringContainsString('connection refused',
            UbusResult::failed(UbusResult::TRANSPORT_FAILED, 'connection refused')->why());
    }

    public function testOkIsOkEvenWhenTheAnswerIsEmpty(): void
    {
        $this->assertTrue(UbusResult::ok(null)->isOk());
        $this->assertNull(UbusResult::ok(null)->data);
    }
}
