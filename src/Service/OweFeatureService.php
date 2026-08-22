<?php

namespace ApManBundle\Service;

use ApManBundle\Library\FeatureContext;

/**
 * Opportunistic Wireless Encryption, in transition mode.
 *
 * OWE is encryption without a passphrase, and a client that does not know it
 * sees nothing. Transition mode is the answer: two bsses, one open and one
 * OWE, each naming the other, so an old client joins the open one and a new
 * one is moved silently to the encrypted twin. The pairing is the whole
 * feature — an owe_transition_ifname that names nothing turns the encrypted
 * half into a hidden network nobody finds.
 */
class OweFeatureService extends AbstractFeatureService
{
    public function getName(): string
    {
        return 'owe';
    }

    public function getConfig(array $config, FeatureContext $ctx): array
    {
        $fcfg = $ctx->catalog();
        if (!isset($fcfg['ssid_open'])) {
            $this->logger->error('OweFeatureService:getConfig(): No ssid_open config entry.');

            return $config;
        }
        if (!isset($fcfg['ssid_owe'])) {
            $this->logger->error('OweFeatureService:getConfig(): No ssid_owe config entry.');

            return $config;
        }

        if (!isset($config['encryption'])) {
            $this->logger->error('OweFeatureService:getConfig(): No encryption in device config.');

            return $config;
        }

        $encryption = strtolower($config['encryption']);
        if ('owe' == $encryption) {
            $other_ssid_name = $fcfg['ssid_open'];
            $config['hidden'] = 1;
        } else {
            $other_ssid_name = $fcfg['ssid_owe'];
        }

        // Naming the partner needs a radio, and a radio needs a bss. The
        // network page previews the chain without one, and that is legitimate:
        // what it can say is which half is hidden, which is the option an
        // editor would otherwise find flipped behind their back. The pairing
        // itself belongs to a bss and is shown when there is one.
        if (!$ctx->device) {
            return $config;
        }

        // get other SSID
        $em = $this->doctrine->getManager();
        $query = $em->createQuery(
            'SELECT c FROM ApManBundle\Entity\SSIDConfigOption c
		LEFT JOIN c.ssid s
		WHERE
		c.name = :ssid AND c.value = :ssid_name'
        );
        $query->setParameter('ssid', 'ssid');
        $query->setParameter('ssid_name', $other_ssid_name);
        try {
            $other_ssid_config = $query->getSingleResult();
        } catch (\Doctrine\ORM\NoResultException $e) {
            $this->logger->error('OweFeatureService:getConfig(): SSID '.$other_ssid_name.' not found.');

            return $config;
        } catch (\Doctrine\ORM\NonUniqueResultException $e) {
            // two networks broadcasting the same name: which of them is the
            // partner is not ours to guess
            $this->logger->error('OweFeatureService:getConfig(): more than one network broadcasts '.$other_ssid_name.'.');

            return $config;
        }
        $other_ssid = $other_ssid_config->getSSID();

        //echo "SSID: ".$config['ssid']."\n";
        //echo "Other SSID: ".$other_ssid_name."\n";
        $query = $em->createQuery(
            'SELECT d
			FROM ApManBundle\Entity\Device d
			WHERE d.ssid = :ssid AND d.radio = :radio'
        );
        $query->setParameter('ssid', $other_ssid);
        $query->setParameter('radio', $ctx->device->getRadio());
        try {
            $other_device = $query->getSingleResult();
        } catch (\Doctrine\ORM\NonUniqueResultException $e) {
            $this->logger->error('OweFeatureService:getConfig(): more than one bss of '.$other_ssid_name.' on this radio.');

            return $config;
        } catch (\Doctrine\ORM\NoResultException $e) {
            $this->logger->error('OweFeatureService:getConfig(): No device found for SSID '.$other_ssid_name.' and radio '.$ctx->device->getRadio()->getName());

            return $config;
        }

        // The name provisioning asks for, not the one currently running. This
        // goes into a configuration that names the partner's intended name in
        // the same breath, and both halves of the pair are written in one run —
        // pointing at what the partner is called right now would be wrong for
        // exactly the run that renames it.
        if (strlen((string) $other_device->getIfname())) {
            $config['owe_transition_ifname'] = $other_device->getIfname();
        } elseif (strlen((string) $other_device->getAddress())) {
            $config['owe_transition_ssid'] = $other_ssid_name;
            $config['owe_transition_bssid'] = $other_device->getAddress();
        } else {
            $this->logger->error('OweFeatureService:getConfig(): Failed to get other device ifname or address.');
        }

        return $config;
    }

