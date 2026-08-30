<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Controller\DefaultController;
use PHPUnit\Framework\TestCase;

/**
 * What can be said about sae_pwe before hostapd reports the RSNXE itself.
 *
 * Patch 816 puts the element's bytes into STA info, and until that build is on
 * the access points the only thing known is whether the station announced the
 * element at all. That is worth saying, because it is not symmetric: a station
 * that sends no RSNXE has no hash-to-element to offer, so with sae_pwe=2 it is
 * on hunting-and-pecking and can be nothing else. The other direction is the
 * weaker one and the wording says so.
 *
 * The negative branch has no live example on this fleet — every station seen so
 * far announces the element — which is exactly why it is pinned here.
 */
class SaePweFromSignatureTest extends TestCase
{
    private function derive(?array $security, $taxonomy): ?array
    {
        $c = (new \ReflectionClass(DefaultController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(DefaultController::class, 'withRsnxeFromSignature');
        $m->setAccessible(true);

        return $m->invoke($c, $security, $taxonomy);
    }

    public function testAnnouncedElementMeansHashToElement(): void
    {
        $out = $this->derive(['AKM' => 'FT-SAE'], ['elements' => ['SSID', 'RSN', 'RSNX']]);
        $this->assertStringContainsString('hash-to-element', $out['SAE PWE']);
    }

    public function testNoElementMeansHuntingAndPecking(): void
    {
        $out = $this->derive(['AKM' => 'SAE'], ['elements' => ['SSID', 'RSN', 'HT_CAP']]);
        $this->assertStringContainsString('hunting-and-pecking', $out['SAE PWE']);
    }

    public function testNothingIsSaidAboutAnAssociationThatDidNotUseSae(): void
    {
        foreach (['PSK', 'OWE', 'FT-PSK', ''] as $akm) {
            $out = $this->derive(['AKM' => $akm], ['elements' => ['RSNX']]);
            $this->assertArrayNotHasKey('SAE PWE', $out,
                '"hunting-and-pecking" about a '.($akm ?: 'nameless').' association is wrong, not vague');
        }
    }

    public function testTheRealBitsWin(): void
    {
        $out = $this->derive(
            ['AKM' => 'SAE', 'RSN extensions' => 'SAE H2E, SAE-PK'],
            ['elements' => []]);
        $this->assertArrayNotHasKey('SAE PWE', $out);
        $this->assertSame('SAE H2E, SAE-PK', $out['RSN extensions']);
    }

    public function testNoTaxonomyAndNoSecurityAreBothSurvivable(): void
    {
        $this->assertNull($this->derive(null, ['elements' => ['RSNX']]));
        $this->assertArrayNotHasKey('SAE PWE', $this->derive(['AKM' => 'SAE'], null));
    }
}
