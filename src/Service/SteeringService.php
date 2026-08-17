<?php

namespace ApManBundle\Service;

/**
 * 802.11v steering: pick a target the client can actually hear, and learn from
 * what it answers.
 *
 * Two things make this possible that were not used before:
 *
 *  - 802.11k beacon reports tell us what the *client* hears, which is the only
 *    measurement taken at the client instead of at the access point. A target
 *    chosen without it is a guess, and the fleet answered 245 of 250 requests
 *    with "low RSSI" because of that.
 *  - The transition response carries a candidate list. hostapd renders the
 *    numeric status code through blobmsg_add_u8(), so libubox turns it into a
 *    JSON boolean and the reject reason is lost — but the candidate list
 *    survives intact and contains both the MBO reject reason and the BSSIDs the
 *    client would have accepted.
 */
class SteeringService
{
    /** MBO transition rejection reason codes (attribute 7) */
    public const MBO_REASONS = [
        0 => 'unspecified',
        1 => 'excessive frame loss',
        2 => 'excessive delay for current traffic',
        3 => 'insufficient QoS capacity',
        4 => 'low RSSI',
        5 => 'high interference',
        6 => 'service unavailable',
    ];

    /** how long a target stays blocked for a client after a rejection */
    public const BLOCK_TTL = 1800;
    /** consecutive rejections before steering is given up for a client */
    public const GIVE_UP_AFTER = 3;
    /** the client has to hear the target at least this much better */
    public const MIN_GAIN_DB = 8;

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

    /**
     * Decode the candidate list of a transition response.
     *
     * @return array [reject_reason, reason_text, candidates]
     */
    public function parseCandidateList($hex)
    {
        $out = ['reject_reason' => null, 'reason_text' => null, 'candidates' => []];
        if (!is_string($hex) || '' === $hex || !ctype_xdigit($hex)) {
            return $out;
        }
        $raw = @hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);
        if (false === $raw) {
            return $out;
        }

        $i = 0;
        $len = strlen($raw);
        while ($i + 2 <= $len) {
            $id = ord($raw[$i]);
            $elen = ord($raw[$i + 1]);
            $body = substr($raw, $i + 2, $elen);
            $i += 2 + $elen;

            if (52 === $id && strlen($body) >= 13) {
                // neighbour report: bssid, bssid info, op class, channel, phy
                $candidate = [
                    'bssid' => implode(':', str_split(bin2hex(substr($body, 0, 6)), 2)),
                    'op_class' => ord($body[10]),
                    'channel' => ord($body[11]),
                ];
                // optional subelement 3 carries the candidate preference
                $sub = substr($body, 13);
                $j = 0;
                while ($j + 2 <= strlen($sub)) {
                    $sid = ord($sub[$j]);
                    $slen = ord($sub[$j + 1]);
                    if (3 === $sid && $slen >= 1) {
                        $candidate['preference'] = ord($sub[$j + 2]);
                    }
                    $j += 2 + $slen;
                }
                $out['candidates'][] = $candidate;
                continue;
            }

            // vendor specific, wi-fi alliance, mbo-oce
            if (221 === $id && strlen($body) >= 4 && "\x50\x6f\x9a" === substr($body, 0, 3) && 0x16 === ord($body[3])) {
                $attrs = substr($body, 4);
                $j = 0;
                while ($j + 2 <= strlen($attrs)) {
                    $aid = ord($attrs[$j]);
                    $alen = ord($attrs[$j + 1]);
                    if (7 === $aid && $alen >= 1) {
                        $reason = ord($attrs[$j + 2]);
                        $out['reject_reason'] = $reason;
                        $out['reason_text'] = self::MBO_REASONS[$reason] ?? ('reason '.$reason);
                    }
                    $j += 2 + $alen;
                }
            }
        }

