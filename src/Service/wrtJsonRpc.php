<?php

namespace ApManBundle\Service;

use ApManBundle\Library\UbusResult;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * ubus over HTTP, straight to rpcd on the access point.
 *
 * The second of the two transports. New work goes over MQTT through the agent;
 * this one carries what already used it — the consistency check reading the
 * running hostapd configuration, neighbour scans, the radio refresh, LLDP and
 * syslog. It is kept honest rather than extended.
 */
class wrtJsonRpc
{
    /** an access point that does not answer the TCP handshake in a second is not there */
    public const CONNECT_TIMEOUT_MS = 1000;

    /** and one that has not finished in twenty seconds is not going to */
    public const TIMEOUT_MS = 20000;

    private $cacheFactory;

    /** @var array<string,\CurlHandle> one per access point */
    private array $handles = [];

    public function __construct(\Psr\Log\LoggerInterface $logger, \ApManBundle\Factory\CacheFactory $cacheFactory)
    {
        $this->logger = $logger;
        $this->cacheFactory = $cacheFactory;
    }

    /**
     * What the agent on this access point says it can do.
     *
     * The list travels on properties/agent and the subscriber keeps the last
     * one it saw; an access point that has never talked to this controller has
     * no list at all, which is not the same as an empty one — but for the only
     * question asked of it here, "can it do this", both mean no.
     */
    public function agentHasFeature(\ApManBundle\Entity\AccessPoint $ap, $feature)
    {
        $agent = $this->cacheFactory->getCacheItemValue('status.ap.'.$ap->getId().'.agent');

        return is_array($agent) && in_array($feature, $agent['features'] ?? [], true);
    }

    /**
     * The method name to address this access point's ubus with.
     *
     * "call" is one at a time. On an agent without the binding it is worse
     * than that — the lua ubus binding turns its own event loop until the
     * answer is there, and that is the loop that also serves mqtt, the hostapd
     * control channel monitors and the radius server, so a call that takes
     * five seconds makes the access point deaf for five seconds and with
     * macaddr_acl=2 turns away every station that tries to associate in that
     * window. Where the binding is there the agent runs them through a queue
     * instead: still strictly in the order they were sent, one outstanding at
     * a time, but the process keeps working in between. The answer says which
     * way it came — "queued": true from the queue, nothing from the blocking
     * path.
     *
     * "call_async" is the one that gives up the order, and that is the whole
     * difference: several at once instead of one after another. So nothing may
     * be sent this way that another command in the same batch builds on.
     * Provisioning — uci add, uci commit, reload — stays on "call" everywhere,
     * deliberately, and gets its sequence either from the queue or from the
     * blocking call, depending on what the access point has.
     *
     * The capability is not the agent's: it comes from libubus-lua-async, a
     * package of its own, so the agent version says nothing about it and the
     * feature has to be asked for by name. Where it is missing, this returns
     * "call" and everything behaves as it always did.
     */
    public function asyncMethod(\ApManBundle\Entity\AccessPoint $ap)
    {
        return $this->agentHasFeature($ap, 'ubus_async') ? 'call_async' : 'call';
    }

    /**
     * Give a command the same deadline its caller works to.
     *
     * Without one it runs on the agent's own, thirty seconds. A caller that
     * gives up after three and a command that keeps going for another
     * twenty seven do not disagree about anything important — but the answer
     * still arrives, on command_result/<id>, long after anybody was listening
     * for it, and whoever is subscribed then sees a reply to a question that
     * was written off. Saying how long we care makes that case not exist.
     *
     * It counts from when the call goes out, not from when the command was
     * sent: a command far back in the agent's queue can start later than the
     * budget allows and then still take all of it. At the depths seen so far —
     * thirty four commands, under a second in total — that is not a real
     * quantity, but it is the reason this is not a guarantee about when an
     * answer arrives.
     *
     * An agent too old to have a queue ignores the field, which is the right
     * thing for it to do: there the call was going to block anyway.
     *
     * @param float $seconds the agent clamps this to 1..300
     */
    public function setTimeout(\stdClass $cmd, $seconds)
    {
        $cmd->timeout = max(1, min(300, (int) ceil($seconds)));

        return $cmd;
    }

