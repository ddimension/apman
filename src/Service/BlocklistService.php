<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Entity\Device;

/**
 * Keeping a station off the whole fleet, using a mechanism that only knows one bss.
 *
 * hostapd throws a station off with `del_client` and keeps it off for
 * `ban_time` milliseconds — measured on ap-av-grwz on 23.08.2026: 15000 kept it
 * out for about fifteen seconds, and `list_bans` on that bss listed it for
 * exactly that long and then stopped. That is the whole of what an access point
 * offers, and three things are missing from it:
 *
 *   it is per bss, so eleven bsses need eleven bans;
 *   it is in memory, so a restart forgets it;
 *   it has an end, so it is a delay and not a decision.
 *
 * The decision therefore lives in the database and this puts it into effect:
 * every bss the station is on right now gets a ban, and any bss it reappears on
 * later gets one when it does, because the control channel says so. The station
 * is associated for a moment each time before it is thrown off, and that moment
 * cannot be closed from here — closing it means a mac address filter in the
 * configuration, and `macaddr_acl` has taken a whole radio off the air in this
 * fleet before, so it is not reached for lightly.
 *
 * The ban that is sent is deliberately longer than the check interval rather
 * than as long as the block: a ban that outlives the decision would keep a
 * station out after somebody let it back in, and the access point has no way of
 * being told otherwise short of a restart.
 */
class BlocklistService
{
    /**
     * how long a single ban lasts on the access point, in milliseconds
     *
     * Deliberately short. A ban cannot be lifted early — hostapd has no call
     * for it, it can only be let to lapse — so every millisecond of it is a
     * millisecond that letting somebody back on takes to happen. What makes a
     * block last is not the length of one ban but the control channel putting
     * another one on the moment the station reappears, and that costs one
     * message a minute for a station that keeps trying. A station that is
     * banned cannot associate at all, so there is nothing to answer in between.
     */
    public const BAN_MS = 60000;

    /** hostapd's reason code 5: the access point cannot handle this station */
    public const REASON = 5;

    public function __construct(
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly ApUbusService $ubus,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
    ) {
    }

    /**
     * Whether this station is blocked, asked of the database every time.
     *
     * A scalar query and not the repository: the caller that matters is the
     * subscriber, it never stops, and Doctrine would hand a long-running
     * process the Client it loaded hours ago. The same trap cost the airtime
     * reapply its first afternoon.
     *
     * @return \DateTimeInterface|null the end of the block, or null
     */
    public function blockedUntil(string $mac): ?\DateTimeInterface
    {
        $rows = $this->doctrine->getManager()->createQuery(
            'SELECT c.blockedUntil FROM ApManBundle\Entity\Client c WHERE c.mac = :mac'
        )->setParameter('mac', strtolower($mac))->setMaxResults(1)->getScalarResult();
        $value = $rows[0]['blockedUntil'] ?? null;
        if (null === $value) {
            return null;
        }
        $until = $value instanceof \DateTimeInterface ? $value : new \DateTime((string) $value);

        return $until > new \DateTime() ? $until : null;
    }

    /**
     * Throw this station off one bss and keep it off for a while.
     *
     * @param bool $wait false publishes and returns, for the subscriber's loop
     */
    public function ban(Device $device, string $mac, bool $wait = false): array
    {
        $radio = $device->getRadio();
        $ap = $radio ? $radio->getAccessPoint() : null;
        $ifname = (string) $device->ifname();
        if (!$ap || '' === $ifname) {
            return ['ok' => false, 'error' => 'this bss has no access point or no interface name'];
        }
        $opts = new \stdClass();
        $opts->addr = strtolower($mac);
        $opts->reason = self::REASON;
        $opts->deauth = true;
        $opts->ban_time = self::BAN_MS;

        if (!$wait) {
            $sent = $this->ubus->callAsync($ap, 'hostapd.'.$ifname, 'del_client', $opts);

            return ['ok' => $sent, 'ap' => $ap->getName(), 'ifname' => $ifname,
                'ban_ms' => self::BAN_MS, 'awaited' => false];
        }
        $res = $this->ubus->call($ap, 'hostapd.'.$ifname, 'del_client', $opts, 8);

        return ['ok' => $res->isOk(), 'ap' => $ap->getName(), 'ifname' => $ifname,
            'ban_ms' => self::BAN_MS, 'error' => $res->isOk() ? null : $res->why()];
    }