    public function applyConstraints(FeatureContext $ctx): void
    {
        if (!$ctx->device) {
            return;
        }
        $em = $this->doctrine->getManager();
        $query = $em->createQuery(
            'SELECT m
			FROM ApManBundle\Entity\SSIDFeatureMap m
			WHERE m.feature = :feature
			AND m.id != :mapid'
        );
        $query->setParameter('feature', $ctx->feature);
        $query->setParameter('mapid', $ctx->map->getId());
        $maps = $query->getResult();
        if (!count($maps)) {
            $this->logger->info('OweFeatureService: no partner mapping for this feature, creating one');
            $this->setupOweSsid($ctx);
        }
    }

    /**
     * Build the encrypted twin of an open network, once.
     *
     * This clones a network, its options and a bss per radio, and assigns
     * addresses — from a provisioning run, which is not where that belongs. It
     * is left as it is on purpose: moving it into a command of its own is a
     * change with its own risks and its own decision.
     */
    private function setupOweSsid(FeatureContext $ctx)
    {
        $em = $this->doctrine->getManager();
        $open_ssid = $ctx->device->getSsid();
        $owe_ssid = clone $open_ssid;
        $owe_ssid->setName($open_ssid->getName().' Secure');
        $em->persist($owe_ssid);
        $map = new \ApManBundle\Entity\SSIDFeatureMap();
        $map->setSsid($owe_ssid);
        $map->setFeature($ctx->feature);
        $map->setPriority(2);
        $map->setConfig(['owe' => true]);
        $map->setName($ctx->map->getName().' OWE');
        $em->persist($map);
        $em->flush();
        foreach ($open_ssid->getConfigOptions() as $option) {
            $new = clone $option;
            $new->setSsid($owe_ssid);
            if ('ssid' == $new->getName()) {
                $new->setValue($new->getValue().' Secure');
            }
            if ('encryption' == $new->getName()) {
                $new->setValue('owe');
            }
            if ('ifname' == $new->getName()) {
                $new->setValue($new->getValue().'o');
            }
            $owe_ssid->addConfigOption($new);
            $em->persist($new);
        }
        $devmap = [];
        $devmapr = [];
        foreach ($open_ssid->getDevices() as $md) {
            $radio = $md->getRadio();
            $device = new \ApManBundle\Entity\Device();
            $device->setName(\ApManBundle\Entity\Device::sectionName($radio, $owe_ssid));
            $device->setRadio($radio);
            $device->setSSID($owe_ssid);
            $device->setAddress(exec($this->kernel->getProjectDir().'/bin/randmac.pl'));
            $md->setAddress(exec($this->kernel->getProjectDir().'/bin/randmac.pl'));
            $device->setIfname('w-r'.$radio->getId().'-s'.$owe_ssid->getId());
            $em->persist($device);
            $em->persist($md);
            $em->flush();
            $devmap[$md->getId()] = $device->getId();
            $devmapr[$device->getId()] = $md->getId();

            $this->logger->info('OweFeatureService:applyConstraints(): Added Device '.$device->getName().' for SSID '.$owe_ssid->getName());
        }
        $ctx->map->setConfig(['owe' => false, 'devmap' => $devmap]);
        $map->setConfig(['owe' => true, 'devmap' => $devmapr]);
        $em->persist($ctx->map);
        $em->flush();
        $this->logger->info('OweFeatureService:applyConstraints(): cloned ssid.');
    }

}