    public static function checkResult($result)
    {
        if (!is_object($result)) {
            return false;
        }
        if (!property_exists($result, 'jsonrpc')) {
            return false;
        }
        if ('2.0' != $result->jsonrpc) {
            return false;
        }
        if (!property_exists($result, 'result')) {
            return false;
        }
        if (!is_array($result->result)) {
            return false;
        }

        return true;
    }

    /**
     * One curl handle per access point, not one for the whole process.
     *
     * There used to be a single handle in $GLOBALS shared by every URL. Two
     * consequences: no two access points could be talked to at once without
     * clobbering each other's options, and the options set by login() —
     * timeouts and SSL_VERIFYPEER — leaked into every later call() and were
     * absent when the session came from the cache and no login had run. A
     * call() on a fresh handle therefore had curl's defaults: no transfer
     * timeout at all.
     */
    private function getHandle($url)
    {
        $parts = parse_url((string) $url);
        $host = is_array($parts)
            ? (($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').':'.($parts['port'] ?? ''))
            : (string) $url;
        if (isset($this->handles[$host])) {
            return $this->handles[$host];
        }

        return $this->handles[$host] = \curl_init();
    }

    /**
     * The options every request needs, in one place.
     *
     * @param int $timeoutMs total budget for the whole exchange
     */
    private function configureHandle($ch, $url, $data, $timeoutMs = self::TIMEOUT_MS)
    {
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // the access points answer with their own certificate
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, self::CONNECT_TIMEOUT_MS);
        \curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: '.strlen($data),
        ]);
    }

    public function login($url, $user, $password)
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('Login '.$url);
        $login = new \stdClass();
        $login->jsonrpc = '2.0';
        $login->id = 1;
        $login->method = 'call';
        $login->params = [];
        $login->params['0'] = '00000000000000000000000000000000';
        $login->params['1'] = 'session';
        $login->params['2'] = 'login';
        $login->params['3'] = new \stdClass();
        $login->params['3']->username = $user;
        $login->params['3']->password = $password;

        $data_string = json_encode($login);
        $ch = $this->getHandle($url);
        $this->configureHandle($ch, $url, $data_string);
        $result_string = curl_exec($ch);
	$result = json_decode($result_string);
	/*
	$clientTlsContext = (new Amp\Socket\ClientTlsContext(''))
		->withoutPeerVerification()
		->withSecurityLevel(0);

	$request = new Request($url, "POST");
	$request->setTransferTimeout(1);
	$request->setHeader('Content-Type', 'application/json');
	$request->setHeader('Content-Length', strlen($data_string));

	$request->setBody($result);

	*/
        if (!self::checkResult($result)) {
            return false;
        }
        if ($result->result[0]) {
            echo "failed to login\n";
            print_r($result);

            return false;
        }

        // Get rights for file objects
        $opts = new \stdClass();
        $opts->scope = 'file';
        $opts->objects = [];
        $opts->objects[0] = ['/*', 'read'];
        $opts->objects[1] = ['/*', 'write'];
        $opts->objects[2] = ['/*', 'exec'];
        $res_grant = self::call($url, $result->result[1], 'session', 'grant', $opts);
        $stopwatch->stop('Login '.$url);

        $session = new wrtJsonRpcSession($url, $result->result[1], $user, $password);
        $session->setRpcService($this);

        return $session;
    }

    /**
     * One ubus call, with the reason it failed if it did.
     *
     * @param int|null $timeoutMs override the default budget for a call that is
     *                            known to be slow, or known to have to be quick
     */
    public function callResult($url, $session, $namespace, $procedure, $arguments = null, $timeoutMs = null): UbusResult
    {
        $start = microtime(true);
        $stopwatch = new Stopwatch();
        $stopwatch->start('Call '.$url.' '.$procedure);
        //$this->logger->debug('wrtJsonRpc: Calling '.$url.' namespace '.$namespace.' procedure '.$procedure.' arguments: '.json_encode($arguments));
        $cmd = new \stdClass();
        $cmd->jsonrpc = '2.0';
        $cmd->id = 1;
        $cmd->method = 'call';
        $cmd->params = [];
        $cmd->params['0'] = $session->ubus_rpc_session;
        $cmd->params['1'] = $namespace;
        $cmd->params['2'] = $procedure;
        if (is_object($arguments)) {
            $cmd->params['3'] = $arguments;
        } else {
            $cmd->params['3'] = new \stdClass();
        }
        $data_string = json_encode($cmd);
        $ch = $this->getHandle($url);
        $this->configureHandle($ch, $url, $data_string, $timeoutMs ?? self::TIMEOUT_MS);
        $result_string = curl_exec($ch);
        $stopwatch->stop('Call '.$url.' '.$procedure);
        $where = $url.' '.$namespace.'.'.$procedure;
        $took = ['duration' => microtime(true) - $start];

        if (false === $result_string) {
            $this->logger->warning('wrtJsonRpc: '.$where.' — '.curl_error($ch), $took);

            return UbusResult::failed(UbusResult::TRANSPORT_FAILED, curl_error($ch));
        }
        $result = json_decode($result_string);
        if (!self::checkResult($result)) {
            $this->logger->warning('wrtJsonRpc: '.$where.' answered something that is not a ubus answer', $took);

            return UbusResult::failed(UbusResult::MALFORMED_ANSWER);
        }
        $status = (int) $result->result[0];
        if (UbusResult::OK !== $status) {
            $failure = UbusResult::failed($status);
            // Not every non-zero status is a problem: a "not found" on a
            // delete is the normal answer for a section that is not there.
            // Whether it matters is the caller's to decide, which is the whole
            // reason the code travels.
            $this->logger->debug('wrtJsonRpc: '.$where.' — '.$failure->why(), $took);

            return $failure;
        }
        $this->logger->debug('wrtJsonRpc: '.$where.' ok', $took);

        return UbusResult::ok($result->result[1] ?? null);
    }

    /**
     * The old contract: the payload, or false for anything that went wrong.
     *
     * Thirty call sites ask exactly this question and are right to. They keep
     * asking it; callResult() is there for the ones that need the reason.
     */
    public function call($url, $session, $namespace, $procedure, $arguments = null)
    {
        return $this->callResult($url, $session, $namespace, $procedure, $arguments)->orFalse();
    }

    public function createRpcRequest($id, $rpcMethod, $session, $namespace, $procedure, $arguments = null)
    {
        $cmd = new \stdClass();
        $cmd->jsonrpc = '2.0';
        $cmd->id = $id;
        $cmd->method = $rpcMethod;
        $cmd->params = [];
        if (is_null($session)) {
            $cmd->params['0'] = '00000000000000000000000000000000';
        } else {
            $cmd->params['0'] = $session;
        }
        $cmd->params['1'] = $namespace;
        $cmd->params['2'] = $procedure;
        if (is_object($arguments) or is_array($arguments)) {
            $cmd->params['3'] = $arguments;
        } else {
            $cmd->params['3'] = new \stdClass();
        }

        return $cmd;
    }

    public function getSession(\ApManBundle\Entity\AccessPoint $ap, $cached = true)
    {
        if ($cached) {
            $cache = new Psr16Cache(new FilesystemAdapter());
            $key = 'session_'.$ap->getName();
            if ($cache->has($key)) {
                return $cache->get($key);
            }
        }
        $session = $this->login($ap->getUbusUrl(), $ap->getUsername(), $ap->getPassword());
        if (!$session) {
            return false;
        }
        if ($cached) {
            $cache->set($key, $session, $session->getExpires() - 1);
        }

        return $session;
    }
}
