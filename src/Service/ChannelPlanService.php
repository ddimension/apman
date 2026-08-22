<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\Radio;

/**
 * Which channels and widths a radio may actually use, asked of the radio.
 *
 * Setting a channel has been guessing. The admin offers a free text field, the
 * schema offers "auto", and whether 116 can carry 80 MHz on this particular
 * access point in this particular country is a question nobody could answer
 * without an ssh session. The device knows. This asks it.
 *
 * ## Why not `ubus call iwinfo freqlist`
 *
 * Because its `flags` are wrong. Measured on ap-av-attic, 2026-08-22, the same
 * radio, minutes apart:
 *
 *     ch48  no_ht40+, no_ht40-, no_80mhz, no_160mhz, no_he, no_ir, indoor_only
 *     ch48  no_20mhz, no_ht40+, no_80mhz, no_he, no_ir, indoor_only
 *     ch48  no_160mhz, no_20mhz, no_he, no_ht40-, no_ir, indoor_only
 *
 * Three answers to one question, while `iwinfo wap-kc1 freqlist` said
 * `[INDOOR_ONLY]` every time and `iw reg get` agreed with it. Five calls in the
 * same second return the same bytes, so it is not random per call — it reads
 * something that is not initialised, and it changes as the memory under it
 * changes. `no_20mhz` on a channel a radio is transmitting 20 MHz on gives the
 * game away.
 *
 * The `channel`, `mhz`, `band` and `active` fields agree between the two, so it
 * is the flag decoding and not the whole object. But the flags are the entire
 * point of asking, so this uses the command line tool through `file exec` —
 * plain ubus, same MQTT transport, no agent change — and parses its text.
 *
 * ## What it asks
 *
 * | | |
 * |---|---|
 * | `iwinfo <ifname> freqlist` | the channels that exist here, and what each forbids |
 * | `iw reg get` | the regulatory rules, per phy, with the widest channel each allows |
 *
 * The first is the authority on what may be used. The second explains why, and
 * catches the case the first cannot express: a block of four channels that are
 * each fine on their own but straddle two regulatory ranges.
 */
class ChannelPlanService
{
    /** Regulation does not change hourly, and neither does a country setting. */
    public const CACHE_TTL = 3600;

    /** the widths we ask about, in MHz */
    public const WIDTHS = [20, 40, 80, 160, 320];

