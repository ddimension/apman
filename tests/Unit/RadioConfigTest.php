<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Entity\Radio;
use PHPUnit\Framework\TestCase;

/**
 * The wifi-device payload: nineteen columns and everything else.
 */
class RadioConfigTest extends TestCase
{
    public function testColumnsAreExportedWithoutTheirPrefix(): void
    {
        $radio = new Radio();
        $radio->setConfigBand('5g');
        $radio->setConfigChannel('100');

        $out = (array) $radio->exportConfig();

        $this->assertSame('5g', $out['band']);
        $this->assertSame('100', $out['channel']);
        $this->assertArrayNotHasKey('config_band', $out);
    }

    public function testTheJsonColumnCarriesWhatNoColumnDoes(): void
    {
        $radio = new Radio();
        $radio->setConfigBand('5g');
        $radio->setConfig(['mbssid' => 1, 'he_bss_color' => 12,
            'hostapd_options' => ['rssi_ignore_probe_request=-80']]);

        $out = (array) $radio->exportConfig();

        $this->assertSame(1, $out['mbssid']);
        $this->assertSame(12, $out['he_bss_color']);
        $this->assertSame(['rssi_ignore_probe_request=-80'], $out['hostapd_options']);
        $this->assertSame('5g', $out['band'], 'the columns still come through');
    }

    /**
     * One option, one place. A json entry for something a column owns would be
     * a second truth about the same value, and which one wins would depend on
     * the order exportConfig() happens to walk in.
     */
    public function testTheJsonColumnRefusesWhatAColumnOwns(): void
    {
        $radio = new Radio();
        $radio->setConfig(['band' => '2g', 'channel' => '1', 'mbssid' => 1]);

        $this->assertSame(['mbssid' => 1], $radio->getConfig());
    }

    public function testEmptyValuesAreLeftOutRatherThanSentAsNothing(): void
    {
        $radio = new Radio();
        $radio->setConfigBand('6g');
        $radio->setConfig(['mbssid' => null, 'hostapd_options' => []]);

        $out = (array) $radio->exportConfig();

        $this->assertArrayNotHasKey('mbssid', $out);
        $this->assertArrayNotHasKey('hostapd_options', $out);
    }
}
