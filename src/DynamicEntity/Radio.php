<?php

namespace ApManBundle\DynamicEntity;

class Radio
{
    /**
     * Get HwMode.
     *
     * @return \string
     */
    public function getHwMode()
    {
        $session = $this->getAccessPoint()->getSession();
        if (false === $session) {
            return '-';
        }
        $opts = new \stdClass();
        $opts->device = $this->getName();
        $data = $session->callCached('iwinfo', 'info', $opts, 2);
        if (false === $data) {
            return 'AP Offline';
        }
        if (!property_exists($data, 'hwmodes')) {
            return '-';
        }

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
     * Get Mode.
     *
     * @return \string
     */
    public function getMode()
    {
        $session = $this->getAccessPoint()->getSession();
        if (false === $session) {
            return '-';
        }
        $opts = new \stdClass();
        $opts->device = $this->getName();
        $data = $session->callCached('iwinfo', 'info', $opts, 2);
        if (false === $data) {
            return 'AP Offline';
        }
        if (!property_exists($data, 'mode')) {
            return '-';
        }

        return $data->mode;
    }

    /**
     * Get Channel.
     *
     * @return \string
     */
    public function getChannel()
    {
        $session = $this->getAccessPoint()->getSession();
        if (false === $session) {
            return '-';
        }
        $opts = new \stdClass();
        $opts->device = $this->getName();
        $data = $session->callCached('iwinfo', 'info', $opts, 2);
        if (false === $data) {
            return 'AP Offline';
        }
        if (!property_exists($data, 'channel')) {
            return '-';
        }

        return $data->channel;
    }

    /**
     * Get TxPower.
     *
     * @return \string
     */
    public function getTxPower()
    {
        $session = $this->getAccessPoint()->getSession();
        if (false === $session) {
            return '-';
        }
        $opts = new \stdClass();
        $opts->device = $this->getName();
        $data = $session->callCached('iwinfo', 'info', $opts, 2);
        if (false === $data) {
            return 'AP Offline';
        }
        if (!property_exists($data, 'txpower')) {
            return '-';
        }

        return $data->txpower;
    }

    /**
     * Get HtMode.
     *
     * @return \string
     */
    public function getHtMode()
    {
        $session = $this->getAccessPoint()->getSession();
        if (false === $session) {
            return '-';
        }
        $opts = new \stdClass();
        $opts->device = $this->getName();
        $data = $session->callCached('iwinfo', 'info', $opts, 2);
        if (false === $data) {
            return 'AP Offline';
        }
        if (!property_exists($data, 'htmodes')) {
            return '-';
        }

        return join(', ', $data->htmodes);
    }

    /**
     * get Status.
     *
     * @return \string
     */
    public function getHwInfo()
    {
        $session = $this->getAccessPoint()->getSession();
        if (false === $session) {
            return '-';
        }
        $opts = new \stdClass();
        $opts->device = $this->getName();
        $data = $session->callCached('iwinfo', 'info', $opts, 2);
        if (false === $data) {
            return 'AP Offline';
        }
        if (!property_exists($data, 'hardware')) {
            return '';
        }
        if (!property_exists($data->hardware, 'id')) {
            return '';
        }
        $hw = $data->hardware->id;
        if (5772 == $hw[0] and 41 == $hw[1] and 5772 == $hw[2] and 41110 == $hw[3]) {
            return 'Atheros AR922X 5GHz';
        } elseif (5772 == $hw[0] and 41 == $hw[1] and 5772 == $hw[2] and 41111 == $hw[3]) {
            return 'Atheros AR922X 2GHz';
        } elseif (5772 == $hw[0] and 51 == $hw[1] and 5772 == $hw[2] and 41248 == $hw[3]) {
            return 'Atheros AR958x 802.11abgn';
        } elseif (5772 == $hw[0] and 70 == $hw[1] and 5772 == $hw[2] and 51966 == $hw[3]) {
            return 'Atheros 9984';
        } elseif (5772 == $hw[0] and 60 == $hw[1] and 0 == $hw[2] and 0 == $hw[3]) {
            return 'Atheros 988X';
        }

        return join(', ', $hw);
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
            'state.radio.composed['.$this->getId().']',
        ]);
    }

    public function getState()
    {
        $node = $this->treeCache['state.radio.composed['.$this->getId().']'] ?? null;
        if (!is_array($node)) {
            return 'Unknown';
        }

        return \ApManBundle\Library\NodeState::name(
            \ApManBundle\Library\NodeState::TYPE_RADIO, $node['state'] ?? null);
    }

    /** how long it has been in that state, in seconds, or null */
    public function getStateSince()
    {
        $node = $this->treeCache['state.radio.composed['.$this->getId().']'] ?? null;
        if (!is_array($node) || empty($node['since'])) {
            return null;
        }

        return time() - (int) $node['since'];
    }
}
