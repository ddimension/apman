<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Entity\Device;
use ApManBundle\Entity\Radio;
use ApManBundle\Factory\CacheFactory;
use ApManBundle\Library\NodeState;
use Psr\Log\LoggerInterface;

/**
 * The state of the fleet as a tree: access point → radio → bss.
 *
 * Each node keeps what it knows about itself. What a parent is follows from its
 * own facts plus its children, worked out when it is read — the tree is never
 * stored as a tree, it is assembled from the entity relations that already
 * exist. That way there is nothing to keep in sync.
 *
 * Two rules run through all of it:
 *
 *  - A missing cache entry is *unknown*, never *offline*. Offline is something
 *    an access point tells us (its mqtt last will) or something we conclude
 *    from age. This is the same mistake the flat AccessPointState made, where
 *    an expired entry read back as 0 and 0 meant offline.
 *  - Freshness is decided when reading, not by a cache lifetime. Entries are
 *    kept for a week; a node whose facts are older than MAX_AGE reads as
 *    unknown regardless. One notion of "recent", the same MAX_AGE that
 *    AccessPointService::isLive() has always used.
 *
 * This is stage one: the tree is computed alongside the old flat state and
 * nothing consumes it yet. The log line every state change writes is the point
 * — it is what tells us whether the composition agrees with the machine it is
 * meant to replace.
 */
class StateTreeService
{
    /** long enough that a gap in the traffic is never mistaken for a fact */
    private const TTL = 7 * 86400;

    /** how recent a node's facts have to be to be believed, in seconds */
    private const MAX_AGE = 300;

    private $cacheFactory;
    private $logger;

    public function __construct(CacheFactory $cacheFactory, LoggerInterface $logger)
    {
        $this->cacheFactory = $cacheFactory;
        $this->logger = $logger;
    }

    /**
     * What a radio said about one of its interfaces, plus what hostapd says
     * about the bss itself.
     *
     * @param array $facts present  bool    the radio lists this section
     *                     status   ?string hostapd's own status, 'ENABLED' when up
     *                     cac      ?bool   a channel availability check is running
     *                     managed  ?bool   neighbour/beacon report and BTM are on
     */
    public function observeBss(Device $device, array $facts): void
    {
        $node = $this->read(NodeState::TYPE_BSS, $device->getId());
        $facts = $facts + ($node['facts'] ?? []);
        $this->write(NodeState::TYPE_BSS, $device->getId(), $facts,
            $this->deriveBss($facts, true), $device->getName());
    }

    /**
     * @param array $facts disabled ?bool
     *                     up       ?bool
     *                     pending  ?bool
     *                     failed   ?bool  retry_setup_failed
     */
    public function observeRadio(Radio $radio, array $facts): void
    {
        $node = $this->read(NodeState::TYPE_RADIO, $radio->getId());
        $facts = $facts + ($node['facts'] ?? []);
        $this->write(NodeState::TYPE_RADIO, $radio->getId(), $facts,
            $this->deriveRadioOwn($facts, true), $radio->getName());
    }

    /**
     * @param array $facts online ?bool  from the online topic and its last will
     */
    public function observeAp(AccessPoint $ap, array $facts): void
    {
        $node = $this->read(NodeState::TYPE_AP, $ap->getId());
        $facts = $facts + ($node['facts'] ?? []);
        // The access point's own state is only ever OFFLINE or ONLINE; anything
        // more specific comes from its radios and is worked out in ap().
        $own = (false === ($facts['online'] ?? null)) ? NodeState::AP_OFFLINE : NodeState::AP_ONLINE;
        $this->write(NodeState::TYPE_AP, $ap->getId(), $facts, $own, $ap->getName());
    }

    public function bss(Device $device): array
    {
        $node = $this->read(NodeState::TYPE_BSS, $device->getId());
        $state = $this->deriveBss($node['facts'] ?? [], $this->isFresh($node));

        return $this->present(NodeState::TYPE_BSS, $device->getId(), $device->getName(), $state, $node);
    }

    public function radio(Radio $radio): array
    {
        $node = $this->read(NodeState::TYPE_RADIO, $radio->getId());
        $facts = $node['facts'] ?? [];
        $fresh = $this->isFresh($node);
        $own = $this->deriveRadioOwn($facts, $fresh);

        $children = [];
        foreach ($radio->getDevices() as $device) {
            $children[] = $this->bss($device);
        }

        $out = $this->present(NodeState::TYPE_RADIO, $radio->getId(), $radio->getName(),
            $this->composeRadio($own, $children), $node);
        $out['children'] = $children;

        return $out;
    }

    public function ap(AccessPoint $ap): array
    {
        $node = $this->read(NodeState::TYPE_AP, $ap->getId());

        $children = [];
        foreach ($ap->getRadios() as $radio) {
            $children[] = $this->radio($radio);
        }

        $out = $this->present(NodeState::TYPE_AP, $ap->getId(), $ap->getName(),
            $this->composeAp($node, $children), $node);
        $out['children'] = $children;

        return $out;
    }

    // ---------------------------------------------------------------- deriving

