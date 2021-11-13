<?php

namespace ApManBundle\DynamicEntity;

class Device
{
    /**
     * get Status.
     *
     * @return \string
     */
    public function getStatus()
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('status', $status)) {
            return null;
        }
        if (!is_array($status['status'])) {
            return null;
        }
        if (!array_key_exists('up', $status['status'])) {
            return null;
        }
        $res = 'Up: '.$status['status']['up'] ? 'Up' : 'Down';

        return $res;
    }

    /**
     * get StatisticsTransmit.
     *
     * @return \integer|\string
     */
    public function getStatisticsTransmit()
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('status', $status)) {
            return null;
        }
        if (!is_array($status['status'])) {
            return null;
        }
        if (!array_key_exists('statistics', $status['status'])) {
            return null;
        }
        if (!is_array($status['status']['statistics'])) {
            return null;
        }
        if (!array_key_exists('tx_bytes', $status['status']['statistics'])) {
            return null;
        }

        return $status['status']['statistics']['tx_bytes'];
    }

    /**
     * get statisticsReceive.
     *
     * @return \integer|\string
     */
    public function getStatisticsReceive()
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('status', $status)) {
            return null;
        }
        if (!is_array($status['status'])) {
            return null;
        }
        if (!array_key_exists('statistics', $status['status'])) {
            return null;
        }
        if (!is_array($status['status']['statistics'])) {
            return null;
        }
        if (!array_key_exists('rx_bytes', $status['status']['statistics'])) {
            return null;
        }

        return $status['status']['statistics']['rx_bytes'];
    }

    public function getClients($useArray = false)
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            if ($useArray) {
                return [];
            }

            return null;
        }
        if (!array_key_exists('stations', $status)) {
            if ($useArray) {
                return [];
            }

            return null;
        }
        if (!is_array($status['stations'])) {
            if ($useArray) {
                return [];
            }

            return null;
        }
        $res = [];
        foreach ($status['stations'] as $mac => $client) {
            if (isset($mac)) {
                $res[] = $mac;
            }
        }
        if (!count($res)) {
            if ($useArray) {
                return [];
            }

            return '-';
        }
        if ($useArray) {
            return $res;
        }

        return join(' ', $res);
    }

    /**
     * get model.
     */
    public function getChannel()
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('info', $status)) {
            return null;
        }
        if (!is_array($status['info'])) {
            return null;
        }
        if (!array_key_exists('channel', $status['info'])) {
            return null;
        }

        return $status['info']['channel'];
    }

    /**
     * get model.
     */
    public function getTxPower()
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('info', $status)) {
            return null;
        }
        if (!is_array($status['info'])) {
            return null;
        }
        if (!array_key_exists('txpower', $status['info'])) {
            return null;
        }

        return $status['info']['txpower'];
    }

    /**
     * get model.
     */
    public function getHwMode()
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('info', $status)) {
            return null;
        }
        if (!is_array($status['info'])) {
            return null;
        }
        if (!array_key_exists('hwmodes', $status['info'])) {
            return null;
        }

        return join('', $status['info']['hwmodes']);
    }

    /**
     * get model.
     */
    public function getHtMode()
    {
        $status = $this->getStatus();
        if (!is_array($status)) {
            return null;
        }
        if (!array_key_exists('info', $status)) {
            return null;
        }
        if (!is_array($status['info'])) {
            return null;
        }
        if (!array_key_exists('htmodes', $status['info'])) {
            return null;
        }

        return join(', ', $status['info']['htmodes']);
    }

    /**
     * get rrm_own.
     */
    public function getRrmOwn()
    {
        $this->getRrm();
    }
}
