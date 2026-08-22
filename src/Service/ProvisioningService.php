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
        // an access point can run
        $started = microtime(true);
        $back = $this->waitForInterfaces($ap, true, self::UP_TIMEOUT);
        $report['steps'][] = $this->step('interfaces back', $started, $back['ok'],
            $back['ok'] ? count($back['expected']).' interfaces up' : 'missing: '.implode(', ', $back['left']));
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
