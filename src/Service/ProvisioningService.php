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
