<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Stopwatch\Stopwatch;

class wrtJsonRpc
{
    private $cacheFactory;

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
     * Give a deferred command the same deadline its caller works to.
     *
     * Without one it runs on the agent's own, thirty seconds. A caller that
     * gives up after five and a command that keeps going for another
     * twenty five do not disagree about anything important — but the answer
     * still arrives, on command_result/<id>, long after anybody was listening
     * for it, and whoever is subscribed then sees a reply to a question that
     * was written off. Saying how long we care makes that case not exist.
     *
     * Only for "call_async": a synchronous call has no deadline of its own to
     * set, and the field would be noise on the wire.
     *
     * @param float $seconds the agent clamps this to 1..300
     */
    public function setTimeout(\stdClass $cmd, $seconds)
    {
        if ('call_async' === $cmd->method) {
            $cmd->timeout = max(1, min(300, (int) ceil($seconds)));
        }

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

    public function getHandle($url)
    {
        if (array_key_exists('curl_cache', $GLOBALS)) {
            return $GLOBALS['curl_cache'];
        }
        $GLOBALS['curl_cache'] = \curl_init();

        return $GLOBALS['curl_cache'];
        /*
                $parts = parse_url($url);
                if (!is_array($parts)) {
                    return false;
                }
                if (!array_key_exists('host', $parts)) {
                    return false;
                }
                $ref = $parts['scheme'].$parts['host'];
                if (array_key_exists('port', $parts)) {
                    $ref.= $parts['port'];
                }
                if (!is_array($GLOBALS['curl_cache'])) {
                    $GLOBALS['curl_cache'] = array();
                }
                if (array_key_exists($ref, $GLOBALS['curl_cache'])) {
                    return $GLOBALS['curl_cache'][ $ref ];
                }
                $GLOBALS['curl_cache'][ $ref ] = \curl_init($url);
                return $GLOBALS['curl_cache'][ $ref ];
         */
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
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1000);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 20000);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'Content-Length: '.strlen($data_string), ]
        );
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

    public function call($url, $session, $namespace, $procedure, $arguments = null)
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
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                    'Content-Length: '.strlen($data_string), ]
        );
        $time_start = time();
        $result_string = curl_exec($ch);
        $time_end = time();
        $stopwatch->stop('Call '.$url.' '.$procedure);
        $result = json_decode($result_string);
        if (!self::checkResult($result)) {
            $this->logger->warning('wrtJsonRpc: Failed to call '.$url.' namespace '.$namespace.' procedure '.$procedure, ['duration' => microtime(true) - $start]);

            return false;
        }
        if ($result->result[0]) {
            $this->logger->warning('wrtJsonRpc: Failed to call '.$url.' namespace '.$namespace.' procedure '.$procedure.', result '.json_encode($result), ['duration' => microtime(true) - $start]);

            return false;
        }
        $this->logger->debug('wrtJsonRpc: Called '.$url.' namespace '.$namespace.' procedure '.$procedure.', result '.json_encode($result), ['duration' => microtime(true) - $start]);
        if (array_key_exists(1, $result->result)) {
            return $result->result[1];
        }
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
