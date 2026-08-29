<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Service\AccessPointService;
use ApManBundle\Service\WifiIeParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Asking a station to move, or throwing it off.
 *
 * The answer came only out of the taxonomy signature until 2026-08-30, and
 * that source is not reliable: measured on one kalnet bss, not one of six
 * stations had extcap: in its signature and one had no signature at all —
 * while hostapd was handing the extended capabilities over as their own field
 * in the same message. So steerClient sent 24 hard disconnects in five days
 * and not a single transition request, on a network where six of the fourteen
 * steered stations announce the capability.
 *
 * Nothing tested it. That is the reason this file exists.
 */
class BssTransitionCapableTest extends TestCase
{
    private function service(): AccessPointService
    {
        return new AccessPointService(
            new NullLogger(),
            $this->createStub(\Doctrine\Persistence\ManagerRegistry::class),
            $this->createStub(\ApManBundle\Service\wrtJsonRpc::class),
            $this->createStub(\ApManBundle\Service\ApUbusService::class),
            $this->createStub(\ApManBundle\Service\DfsService::class),
            $this->createStub(\Symfony\Component\HttpKernel\KernelInterface::class),
            $this->createStub(\ApManBundle\Factory\MqttFactory::class),
            $this->createStub(\ApManBundle\Factory\CacheFactory::class),
            new WifiIeParser(new NullLogger()),
            $this->createStub(\ApManBundle\Service\PpskService::class),
            $this->createStub(\ApManBundle\Service\SteeringService::class),
            $this->createStub(\ApManBundle\Service\StateTreeService::class),
            $this->createStub(\ApManBundle\Service\FeatureRegistry::class));
    }

    /** the eight octets hostapd sent for a8:4f:a4:72:34:56, bit 19 set */
    private function capable(): array
    {
        return [0x04, 0x00, 0x08, 0x00, 0x00, 0x40, 0x00, 0x40];
    }

    public function testTheUbusFieldIsEnough(): void
    {
        // the case that was being missed: a station with no signature at all
        // whose extended_capabilities say plainly that it can be asked to move
        $this->assertTrue($this->service()->canBeAskedToMove([
            'extended_capabilities' => $this->capable(),
            'signature' => '',
        ]));
    }

    public function testABitThatIsNotSetMeansNo(): void
    {
        $ec = $this->capable();
        $ec[2] = 0x00;

        $this->assertFalse($this->service()->canBeAskedToMove(['extended_capabilities' => $ec]));
    }

    public function testItIsBitNineteenAndNotSomeOtherBitOfThatOctet(): void
    {
        foreach ([0x01, 0x02, 0x04, 0x10, 0x20, 0x40, 0x80] as $other) {
            $ec = $this->capable();
            $ec[2] = $other;
            $this->assertFalse($this->service()->canBeAskedToMove(['extended_capabilities' => $ec]),
                sprintf('octet 2 = 0x%02x is not BSS Transition', $other));
        }
    }

    public function testAShortFieldIsNotReadPastItsEnd(): void
    {
        $this->assertFalse($this->service()->canBeAskedToMove(['extended_capabilities' => [0x04, 0x00]]));
        $this->assertFalse($this->service()->canBeAskedToMove(['extended_capabilities' => []]));
    }

    public function testTheSignatureStillWorksAsAFallback(): void
    {
        // a real one, from 02:27:e2:fe:6d:fe on 2026-08-29
        $sig = 'wifi4|probe:0,1,45,127,191|assoc:0,1,33,36,48,70,54,59,45,127,191,255,244,'
            .'htcap:006f,htagg:1b,htmcs:0000ffff,extcap:0400080001400040002120';

        $this->assertTrue($this->service()->canBeAskedToMove(['signature' => $sig]),
            'no extended_capabilities field, but the signature carries extcap');
    }

    public function testNeitherSourceMeansNo(): void
    {
        $this->assertFalse($this->service()->canBeAskedToMove([]));
        $this->assertFalse($this->service()->canBeAskedToMove(['signature' => null]));
        // a probe-only signature, which is what most of the fleet actually has
        $this->assertFalse($this->service()->canBeAskedToMove([
            'signature' => 'wifi4|probe:0,1,221(0050f2,8)',
        ]));
    }
}
