<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;
use ApManBundle\Entity\SSID;
use ApManBundle\Entity\SsidRadioOptOut;
use ApManBundle\Library\IfnameScheme;

/**
 * Putting a network on a radio, and taking it off again on purpose.
 *
 * A bss is a `Device` row, and until now the only thing that made one was
 * `apman:assign-ssid`: every radio of an access point, unconditionally, no
 * band check, no capability check. Deleting one by hand worked until the next
 * run put it back, because nothing recorded that the deletion was a decision.
 *
 * So a radio has three states here rather than two:
 *
 *     carries       a Device exists
 *     not on purpose an opt-out exists
 *     open          neither, and an assignment run may act on it
 *
 * Nothing this class does reaches an access point. It writes rows; the access
 * point finds out at the next provisioning run, and the page says so.
 */
class RolloutService
{
    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * Where this network stands on every radio of every access point.
     *
     * @return array one entry per access point, radios inside
     */
    public function matrix(SSID $ssid): array
    {
        $em = $this->doctrine->getManager();
        $aps = $em->createQuery('SELECT a,r FROM ApManBundle\\Entity\\AccessPoint a
                LEFT JOIN a.radios r ORDER BY a.name')->getResult();

        $devices = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')
            ->findBy(['ssid' => $ssid]) as $device) {
            if ($device->getRadio()) {
                $devices[$device->getRadio()->getId()] = $device;
            }
        }
        $optOuts = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\SsidRadioOptOut')
            ->findBy(['ssid' => $ssid]) as $out) {
            if ($out->getRadio()) {
                $optOuts[$out->getRadio()->getId()] = $out;
            }
        }

        $rows = [];
        foreach ($aps as $ap) {
            $radiosOfAp = $ap->getRadios()->toArray();
            $radios = [];
            foreach ($radiosOfAp as $radio) {
                $device = $devices[$radio->getId()] ?? null;
                $out = $optOuts[$radio->getId()] ?? null;
                $proposal = $this->proposedIfname($ssid, $radio, $radiosOfAp);
                $radios[] = [
                    'radio' => $radio,
                    'id' => $radio->getId(),
                    'name' => $radio->getName(),
                    'band' => $radio->getConfigBand(),
                    'channel' => $radio->getConfigChannel(),
                    'state' => $device ? 'carries' : ($out ? 'opted out' : 'open'),
                    'device' => $device,
                    'ifname' => $device ? $device->ifname() : null,
                    'opt_out' => $out,
                    'proposed' => $proposal['name'],
                    'why_not' => $proposal['why'],
                ];
            }
            usort($radios, function ($a, $b) {
                return [(string) $a['band'], (string) $a['name']] <=> [(string) $b['band'], (string) $b['name']];
            });
            $rows[] = [
                'ap' => $ap,
                'name' => $ap->getName(),
                'productive' => $ap->getIsProductive(),
                'radios' => $radios,
            ];
        }

