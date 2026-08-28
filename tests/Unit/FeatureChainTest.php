<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Entity\Feature;
use ApManBundle\Entity\SSID;
use ApManBundle\Entity\SSIDFeatureMap;
use ApManBundle\Library\FeatureContext;
use ApManBundle\Service\DefaultFeatureService;
use ApManBundle\Service\FeatureRegistry;
use ApManBundle\Service\IpskFeatureService;
use ApManBundle\Service\iFeatureService;
use ApManBundle\Service\OweFeatureService;
use ApManBundle\Service\StaticMACFeatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The chain itself: the merge rules, the order, and the promise the preview
 * relies on.
 */
class FeatureChainTest extends TestCase
{
    private function build(string $class): iFeatureService
    {
        $args = [
            new NullLogger(),
            $this->createStub(\Doctrine\Persistence\ManagerRegistry::class),
            $this->createStub(\ApManBundle\Service\wrtJsonRpc::class),
            $this->createStub(\ApManBundle\Factory\MqttFactory::class),
            $this->createStub(\Symfony\Component\HttpKernel\KernelInterface::class),
        ];
        // A feature may take more than the five the base class does — iPSK
        // takes FtKeyService, because the flag it sets depends on whether the
        // network has an FT key. Rather than teach this helper each of them,
        // it asks the constructor what it wants and fills the rest from the
        // container-less stubs a unit test can build.
        $extra = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        foreach (array_slice($extra, count($args)) as $param) {
            $type = $param->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            $args[] = \ApManBundle\Service\FtKeyService::class === $name
                ? new \ApManBundle\Service\FtKeyService(
                    $this->createStub(\Doctrine\Persistence\ManagerRegistry::class), new NullLogger())
                : $this->createStub($name);
        }

        return new $class(...$args);
    }

    private function context(array $catalog = []): FeatureContext
    {
        $feature = new Feature();
        $feature->setConfig($catalog);
        $map = new SSIDFeatureMap();
        $map->setFeature($feature);
        $ssid = new SSID();
        $ssid->setName('kalnet');
        $map->setSsid($ssid);

        return new FeatureContext($ssid, $map, $feature);
    }

    public function testScalarsReplaceAndListsAppend(): void
    {
        $out = $this->build(DefaultFeatureService::class)->getConfig(
            ['dtim_period' => 1, 'hostapd_bss_options' => ['oce=4']],
            $this->context(['dtim_period' => 3, 'hostapd_bss_options' => ['mbo=1']]));

        // one value wins, both raw lines survive
        $this->assertSame(3, $out['dtim_period']);
        $this->assertSame(['oce=4', 'mbo=1'], array_values($out['hostapd_bss_options']));
    }

    public function testAListIsDeduplicated(): void
    {
        $out = $this->build(DefaultFeatureService::class)->getConfig(
            ['hostapd_bss_options' => ['mbo=1']],
            $this->context(['hostapd_bss_options' => ['mbo=1', 'oce=4']]));

        $this->assertSame(['mbo=1', 'oce=4'], array_values($out['hostapd_bss_options']));
    }

    /**
     * Priority decides, and the later feature sees what the earlier one wrote.
     */
    public function testTheSecondFeatureSeesTheFirst(): void
    {
        $first = $this->build(DefaultFeatureService::class);
        $cfg = $first->getConfig(['ssid' => 'kalnet'], $this->context(['ieee80211r' => '1']));
        $second = $this->build(DefaultFeatureService::class);
        $cfg = $second->getConfig($cfg, $this->context(['ieee80211r' => '0']));

        $this->assertSame('0', $cfg['ieee80211r']);
    }

    /**
     * The contract the network page depends on: every implementation must
     * survive a context with no device, because a preview has none. This is
     * what made every OWE mapping render as "cannot be previewed".
     */
    public function testEveryImplementationPreviewsWithoutADevice(): void
    {
        $classes = [DefaultFeatureService::class, IpskFeatureService::class,
            OweFeatureService::class, StaticMACFeatureService::class];
        foreach ($classes as $class) {
            $ctx = $this->context(['ssid_open' => 'OpenNet', 'ssid_owe' => 'OpenNet Secure']);
            $out = $this->build($class)->getConfig(['ssid' => 'kalnet', 'encryption' => 'owe'], $ctx);
            $this->assertIsArray($out, $class.' did not survive a preview');
        }
    }

    public function testOweStillSaysWhichHalfIsHiddenWithoutADevice(): void
    {
        $ctx = $this->context(['ssid_open' => 'OpenNet', 'ssid_owe' => 'OpenNet Secure']);
        $out = $this->build(OweFeatureService::class)
            ->getConfig(['ssid' => 'OpenNet Secure', 'encryption' => 'owe'], $ctx);

        // the option an editor would otherwise find flipped behind their back
        $this->assertSame(1, $out['hidden']);
    }

    public function testTheRegistryKnowsBothNames(): void
    {
        $ipsk = $this->build(IpskFeatureService::class);
        $registry = new FeatureRegistry([$ipsk, $this->build(DefaultFeatureService::class)]);

        // the class name is what the database stores; the short name is what
        // survives a rename
        $this->assertSame($ipsk, $registry->get(IpskFeatureService::class));
        $this->assertSame($ipsk, $registry->get('ipsk'));
        // twenty of the twenty-one rows in the feature table are spelled with
        // a leading backslash; `new $string()` never minded and neither may
        // this
        $this->assertSame($ipsk, $registry->get('\\'.IpskFeatureService::class));
        $this->assertTrue($registry->has('\\'.IpskFeatureService::class));
        $this->assertContains('ipsk', $registry->names());
    }

    public function testAnUnknownImplementationSaysSoInsteadOfCrashing(): void
    {
        $registry = new FeatureRegistry([$this->build(DefaultFeatureService::class)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no such feature implementation');
        $registry->get('ApManBundle\Service\TypoFeatureService');
    }
}
