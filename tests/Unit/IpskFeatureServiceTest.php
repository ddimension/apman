<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Entity\Feature;
use ApManBundle\Entity\SSID;
use ApManBundle\Entity\SSIDFeatureMap;
use ApManBundle\Library\FeatureContext;
use ApManBundle\Service\IpskFeatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What iPSK writes into a wireless configuration, and what it must not.
 *
 * Every assertion here is a fleet outage or a night of searching. The one that
 * matters most is the last: sae_pwe must stay unset. Setting it to 2 on
 * 2026-08-21 threw every client off kalclients with status 126 and they could
 * not come back.
 */
class IpskFeatureServiceTest extends TestCase
{
    private function service(): IpskFeatureService
    {
        // A real FtKeyService, not a stub: the two methods this feature calls
        // read the configuration and the network's own lists and never touch
        // the database, so the real one answers truthfully and a stub would
        // only be able to answer what the test already assumed.
        $ftKeys = new \ApManBundle\Service\FtKeyService(
            $this->createStub(\Doctrine\Persistence\ManagerRegistry::class),
            new NullLogger());

        return new IpskFeatureService(
            new NullLogger(),
            $this->createStub(\Doctrine\Persistence\ManagerRegistry::class),
            $this->createStub(\ApManBundle\Service\wrtJsonRpc::class),
            $this->createStub(\ApManBundle\Factory\MqttFactory::class),
            $this->createStub(\Symfony\Component\HttpKernel\KernelInterface::class),
            $ftKeys);
    }

    /** A network that carries an FT key, the way a provisioned one does. */
    private function contextWithFtKey(string $key): FeatureContext
    {
        $ctx = $this->context();
        $list = new \ApManBundle\Entity\SSIDConfigList();
        $list->setName('r0kh');
        $option = new \ApManBundle\Entity\SSIDConfigListOption();
        $option->setValue('ff:ff:ff:ff:ff:ff,*,'.$key);
        $list->addOption($option);
        $ctx->ssid->addConfigList($list);

        return $ctx;
    }

    /** A context without a device — what the network page previews with. */
    private function context(array $catalog = []): FeatureContext
    {
        $feature = new Feature();
        $feature->setConfig($catalog);
        $map = new SSIDFeatureMap();
        $map->setFeature($feature);
        $ssid = new SSID();
        $ssid->setName('kalclients');
        $map->setSsid($ssid);

        return new FeatureContext($ssid, $map, $feature);
    }

    public function testTurnsOnTheOpenWrtNativeSwitch(): void
    {
        $out = $this->service()->getConfig(['ssid' => 'kalclients'], $this->context());

        $this->assertSame('1', $out['ppsk'], 'ppsk is what ap.uc turns into wpa_psk_radius=2');
    }

    public function testRemovesTheNetworkPassphrase(): void
    {
        $out = $this->service()->getConfig(['ssid' => 'kalclients', 'key' => 'sharedsecret'], $this->context());

        // sae_get_password() prefers a configured passphrase over anything
        // RADIUS delivered, so leaving it in gives every device one key again
        $this->assertArrayNotHasKey('key', $out);
    }

    public function testAddsTheRawLinesExactlyOnce(): void
    {
        $out = $this->service()->getConfig([
            'ssid' => 'kalclients',
            'hostapd_bss_options' => ['wpa_psk_radius=2', 'stationary_ap=1'],
        ], $this->context());

        $this->assertSame(['wpa_psk_radius=2', 'stationary_ap=1', 'macaddr_acl=2'],
            array_values($out['hostapd_bss_options']));
    }

    public function testCarriesTheCatalogOptionsThrough(): void
    {
        $out = $this->service()->getConfig(['ssid' => 'kalclients'],
            $this->context(['auth_server' => '127.0.0.1', 'auth_server_port' => 1812]));

        $this->assertSame('127.0.0.1', $out['auth_server']);
        $this->assertSame(1812, $out['auth_server_port']);
    }

    /**
     * The regression guard for 2026-08-21.
     *
     * ap.uc skips its own sae_pwe default as soon as ppsk is set, and a key
     * that arrives over RADIUS carries no PT, so it can do no hash-to-element.
     * A station that commits with H2E against an advertised H2E is answered
     * with status 1 and never associates.
     */
    public function testNeverSetsSaePwe(): void
    {
        $out = $this->service()->getConfig(['ssid' => 'kalclients'],
            $this->context(['auth_server' => '127.0.0.1']));

        $this->assertArrayNotHasKey('sae_pwe', $out);
    }

    /**
     * The preview instantiates without a device — no radio, no access point,
     * no per access point secret to fill in. It must still come back with a
     * configuration instead of throwing.
     */
    public function testSurvivesWithoutADevice(): void
    {
        $out = $this->service()->getConfig(['ssid' => 'kalclients'], $this->context());

        $this->assertIsArray($out);
        $this->assertArrayNotHasKey('auth_secret', $out);
    }

    public function testLeavesLocalFtDerivationAloneWithoutAKey(): void
    {
        // The flag and the key belong together. Turning local derivation off
        // while ap.uc is still deriving the FT key from the per access point
        // RADIUS secret would trade one broken roam for another.
        $out = $this->service()->getConfig(
            ['ssid' => 'kalclients', 'ieee80211r' => '1', 'ft_psk_generate_local' => '1'],
            $this->context());

        $this->assertSame('1', $out['ft_psk_generate_local'],
            'without a shared key the flag must not be touched');
    }

    public function testTurnsOffLocalFtDerivationOnceThereIsAKey(): void
    {
        $out = $this->service()->getConfig(
            ['ssid' => 'kalclients', 'ieee80211r' => '1', 'ft_psk_generate_local' => '1'],
            $this->contextWithFtKey(str_repeat('a', 64)));

        $this->assertSame('0', $out['ft_psk_generate_local'],
            'per station keys cannot be derived locally by the target of a roam');
        $this->assertContains('ft_psk_generate_local=0', $out['hostapd_bss_options'],
            'and the raw line repeats it, like the other two');
    }

    public function testSaysNothingAboutFtOnANetworkWithoutIt(): void
    {
        $out = $this->service()->getConfig(
            ['ssid' => 'kalclients'], $this->contextWithFtKey(str_repeat('b', 64)));

        $this->assertArrayNotHasKey('ft_psk_generate_local', $out,
            'a network that does not roam gets no roaming options');
    }
}
