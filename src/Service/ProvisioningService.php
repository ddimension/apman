<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Library\UbusResult;

/**
 * Provisioning that takes the radios down first and waits until they are down.
 *
 * The order matters and is the whole point:
 *
 *     1  network.wireless down
 *     2  wait until no interface of this access point is left, checking the
 *        netdevs rather than assuming
 *     3  one second
 *     4  apply the configuration, and wait for it to finish
 *     5  network.wireless up
 *
 * Step 2 is the reason. `AccessPointService::stopRadio()` publishes a `down`
 * with a fixed id, collects nothing and returns true immediately — so the
 * configuration went out while the interfaces were still being torn down, and
 * whether that raced was a matter of how busy the access point happened to be.
 * A netdev that is still there is a fact, and this waits for it to stop being
 * one.
 *
 * Everything goes over MQTT. `iwinfo devices` was already being fetched by the
 * status cycle and thrown away; here it is the thing that decides when to carry
 * on.
 */
class ProvisioningService
{
    /** how long to wait for the interfaces to go away before giving up */
    public const DOWN_TIMEOUT = 25.0;

    /** between two looks at the netdev list */
    public const POLL_INTERVAL = 0.5;

    /** the pause in step 3, after the last interface is gone */
    public const SETTLE = 1.0;

    /** how long to wait for the interfaces to come back in step 5 */
    public const UP_TIMEOUT = 40.0;

    /**
     * how long a live run waits for a bss to appear or disappear
     *
     * Measured: the netdev of an added bss was up 0.4 s after the apply, and a
     * removed one was gone inside the same second. Nothing on this path
     * restarts a phy, so nothing on it has a channel availability check to sit
     * out — a budget in the tens of seconds would only make a real failure take
     * longer to report.
     */
    public const LIVE_TIMEOUT = 12.0;

    /**
     * how long a forced run waits for the radios it is restarting to go down
     *
     * Short, because this is not waiting for a result — it is waiting for the
     * teardown to have started so that what comes after is measured on the far
     * side of it. If they do not go down, that is an answer too and the report
     * says so.
     */
    public const FORCED_DOWN_TIMEOUT = 15.0;

