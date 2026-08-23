<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Client;
use ApManBundle\Entity\Device;

/**
 * Who yields to whom when two stations want the medium at the same moment.
 *
 * mac80211 shares airtime between stations in proportion to a weight, 256 for
 * everybody by default. Doubling one station's weight does not give it a faster
 * connection and does not cap anybody — a station alone on a radio gets the
 * whole radio whatever this says. It decides the order of yielding, which is
 * the thing that matters when one slow client is holding up a busy radio.
 *
 * Three facts, all measured on ap-av-grwz on 23.08.2026, and each of them is a
 * trap for anyone who does not know it:
 *
 * **hostapd lies about success.** `update_airtime` answers ok on a radio that
 * has no `airtime_mode`, and the driver value does not move. So every path
 * here checks the radio first and says what it found, rather than reporting a
 * success the station never saw.
 *
 * **Zero does not mean normal.** With the policy on, a weight of 0 is ignored
 * and the previous value stands. Going back to normal means sending 256, which
 * is why DEFAULT_WEIGHT exists as a number to send rather than as a comment.
 *
 * **Nothing persists it.** A station set to 700, deauthenticated, came back at
 * 256. wifi-scripts have no per-station weight to render, so the access point
 * never remembers one. The database holds the intention and this puts it back
 * on every connect.
 */
class AirtimeService
{
    /** what mac80211 gives a station nobody has an opinion about */
    public const DEFAULT_WEIGHT = 256;

    /** below this a station is starved rather than deprioritised */
    public const MIN_WEIGHT = 1;

