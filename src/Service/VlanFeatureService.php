<?php

namespace ApManBundle\Service;

use ApManBundle\Library\FeatureContext;

/**
 * Dynamic VLANs, and the wifi-vlan sections that go with them.
 *
 * The only feature that returns whole uci sections rather than options — the
 * $extraConfigs branch in getDeviceConfig() exists for this one.
 *
 * It does not currently work. It was ported to the new contract so the chain
 * compiles and nothing else has to know about the exception, and for no other
 * reason: the shape of what the catalog row must hold is documented nowhere
 * except in a commented-out example, and the guards below throw rather than
 * skip on a row that does not have it. Both are worth fixing when somebody
 * comes back to this.
 */
class VlanFeatureService extends AbstractFeatureService
{
    public function getName(): string
    {
        return 'vlan';
    }

    public function getConfig(array $config, FeatureContext $ctx): array
    {
        // Deliberately not the catalog merge of the base class: here the
        // catalog holds whole sections for getAdditionalConfig(), not options,
        // and merging them into the bss would write nonsense into wifi-iface.
        $config['dynamic_vlan'] = 1;

        return $config;
    }

    /**
     * The catalog row is a list of uci sections; each gets the bss stamped in.
     *
     * Expected shape, which nothing validates:
     *
     *   [{"config":"wireless","type":"wifi-vlan",
     *     "values":{"name":"ops","vid":22,"network":"opennet"}}, ...]
     *
     * "iface" is filled in here, because the catalog is shared by every network
     * mapped to it and cannot know the bss.
     */
    public function getAdditionalConfig(array $config, FeatureContext $ctx): array
    {
        if (!$ctx->device) {
            return [];
        }
        $sections = $ctx->catalog();
        foreach ($sections as $i => $entry) {
            if (!is_array($entry) || !isset($entry['values']) || !is_array($entry['values'])) {
                continue;
            }
            $sections[$i]['values']['iface'] = $ctx->device->getName();
        }

        return $sections;
    }
}
