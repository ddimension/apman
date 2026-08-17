<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Ppsk;
use SWSN\ReactRadius\Attribute\AttributeInterface;
use SWSN\ReactRadius\Attribute\StringAttribute;
use SWSN\ReactRadius\Attribute\TunnelAttribute;
use SWSN\ReactRadius\Connection\Context;
use SWSN\ReactRadius\Packet\PacketInterface;

/**
 * What the controller answers when an access point asks about a station.
 *
 * hostapd asks once per association attempt, before the handshake: User-Name
 * and User-Password both carry the station's MAC, Called-Station-Id carries
 * "<BSSID>:<SSID>". The answer either carries the key that station is to use,
 * as a Tunnel-Password, or it is a reject and the station never gets to try.
 *
 * Two properties of hostapd shape everything here:
 *
 *  - With WPA2 it collects *all* Tunnel-Password attributes and tries them in
 *    turn during the four way handshake. With SAE it only ever uses the first
 *    one — sae_get_password() takes the first passphrase in the list. So the
 *    order is part of the answer: the key bound to this MAC comes first, the
 *    keys that are not bound to any MAC come after it.
 *  - macaddr_acl=2 means a reject is final. Which is why an SSID can be told to
 *    fall back to its network passphrase for stations we do not know: that is
 *    what the network did before it had per device keys, and switching it on
 *    must not lock anybody out.
 */
class RadiusAuthService
{
    public const RESULT_ACCEPT = 'accept';
    public const RESULT_FALLBACK = 'fallback';
    public const RESULT_REJECT = 'reject';

    /** RFC 2868: VLAN */
    private const TUNNEL_TYPE_VLAN = 13;
    /** RFC 2868: IEEE-802 */
    private const TUNNEL_MEDIUM_802 = 6;

