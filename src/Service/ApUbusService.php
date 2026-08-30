<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;
use ApManBundle\Library\UbusResult;

/**
 * One ubus call to one access point, and the answer.
 *
 * This is the one way in. Which road it takes is the access point's own
 * `transport` column — mqtt through the apman agent, http to the access point's
 * json-rpc endpoint — and mqtt is the default. Both roads reach the same ubus
 * and both hand back the same UbusResult, so a caller does not have to know
 * which one it got, and switching one access point over is a column and not a
 * code change.
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

    /** Set by the subscriber on itself. See inLoop(). */
    private bool $inLoop = false;

    /**
     * Say that this process is the subscriber, whose event loop is turning.
     *
     * A synchronous call cannot work in there, and it does not fail quietly.
     * mqttFactory hands out the blocking client, whose publish() goes through
     * React\Async\await() — and await() runs the loop. Running a loop that is
     * already running drains its future tick queue, so when the outer tick
     * resumes its "while ($count--)" it dequeues from an empty one:
     *
     *   RuntimeException: Can't shift from an empty datastructure
     *   at FutureTickQueue.php:46
     *
     * which ends the process. Measured twice on 2026-08-30, at 02:46:10 and at
     * 04:35:53, both times through DfsService::probe() from the lifetime
     * handler. And where it does not crash it blocks: waitForAnyResult() waits
     * on a redis list with the loop stopped, so the publish never leaves and
     * the answer cannot arrive.
     *
     * callAsync() is what this class offers the subscriber, and it says so.
     */
    public function inLoop(bool $yes = true): void
    {
        $this->inLoop = $yes;
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
     * The same, returning what the old HTTP path returned: the payload, or
     * false.
     *
     * Thirty call sites only ever asked "did I get something". They move over
     * on this and gain the transport switch without gaining a new shape to
     * handle; the ones that have to tell a missing object from a denied
     * permission use call() and read the status.
     */
    public function callOrFalse(AccessPoint $ap, string $object, string $method, $args = null, float $timeout = self::DEFAULT_TIMEOUT)
    {
        return $this->call($ap, $object, $method, $args, $timeout)->orFalse();
    }

    /**
     * The same as callOrFalse(), but a repeated question is only asked once.
     *
     * `wrtJsonRpcSession::callCached()` did this on the HTTP side and the
     * callers that used it need it: DynamicEntity\Radio asks `iwinfo info` six
     * times for six properties of one radio, and SSIDService asks a bss for its
     * clients once per station on the page. Without a cache the switch to one
     * call path would turn one round trip into six.
     *
     * Keyed by access point, object, method and arguments — not by transport,
     * because the answer is the access point's and does not depend on the road
     * taken to it.
     */
    public function callCached(AccessPoint $ap, string $object, string $method, $args = null, int $ttl = 300, float $timeout = self::DEFAULT_TIMEOUT)
    {
        $key = 'ubuscall.'.hash('sha256', serialize([$ap->getId(), $object, $method, $args]));
        $hit = $this->cacheFactory->getCacheItemValue($key);
        if (null !== $hit) {
            // a miss and a stored false are told apart by the wrapper, so a
            // failed call is not retried on every station of a page
            return $hit['v'] ?? false;
        }
        $value = $this->call($ap, $object, $method, $args, $timeout)->orFalse();
        $this->cacheFactory->addCacheItem($key, ['v' => $value], $ttl);

        return $value;
    }

    /**
     * Forget one cached answer, for when we have just changed what it answers.
     */
    public function forget(AccessPoint $ap, string $object, string $method, $args = null): void
    {
        $this->cacheFactory->deleteCacheItem(
            'ubuscall.'.hash('sha256', serialize([$ap->getId(), $object, $method, $args])));
    }

    /**
     * Send one ubus call and do not wait for the answer.
     *
     * For the callers that run inside the subscriber's event loop. A
     * synchronous call there blocks everything the controller does — every
     * access point, not only this one — for as long as the timeout, and it has
     * cost the fleet once already: a blocking probe in the housekeeping loop
     * timed out fleet-wide while the agents were answering in milliseconds.
     *
     * So this publishes and returns. `call_async` is the agent's own word for
     * it and the same thing client steering has always used: the access point
     * carries the call out and answers nobody, which is right when there is
     * nothing in the answer worth having.
     *
     * @param object|array|null $args
     *
     * @return bool whether it went out, which is all that can be known here
     */
    public function callAsync(AccessPoint $ap, string $object, string $method, $args = null): bool
    {
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->debug('ApUbusService: no mqtt connection, '.$ap->getName().' did not get '
                .$object.'.'.$method);

            return false;
        }
        $cmd = $this->rpcService->createRpcRequest('u-async-'.bin2hex(random_bytes(4)), 'call_async',
            null, $object, $method, $args);
        $client->publish('apman/ap/'.$ap->getName().'/command', json_encode($cmd), 1);

        return true;
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
        if (!$ap->usesMqtt()) {
            return $this->callManyHttp($ap, $calls, $timeout);
        }
        if ($this->inLoop) {
            // Refusing is the whole point: this used to end the daemon.
            $this->logger->warning('ApUbusService: refusing a synchronous '
                .$calls[0]['object'].'.'.$calls[0]['method'].' for '.$ap->getName()
                .' inside the subscriber — use callAsync()', ['ap' => $ap->getName()]);

            return array_fill(0, count($calls), UbusResult::failed(
                UbusResult::TRANSPORT_FAILED, 'synchronous ubus call refused inside the event loop'));
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

        return UbusResult::ok(self::asObject($data['result'] ?? null));
    }

    /**
     * The same shape from both transports.
     *
     * The HTTP client decodes json into objects; the MQTT path receives it
     * already decoded into arrays. A caller that reads `$res->data['stdout']`
     * therefore worked over MQTT and died over HTTP with "Cannot use object of
     * type stdClass as array" — measured on ap-av-attic the first time one
     * access point was switched over. A switch whose two positions need
     * different calling code is not a switch.
     *
     * Objects win because that is what `wrtJsonRpc::call()` has always
     * returned, so callOrFalse() is a true drop-in for the thirty call sites
     * that came from there.
     */
    public static function asObject($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        // a list stays a list; only maps become objects, which is exactly what
        // json_decode without assoc does
        $decoded = json_decode(json_encode($value));

        return null === $decoded && 'null' !== json_encode($value) ? $value : $decoded;
    }

    /**
     * The same calls over HTTP, for an access point that says so.
     *
     * No batching: the json-rpc endpoint answers one call per request, so a
     * batch is a loop. The order is still the order, which is what a batch is
     * for; what is lost is only the single round trip.
     *
     * A login that fails is a transport failure for every call in the batch
     * rather than one failure repeated — there is nothing to retry per call.
     *
     * @return UbusResult[]
     */
    private function callManyHttp(AccessPoint $ap, array $calls, float $timeout): array
    {
        $session = $this->rpcService->getSession($ap);
        if (!$session) {
            return array_fill(0, count($calls),
                UbusResult::failed(UbusResult::TRANSPORT_FAILED, 'cannot log in to '.$ap->getName()));
        }
        $out = [];
        foreach ($calls as $call) {
            // The HTTP client sends the arguments verbatim and replaces
            // anything that is not an object with an empty one, so an array of
            // arguments would arrive as no arguments at all. The MQTT path
            // encodes either shape to the same json.
            $args = $call['args'] ?? null;
            if (is_array($args)) {
                $args = (object) $args;
            }
            $out[] = $session->callResult($call['object'], $call['method'], $args,
                (int) ($timeout * 1000));
        }

        return $out;
    }
}
