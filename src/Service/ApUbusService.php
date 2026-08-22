<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Library\UbusResult;

/**
 * One ubus call to one access point, over MQTT, and the answer.
 *
 * This is the transport everything new uses. The access points' HTTP API is
 * deliberately not extended — what already goes that way stays there, nothing
 * joins it.
 *
 * The machinery existed, three times over and never quite the same:
 * AccessPointService::collectResults() polls the cache every 250 ms,
 * CacheFactory::waitForAnyResult() blocks on a redis list, and
 * DefaultController::apActionAction() builds both by hand with its own four
 * second deadline and its own id scheme. Each of them is right about something
 * — the redis wait costs nothing while it waits, the polling survives a redis
 * that is not there, and a random id keeps a stale answer from a previous run
 * out of the way — so this does all three, once.
 *
 * The answer carries the ubus status code, the same UbusResult the HTTP
 * transport returns, so a caller that has to tell "not found" from "permission
 * denied" can, whichever way the call went out.
 */
class ApUbusService
{
    /** long enough for iwinfo scan, short enough that a page does not hang on it */
    public const DEFAULT_TIMEOUT = 8;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \ApManBundle\Factory\MqttFactory $mqttFactory,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
        private readonly wrtJsonRpc $rpcService,
    ) {
    }

    /**
     * Call one ubus method and wait for the answer.
     *
     * @param object|array|null $args
     */
    public function call(AccessPoint $ap, string $object, string $method, $args = null, float $timeout = self::DEFAULT_TIMEOUT): UbusResult
    {
        $answers = $this->callMany($ap, [['object' => $object, 'method' => $method, 'args' => $args]], $timeout);

        return $answers[0] ?? UbusResult::failed(UbusResult::TRANSPORT_FAILED, 'no answer');
    }

    /**
     * Several calls in one batch, answered in the order they were given.
     *
     * The agent runs a batch in the order it was sent, which is the reason to
     * use one rather than several single calls: uci add, uci commit, reload is
     * a sequence. Every entry is `['object'=>…, 'method'=>…, 'args'=>…]`.
     *
     * @return UbusResult[] one per call, same order
     */
    public function callMany(AccessPoint $ap, array $calls, float $timeout = self::DEFAULT_TIMEOUT): array
    {
        if (!$calls) {
            return [];
        }
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return array_fill(0, count($calls),
                UbusResult::failed(UbusResult::TRANSPORT_FAILED, 'no mqtt connection'));
        }

        // A fresh id per run. The ids in publishConfig() are derived from radio
        // and device names and are therefore the same on every run, which is
        // why that code has to delete the previous answers first; here there is
        // nothing to collide with.
        $run = bin2hex(random_bytes(4));
        $ids = [];
        $list = [];
        foreach ($calls as $i => $call) {
            $id = 'u-'.$run.'-'.$i;
            $ids[$id] = $i;
            $cmd = $this->rpcService->createRpcRequest($id, 'call', null,
                $call['object'], $call['method'], $call['args'] ?? null);
            // the agent stops caring when we do, instead of answering into an
            // empty room half a minute later
            $list[] = $this->rpcService->setTimeout($cmd, $timeout);
        }

        $topic = 'apman/ap/'.$ap->getName().'/command'.(1 === count($list) ? '' : '/bulk');
        $payload = 1 === count($list) ? $list[0] : ['list' => $list];
        $client->publish($topic, json_encode($payload), 1);

        $out = array_fill(0, count($calls), null);
        $pending = $ids;
        $deadline = microtime(true) + $timeout;
        while ($pending && microtime(true) < $deadline) {
            $wait = [];
            foreach ($pending as $id => $i) {
                $wait[$id] = $ap->getName();
            }
            $hit = $this->cacheFactory->waitForAnyResult($wait, max(1, (int) ceil($deadline - microtime(true))));
            if (!$hit || !isset($pending[$hit['id']])) {
                break;
            }
            $out[$pending[$hit['id']]] = $this->interpret($hit['data']);
            unset($pending[$hit['id']]);
        }

        foreach ($pending as $id => $i) {
            $this->logger->info('ApUbusService: '.$ap->getName().' did not answer '
                .$calls[$i]['object'].'.'.$calls[$i]['method'].' within '.$timeout.'s');
            $out[$i] = UbusResult::failed(UbusResult::TIMEOUT, 'no answer within '.$timeout.'s');
        }

        return $out;
    }

    /**
     * What the agent sends back, as a result.
     *
     * Three shapes arrive: a plain answer with `result`, an error object with
     * `error.code`, and — for a call that reached no object — the same error
     * with `stage: lookup`, which the agent adds so a bss that is not running
     * can be told from a station that is not there.
     */
    private function interpret($data): UbusResult
    {
        if (!is_array($data)) {
            return UbusResult::failed(UbusResult::MALFORMED_ANSWER);
        }
        if (isset($data['error'])) {
            $code = (int) ($data['error']['code'] ?? UbusResult::UNKNOWN_ERROR);
            $detail = $data['error']['message'] ?? null;
            if ('lookup' === ($data['error']['stage'] ?? null)) {
                $detail = trim(($detail ? $detail.', ' : '').'the object does not exist on this access point');
            }

            return UbusResult::failed($code, $detail);
        }

        return UbusResult::ok($data['result'] ?? null);
    }
}
