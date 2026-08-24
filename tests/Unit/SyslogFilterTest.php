<?php

namespace App\Tests\Unit;

use ApManBundle\Service\SyslogService;
use PHPUnit\Framework\TestCase;

/**
 * The filter the controller sends, and how it is compared with what came back.
 */
class SyslogFilterTest extends TestCase
{
    private function service(): SyslogService
    {
        return (new \ReflectionClass(SyslogService::class))->newInstanceWithoutConstructor();
    }

    public function testTheListIsTidiedBeforeItGoesOut(): void
    {
        $s = $this->service();

        $this->assertSame(['hostapd', 'netifd'], $s->clean([' hostapd ', 'netifd', '', '  ']));
        $this->assertSame(['hostapd'], $s->clean(['hostapd', 'hostapd']),
            'uci add_list appends, so a duplicate here is a duplicate on the device');
        $this->assertSame(['b', 'a'], $s->clean(['b', 'a']), 'order is the operator\'s, not ours');
        $this->assertSame([], $s->clean(null));
        $this->assertSame([], $s->clean(''));
    }

    /**
     * The lists have to be deleted before they are written. uci's add_list
     * appends, so a second push without a delete leaves an access point with
     * every ident it has ever been told about — and it looks like it worked.
     */
    public function testEveryOptionIsDeletedBeforeAnythingIsSet(): void
    {
        $s = $this->service();
        $ref = new \ReflectionMethod($s, 'uciExec');
        $ref->setAccessible(true);

        // the contract this rests on, stated where a change to it would break a test
        $this->assertSame(['syslog_enabled', 'syslog_all', 'syslog_kernel',
            'syslog_allow', 'syslog_deny', 'syslog_allow_re'], SyslogService::OPTIONS);
        $this->assertSame(['syslog_allow', 'syslog_deny', 'syslog_allow_re'], SyslogService::LISTS);
        foreach (SyslogService::LISTS as $list) {
            $this->assertContains($list, SyslogService::OPTIONS,
                'a list that is not in OPTIONS is never deleted and therefore grows');
        }

        $call = $ref->invoke($s, ['delete', 'apman.main.syslog_allow']);
        $this->assertSame('file', $call['object']);
        $this->assertSame('exec', $call['method']);
        $this->assertSame('/sbin/uci', $call['args']->command);
    }

    public function testDriftIsSilentWhenEitherSideIsUnknown(): void
    {
        $s = $this->service();
        $running = ['enabled' => true, 'all' => false, 'kernel' => true, 'allow' => [], 'allow_re' => []];

        $this->assertSame([], $s->driftBetween(null, $running), 'no intention, no disagreement');
        $this->assertSame([], $s->driftBetween(['allow' => ['x']], null),
            'an access point that has not reported is not an access point that disagrees');
    }

    public function testAFlagThatDisagreesIsReported(): void
    {
        $s = $this->service();
        $out = $s->driftBetween(
            ['enabled' => true, 'all' => false, 'kernel' => true, 'allow' => []],
            ['enabled' => false, 'all' => false, 'kernel' => true, 'allow' => [], 'allow_re' => []]);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('enabled: asked for on, running off', $out[0]);
    }

    /**
     * allow adds to the agent's built-in defaults, so extra idents on the
     * device are those defaults and not a disagreement — only a missing one is.
     */
    public function testOnlyMissingIdentsCount(): void
    {
        $s = $this->service();
        $out = $s->driftBetween(
            ['allow' => ['hostapd', 'mydaemon']],
            ['enabled' => true, 'all' => false, 'kernel' => true,
             'allow' => ['hostapd', 'netifd', 'kernel'], 'allow_re' => []]);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('mydaemon', $out[0]);
        $this->assertStringNotContainsString('netifd', $out[0], 'the defaults are not drift');
    }

    public function testDenyIsNotComparedBecauseItCannotBe(): void
    {
        $s = $this->service();
        $this->assertSame([], $s->driftBetween(
            ['deny' => ['hostapd'], 'allow' => []],
            ['enabled' => true, 'all' => false, 'kernel' => true,
             'allow' => ['netifd'], 'allow_re' => []]),
            'deny removes from the defaults, so it shows up as an absence and never as a value');
    }

}
