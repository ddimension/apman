<?php

namespace App\Tests\Unit;

use ApManBundle\Service\WlanConsistencyService;
use PHPUnit\Framework\TestCase;

/**
 * The two audits that are silent on this fleet, driven with cases it does not
 * have.
 *
 * Measured 2026-08-22: all eighty-seven bsses on productive access points carry
 * a well formed, unique address and a chosen interface name, so both rules say
 * nothing. A rule that is silent because everything is right and one that is
 * silent because it is broken look identical from outside, and the second kind
 * has already happened once today — the arrival rule cast getDeviceConfig()
 * wrong, compared nothing, and reported a clean fleet.
 */
class AddressAndIfnameAuditTest extends TestCase
{
    private function bss(array $over = []): array
    {
        return $over + [
            'ap' => 'ap-test', 'productive' => true, 'name' => 'radio0_net',
            'ifname' => 'wap-net-2g', 'address' => '20:20:4b:11:22:33',
            'enabled' => true, 'reported' => '',
        ];
    }

    private function texts(array $findings): string
    {
        $out = '';
        foreach ($findings as $f) {
            $out .= $f['group'].' '.$f['option'].' ';
            foreach ($f['values'] as $why => $where) {
                $out .= $why.' '.implode(' ', $where).' ';
            }
        }

        return $out;
    }

    public function testACleanFleetSaysNothing(): void
    {
        $rows = [$this->bss(), $this->bss(['name' => 'radio1_net', 'ifname' => 'wap-net-5g',
            'address' => '20:20:4b:11:22:34'])];
        $this->assertSame([], WlanConsistencyService::addressFindings($rows, []));
    }

    /** The one that takes a network down, so it is the one reported first. */
    public function testTwoBssesCannotShareAnAddress(): void
    {
        $rows = [
            $this->bss(['name' => 'radio0_net']),
            $this->bss(['name' => 'radio1_net', 'ifname' => 'wap-net-5g']),
        ];
        $findings = WlanConsistencyService::addressFindings($rows, []);
        $this->assertCount(1, $findings);
        $this->assertSame('bss addresses', $findings[0]['group']);
        $this->assertTrue($findings[0]['roaming']);
        $text = $this->texts($findings);
        $this->assertStringContainsString('ap-test/radio0_net', $text);
        $this->assertStringContainsString('ap-test/radio1_net', $text);
    }

    /** A duplicate counts across access points too — a bssid is not local. */
    public function testADuplicateAcrossAccessPointsCounts(): void
    {
        $rows = [$this->bss(['ap' => 'ap-one']), $this->bss(['ap' => 'ap-two'])];
        $this->assertCount(1, WlanConsistencyService::addressFindings($rows, []));
    }

    public function testTheRunningConfigurationDisagreeing(): void
    {
        $rows = [$this->bss()];
        $findings = WlanConsistencyService::addressFindings($rows,
            ['ap-test' => ['wap-net-2g' => '20:20:4b:99:99:99']]);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('in the running configuration 20:20:4b:99:99:99',
            $this->texts($findings));
    }

    public function testWhatIsOnTheAirDisagreeing(): void
    {
        $rows = [$this->bss(['reported' => '20:20:4B:AB:CD:EF'])];
        $findings = WlanConsistencyService::addressFindings($rows, []);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('on the air 20:20:4b:ab:cd:ef', $this->texts($findings));
    }

    /** Case is not a difference; the parser lowercases one side and not the other. */
    public function testCaseAloneIsNotADisagreement(): void
    {
        $rows = [$this->bss(['address' => '20:20:4B:11:22:33', 'reported' => '20:20:4b:11:22:33'])];
        $this->assertSame([], WlanConsistencyService::addressFindings($rows, []));
    }

    public function testAnEnabledBssWithNoAddressIsReportedAndADisabledOneIsNot(): void
    {
        $rows = [$this->bss(['address' => '']), $this->bss(['name' => 'off', 'address' => '',
            'enabled' => false])];
        $findings = WlanConsistencyService::addressFindings($rows, []);
        $this->assertCount(1, $findings);
        $this->assertSame('not configured', $findings[0]['option']);
        $text = $this->texts($findings);
        $this->assertStringContainsString('ap-test/radio0_net', $text);
        $this->assertStringNotContainsString('ap-test/off', $text);
    }

    public function testSomethingThatIsNotAnAddressAtAll(): void
    {
        $findings = WlanConsistencyService::addressFindings([$this->bss(['address' => 'random'])], []);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('is not an address', $this->texts($findings));
    }

    /** A non productive access point is somebody's bench, and not audited. */
    public function testANonProductiveAccessPointIsLeftAlone(): void
    {
        $rows = [$this->bss(['productive' => false, 'address' => '', 'ifname' => ''])];
        $this->assertSame([], WlanConsistencyService::addressFindings($rows, []));
    }

    // ---- interface names ------------------------------------------------

    private function ifrow(array $over = []): array
    {
        return $over + [
            'ap' => 'ap-test', 'productive' => true, 'name' => 'radio0_net',
            'wanted' => 'wap-net-2g', 'seen' => 'wap-net-2g', 'enabled' => true,
        ];
    }

    public function testNamesThatAgreeSayNothing(): void
    {
        $this->assertSame([], WlanConsistencyService::ifnameFindings(
            [$this->ifrow()], ['ap-test' => ['wap-net-2g' => true]]));
    }

    public function testANameTheKernelWillRefuse(): void
    {
        $long = 'wap-much-too-long-for-a-netdev';
        $findings = WlanConsistencyService::ifnameFindings(
            [$this->ifrow(['wanted' => $long, 'seen' => ''])], []);
        $this->assertStringContainsString('the kernel takes at most', $this->texts($findings));
    }

    public function testTwoBssesOfOneAccessPointUnderOneName(): void
    {
        $rows = [$this->ifrow(['name' => 'a']), $this->ifrow(['name' => 'b'])];
        $findings = WlanConsistencyService::ifnameFindings($rows, []);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('two bsses of one access point', $this->texts($findings));
    }

    /** The same name on two access points is normal and must not be reported. */
    public function testTheSameNameOnTwoAccessPointsIsFine(): void
    {
        $rows = [$this->ifrow(['ap' => 'ap-one']), $this->ifrow(['ap' => 'ap-two'])];
        $this->assertSame([], WlanConsistencyService::ifnameFindings($rows, []));
    }

    public function testABssNobodyNamed(): void
    {
        $findings = WlanConsistencyService::ifnameFindings(
            [$this->ifrow(['wanted' => '', 'seen' => 'wlan0-1'])], []);
        $this->assertCount(1, $findings);
        $this->assertSame('not configured', $findings[0]['option']);
        $this->assertStringContainsString('running as wlan0-1', $this->texts($findings));
    }

    public function testAnInterfaceRunningThatMatchesNoBssWeKnow(): void
    {
        $findings = WlanConsistencyService::ifnameFindings([$this->ifrow()],
            ['ap-test' => ['wap-net-2g' => true, 'wap-leftover' => true]]);
        $this->assertCount(1, $findings);
        $this->assertSame('not ours', $findings[0]['option']);
        $this->assertStringContainsString('ap-test/wap-leftover', $this->texts($findings));
    }

    public function testTheNameWeAskedForAndTheNameThatRuns(): void
    {
        $findings = WlanConsistencyService::ifnameFindings(
            [$this->ifrow(['wanted' => 'wap-net-2g', 'seen' => 'wlan0-1'])], []);
        $this->assertStringContainsString('configured wap-net-2g, running wlan0-1',
            $this->texts($findings));
    }
}
