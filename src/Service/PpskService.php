<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Ppsk;

/**
 * Per device pre shared keys, with the controller as the point of truth.
 *
 * Two paths, deliberately separate:
 *
 *  - persistence: uci wifi-station sections, rendered together with the rest of
 *    the wireless config (see AccessPointService::publishConfig). They survive a
 *    reboot and are what regenerates the runtime file on every wifi reload.
 *  - immediacy: the runtime wpa_psk_file plus hostapd's RELOAD_WPA_PSK, which
 *    re-reads the file and kicks only stations whose key no longer matches.
 *    Adding a key is therefore not disruptive, and revoking one hits exactly
 *    the device it belongs to.
 *
 * Both together mean a device enrolled on one access point can roam to any
 * other one seconds later.
 */
class PpskService
{
    /**
     * How long a station that lost its key is kept out. hostapd caches the
     * RADIUS answer for roughly half a minute; the ban has to outlast that or
     * the station is let back in with the key that was just withdrawn.
     */
    public const KICK_BAN_MS = 45000;


    private $logger;
    private $doctrine;
    private $rpcService;
    private $mqttFactory;
    private $cacheFactory;
    private $stateTree;
    /** ssid id => ['at' => …, 'auto' => bool, 'moving' => bool]; see managesOwnKeys() */
    private $flagCache = [];

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Factory\CacheFactory $cacheFactory,
        StateTreeService $stateTree
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
        $this->stateTree = $stateTree;
    }

    /**
     * @return Ppsk[]
     */
    public function getForSsid($ssid)
    {
        return $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->findBy(
            ['ssid' => $ssid, 'enabled' => true],
            ['mac' => 'ASC']
        );
    }

    /**
     * uci wifi-station sections for one device, ready for 'uci add'.
     * This is the persistent half; wifi-scripts renders the psk file from it.
     */
    public function getStationSections(\ApManBundle\Entity\Device $device)
    {
        $sections = [];
        $ssid = $device->getSsid();
        if (!$ssid) {
            return $sections;
        }
        foreach ($this->getForSsid($ssid) as $i => $ppsk) {
            if (!$ppsk->isValid()) {
                $this->logger->warning('PpskService: skipping invalid key '.$ppsk->getId());
                continue;
            }
            $values = [
                'iface' => [$device->getName()],
                'mac' => [$ppsk->getMac()],
                'key' => $ppsk->getPsk(),
            ];
            if (null !== $ppsk->getVid()) {
                $values['vid'] = (string) $ppsk->getVid();
            }
            $section = new \stdClass();
            $section->config = 'wireless';
            $section->type = 'wifi-station';
            $section->name = 'ppsk_'.$device->getName().'_'.$ppsk->getId();
            $section->values = $values;
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Map a uci wifi-station section name back to its ppsk row.
     *
     * getStationSections() names them ppsk_<device>_<id>, the keystore
     * ppsk_<ssid>_<id>; the row id is the trailing segment. The device part cannot be trusted across renames, the
     * id can. The ssid name is a cross-check: a section that uses our naming
     * scheme must also belong to the ssid it answers for — anything else is a
     * leftover from a rename or a rogue.
     */
    public function resolveBySectionName($sectionName, $ssidName = null)
    {
        if (!preg_match('/^ppsk_.+_(\d+)$/', (string) $sectionName, $m)) {
            return null;
        }
        $ppsk = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->find((int) $m[1]);
        if (!$ppsk) {
            return null;
        }
        if (null !== $ssidName && '' !== $ssidName
            && $ppsk->getSsid() && $ppsk->getSsid()->getName() !== $ssidName) {
            $this->logger->warning('PpskService: section '.$sectionName.' answered for ssid '.
                $ssidName.' but belongs to '.$ppsk->getSsid()->getName());

            return null;
        }

        return $ppsk;
    }

    /**
     * How a key of this SSID reaches the clients.
     *
     * PSK keys live in wpa_psk_file, which hostapd re-reads on demand — a new
     * key works within seconds. SAE passwords live in sae_password_file, and
     * hostapd only parses that when it reads its whole configuration: only
     * RELOAD_CONFIG picks it up, and that one throws every client of the radio
     * off (measured). So an SAE key is persisted through the uci wifi-station
     * sections — wifi-scripts renders both files from them — and becomes
     * effective at the next wireless reload, not immediately.
     *
     * @return array ['psk' => bool, 'sae' => bool]
     */
    public function keyDelivery($ssid)
    {
        $config = $ssid->exportConfig();
        $encryption = strtolower((string) ($config->encryption ?? ''));
        $sae = (bool) preg_match('/sae|wpa3/', $encryption);
        // psk2, psk-mixed, … and the mixed modes, which offer both
        $psk = (bool) preg_match('/psk|wpa2/', $encryption)
            || (bool) preg_match('/(sae|wpa3)-mixed/', $encryption);

        return ['psk' => $psk, 'sae' => $sae];
    }

    /**
     * Whether this network manages its own keys, read from the database rather
     * than from the entity.
     *
     * The subscriber runs for weeks and Doctrine hands out whatever it
     * hydrated the first time, so a switch flipped in the interface would only
     * take effect after a restart — which is not what a switch is. Ten seconds
     * of caching keeps this off the hot path without making it stale enough to
     * notice.
     */
    public function managesOwnKeys($ssid)
    {
        $id = $ssid->getId();
        if (isset($this->flagCache[$id]) && (time() - $this->flagCache[$id]['at']) < 10) {
            return $this->flagCache[$id]['auto'];
        }
        try {
            $row = $this->doctrine->getManager()->getConnection()->fetchAssociative(
                'SELECT auto_ppsk, moving_psk FROM ssid WHERE id = :id', ['id' => $id]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('PpskService: could not read the key flags of '.$id.': '.$e->getMessage());

            return false;
        }
        $this->flagCache[$id] = [
            'at' => time(),
            'auto' => (bool) ($row['auto_ppsk'] ?? false),
            'moving' => (bool) ($row['moving_psk'] ?? false),
        ];

        return $this->flagCache[$id]['auto'];
    }

    /**
     * A station came in on a key that is bound to no address — give it one of
     * its own, carrying the same passphrase.
     *
     * The device notices nothing: same secret, same connection. What changes is
     * that it stops being anonymous. From the next association on, the access
     * points report which identity it used, the key can be withdrawn for this
     * one device, and the shared key it came in on can be rotated without
     * taking the device with it.
     *
     * The keyid is the address without separators, so the identity of a learned
     * key can be read off the station itself.
     *
     * @return \ApManBundle\Entity\Ppsk|null the new key, null when there was
     *                                      nothing to learn
     */
    public function learnFromShared(\ApManBundle\Entity\Ppsk $shared, $mac)
    {
        $ssid = $shared->getSsid();
        $mac = strtolower((string) $mac);
        // the learn belongs to networks that hand out per-station keys — the
        // classic auto_ppsk marker or the iPSK feature (on-AP radius)
        if (!$ssid || !($this->managesOwnKeys($ssid) || $this->usesOnApRadius($ssid))) {
            return null;
        }
        if (\ApManBundle\Entity\Ppsk::ANY_MAC !== $shared->getMac()) {
            return null;
        }
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
            return null;
        }

        $em = $this->doctrine->getManager();
        $repo = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk');
        // a registration key belongs to the enrolment that issued it; pinning
        // is its job, not ours
        if ($shared->isRegistration()) {
            return null;
        }
        // A device that already has a key in service has an identity — nothing
        // to learn. A withdrawn one does not: it is connecting on the shared
        // key right now, and giving it an identity is what makes it possible to
        // withdraw it properly rather than leaving it anonymous.
        if ($repo->findOneBy(['ssid' => $ssid, 'mac' => $mac, 'enabled' => true])) {
            return null;
        }
        // the same passphrase for the same address already exists — that row is
        // the identity, it was only switched off
        if ($repo->findOneBy(['ssid' => $ssid, 'mac' => $mac, 'psk' => $shared->getPsk()])) {
            return null;
        }

        $entry = new \ApManBundle\Entity\Ppsk();
        $entry->setSsid($ssid);
        $entry->setMac($mac);
        $entry->setPsk($shared->getPsk());
        $entry->setSource(\ApManBundle\Entity\Ppsk::SOURCE_AUTO);
        $entry->setPinMac(false);
        $entry->setName($this->nameForStation($mac, []));
        $entry->setComment('learned from '.($shared->getKeyid() ?: 'a shared key')
            .' — same passphrase, now an identity of its own');
        $em->persist($entry);
        $em->flush();
        $entry->setKeyid(str_replace(':', '', $mac));
        $em->flush();

        $this->logger->notice('PpskService: learned '.$entry->getKeyid().' on '.$ssid->getName()
            .' from '.($shared->getKeyid() ?: 'a shared key'));

        return $entry;
    }

    /**
     * Open an enrolment: hand out one key, for one device, exclusively.
     *
     * Three things happen together, and the order is the point:
     *
     *  1. Every station connected right now is converted to a key of its own,
     *     so that step 2 cannot lock anybody out.
     *  2. The shared keys step aside. For the length of the enrolment the
     *     network accepts nothing from an unknown device except what was just
     *     issued — which is what makes it an enrolment rather than another copy
     *     of a shared secret.
     *  3. A fresh key is issued, bound to no address yet and marked to pin
     *     itself to the first device that uses it. When that happens the
     *     displaced keys go back into service.
     *
     * The controller's own RADIUS server is what makes this practical: it
     * answers from the database, so the new key works the moment it exists,
     * while the psk files are still being written and reloaded.
     *
     * @return array ['key' => Ppsk, 'converted' => …, 'suspended' => […]]
     */
    public function startRegistration($ssid, $name, $vid = null)
    {
        if (!$this->managesOwnKeys($ssid)) {
            return ['error' => 'this network does not manage its own keys — '
                .'switch on automatic per device keys first'];
        }

        $em = $this->doctrine->getManager();
        $converted = $this->convertConnected($ssid, true);

        $suspended = [];
        foreach ($this->getForSsid($ssid) as $key) {
            if (\ApManBundle\Entity\Ppsk::ANY_MAC !== $key->getMac() || $key->isRegistration()) {
                continue;
            }
            $key->setEnabled(false);
            $suspended[] = $key->getId();
        }
        $em->flush();

        // A moving key does not pin itself and nothing is put back after it:
        // it *is* the network's shared secret from here on, and the device
        // being enrolled gets its own copy the moment it connects, the same way
        // every other device on this network did. The next enrolment replaces
        // it again.
        // read through the same fresh path as the flag above it
        $this->managesOwnKeys($ssid);
        $moving = $this->flagCache[$ssid->getId()]['moving'] ?? $ssid->getMovingPsk();
        $entry = $this->createIpsk($ssid, $name, $vid, !$moving);

        if ($moving) {
            $entry->setComment('shared key of this network, issued for the enrolment of '
                .$name.' — replaces '.(count($suspended) ?: 'nothing').', and is replaced by the next one');
            $em->flush();
            $rotated = $this->setNetworkKey($ssid, $entry->getPsk());
        } elseif ($suspended) {
            // one row can only point at one; the rest are named in the comment
            // so a half finished enrolment can still be untangled by hand
            $entry->setRestoresId($suspended[0]);
            $entry->setComment('registration key — displaced '.implode(', ', $suspended));
            $em->flush();
            $rotated = false;
        } else {
            $rotated = false;
        }

        $this->logger->notice('PpskService: registration open on '.$ssid->getName()
            .' with '.$entry->getKeyid().', '.count($suspended).' shared key(s) '
            .($moving ? 'replaced' : 'suspended').', '
            .count($converted['created'] ?? []).' station(s) converted');

        return [
            'key' => $entry,
            'converted' => $converted,
            'suspended' => $suspended,
            'moving' => $moving,
            'rotated' => $rotated,
            'result' => $this->distribute($ssid, true),
        ];
    }

    /**
     * Write a new network passphrase into the SSID's own configuration.
     *
     * Where the access points ask us (ppsk with a RADIUS server) this is in
     * force immediately: the passphrase only ever lived here, hostapd never had
     * it, and the fallback answer hands out the new one from the next request
     * on. Everywhere else it is a configuration change like any other and
     * reaches the access points at the next provisioning run — which is worth
     * knowing before rotating a network that has no RADIUS behind it.
     *
     * @return bool whether the value actually changed
     */
    public function setNetworkKey($ssid, $psk)
    {
        $em = $this->doctrine->getManager();
        foreach ($ssid->getConfigOptions() as $option) {
            if ('key' === $option->getName()) {
                if ((string) $option->getValue() === (string) $psk) {
                    return false;
                }
                $option->setValue($psk);
                $em->flush();
                $this->logger->notice('PpskService: '.$ssid->getName().' has a new network passphrase');

                return true;
            }
        }

        $option = new \ApManBundle\Entity\SSIDConfigOption();
        $option->setName('key');
        $option->setValue($psk);
        $option->setSsid($ssid);
        $em->persist($option);
        $em->flush();
        $this->logger->notice('PpskService: '.$ssid->getName().' has a network passphrase now');

        return true;
    }

    /**
     * The enrolment is over — the key found its device. Put back what stepped
     * aside for it.
     *
     * Called from the subscriber the moment a registration key is pinned, so
     * the network is shared again within seconds of the new device being on it.
     */
    public function finishRegistration(\ApManBundle\Entity\Ppsk $ppsk)
    {
        $restores = $ppsk->getRestoresId();
        if (!$restores) {
            return [];
        }
        $em = $this->doctrine->getManager();
        $back = [];
        // the comment carries the whole list; the column carries the first
        $ids = [$restores];
        if (preg_match('/displaced ([0-9, ]+)/', (string) $ppsk->getComment(), $m)) {
            foreach (preg_split('/\s*,\s*/', trim($m[1])) as $id) {
                if ('' !== $id) {
                    $ids[] = (int) $id;
                }
            }
        }
        // Written as statements, not through the entity manager: the keys were
        // switched off by the web process, and the subscriber that runs this
        // still holds them as it first read them. Asking Doctrine here would
        // ask its own memory and quietly restore nothing.
        $connection = $em->getConnection();
        foreach (array_unique($ids) as $id) {
            $row = $connection->fetchAssociative('SELECT id, keyid, enabled FROM ppsk WHERE id = :id', ['id' => $id]);
            if (!$row || $row['enabled']) {
                continue;
            }
            $connection->executeStatement('UPDATE ppsk SET enabled = 1 WHERE id = :id', ['id' => $id]);
            $back[] = $row['keyid'] ?: $row['id'];
        }
        $ppsk->setRestoresId(null);
        $em->flush();

        if ($back) {
            $this->logger->notice('PpskService: registration finished, back in service: '
                .implode(', ', $back));
        }

        return $back;
    }

    /**
     * A station used this key — timestamps, and the pin/learn logic that turns
     * a use into an identity.
     *
     * Shared by both sources that report a key's use: the keyid reports from
     * the control channel, and the accept events of the agent's RADIUS server.
     * For an SAE network the latter is the only trace a use leaves at all.
     *
     * @return int|null the ssid id that needs a redistribution, null when
     *                  nothing changed
     */
    public function recordUsed(\ApManBundle\Entity\Ppsk $ppsk, $mac, $apName = null)
    {
        $from = $apName ? '['.$apName.'] ' : '';
        $mac = strtolower((string) $mac);
        $now = new \DateTime();
        if (!$ppsk->getFirstSeen()) {
            $ppsk->setFirstSeen($now);
            $this->logger->notice('recordUsed(): '.$from.'ipsk '.$ppsk->getKeyid().
                ' used for the first time by '.$mac);
        }
        $ppsk->setLastSeen($now);
        $ppsk->setLastMac($mac);

        // the running station→key registry: every auth overwrites its entry,
        // so a key change or revoke can find and kick exactly the stations
        // that used it — the file kick that used to do this is gone on
        // RADIUS-only networks
        if ($ppsk->getSsid()) {
            $this->cacheFactory->addCacheItem(
                'sta.key.'.str_replace(':', '', $mac),
                ['id' => $ppsk->getId(), 'keyid' => $ppsk->getKeyid(),
                    'ssid' => $ppsk->getSsid()->getId(), 'h' => sha1((string) $ppsk->getPsk()),
                    'ts' => time()],
                7 * 86400
            );
        }

        // Bind the key to the device that just claimed it. From here on the
        // key only works for this address: a copy of the code on a second
        // phone is refused, and the refusal is visible as
        // AP-STA-POSSIBLE-PSK-MISMATCH. The station itself keeps its
        // connection — RELOAD_WPA_PSK only drops stations whose key stopped
        // matching, and for this one it still does.
        $pending = null;
        $wasRegistration = $ppsk->isRegistration();
        if ($ppsk->isPinPending()) {
            $ppsk->setMac($mac);
            $this->logger->notice('recordUsed(): '.$from.'ipsk '.$ppsk->getKeyid().
                ' pinned to '.$mac.', redistributing');
            if ($ppsk->getSsid()) {
                $pending = $ppsk->getSsid()->getId();
            }

            // An enrolment ends the moment its key finds a device: what stepped
            // aside for it goes back into service, and the network is shared
            // again.
            if ($wasRegistration) {
                $back = $this->finishRegistration($ppsk);
                if ($back) {
                    $this->logger->notice('recordUsed(): '.$from.'registration on '.
                        $ppsk->getSsid()->getName().' finished, back in service: '.implode(', ', $back));
                }
            }
        } elseif (\ApManBundle\Entity\Ppsk::ANY_MAC === $ppsk->getMac()) {
            // A station on a key bound to no address, on a network that manages
            // its own keys: give it one of its own, same passphrase, so it
            // becomes an identity without noticing anything.
            $learned = $this->learnFromShared($ppsk, $mac);
            if ($learned && $ppsk->getSsid()) {
                $pending = $ppsk->getSsid()->getId();
            }
        }
        $this->doctrine->getManager()->flush();

        return $pending;
    }

    /**
     * Whether a station section on the access point already says what we want.
     *
     * uci hands single values back as one-element lists for the options it
     * knows as lists, so compare the flattened shapes rather than the literal
     * structures.
     */
    private function sectionMatches($onAp, $wanted): bool
    {
        $flat = static function ($v) {
            if (is_array($v)) {
                return implode(',', array_map('strval', $v));
            }

            return null === $v ? '' : (string) $v;
        };
        foreach (['iface', 'mac', 'key', 'vid'] as $k) {
            if ($flat($onAp[$k] ?? null) !== $flat($wanted[$k] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether it is worth waiting for an answer from this access point.
     *
     * The key set still goes out to it — the broker may yet deliver it, and an
     * access point that is merely slow must not be skipped. What changes is the
     * waiting: an access point the state tree has not heard from will not
     * answer, and until now every distribution sat out its full deadline for
     * it. Measured 2026-08-21: one offline access point cost every single
     * distribution its whole eight seconds, and the file path ten more.
     */
    private function worthWaitingFor(\ApManBundle\Entity\AccessPoint $ap): bool
    {
        $state = $this->stateTree->composedState(
            \ApManBundle\Library\NodeState::TYPE_AP, $ap->getId());
        if (null === $state) {
            return true;    // nothing known yet is not a reason to give up on it
        }

        return \ApManBundle\Library\NodeState::AP_OFFLINE !== $state
            && \ApManBundle\Library\NodeState::AP_UNKNOWN !== $state;
    }

    /**
     * Whether the access points ask a RADIUS server about every station of this
     * SSID instead of deciding on their own.
     *
     * Both parts are needed: an auth_server alone only makes OpenWrt use the
     * answer as an access list, the `ppsk` option is what turns it into
     * wpa_psk_radius=2 and makes the answer carry the key.
     */
    public function usesRadius($ssid)
    {
        $config = $ssid->exportConfig();
        $server = $config->auth_server ?? ($config->auth_server_addr ?? null);
        $ppsk = $config->ppsk ?? null;

        return (bool) $server && in_array((string) $ppsk, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Kick the stations whose key changed, was disabled, or is no longer
     * bound to them — the explicit replacement for the file kick that
     * RADIUS-only networks no longer have.
     *
     * The registry (sta.key.<mac>) records which key every station used at
     * its last auth; the devices' status caches say where it is right now.
     *
     * @return string[] the macs that were kicked
     */
    public function kickChangedKeys($ssid, $banMs = 0)
    {
        if (!$this->usesOnApRadius($ssid)) {
            return [];
        }
        $keys = [];
        foreach ($this->getForSsid($ssid) as $ppsk) {
            if ($ppsk->isValid()) {
                $keys[$ppsk->getId()] = $ppsk;
            }
        }
        $kicked = [];
        foreach ($ssid->getDevices() as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status) || !isset($status['stations']) || !is_array($status['stations'])) {
                continue;
            }
            foreach (array_keys($status['stations']) as $mac) {
                $mac = strtolower((string) $mac);
                $entry = $this->cacheFactory->getCacheItemValue('sta.key.'.str_replace(':', '', $mac));
                if (!is_array($entry) || ($entry['ssid'] ?? null) != $ssid->getId()) {
                    continue;
                }
                $ppsk = $keys[$entry['id'] ?? 0] ?? null;
                $stale = !$ppsk
                    || ((string) ($entry['h'] ?? '')) !== sha1((string) $ppsk->getPsk())
                    || (\ApManBundle\Entity\Ppsk::ANY_MAC !== $ppsk->getMac()
                        && strtolower((string) $ppsk->getMac()) !== $mac);
                if (!$stale) {
                    continue;
                }
                if ($this->deauthenticate($ssid, $mac, $banMs)) {
                    $kicked[] = $mac;
                    $this->cacheFactory->deleteCacheItem('sta.key.'.str_replace(':', '', $mac));
                }
            }
        }

        return $kicked;
    }

    /**
     * Whether this SSID points its per-station PSK queries at the AP itself.
     *
     * Answered by the agent's own RADIUS server, whose secret is per AP and
     * lives in /etc/config/apman — the SSID config is shared by every AP of
     * the network and must not carry it. True either on the stored marker
     * (ppsk + auth_server pinned to loopback, the manual flip) or when the
     * iPSK feature is assigned — the feature's catalog config carries the
     * marker, not the SSID's own options.
     */
    public function usesOnApRadius($ssid, $fresh = false)
    {
        if ($this->hasIpskFeature($ssid, $fresh)) {
            return true;
        }
        if (!$this->usesRadius($ssid)) {
            return false;
        }
        $config = $ssid->exportConfig();
        $server = $config->auth_server ?? ($config->auth_server_addr ?? null);

        return '127.0.0.1' === (string) $server;
    }

    /**
     * The same, restricted to networks that manage their own keys.
     */
    public function isIpsk($ssid)
    {
        return $this->usesOnApRadius($ssid) && $this->managesOwnKeys($ssid);
    }

    /**
     * Whether the iPSK feature is assigned and enabled for this SSID.
     *
     * Read through the connection like managesOwnKeys(): the subscriber runs
     * for weeks and must not depend on Doctrine's hydration of feature maps.
     */
    private function hasIpskFeature($ssid, $fresh = false)
    {
        $id = $ssid->getId();
        if (!$fresh && isset($this->flagCache['ipsk']) && (time() - $this->flagCache['ipsk']['at']) < 10) {
            return isset($this->flagCache['ipsk']['on'][$id]);
        }
        $on = [];
        foreach ($this->doctrine->getManager()->getConnection()->fetchAllAssociative(
            'SELECT m.ssid_id FROM ssid_feature_map m JOIN feature f ON f.id = m.feature_id'
            ." WHERE m.enabled = 1 AND f.implementation LIKE '%IpskFeatureService'"
        ) as $row) {
            $on[(int) $row['ssid_id']] = true;
        }
        $this->flagCache['ipsk'] = ['at' => time(), 'on' => $on];

        return isset($on[$id]);
    }

    /**
     * Throw a station off the air, wherever it currently is.
     *
     * Needed because withdrawing a key over RADIUS is not the same as
     * withdrawing one from a psk file. The file case takes care of itself:
     * RELOAD_WPA_PSK drops exactly those stations whose key no longer matches.
     * A RADIUS answer, on the other hand, is only asked for when a station
     * associates — a device that is already connected would keep its
     * connection until it next tries, which for a withdrawn key is the wrong
     * answer. So we disconnect it and let it ask again.
     *
     * @return array [['ap' => …, 'ifname' => …], …] where it was sent
     */
    public function deauthenticate($ssid, $mac, $banMs = 0)
    {
        $mac = strtolower((string) $mac);
        $sent = [];
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
            return $sent;
        }

        // Where the status cache says the station is. It is a cache: a device
        // that has not reported yet, or a station that associated since the
        // last report, is simply not in it — and a kick that silently does
        // nothing is the worst outcome of a revocation. So when nothing
        // matches, every bss of the network is asked instead; hostapd ignores
        // a del_client for a station it does not have.
        $targets = [];
        foreach ($ssid->getDevices() as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status) || !isset($status['stations']) || !is_array($status['stations'])) {
                continue;
            }
            foreach (array_keys($status['stations']) as $station) {
                if (strtolower((string) $station) === $mac) {
                    $targets[$device->getId()] = $device;
                    break;
                }
            }
        }
        $blind = !$targets;
        if ($blind) {
            foreach ($ssid->getDevices() as $device) {
                $targets[$device->getId()] = $device;
            }
        }

        $client = null;
        foreach ($targets as $device) {
            if (!$device->getIfname()) {
                continue;
            }
            $ap = $device->getRadio() ? $device->getRadio()->getAccessPoint() : null;
            if (!$ap) {
                continue;
            }
            if (null === $client) {
                $client = $this->mqttFactory->getClient();
                if (!$client) {
                    $this->logger->error('PpskService: no mqtt client, cannot disconnect '.$mac);

                    return $sent;
                }
            }

            $opts = new \stdClass();
            $opts->addr = $mac;
            // 1 = unspecified reason; no ban, the station is welcome back the
            // moment it has a key again
            $opts->reason = 1;
            $opts->deauth = true;
            // A ban is what makes a withdrawal stick. hostapd caches the
            // RADIUS answer per station for about half a minute, so a station
            // that comes back right away is admitted again with the key that
            // was just taken away (measured 2026-08-21). Keeping it out until
            // that cache has expired forces a fresh question.
            $opts->ban_time = (int) $banMs;
            $cmd = $this->rpcService->createRpcRequest('ppsk-deauth-'.$device->getIfname(),
                'call', null, 'hostapd.'.$device->getIfname(), 'del_client', $opts);
            $client->publish('apman/ap/'.$ap->getName().'/command', json_encode($cmd), 1);
            $sent[] = ['ap' => $ap->getName(), 'ifname' => $device->getIfname()];
        }
        if ($client) {
            $client->disconnect();
        }
        if ($sent) {
            $this->logger->notice('PpskService: disconnected '.$mac.' on '
                .implode(', ', array_map(function ($e) { return $e['ap'].'/'.$e['ifname']; }, $sent))
                .($blind ? ' (every bss of the network — the status cache did not know it)' : ''));
        }

        return $sent;
    }

    /**
     * A key that is strong but still survives being read out over the phone
     * and typed on a mobile keyboard.
     *
     * The alphabet leaves out everything that gets confused in that situation
     * (0/O, 1/l/I) and the groups make it readable; 16 characters out of 31
     * are about 79 bits, far beyond what a WPA2 handshake capture can be
     * brute forced for.
     */
    public function generatePsk($groups = 4, $groupLength = 4)
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $parts = [];
        for ($g = 0; $g < $groups; ++$g) {
            $part = '';
            for ($i = 0; $i < $groupLength; ++$i) {
                $part .= $alphabet[random_int(0, $max)];
            }
            $parts[] = $part;
        }

        return implode('-', $parts);
    }

    /**
     * The runtime file content, in the format wifi-scripts produces plus the
     * keyid hostapd needs to tell us which identity a station used:
     * "[keyid=<id> ][vlanid=<vid> ]<mac> <key>".
     *
     * The keyid is the one part uci cannot carry — OpenWrt's wifi-station
     * schema only knows mac, key and vid — so a wifi reload regenerates the
     * file without it and the identities go anonymous until the next
     * distribution. distribute() notices that and repairs it.
     */
    public function renderPskFile($ssid)
    {
        $lines = [];
        foreach ($this->getForSsid($ssid) as $ppsk) {
            if (!$ppsk->isValid()) {
                continue;
            }
            // A key that is waiting to claim its first device is stored with
            // the wildcard address — and in a psk file that address means
            // "any station", which is the opposite of what it is for. The
            // RADIUS answer applies the pin; a file cannot, so such a key
            // stays out of the file until it has an owner.
            if ($ppsk->isPinPending()) {
                continue;
            }
            $line = $ppsk->getMac().' '.$ppsk->getPsk();
            if (null !== $ppsk->getVid()) {
                $line = 'vlanid='.$ppsk->getVid().' '.$line;
            }
            if ($ppsk->getKeyid()) {
                $line = 'keyid='.$ppsk->getKeyid().' '.$line;
            }
            $lines[] = $line;
        }

        return $lines ? implode("\n", $lines)."\n" : "\n";
    }

    /**
     * Withdraw a key.
     *
     * Disabling is enough to make it stop working: getForSsid() only renders
     * enabled keys, so the next distribution writes a file without it, and
     * hostapd's RELOAD_WPA_PSK throws out exactly those stations whose key no
     * longer matches — the device that used it loses its connection, everybody
     * else keeps theirs.
     *
     * The row stays, because when a key was handed out and when it was last
     * used is worth more than a clean table. $purge removes it for good.
     *
     * The distribution has to be forced: without keys left, distribute()
     * refuses to touch anything as a guard against an empty database, and the
     * uci sections of the withdrawn key would survive to the next wifi reload.
     */
    public function revoke(\ApManBundle\Entity\Ppsk $ppsk, $purge = false)
    {
        $em = $this->doctrine->getManager();
        $ssid = $ppsk->getSsid();
        $what = $ppsk->getKeyid() ?: $ppsk->getMac();
        // read before the row is gone: for a MAC agnostic key the only trace of
        // who used it is the address it was last seen on
        $mac = \ApManBundle\Entity\Ppsk::ANY_MAC === $ppsk->getMac()
            ? $ppsk->getLastMac() : $ppsk->getMac();

        if ($purge) {
            $em->remove($ppsk);
        } else {
            $ppsk->setEnabled(false);
        }
        $em->flush();
        $this->logger->notice('PpskService: '.($purge ? 'deleted' : 'revoked').' key '.$what);

        if (!$ssid) {
            return ['ok' => true, 'result' => []];
        }

        $delivery = $this->keyDelivery($ssid);
        // Both kinds of RADIUS count. usesRadius() only sees an auth_server in
        // the SSID's own options, and an iPSK network carries it in the
        // feature config instead — asking it alone left the station connected
        // with a key that had just been taken away (measured 2026-08-21).
        $onAp = $this->usesOnApRadius($ssid);
        $radius = $onAp || $this->usesRadius($ssid);
        $out = ['ok' => true, 'result' => [], 'disconnected' => []];

        // Where a psk file is in play, distributing is the withdrawal:
        // RELOAD_WPA_PSK drops exactly the stations whose key no longer
        // matches and leaves everybody else connected.
        if ($onAp) {
            // the key set goes out and the pmksa caches are flushed, so the
            // station cannot come back through a cached one
            $out['result'] = $this->distribute($ssid, true);
            $out['note'] = 'the access points answer this network themselves — '
                .'the key stops working at the next association';
        } elseif ($delivery['psk']) {
            $out['result'] = $this->distribute($ssid, true);
        } elseif (!$radius) {
            // SAE without a RADIUS server: the keys live in uci and hostapd
            // only reads them when it reads its whole configuration. Writing
            // them is all we can do here — it takes effect at the next
            // wireless reload, and that reload throws every client off.
            $out['result'] = $this->distribute($ssid, true);
            $out['note'] = 'this network answers SAE from its own configuration, '
                .'so the withdrawal only takes effect at the next wireless reload';
        }

        // A RADIUS answer is only asked for when a station associates, so a
        // device that is already connected would keep its connection until it
        // next tries. Disconnect it and let it ask again — with the key gone,
        // the answer is now a reject.
        //
        // The ban and the flush after it are what make a withdrawal stick:
        // without them the station is back within seconds, on hostapd's
        // cached answer or on its cached PMKSA (both measured 2026-08-21).
        if ($radius && $mac) {
            $out['disconnected'] = $this->deauthenticate($ssid, $mac, self::KICK_BAN_MS);
            if ($onAp && $out['disconnected']) {
                $mqtt = $this->mqttFactory->getClient();
                if ($mqtt) {
                    $this->flushPmksa($ssid, $mqtt, bin2hex(random_bytes(3)));
                    $mqtt->disconnect();
                }
            }
        }

        return $out;
    }

    /**
     * Create an iPSK: a generated key that is an identity of its own, bound to
     * no MAC address. The caller distributes it afterwards.
     */
    /**
     * The unbound key that would stop a new one from being issued: one that has
     * already been handed out and used, but has not bound itself to a device
     * yet. An unbound key nobody has touched is replaced silently instead.
     *
     * @return \ApManBundle\Entity\Ppsk|null
     */
    public function blockingUnboundKey($ssid)
    {
        foreach ($this->doctrine->getManager()->getRepository('ApManBundle\Entity\Ppsk')->findBy([
            'ssid' => $ssid, 'mac' => \ApManBundle\Entity\Ppsk::ANY_MAC,
        ]) as $old) {
            if ($old->getFirstSeen() || $old->getLastSeen()) {
                return $old;
            }
        }

        return null;
    }

    public function createIpsk($ssid, $name, $vid = null, $pinMac = true, $replaceUsed = false)
    {
        $em = $this->doctrine->getManager();

        // A network can hold only one unbound key. The index enforces it, and
        // it could not be otherwise: the access point answers a station with
        // exactly one key, so a second unbound one would sit behind the first
        // where nothing can ever reach it — an invitation that silently does
        // not work. An unbound key nobody has redeemed is a draft, so replace
        // it instead of refusing the new one.
        foreach ($em->getRepository('ApManBundle\Entity\Ppsk')->findBy([
            'ssid' => $ssid, 'mac' => \ApManBundle\Entity\Ppsk::ANY_MAC,
        ]) as $old) {
            $used = $old->getFirstSeen() || $old->getLastSeen();
            if ($used && !$replaceUsed) {
                // Somebody is holding this one — it has been redeemed and is
                // only waiting for the device that will bind it. The caller
                // decides whether that still matters; blockingUnboundKey()
                // gives it what it needs to ask.
                throw new \RuntimeException(sprintf(
                    'the unbound key "%s" on %s has already been used and is waiting to bind',
                    (string) $old->getName(), $ssid->getName()
                ));
            }
            $this->logger->notice('PpskService: replacing the '.($used ? 'used' : 'unused').
                ' unbound key '.$old->getKeyid().' on '.$ssid->getName().
                ' — a network can hold only one');
            $em->remove($old);
        }
        $em->flush();

        $ppsk = new \ApManBundle\Entity\Ppsk();
        $ppsk->setSsid($ssid);
        $ppsk->setName($name);
        $ppsk->setMac(\ApManBundle\Entity\Ppsk::ANY_MAC);
        $ppsk->setPsk($this->generatePsk());
        $ppsk->setSource(\ApManBundle\Entity\Ppsk::SOURCE_IPSK);
        $ppsk->setPinMac($pinMac);
        if (null !== $vid && '' !== $vid) {
            $ppsk->setVid((int) $vid);
        }
        $em->persist($ppsk);
        $em->flush();
        // the keyid carries the row id, so it can only be built once the row
        // exists
        $ppsk->setKeyid($ppsk->buildKeyid());
        $em->flush();
        $this->logger->notice('PpskService: created ipsk '.$ppsk->getKeyid().' for '.$ssid->getName());

        return $ppsk;
    }

    /**
     * The uci wifi-station sections one access point has right now.
     *
     * @return array apName => (sectionName => values), null when it did not answer
     */
    private function readState($client, array $ifnamesByAp, $timeout = 8, array $noWait = [])
    {
        $wait = [];
        $run = bin2hex(random_bytes(3));
        foreach ($ifnamesByAp as $apName => $ifnames) {
            $commands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            $opts = new \stdClass();
            $opts->config = 'wireless';
            $opts->type = 'wifi-station';
            $id = 'ppsk-read-'.$apName.'-'.$run;
            $commands['list'][] = $this->rpcService->createRpcRequest($id, 'call', null, 'uci', 'get', $opts);
            // ask it anyway, but do not hold everyone else up for an answer
            // that is not coming
            if (!isset($noWait[$apName])) {
                $wait[$id] = ['ap' => $apName, 'what' => 'sections'];
            }

            // the runtime file as it really is, not as we remember writing it:
            // a wifi reload regenerates it from uci and drops the keyids
            foreach ($ifnames as $ifname) {
                $read = new \stdClass();
                $read->path = '/var/run/hostapd-'.$ifname.'.psk';
                $fid = 'ppsk-readfile-'.$ifname.'-'.$run;
                $commands['list'][] = $this->rpcService->createRpcRequest($fid, 'call', null, 'file', 'read', $read);
                $wait[$fid] = ['ap' => $apName, 'what' => 'file', 'ifname' => $ifname];
            }
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
        }

        $sections = [];
        $files = [];
        $deadline = microtime(true) + $timeout;
        while ($wait && microtime(true) < $deadline) {
            $ids = [];
            foreach ($wait as $id => $what) {
                $ids[$id] = $what['ap'];
            }
            $hit = $this->cacheFactory->waitForAnyResult($ids,
                max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($wait[$hit['id']])) {
                break;
            }
            $what = $wait[$hit['id']];
            unset($wait[$hit['id']]);
            $data = $hit['data'];

            if ('file' === $what['what']) {
                // a missing file is not an error: the bss may never have had one
                $files[$what['ap']][$what['ifname']] = (is_array($data) && isset($data['result']['data']))
                    ? $data['result']['data'] : '';
                continue;
            }
            // "not found" simply means this access point has no station
            // section yet, which is a valid starting point
            if (is_array($data) && isset($data['result']['values'])) {
                $sections[$what['ap']] = $data['result']['values'];
            } elseif (is_array($data) && 4 === ($data['error']['code'] ?? null)) {
                $sections[$what['ap']] = [];
            } else {
                $sections[$what['ap']] = null;
            }
        }
        foreach ($wait as $what) {
            if ('sections' === $what['what']) {
                $sections[$what['ap']] = null;
            }
        }

        return ['sections' => $sections, 'files' => $files];
    }

    /**
     * The psk file wifi-scripts would render from these uci sections, for the
     * given wifi-iface sections. Order is irrelevant to hostapd, so the caller
     * compares sets of lines.
     */
    private function renderFromSections(array $sections, array $ifaces)
    {
        $lines = [];
        foreach ($sections as $values) {
            $iface = $values['iface'] ?? null;
            if (is_array($iface)) {
                $iface = $iface[0] ?? null;
            }
            if (null === $iface || !isset($ifaces[$iface])) {
                continue;
            }
            $mac = $values['mac'] ?? null;
            if (is_array($mac)) {
                $mac = $mac[0] ?? null;
            }
            $key = $values['key'] ?? null;
            if (null === $mac || null === $key) {
                continue;
            }
            $line = $mac.' '.$key;
            if (isset($values['vid']) && '' !== $values['vid']) {
                $line = 'vlanid='.$values['vid'].' '.$line;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * uci cannot store the keyid, so comparing what uci would render against
     * our file has to ignore it — otherwise every key with an identity would
     * look like a mismatch forever.
     */
    private function stripKeyid($lines)
    {
        return array_map(function ($line) {
            return preg_replace('/^keyid=\\S+\\s+/', '', $line);
        }, is_array($lines) ? $lines : preg_split('/\\r?\\n/', (string) $lines));
    }

    /** compare two psk files as sets of lines: hostapd does not care about order */
    private function sameLines($a, $b)
    {
        $normalise = function ($value) {
            $lines = is_array($value) ? $value : preg_split('/\r?\n/', (string) $value);
            $lines = array_values(array_filter(array_map('trim', $lines), function ($line) {
                return '' !== $line;
            }));
            sort($lines);

            return $lines;
        };

        return $normalise($a) === $normalise($b);
    }

    /**
     * Turn the stations that are connected right now into keys of their own.
     *
     * Every one of them gets a row carrying the passphrase it is already using
     * — the network passphrase. Nothing has to be typed into any device: the
     * secret does not change, only who owns it. What the network gains is an
     * identity per station: the access points report the keyid it authenticated
     * with, the key can be withdrawn or rotated for that one device, and the
     * network passphrase can finally be changed without locking out everything
     * that was ever configured with it.
     *
     * That is the whole point of the exercise, and the reason a key is unique
     * per SSID *and address* rather than per SSID: several rows carrying the
     * same passphrase is not an accident here, it is the migration.
     *
     * @param bool $apply false only reports what it would do
     *
     * @return array ['created' => [...], 'skipped' => [mac => reason], 'psk' => string]
     */
    public function convertConnected($ssid, $apply = false)
    {
        $em = $this->doctrine->getManager();
        $config = $ssid->exportConfig();
        $psk = (string) ($config->key ?? '');
        $out = ['created' => [], 'skipped' => [], 'psk' => $psk, 'distributed' => null];

        if (strlen($psk) < 8 || strlen($psk) > 63) {
            $out['error'] = 'the network has no passphrase that could be handed over ('
                .(('' === $psk) ? 'none set' : strlen($psk).' characters').')';

            return $out;
        }

        // what is on the air right now, per interface of this SSID
        $connected = [];
        foreach ($ssid->getDevices() as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status) || !isset($status['stations']) || !is_array($status['stations'])) {
                continue;
            }
            $ap = $device->getRadio() ? $device->getRadio()->getAccessPoint() : null;
            foreach ($status['stations'] as $mac => $station) {
                $mac = strtolower((string) $mac);
                if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                    continue;
                }
                $connected[$mac] = [
                    'ap' => $ap ? $ap->getName() : '?',
                    'ifname' => $device->getIfname(),
                    // hostapd reports this for a station that used a key from
                    // the psk file — one that already has an identity
                    'keyid' => is_array($station) ? ($station['keyid'] ?? null) : null,
                ];
            }
        }
        if (!$connected) {
            $out['error'] = 'no station is connected to this network right now — '
                .'there is nothing to convert';

            return $out;
        }

        $known = [];
        foreach ($this->doctrine->getRepository('ApManBundle\Entity\Ppsk')->findBy(['ssid' => $ssid]) as $entry) {
            $known[strtolower((string) $entry->getMac())] = $entry;
        }

        foreach ($connected as $mac => $info) {
            if (isset($known[$mac])) {
                $out['skipped'][$mac] = 'already has a key ('
                    .($known[$mac]->getKeyid() ?: $known[$mac]->getName() ?: 'unnamed').')';
                continue;
            }
            if (!empty($info['keyid'])) {
                $out['skipped'][$mac] = 'is using the identity '.$info['keyid'].' already';
                continue;
            }
            if (!$apply) {
                $out['created'][$mac] = $info;
                continue;
            }

            $entry = new \ApManBundle\Entity\Ppsk();
            $entry->setSsid($ssid);
            $entry->setMac($mac);
            $entry->setPsk($psk);
            $entry->setName($this->nameForStation($mac, $info));
            $entry->setSource(\ApManBundle\Entity\Ppsk::SOURCE_CONVERTED);
            // it is already bound to this address, there is nothing to learn
            $entry->setPinMac(false);
            $entry->setComment('converted from a station connected on '.$info['ap'].' '.$info['ifname']
                .' — carries the network passphrase it was already using');
            $em->persist($entry);
            $em->flush();
            $entry->setKeyid($entry->buildKeyid());
            $em->flush();
            $out['created'][$mac] = $info + ['keyid' => $entry->getKeyid()];
        }

        if ($apply && $out['created']) {
            $this->logger->notice('PpskService: converted '.count($out['created'])
                .' connected stations of '.$ssid->getName().' into keys of their own');
            $out['distributed'] = $this->distribute($ssid, true);
        }

        return $out;
    }

    /**
     * A name a human can recognise: whatever the network already knows about
     * the station, and the tail of its address when it knows nothing.
     */
    private function nameForStation($mac, array $info)
    {
        $client = $this->doctrine->getRepository('ApManBundle\Entity\Client')->findOneBy(['mac' => $mac]);
        if ($client && $client->getName()) {
            return $client->getName();
        }

        return 'Station '.strtoupper(substr(str_replace(':', '', $mac), -6));
    }

    /**
     * Push the rendered file to every access point that carries this SSID and
     * make hostapd re-read it.
     *
     * Three things have to line up, or the distribution silently reverts:
     *
     *  1. the uci wifi-station sections, because wifi-scripts regenerates the
     *     runtime file from them on the next wifi reload — an access point can
     *     carry leftovers there (anonymous sections from an earlier scheme)
     *     that would reappear,
     *  2. the runtime file itself, written to a temporary name and moved into
     *     place so hostapd never reads a half written one,
     *  3. only then the reload.
     *
     * Both 1 and 2 are read back and compared before the reload goes out.
     *
     * @return array per device result
     */
    public function distribute($ssid, $force = false)
    {
        // Read the flag without the cache here, and only here. Everywhere else
        // ten seconds of staleness is harmless; at this fork it is not. Taking
        // the file branch for a network that is answered by the access points
        // writes wifi-station sections and commits them, and wifi-scripts then
        // renders real keys out of them that beat the RADIUS answer until the
        // next provisioning run deletes the sections again.
        if ($this->usesOnApRadius($ssid, true)) {
            return $this->distributeKeystore($ssid);
        }
        $em = $this->doctrine->getManager();
        $keys = $this->getForSsid($ssid);
        if (!$keys && !$force) {
            // an empty file plus reload_wpa_psk would disconnect every station
            // that authenticates with a per device key
            $this->logger->error('PpskService: refusing to distribute an empty key set for '.$ssid->getName());

            return ['error' => 'no keys for this ssid, refusing to write an empty file (import first)'];
        }
        $content = $this->renderPskFile($ssid);
        $results = [];

        // An on-AP RADIUS network gets no file from us: the bss generates
        // its psk/sae files locally from the uci sections at the next wifi
        // reload, so a file we wrote would fight the radios' own and
        // RELOAD_WPA_PSK against the wrong list would deauth stations.
        // Changed keys are kicked explicitly instead (kickChangedKeys below).
        //
        // An external RADIUS network still gets its file: at the handshake
        // hostapd consults wpa_psk_file before the RADIUS answer
        // (wpa_auth_glue.c), so file and server must speak with one voice.
        // The file plus RELOAD_WPA_PSK is also what kicks a station whose key
        // changed.
        //
        // A network that only speaks SAE has no wpa_psk_file at all: writing
        // one would leave an unused file behind and RELOAD_WPA_PSK would fail.
        // Its keys travel through the uci sections alone and take effect at the
        // next wireless reload.
        $delivery = $this->keyDelivery($ssid);
        $runtime = $delivery['psk'] && !$this->usesOnApRadius($ssid);
        if (!$runtime) {
            if ($this->usesOnApRadius($ssid)) {
                $this->logger->info('PpskService: '.$ssid->getName().
                    " is answered by the AP's own RADIUS server, persisting the keys in uci only — effective at the next association");
            } elseif ($this->usesRadius($ssid)) {
                $this->logger->info('PpskService: '.$ssid->getName().
                    ' is SAE with a RADIUS server, persisting the keys in uci only — wildcard keys work through RADIUS at the next association');
            } else {
                $this->logger->info('PpskService: '.$ssid->getName().
                    ' is SAE only, persisting the keys in uci without touching a runtime file');
            }
        }

        $query = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a
                WHERE d.ssid = :ssid');
        $query->setParameter('ssid', $ssid);
        $devices = $query->getResult();
        if (!$devices) {
            return $results;
        }

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error('PpskService: no mqtt client, cannot distribute');

            return ['error' => 'no mqtt connection'];
        }

        $byAp = [];
        foreach ($devices as $device) {
            $ap = $device->getRadio()->getAccessPoint();
            $byAp[$ap->getName()][] = $device;
        }

        // what the access points carry right now — uci and the runtime files —
        // so leftovers can be spotted and the ones that need nothing are left
        // alone
        $ifnamesByAp = [];
        foreach ($byAp as $apName => $apDevices) {
            $ifnamesByAp[$apName] = [];
            foreach ($apDevices as $device) {
                if ($device->getIfname()) {
                    $ifnamesByAp[$apName][] = $device->getIfname();
                }
            }
        }
        // Work out once which access points will not answer. They still get
        // everything published; they are simply not waited for. One unreachable
        // access point used to cost every distribution the full deadline of
        // every phase — measured 2026-08-21 with ap-hv-klwz down all day.
        $noWait = [];
        foreach ($byAp as $apName => $apDevices) {
            $waitAp = $apDevices[0]->getRadio()->getAccessPoint();
            if ($waitAp && !$this->worthWaitingFor($waitAp)) {
                $noWait[$apName] = true;
            }
        }
        if ($noWait) {
            $this->logger->notice('PpskService: not waiting for '.implode(', ', array_keys($noWait)).
                ' — the state tree says they are not reachable');
        }

        $state = $this->readState($client, $ifnamesByAp, 8, $noWait);
        $current = $state['sections'];
        $committed = [];
        $fileBatches = [];
        $run = bin2hex(random_bytes(3));
        $hash = hash('sha256', $content);
        $verify = [];

        foreach ($byAp as $apName => $apDevices) {
            $commands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            $ifaces = [];    // uci wifi-iface section names carrying this ssid
            $expected = [];  // section name => uci add payload
            $targets = [];   // ifname => device
            foreach ($apDevices as $device) {
                $ifname = $device->getIfname();
                if (!$ifname) {
                    $results[$apName][] = ['device' => $device->getName(), 'skipped' => 'no ifname yet'];
                    continue;
                }
                $ifaces[$device->getName()] = true;
                $targets[$ifname] = $device;
                foreach ($this->getStationSections($device) as $section) {
                    $expected[$section->name] = $section;
                }
            }
            if (!$targets) {
                continue;
            }
            if (null === $current[$apName]) {
                $results[$apName][] = ['error' => 'no answer to the uci read, left untouched'];
                $this->logger->error('PpskService: '.$apName.' did not answer the uci read, skipping it');
                continue;
            }

            // nothing to do when uci already renders our file and every runtime
            // file is the one we last wrote
            // uci holds one section per key AND interface, while the psk file
            // of a bss holds each key once — so the comparison is per interface
            $uciMatches = true;
            foreach ($targets as $ifname => $device) {
                // uci cannot carry the keyid, so it is not part of this
                // comparison — see stripKeyid()
                if (!$this->sameLines($this->renderFromSections(
                        $current[$apName], [$device->getName() => true]),
                        $this->stripKeyid($content))) {
                    $uciMatches = false;
                }
            }
            // compared against the file that is really there: after a wifi
            // reload it has been regenerated from uci and lost every keyid,
            // which a remembered hash would never notice
            $filesMatch = true;
            foreach ($runtime ? $targets : [] as $ifname => $device) {
                $onAp = $state['files'][$apName][$ifname] ?? null;
                if (null === $onAp || !$this->sameLines($onAp, $content)) {
                    $filesMatch = false;
                }
            }
            if (!$force && $uciMatches && $filesMatch) {
                foreach ($targets as $ifname => $device) {
                    $results[$apName][] = ['device' => $device->getName(), 'ifname' => $ifname, 'unchanged' => true];
                }
                continue;
            }
            if (!$uciMatches) {
                $this->logger->notice('PpskService: '.$apName.' uci stations differ from the database, rewriting them');
            }

            // 1. uci: every station section of this ssid goes, ours come back.
            // Deleting first is what catches anonymous leftovers from an
            // earlier scheme, which would otherwise reappear in the runtime
            // file on the next wifi reload and undo this distribution.
            //
            // This goes out as its own batch and is waited for, because
            // committing over ubus raises a config change event: netifd
            // reloads the wireless config and regenerates every psk file from
            // uci — without the keyids, which uci cannot store. Writing the
            // runtime file in the same batch means that regeneration lands on
            // top of it and silently strips the identities.
            // Only the difference. Every uci call is a full round trip through
            // the config: measured on an ipq60xx, `add` costs 19 ms and
            // `delete` 6 ms. With ninety station sections per access point,
            // deleting them all and writing them all back was 2.3 seconds of
            // work for what is usually one changed key.
            $uciCommands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            $dropped = 0;
            $added = 0;
            foreach ($current[$apName] as $name => $values) {
                $iface = $values['iface'] ?? null;
                if (is_array($iface)) {
                    $iface = $iface[0] ?? null;
                }
                if (null === $iface || !isset($ifaces[$iface])) {
                    continue;   // belongs to another ssid, not ours to touch
                }
                // Still wanted and unchanged: leave it alone. Changed sections
                // are dropped and written again rather than patched — uci add
                // merges onto an existing name, so an option that went away
                // would otherwise stay behind.
                if (isset($expected[$name])
                    && $this->sectionMatches($values, $expected[$name]->values)) {
                    unset($expected[$name]);
                    continue;
                }
                $opts = new \stdClass();
                $opts->config = 'wireless';
                $opts->section = $name;
                $uciCommands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-del-'.(++$dropped).'-'.$run, 'call', null, 'uci', 'delete', $opts
                );
            }
            foreach ($expected as $section) {
                ++$added;
                $uciCommands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-add-'.$section->name.'-'.$run, 'call', null, 'uci', 'add', $section
                );
            }
            if ($dropped || $added) {
                $this->logger->info('PpskService: '.$apName.': '.$dropped.' station section(s) to drop, '.
                    $added.' to write, '.count($current[$apName]).' on the access point');
            }
            // Committing through the ubus uci plugin raises a config change
            // event, netifd reloads the wireless config and that restarts the
            // bsses — clients are thrown off for a key that was only added.
            // The uci command line writes the same file without the event, so
            // the change is persisted and the running radios are left alone.
            // hostapd learns about the key from the runtime file below.
            $commit = new \stdClass();
            $commit->command = '/sbin/uci';
            $commit->params = ['commit', 'wireless'];
            $uciCommands['list'][] = $this->rpcService->createRpcRequest(
                'ppsk-commit-'.$apName.'-'.$run, 'call', null, 'file', 'exec', $commit
            );
            if (!$uciMatches) {
                $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($uciCommands), 1);
                // same as the key store path: publish to it, do not wait on it
                if (!isset($noWait[$apName])) {
                    $committed['ppsk-commit-'.$apName.'-'.$run] = $apName;
                }
            }

            // 2. the runtime file, written aside and moved into place
            foreach ($runtime ? $targets : [] as $ifname => $device) {
                $path = '/var/run/hostapd-'.$ifname.'.psk';

                $write = new \stdClass();
                $write->path = $path.'.tmp';
                $write->data = $content;
                $write->append = false;
                $write->base64 = false;
                $write->mode = 416; // 0640
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-write-'.$ifname.'-'.$run, 'call', null, 'file', 'write', $write
                );

                $move = new \stdClass();
                $move->command = '/bin/mv';
                $move->params = [$path.'.tmp', $path];
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-move-'.$ifname.'-'.$run, 'call', null, 'file', 'exec', $move
                );

                // hostapd runs as user "network" inside a ujail. A file it
                // cannot read is reported as "WPA PSK file not found" and
                // RELOAD_WPA_PSK answers FAIL — so hand the group over.
                $own = new \stdClass();
                $own->command = '/bin/chown';
                $own->params = ['root:network', $path];
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-own-'.$ifname.'-'.$run, 'call', null, 'file', 'exec', $own
                );

                // …and the group has to be able to read it. The mode passed to
                // the write above does not survive the move, so the file ends
                // up 0600 and hostapd still cannot read it — measured
                // 2026-08-21: every RELOAD_WPA_PSK answered FAIL with "WPA PSK
                // file not found" until this chmod was added.
                $mode = new \stdClass();
                $mode->command = '/bin/chmod';
                $mode->params = ['640', $path];
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-mode-'.$ifname.'-'.$run, 'call', null, 'file', 'exec', $mode
                );

                // 3. read it back: the reload only goes out for a file that is
                // provably the one we meant to write
                $read = new \stdClass();
                $read->path = $path;
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-file-'.$ifname.'-'.$run, 'call', null, 'file', 'read', $read
                );
            }

            // and read uci back too, after the commit
            $opts = new \stdClass();
            $opts->config = 'wireless';
            $opts->type = 'wifi-station';
            $commands['list'][] = $this->rpcService->createRpcRequest(
                'ppsk-uci-'.$apName.'-'.$run, 'call', null, 'uci', 'get', $opts
            );

            $fileBatches[$apName] = $commands;
            $verify[$apName] = ['ifaces' => $ifaces, 'targets' => $runtime ? $targets : [],
                'staged' => $runtime ? [] : $targets,
                'staged_note' => $this->usesRadius($ssid)
                    ? 'not applicable — RADIUS answers per station, effective at the next association'
                    : 'not applicable — SAE takes effect at the next wireless reload'];
        }

        // Let the config change settle before the runtime files go out. Without
        // this the netifd reload that the commit triggers overwrites them.
        if ($committed) {
            $deadline = microtime(true) + 10;
            $wait = $committed;
            while ($wait && microtime(true) < $deadline) {
                $hit = $this->cacheFactory->waitForAnyResult($wait,
                    max(1, (int) ceil($deadline - microtime(true))));
                if (!$hit || !isset($wait[$hit['id']])) {
                    break;
                }
                unset($wait[$hit['id']]);
            }
            $this->logger->info('PpskService: committed uci on '.count($committed).' access point(s)');
        }

        foreach ($fileBatches as $apName => $commands) {
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
            $this->logger->info('PpskService: staged '.count($commands['list']).' commands for '.$apName);
        }

        if ($verify) {
            $results = $this->verifyAndReload($client, $verify, $content, $hash, $run, $results, $noWait);
        }
        $client->disconnect();

        return $results;
    }

    /**
     * The complete key set of an SSID as the AP's RADIUS server wants it: one
     * command per AP, answered with the version in force. No uci, no runtime
     * file, no RELOAD_WPA_PSK — hostapd on these networks has neither
     * wpa_psk_file nor sae_password_file (no wifi-station sections exist,
     * so wifi-scripts renders none) and takes every key, WPA2 and SAE, from
     * the Access-Accept. The key name is ppsk_<ssid>_<id>, which
     * resolveBySectionName() maps back to the row.
     *
     * @return array per AP result
     */
    public function distributeKeystore($ssid)
    {
        $keys = [];
        foreach ($this->getForSsid($ssid) as $ppsk) {
            if (!$ppsk->isValid()) {
                continue;
            }
            $keys[] = [
                'name' => 'ppsk_'.$ssid->getId().'_'.$ppsk->getId(),
                'mac' => \ApManBundle\Entity\Ppsk::ANY_MAC === $ppsk->getMac() ? null : $ppsk->getMac(),
                'psk' => $ppsk->getPsk(),
                'vid' => null !== $ppsk->getVid() ? (string) $ppsk->getVid() : null,
            ];
        }
        $config = $ssid->exportConfig();
        $networkKey = ($ssid->getRadiusFallback() && strlen((string) ($config->key ?? '')) >= 8)
            ? (string) $config->key : null;
        // hostapd does not put WLAN-AKM-Suite in the mac acl query, so the
        // access point cannot tell an SAE association from a WPA2 one and
        // would offer a 64 hex raw psk (wps) to a station that can only use
        // a passphrase. We know the encryption, so we say so.
        $sae = $this->keyDelivery($ssid)['sae'];
        $version = substr(sha1(json_encode([$keys, $networkKey, $sae])), 0, 12);

        $byAp = $this->devicesByAp($ssid);
        if (!$byAp) {
            return [];
        }
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error('PpskService: no mqtt client, cannot distribute');

            return ['error' => 'no mqtt connection'];
        }
        $run = bin2hex(random_bytes(3));
        $wait = [];
        $skipped = [];
        $results = [];
        foreach ($byAp as $apName => $devices) {
            $payload = new \stdClass();
            $payload->ssid = $ssid->getName();
            $payload->version = $version;
            $payload->ifaces = array_values(array_map(function ($d) { return $d->getName(); }, $devices));
            $payload->network_key = $networkKey;
            $payload->sae = $sae;
            $payload->keys = $keys;
            $id = 'ppsk-keys-'.$apName.'-'.$run;
            $commands = ['list' => [
                $this->rpcService->createRpcRequest($id, 'call', null, 'apman', 'keys', $payload),
            ], 'options' => ['cancel_on_error' => false]];
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
            // It still gets the key set — the broker may yet deliver it, and a
            // slow access point must not be skipped. What we do not do is sit
            // out the deadline for one the tree knows is not there.
            $ap = $devices[0]->getRadio()->getAccessPoint();
            if ($ap && !$this->worthWaitingFor($ap)) {
                $results[$apName] = ['version' => $version, 'keys' => count($keys),
                    'ack' => 'not waited for, the access point is not reachable'];
                $skipped[] = $apName;
                continue;
            }
            $wait[$id] = $apName;
            $results[$apName] = ['version' => $version, 'keys' => count($keys), 'ack' => 'no answer'];
        }
        $deadline = microtime(true) + 8;
        while ($wait && microtime(true) < $deadline) {
            $hit = $this->cacheFactory->waitForAnyResult($wait, max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($wait[$hit['id']])) {
                break;
            }
            $apName = $wait[$hit['id']];
            unset($wait[$hit['id']]);
            $data = $hit['data'];
            if (isset($data['error'])) {
                $results[$apName]['ack'] = 'failed: '.($data['error']['message'] ?? '?');
                $this->logger->error('PpskService: '.$apName.' refused the key set: '.$results[$apName]['ack']);
            } else {
                $got = $data['result']['versions'][$ssid->getName()] ?? null;
                $results[$apName]['ack'] = $got === $version ? 'ok' : 'version mismatch: '.json_encode($got);
                if (!empty($data['result']['errors'])) {
                    $results[$apName]['errors'] = $data['result']['errors'];
                }
            }
        }
        foreach ($wait as $apName) {
            $this->logger->error('PpskService: '.$apName.' did not acknowledge the key set');
        }
        // Count what was actually confirmed. The ones we did not wait for were
        // published to all the same, but nobody said they arrived — calling
        // that "acknowledged" would be the kind of green light that cost a
        // night of debugging once already.
        $answered = count($byAp) - count($wait) - count($skipped);
        $this->logger->notice('PpskService: '.$ssid->getName().' key set '.$version.' ('.count($keys).
            ' keys) sent to '.count($byAp).' access point(s), '.$answered.' acknowledged'.
            ($skipped ? ', '.count($skipped).' not waited for ('.implode(', ', $skipped).')' : ''));

        // A changed key set needs more than a new answer. Three properties of
        // hostapd get in the way, all measured on the fleet 2026-08-21, and
        // the order below is what defeats them:
        //
        //  1. it caches the RADIUS answer per station for about half a minute,
        //     so a station that reassociates right away is admitted again with
        //     the key that was just withdrawn — the kick therefore carries a
        //     ban long enough to outlast that cache;
        //  2. a station it deauthenticates otherwise comes back through its
        //     cached PMKSA without running SAE or the four way handshake at
        //     all (auth_alg=open on an SAE bss), keeping the withdrawn key;
        //  3. flushing the PMKSA cache only helps after the last successful
        //     association, so it has to happen *after* the kick, not before.
        //
        // Flushing costs the stations that stay connected nothing: their PTK
        // lives on, only their next reassociation is a full one.
        $previous = $this->cacheFactory->getCacheItemValue('ppsk.version.'.$ssid->getId());
        $changed = $previous !== $version;

        $kicked = $this->kickChangedKeys($ssid, $changed ? self::KICK_BAN_MS : 0);
        if ($kicked) {
            $this->logger->notice('PpskService: kicked '.count($kicked).' station(s) whose key changed on '.$ssid->getName());
        }

        if ($changed) {
            $this->flushPmksa($ssid, $client, $run);
            $this->cacheFactory->addCacheItem('ppsk.version.'.$ssid->getId(), $version, 180 * 86400);
        }
        $client->disconnect();

        return $results;
    }

    /**
     * Take this network's key set off the access points again.
     *
     * The counterpart of distributeKeystore(): a network that stops being an
     * iPSK network, or a test network being taken down, would otherwise leave
     * a key set behind that keeps answering for interfaces of that name.
     *
     * @return array per access point result
     */
    public function removeKeystore($ssid)
    {
        $byAp = $this->devicesByAp($ssid);
        if (!$byAp) {
            return [];
        }
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return ['error' => 'no mqtt connection'];
        }
        $run = bin2hex(random_bytes(3));
        $wait = [];
        $results = [];
        foreach ($byAp as $apName => $devices) {
            $payload = new \stdClass();
            $payload->ssid = $ssid->getName();
            $payload->keys = null;
            $id = 'ppsk-drop-'.$apName.'-'.$run;
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode(['list' => [
                $this->rpcService->createRpcRequest($id, 'call', null, 'apman', 'keys', $payload),
            ], 'options' => ['cancel_on_error' => false]]), 1);
            $wait[$id] = $apName;
            $results[$apName] = 'no answer';
        }
        $deadline = microtime(true) + 8;
        while ($wait && microtime(true) < $deadline) {
            $hit = $this->cacheFactory->waitForAnyResult($wait, max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($wait[$hit['id']])) {
                break;
            }
            $apName = $wait[$hit['id']];
            unset($wait[$hit['id']]);
            $results[$apName] = isset($hit['data']['error'])
                ? 'failed: '.($hit['data']['error']['message'] ?? '?') : 'removed';
        }
        $client->disconnect();
        $this->cacheFactory->deleteCacheItem('ppsk.version.'.$ssid->getId());
        $this->logger->notice('PpskService: took the key set of '.$ssid->getName().' off '
            .count($byAp).' access point(s)');

        return $results;
    }

    /**
     * Empty the PMKSA caches of every bss of this network.
     *
     * Only useful *after* the stations that lost their key were thrown off: a
     * station that associates successfully afterwards simply builds a new
     * entry and comes back on it, without running SAE or the four way
     * handshake again.
     *
     * @return int the number of bss asked
     */
    private function flushPmksa($ssid, $client, $run)
    {
        $flushed = 0;
        foreach ($this->devicesByAp($ssid) as $apName => $devices) {
            $commands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            foreach ($devices as $device) {
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-pmksa-'.$device->getIfname().'-'.$run, 'ctrl', null,
                    $device->getIfname(), 'PMKSA_FLUSH'
                );
                ++$flushed;
            }
            if ($commands['list']) {
                $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
            }
        }
        if ($flushed) {
            $this->logger->notice('PpskService: flushed the pmksa cache of '.$flushed.' bss of '.$ssid->getName());
        }

        return $flushed;
    }

    /**
     * Confirm that uci and the runtime file both hold what we distributed, then
     * make hostapd re-read the file.
     *
     * RELOAD_WPA_PSK goes through the hostapd control channel: it re-reads the
     * psk file and leaves existing associations alone. The ubus "reload" method
     * that was used before restarts the bss and drops every client on it, which
     * made distributing a key an outage.
     */
    private function verifyAndReload($client, array $verify, $content, $hash, $run, array $results, array $noWait = [])
    {
        $wait = [];
        foreach ($verify as $apName => $what) {
            if (isset($noWait[$apName])) {
                continue;
            }
            $wait['ppsk-uci-'.$apName.'-'.$run] = $apName;
            foreach ($what['targets'] as $ifname => $device) {
                $wait['ppsk-file-'.$ifname.'-'.$run] = $apName;
            }
        }

        $answers = [];
        $deadline = microtime(true) + 15;
        while ($wait && microtime(true) < $deadline) {
            $hit = $this->cacheFactory->waitForAnyResult($wait,
                max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($wait[$hit['id']])) {
                break;
            }
            unset($wait[$hit['id']]);
            $answers[$hit['id']] = $hit['data'];
        }

        foreach ($verify as $apName => $what) {
            $uci = $answers['ppsk-uci-'.$apName.'-'.$run] ?? null;
            $sections = (is_array($uci) && isset($uci['result']['values'])) ? $uci['result']['values'] : null;
            $reload = [];
            foreach ($what['targets'] as $ifname => $device) {
                // what wifi-scripts would write for this bss on the next reload
                $uciOk = null !== $sections && $this->sameLines(
                    $this->renderFromSections($sections, [$device->getName() => true]),
                    $this->stripKeyid($content));
                if (!$uciOk) {
                    $this->logger->error('PpskService: '.$apName.'/'.$ifname.
                        ' uci would not reproduce the distributed keys, no reload sent');
                }
                $entry = ['device' => $device->getName(), 'ifname' => $ifname, 'uci' => $uciOk];
                $file = $answers['ppsk-file-'.$ifname.'-'.$run] ?? null;
                $data = (is_array($file) && isset($file['result']['data'])) ? $file['result']['data'] : null;
                $entry['file'] = null !== $data && $this->sameLines($data, $content);
                if (!$entry['file']) {
                    $this->logger->error('PpskService: '.$apName.'/'.$ifname.
                        ' runtime file does not match what was written, no reload sent');
                }
                if ($uciOk && $entry['file']) {
                    // only a verified file may be handed to hostapd
                    $this->cacheFactory->addCacheItem('ppsk.hash.'.$device->getId(), $hash, 180 * 86400);
                    $reload[$ifname] = $device;
                    $entry['reload'] = 'sent';
                } else {
                    $entry['reload'] = 'skipped';
                }
                $results[$apName][] = $entry;
            }

            // SAE only: there is no runtime file to verify, the uci sections
            // are the whole delivery
            foreach ($what['staged'] ?? [] as $ifname => $device) {
                $staged = null !== $sections && $this->sameLines(
                    $this->renderFromSections($sections, [$device->getName() => true]),
                    $this->stripKeyid($content));
                if (!$staged) {
                    $this->logger->error('PpskService: '.$apName.'/'.$ifname.
                        ' uci does not hold the keys after the commit');
                }
                $results[$apName][] = ['device' => $device->getName(), 'ifname' => $ifname,
                    'uci' => $staged, 'file' => null,
                    'reload' => $what['staged_note'] ?? 'not applicable — SAE takes effect at the next wireless reload'];
            }
            if (!$reload) {
                continue;
            }
            $commands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            foreach ($reload as $ifname => $device) {
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-reload-'.$ifname.'-'.$run, 'ctrl', null, $ifname, 'RELOAD_WPA_PSK'
                );
            }
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
            $this->logger->notice('PpskService: '.$apName.': reload_wpa_psk for '.
                implode(', ', array_keys($reload)));
        }

        // collect what hostapd said, so the caller sees more than "sent"
        $wait = [];
        foreach ($results as $apName => $entries) {
            foreach ($entries as $entry) {
                if (($entry['reload'] ?? null) === 'sent') {
                    $wait['ppsk-reload-'.$entry['ifname'].'-'.$run] = $apName;
                }
            }
        }
        $deadline = microtime(true) + 8;
        $replies = [];
        while ($wait && microtime(true) < $deadline) {
            $hit = $this->cacheFactory->waitForAnyResult($wait,
                max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($wait[$hit['id']])) {
                break;
            }
            unset($wait[$hit['id']]);
            $replies[$hit['id']] = $hit['data'];
        }
        foreach ($results as $apName => $entries) {
            foreach ($entries as $i => $entry) {
                if (($entry['reload'] ?? null) !== 'sent') {
                    continue;
                }
                $reply = $replies['ppsk-reload-'.$entry['ifname'].'-'.$run] ?? null;
                if (!is_array($reply)) {
                    $results[$apName][$i]['reload'] = 'no answer';
                } elseif (isset($reply['error'])) {
                    $results[$apName][$i]['reload'] = 'failed: '.($reply['error']['message'] ?? '?');
                } else {
                    $raw = trim($reply['result']['raw'] ?? '');
                    $results[$apName][$i]['reload'] = ('OK' === $raw) ? 'ok' : ('hostapd: '.$raw);
                }
            }
        }

        return $results;
    }

    /**
     * Read the runtime file of one device and adopt keys hostapd generated
     * during WPS enrolment ("wps=1 <mac> <64 hex>") into the database, which
     * turns a key that only exists on one access point and only until the next
     * wifi reload into fleet wide, persistent state.
     *
     * @return Ppsk[] newly imported keys
     */
    public function importFromWps($ssid)
    {
        $imported = [];
        foreach ($this->devicesByAp($ssid) as $apName => $devices) {
            foreach ($devices as $device) {
                $imported = array_merge($imported, $this->importFromWpsDevice($device));
            }
        }

        return $imported;
    }

    private function importFromWpsDevice(\ApManBundle\Entity\Device $device)
    {
        $em = $this->doctrine->getManager();
        $ap = $device->getRadio()->getAccessPoint();
        $ifname = $device->getIfname();
        $ssid = $device->getSsid();
        if (!$ifname || !$ssid) {
            return [];
        }

        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $this->logger->error('PpskService: cannot log in to '.$ap->getName());

            return [];
        }
        $opts = new \stdClass();
        $opts->path = '/var/run/hostapd-'.$ifname.'.psk';
        $opts->base64 = false;
        $stat = $session->call('file', 'read', $opts);
        if (!is_object($stat) || !property_exists($stat, 'data')) {
            $this->logger->warning('PpskService: cannot read '.$opts->path.' on '.$ap->getName());

            return [];
        }

        $imported = [];
        foreach (explode("\n", $stat->data) as $line) {
            $line = trim($line);
            if ('' === $line || 0 !== strpos($line, 'wps=1 ')) {
                continue;
            }
            // hostapd writes: wps=1 <mac> <64 hex>   (src/ap/wps_hostapd.c)
            $fields = preg_split('/\s+/', $line);
            if (3 !== count($fields)) {
                continue;
            }
            $mac = strtolower($fields[1]);
            $psk = $fields[2];
            if (64 !== strlen($psk) || !ctype_xdigit($psk)) {
                continue;
            }
            $existing = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')
                ->findOneBy(['ssid' => $ssid, 'mac' => $mac]);
            if ($existing) {
                continue;
            }
            $ppsk = new Ppsk();
            $ppsk->setSsid($ssid);
            $ppsk->setMac($mac);
            $ppsk->setPsk($psk);
            $ppsk->setSource(Ppsk::SOURCE_WPS);
            $ppsk->setName('WPS '.$mac);
            $ppsk->setComment('enrolled on '.$ap->getName().' / '.$ifname);
            $em->persist($ppsk);
            $imported[] = $ppsk;
        }
        if ($imported) {
            $em->flush();
            $this->logger->notice('PpskService: imported '.count($imported).' wps key(s) from '.$ap->getName());
        }

        return $imported;
    }

    /**
     * Adopt the wifi-station sections that already live on an access point.
     *
     * publishConfig deletes every wifi-station section before it writes the
     * wireless config, so without this the first full provisioning would drop
     * keys that only ever existed on the device. Run once per access point to
     * make the controller the point of truth without losing anything.
     *
     * @return array summary
     */
    public function importFromUci(\ApManBundle\Entity\AccessPoint $ap)
    {
        $em = $this->doctrine->getManager();
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            return ['error' => 'cannot log in to '.$ap->getName()];
        }
        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-station';
        $stat = $session->call('uci', 'get', $opts);
        if (!is_object($stat) || !property_exists($stat, 'values')) {
            return ['error' => 'no wifi-station sections on '.$ap->getName()];
        }

        // section name of the wifi-iface -> device
        $devices = [];
        foreach ($ap->getRadios() as $radio) {
            foreach ($radio->getDevices() as $device) {
                $devices[$device->getName()] = $device;
                if ($device->getIfname()) {
                    $devices[$device->getIfname()] = $device;
                }
            }
        }

        $imported = 0;
        $skipped = 0;
        // an ssid usually exists on several radios; the same key would then be
        // seen once per interface, and rows not flushed yet are invisible to
        // the repository lookup below
        $seen = [];
        foreach ((array) $stat->values as $section) {
            $section = (array) $section;
            if (empty($section['key']) || empty($section['iface'])) {
                ++$skipped;
                continue;
            }
            $macs = isset($section['mac']) ? (array) $section['mac'] : [Ppsk::ANY_MAC];
            foreach ((array) $section['iface'] as $iface) {
                if (!isset($devices[$iface])) {
                    ++$skipped;
                    continue;
                }
                $ssid = $devices[$iface]->getSsid();
                if (!$ssid) {
                    ++$skipped;
                    continue;
                }
                foreach ($macs as $mac) {
                    $mac = strtolower(trim($mac));
                    $dedup = $ssid->getId().'|'.$mac;
                    if (isset($seen[$dedup])) {
                        ++$skipped;
                        continue;
                    }
                    $existing = $this->doctrine->getRepository('ApManBundle\Entity\Ppsk')
                        ->findOneBy(['ssid' => $ssid, 'mac' => $mac]);
                    if ($existing) {
                        ++$skipped;
                        continue;
                    }
                    $seen[$dedup] = true;
                    $ppsk = new Ppsk();
                    $ppsk->setSsid($ssid);
                    $ppsk->setMac($mac);
                    $ppsk->setPsk($section['key']);
                    $ppsk->setSource(Ppsk::SOURCE_MANUAL);
                    $ppsk->setName(Ppsk::ANY_MAC === $mac ? 'shared key ('.$ssid->getName().')' : $mac);
                    if (isset($section['vid']) && '' !== $section['vid']) {
                        $ppsk->setVid((int) $section['vid']);
                    }
                    $ppsk->setComment('imported from '.$ap->getName().' / '.$iface);
                    $em->persist($ppsk);
                    ++$imported;
                }
            }
        }
        if ($imported) {
            $em->flush();
        }
        $this->logger->notice('PpskService: imported '.$imported.' key(s) from '.$ap->getName().', skipped '.$skipped);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return array devices carrying this ssid, grouped by access point name
     */
    private function devicesByAp($ssid)
    {
        $em = $this->doctrine->getManager();
        $devices = $em->createQuery('SELECT d,r,a FROM ApManBundle\\Entity\\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a
                WHERE d.ssid = :ssid')
            ->setParameter('ssid', $ssid)->getResult();
        $byAp = [];
        foreach ($devices as $device) {
            if (!$device->getIfname()) {
                continue;
            }
            $byAp[$device->getRadio()->getAccessPoint()->getName()][] = $device;
        }

        return $byAp;
    }

    /**
     * Start a WPS PIN registration on every bss of the SSID.
     *
     * A device being enrolled stands somewhere, not next to one particular
     * radio, so the registration window has to be open on all of them: every
     * band of every access point. hostapd's ubus wps_start takes no arguments
     * (push button only), so the PIN path goes through hostapd_cli. "any"
     * accepts the next enrollee presenting this PIN.
     */
    /**
     * Open the WPS registration window on every bss of the SSID, over ubus.
     *
     * hostapd exposes wps_start / wps_status / wps_cancel on each bss object,
     * so no hostapd_cli and no reload is involved: the window opens and closes
     * at runtime and no client is disturbed. wps_start is the push button
     * variant -- the button being the one in this interface.
     *
     * It only works when WPS is enabled in the configuration of that bss
     * (uci option wps_pushbutton), otherwise hostapd answers with
     * "not supported" and this reports it per interface.
     */
    public function startWps($ssid)
    {
        return $this->wpsUbus($ssid, 'wps_start', 'start');
    }

    /**
     * PIN registration. hostapd's ubus wps_start takes no arguments, so a PIN
     * has to go through hostapd_cli, which is not part of wpad -- the access
     * points would need the hostapd-utils package. Kept for installations that
     * have it; the push button path above is the one that works everywhere.
     */
    public function startWpsPin($ssid, $pin, $timeout = 300, $uuid = 'any')
    {
        return $this->wpsCommand($ssid, ['wps_pin', $uuid, (string) $pin, (string) $timeout], 'start');
    }

    /**
     * Runs one argument-less hostapd method on every bss of the SSID and
     * collects the answers the agents publish back on command_result/<id>.
     */
    private function wpsUbus($ssid, $method, $what)
    {
        $byAp = $this->devicesByAp($ssid);
        if (!$byAp) {
            return ['ok' => false, 'error' => 'no interfaces for this ssid'];
        }
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return ['ok' => false, 'error' => 'no mqtt connection'];
        }

        $expect = [];
        foreach ($byAp as $apName => $devices) {
            $commands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            foreach ($devices as $device) {
                $id = $what.'-'.$device->getId();
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    $id, 'call', null, 'hostapd.'.$device->getIfname(), $method, new \stdClass()
                );
                $expect[$id] = $apName.'/'.$device->getIfname();
            }
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
        }
        $client->disconnect();

        // wait briefly for the agents to answer; a bss without WPS in its
        // configuration reports ubus status 8 (not supported)
        $ok = [];
        $failed = [];
        $pending = $expect;
        $deadline = microtime(true) + 4;
        while ($pending && microtime(true) < $deadline) {
            foreach ($pending as $id => $where) {
                list($apName, ) = explode('/', $where, 2);
                $res = $this->cacheFactory->getCacheItemValue('command.result.'.$apName.'.'.$id);
                if (!is_array($res)) {
                    continue;
                }
                unset($pending[$id]);
                if (isset($res['error'])) {
                    $failed[$where] = ($res['error']['message'] ?? 'failed').' ('.($res['error']['code'] ?? '?').')';
                } else {
                    $ok[] = $where;
                }
            }
            if ($pending) {
                usleep(250000);
            }
        }

        $result = [
            'ok' => count($ok) > 0,
            'interfaces' => count($expect),
            'answered' => count($ok) + count($failed),
            'active' => $ok,
            'failed' => $failed,
            'offline' => array_values($pending),
        ];
        if (!$ok && $failed) {
            $result['error'] = 'no interface accepted '.$method.
                ' — enable WPS for this SSID first (uci option wps_pushbutton)';
        }
        $this->logger->notice('PpskService: '.$method.' on '.$ssid->getName().': '.
            count($ok).' ok, '.count($failed).' failed, '.count($pending).' no answer');

        return $result;
    }

    /**
     * Close the window everywhere again. Leaving it open on eleven interfaces
     * for the full timeout is exactly the exposure WPS is criticised for.
     */
    public function cancelWps($ssid)
    {
        return $this->wpsUbus($ssid, 'wps_cancel', 'cancel');
    }

    /**
     * pbc_status is "Active" while a registration window is open.
     */
    public function wpsStatus($ssid)
    {
        return $this->wpsUbus($ssid, 'wps_status', 'status');
    }

    private function wpsCommand($ssid, array $args, $what)
    {
        $byAp = $this->devicesByAp($ssid);
        if (!$byAp) {
            return ['ok' => false, 'error' => 'no interfaces for this ssid'];
        }
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return ['ok' => false, 'error' => 'no mqtt connection'];
        }

        $sent = 0;
        $targets = [];
        foreach ($byAp as $apName => $devices) {
            $commands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            foreach ($devices as $device) {
                $opts = new \stdClass();
                $opts->command = '/usr/sbin/hostapd_cli';
                $opts->params = array_merge(['-i', $device->getIfname()], $args);
                $commands['list'][] = $this->rpcService->createRpcRequest(
                    'wps-'.$what.'-'.$device->getIfname(), 'call', null, 'file', 'exec', $opts
                );
                $targets[] = $apName.'/'.$device->getIfname();
                ++$sent;
            }
            $client->publish('apman/ap/'.$apName.'/command/bulk', json_encode($commands), 1);
        }
        $client->disconnect();
        $this->logger->notice('PpskService: wps '.$what.' on '.$sent.' interface(s) of '.$ssid->getName());

        return ['ok' => true, 'interfaces' => $sent, 'aps' => count($byAp), 'targets' => $targets];
    }

    /**
     * A WPS PIN is seven digits plus a checksum digit.
     */
    public function generatePin()
    {
        $pin = random_int(1000000, 9999999);
        $accum = 0;
        $t = $pin;
        $accum += 3 * (int) ($t / 1000000 % 10);
        $accum += 1 * (int) ($t / 100000 % 10);
        $accum += 3 * (int) ($t / 10000 % 10);
        $accum += 1 * (int) ($t / 1000 % 10);
        $accum += 3 * (int) ($t / 100 % 10);
        $accum += 1 * (int) ($t / 10 % 10);
        $accum += 3 * (int) ($t % 10);
        $digit = (10 - $accum % 10) % 10;

        return sprintf('%07d%d', $pin, $digit);
    }
}
