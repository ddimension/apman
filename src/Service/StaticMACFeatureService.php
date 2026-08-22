<?php

namespace ApManBundle\Service;

use ApManBundle\Library\FeatureContext;

/**
 * Give a bss a MAC address of its own and keep it.
 *
 * A bss without an address gets whatever the driver derives from the radio,
 * which changes with the firmware and with the order the interfaces come up —
 * and a bssid that moves takes the neighbour reports and every client's
 * remembered network with it. Assigning one once and storing it makes the
 * address ours.
 *
 * The whole feature is the constraint; it changes no option. The address
 * reaches the configuration through getDeviceConfig(), which copies
 * Device::getAddress() into macaddr — and does so *before* the feature chain
 * runs, so the very first provisioning of a new bss still goes out without it
 * and the address arrives on the next one.
 */
class StaticMACFeatureService extends AbstractFeatureService
{
    public function getName(): string
    {
        return 'static_mac';
    }

    public function applyConstraints(FeatureContext $ctx): void
    {
        $device = $ctx->device;
        if (!$device || !empty($device->getAddress())) {
            return;
        }
        $address = exec($this->kernel->getProjectDir().'/bin/randmac.pl');
        if (!$address) {
            $this->logger->error('StaticMACFeatureService: randmac.pl returned nothing, no address assigned',
                ['device_id' => $device->getId()]);

            return;
        }
        $device->setAddress($address);
        $this->logger->info('StaticMACFeatureService: assigned '.$address.' to '.$device->getName());
        $em = $this->doctrine->getManager();
        $em->persist($device);
        $em->flush();
    }
}