        return $rows;
    }

    /**
     * The name a new bss would get, or why it would have none.
     *
     * A network with no short name gets no name from us and is named by the
     * access point instead — which works, and which the page says out loud so
     * that "wlan0-1" is a consequence somebody chose rather than a surprise.
     *
     * @param Radio[] $radiosOfAp
     */
    public function proposedIfname(SSID $ssid, Radio $radio, array $radiosOfAp): array
    {
        $probe = new Device();
        $probe->setRadio($radio);
        $probe->setSSID($ssid);

        return IfnameScheme::forDevice($probe, $radiosOfAp);
    }

    /**
     * Put the network on this radio.
     *
     * Removes an opt-out if there is one — asking for it is the clearer signal
     * of the two, and leaving the note behind would make the next assignment run
     * disagree with the page that just ran.
     */
    public function add(SSID $ssid, Radio $radio): array
    {
        $em = $this->doctrine->getManager();
        $existing = $this->doctrine->getRepository('ApManBundle\\Entity\\Device')
            ->findOneBy(['ssid' => $ssid, 'radio' => $radio]);
        if ($existing) {
            return ['ok' => true, 'note' => 'it was already there', 'device' => $existing->getName()];
        }

        $device = new Device();
        $device->setName(Device::sectionName($radio, $ssid));
        $device->setRadio($radio);
        $device->setSSID($ssid);
        $device->setConfig([]);

        $radiosOfAp = $radio->getAccessPoint() ? $radio->getAccessPoint()->getRadios()->toArray() : [$radio];
        $proposal = IfnameScheme::forDevice($device, $radiosOfAp);
        if ($proposal['name']) {
            $device->setIfname($proposal['name']);
        }
        $device->setAddress($this->freeAddress($radio));

        $em->persist($device);
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\SsidRadioOptOut')
            ->findBy(['ssid' => $ssid, 'radio' => $radio]) as $out) {
            $em->remove($out);
        }
        $em->flush();

        $this->logger->notice('RolloutService: added '.$ssid->getName().' to '
            .($radio->getAccessPoint() ? $radio->getAccessPoint()->getName().'/' : '').$radio->getName()
            .' as '.$device->getName().($proposal['name'] ? ' ('.$proposal['name'].')' : ' (unnamed)'));

        return ['ok' => true, 'device' => $device->getName(), 'ifname' => $proposal['name'],
            'why_no_name' => $proposal['why'], 'address' => $device->getAddress()];
    }

    /**
     * Take it off, and write down that this was meant.
     */
    public function remove(SSID $ssid, Radio $radio, ?string $reason = null): array
    {
        $em = $this->doctrine->getManager();
        $device = $this->doctrine->getRepository('ApManBundle\\Entity\\Device')
            ->findOneBy(['ssid' => $ssid, 'radio' => $radio]);
        $name = $device ? $device->getName() : null;
        if ($device) {
            $em->remove($device);
        }
        $out = $this->doctrine->getRepository('ApManBundle\\Entity\\SsidRadioOptOut')
            ->findOneBy(['ssid' => $ssid, 'radio' => $radio]);
        if (!$out) {
            $out = new SsidRadioOptOut($ssid, $radio, $reason);
            $em->persist($out);
        } elseif (null !== $reason) {
            $out->setReason($reason);
        }
        $em->flush();

        $this->logger->notice('RolloutService: '.$ssid->getName().' will not be on '
            .($radio->getAccessPoint() ? $radio->getAccessPoint()->getName().'/' : '').$radio->getName()
            .($reason ? ' — '.$reason : '').($name ? ', removed '.$name : ''));

        return ['ok' => true, 'removed' => $name, 'reason' => $reason];
    }

    /**
     * Forget the decision, putting the radio back to open.
     */
    public function reopen(SSID $ssid, Radio $radio): array
    {
        $em = $this->doctrine->getManager();
        $count = 0;
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\SsidRadioOptOut')
            ->findBy(['ssid' => $ssid, 'radio' => $radio]) as $out) {
            $em->remove($out);
            ++$count;
        }
        $em->flush();

        return ['ok' => true, 'cleared' => $count];
    }

    /**
     * Is this radio deliberately without this network?
     */
    public function isOptedOut(SSID $ssid, Radio $radio): bool
    {
        return (bool) $this->doctrine->getRepository('ApManBundle\\Entity\\SsidRadioOptOut')
            ->findOneBy(['ssid' => $ssid, 'radio' => $radio]);
    }

    /**
     * An address in the access point's range that nothing else has.
     *
     * Same scheme as apman:renumber-mac: 20:20:<access point id> and three
     * random bytes, so an address says which machine it belongs to. That
     * command shells out to bin/randmac.pl, which cannot be called from a web
     * request with any confidence about the working directory; the arithmetic
     * is three lines.
     */
    public function freeAddress(Radio $radio): string
    {
        $ap = $radio->getAccessPoint();
        $prefix = sprintf('20:20:%02x', $ap ? $ap->getId() % 256 : 0);
        $taken = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')->findAll() as $d) {
            if ($d->getAddress()) {
                $taken[strtolower($d->getAddress())] = true;
            }
        }
        for ($i = 0; $i < 100; ++$i) {
            $mac = $prefix.sprintf(':%02x:%02x:%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255));
            if (!isset($taken[$mac])) {
                return $mac;
            }
        }

        // 100 collisions in a 24 bit space means the space is not what we think
        // it is; say so rather than returning a duplicate.
        throw new \RuntimeException('could not find a free address under '.$prefix);
    }
}