    public function __construct(
        private readonly ApUbusService $ubus,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * The plan for one radio, from the cache or from the device.
     *
     * @param bool $refresh ask the device even if a recent answer is stored
     */
    public function plan(Radio $radio, bool $refresh = false): ?array
    {
        $key = 'channelplan.radio.'.$radio->getId();
        if (!$refresh) {
            $cached = $this->cacheFactory->getCacheItemValue($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $plan = $this->fetch($radio);
        if ($plan) {
            $this->cacheFactory->addCacheItem($key, $plan, self::CACHE_TTL);
        }

        return $plan;
    }

    /**
     * Ask the device.
     *
     * Needs a running interface: `iwinfo` reports what the phy behind a netdev
     * can do, and without a netdev there is nothing to ask about. A radio with
     * no bss up therefore has no plan, which is honest — it also has no channel.
     */
    private function fetch(Radio $radio): ?array
    {
        $ap = $radio->getAccessPoint();
        if (!$ap) {
            return null;
        }
        $ifname = null;
        $phy = null;
        $ownAirtime = null;
        foreach ($radio->getDevices() as $device) {
            $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
            $ap_status = is_array($status) && is_array($status['ap_status'] ?? null) ? $status['ap_status'] : [];
            if (isset($ap_status['phy'])) {
                $phy = $ap_status['phy'];
            }
            if (null === $ownAirtime && isset($ap_status['airtime']['utilization'])) {
                // what this radio itself is putting on the air, so a busy
                // channel can be told from a busy access point
                $ownAirtime = (int) round($ap_status['airtime']['utilization'] * 100 / 255);
            }
            if (null === $ifname && $device->ifname()) {
                $ifname = $device->ifname();
            }
        }
        if (!$ifname) {
            return null;
        }

        $answers = $this->ubus->callMany($ap, [
            ['object' => 'file', 'method' => 'exec',
                'args' => ['command' => '/usr/bin/iwinfo', 'params' => [$ifname, 'freqlist']]],
            ['object' => 'file', 'method' => 'exec',
                'args' => ['command' => '/usr/sbin/iw', 'params' => ['reg', 'get']]],
            // passive and cheap: the radio counts busy time whether anybody
            // asks or not, so this costs a read and disturbs nothing
            ['object' => 'iwinfo', 'method' => 'survey', 'args' => ['device' => $ifname]],
        ], 10);

        $freqOut = $answers[0]->isOk() && is_object($answers[0]->data)
            ? (string) ($answers[0]->data->stdout ?? '') : null;
        if (!$freqOut) {
            $this->logger->info('ChannelPlanService: '.$ap->getName().' '.$ifname
                .' gave no freqlist: '.$answers[0]->why());

            return null;
        }
        $regOut = $answers[1]->isOk() && is_object($answers[1]->data)
            ? (string) ($answers[1]->data->stdout ?? '') : '';

        $channels = $this->parseFreqlist($freqOut);
        if (!$channels) {
            return null;
        }
        $reg = $this->parseReg($regOut, $phy);
        $survey = $answers[2]->isOk() ? $answers[2]->data : null;
        $neighbours = $this->cacheFactory->getCacheItemValue('neighbour.summary.radio.'.$radio->getId());

        $channels = $this->addLoad($channels, $survey,
            is_array($neighbours) ? ($neighbours['channels'] ?? []) : []);

        return [
            'fetched' => time(),
            'ap' => $ap->getName(),
            'ifname' => $ifname,
            'phy' => $phy,
            'country' => $reg['country'],
            'rules' => $reg['rules'],
            'own_airtime' => $ownAirtime,
            'scan_age' => is_array($neighbours) && isset($neighbours['ts'])
                ? time() - $neighbours['ts'] : null,
            'channels' => $channels,
            'widths' => $this->widths($channels, $reg['rules']),
            'blocks' => $this->blockLoad($channels),
        ];
    }

    /**
     * How busy each channel is, and who else is on it.
     *
     * A channel that is permitted is not thereby free. The three numbers that
     * say whether it is worth having exist already and were never put next to
     * each other: the survey counts busy time whether anybody asks or not, the
     * neighbour scan knows how many networks are on which channel, and
     * `ap_status.airtime` says how much of the noise is us.
     *
     * `noise` arrives as an unsigned byte — 158 means -98 dBm — because that is
     * how nl80211 hands it over and iwinfo passes it through. Read as written
     * it would say the quietest channels are the loudest.
     */
    private function addLoad(array $channels, $survey, array $neighbours): array
    {
        $byMhz = [];
        $rows = is_object($survey) ? ($survey->results ?? null)
            : (is_array($survey) ? ($survey['results'] ?? null) : null);
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $r = (array) $r;
                if (!isset($r['mhz'])) {
                    continue;
                }
                $active = (int) ($r['active_time'] ?? 0);
                $noise = isset($r['noise']) ? (int) $r['noise'] : null;
                if (null !== $noise && $noise > 127) {
                    $noise -= 256;
                }
                $byMhz[(int) $r['mhz']] = [
                    'busy_pct' => $active > 0 ? round((int) ($r['busy_time'] ?? 0) * 100 / $active, 1) : null,
                    'noise' => $noise,
                    'active_ms' => $active,
                ];
            }
        }

        foreach ($channels as $ch => $info) {
            $load = $byMhz[$info['mhz']] ?? ['busy_pct' => null, 'noise' => null, 'active_ms' => 0];
            $channels[$ch]['busy_pct'] = $load['busy_pct'];
            $channels[$ch]['noise'] = $load['noise'];
            $channels[$ch]['measured_ms'] = $load['active_ms'];
            $channels[$ch]['bss'] = (int) ($neighbours[$ch]['bss'] ?? 0);
            $channels[$ch]['strongest'] = $neighbours[$ch]['strongest'] ?? null;
        }

        return $channels;
    }

    /**
     * The load of a whole block, for each width.
     *
     * An 80 MHz channel inherits the interference of all four 20 MHz channels
     * it is made of, so the number that matters for choosing one is not the
     * primary's but the block's — the worst busy time in it and the networks
     * across all of it. A quiet primary next to three crowded neighbours is the
     * trap this exists to show.
     *
     * @return array width => primary channel => the block's numbers
     */
    private function blockLoad(array $channels): array
    {
        $out = [];
        foreach (self::WIDTHS as $w) {
            foreach ($channels as $ch => $info) {
                $members = 20 === $w ? [$ch] : $this->block($channels, $ch, $w);
                if (null === $members) {
                    continue;
                }
                $busy = null;
                $bss = 0;
                $strongest = null;
                foreach ($members as $m) {
                    $b = $channels[$m]['busy_pct'] ?? null;
                    if (null !== $b) {
                        $busy = null === $busy ? $b : max($busy, $b);
                    }
                    $bss += (int) ($channels[$m]['bss'] ?? 0);
                    $sig = $channels[$m]['strongest'] ?? null;
                    if (null !== $sig && (null === $strongest || $sig > $strongest)) {
                        $strongest = $sig;
                    }
                }
                $out[$w][$ch] = ['busy_pct' => $busy, 'bss' => $bss, 'strongest' => $strongest,
                    'members' => $members];
            }
        }

        return $out;
    }

    /**
     * `iwinfo <if> freqlist`, one channel per line.
     *
     *     * 5.520 GHz (Band: 5 GHz, Channel 104) [RADAR_DETECTION]
     *       5.180 GHz (Band: 5 GHz, Channel 36) [NO_HT40-, INDOOR_ONLY]
     *
     * The leading star is the channel in use. A channel that is not listed is
     * not available at all — disabled by regulation or unsupported by the phy —
     * so the list is the whole of what may be used, and absence is the answer
     * for everything else.
     *
     * @return array<int, array> keyed by channel number
     */
    public function parseFreqlist(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/^(\*)?\s*([\d.]+) GHz \(Band: ([\d.]+) GHz, Channel (\d+)\)(?:\s*\[([^\]]*)\])?/', $line, $m)) {
                continue;
            }
            $flags = [];
            if (!empty($m[5])) {
                foreach (explode(',', $m[5]) as $f) {
                    $f = strtolower(trim($f));
                    if ('' !== $f) {
                        $flags[] = $f;
                    }
                }
            }
            $out[(int) $m[4]] = [
                'channel' => (int) $m[4],
                'mhz' => (int) round((float) $m[2] * 1000),
                'band' => (float) $m[3],
                'active' => '*' === $m[1],
                'flags' => $flags,
                'dfs' => in_array('radar_detection', $flags, true),
                'indoor' => in_array('indoor_only', $flags, true),
                // NO_IR means we may listen but not start a network here
                'no_ir' => in_array('no_ir', $flags, true),
            ];
        }
        ksort($out);

        return $out;
    }

    /**
     * `iw reg get`, which prints one block per phy plus a global one.
     *
     *     phy#1 (self-managed)
     *     country DE: DFS-ETSI
     *         (5490 - 5590 @ 80), (N/A, 30), (0 ms), DFS, AUTO-BW
     *
     * A self-managed phy carries its own rules and they are the ones that
     * apply; the global block is the fallback for a phy that has none.
     *
     * @param string|null $phy e.g. 'phy1'
     */
    public function parseReg(string $text, ?string $phy): array
    {
        $blocks = [];
        $current = 'global';
        $country = null;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(global|phy#(\d+))/', $line, $m)) {
                $current = 'global' === $m[1] ? 'global' : 'phy'.$m[2];
                continue;
            }
            if (preg_match('/^country (\w+):/', $line, $m)) {
                $blocks[$current]['country'] = $m[1];
                continue;
            }
            if (preg_match('/\((\d+) - (\d+) @ (\d+)\)/', $line, $m)) {
                $blocks[$current]['rules'][] = [
                    'from' => (int) $m[1],
                    'to' => (int) $m[2],
                    'max_bw' => (int) $m[3],
                    'dfs' => (bool) preg_match('/\bDFS\b/', $line),
                    'no_outdoor' => (bool) preg_match('/NO-OUTDOOR/', $line),
                    'auto_bw' => (bool) preg_match('/AUTO-BW/', $line),
                ];
            }
        }
        $block = ($phy && isset($blocks[$phy])) ? $blocks[$phy] : ($blocks['global'] ?? []);

        return [
            'country' => $block['country'] ?? null,
            'rules' => $block['rules'] ?? [],
            'from' => ($phy && isset($blocks[$phy])) ? $phy : 'global',
        ];
    }

    /**
     * For each width, which channels may be the primary — and for the rest, why
     * not.
     *
     * A wide channel is a block of adjacent 20 MHz channels, and it needs all of
     * them: 80 MHz on 116 uses 116, 120, 124 and 128, so one of those missing
     * from the list takes 116 out of the 80 MHz row. That is the part a free
     * text field cannot know and the reason this exists.
     */
    public function widths(array $channels, array $rules = []): array
    {
        // Only the widths the band has at all. A 320 MHz row on a 5 GHz radio
        // full of "not possible" says nothing; leaving it out says the same
        // thing and takes no room.
        $band = $channels ? reset($channels)['band'] : 5;
        $widths = $band < 3 ? [20, 40] : ($band >= 5.9 ? [20, 40, 80, 160, 320] : [20, 40, 80, 160]);

        $out = [];
        foreach ($widths as $w) {
            $out[$w] = ['ok' => [], 'no' => []];
            foreach ($channels as $ch => $info) {
                $verdict = $this->verdict($channels, $ch, $w, $rules);
                if (true === $verdict) {
                    $out[$w]['ok'][] = $ch;
                } else {
                    $out[$w]['no'][$ch] = $verdict;
                }
            }
        }

        return $out;
    }

    /**
     * True if this channel can be the primary of a block this wide, otherwise
     * the reason it cannot — in words, because a reason nobody can read is the
     * same as no reason.
     *
     * @return true|string
     */
    private function verdict(array $channels, int $ch, int $width, array $rules)
    {
        $info = $channels[$ch];
        if ($info['no_ir']) {
            return 'may be listened to but not transmitted on';
        }
        if (20 === $width) {
            return true;
        }
        if (in_array('no_'.$width.'mhz', $info['flags'], true)) {
            return 'the radio says no '.$width.' MHz here';
        }

        $members = $this->block($channels, $ch, $width);
        if (null === $members) {
            if (40 === $width) {
                return 'no neighbour to pair with — both sides are forbidden or missing';
            }

            return 'the '.$width.' MHz block around it is not complete on this radio';
        }
        foreach ($members as $m) {
            if ($channels[$m]['no_ir']) {
                return 'channel '.$m.' in the same block may not be transmitted on';
            }
        }

        // The regulatory answer, which the per-channel flags do not carry: a
        // block that straddles two ranges is limited by the narrower of them.
        $lo = $channels[min($members)]['mhz'] - 10;
        $hi = $channels[max($members)]['mhz'] + 10;
        $limit = $this->ruleLimit($rules, $lo, $hi);
        if (null !== $limit && $limit < $width) {
            return 'regulation allows at most '.$limit.' MHz across '.$lo.'–'.$hi.' MHz here';
        }

        return true;
    }

    /**
     * The 5 GHz blocks, written out rather than computed.
     *
     * Arithmetic on the channel number looks like it works and then does not:
     * the channels are four apart up to 144 and then jump, because 144 is
     * 5720 MHz and 149 is 5745. A grid anchored at 36 puts 149 at index 28.25
     * and quietly drops the whole of U-NII-3 out of every width above 20 — the
     * first version of this did exactly that, and the 27-channel radio on
     * ap-av-grwz showed 149 to 173 as 20 MHz only.
     *
     * So the blocks are the ones 802.11 defines, listed.
     */
    private const BLOCKS_5 = [
        40 => [[36, 40], [44, 48], [52, 56], [60, 64], [100, 104], [108, 112], [116, 120],
            [124, 128], [132, 136], [140, 144], [149, 153], [157, 161], [165, 169], [173, 177]],
        80 => [[36, 40, 44, 48], [52, 56, 60, 64], [100, 104, 108, 112], [116, 120, 124, 128],
            [132, 136, 140, 144], [149, 153, 157, 161], [165, 169, 173, 177]],
        160 => [[36, 40, 44, 48, 52, 56, 60, 64], [100, 104, 108, 112, 116, 120, 124, 128],
            [149, 153, 157, 161, 165, 169, 173, 177]],
    ];

    /**
     * The channels of the block this channel belongs to, or null if there is no
     * such block or it is not complete on this radio.
     *
     * 6 GHz is regular — channels 1, 5, 9 … 233 with no gap — so it is computed.
     * 2.4 GHz gets its own arithmetic: its channels overlap, five apart and
     * twenty wide, so there is no grid to align to and 40 MHz is a primary plus
     * a secondary four channels up or down, chosen by the NO_HT40+/NO_HT40-
     * flags rather than by position.
     */
    private function block(array $channels, int $ch, int $width): ?array
    {
        $info = $channels[$ch];

        if ($info['band'] < 3) {
            if (40 !== $width) {
                return null;   // 2.4 GHz does nothing wider, whatever the flags say
            }
            foreach ([['no_ht40+', $ch + 4], ['no_ht40-', $ch - 4]] as [$flag, $partner]) {
                if (!in_array($flag, $info['flags'], true) && isset($channels[$partner])) {
                    return [$ch, $partner];
                }
            }

            return null;
        }

        $members = null;
        if ($info['band'] >= 5.9) {
            $step = intdiv($width, 20);
            if ($step < 2) {
                return null;
            }
            $idx = intdiv($ch - 1, 4);
            $start = intdiv($idx, $step) * $step;
            $members = [];
            for ($i = $start; $i < $start + $step; ++$i) {
                $members[] = 1 + $i * 4;
            }
        } else {
            if (320 === $width) {
                return null;   // 5 GHz has no 320 MHz
            }
            foreach (self::BLOCKS_5[$width] ?? [] as $candidate) {
                if (in_array($ch, $candidate, true)) {
                    $members = $candidate;
                    break;
                }
            }
        }
        if (!$members) {
            return null;
        }
        foreach ($members as $m) {
            if (!isset($channels[$m])) {
                return null;   // the block is not complete on this radio
            }
        }

        // The HT40 flags say which half a channel may be: the lower member of a
        // pair has to extend upwards. Where the block table and the flags
        // disagree the flags win — they come from the regulatory database for
        // this device, the table is the same everywhere.
        if (40 === $width) {
            $lower = min($members) === $ch;
            if ($lower && in_array('no_ht40+', $info['flags'], true)) {
                return null;
            }
            if (!$lower && in_array('no_ht40-', $info['flags'], true)) {
                return null;
            }
        }

        return $members;
    }

    /**
     * The widest channel the regulation allows across a frequency span.
     *
     * Adjacent AUTO-BW rules are joined first: that is what the flag means — a
     * rule may be widened when the range next to it continues seamlessly, which
     * is how 5490–5590 @ 80 and 5590–5650 @ 40 together still carry an 80 MHz
     * block that crosses 5590. Without joining them this would forbid a channel
     * the fleet is demonstrably using.
     */
    private function ruleLimit(array $rules, int $lo, int $hi): ?int
    {
        if (!$rules) {
            return null;
        }
        usort($rules, function ($a, $b) { return $a['from'] <=> $b['from']; });
        $merged = [];
        foreach ($rules as $r) {
            $last = $merged ? count($merged) - 1 : null;
            if (null !== $last && $merged[$last]['to'] === $r['from']
                && $merged[$last]['auto_bw'] && $r['auto_bw']) {
                $merged[$last]['to'] = $r['to'];
                $merged[$last]['max_bw'] = max($merged[$last]['max_bw'], $r['max_bw']);
                continue;
            }
            $merged[] = $r;
        }
        foreach ($merged as $r) {
            if ($lo >= $r['from'] && $hi <= $r['to']) {
                return $r['max_bw'];
            }
        }

        // The span is not inside any one rule. Say nothing rather than forbid:
        // the rule set may simply not describe this band.
        return null;
    }
}
