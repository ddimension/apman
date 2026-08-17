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

    private $logger;
    private $doctrine;
    private $rpcService;
    private $mqttFactory;
    private $cacheFactory;
    /** ssid id => ['at' => …, 'auto' => bool, 'moving' => bool]; see managesOwnKeys() */
    private $flagCache = [];

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Factory\CacheFactory $cacheFactory
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
    }

    /**
     * @return Ppsk[]
     */
    public function getForSsid($ssid)
    {
        return $this->doctrine->getRepository('ApManBundle:Ppsk')->findBy(
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
            $row = $this->doctrine->getManager()->getConnection()->fetchAssoc(
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
        if (!$ssid || !$this->managesOwnKeys($ssid)) {
            return null;
        }
        if (\ApManBundle\Entity\Ppsk::ANY_MAC !== $shared->getMac()) {
            return null;
        }
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
            return null;
        }

        $em = $this->doctrine->getManager();
        $repo = $this->doctrine->getRepository('ApManBundle:Ppsk');
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
            $row = $connection->fetchAssoc('SELECT id, keyid, enabled FROM ppsk WHERE id = :id', ['id' => $id]);
            if (!$row || $row['enabled']) {
                continue;
            }
            $connection->executeUpdate('UPDATE ppsk SET enabled = 1 WHERE id = :id', ['id' => $id]);
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
    public function deauthenticate($ssid, $mac)
    {
        $mac = strtolower((string) $mac);
        $sent = [];
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
            return $sent;
        }

        $client = null;
        foreach ($ssid->getDevices() as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status) || !isset($status['stations']) || !is_array($status['stations'])) {
                continue;
            }
            $here = false;
            foreach (array_keys($status['stations']) as $station) {
                if (strtolower((string) $station) === $mac) {
                    $here = true;
                    break;
                }
            }
            if (!$here) {
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
            $opts->ban_time = 0;
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
                .implode(', ', array_map(function ($e) { return $e['ap'].'/'.$e['ifname']; }, $sent)));
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
        $radius = $this->usesRadius($ssid);
        $out = ['ok' => true, 'result' => [], 'disconnected' => []];

        // Where a psk file is in play, distributing is the withdrawal:
        // RELOAD_WPA_PSK drops exactly the stations whose key no longer
        // matches and leaves everybody else connected.
        if ($delivery['psk']) {
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
        if ($radius && $mac) {
            $out['disconnected'] = $this->deauthenticate($ssid, $mac);
        }

        return $out;
    }

    /**
     * Create an iPSK: a generated key that is an identity of its own, bound to
     * no MAC address. The caller distributes it afterwards.
     */
    public function createIpsk($ssid, $name, $vid = null, $pinMac = true)
    {
        $em = $this->doctrine->getManager();
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
    private function readState($client, array $ifnamesByAp, $timeout = 8)
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
            $wait[$id] = ['ap' => $apName, 'what' => 'sections'];

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
        foreach ($this->doctrine->getRepository('ApManBundle:Ppsk')->findBy(['ssid' => $ssid]) as $entry) {
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
        $client = $this->doctrine->getRepository('ApManBundle:Client')->findOneBy(['mac' => $mac]);
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

        // A network that only speaks SAE has no wpa_psk_file at all: writing
        // one would leave an unused file behind and RELOAD_WPA_PSK would fail.
        // Its keys travel through the uci sections alone and take effect at the
        // next wireless reload.
        $delivery = $this->keyDelivery($ssid);
        $runtime = $delivery['psk'];
        if (!$runtime) {
            $this->logger->info('PpskService: '.$ssid->getName().
                ' is SAE only, persisting the keys in uci without touching a runtime file');
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
        $state = $this->readState($client, $ifnamesByAp);
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
            $uciCommands = ['list' => [], 'options' => ['cancel_on_error' => false]];
            $dropped = 0;
            foreach ($current[$apName] as $name => $values) {
                $iface = $values['iface'] ?? null;
                if (is_array($iface)) {
                    $iface = $iface[0] ?? null;
                }
                if (null === $iface || !isset($ifaces[$iface])) {
                    continue;   // belongs to another ssid, not ours to touch
                }
                $opts = new \stdClass();
                $opts->config = 'wireless';
                $opts->section = $name;
                $uciCommands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-del-'.(++$dropped).'-'.$run, 'call', null, 'uci', 'delete', $opts
                );
            }
            foreach ($expected as $section) {
                $uciCommands['list'][] = $this->rpcService->createRpcRequest(
                    'ppsk-add-'.$section->name.'-'.$run, 'call', null, 'uci', 'add', $section
                );
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
                $committed['ppsk-commit-'.$apName.'-'.$run] = $apName;
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
                'staged' => $runtime ? [] : $targets];
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
            $results = $this->verifyAndReload($client, $verify, $content, $hash, $run, $results);
        }
        $client->disconnect();

        return $results;
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
    private function verifyAndReload($client, array $verify, $content, $hash, $run, array $results)
    {
        $wait = [];
        foreach ($verify as $apName => $what) {
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
                    'reload' => 'not applicable — SAE takes effect at the next wireless reload'];
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
            $existing = $this->doctrine->getRepository('ApManBundle:Ppsk')
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
                    $existing = $this->doctrine->getRepository('ApManBundle:Ppsk')
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