    private $logger;
    private $doctrine;
    private $cacheFactory;
    /** broadcast name => ['ssid' => SSID, 'key' => string, ...], rebuilt on a timer */
    private $ssidCache = [];
    private $ssidCacheAge = 0;
    private const SSID_CACHE_TTL = 30;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Factory\CacheFactory $cacheFactory
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->cacheFactory = $cacheFactory;
    }

    /**
     * Answer everything with one key, for bringing the socket up: it proves
     * the packet path and the Tunnel-Password encryption without the database
     * having a say. Switched on with RADIUS_TEST_PSK, off in normal operation.
     */
    public function answerFixed(Context $context, $psk)
    {
        $request = $this->readRequest($context);
        $response = $context->getResponse();
        $response->addAttributes([
            new StringAttribute(AttributeInterface::ATTR_REPLY_MESSAGE, 'apman test answer'),
            new TunnelAttribute(AttributeInterface::ATTR_TUNNEL_PASSWORD, 0, $psk),
        ]);
        $response->setType(PacketInterface::ACCESS_ACCEPT);
        $this->logger->info('RadiusAuth: test answer for '.$request['mac'].' on '.$request['ssid']);
    }

    /**
     * The real thing.
     */
    public function handle(Context $context)
    {
        $started = microtime(true);
        $request = $this->readRequest($context);
        $response = $context->getResponse();

        $entry = $this->findSsid($request['ssid']);
        if (!$entry) {
            $this->finish($context, $request, self::RESULT_REJECT,
                'no ssid "'.$request['ssid'].'" in the controller', [], null, $started);

            return;
        }

        $keys = $this->candidates($entry['id'], $request['mac']);
        $result = $keys ? self::RESULT_ACCEPT : self::RESULT_REJECT;
        $reason = $keys ? 'own key' : 'no key for this station';
        $identity = $keys ? reset($keys) : null;

        if (!$keys && $entry['fallback'] && '' !== (string) $entry['key']) {
            $result = self::RESULT_FALLBACK;
            $reason = 'network passphrase';
        }

        if (self::RESULT_REJECT === $result) {
            $response->setType(PacketInterface::ACCESS_REJECT);
            $response->addAttributes([
                new StringAttribute(AttributeInterface::ATTR_REPLY_MESSAGE, 'apman: '.$reason),
            ]);
            $this->finish($context, $request, $result, $reason, [], null, $started);

            return;
        }

        $attributes = [];
        if (self::RESULT_FALLBACK === $result) {
            $attributes[] = new TunnelAttribute(AttributeInterface::ATTR_TUNNEL_PASSWORD, 0, $entry['key']);
            $name = 'network';
        } else {
            // The network passphrase goes first in the packet, which makes it
            // *last* in hostapd's list — offered to everybody, preferred by
            // nobody. Without it a device that knows only the network
            // passphrase would be turned away the moment a single per device
            // key exists, because those keys alone would be the answer. That is
            // exactly what the catch all rule of a classic RADIUS setup does,
            // and losing it would lock out every ordinary device on the network.
            if ($entry['fallback'] && '' !== (string) $entry['key']) {
                $known = false;
                foreach ($keys as $key) {
                    if ($key['psk'] === $entry['key']) {
                        $known = true;
                        break;
                    }
                }
                if (!$known) {
                    $attributes[] = new TunnelAttribute(AttributeInterface::ATTR_TUNNEL_PASSWORD, 0, $entry['key']);
                }
            }

            // Least specific first, most specific last — deliberately the
            // reverse of the order they are wanted in.
            //
            // hostapd reads the Tunnel-Password attributes in packet order and
            // *prepends* each one to the station's key list, so the last
            // attribute of the packet ends up first in its list. With WPA2 that
            // makes no difference, it tries all of them during the handshake.
            // With SAE it decides everything: sae_get_password() takes the
            // first passphrase in that list and never looks at another one.
            // Measured on hostapd 2024.09: with the MAC agnostic key sent last,
            // a station with a key of its own was rejected with status 15;
            // sending it first made the same station connect.
            foreach (array_reverse($keys) as $key) {
                $attributes[] = new TunnelAttribute(AttributeInterface::ATTR_TUNNEL_PASSWORD, 0, $key['psk']);
            }
            $name = $identity['keyid'] ?: ($identity['name'] ?: $identity['mac']);
            // A VLAN belongs to the key, and only the first key can carry one:
            // hostapd applies one VLAN per station, and which key it ends up
            // using is decided in the handshake, not here.
            //
            // All three are tagged attributes (RFC 2868), and tag 0 is what
            // the FreeRADIUS next door sends: on the wire this reads
            // Tunnel-Type:0 = VLAN, Tunnel-Medium-Type:0 = IEEE-802,
            // Tunnel-Private-Group-Id:0 = "26" — the group id a string, not a
            // number.
            if (null !== $identity['vid']) {
                $attributes[] = new TunnelAttribute(AttributeInterface::ATTR_TUNNEL_TYPE, 0, self::TUNNEL_TYPE_VLAN);
                $attributes[] = new TunnelAttribute(AttributeInterface::ATTR_TUNNEL_MEDIUM_TYPE, 0, self::TUNNEL_MEDIUM_802);
                $attributes[] = new TunnelAttribute(AttributeInterface::ATTR_TUNNEL_PRIVATE_GROUP_ID, 0, (string) $identity['vid']);
            }
        }
        $attributes[] = new StringAttribute(AttributeInterface::ATTR_REPLY_MESSAGE, 'apman: '.$name);

        $response->addAttributes($attributes);
        $response->setType(PacketInterface::ACCESS_ACCEPT);

        $this->finish($context, $request, $result, $reason, $keys, $identity, $started);
    }

    /**
     * The attributes we care about, in the shapes the rest of the controller
     * uses: MAC lower case with colons, SSID as broadcast.
     */
    private function readRequest(Context $context)
    {
        $request = $context->getRequest();
        $get = function ($type) use ($request) {
            $found = $request->getAttribute($type);
            $first = reset($found);

            return $first ? (string) $first->getValue() : null;
        };

        $called = (string) $get(AttributeInterface::ATTR_CALLED_STATION_ID);
        $ssid = '';
        if (false !== strpos($called, ':')) {
            // "<BSSID>:<SSID>" — the SSID may contain colons itself, the BSSID
            // may not, so split once from the left
            $ssid = substr($called, strpos($called, ':') + 1);
        }

        $mac = $get(AttributeInterface::ATTR_CALLING_STATION_ID);
        if (null === $mac || '' === $mac) {
            $mac = $get(AttributeInterface::ATTR_USER_NAME);
        }

        return [
            'mac' => $this->normaliseMac($mac),
            'user' => $get(AttributeInterface::ATTR_USER_NAME),
            'ssid' => $ssid,
            'called' => $called,
            'nas' => $get(AttributeInterface::ATTR_NAS_IDENTIFIER) ?: $get(AttributeInterface::ATTR_NAS_IP_ADDRESS),
        ];
    }

    /**
     * Keys that may be handed to this station, most specific first.
     *
     * Read as rows, not as entities, and that is not an optimisation: in a
     * process that never ends, Doctrine hands out whatever it hydrated the
     * first time. A key revoked in the interface would keep being answered
     * with, which is the one thing a revocation must not do. Rows come from
     * the database every time.
     */
    private function candidates($ssidId, $mac)
    {
        $rows = $this->query(
            'SELECT id, psk, keyid, name, vid, mac FROM ppsk'
            .' WHERE ssid_id = :ssid AND enabled = 1 AND (mac = :mac OR mac = :any)'
            // the key bound to this MAC first, the MAC agnostic ones after it:
            // with SAE hostapd only ever uses the first one
            .' ORDER BY (mac = :any) ASC, id ASC',
            ['ssid' => $ssidId, 'mac' => (string) $mac, 'any' => Ppsk::ANY_MAC]
        );

        return $rows;
    }

    /**
     * SSIDs by the name they broadcast — which is what the access point sends.
     *
     * Rebuilt every half minute so a change in the interface takes effect
     * without a restart, and again as rows rather than entities for the reason
     * above: the fallback switch is read here, and a switch that only takes
     * effect after a restart is not a switch.
     */
    private function findSsid($name)
    {
        if ('' === (string) $name) {
            return null;
        }
        if ((time() - $this->ssidCacheAge) > self::SSID_CACHE_TTL) {
            $rows = $this->query(
                'SELECT s.id, s.radius_fallback,'
                ." MAX(CASE WHEN o.name = 'ssid' THEN o.value END) AS broadcast,"
                ." MAX(CASE WHEN o.name = 'key' THEN o.value END) AS network_key,"
                ." MAX(CASE WHEN o.name = 'encryption' THEN o.value END) AS encryption"
                .' FROM ssid s LEFT JOIN ssid_config_option o ON o.ssid_id = s.id'
                .' GROUP BY s.id, s.radius_fallback'
            );
            $this->ssidCache = [];
            foreach ($rows as $row) {
                if (!$row['broadcast']) {
                    continue;
                }
                $this->ssidCache[$row['broadcast']] = [
                    'id' => (int) $row['id'],
                    'key' => $row['network_key'],
                    'encryption' => $row['encryption'] ?: 'none',
                    'fallback' => (bool) $row['radius_fallback'],
                ];
            }
            $this->ssidCacheAge = time();
        }

        return $this->ssidCache[$name] ?? null;
    }

    /**
     * A query that survives the database going away underneath a process that
     * runs for weeks: one retry after a reconnect, then give up and let the
     * caller answer without what it wanted to know.
     */
    private function query($sql, array $params = [])
    {
        $connection = $this->doctrine->getManager()->getConnection();
        try {
            return $connection->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->warning('RadiusAuth: query failed, reconnecting: '.$e->getMessage());
            $connection->close();
            $connection->connect();

            return $connection->fetchAll($sql, $params);
        }
    }

    private function execute($sql, array $params = [])
    {
        $connection = $this->doctrine->getManager()->getConnection();
        try {
            return $connection->executeUpdate($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->warning('RadiusAuth: statement failed, reconnecting: '.$e->getMessage());
            $connection->close();
            $connection->connect();

            return $connection->executeUpdate($sql, $params);
        }
    }

    /**
     * Everything that happens after the answer is written: the history row,
     * the counters, the cache entry the client pages read, and the timestamps
     * on the key that was used. For an SAE network this is the only place
     * where a key's use can be seen at all — hostapd's control channel reports
     * no keyid there.
     */
    private function finish(Context $context, array $request, $result, $reason, array $keys, $identity, $started)
    {
        $ms = (microtime(true) - $started) * 1000;
        $this->logger->info(sprintf('RadiusAuth: %s %s on %s (%s, %.1f ms)',
            $result, $request['mac'], $request['ssid'] ?: '?', $reason, $ms));

        try {
            $this->record($request, $result, $reason, $identity, $ms);
        } catch (\Throwable $e) {
            // an answer already written must not be lost over bookkeeping
            $this->logger->error('RadiusAuth: recording failed: '.$e->getMessage());
        }
    }

    /**
     * Written with statements rather than through the entity manager: this
     * runs inside the same process that holds half the access point objects in
     * memory, and a flush here would carry whatever else is dirty along with
     * it. One insert, one update, nothing else moves.
     */
    private function record(array $request, $result, $reason, $identity, $ms)
    {
        $this->execute(
            'INSERT INTO radius_auth (created, mac, ssid_name, nas, result, reason, keyid, ppsk_id, duration_ms)'
            .' VALUES (NOW(), :mac, :ssid, :nas, :result, :reason, :keyid, :ppsk, :ms)',
            [
                'mac' => $request['mac'],
                'ssid' => mb_substr((string) $request['ssid'], 0, 64),
                'nas' => mb_substr((string) $request['nas'], 0, 128),
                'result' => $result,
                'reason' => mb_substr((string) $reason, 0, 128),
                'keyid' => $identity ? $identity['keyid'] : null,
                'ppsk' => $identity ? $identity['id'] : null,
                'ms' => $ms,
            ]
        );

        if ($identity && self::RESULT_ACCEPT === $result) {
            // For an SAE network this is the only trace a key's use leaves:
            // the control channel reports no keyid there.
            $this->execute(
                'UPDATE ppsk SET first_seen = COALESCE(first_seen, NOW()), last_seen = NOW(), last_mac = :mac'
                .' WHERE id = :id',
                ['mac' => $request['mac'], 'id' => $identity['id']]
            );
        }

        // the client pages read this, filled by the FreeRADIUS export until now
        $this->cacheFactory->addCacheItem(
            'client.authtablev2.'.$request['ssid'].$request['mac'],
            json_encode([
                'mac' => $request['mac'],
                'ssid' => $request['ssid'],
                'username' => $request['user'],
                'auth' => [
                    'reply' => [
                        'APMAN-PSK-Type' => $identity ? 'ppsk' : 'psk',
                        'APMAN-Client-Name' => $identity ? (string) ($identity['name'] ?: $identity['keyid']) : '',
                        'Reply-Message' => $reason,
                        'Result' => $result,
                    ],
                    'post_auth' => [
                        'Called-Station-Id' => $request['called'],
                        'NAS-Identifier' => $request['nas'],
                        'User-Name' => $request['user'],
                    ],
                ],
                'timestamp' => time(),
            ]),
            7 * 86400
        );
    }

    private function normaliseMac($mac)
    {
        $raw = strtolower(preg_replace('/[^0-9a-f]/i', '', (string) $mac));
        if (12 !== strlen($raw)) {
            return strtolower((string) $mac);
        }

        return implode(':', str_split($raw, 2));
    }
}