        return $out;
    }

    /**
     * Remember what a client answered. Called for every bss-transition-response.
     */
    public function recordResponse($mac, array $data)
    {
        $mac = strtolower($mac);
        // status-code arrives as a boolean because of the u8 rendering:
        // false means 0 means accepted
        $accepted = empty($data['status-code']);
        $parsed = $this->parseCandidateList($data['candidate-list'] ?? '');

        $state = $this->getState($mac);
        // The control channel reports the status as a number, so when the
        // event came from there the real reason is known instead of guessed
        // from the MBO element of the candidate list.
        $state['last'] = [
            'ts' => time(),
            'accepted' => $accepted,
            'reason' => $data['status-text'] ?? $parsed['reason_text'],
            'reason_code' => isset($data['status-code']) && is_int($data['status-code'])
                ? $data['status-code'] : $parsed['reject_reason'],
            'mbo_reason' => $parsed['reason_text'],
            'candidates' => $parsed['candidates'],
            'target' => isset($data['target-bssid']) ? strtolower($data['target-bssid']) : null,
        ];

        if ($accepted) {
            $state['rejects'] = 0;
            $state['blocked'] = [];
            $this->logger->notice('steering('.$mac.'): transition accepted');
        } else {
            $state['rejects'] = ($state['rejects'] ?? 0) + 1;
            // the target we asked for is not one this client will take
            if (!empty($state['pending_target'])) {
                $state['blocked'][$state['pending_target']] = time() + self::BLOCK_TTL;
            }
            $this->logger->notice('steering('.$mac.'): rejected ('.
                ($data['status-text'] ?? $parsed['reason_text'] ?? 'no reason given').'), '.
                $state['rejects'].' in a row');
        }
        // the client tells us what it would take instead
        if ($parsed['candidates']) {
            $state['client_candidates'] = $parsed['candidates'];
        }
        unset($state['pending_target']);
        $this->putState($mac, $state);

        return $state;
    }

    public function getState($mac)
    {
        $state = $this->cacheFactory->getCacheItemValue('steering.state.'.str_replace(':', '', strtolower($mac)));

        return is_array($state) ? $state : ['rejects' => 0, 'blocked' => []];
    }

    public function putState($mac, array $state)
    {
        $this->cacheFactory->addCacheItem('steering.state.'.str_replace(':', '', strtolower($mac)), $state, 7 * 86400);
    }

    public function isGivenUp($mac)
    {
        $state = $this->getState($mac);

        return ($state['rejects'] ?? 0) >= self::GIVE_UP_AFTER;
    }

    public function isBlocked($mac, $bssid)
    {
        $state = $this->getState($mac);
        $until = $state['blocked'][strtolower($bssid)] ?? 0;

        return $until > time();
    }

    /**
     * What the client itself reported hearing, newest report per bssid.
     *
     * @return array bssid => dbm
     */
    public function heardByClient($mac, $maxAge = 3600)
    {
        $em = $this->doctrine->getManager();
        $since = new \DateTime('@'.(time() - $maxAge));
        $since->setTimezone((new \DateTime())->getTimezone());
        $events = $em->createQuery("SELECT e FROM ApManBundle\\Entity\\Event e
                WHERE e.address = :mac AND e.type = 'beacon-report' AND e.ts > :since
                ORDER BY e.ts DESC")
            ->setParameter('mac', strtolower($mac))
            ->setParameter('since', $since)
            ->setMaxResults(300)
            ->getResult();

        $heard = [];
        foreach ($events as $event) {
            $d = json_decode($event->getEvent(), true);
            if (!is_array($d) || empty($d['bssid'])) {
                continue;
            }
            $bssid = strtolower($d['bssid']);
            if (isset($heard[$bssid])) {
                continue;
            }
            $rcpi = isset($d['rcpi']) ? (int) $d['rcpi'] : 255;
            if (255 === $rcpi) {
                continue;
            }
            $heard[$bssid] = round($rcpi / 2 - 110, 1);
        }

        return $heard;
    }

    /**
     * Pick a steering target for a client.
     *
     * Ranked by what the client itself hears, not by what we hear. Candidates
     * the client named in an earlier rejection get priority, targets it already
     * rejected are skipped, and a target has to be clearly better than the
     * current one or the client will answer "low RSSI" again.
     *
     * @return array|null [device, bssid, gain, source]
     */
    public function pickTarget($mac, \ApManBundle\Entity\Device $current, $crossAp = true)
    {
        $mac = strtolower($mac);
        $ssid = $current->getSsid();
        if (!$ssid) {
            return null;
        }
        $heard = $this->heardByClient($mac);
        if (!$heard) {
            return null;
        }
        $state = $this->getState($mac);
        $preferred = [];
        foreach ($state['client_candidates'] ?? [] as $candidate) {
            if (!empty($candidate['bssid'])) {
                $preferred[strtolower($candidate['bssid'])] = true;
            }
        }

        $currentBssid = $current->getAddress() ? strtolower($current->getAddress()) : null;
        $currentHeard = $currentBssid && isset($heard[$currentBssid]) ? $heard[$currentBssid] : null;

        $em = $this->doctrine->getManager();
        $query = $em->createQuery('SELECT d,r,a FROM ApManBundle\\Entity\\Device d
                LEFT JOIN d.radio r LEFT JOIN r.accesspoint a
                WHERE d.ssid = :ssid AND d.id != :current AND d.address IS NOT NULL');
        $query->setParameter('ssid', $ssid);
        $query->setParameter('current', $current->getId());

        $best = null;
        foreach ($query->getResult() as $device) {
            $bssid = strtolower($device->getAddress());
            if (!isset($heard[$bssid])) {
                continue;      // the client never reported hearing this one
            }
            if ($this->isBlocked($mac, $bssid)) {
                continue;
            }
            if (!$crossAp && $device->getRadio()->getAccessPoint()->getId() !== $current->getRadio()->getAccessPoint()->getId()) {
                continue;
            }
            if (null === $device->getRrm()) {
                continue;      // without a neighbour report we cannot name it
            }

            $gain = null === $currentHeard ? null : $heard[$bssid] - $currentHeard;
            if (null !== $gain && $gain < self::MIN_GAIN_DB && !isset($preferred[$bssid])) {
                continue;      // not enough better, the client would refuse
            }

            // band steering intent is kept: a 5/6 GHz target wins over an
            // equally loud 2.4 GHz one, but a much better neighbour on another
            // access point can still win
            $band = $device->getRadio()->getConfigBand();
            $bandBonus = in_array($band, ['5g', '6g'], true) ? 15 : 0;
            $score = $heard[$bssid] + $bandBonus + (isset($preferred[$bssid]) ? 20 : 0);
            if (null === $best || $score > $best['score']) {
                $best = [
                    'device' => $device,
                    'bssid' => $bssid,
                    'heard' => $heard[$bssid],
                    'current_heard' => $currentHeard,
                    'gain' => $gain,
                    'score' => $score,
                    'band' => $band,
                    'source' => isset($preferred[$bssid]) ? 'client candidate' : 'beacon report',
                    'ap' => $device->getRadio()->getAccessPoint()->getName(),
                ];
            }
        }

        return $best;
    }

    public function markPending($mac, $bssid)
    {
        $state = $this->getState($mac);
        $state['pending_target'] = strtolower($bssid);
        $state['pending_since'] = time();
        $this->putState($mac, $state);
    }
}
