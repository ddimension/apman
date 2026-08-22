<?php

namespace App\Tests\Unit;

use ApManBundle\Service\RadiusAuthService;
use PHPUnit\Framework\TestCase;

/**
 * The network name, out of what RADIUS calls it.
 *
 * Called-Station-Id carries the bssid and the ssid in one string. Passed
 * through unchanged it is a name no network has, the key the access point named
 * is refused because it belongs to a different network, and nothing is stamped
 * onto it — the key keeps an empty first_seen and looks like one nobody ever
 * used.
 *
 * Measured on the fleet: 109 accepts on 2026-08-21 arrived as
 * "20:4b:e3:b4:f8:kalclients" and resolved no key.
 */
class RadiusSsidNameTest extends TestCase
{
    private function service(): RadiusAuthService
    {
        // normaliseSsid() touches nothing but its argument
        return new RadiusAuthService(
            new \Psr\Log\NullLogger(),
            $this->createMock(\Doctrine\Persistence\ManagerRegistry::class),
            $this->createMock(\ApManBundle\Factory\CacheFactory::class),
        );
    }

    /** @dataProvider names */
    public function testTheNameIsReadOutOfWhatTheAccessPointSent(string $sent, string $expected): void
    {
        $this->assertSame($expected, $this->service()->normaliseSsid($sent));
    }

    public static function names(): array
    {
        return [
            'a plain name is left alone' => ['kalclients', 'kalclients'],
            'the full called-station-id' => ['20:4b:e3:b4:f8:76:kalclients', 'kalclients'],
            'the five group form the fleet actually sent' => ['20:4b:e3:b4:f8:kalclients', 'kalclients'],
            'the same for kalnet' => ['20:50:06:53:e0:kalnet', 'kalnet'],
            'dashes, as radius often writes them' => ['20-4B-E3-B4-F8-76:kalnet', 'kalnet'],
            'a name with a colon in it survives' => ['ab:cd:net', 'ab:cd:net'],
            'three groups are not an address' => ['ab:cd:ef:net', 'ab:cd:ef:net'],
            'nothing but an address stays as it is' => ['20:4b:e3:b4:f8:76', '20:4b:e3:b4:f8:76'],
            'an empty name is not invented' => ['', ''],
            'a name that only looks hex' => ['ab:cd:ef:ab:beef', 'beef'],
        ];
    }

    /**
     * The one that would bite: a network whose name begins with something that
     * reads as an address. Four groups are required, and what is left must not
     * look like more address, so 'ab:cd:net' keeps its name.
     */
    public function testANameIsNeverEatenDownToNothing(): void
    {
        foreach (['20:4b:e3:b4:f8:76:', '20:4b:e3:b4:', 'aa:bb:cc:dd:ee:ff'] as $sent) {
            $this->assertSame($sent, $this->service()->normaliseSsid($sent),
                $sent.' has no name in it and must be left alone');
        }
    }
}