    private function deriveBss(array $facts, bool $fresh): int
    {
        if (!$fresh) {
            return NodeState::BSS_UNKNOWN;
        }
        if (false === ($facts['present'] ?? null)) {
            return NodeState::BSS_ABSENT;
        }
        $status = $facts['status'] ?? null;
        if (null !== $status && 'ENABLED' !== $status) {
            return NodeState::BSS_STARTING;
        }
        if (null === $status) {
            // the radio lists the interface but hostapd has not reported on it
            return NodeState::BSS_STARTING;
        }
        if (true === ($facts['managed'] ?? null)) {
            return NodeState::BSS_ACTIVE;
        }

        return NodeState::BSS_READY;
    }

    /** what the radio knows about itself, before its children are considered */
    private function deriveRadioOwn(array $facts, bool $fresh): int
    {
        // a radio that is switched off says so whatever its age
        if (true === ($facts['disabled'] ?? null)) {
            return NodeState::RADIO_DISABLED;
        }
        if (!$fresh) {
            return NodeState::RADIO_UNKNOWN;
        }
        if (true === ($facts['failed'] ?? null)) {
            return NodeState::RADIO_FAILED;
        }
        if (true === ($facts['pending'] ?? null) || true !== ($facts['up'] ?? null)) {
            return NodeState::RADIO_PENDING;
        }

        return NodeState::RADIO_READY;
    }

    private function composeRadio(int $own, array $children): int
    {
        if (NodeState::RADIO_READY !== $own) {
            return $own;
        }
        // CAC is reported per bss but belongs to the radio: they share a channel
        foreach ($children as $c) {
            if (true === ($c['facts']['cac'] ?? null)) {
                return NodeState::RADIO_CAC;
            }
        }

        $known = 0;
        $active = 0;
        $short = 0;
        foreach ($children as $c) {
            if (NodeState::BSS_UNKNOWN === $c['state']) {
                continue;
            }
            ++$known;
            if (NodeState::BSS_ACTIVE === $c['state']) {
                ++$active;
            } elseif (NodeState::BSS_READY !== $c['state']) {
                ++$short;
            }
        }
        if (!$known) {
            return NodeState::RADIO_READY;
        }
        if ($active === $known) {
            return NodeState::RADIO_ACTIVE;
        }
        if ($short && $active) {
            return NodeState::RADIO_DEGRADED;
        }

        return $short ? NodeState::RADIO_DEGRADED : NodeState::RADIO_READY;
    }

    /**
     * First match wins, in this order:
     * OFFLINE, UNKNOWN, FAILED, CONFIGURING, CAC, DEGRADED, ACTIVE, ONLINE.
     */
    private function composeAp(array $node, array $children): int
    {
        if (false === ($node['facts']['online'] ?? null)) {
            return NodeState::AP_OFFLINE;
        }
        if (!$node) {
            return NodeState::AP_UNKNOWN;
        }

        $states = [];
        foreach ($children as $c) {
            if (NodeState::RADIO_DISABLED === $c['state']) {
                continue;
            }
            $states[] = $c['state'];
        }
        // nothing heard from any radio for a while, and no last will either:
        // the access point is not talking to us any more
        if (!$this->isFresh($node) && (!$states || $states === array_fill(0, count($states), NodeState::RADIO_UNKNOWN))) {
            return NodeState::AP_OFFLINE;
        }
        if (!$states) {
            return NodeState::AP_ONLINE;
        }
        if (in_array(NodeState::RADIO_FAILED, $states, true)) {
            return NodeState::AP_FAILED;
        }
        if (in_array(NodeState::RADIO_PENDING, $states, true)) {
            return NodeState::AP_CONFIGURING;
        }
        if (in_array(NodeState::RADIO_CAC, $states, true)) {
            return NodeState::AP_CAC;
        }
        $active = count(array_filter($states, static fn ($s) => NodeState::RADIO_ACTIVE === $s));
        if ($active === count($states)) {
            return NodeState::AP_ACTIVE;
        }
        if (in_array(NodeState::RADIO_DEGRADED, $states, true) || $active) {
            return NodeState::AP_DEGRADED;
        }

        return NodeState::AP_ONLINE;
    }

    // ----------------------------------------------------------------- storage

    private function key(string $type, $id): string
    {
        return 'state.'.$type.'['.$id.']';
    }

    private function read(string $type, $id): array
    {
        $v = $this->cacheFactory->getCacheItemValue($this->key($type, $id));

        return is_array($v) ? $v : [];
    }

    private function isFresh(array $node): bool
    {
        $seen = $node['seen'] ?? null;

        return null !== $seen && (time() - (int) $seen) <= self::MAX_AGE;
    }

    private function write(string $type, $id, array $facts, int $state, ?string $label): void
    {
        $node = $this->read($type, $id);
        $now = time();
        $before = $node['state'] ?? null;
        if ($state !== $before) {
            $this->logger->notice(sprintf('stateTree: %s %s %s -> %s',
                $type, $label ?: $id,
                NodeState::name($type, $before), NodeState::name($type, $state)));
        }
        $this->cacheFactory->addCacheItem($this->key($type, $id), [
            'state' => $state,
            'since' => ($state === $before) ? ($node['since'] ?? $now) : $now,
            'seen' => $now,
            'facts' => $facts,
        ], self::TTL);
    }

    private function present(string $type, $id, ?string $label, int $state, array $node): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'name' => $label,
            'state' => $state,
            'state_name' => NodeState::name($type, $state),
            'since' => $node['since'] ?? null,
            'seen' => $node['seen'] ?? null,
            'fresh' => $this->isFresh($node),
            'facts' => $node['facts'] ?? [],
        ];
    }
}
