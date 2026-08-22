<?php

namespace ApManBundle\Service;

use ApManBundle\Library\FeatureContext;

/**
 * What every feature has in common, in one place.
 *
 * There used to be four copies of this: DefaultFeatureService, OweFeatureService,
 * VlanFeatureService and StaticMACFeatureService each carried the same 68 lines
 * of property declarations and setters, byte for byte. Only IpskFeatureService
 * had escaped by extending one of them. Two hundred of those lines are gone.
 *
 * The collaborators are constructor arguments now, not setters, because these
 * are container services again — FeatureRegistry hands them out and nothing
 * builds them from a database string any more. They are protected: the private
 * declarations in the old base class were invisible to the one subclass that
 * existed, which is why it reached for undeclared dynamic properties instead.
 */
abstract class AbstractFeatureService implements iFeatureService
{
    public function __construct(
        protected \Psr\Log\LoggerInterface $logger,
        protected \Doctrine\Persistence\ManagerRegistry $doctrine,
        protected wrtJsonRpc $rpcService,
        protected \ApManBundle\Factory\MqttFactory $mqttFactory,
        protected \Symfony\Component\HttpKernel\KernelInterface $kernel,
    ) {
    }

    /**
     * Nothing to make sure of. Most features only transform.
     */
    public function applyConstraints(FeatureContext $ctx): void
    {
    }

    /**
     * The generic merge: the catalog row written over the configuration.
     *
     * Scalars replace, lists append and are de-duplicated. That asymmetry is
     * deliberate — two features both adding a raw hostapd line should end up
     * with both lines, while two features both setting dtim_period must end up
     * with one value, and the later one wins because priority says so.
     */
    public function getConfig(array $config, FeatureContext $ctx): array
    {
        foreach ($ctx->catalog() as $key => $value) {
            if (!is_array($value)) {
                $config[$key] = $value;
                continue;
            }
            if (!isset($config[$key]) || !is_array($config[$key])) {
                $config[$key] = [];
            }
            foreach ($value as $entry) {
                $config[$key][] = $entry;
            }
            $config[$key] = array_values(array_unique($config[$key]));
        }

        return $config;
    }

    /**
     * No extra sections. Only the vlan feature has any.
     */
    public function getAdditionalConfig(array $config, FeatureContext $ctx): array
    {
        return [];
    }
}
