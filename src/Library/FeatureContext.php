<?php

namespace ApManBundle\Library;

use ApManBundle\Entity\Device;
use ApManBundle\Entity\Feature;
use ApManBundle\Entity\SSID;
use ApManBundle\Entity\SSIDFeatureMap;

/**
 * Everything a feature is allowed to know about the configuration it is
 * changing.
 *
 * This used to be four setters, called in one order by provisioning and a
 * different order by the preview, with no implementation checking what had
 * actually been set. Two of the four values were written into properties no
 * class declared, which PHP 8.2 deprecates and 9.0 refuses, and which is the
 * only reason a subclass could read them at all.
 *
 * The one that mattered is $device. Provisioning has one; the preview does
 * not, because a preview belongs to a network and not to a bss. Nothing said
 * so, so OweFeatureService dereferenced it and every OWE preview came out as
 * "cannot be previewed". Here it is null, in the signature, and a feature that
 * needs a device has to say what it does without one.
 */
final class FeatureContext
{
    public function __construct(
        public readonly SSID $ssid,
        public readonly SSIDFeatureMap $map,
        public readonly Feature $feature,
        /** null while previewing: there is no bss, only a network */
        public readonly ?Device $device = null,
    ) {
    }

    /**
     * The access point this bss runs on, or null when there is no bss.
     *
     * Three implementations walked device → radio → access point themselves,
     * each with its own null checks, and one of them had a check missing.
     */
    public function accessPoint(): ?\ApManBundle\Entity\AccessPoint
    {
        return $this->device?->getRadio()?->getAccessPoint();
    }

    /** What the catalog row carries — shared by every network mapped to it. */
    public function catalog(): array
    {
        $config = $this->feature->getConfig();

        return is_array($config) ? $config : [];
    }
}
