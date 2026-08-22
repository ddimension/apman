<?php

namespace ApManBundle\Library;

use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;

/**
 * What a bss should be called on the access point.
 *
 * The interface name is a key in five places at once — the ubus object
 * hostapd.<ifname>, the key file /var/run/hostapd-<ifname>.psk, the mqtt status
 * topic, the owe_transition_ifname a partner bss names, and the neighbour
 * reports. So it has to be stable, and it has to say something.
 *
 * The names in the field today are neither. They are built as
 * <prefix><abbreviation><radio index>, and the radio index is the order the
 * radios happen to enumerate in: kalclients is wap-kc1 on ap-av-attic, where
 * radio1 is the 5 GHz radio, and wap-kc1 on ap-av-grwz, where radio0 is 2.4 GHz
 * and wap-kc1 is the 2.4 GHz bss. The same name means a different band
 * depending on the machine, and the band you want is not in the name at all.
 *
 * So: the band, and never the enumeration.
 *
 *     wap-kc-5g       kalclients, 5 GHz          9 characters
 *     wap-onets-2g    OpenNet Secure, 2.4 GHz   12
 *     wap-kinfra-6g   kalinfra, 6 GHz           13
 *
 * The one case the band does not settle is two radios of the same band on one
 * access point — ap-av-klwz has two on 5 GHz. Only then a running number, and
 * it is ordered by the radio's uci `path`, which is where the hardware sits and
 * does not change, rather than by the radio name, which is the thing that moved
 * in the first place.
 */
final class IfnameScheme
{
    /**
     * IFNAMSIZ is 16 including the terminating NUL.
     *
     * Not a style rule: the kernel refuses a longer name, the uci add fails,
     * and because a provisioning run is one transaction it takes the whole
     * access point with it.
     */
    public const MAX_LENGTH = 15;

    public const PREFIX = 'wap-';

    /** what a name may be made of once it reaches a file path and an mqtt topic */
    public const CHARSET = '/^[a-z0-9-]+$/';

    /**
     * Build a name from its parts.
     *
     * @param string $slug     the network's short name, lower case
     * @param string $band     as uci spells it: 2g, 5g, 6g, 60g
     * @param int    $ordinal  1 for the only radio of this band, 2 upwards otherwise
     */
    public static function build(string $slug, string $band, int $ordinal = 1): string
    {
        $suffix = '-'.$band.($ordinal > 1 ? (string) $ordinal : '');

        return self::PREFIX.$slug.$suffix;
    }

    /**
     * Why this name cannot be used, or null if it can.
     */
    public static function reject(string $name): ?string
    {
        if ('' === $name) {
            return 'empty';
        }
        if (strlen($name) > self::MAX_LENGTH) {
            return 'longer than '.self::MAX_LENGTH.' characters ('.strlen($name).')';
        }
        if (!preg_match(self::CHARSET, $name)) {
            return 'contains something other than a-z, 0-9 and -';
        }

        return null;
    }

    /**
     * The longest slug that still fits for this band.
     */
    public static function slugBudget(string $band, int $ordinal = 1): int
    {
        return self::MAX_LENGTH - strlen(self::build('', $band, $ordinal));
    }

    /**
     * A slug proposal, read out of the name a bss already has.
     *
     * The abbreviations in the field were chosen by somebody and are the part
     * worth keeping: wap-onets0 was meant to say "OpenNet Secure" and does.
     * Only the prefix and the radio index come off. Returns null when there is
     * nothing to read, which is the honest answer for a bss that has never had
     * a name.
     */
    public static function slugFrom(?string $existing): ?string
    {
        $name = strtolower(trim((string) $existing));
        if ('' === $name) {
            return null;
        }
        if (str_starts_with($name, self::PREFIX)) {
            $name = substr($name, strlen(self::PREFIX));
        }
        // the trailing radio index, which is the thing being replaced
        $name = rtrim($name, '0123456789');
        $name = trim($name, '-_');
        $name = preg_replace('/[^a-z0-9]/', '', $name);

        return '' === $name ? null : $name;
    }

    /**
     * The name for one bss, given every radio of its access point.
     *
     * The radios are needed for the ordinal: whether this radio is the only one
     * of its band on the machine can only be answered by looking at the others.
     *
     * @param Radio[] $radiosOfAp
     *
     * @return array{name: ?string, why: ?string} the name, or why there is none
     */
    public static function forDevice(Device $device, array $radiosOfAp, ?string $slug = null): array
    {
        $radio = $device->getRadio();
        if (!$radio) {
            return ['name' => null, 'why' => 'the bss is on no radio'];
        }
        $band = strtolower((string) $radio->getConfigBand());
        if ('' === $band) {
            return ['name' => null, 'why' => 'the radio has no band'];
        }
        $slug = $slug ?? self::slugFrom($device->getIfname()) ?? self::slugFrom($device->getIfnameSeen());
        if (null === $slug) {
            return ['name' => null, 'why' => 'no short name for '
                .($device->getSsid() ? $device->getSsid()->getName() : 'this network')
                .', and none to read out of an existing interface name'];
        }

        $name = self::build($slug, $band, self::ordinalOf($radio, $radiosOfAp));
        $why = self::reject($name);

        return $why ? ['name' => null, 'why' => $name.': '.$why] : ['name' => $name, 'why' => null];
    }

    /**
     * Which radio of this band this is, counted in a stable order.
     *
     * By uci `path` — the hardware topology, which survives a reboot and a
     * firmware upgrade. Counting by radio name would put the enumeration back
     * into the name through the side door.
     *
     * @param Radio[] $radiosOfAp
     */
    public static function ordinalOf(Radio $radio, array $radiosOfAp): int
    {
        $band = strtolower((string) $radio->getConfigBand());
        $sameBand = [];
        foreach ($radiosOfAp as $other) {
            if (strtolower((string) $other->getConfigBand()) === $band) {
                $sameBand[] = $other;
            }
        }
        if (count($sameBand) < 2) {
            return 1;
        }
        usort($sameBand, function (Radio $a, Radio $b) {
            return [(string) $a->getConfigPath(), (string) $a->getName()]
                <=> [(string) $b->getConfigPath(), (string) $b->getName()];
        });
        foreach ($sameBand as $i => $other) {
            if ($other->getName() === $radio->getName()) {
                return $i + 1;
            }
        }

        return 1;
    }
}
