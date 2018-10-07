<?php

namespace ApManBundle\Library;

use Symfony\Component\Stopwatch\Stopwatch;


class wrtJsonRpc {

	public static function checkResult($result) {
		if (!is_object($result)) {
			return false;
		}
		if (!property_exists($result, 'jsonrpc')) {
			return false;
		}
		if ($result->jsonrpc != '2.0') {
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

	public static function login($url, $user, $password) {
		$stopwatch = new Stopwatch();
		$stopwatch->start('Login '.$url);
		$login = new \stdClass();
		$login->jsonrpc = '2.0';
		$login->id = 1;
		$login->method = 'call';
		$login->params = array();
		$login->params['0'] = '00000000000000000000000000000000';
		$login->params['1'] = 'session';
		$login->params['2'] = 'login';
		$login->params['3'] = new \stdClass();
		$login->params['3']->username = $user;
		$login->params['3']->password = $password;

		$data_string = json_encode($login);                                                                                   
		$ch = \curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1000); 
		curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2000);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
			    'Content-Type: application/json',                                                                                
			        'Content-Length: ' . strlen($data_string))                                                                       
		);
		$result_string = curl_exec($ch);
		curl_close($ch);
		$result = json_decode($result_string);
		if (!self::checkResult($result)) {
			return false;
		}
		if ($result->result[0]) {
			echo "failed to login\n";
			print_r($result);
			return false;
		}
		$stopwatch->start('Login '.$url);
		return new wrtJsonRpcSession($url, $result->result[1], $user, $password);
	}

	public static function call($url, $session, $namespace, $procedure, $arguments = null) {
		$stopwatch = new Stopwatch();
		$stopwatch->start('Call '.$url.' '.$procedure);
		$cmd = new \stdClass();
		$cmd->jsonrpc = '2.0';
		$cmd->id = 1;
		$cmd->method = 'call';
		$cmd->params = array();
		$cmd->params['0'] = $session->ubus_rpc_session;
		$cmd->params['1'] = $namespace;
		$cmd->params['2'] = $procedure;
		if (is_object($arguments)) {
			$cmd->params['3'] = $arguments;
		} else {
			$cmd->params['3'] = new \stdClass();
		}
		$data_string = json_encode($cmd);                                                                                   
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
			    'Content-Type: application/json',                                                                                
			        'Content-Length: ' . strlen($data_string))                                                                       
		);
		$time_start = time();
		$result_string = curl_exec($ch);
		$time_end = time();
		$stopwatch->stop('Call '.$url.' '.$procedure);
		#error_log('Duration for '.$url.' cmd '.$data_string.' took: '.($time_end-$time_start));
		$result = json_decode($result_string);
		if (!self::checkResult($result)) {
			return false;
		}
		if ($result->result[0]) {
			/*
			error_log("Failed to run call $url $namespace $procedure ".serialize($arguments));
			error_log("Failed to run call: $data_string\n");
			 */
			return false;
		}
		if (array_key_exists(1, $result->result))
			return $result->result[1];
	}
}