    /** mac80211 stores the weight in a u16 */
    public const MAX_WEIGHT = 65535;

    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly ApUbusService $ubus,
    ) {
    }

    /**
     * Whether this radio shares airtime at all.
     *
     * Read from the configuration rather than from the device: it is a
     * wifi-device option, provisioning is the only thing that sets it, and the
     * generated hostapd configuration agrees with it as soon as the radio has
     * been provisioned. A radio whose database says one thing and whose phy
     * says another is a drift finding and belongs to the audit, not here.
     */
    public function policyOf(\ApManBundle\Entity\Radio $radio): ?string
    {
        $config = $radio->exportConfig();
        $mode = $config->airtime_mode ?? null;

        return null === $mode || '' === (string) $mode ? null : (string) $mode;
    }

    /**
     * What a weight means on this radio, in a sentence, or why it means nothing.
     */
    public function explain(\ApManBundle\Entity\Radio $radio): array
    {
        $mode = $this->policyOf($radio);
        if (null === $mode || '0' === $mode) {
            return ['ok' => false, 'mode' => $mode,
                'text' => 'this radio shares airtime evenly and ignores weights — hostapd accepts '
                    .'them and answers success, and the driver value does not move. Set '
                    .'airtime_mode on the radio first; that is a radio level change and restarts '
                    .'this phy.'];
        }
        $said = [
            '1' => 'static: the weights are used as they are given, which is what this page sets',
            '2' => 'dynamic: hostapd works the station weights out from the bss weights on its '
                .'own, so a weight set here is overwritten again',
            '3' => 'limit: the bss airtime limit applies and station weights only decide the '
                .'share inside it',
        ];

        return ['ok' => '1' === $mode || '3' === $mode, 'mode' => $mode,
            'text' => $said[$mode] ?? 'airtime_mode='.$mode.', which this version does not know about'];
    }

    /**
     * Put one client's weight on one bss.
     *
     * @param int|null $weight null resets to the default, which means sending
     *                         the default and not sending nothing
     * @param bool     $wait   false publishes and returns, for callers inside
     *                         the subscriber's event loop
     */
    public function set(Device $device, string $mac, ?int $weight, bool $wait = true): array
    {
        $radio = $device->getRadio();
        $ap = $radio ? $radio->getAccessPoint() : null;
        $ifname = (string) $device->ifname();
        if (!$ap || '' === $ifname) {
            return ['ok' => false, 'error' => 'this bss has no access point or no interface name'];
        }

        $send = null === $weight ? self::DEFAULT_WEIGHT : $this->clamp($weight);
        $policy = $this->explain($radio);

        $opts = new \stdClass();
        $opts->sta = strtolower($mac);
        $opts->weight = $send;

        if (!$wait) {
            // Measured on 23.08.2026: waiting for this answer from inside the
            // control channel handler blocked the subscriber for the full eight
            // seconds and then reported a timeout — while the weight had in
            // fact been set. The answer carries nothing; the wait carried the
            // whole controller.
            $sent = $this->ubus->callAsync($ap, 'hostapd.'.$ifname, 'update_airtime', $opts);

            return ['ok' => $sent, 'weight' => $send, 'effective' => $policy['ok'],
                'note' => $policy['ok'] ? null : $policy['text'], 'ap' => $ap->getName(),
                'ifname' => $ifname, 'awaited' => false,
                'error' => $sent ? null : 'there was no mqtt connection to send it on'];
        }

        $res = $this->ubus->call($ap, 'hostapd.'.$ifname, 'update_airtime', $opts, 8);
        if (!$res->isOk()) {
            return ['ok' => false, 'error' => $res->why(), 'weight' => $send];
        }

        // The call succeeding is not the station having the weight — that is
        // the whole point of the airtime_mode trap — so the answer says which
        // of the two happened.
        return [
            'ok' => true,
            'weight' => $send,
            'effective' => $policy['ok'],
            'note' => $policy['ok'] ? null : $policy['text'],
            'ap' => $ap->getName(),
            'ifname' => $ifname,
        ];
    }

    /**
     * The weights the driver is actually using on this bss.
     *
     * `iw station dump` rather than anything on the hostapd object: hostapd
     * writes the weight and never reads it back, so it cannot say what the
     * driver made of it, and the difference between the two is exactly what is
     * worth showing.
     *
     * @return array<string,int>|null mac => weight, or null if it could not be read
     */
    public function running(Device $device): ?array
    {
        $radio = $device->getRadio();
        $ap = $radio ? $radio->getAccessPoint() : null;
        $ifname = (string) $device->ifname();
        if (!$ap || '' === $ifname) {
            return null;
        }
        $opts = new \stdClass();
        $opts->command = '/usr/sbin/iw';
        $opts->params = ['dev', $ifname, 'station', 'dump'];
        $res = $this->ubus->call($ap, 'file', 'exec', $opts, 10);
        if (!$res->isOk()) {
            return null;
        }
        $stdout = is_object($res->data) ? (string) ($res->data->stdout ?? '') : '';

        return $this->parseDump($stdout);
    }

    /**
     * mac => airtime weight, from `iw station dump`.
     *
     * Pure, because the parsing is the part that can be wrong and a fixture is
     * cheaper than an access point.
     *
     * @return array<string,int>|null
     */
    public function parseDump(string $stdout): ?array
    {
        if ('' === trim($stdout)) {
            return null;
        }
        $weights = [];
        $current = null;
        foreach (explode("\n", $stdout) as $line) {
            if (preg_match('/^Station\s+([0-9a-f:]{17})/i', trim($line), $m)) {
                $current = strtolower($m[1]);
                continue;
            }
            if (null !== $current && preg_match('/^airtime weight:\s*(\d+)/i', trim($line), $m)) {
                $weights[$current] = (int) $m[1];
                $current = null;
            }
        }

        return $weights;
    }

    /**
     * Put the stored weight back after a station associated.
     *
     * Called from the control channel, where AP-STA-CONNECTED arrives. Silent
     * when there is nothing to say: most stations have no weight, and a log
     * line per association would drown the ones that do.
     */
    public function onConnect(Device $device, string $mac): void
    {
        $weight = $this->intendedWeight($mac);
        if (null === $weight || self::DEFAULT_WEIGHT === $weight) {
            return;
        }
        $res = $this->set($device, $mac, $weight, false);
        if (!($res['ok'] ?? false)) {
            $this->logger->warning('airtime: could not put '.$mac.' back on weight '.$weight
                .' after it associated to '.$device->ifname().': '.($res['error'] ?? 'no answer'));

            return;
        }
        // notice, not info: prod does not carry info to the journal, and this
        // is the controller reaching out and changing something on a device.
        // An action nobody can see afterwards is an action nobody can check.
        $this->logger->notice('airtime: '.$mac.' is back on '.$device->ifname()
            .' and was sent weight '.$weight
            .($res['effective'] ? '' : ' — which this radio ignores, it has no airtime policy'));
    }

    /**
     * The weight we want this station to have, read from the database itself.
     *
     * A scalar query rather than the repository, because the caller that
     * matters here is the subscriber and the subscriber never stops. Doctrine
     * hands a long-running process the entity it already has in its identity
     * map, so a weight set through the web an hour ago is invisible to it — it
     * was, and the reapply sat there doing nothing for exactly that reason
     * until this stopped going through the repository. A scalar result has no
     * identity to reuse and always asks.
     */
    public function intendedWeight(string $mac): ?int
    {
        $rows = $this->doctrine->getManager()->createQuery(
            'SELECT c.airtimeWeight FROM ApManBundle\\Entity\\Client c WHERE c.mac = :mac'
        )->setParameter('mac', strtolower($mac))->setMaxResults(1)->getScalarResult();
        $value = $rows[0]['airtimeWeight'] ?? null;

        return null === $value ? null : (int) $value;
    }

    /**
     * Every client we hold an opinion about, and where it currently is.
     *
     * @return Client[]
     */
    public function weighted(): array
    {
        return $this->doctrine->getManager()->createQuery(
            'SELECT c FROM ApManBundle\\Entity\\Client c WHERE c.airtimeWeight IS NOT NULL ORDER BY c.mac'
        )->getResult();
    }

    public function clamp(int $weight): int
    {
        return max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, $weight));
    }
}
