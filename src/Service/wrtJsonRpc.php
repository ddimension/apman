<?php

namespace ApManBundle\Service;

use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\Stopwatch\Stopwatch;

class wrtJsonRpc
{
    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: '.strlen($data_string), ]
        );
        $result_string = curl_exec($ch);
        $result = json_decode($result_string);
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
        $this->logger->debug('wrtJsonRpc: Calling '.$url.' namespace '.$namespace.' procedure '.$procedure.' arguments: '.json_encode($arguments));
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                    'Content-Length: '.strlen($data_string), ]
        );
        $time_start = time();
        $result_string = curl_exec($ch);
        $time_end = time();
        $stopwatch->stop('Call '.$url.' '.$procedure);
        $result = json_decode($result_string);
        if (!self::checkResult($result)) {
            $this->logger->warn('wrtJsonRpc: Failed to call '.$url.' namespace '.$namespace.' procedure '.$procedure, ['duration' => microtime(true) - $start]);

            return false;
        }
        if ($result->result[0]) {
            $this->logger->warn('wrtJsonRpc: Failed to call '.$url.' namespace '.$namespace.' procedure '.$procedure.', result '.json_encode($result), ['duration' => microtime(true) - $start]);

            return false;
        }
        $this->logger->debug('wrtJsonRpc: Called '.$url.' namespace '.$namespace.' procedure '.$procedure.', result '.json_encode($result), ['duration' => microtime(true) - $start]);
        if (array_key_exists(1, $result->result)) {
            return $result->result[1];
        }
    }

    public function createRpcRequest($id, $rpcMethod, $session = null, $namespace, $procedure, $arguments = null)
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
            $cache = new FilesystemCache();
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
