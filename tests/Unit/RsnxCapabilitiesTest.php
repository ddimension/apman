<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Controller\DefaultController;
use PHPUnit\Framework\TestCase;

/**
 * The RSN Extension element, read the way hostapd reads it.
 *
 * This is the element that answers "can this station do SAE-PK" — the
 * question that, before hostapd was patched to report it, could only be
 * answered by switching SAE-PK on and seeing who stopped connecting.
 *
 * The layout has one trap and every assertion here is about not falling into
 * it: the body encodes its own length in the low four bits of its first
 * octet, and those same four bits are part of the capability bitmap. So the
 * first bit with a meaning is bit 4, and bit 0..3 must never be reported.
 */
class RsnxCapabilitiesTest extends TestCase
{
    public function testTheOrdinaryCase(): void
    {
        // id 244, length 1, body 0x20: field length (0&0xf)+1 = 1 octet,
        // bit 5 set. This is what a station doing SAE H2E sends, and it is
        // what all three SAE stations on the fleet sent on 2026-08-29.
        $c = DefaultController::rsnxCapabilities('f40120');

        $this->assertSame([5 => 'SAE hash-to-element'], $c);
    }

    public function testSaePkIsBitSix(): void
    {
        $c = DefaultController::rsnxCapabilities('f40160');

        $this->assertArrayHasKey(6, $c, 'SAE-PK is bit 6');
        $this->assertSame('SAE-PK', $c[6]);
        $this->assertArrayHasKey(5, $c, 'and SAE-PK implies H2E, which is bit 5');
    }

    public function testASecondOctetIsRead(): void
    {
        // body 0x21 0x03: field length (1&0xf)+1 = 2 octets, so the bitmap is
        // 0x0321 little endian — bits 0, 5, 8, 9.
        $c = DefaultController::rsnxCapabilities('f40221' . '03');

        $this->assertArrayHasKey(8, $c, 'secure LTF, in the second octet');
        $this->assertArrayHasKey(9, $c, 'secure RTT');
        $this->assertSame('secure LTF', $c[8]);
    }

    public function testTheLengthNibbleIsNotACapability(): void
    {
        // bit 0 is set in the byte above and must not be reported: those four
        // bits are the field length, and reading them as capabilities is the
        // mistake this test exists to prevent
        $c = DefaultController::rsnxCapabilities('f40221' . '03');

        foreach ([0, 1, 2, 3] as $bit) {
            $this->assertArrayNotHasKey($bit, $c, 'bit '.$bit.' is length, not capability');
        }
    }

    public function testAnUnknownBitIsStillReported(): void
    {
        // body 0x07 0x00 0x00 0x00 0x80: field length 8 is capped at what is
        // there; bit 39 has no name and must come back as a number rather
        // than be dropped — a bit nobody recognises is the interesting one
        $c = DefaultController::rsnxCapabilities('f40507000000' . '80');

        $this->assertArrayHasKey(39, $c);
        $this->assertSame('bit 39', $c[39]);
    }

    public function testThingsThatAreNotAnRsnxe(): void
    {
        $this->assertSame([], DefaultController::rsnxCapabilities(null));
        $this->assertSame([], DefaultController::rsnxCapabilities(''));
        $this->assertSame([], DefaultController::rsnxCapabilities('f401'), 'header only');
        $this->assertSame([], DefaultController::rsnxCapabilities('f4012'), 'odd length');
        $this->assertSame([], DefaultController::rsnxCapabilities('300120'), 'a different element');
        $this->assertSame([], DefaultController::rsnxCapabilities('f40000'), 'empty body');
    }
}
