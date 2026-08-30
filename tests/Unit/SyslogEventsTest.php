<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Service\SyslogService;
use PHPUnit\Framework\TestCase;

/**
 * Which log lines are worth keeping past the access point's own ring buffer.
 *
 * The trap this file exists for: a line can quote a pattern instead of
 * reporting the thing. The reboot cron on the ath11k access points greps for
 * these very strings, and crond logs the whole command text once a minute — so
 * its own line matches every pattern. Measuring without that guard reported 314
 * firmware faults on ap-av-klwz on 2026-08-30, of which the real number was
 * zero. Twice in a row, by the same mistake.
 */
class SyslogEventsTest extends TestCase
{
    private function line(string $text, ?string $ident = 'kernel'): array
    {
        return ['text' => $text, 'ident' => $ident, 'ts' => 1788000000];
    }

    public function testTheFirmwareFault(): void
    {
        $this->assertSame('ath11k_fault', SyslogService::classify($this->line(
            'ath11k_pci 0000:01:00.0: failed to send WMI_PDEV_BSS_CHAN_INFO_REQUEST cmd')));
        $this->assertSame('ath11k_fault', SyslogService::classify($this->line(
            'wap-kc1: too many connected already', 'hostapd')));
    }

    public function testTheDfsEvents(): void
    {
        $this->assertSame('dfs_radar', SyslogService::classify($this->line(
            'wap-kc1: DFS-RADAR-DETECTED freq=5500 ht_enabled=0', 'hostapd')));
        $this->assertSame('dfs_cac_completed', SyslogService::classify($this->line(
            'wap-kc1: DFS-CAC-COMPLETED success=1 freq=5500', 'hostapd')));
        $this->assertSame('dfs_new_channel', SyslogService::classify($this->line(
            'wap-kc1: DFS-NEW-CHANNEL freq=5180', 'hostapd')));
    }

    public function testCrondQuotingThePatternIsNotAnEvent(): void
    {
        // the real line, as crond writes it every minute on the three ath11k
        // access points. It contains both fault patterns verbatim.
        $cron = 'USER root pid 12999 cmd /usr/bin/timeout 2 /sbin/logread | /bin/grep -v '
            ."'crond' | /bin/grep -E 'failed to send WMI_PDEV_BSS_CHAN_INFO_REQUEST cmd|"
            ."too many connected already' >/dev/null && logger -s \"ath11k firmware crash, reboot\"";

        $this->assertNull(SyslogService::classify($this->line($cron, 'crond')),
            'the ident alone is enough to rule it out');
        $this->assertNull(SyslogService::classify($this->line($cron, 'kernel')),
            'and so is the word in the text, for when the ident is lost on the way');
    }

    public function testAnEmptyOrUnremarkableLine(): void
    {
        $this->assertNull(SyslogService::classify($this->line('')));
        $this->assertNull(SyslogService::classify($this->line('wap-kc1: AP-STA-CONNECTED aa:bb:cc:dd:ee:ff', 'hostapd')));
        $this->assertNull(SyslogService::classify([]));
    }

    public function testEveryPatternHasALabelAndAVerdict(): void
    {
        foreach (SyslogService::EVENTS as $key => $event) {
            $this->assertArrayHasKey('re', $event, $key);
            $this->assertArrayHasKey('label', $event, $key);
            $this->assertArrayHasKey('bad', $event, $key);
            $this->assertIsBool($event['bad'], $key);
            // preg_match returns 0 for "no match" and false for a broken
            // pattern, so false is what has to be ruled out here
            $this->assertNotFalse(@preg_match($event['re'], 'x'), $key.': the pattern must compile');
        }
    }
}
