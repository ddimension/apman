<?php

namespace ApManBundle\DynamicEntity;

class Radio
{
    /**
     * Get HwMode
     *
     * @return \string
     */
    public function getHwMode()
    {
	$session = $this->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getName();
	$data = $session->callCached('iwinfo','info', $opts, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (!property_exists($data, 'hwmodes')) 
		return '-';
	return join(', ', $data->hwmodes);
/*	
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('board', $status)) {
            return null;
        }
        if (!is_array($status['board'])) {
            return null;
        }
        if (!array_key_exists('model', $status['board'])) {
            return null;
            return '';
        }
        return $status['board']['model'];
*/	
    }

    /**
     * Get Mode
     *
     * @return \string
     */
    public function getMode()
    {
	$session = $this->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getName();
	$data = $session->callCached('iwinfo','info', $opts, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (!property_exists($data, 'mode')) 
		return '-';
	return $data->mode;
    }

    /**
     * Get Channel
     *
     * @return \string
     */
    public function getChannel()
    {
	$session = $this->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getName();
	$data = $session->callCached('iwinfo','info', $opts, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (!property_exists($data, 'channel')) 
		return '-';
	return $data->channel;
    }

    /**
     * Get TxPower
     *
     * @return \string
     */
    public function getTxPower()
    {
	$session = $this->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getName();
	$data = $session->callCached('iwinfo','info', $opts, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (!property_exists($data, 'txpower')) 
		return '-';
	return $data->txpower;
    }

    /**
     * Get HtMode
     *
     * @return \string
     */
    public function getHtMode()
    {
	$session = $this->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getName();
	$data = $session->callCached('iwinfo','info', $opts, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (!property_exists($data, 'htmodes')) 
		return '-';
	return join(', ', $data->htmodes);
    }

    /**
     * get Status
     * @return \string
     */
    public function getHwInfo()
    {
	$session = $this->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $this->getName();
	$data = $session->callCached('iwinfo','info', $opts, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (!property_exists($data, 'hardware'))
		return '';
	if (!property_exists($data->hardware, 'id'))
		return '';
	$hw = $data->hardware->id;
	if ($hw[0] == 5772 and $hw[1] == 41 and $hw[2] == 5772 and $hw[3] == 41110) {
		return 'Atheros AR922X 5GHz';
	} elseif ($hw[0] == 5772 and $hw[1] == 41 and $hw[2] == 5772 and $hw[3] == 41111) {
		return 'Atheros AR922X 2GHz';
	} elseif ($hw[0] == 5772 and $hw[1] == 51 and $hw[2] == 5772 and $hw[3] == 41248) {
		return 'Atheros AR958x 802.11abgn';
	} elseif ($hw[0] == 5772 and $hw[1] == 70 and $hw[2] == 5772 and $hw[3] == 51966) {
		return 'Atheros 9984';
	} elseif ($hw[0] == 5772 and $hw[1] == 60 and $hw[2] == 0 and $hw[3] == 0) {
		return 'Atheros 988X';
	}
	return join(', ',$hw);
    }
}
