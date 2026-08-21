<?php

namespace ApManBundle\DynamicEntity;

class Device
{
    /**
     * get Status.
     *
     * NOTE: Entity\Device overrides getStatus() with the real database column.
     * This copy only existed to be shadowed by it, and it called itself —
     * every getter below starts with $this->getStatus(), so the one place the
     * recursion could have been reached again was right here.
     *
     * @return \string
     */
    public function getStatus()
    {
        return null;
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
        return $this->getRrm();
    }

    /**
     * The composed state from StateTreeService, injected by AccessPointListener
     * on postLoad like the access point's has always been. Read only: the tree
     * writes it once per status message, this hands it to Sonata and the pages.
     */
    private $treeCache;

    public function setCache($cache)
    {
        $this->treeCache = $cache->getMultipleCacheItemValues([
            'state.bss.composed['.$this->getId().']',
        ]);
    }

    public function getState()
    {
        $node = $this->treeCache['state.bss.composed['.$this->getId().']'] ?? null;
        if (!is_array($node)) {
            return 'Unknown';
        }

        return \ApManBundle\Library\NodeState::name(
            \ApManBundle\Library\NodeState::TYPE_BSS, $node['state'] ?? null);
    }

    /** how long it has been in that state, in seconds, or null */
    public function getStateSince()
    {
        $node = $this->treeCache['state.bss.composed['.$this->getId().']'] ?? null;
        if (!is_array($node) || empty($node['since'])) {
            return null;
        }

        return time() - (int) $node['since'];
    }
}
