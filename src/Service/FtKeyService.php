<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\SSID;
use ApManBundle\Entity\SSIDConfigList;
use ApManBundle\Entity\SSIDConfigListOption;

/**
 * The 802.11r key a network's access points have to agree on.
 *
 * ## Why a network needs one at all
 *
 * Left to itself, ap.uc derives the FT key from
 * `md5(mobility_domain + '/' + auth_secret)`. On a network whose keys come from
 * RADIUS that is fatal, because `auth_secret` is the **per access point** RADIUS
 * secret: every access point derives a different key and no transition can ever
 * be validated. `docs/ipsk-test.md` calls r0kh/r1kh mandatory for exactly this
 * reason.
 *
 * ## Why iPSK cannot use ft_psk_generate_local
 *
 * With `ft_psk_generate_local=1` the target of a roam derives PMK-R0 itself,
 * from the pre-shared key it holds. That works when the whole network shares
 * one passphrase — kalinfra does, and is right to keep it — and cannot work
 * when every station has its own key: the target has not seen this station yet
 * and holds nothing to derive from. The key has to come over the R0KH/R1KH
 * protocol instead, which is what `ft_psk_generate_local=0` selects, and that
 * protocol needs the shared key above.
 *
 * So the two belong together and are switched together. Setting the flag
 * without a key would replace one broken roam with another.
 *
 * ## The wildcard form
 *
 * One key for the whole mobility domain, not a table of access point pairs:
 *
 *     r0kh=ff:ff:ff:ff:ff:ff,*,<key>
 *     r1kh=00:00:00:00:00:00,00:00:00:00:00:00,<key>
 *
 * That is what kalclients has carried since the FT tests of 2026-08-21, and it
 * is what the procedure in docs/ipsk-test.md writes. A per-pair table would
 * have to be rebuilt every time an access point joins or leaves; a wildcard
 * entry does not, and the thing it gives up — telling access points apart at
 * the FT layer — is not something this fleet uses.
 *
 * The key lives in the SSID's config lists, which is where a hand-written one
 * already lives, so it is one mechanism rather than two, it shows up in the
 * admin under Roaming, and it reaches every access point of the network through
 * the ordinary provisioning path. That last part is the whole point: a key that
 * is not identical everywhere is worse than none.
 */
class FtKeyService
{
    /** any R0KH, any NAS identifier, this key */
    public const R0KH = 'ff:ff:ff:ff:ff:ff,*,%s';

    /** any R1KH, any MAC, this key */
    public const R1KH = '00:00:00:00:00:00,00:00:00:00:00:00,%s';

    /** 32 bytes, the size hostapd wants for an FT key */
    public const BYTES = 32;

    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * The key this network is configured with, or null.
     *
     * Read from r0kh, because that is the entry hostapd validates against.
     */
    public function keyOf(SSID $ssid): ?string
    {
        foreach ($ssid->getConfigLists() as $list) {
            if ('r0kh' !== $list->getName()) {
                continue;
            }
            foreach ($list->getOptions() as $option) {
                $parts = explode(',', (string) $option->getValue());
                $key = trim($parts[2] ?? '');
                if ('' !== $key) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Give this network a key, or replace the one it has.
     *
     * Returns [key, created, rotated]. Nothing is written when a key is already
     * there and no rotation was asked for — an existing key is somebody's
     * working roaming and must not be replaced by accident.
     */
    public function ensure(SSID $ssid, bool $rotate = false): array
    {
        $existing = $this->keyOf($ssid);
        if (null !== $existing && !$rotate) {
            return ['key' => $existing, 'created' => false, 'rotated' => false];
        }

        $key = bin2hex(random_bytes(self::BYTES));
        $this->writeList($ssid, 'r0kh', sprintf(self::R0KH, $key));
        $this->writeList($ssid, 'r1kh', sprintf(self::R1KH, $key));
        $this->doctrine->getManager()->flush();

        $this->logger->notice('FtKeyService: '.$ssid->getName().' '
            .(null === $existing ? 'given an FT key' : 'FT key rotated')
            .' — every access point of this network has to be provisioned before '
            .'roaming works again');

        return ['key' => $key, 'created' => null === $existing, 'rotated' => null !== $existing];
    }

    /**
     * Replace one list's contents with a single value.
     *
     * The list is emptied first rather than appended to: uci's add_list appends
     * too, and a network that accumulated two r0kh entries would hand hostapd
     * two keys for the same wildcard and validate against whichever it tried
     * first.
     */
    private function writeList(SSID $ssid, string $name, string $value): void
    {
        $em = $this->doctrine->getManager();
        $list = null;
        foreach ($ssid->getConfigLists() as $candidate) {
            if ($name === $candidate->getName()) {
                $list = $candidate;
                break;
            }
        }
        if (null === $list) {
            $list = new SSIDConfigList();
            $list->setName($name);
            $list->setSSID($ssid);
            $em->persist($list);
        } else {
            foreach ($list->getOptions() as $option) {
                $em->remove($option);
            }
        }

        $entry = new SSIDConfigListOption();
        $entry->setSSIDConfigList($list);
        $entry->setValue($value);
        $em->persist($entry);
    }

    /**
     * Is fast transition on, as the configuration will reach the device.
     *
     * ieee80211r comes from a feature rather than the network itself on every
     * network in this fleet, so the answer is only correct against the
     * configuration after the features have run.
     */
    public function ftEnabled(array $config): bool
    {
        return in_array((string) ($config['ieee80211r'] ?? ''), ['1', 'true', 'on'], true);
    }
}
