<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;
use ApManBundle\Entity\SSID;
use ApManBundle\Library\IfnameScheme;
use PHPUnit\Framework\TestCase;

/**
 * The name a bss gets, and the two ways it can be wrong.
 */
class IfnameSchemeTest extends TestCase
{
    private function radio(string $name, string $band, string $path = ''): Radio
    {
        $radio = new Radio();
        $radio->setName($name);
        $radio->setConfigBand($band);
        $radio->setConfigPath($path);

        return $radio;
    }

    private function device(Radio $radio, string $ssidName, ?string $ifname = null): Device
    {
        $ssid = new SSID();
        $ssid->setName($ssidName);
        $device = new Device();
        $device->setRadio($radio);
        $device->setSsid($ssid);
        if (null !== $ifname) {
            $device->setIfname($ifname);
        }

        return $device;
    }

    public function testTheBandIsInTheNameAndTheRadioIndexIsNot(): void
    {
        $this->assertSame('wap-kc-5g', IfnameScheme::build('kc', '5g'));
        $this->assertSame('wap-kc-2g', IfnameScheme::build('kc', '2g'));
        $this->assertSame('wap-kc-6g', IfnameScheme::build('kc', '6g'));
    }

    /**
     * IFNAMSIZ is 16 including the NUL. A longer name is refused by the kernel,
     * the uci add fails, and a provisioning run is one transaction — so it
     * takes the whole access point with it.
     */
    public function testTooLongIsRejectedRatherThanTruncated(): void
    {
        $this->assertNull(IfnameScheme::reject('wap-kinfra-6g'));
        $this->assertNull(IfnameScheme::reject(str_repeat('a', 15)));
        $this->assertStringContainsString('longer than 15', IfnameScheme::reject(str_repeat('a', 16)));
    }

    public function testOnlyWhatSurvivesAFilePathAndATopic(): void
    {
        $this->assertNotNull(IfnameScheme::reject('wap-KC-5g'), 'upper case');
        $this->assertNotNull(IfnameScheme::reject('wap_kc_5g'), 'underscore');
        $this->assertNotNull(IfnameScheme::reject('wap-kc 5g'), 'space');
        $this->assertNotNull(IfnameScheme::reject(''));
    }

    public function testTheSlugBudgetSaysWhatStillFits(): void
    {
        $this->assertSame(8, IfnameScheme::slugBudget('5g'));
        $this->assertSame(7, IfnameScheme::slugBudget('60g'));
        $this->assertSame(7, IfnameScheme::slugBudget('5g', 2));
    }

    /**
     * The abbreviations in the field were chosen by somebody and are worth
     * keeping. Only the prefix and the radio index come off.
     */
    public function testTheSlugIsReadOutOfTheNameThatExists(): void
    {
        $this->assertSame('kc', IfnameScheme::slugFrom('wap-kc1'));
        $this->assertSame('onets', IfnameScheme::slugFrom('wap-onets0'));
        $this->assertSame('kinfra', IfnameScheme::slugFrom('kinfra1'));
        $this->assertSame('prv', IfnameScheme::slugFrom('wap-prv2'));
        $this->assertNull(IfnameScheme::slugFrom(''));
        $this->assertNull(IfnameScheme::slugFrom(null));
    }

    public function testOneRadioPerBandNeedsNoNumber(): void
    {
        $r5 = $this->radio('radio1', '5g', 'platform/soc/wifi');
        $r2 = $this->radio('radio2', '2g', 'platform/soc/wifi+1');
        $device = $this->device($r5, 'kalclients', 'wap-kc1');

        $this->assertSame('wap-kc-5g', IfnameScheme::forDevice($device, [$r5, $r2])['name']);
    }

    /**
     * ap-av-klwz really has two 5 GHz radios. Only then a number, and it is
     * ordered by the hardware path — ordering by radio name would put the
     * enumeration back into the name through the side door.
     */
    public function testTwoRadiosOfOneBandAreOrderedByHardwarePath(): void
    {
        $first = $this->radio('radio3', '5g', 'platform/soc/wifi');
        $second = $this->radio('radio0', '5g', 'platform/soc/wifi+1');
        $radios = [$second, $first];   // deliberately not in name order

        $a = $this->device($first, 'kalclients', 'wap-kc3');
        $b = $this->device($second, 'kalclients', 'wap-kc0');

        $this->assertSame('wap-kc-5g', IfnameScheme::forDevice($a, $radios)['name']);
        $this->assertSame('wap-kc-5g2', IfnameScheme::forDevice($b, $radios)['name']);
    }

    public function testTheSameInputGivesTheSameNameTwice(): void
    {
        $r = $this->radio('radio1', '5g', 'platform/soc/wifi');
        $device = $this->device($r, 'kalclients', 'wap-kc1');

        $this->assertSame(
            IfnameScheme::forDevice($device, [$r])['name'],
            IfnameScheme::forDevice($device, [$r])['name']);
    }

    public function testItSaysWhyRatherThanGuessing(): void
    {
        $r = $this->radio('radio1', '', 'platform/soc/wifi');
        $device = $this->device($r, 'kalclients', 'wap-kc1');
        $this->assertStringContainsString('no band', IfnameScheme::forDevice($device, [$r])['why']);

        $r2 = $this->radio('radio1', '5g', 'platform/soc/wifi');
        $nameless = $this->device($r2, 'a network with a very long name');
        $this->assertStringContainsString('no short name', IfnameScheme::forDevice($nameless, [$r2])['why']);
    }

    public function testALongSlugIsRefusedWithTheNameInTheMessage(): void
    {
        $r = $this->radio('radio1', '5g', 'platform/soc/wifi');
        $device = $this->device($r, 'kalclients');
        $out = IfnameScheme::forDevice($device, [$r], 'muchtoolongslug');

        $this->assertNull($out['name']);
        $this->assertStringContainsString('wap-muchtoolongslug-5g', $out['why']);
        $this->assertStringContainsString('longer than 15', $out['why']);
    }
}
