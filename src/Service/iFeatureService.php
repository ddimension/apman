<?php

namespace ApManBundle\Service;

use ApManBundle\Library\FeatureContext;

/**
 * One step in the chain that turns a network's options into a bss configuration.
 *
 * Every SSIDFeatureMap of a network names one of these, and they run in
 * priority order over the accumulated configuration on the way to an access
 * point — see AccessPointService::getDeviceConfig(). Each may add, change or
 * remove options, and each sees what the ones before it did.
 *
 * The split between the two halves is the part worth reading twice:
 *
 *   getConfig() is pure. It reads the configuration and the context and
 *   returns a new configuration. It must not persist, must not publish, and
 *   must not mind a context without a device — the network page previews the
 *   whole chain by calling exactly this, and a feature that cannot be previewed
 *   is a feature whose effect nobody can see before it reaches the fleet.
 *
 *   applyConstraints() is where writing is allowed. It runs only on the
 *   provisioning path, once per bss, and may assign what does not exist yet.
 *   It runs before getConfig() and cannot see the configuration.
 *
 * Implementations extend AbstractFeatureService, which brings the collaborators
 * and the catalog merge. The class name is what the database stores in
 * feature.implementation, and FeatureRegistry resolves it — nothing constructs
 * these with new.
 */
interface iFeatureService
{
    /**
     * A stable short name for this feature, independent of the class name.
     *
     * Used as the second key in FeatureRegistry and shown in the admin, so a
     * class can be renamed without a database migration.
     */
    public function getName(): string;

    /**
     * Make sure what this feature needs exists — the only place that may write.
     *
     * Never called while previewing. $ctx->device is therefore always set here.
     */
    public function applyConstraints(FeatureContext $ctx): void;

    /**
     * This feature's effect on the configuration.
     *
     * Pure: same input, same output, no side effects. $ctx->device may be null.
     *
     * @param array $config what the network and the features before this one made of it
     *
     * @return array the configuration as this feature leaves it
     */
    public function getConfig(array $config, FeatureContext $ctx): array;

    /**
     * Whole uci sections to write alongside the wifi-iface — not options.
     *
     * Each entry is a uci "add" payload of its own; the caller stamps no name,
     * so an implementation returning these owns their section names. Most
     * features have none and return an empty array.
     *
     * @param array $config the configuration after getConfig() has run
     */
    public function getAdditionalConfig(array $config, FeatureContext $ctx): array;
}
