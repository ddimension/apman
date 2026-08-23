<?php

namespace App\Tests\Unit;

use ApManBundle\Service\TopologyService;
use PHPUnit\Framework\TestCase;

/**
 * lldpcli's json, and the two shapes it comes in.
 *
 * Both fixtures are real answers from this fleet on 23.08.2026 — a Zyxel
 * GS1900-10HP that feeds ap-av-attic over ethernet, and an Omada switch that
 * does not. They differ in more than the power block: the Zyxel is only a
 * bridge and its capability is one object, the Omada also routes and its
 * capability is a list. Anything walking that structure has to survive both.
 */
class TopologyParseTest extends TestCase
{
    private function service(): TopologyService
    {
        return (new \ReflectionClass(TopologyService::class))->newInstanceWithoutConstructor();
    }

    private const POE = <<<'JSON'
        {"lldp":{"interface":{"wan":{"via":"LLDP","rid":"1","age":"0 day, 22:19:41",
        "chassis":{"switch-poe2":{"id":{"type":"mac","value":"BC:CF:4F:D1:73:88"},
        "descr":"GS1900-10HP","mgmt-ip":"192.168.203.22",
        "capability":{"type":"Bridge","enabled":true}}},
        "port":{"id":{"type":"local","value":"6"},"descr":"ap-av-attic","ttl":"120",
        "auto-negotiation":{"supported":true,"enabled":true,
        "current":"1000BaseTFD - Four-pair Category 5 UTP, full duplex mode"},
        "power":{"supported":true,"enabled":true,"device-type":"PSE","class":"class 4",
        "allocated":"31200","priority":"low"}}}}}}
        JSON;

    private const NO_POE = <<<'JSON'
        {"lldp":{"interface":{"eth1":{"via":"LLDP","rid":"2","age":"1 day, 02:56:53",
        "chassis":{"coreswitch-n2":{"id":{"type":"mac","value":"30:68:93:9d:77:9e"},
        "descr":"Omada 24-Port 2.5GBASE-T and 4-Port 10GE SFP+ L2+ Managed Switch",
        "mgmt-ip":"192.168.203.20","mgmt-iface":"1",
        "capability":[{"type":"Bridge","enabled":true},{"type":"Router","enabled":true}]}},
        "port":{"id":{"type":"ifname","value":"two-gigabitEthernet 1/0/19"},
        "descr":"two-gigabitEthernet 1/0/19","ttl":"120"}}}}}
        JSON;

    public function testAPoweredPortIsReadWhole(): void
    {
        $out = $this->service()->parse(self::POE);

        $this->assertTrue($out['ok']);
        $this->assertCount(1, $out['links']);
        $link = $out['links'][0];

        $this->assertSame('wan', $link['local']);
        $this->assertSame('switch-poe2', $link['switch']);
        $this->assertSame('bc:cf:4f:d1:73:88', $link['switch_mac'], 'addresses are compared lowercase everywhere else');
        $this->assertSame('GS1900-10HP', $link['model']);
        $this->assertSame('192.168.203.22', $link['mgmt_ip']);
        $this->assertSame('6', $link['port']);
        $this->assertStringStartsWith('1000BaseTFD', $link['link']);
        $this->assertSame(31200, $link['poe_mw'], 'lldp carries milliwatts, so 31200 is 31.2 W');
        $this->assertSame('class 4', $link['poe_class']);
        $this->assertSame('PSE', $link['poe_from']);
    }

    /**
     * The switch that routes as well, whose capability is a list rather than an
     * object — and which does not feed the access point.
     */
    public function testAnUnpoweredPortOnASwitchThatAlsoRoutes(): void
    {
        $out = $this->service()->parse(self::NO_POE);

        $link = $out['links'][0];
        $this->assertSame('coreswitch-n2', $link['switch']);
        $this->assertSame('two-gigabitEthernet 1/0/19', $link['port']);
        $this->assertNull($link['poe_mw'], 'no power block means no power, not zero watts');
        $this->assertNull($link['link'], 'this switch reports no negotiated link, and inventing one would be worse');
    }

    public function testNothingReadableIsSaidRatherThanReportedAsEmpty(): void
    {
        foreach (['', 'lldpcli: command not found', '{}', '{"lldp":{}}'] as $rubbish) {
            $out = $this->service()->parse($rubbish);
            $this->assertFalse($out['ok'], 'on: '.$rubbish);
            $this->assertSame([], $out['links']);
        }
    }
}
