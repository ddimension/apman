<?php

namespace ApManBundle\DynamicEntity;

class Device
{
    /**
     * get Status
     * @return \string
     */
    public function getStatus()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
	    	return 'No ifname';
		return;
	}
	$ifname = $this->getIfname();
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->name = $this->getIfname();
	$data = $session->callCached('network.device','status', null, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (isset($data->$ifname)) {
		$res = 'Up: '.$data->$ifname->up?'Up':'Down';
		return $res;
	}

	$opts = new \stdclass();
	$opts->command = 'ip';
	$opts->params = array('-s', 'link', 'show');
	$opts->env = array('LC_ALL' => 'C');
	$stat = $session->callCached('file','exec', $opts, 5);
	if (isset($stat->code) && $stat->code) {
		return '-';
	}
	$lines = explode("\n", $stat->stdout);
	foreach ($lines as $id => $line) {
		$line = trim($line);
		if (strpos($line, ' '.$ifname.':')!== false) {
			if (strpos($line, ',UP')!== false) {
				return 'Up';
			}
			return 'Down';
		}
	}
	return 'Down';
    }

    /**
     * get StatisticsTransmit
     * @return \integer|\string
     */
    public function getStatisticsTransmit()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
	    	return 'No ifname';
		return;
	}
	$ifname = $this->getIfname();
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->name = $this->getIfname();
	$data = $session->callCached('network.device','status', null, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (isset($data->$ifname->statistics)) {
		return sprintf('%d',$data->$ifname->statistics->tx_bytes);
	}

	$opts = new \stdclass();
	$opts->command = 'ip';
	$opts->params = array('-s', 'link', 'show');
	$opts->env = array('LC_ALL' => 'C');
	$stat = $session->callCached('file','exec', $opts, 5);
	if (isset($stat->code) && $stat->code) {
		return '-';
	}
	$lines = explode("\n", $stat->stdout);
	$found = false;
	$foundHead = false;
	foreach ($lines as $id => $line) {
		$line = trim($line);
		if (strpos($line, ' '.$ifname.':')!== false) {
			$found = true;
			continue;
		}
		if (!$found) continue;
		if (substr($line,0,9)  == 'TX: bytes') {
			$foundHead = true;
			continue;
		}
		if (!$foundHead) continue;
		$x = explode(' ', $line);
		return sprintf('%d',$x[0]);
	}
	return '-';
    }

    /**
     * get statisticsReceive
     * @return \integer|\string
     */
    public function getStatisticsReceive()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
	    	return 'No ifname';
		return;
	}
	$ifname = $this->getIfname();
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->name = $this->getIfname();
	$data = $session->callCached('network.device','status', null, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (isset($data->$ifname->statistics)) {
		return sprintf('%d',$data->$ifname->statistics->rx_bytes);
	}

	$opts = new \stdclass();
	$opts->command = 'ip';
	$opts->params = array('-s', 'link', 'show');
	$opts->env = array('LC_ALL' => 'C');
	$stat = $session->callCached('file','exec', $opts, 2);
	if (isset($stat->code) && $stat->code) {
		return '-';
	}
	$lines = explode("\n", $stat->stdout);
	$found = false;
	$foundHead = false;
	foreach ($lines as $id => $line) {
		$line = trim($line);
		if (empty($line)) continue;
		if (strpos($line, ' '.$ifname.':')!== false) {
			$found = true;
			continue;
		}
		if (!$found) continue;
		if (substr($line,0,9)  == 'RX: bytes') {
			$foundHead = true;
			continue;
		}
		if (!$foundHead) continue;
		$x = explode(' ', $line);
		return sprintf('%d',$x[0]);
	}
	return '-';
    }

    public function getClients($useArray = false)
    {
        $config = $this->getConfig();
	if (empty($this->getIfname())) {
		if ($useArray) return array();
	    	return 'No ifname';
		return;
	}
	$ifname = $this->getIfname();
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		if ($useArray) return array();
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getIfname();
	$data = $session->callCached('iwinfo','assoclist', $opts , 2);
	if ($data === false) {
		if ($useArray) return array();
		return '-';
	}
	if (!isset($data->results)) {
		if ($useArray) return array();
		return '-';
	}
	$res = array();
	foreach ($data->results as $client) {
		if (isset($client->mac)) {
			$res[] = $client->mac;
		}
	}
	if (!count($res)) {
		if ($useArray) return array();
		return '-';
	}
	if ($useArray) return $res;
	return join(' ',$res);
    }

    /**
     * get model
     */
    public function getChannel()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getIfname();
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->channel)) {
		return 'No Channel';
	}
	return $data->channel;
    }

    /**
     * get model
     */
    public function getTxPower()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getIfname();
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->txpower)) {
		return 'No TX Power';
	}
	return $data->txpower;
    }

    /**
     * get model
     */
    public function getHwMode()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getIfname();
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->hwmodes)) {
		return 'No hwmodes';
	}
	return join('', $data->hwmodes);
    }

    /**
     * get model
     */
    public function getHtMode()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getIfname();
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->hwmodes)) {
		return 'No hwmodes';
	}
	return join(', ', $data->htmodes);
    }

    /**
     * get rrm_own
     */
    public function getRrmOwn()
    {
	$config = $this->getConfig();
	if (empty($this->getIfname())) {
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return null;
	}
	$data = $session->callCached('hostapd.'.$this->getIfname(), 'rrm_nr_get_own', null);
	if ($data === false) {
		return null;
	}
	if (is_object($data) && property_exists($data, 'value')) {
		return $data->value;
	}
	return null;
    }
}