    /**
     * Who is currently banned on this bss, as the access point sees it.
     *
     * @return string[]|null null if it could not be asked
     */
    public function bans(Device $device): ?array
    {
        $radio = $device->getRadio();
        $ap = $radio ? $radio->getAccessPoint() : null;
        $ifname = (string) $device->ifname();
        if (!$ap || '' === $ifname) {
            return null;
        }
        $res = $this->ubus->call($ap, 'hostapd.'.$ifname, 'list_bans', null, 6);
        if (!$res->isOk()) {
            return null;
        }
        $clients = is_object($res->data) ? ($res->data->clients ?? null) : null;
        if (!is_array($clients)) {
            return null;
        }

        return array_values(array_filter(array_map('strtolower', $clients), 'strlen'));
    }

    /**
     * Every ban standing on this access point right now, by bss.
     *
     * One batch rather than one call per bss: eleven bsses is eleven round
     * trips otherwise, and the agent answers a batch in one message.
     *
     * @return array<string,string[]> ifname => macs
     */
    public function bansOf(AccessPoint $ap): array
    {
        $devices = [];
        foreach ($ap->getRadios() as $radio) {
            foreach ($radio->getDevices() as $device) {
                $ifname = (string) $device->ifname();
                if ('' !== $ifname) {
                    $devices[$ifname] = $device;
                }
            }
        }
        if (!$devices) {
            return [];
        }
        $calls = [];
        foreach (array_keys($devices) as $ifname) {
            $calls[] = ['object' => 'hostapd.'.$ifname, 'method' => 'list_bans', 'args' => null];
        }
        $answers = $this->ubus->callMany($ap, $calls, 10);

        $out = [];
        foreach (array_keys($devices) as $i => $ifname) {
            $res = $answers[$i] ?? null;
            if (!$res || !$res->isOk()) {
                continue;
            }
            $clients = is_object($res->data) ? ($res->data->clients ?? null) : null;
            if (!is_array($clients) || !$clients) {
                continue;
            }
            $out[$ifname] = array_values(array_filter(array_map('strtolower', $clients), 'strlen'));
        }

        return $out;
    }

    /**
     * Put a block into effect everywhere the station is right now.
     *
     * "Right now" comes from the status cache, which already knows where every
     * station is associated — asking the fleet again would be a round trip per
     * bss to learn something the last status cycle wrote down.
     *
     * @return array one entry per bss it was thrown off
     */
    public function enforce(string $mac, bool $wait = true): array
    {
        $mac = strtolower($mac);
        $em = $this->doctrine->getManager();
        $devices = $em->createQuery('SELECT d,r,a FROM ApManBundle\Entity\Device d
                JOIN d.radio r JOIN r.accesspoint a')->getResult();

        $hit = [];
        foreach ($devices as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            if (!is_array($status) || !isset($status['stations'][$mac])) {
                continue;
            }
            $hit[(string) $device->ifname()] = $this->ban($device, $mac, $wait);
        }

        return $hit;
    }

    /**
     * A blocked station got in anyway. Throw it back off.
     *
     * This is the loop that makes a per bss, in memory, expiring ban behave
     * like a decision: the ban ran out, or the bss restarted, or the access
     * point rebooted, and the station is back. The control channel says so
     * within milliseconds and this answers without waiting for anything —
     * waiting here would stop the controller for every access point at once.
     */
    public function onConnect(Device $device, string $mac): bool
    {
        $until = $this->blockedUntil($mac);
        if (null === $until) {
            return false;
        }
        $res = $this->ban($device, $mac, false);
        $this->logger->notice('blocklist: '.$mac.' associated to '.$device->ifname()
            .' and is blocked until '.$until->format('d.m. H:i').' — thrown off and banned for '
            .(int) (self::BAN_MS / 1000).'s'
            .(($res['ok'] ?? false) ? '' : ' (that did not go out: '.($res['error'] ?? 'no mqtt').')'));

        return true;
    }

    /**
     * Everyone with a block on them, expired ones included.
     *
     * @return \ApManBundle\Entity\Client[]
     */
    public function blocked(): array
    {
        return $this->doctrine->getManager()->createQuery(
            'SELECT c FROM ApManBundle\Entity\Client c WHERE c.blockedUntil IS NOT NULL ORDER BY c.blockedUntil DESC'
        )->getResult();
    }
}
