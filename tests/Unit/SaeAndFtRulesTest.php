<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Service\WlanConsistencyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The two rules that judge a bss by what its hostapd can do.
 *
 * Written after an audit found the first version of the sae_pwe rule firing
 * its RADIUS wording at every SAE network: a plain one with a configured
 * passphrase derives a PT from that passphrase and is fine, and was being told
 * that no station could associate at all. A rule that is confidently wrong is
 * worse than no rule, and nothing here was covered by a test.
 */
class SaeAndFtRulesTest extends TestCase
{
    private function service(): WlanConsistencyService
    {
        return new WlanConsistencyService(
            new NullLogger(),
            $this->createStub(\Doctrine\Persistence\ManagerRegistry::class),
            $this->createStub(\ApManBundle\Service\wrtJsonRpc::class),
            $this->createStub(\ApManBundle\Factory\CacheFactory::class),
            $this->createStub(\ApManBundle\Service\StateTreeService::class),
            $this->createStub(\ApManBundle\Service\WirelessSchemaService::class),
            $this->createStub(\ApManBundle\Service\ApUbusService::class),
            $this->createStub(\ApManBundle\Service\AccessPointService::class),
            $this->createStub(\ApManBundle\Service\HostapdBuildService::class));
    }

    /** Run blockRules() over one parsed bss, with the build stated. */
    private function findings(array $cfg, bool $patched = false): array
    {
        $svc = $this->service();
        $ref = new \ReflectionClass($svc);

        $map = $ref->getProperty('patched');
        $map->setAccessible(true);
        $map->setValue($svc, ['ap1' => $patched]);

        $rules = $ref->getMethod('blockRules');
        $rules->setAccessible(true);

        $out = [];
        foreach ($rules->invoke($svc, ['ap' => 'ap1', 'bss' => 'wap0', 'cfg' => $cfg]) as $f) {
            $out[$f['option']] = array_key_first($f['values']);
        }

        return $out;
    }

    private function sae(array $extra = []): array
    {
        return array_merge([
            'ssid' => 'kalclients', '_band' => '5g',
            'wpa_key_mgmt' => 'SAE FT-SAE', 'ieee80211w' => '2',
            'wpa_pairwise' => 'CCMP',
        ], $extra);
    }

    public function testPlainSaeWithAPassphraseIsNotAccusedOfTheRadiusFault(): void
    {
        $f = $this->findings($this->sae(['sae_pwe' => '2']));

        $this->assertArrayHasKey('sae_pwe', $f, 'H2E-only still excludes pre-H2E stations');
        $this->assertStringNotContainsString('RADIUS', $f['sae_pwe'],
            'a locally configured passphrase has a PT — this is not the RADIUS fault');
        $this->assertStringContainsString('without H2E', $f['sae_pwe']);
    }

    public function testRadiusKeyedSaeWithHashToElementOnlyOnStockIsFatal(): void
    {
        $f = $this->findings($this->sae(['sae_pwe' => '2', 'wpa_psk_radius' => '2']));

        $this->assertStringContainsString('no station can associate at all', $f['sae_pwe']);
    }

    public function testRadiusKeyedSaeWithBothMethodsOnStockIsIntermittent(): void
    {
        // the case the first version of the rule missed entirely: sae_pwe=1
        // offers both, so only the stations that choose H2E are refused
        $f = $this->findings($this->sae(['sae_pwe' => '1', 'wpa_psk_radius' => '2']));

        $this->assertArrayHasKey('sae_pwe', $f, 'sae_pwe=1 is not silent on a stock build');
        $this->assertStringContainsString('chooses H2E', $f['sae_pwe']);
    }

    public function testThePatchedBuildTurnsTheOutageIntoAChoice(): void
    {
        $f = $this->findings($this->sae(['sae_pwe' => '2', 'wpa_psk_radius' => '2']), true);

        $this->assertStringNotContainsString('no station can associate', $f['sae_pwe']);
        $this->assertStringContainsString('sae_pwe=1 admits both', $f['sae_pwe']);
    }

    public function testPerStationKeysCannotDeriveFtLocally(): void
    {
        $f = $this->findings($this->sae([
            'wpa_key_mgmt' => 'WPA-PSK FT-PSK', 'wpa_psk_radius' => '2',
            'ft_psk_generate_local' => '1',
        ]));

        $this->assertArrayHasKey('ft_psk_generate_local', $f);
        $this->assertArrayHasKey('r0kh', $f, 'and the key it would fetch instead is missing');
    }

    public function testAsharedPassphraseIsRightToDeriveLocally(): void
    {
        // kalinfra: FT-PSK, one passphrase for everybody, no RADIUS keys.
        // ft_psk_generate_local=1 is correct there and must stay silent.
        $f = $this->findings($this->sae([
            'wpa_key_mgmt' => 'WPA-PSK FT-PSK', 'ft_psk_generate_local' => '1',
        ]));

        $this->assertArrayNotHasKey('ft_psk_generate_local', $f);
        $this->assertArrayNotHasKey('r0kh', $f);
    }

    public function testAnFtNetworkWithBothHalvesIsSilent(): void
    {
        $f = $this->findings($this->sae([
            'wpa_psk_radius' => '2', 'ft_psk_generate_local' => '0',
            'r0kh' => 'ff:ff:ff:ff:ff:ff * '.str_repeat('a', 64),
        ]));

        $this->assertArrayNotHasKey('ft_psk_generate_local', $f);
        $this->assertArrayNotHasKey('r0kh', $f);
    }
}
