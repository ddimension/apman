<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;
use ApManBundle\Entity\SSID;
use ApManBundle\Entity\SsidRadioOptOut;
use ApManBundle\Library\IfnameScheme;
use ApManBundle\Library\NodeState;

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
        private readonly ProvisioningService $provisioning,
        private readonly ApUbusService $ubus,
        private readonly StateTreeService $stateTree,
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
     * Whether this network can be changed under running radios, per access point.
     *
     * Three things have to hold, and they are the same three whichever
     * direction the network is moving in — which is why the check is one
     * method and not two.
     *
     * **The radios must be settled.** If a wifi-device option in the database
     * differs from the one on the access point, the next provisioning run takes
     * that phy down whatever this flag says, and the promise is broken on first
     * use rather than later.
     *
     * **Every bss must be named and addressed by us.** A bss the access point
     * names itself, or whose address we left to it, is one whose identity is
     * not pinned by the configuration — and that is the single place where
     * adding a bss to a running radio could differ from starting the radio with
     * it. Measured on ap-av-attic: every bss carries an explicit `bssid=` in the
     * generated configuration and the running addresses match it exactly, so
     * with the addresses set there is no difference at all. Without them there
     * would be.
     *
     * **hostapd must be able to apply a configuration difference.** That is the
     * `config_set` method; `hostapd.uc` hands it the new file and the previous
     * one and hostapd works out what changed.
     *
     * @return array one entry per access point that carries this network
     */
    public function readiness(SSID $ssid): array
    {
        $byAp = [];
        foreach ($this->doctrine->getRepository('ApManBundle\\Entity\\Device')
            ->findBy(['ssid' => $ssid]) as $device) {
            $radio = $device->getRadio();
            $ap = $radio ? $radio->getAccessPoint() : null;
            if (!$ap) {
                continue;
            }
            $byAp[$ap->getName()]['ap'] = $ap;
            $byAp[$ap->getName()]['devices'][] = $device;
        }
        ksort($byAp);

        $out = [];
        foreach ($byAp as $name => $entry) {
            $ap = $entry['ap'];
            $blockers = [];
            $notes = [];

            $unnamed = [];
            $unaddressed = [];
            $radios = [];
            foreach ($entry['devices'] as $device) {
                $radios[(string) $device->getRadio()->getName()] = true;
                if (!$device->ifname()) {
                    $unnamed[] = $device->getName();
                }
                if (!$device->getAddress()) {
                    $unaddressed[] = $device->getName();
                }
            }
            if ($unnamed) {
                $blockers[] = 'named by the access point, not by us: '.implode(', ', $unnamed)
                    .' — give the network a short name so the interface name is ours';
            }
            if ($unaddressed) {
                $blockers[] = 'no address of their own: '.implode(', ', $unaddressed)
                    .' — without one the access point picks the bssid, and then adding the bss '
                    .'while the radio runs is not the same as starting the radio with it';
            }

            // An access point nobody has heard from cannot answer the two
            // questions below, and asking it anyway costs a ten second timeout
            // per question to arrive at "no answer" — which the state tree
            // already knew. Whether that blocks the switch depends on what the
            // access point is: one that is meant to be running and is not is a
            // problem, one that is switched off on purpose is a gap in what we
            // can say and not a reason to refuse.
            $node = $this->stateTree->ap($ap);
            $reachable = !in_array($node['state'], [NodeState::AP_OFFLINE, NodeState::AP_UNKNOWN], true);
            if (!$reachable) {
                $line = 'it is '.$node['state_name']
                    .($node['since'] ? ' since '.date('d.m. H:i', (int) $node['since']) : '')
                    .', so what its radios are running cannot be checked';
                if ($ap->getIsProductive()) {
                    $blockers[] = $line;
                } else {
                    $notes[] = $line.' — it is not productive, so this is a gap and not a fault';
                }
                $out[$name] = [
                    'ap' => $ap,
                    'devices' => count($entry['devices']),
                    'radios' => array_keys($radios),
                    'classify' => ['ok' => false, 'mode' => 'unknown', 'radios' => []],
                    'blockers' => $blockers,
                    'notes' => $notes,
                    'ready' => !$blockers,
                    'reachable' => false,
                ];
                continue;
            }

            $verdict = $this->provisioning->classify($ap);
            if (!($verdict['ok'] ?? false)) {
                $blockers[] = $verdict['error'] ?? 'the access point did not say what its radios are running';
            } else {
                foreach ($verdict['radios'] as $radioName => $radio) {
                    if ('live' === $radio['mode']) {
                        continue;
                    }
                    $carries = isset($radios[$radioName]);
                    $line = $radioName.' is not settled ('.implode('; ', $radio['reasons']).')';
                    if ($carries) {
                        $blockers[] = $line.' — this radio carries the network, so the next change '
                            .'takes it down';
                    } else {
                        $notes[] = $line.' — it does not carry this network, but the next run of '
                            .'anything on this access point restarts it';
                    }
                }
            }

            $capable = $this->canApplyDifference($ap);
            if (false === $capable) {
                $blockers[] = 'hostapd here has no config_set, so it cannot apply a configuration '
                    .'difference and every change restarts the phy';
            } elseif (null === $capable) {
                $notes[] = 'could not ask hostapd whether it can apply a difference';
            }

            $out[$name] = [
                'ap' => $ap,
                'devices' => count($entry['devices']),
                'radios' => array_keys($radios),
                'classify' => $verdict,
                'blockers' => $blockers,
                'notes' => $notes,
                'ready' => !$blockers,
                'reachable' => true,
            ];
        }

        return $out;
    }

    /**
     * Move this network on to running-radio changes, or back off them.
     *
     * The way back is deliberately quiet. Turning the flag off changes nothing
     * on any access point, because both paths write the same uci configuration
     * — the difference between them is only whether the radios are taken down
     * on the way. Restarting the fleet to mark the occasion would cost every
     * dfs radio a fresh ten minute channel availability check and change
     * nothing, so it is not done. What is done is the same readiness check as
     * on the way out, so that leaving this mode does not leave a surprise
     * behind: if something drifted while the network was dynamic, this is where
     * it gets said.
     *
     * @param bool $dryRun report, change nothing
     */
    public function migrate(SSID $ssid, bool $toDynamic, bool $dryRun = false, bool $force = false): array
    {
        $report = [
            'ok' => false,
            'ssid' => $ssid->getName(),
            'from' => $ssid->isDynamic() ? 'dynamic' : 'restart',
            'to' => $toDynamic ? 'dynamic' : 'restart',
            'dry_run' => $dryRun,
            'aps' => $this->readiness($ssid),
        ];
        $report['unchanged'] = $ssid->isDynamic() === $toDynamic;

        $blocking = [];
        foreach ($report['aps'] as $name => $entry) {
            if (!$entry['ready']) {
                $blocking[] = $name;
            }
        }
        $report['blocking'] = $blocking;

        // Only the way out is gated. Going back is always allowed: refusing to
        // leave a mode because of a problem inside it would be a trap.
        if ($toDynamic && $blocking && !$force) {
            $report['error'] = 'not ready on '.implode(', ', $blocking)
                .' — settle those first, or say so explicitly';

            return $report;
        }

        if (!$dryRun) {
            $ssid->setDynamic($toDynamic);
            $this->doctrine->getManager()->flush();
            $this->logger->notice('rollout: '.$ssid->getName().' now changes '
                .($toDynamic
                    ? 'under the running radios — its bss are added and removed without restarting a phy'
                    : 'through a radio restart again')
                .($blocking ? ' (not ready on '.implode(', ', $blocking).', asked for anyway)' : ''));
        }
        $report['ok'] = true;

        return $report;
    }

    /**
     * Whether hostapd on this access point can apply a configuration difference.
     *
     * @return bool|null null when the access point did not answer
     */
    private function canApplyDifference(\ApManBundle\Entity\AccessPoint $ap): ?bool
    {
        $opts = new \stdClass();
        $opts->command = '/bin/ubus';
        $opts->params = ['-v', 'list', 'hostapd'];
        $res = $this->ubus->callCached($ap, 'file', 'exec', $opts, 3600, 10);
        if (!$res || !is_object($res)) {
            return null;
        }
        $stdout = (string) ($res->stdout ?? '');
        if ('' === trim($stdout)) {
            return null;
        }

        return str_contains($stdout, 'config_set');
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