    /** uci bookkeeping, not configuration — never a reason to restart a radio */
    private const UCI_META = ['type' => true, 'name' => true, 'section' => true, 'config' => true];

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly ApUbusService $ubus,
        private readonly AccessPointService $aps,
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
        private readonly DfsService $dfs,
    ) {
    }

    /**
     * The whole flow, with what each step cost.
     *
     * A dry run stops before it touches anything: it stages the configuration,
     * asks for the resulting diff and reverts, which is what `applyConfig()`
     * already does — the radios stay up, because the point of asking is to find
     * out whether taking them down is worth it.
     */
    public function restart(AccessPoint $ap, bool $dryRun = false): array
    {
        $report = [
            'ok' => false,
            'ap' => $ap->getName(),
            'dry_run' => $dryRun,
            'steps' => [],
        ];
        if (!$ap->getProvisioningEnabled()) {
            $report['error'] = 'provisioning is disabled for this access point';

            return $report;
        }

        if ($dryRun) {
            $started = microtime(true);
            $report['config'] = $this->aps->applyConfig($ap, true);
            $report['steps'][] = $this->step('config (staged only)', $started,
                (bool) ($report['config']['ok'] ?? false));
            $report['ok'] = (bool) ($report['config']['ok'] ?? false);
            $report['expect'] = $this->expectedInterfaces($ap);

            return $report;
        }

        // 1 — down
        $started = microtime(true);
        $down = $this->ubus->call($ap, 'network.wireless', 'down', null, 20);
        $report['steps'][] = $this->step('wireless down', $started, $down->isOk(), $down->why());
        if (!$down->isOk()) {
            $report['error'] = 'could not take the radios down: '.$down->why();

            return $report;
        }

        // 2 — wait for the netdevs to disappear, and say which one did not
        $started = microtime(true);
        $gone = $this->waitForInterfaces($ap, false, self::DOWN_TIMEOUT);
        $report['steps'][] = $this->step('interfaces gone', $started, $gone['ok'],
            $gone['ok'] ? count($gone['expected']).' interfaces gone' : 'still there: '.implode(', ', $gone['left']));
        $report['expect'] = $gone['expected'];
        if (!$gone['ok']) {
            $report['left'] = $gone['left'];
            $report['error'] = 'interfaces did not go away: '.implode(', ', $gone['left'])
                .' — the configuration was not applied, and the radios are down';

            return $report;
        }

        // 3 — a second, because a netdev disappearing and the driver being
        // finished with it are not quite the same moment
        $started = microtime(true);
        usleep((int) (self::SETTLE * 1000000));
        $report['steps'][] = $this->step('settle', $started, true);

        // 4 — the configuration, unchanged from what it has always been
        $started = microtime(true);
        $config = $this->aps->applyConfig($ap);
        $report['config'] = $config;
        $report['steps'][] = $this->step('config applied', $started, (bool) ($config['ok'] ?? false),
            isset($config['change_count']) ? $config['change_count'].' changes' : ($config['error'] ?? null));

        // 5 — up, whatever happened in step 4. Leaving an access point with its
        // radios down because a uci call failed is the worse outcome of the two.
        $started = microtime(true);
        $up = $this->ubus->call($ap, 'network.wireless', 'up', null, 30);
        $report['steps'][] = $this->step('wireless up', $started, $up->isOk(), $up->why());

        if (!($config['ok'] ?? false)) {
            $report['error'] = $config['error'] ?? 'the configuration was not applied';

            return $report;
        }
        if (!$up->isOk()) {
            $report['error'] = 'the configuration was applied but the radios did not come back: '.$up->why();

            return $report;
        }

        // 6 — and back, which is the only proof that step 4 produced something
        // an access point can run.
        //
        // A radio on a dfs channel listens before it transmits, and on the
        // weather radar range that is ten minutes. ap-outdoor reports
        // cac_seconds 600 on channel 116 while ap-outdoor2 reports 60 on the
        // same channel, because their phys carry different regulatory rules —
        // so the wait cannot be a constant, and it is taken from the radio.
        $started = microtime(true);
        $budget = $this->waitBudget($ap);
        $back = $this->waitForInterfaces($ap, true, $budget);
        $checking = $this->radiosChecking($ap);
        // and ask the radios themselves, because the status cycle cannot see a
        // check that is running while the bss is down — the control socket can
        if (!$back['ok']) {
            foreach ($this->probeChecking($ap) as $name => $detail) {
                $checking[$name] = $detail;
            }
        }
        if (!$back['ok'] && $checking) {
            // Still listening when the budget ran out: that is the regulation
            // working, not a failed provisioning run, and calling it a failure
            // would train people to ignore the report.
            $report['steps'][] = $this->step('interfaces back', $started, true,
                'still listening: '.implode('; ', $this->describe($checking))
                .' — a channel availability check is not a failure, and no traffic passes '
                .'until it ends');
            $report['ok'] = true;
            $report['cac'] = $checking;
            $report['waiting_for'] = $back['left'];

            return $report;
        }
        $report['steps'][] = $this->step('interfaces back', $started, $back['ok'],
            $back['ok'] ? count($back['expected']).' interfaces up'
                : 'missing: '.implode(', ', $back['left']).' after '.(int) $budget.'s'
                    .($budget > self::UP_TIMEOUT ? ' — a channel availability check was allowed for' : ''));
        $report['ok'] = $back['ok'];
        if (!$back['ok']) {
            $report['missing'] = $back['left'];
            $report['error'] = 'these did not come back: '.implode(', ', $back['left']);
        }

        return $report;
    }


    /**
     * What the next provisioning would cost this access point, per radio.
     *
     * A bss can be added to a running phy and taken off it again without the
     * others noticing — measured on ap-av-attic on 2026-08-23 in both
     * directions, with nothing at all in the log for the five networks that
     * were not the subject. hostapd is handed the new configuration file and
     * the previous one and applies the difference, so the delta is worked out
     * on the device, by the daemon that owns the interfaces.
     *
     * A radio is the other case. Change a wifi-device option and the phy comes
     * down with every bss standing on it, and on a dfs channel it then listens
     * for ten minutes before it says anything. That is the line, and this is
     * where it gets drawn: the running wifi-device sections are fetched from
     * the access point and held against what provisioning would write.
     *
     * The comparison is uci against uci on purpose. The generated hostapd
     * configuration is cached too, but matching `htmode` against
     * `he_oper_chwidth` means a mapping table, and a mapping table is a place
     * for this to be quietly wrong.
     *
     * @return array mode plus the reason for it, per radio
     */
    public function classify(AccessPoint $ap): array
    {
        $report = ['ok' => false, 'ap' => $ap->getName(), 'mode' => 'restart', 'radios' => []];

        $opts = new \stdClass();
        $opts->config = 'wireless';
        $opts->type = 'wifi-device';
        $res = $this->ubus->call($ap, 'uci', 'get', $opts, 10);
        if (!$res->isOk()) {
            $report['error'] = 'could not read the running radio configuration: '.$res->why();

            return $report;
        }
        $values = is_object($res->data) ? ($res->data->values ?? null) : null;
        $running = [];
        foreach ((array) $values as $section => $cfg) {
            $cfg = (array) $cfg;
            $name = (string) ($cfg['.name'] ?? $section);
            $running[$name] = $cfg;
        }

        $report['ok'] = true;
        $seen = [];
        foreach ($ap->getRadios() as $radio) {
            $name = (string) $radio->getName();
            $seen[$name] = true;
            $reasons = [];
            if (!isset($running[$name])) {
                $reasons[] = 'the access point has no radio called '.$name.' — provisioning creates it';
            } else {
                $wanted = (array) $radio->exportConfig();
                $have = $running[$name];
                foreach ($wanted as $option => $value) {
                    if (isset(self::UCI_META[$option])) {
                        continue;
                    }
                    if (!array_key_exists($option, $have)) {
                        $reasons[] = $option.' would be set to '.$this->readable($value).', it is unset';
                        continue;
                    }
                    if (!$this->same($have[$option], $value)) {
                        $reasons[] = $option.': '.$this->readable($have[$option]).' would become '
                            .$this->readable($value);
                    }
                }
                // Provisioning deletes every wifi-device section and writes it
                // again, so an option the access point carries and the database
                // does not is an option that goes away — which is a change to
                // the phy however little it looks like one.
                foreach ($have as $option => $value) {
                    if (str_starts_with((string) $option, '.') || isset(self::UCI_META[$option])) {
                        continue;
                    }
                    if (!array_key_exists($option, $wanted)) {
                        $reasons[] = $option.' ('.$this->readable($value).') would be dropped';
                    }
                }
            }
            $report['radios'][$name] = [
                'mode' => $reasons ? 'restart' : 'live',
                'reasons' => $reasons,
                'devices' => count($radio->getDevices()),
            ];
        }
        foreach ($running as $name => $cfg) {
            if (isset($seen[$name])) {
                continue;
            }
            // A radio on the access point that the database knows nothing
            // about is deleted by the next run, along with whatever stands on
            // it. Nobody should find that out afterwards.
            $report['radios'][$name] = [
                'mode' => 'restart',
                'reasons' => ['this radio is not in the database and provisioning would delete it'],
                'devices' => 0,
                'unknown' => true,
            ];
        }

        $restarting = array_keys(array_filter($report['radios'], fn ($r) => 'restart' === $r['mode']));
        $report['mode'] = $restarting ? 'restart' : 'live';
        $report['restarting'] = $restarting;

        return $report;
    }

    /**
     * Provision without taking anything down, and check afterwards what moved.
     *
     * The check is the part worth having. Applying the configuration under
     * running radios and reporting success proves nothing — a bss that was
     * torn down and built again comes back under the same name and looks
     * identical in every list. Its index does not: the kernel hands out a new
     * one, so a netdev with the index it had before is the same netdev, and one
     * that changed index is one that was restarted. That turns "it should not
     * have disturbed anything" into something the report can say or refuse to
     * say.
     *
     * @param bool $force apply even where a radio level option changed
     */
    public function live(AccessPoint $ap, bool $dryRun = false, bool $force = false): array
    {
        $report = ['ok' => false, 'ap' => $ap->getName(), 'mode' => 'live',
            'dry_run' => $dryRun, 'steps' => []];
        if (!$ap->getProvisioningEnabled()) {
            $report['error'] = 'provisioning is disabled for this access point';

            return $report;
        }

        $started = microtime(true);
        $verdict = $this->classify($ap);
        $report['classify'] = $verdict;
        $report['steps'][] = $this->step('classify', $started, (bool) $verdict['ok'],
            $verdict['ok'] ? ('live' === $verdict['mode'] ? 'only bss sections change'
                : 'radio level changes on '.implode(', ', $verdict['restarting']))
                : ($verdict['error'] ?? null));
        if (!$verdict['ok']) {
            $report['error'] = $verdict['error'] ?? 'could not tell what would change';

            return $report;
        }
        if ('restart' === $verdict['mode'] && !$force) {
            $why = [];
            foreach ($verdict['restarting'] as $name) {
                $why[] = $name.': '.implode('; ', $verdict['radios'][$name]['reasons']);
            }
            $report['error'] = 'this changes the radios themselves, which takes every network on them '
                .'down — provision with a restart instead. '.implode(' | ', $why);
            $report['needs_restart'] = $verdict['restarting'];

            return $report;
        }

        if ($dryRun) {
            $started = microtime(true);
            $report['config'] = $this->aps->applyConfig($ap, true);
            $report['steps'][] = $this->step('config (staged only)', $started,
                (bool) ($report['config']['ok'] ?? false));
            $report['expect'] = $this->expectedInterfaces($ap);
            $report['ok'] = (bool) ($report['config']['ok'] ?? false);

            return $report;
        }

        $before = $this->wirelessIndices($ap);

        $started = microtime(true);
        $config = $this->aps->applyConfig($ap);
        $report['config'] = $config;
        $report['steps'][] = $this->step('config applied', $started, (bool) ($config['ok'] ?? false),
            isset($config['change_count']) ? $config['change_count'].' changes' : ($config['error'] ?? null));
        if (!($config['ok'] ?? false)) {
            $report['error'] = $config['error'] ?? 'the configuration was not applied';

            return $report;
        }

        // A bss that is being added gets its netdev within about a second; one
        // that is going away disappears about as fast, so a short budget is
        // enough — nothing on this path restarts a radio.
        //
        // Unless it was forced. Then a phy is coming down with everything on
        // it, and measuring a second later catches the teardown half done: on
        // ap-av-grwz that reported eleven interfaces kept running while five of
        // them were in the middle of being rebuilt. So when a restart was
        // forced, the interfaces of the radios that classify named are first
        // waited out of existence, and only then waited back — with the budget
        // a restart needs, channel availability check included.
        $started = microtime(true);
        $forcedRadios = 'restart' === $verdict['mode'] ? $verdict['restarting'] : [];
        if ($forcedRadios) {
            $falling = $this->interfacesOfRadios($ap, $forcedRadios);
            $fell = $falling
                ? $this->waitForNames($ap, $falling, false, self::FORCED_DOWN_TIMEOUT)
                : ['ok' => true, 'left' => []];
            $report['forced'] = $forcedRadios;
            // Not catching them down is not evidence that they stayed up. The
            // netdevs are gone for a second or two and the poll is most of a
            // second, so a quick restart slips between two looks — measured on
            // ap-av-grwz and ap-hv-grwz, where this said the phy had not
            // restarted and the interface indices said all five bsses had. Only
            // the indices can settle it, and they are compared below.
            $report['steps'][] = $this->step('radios down', $started, true,
                $fell['ok']
                    ? implode(', ', $forcedRadios).' went down with '.count($falling).' network(s)'
                    : 'did not catch '.implode(', ', $forcedRadios).' down — either hostapd applied '
                        .'the change without restarting the phy, or it was quicker than the polling. '
                        .'The interface indices say which.');
            $report['caught_down'] = $fell['ok'];
            $started = microtime(true);
        }
        $back = $this->waitForInterfaces($ap, true,
            $forcedRadios ? $this->waitBudget($ap) : self::LIVE_TIMEOUT);
        // And the other half of "the set matches": a bss that was switched off
        // has to be gone, not merely not-expected. Waiting only for the
        // arrivals meant a removal was still in flight when the interfaces were
        // counted, and the run reported that nothing had gone away.
        $shouldBeGone = array_values(array_diff($this->managedInterfaces($ap), $back['expected']));
        $gone = $shouldBeGone
            ? $this->waitForNames($ap, $shouldBeGone, false, max(1.0, self::LIVE_TIMEOUT - (microtime(true) - $started)))
            : ['ok' => true, 'left' => []];
        $report['steps'][] = $this->step('interfaces there', $started, $back['ok'] && $gone['ok'],
            trim(($back['ok'] ? count($back['expected']).' interfaces up'
                : 'missing: '.implode(', ', $back['left']))
                .($shouldBeGone
                    ? ($gone['ok'] ? ', '.count($shouldBeGone).' gone'
                        : ', still there: '.implode(', ', $gone['left']))
                    : '')));
        $report['expect'] = $back['expected'];

        $after = $this->wirelessIndices($ap);
        // Judged against every interface this access point is ours to manage,
        // not only the ones it should have now — a bss that was just switched
        // off is no longer expected, and judging only the expected set made its
        // removal invisible in the very report that exists to confirm it.
        $moved = $this->compareIndices($before, $after, $back['expected'],
            $this->managedInterfaces($ap));
        $report += $moved;
        // A forced run was told to restart named radios, so their interfaces
        // coming back with new indices is the thing that was asked for. What
        // still matters is whether anything *else* went with them — measured on
        // ap-av-grwz on 23.08.2026: a radio level change restarts that phy and
        // only that phy, and the seven interfaces on the other two kept the
        // index the kernel had given them.
        $asked = $forcedRadios ? $this->interfacesOfRadios($ap, $forcedRadios) : [];
        $collateral = array_values(array_diff($moved['restarted'], $asked));
        $report['restarted_as_asked'] = array_values(array_intersect($moved['restarted'], $asked));
        $report['collateral'] = $collateral;

        $report['steps'][] = $this->step('nothing else moved', $started, !$collateral,
            $collateral
                ? 'these were restarted although nothing about them changed: '.implode(', ', $collateral)
                : count($moved['kept']).' kept running, '.count($moved['added']).' added, '
                    .count($moved['removed']).' removed'
                    .($report['restarted_as_asked']
                        ? ', '.count($report['restarted_as_asked']).' restarted as asked' : ''));

        if ($collateral) {
            // Worth an error in the log and not only in the answer: the whole
            // point of this path is that it does not do that, so if it did, the
            // assumption underneath it needs revisiting rather than repeating.
            $this->logger->error($ap->getName().': a live provisioning run restarted '
                .implode(', ', $collateral).' — these were not part of the change, and a '
                .'bss that restarts drops every station on it');
        }
        if (!$moved['known']) {
            $report['steps'][] = $this->step('nothing else moved', $started, true,
                'the interface indices could not be read, so this run cannot say');
        }

        $report['ok'] = $back['ok'] && $gone['ok'];
        if (!$back['ok']) {
            $report['missing'] = $back['left'];
            $report['error'] = 'these did not appear: '.implode(', ', $back['left']);
        } elseif (!$gone['ok']) {
            $report['lingering'] = $gone['left'];
            $report['error'] = 'these did not go away: '.implode(', ', $gone['left']);
        }

        return $report;
    }

    /**
     * Wait until a named set of interfaces is there, or gone.
     *
     * waitForInterfaces() asks the same question about the set the access point
     * is supposed to have; this asks it about a set the caller names, which is
     * what a removal needs — the interfaces going away are by definition not in
     * the expected set any more.
     */
    private function waitForNames(AccessPoint $ap, array $names, bool $present, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $left = $names;
        $polls = 0;

        while (microtime(true) < $deadline) {
            ++$polls;
            $res = $this->ubus->call($ap, 'iwinfo', 'devices', null,
                max(2, min(6, $deadline - microtime(true))));
            $devices = $res->isOk() && is_object($res->data) ? ($res->data->devices ?? null) : null;
            if (is_array($devices)) {
                $left = $present
                    ? array_values(array_diff($names, $devices))
                    : array_values(array_intersect($names, $devices));
                if (!$left) {
                    return ['ok' => true, 'left' => [], 'polls' => $polls];
                }
            }
            if (microtime(true) + self::POLL_INTERVAL >= $deadline) {
                break;
            }
            usleep((int) (self::POLL_INTERVAL * 1000000));
        }

        return ['ok' => false, 'left' => $left, 'polls' => $polls];
    }

    /**
     * Every wireless netdev of this access point with the index the kernel gave it.
     *
     * `ip -o link` rather than `iwinfo devices`, because iwinfo answers with
     * names alone and the name is exactly the part that survives a restart.
     *
     * @return array<string,int>|null null if the access point did not answer
     */
    private function wirelessIndices(AccessPoint $ap): ?array
    {
        $opts = new \stdClass();
        $opts->command = '/sbin/ip';
        $opts->params = ['-o', 'link', 'show'];
        $res = $this->ubus->call($ap, 'file', 'exec', $opts, 10);
        if (!$res->isOk()) {
            $this->logger->debug($ap->getName().': could not read the interface indices: '.$res->why());

            return null;
        }
        $stdout = is_object($res->data) ? (string) ($res->data->stdout ?? '') : '';
        if ('' === trim($stdout)) {
            return null;
        }
        $map = [];
        foreach (explode("\n", $stdout) as $line) {
            if (!preg_match('/^(\d+):\s*([^:@\s]+)/', trim($line), $m)) {
                continue;
            }
            $map[$m[2]] = (int) $m[1];
        }

        return $map ?: null;
    }

    /**
     * Which interfaces came, went, stayed, and which quietly restarted.
     *
     * Only the interfaces this access point is supposed to have or is ours to
     * manage are judged; the ethernet ports and bridges come along in the same
     * listing and have nothing to do with it.
     *
     * Public because it is the whole judgement of a live run and it is pure —
     * two maps of name to index in, four lists out.
     */
    public function compareIndices(?array $before, ?array $after, array $expected, array $managed): array
    {
        $out = ['known' => false, 'added' => [], 'removed' => [], 'kept' => [], 'restarted' => []];
        if (null === $before || null === $after) {
            return $out;
        }
        $out['known'] = true;
        $names = array_unique(array_merge($expected, $managed));
        foreach ($names as $name) {
            $was = $before[$name] ?? null;
            $is = $after[$name] ?? null;
            if (null === $was && null !== $is) {
                $out['added'][] = $name;
            } elseif (null !== $was && null === $is) {
                $out['removed'][] = $name;
            } elseif (null !== $was && $was === $is) {
                $out['kept'][] = $name;
            } elseif (null !== $was) {
                $out['restarted'][] = $name;
            }
        }
        sort($out['added']);
        sort($out['removed']);
        sort($out['kept']);
        sort($out['restarted']);

        return $out;
    }

    /**
     * The interface names standing on the named radios of this access point.
     *
     * @param string[] $radioNames
     *
     * @return string[]
     */
    private function interfacesOfRadios(AccessPoint $ap, array $radioNames): array
    {
        $wanted = array_flip($radioNames);
        $names = [];
        foreach ($ap->getRadios() as $radio) {
            if (!isset($wanted[(string) $radio->getName()])) {
                continue;
            }
            foreach ($radio->getDevices() as $device) {
                $name = $device->ifname();
                if ($name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Every interface name on this access point that is ours to manage.
     *
     * Unlike expectedInterfaces() this does not care whether the bss is
     * switched on: an interface that is supposed to go away is exactly the one
     * a live run has to be able to report on, and it is not expected any more
     * by the time the run finishes.
     *
     * @return string[]
     */
    private function managedInterfaces(AccessPoint $ap): array
    {
        $names = [];
        foreach ($ap->getRadios() as $radio) {
            foreach ($radio->getDevices() as $device) {
                $name = $device->ifname();
                if ($name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * uci string against whatever the database holds, the same way
     * AccessPointService compares them when it builds the diff.
     */
    private function same($a, $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return array_values(array_map('strval', (array) $a)) === array_values(array_map('strval', (array) $b));
        }

        return (string) $a === (string) $b;
    }

    private function readable($value): string
    {
        if (is_array($value)) {
            return '['.implode(' ', array_map('strval', $value)).']';
        }

        return '' === (string) $value ? "''" : (string) $value;
    }

    /**
     * The interface names this access point should have.
     *
     * From the database, because that is what we asked for; a device whose name
     * we never learned and never set has none, and is left out rather than
     * guessed at — waiting for a name nobody chose would never end.
     *
     * @return string[]
     */
    public function expectedInterfaces(AccessPoint $ap): array
    {
        $names = [];
        foreach ($ap->getRadios() as $radio) {
            // A bss on a switched off radio cannot come up, and waiting for it
            // is waiting for something that will not happen. ap-av-klwz radio0
            // is disabled and carries one bss; without this the flow reported
            // a failure on every run and spent the whole forty second deadline
            // getting there.
            if ((string) $radio->getConfigDisabled() === '1') {
                continue;
            }
            foreach ($radio->getDevices() as $device) {
                if (!$device->getIsEnabled()) {
                    continue;
                }
                $name = $device->ifname();
                if ($name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Wait until the access point's interfaces are all gone, or all there.
     *
     * `iwinfo devices` lists every wireless netdev the access point has, so the
     * question is asked of the device and not of anything we believe about it.
     *
     * A call that fails is not an answer: while the radios are being torn down
     * the access point is busy and a timed-out call means nothing either way, so
     * a failure is retried until the deadline rather than being read as "gone".
     *
     * @param bool $present true: wait for them to appear; false: to disappear
     */
    public function waitForInterfaces(AccessPoint $ap, bool $present, float $timeout): array
    {
        $expected = $this->expectedInterfaces($ap);
        $deadline = microtime(true) + $timeout;
        $left = $expected;
        $polls = 0;
        $lastWhy = null;

        while (microtime(true) < $deadline) {
            ++$polls;
            $res = $this->ubus->call($ap, 'iwinfo', 'devices', null,
                max(2, min(6, $deadline - microtime(true))));
            $devices = $res->isOk() && is_object($res->data) ? ($res->data->devices ?? null) : null;
            if (is_array($devices)) {
                $have = $devices;
                $left = $present
                    ? array_values(array_diff($expected, $have))
                    : array_values(array_intersect($expected, $have));
                if (!$left) {
                    return ['ok' => true, 'expected' => $expected, 'left' => [], 'polls' => $polls];
                }
            } else {
                $lastWhy = $res->why();
                $this->logger->debug('ProvisioningService: '.$ap->getName()
                    .' iwinfo devices did not answer ('.$lastWhy.'), asking again');
            }
            if (microtime(true) + self::POLL_INTERVAL >= $deadline) {
                break;
            }
            usleep((int) (self::POLL_INTERVAL * 1000000));
        }

        return ['ok' => false, 'expected' => $expected, 'left' => $left, 'polls' => $polls,
            'last_error' => $lastWhy];
    }

    /**
     * How long to wait for the interfaces, given what the radios are doing.
     *
     * The floor is UP_TIMEOUT. A radio in the middle of a check raises it to
     * what that check still needs, because waiting less and calling the result
     * a failure is worse than waiting.
     */
    private function waitBudget(AccessPoint $ap): float
    {
        $budget = self::UP_TIMEOUT;
        $why = [];
        foreach ($ap->getRadios() as $radio) {
            if ('1' === (string) $radio->getConfigDisabled()) {
                continue;
            }
            $forRadio = $this->dfs->waitBudget($radio, (int) self::UP_TIMEOUT);
            if ($forRadio > $budget) {
                $budget = $forRadio;
                $why[] = $radio->getName().' needs up to '.$this->dfs->expectFor($radio).'s to listen';
            }
        }
        if ($why) {
            $this->logger->info('ProvisioningService: waiting up to '.(int) $budget.'s — '
                .implode(', ', $why));
        }

        return $budget;
    }

    /**
     * The radios that are listening rather than transmitting, from what the
     * status cycle last saw.
     *
     * @return array<string,string> radio name => what is known
     */
    private function radiosChecking(AccessPoint $ap): array
    {
        $out = [];
        foreach ($ap->getRadios() as $radio) {
            $state = $this->dfs->state($radio);
            if ($state['active'] ?? false) {
                $out[$radio->getName()] = $state['elapsed'].'s of '.$state['expected'];
            }
        }

        return $out;
    }

    /**
     * The same question asked of the radios directly.
     *
     * hostapd registers its ubus object when the interface is enabled, so
     * during a check there is nothing there to ask — but the control socket is,
     * and it answers `state=DFS` with the time left. Measured on ap-av-attic:
     * `hostapd.wap-kc1 get_status` was "not found" for the whole check while
     * `hostapd_cli -i wap-kc1 status` answered throughout.
     *
     * @return array<string,string>
     */
    private function probeChecking(AccessPoint $ap): array
    {
        $out = [];
        foreach ($ap->getRadios() as $radio) {
            if ('1' === (string) $radio->getConfigDisabled()) {
                continue;
            }
            foreach ($radio->getDevices() as $device) {
                $ifname = (string) $device->ifname();
                if ('' === $ifname) {
                    continue;
                }
                $probe = $this->dfs->probe($ap, $ifname);
                if (null === $probe) {
                    continue;
                }
                if ($probe['checking']) {
                    $out[$radio->getName()] = 'on '.$probe['freq'].' MHz, '
                        .(null !== $probe['left'] ? $probe['left'].'s left of ' : '')
                        .$probe['expected'].'s';
                }
                // one bss per radio is enough: the socket answers for the phy
                break 1;
            }
        }

        return $out;
    }

    /** @param array<string,string> $checking */
    private function describe(array $checking): array
    {
        $out = [];
        foreach ($checking as $radio => $detail) {
            $out[] = $radio.' ('.$detail.')';
        }

        return $out;
    }

    private function step(string $name, float $started, bool $ok, ?string $detail = null): array
    {
        $out = [
            'step' => $name,
            'ok' => $ok,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        if (null !== $detail) {
            $out['detail'] = $detail;
        }
        $this->logger->info('ProvisioningService: '.$name.' '.($ok ? 'ok' : 'FAILED')
            .' in '.$out['ms'].' ms'.($detail ? ' — '.$detail : ''));

        return $out;
    }
}
