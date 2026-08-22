<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Ppsk;

/**
 * The record of what the access points decided.
 *
 * This used to be the answer as well: a RADIUS server ran inside the
 * subscriber daemon, and hostapd asked it over the network. It does not
 * anymore. Every access point answers its own hostapd on 127.0.0.1 out of a
 * key store the controller distributes, which is faster by two orders of
 * magnitude, survives the controller being unreachable, and cannot be reached
 * by anybody else on the network.
 *
 * What is left here is the other half of that arrangement: the agents publish
 * every decision they make, and this writes it down — for the history, the
 * client pages, and the identity trace that the control channel cannot
 * provide, because it reports no keyid and for SAE reports nothing at all.
 */
class RadiusAuthService
{
    public const RESULT_ACCEPT = 'accept';
    public const RESULT_FALLBACK = 'fallback';
    public const RESULT_REJECT = 'reject';

    private $logger;
    private $doctrine;
    private $cacheFactory;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        \ApManBundle\Factory\CacheFactory $cacheFactory
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->cacheFactory = $cacheFactory;
    }

    private function execute($sql, array $params = [])
    {
        $connection = $this->doctrine->getManager()->getConnection();
        try {
            return $connection->executeStatement($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->warning('RadiusAuth: statement failed, reconnecting: '.$e->getMessage());
            $connection->close();
            $connection->connect();

            return $connection->executeStatement($sql, $params);
        }
    }

    /**
     * One accept/reject as decided by the agent's on-AP RADIUS server.
     *
     * Same history row as record() — the /radius pages read radius_auth —
     * but the decision was made on the AP, not in this process. The key
     * timestamps are handled by PpskService::recordUsed() (pinning and
     * learning need more than an UPDATE); the cache entry the client pages
     * read is filled here the way record() fills it.
     *
     * @param Ppsk|null $ppsk the key the answer named, when it names one
     * @param array     $event the agent's event payload (bssid, key, vid, …)
     */
    public function recordAgentAuth($mac, $ssid, $nas, $result, $reason, $ppsk, array $event)
    {
        $mac = $this->normaliseMac($mac);
        $this->execute(
            'INSERT INTO radius_auth (created, mac, ssid_name, nas, result, reason, keyid, ppsk_id, duration_ms)'
            .' VALUES (NOW(), :mac, :ssid, :nas, :result, :reason, :keyid, :ppsk, :ms)',
            [
                'mac' => $mac,
                'ssid' => mb_substr((string) $ssid, 0, 64),
                'nas' => mb_substr((string) $nas, 0, 128),
                'result' => $result,
                'reason' => mb_substr((string) $reason, 0, 128),
                'keyid' => $ppsk ? $ppsk->getKeyid() : null,
                'ppsk' => $ppsk ? $ppsk->getId() : null,
                // The agent times its own answer and sends it along. Until it
                // did, this column was NULL for every request the access
                // points answered — which is all of them now — so the page's
                // "average answer" was measuring a server nobody asks.
                'ms' => isset($event['ms']) ? round((float) $event['ms'], 3) : null,
            ]
        );

        // the client pages read this, filled by the FreeRADIUS export until now
        $this->cacheFactory->addCacheItem(
            'client.authtablev2.'.$ssid.$mac,
            json_encode([
                'mac' => $mac,
                'ssid' => $ssid,
                'username' => $ppsk ? (string) ($ppsk->getName() ?: $ppsk->getKeyid()) : (string) ($event['key'] ?? $mac),
                'auth' => [
                    'reply' => [
                        'APMAN-PSK-Type' => $ppsk ? 'ppsk' : 'psk',
                        'APMAN-Client-Name' => $ppsk ? (string) ($ppsk->getName() ?: $ppsk->getKeyid()) : '',
                        'Reply-Message' => (string) $reason,
                        'Result' => $result,
                    ],
                    'post_auth' => [
                        'Called-Station-Id' => $event['bssid'] ?? null,
                        'NAS-Identifier' => $nas,
                        'User-Name' => $event['key'] ?? null,
                    ],
                ],
                'timestamp' => time(),
            ]),
            7 * 86400
        );
    }

    public function normaliseMac($mac)
    {
        $raw = strtolower(preg_replace('/[^0-9a-f]/i', '', (string) $mac));
        if (12 !== strlen($raw)) {
            return strtolower((string) $mac);
        }

        return implode(':', str_split($raw, 2));
    }

    /**
     * The network name, out of whatever the access point called it.
     *
     * RADIUS carries the network in Called-Station-Id, and the convention is
     * the bssid and the ssid in one string: `20:4b:e3:b4:f8:76:kalclients`.
     * Passed through unchanged it is a name no network has, so
     * `resolveBySectionName()` refuses the key it was given, no key is
     * resolved, and nothing is stamped onto it — which is exactly the symptom
     * of "the mac is not set on the ppsk object after the first
     * authentication".
     *
     * Measured 2026-08-22: 109 accepts on 2026-08-21 between 18:36 and 21:08
     * arrived with names like `20:4b:e3:b4:f8:kalclients` and resolved no key.
     * The access points send the plain name again since, so this is defence
     * rather than a running fault — and defence is the point, because the
     * failure is silent and looks like a network nobody uses.
     *
     * Four hex groups are required before anything is stripped. A network
     * named `ab:cd:net` keeps its name; one that starts with four of them is a
     * thing nobody has.
     */
    public function normaliseSsid($ssid): string
    {
        $ssid = (string) $ssid;
        if (!preg_match('/^((?:[0-9a-f]{2}[:-]){4,6})(.+)$/i', $ssid, $m)) {
            return $ssid;
        }
        $rest = $m[2];
        // ...and what is left has to look like a name rather than the rest of
        // an address. A bare bssid ends in one more octet, and taking that for
        // a network called "76" would be worse than leaving the whole string
        // alone. Two hex characters is an octet; four — "beef" — is a name
        // somebody chose.
        if (preg_match('/^[0-9a-f]{2}[:-]/i', $rest) || preg_match('/^[0-9a-f]{2}$/i', $rest)
            || '' === trim($rest)) {
            return $ssid;
        }

        return $rest;
    }
}
