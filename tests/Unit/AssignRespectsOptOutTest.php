<?php

namespace App\Tests\Unit;

use ApManBundle\Command\AssignAllSSIDsCommand;
use ApManBundle\Entity\AccessPoint;
use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;
use ApManBundle\Entity\SSID;
use ApManBundle\Service\RolloutService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A radio that said no keeps saying no when an assignment run goes past.
 *
 * This is the whole reason SsidRadioOptOut exists: the run used to create a bss
 * on every radio unconditionally, so deleting one by hand lasted until the next
 * run. The loop is static and takes its collaborators as arguments, which makes
 * the decision testable without a database.
 */
class AssignRespectsOptOutTest extends TestCase
{
    private function ap(array $radios): AccessPoint
    {
        $ap = new AccessPoint();
        $ap->setName('ap-test');
        foreach ($radios as $r) {
            $ap->addRadio($r);
        }

        return $ap;
    }

    private function radio(string $name): Radio
    {
        $radio = new Radio();
        $radio->setName($name);

        return $radio;
    }

    private function doctrineFinding(?Device $device): \Doctrine\Persistence\ManagerRegistry
    {
        $repo = $this->createMock(\Doctrine\Persistence\ObjectRepository::class);
        $repo->method('findOneBy')->willReturn($device);
        $doctrine = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $doctrine->method('getRepository')->willReturn($repo);

        return $doctrine;
    }

    public function testAnOptedOutRadioIsLeftAlone(): void
    {
        $ssid = new SSID();
        $ssid->setName('kalclients');
        $yes = $this->radio('radio0');
        $no = $this->radio('radio1');

        $rollout = $this->createMock(RolloutService::class);
        $rollout->method('isOptedOut')->willReturnCallback(
            function (SSID $s, Radio $r) use ($no) { return $r === $no; });
        // the one that did not opt out is the only one added
        $rollout->expects($this->once())->method('add')
            ->with($ssid, $yes)
            ->willReturn(['ok' => true, 'device' => 'radio0_kalclients', 'ifname' => 'wap-kc-2g']);

        $out = new BufferedOutput();
        AssignAllSSIDsCommand::assign($out, $this->doctrineFinding(null), $rollout,
            $this->ap([$yes, $no]), [$ssid], false, false);

        $text = $out->fetch();
        $this->assertStringContainsString('radio1 kalclients deliberately without it', $text);
        $this->assertStringContainsString('radio0', $text);
        $this->assertStringContainsString('1 added, 1 left alone', $text);
    }

    public function testForceOverridesTheDecision(): void
    {
        $ssid = new SSID();
        $ssid->setName('kalclients');
        $no = $this->radio('radio1');

        $rollout = $this->createMock(RolloutService::class);
        $rollout->method('isOptedOut')->willReturn(true);
        $rollout->expects($this->once())->method('add')
            ->willReturn(['ok' => true, 'device' => 'radio1_kalclients', 'ifname' => null,
                'why_no_name' => 'no short name']);

        $out = new BufferedOutput();
        AssignAllSSIDsCommand::assign($out, $this->doctrineFinding(null), $rollout,
            $this->ap([$no]), [$ssid], true, false);

        $this->assertStringContainsString('1 added, 0 left alone', $out->fetch());
    }

    /** A dry run touches nothing, whatever it says it would do. */
    public function testADryRunAddsNothing(): void
    {
        $ssid = new SSID();
        $ssid->setName('kalclients');

        $rollout = $this->createMock(RolloutService::class);
        $rollout->method('isOptedOut')->willReturn(false);
        $rollout->expects($this->never())->method('add');

        $out = new BufferedOutput();
        AssignAllSSIDsCommand::assign($out, $this->doctrineFinding(null), $rollout,
            $this->ap([$this->radio('radio0')]), [$ssid], false, true);

        $this->assertStringContainsString('would be added', $out->fetch());
    }

    /** A radio that already carries it is neither added nor counted as skipped. */
    public function testAnExistingBssIsLeftWhereItIs(): void
    {
        $ssid = new SSID();
        $ssid->setName('kalclients');

        $rollout = $this->createMock(RolloutService::class);
        $rollout->expects($this->never())->method('add');

        $out = new BufferedOutput();
        AssignAllSSIDsCommand::assign($out, $this->doctrineFinding(new Device()), $rollout,
            $this->ap([$this->radio('radio0')]), [$ssid], false, false);

        $text = $out->fetch();
        $this->assertStringContainsString('already carries it', $text);
        $this->assertStringContainsString('0 added, 0 left alone', $text);
    }
}
