<?php

namespace ApManBundle\Library;

/**
 * The vocabulary of the state tree: access point → radio → bss.
 *
 * Three separate ranges on purpose. A radio is never "offline" — the access
 * point it hangs on is. A bss is never "in CAC" — the radio it shares a channel
 * with is. Giving each level its own words is what keeps the composition
 * honest; the flat AccessPointState it replaces had to describe all three at
 * once and ended up describing none of them precisely.
 *
 * The numbers carry no ordering meaning. Comparisons like "$state < ONLINE"
 * were how the old machine encoded its rules, and they are what made a missing
 * cache entry read as offline.
 */
class NodeState
{
    public const TYPE_AP = 'ap';
    public const TYPE_RADIO = 'radio';
    public const TYPE_BSS = 'bss';

    /** never heard from, or nothing recent enough to believe */
    public const BSS_UNKNOWN = 0;
    /** configured here, but the radio does not list the interface */
    public const BSS_ABSENT = 1;
    /** the interface exists, hostapd has not enabled it yet */
    public const BSS_STARTING = 2;
    /** hostapd reports ENABLED */
    public const BSS_READY = 3;
    /** ready, and its management (neighbour/beacon report, BTM) is switched on */
    public const BSS_ACTIVE = 4;

    public const RADIO_UNKNOWN = 0;
    public const RADIO_DISABLED = 1;
    public const RADIO_FAILED = 2;
    public const RADIO_PENDING = 3;
    /** a channel availability check is running — a property of the radio, even
     *  though hostapd reports it per bss, because they share the channel */
    public const RADIO_CAC = 4;
    /** up, but not every bss it should carry is there */
    public const RADIO_DEGRADED = 5;
    public const RADIO_READY = 6;
    public const RADIO_ACTIVE = 7;

    public const AP_UNKNOWN = 0;
    /** told us so (mqtt last will), or nothing has been heard for too long */
    public const AP_OFFLINE = 1;
    /** talking to us, nothing known about its radios yet */
    public const AP_ONLINE = 2;
    public const AP_FAILED = 3;
    public const AP_CONFIGURING = 4;
    public const AP_CAC = 5;
    public const AP_DEGRADED = 6;
    public const AP_ACTIVE = 7;

    private const NAMES = [
        self::TYPE_BSS => [
            self::BSS_UNKNOWN => 'UNKNOWN',
            self::BSS_ABSENT => 'ABSENT',
            self::BSS_STARTING => 'STARTING',
            self::BSS_READY => 'READY',
            self::BSS_ACTIVE => 'ACTIVE',
        ],
        self::TYPE_RADIO => [
            self::RADIO_UNKNOWN => 'UNKNOWN',
            self::RADIO_DISABLED => 'DISABLED',
            self::RADIO_FAILED => 'FAILED',
            self::RADIO_PENDING => 'PENDING',
            self::RADIO_CAC => 'CAC',
            self::RADIO_DEGRADED => 'DEGRADED',
            self::RADIO_READY => 'READY',
            self::RADIO_ACTIVE => 'ACTIVE',
        ],
        self::TYPE_AP => [
            self::AP_UNKNOWN => 'UNKNOWN',
            self::AP_OFFLINE => 'OFFLINE',
            self::AP_ONLINE => 'ONLINE',
            self::AP_FAILED => 'FAILED',
            self::AP_CONFIGURING => 'CONFIGURING',
            self::AP_CAC => 'CAC',
            self::AP_DEGRADED => 'DEGRADED',
            self::AP_ACTIVE => 'ACTIVE',
        ],
    ];

    public static function name(string $type, $state): string
    {
        if (null === $state) {
            return 'UNKNOWN';
        }

        return self::NAMES[$type][(int) $state] ?? ('?'.$state);
    }
}
