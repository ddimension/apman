<?php

namespace ApManBundle\Library;

class AccessPointState
{
    public const STATE_OFFLINE = 0;
    public const STATE_ONLINE = 1;
    public const STATE_PENDING = 2;
    public const STATE_FAILED = 3;
    public const STATE_CONFIGURED = 4;
    public const STATE_DFS_RUNNING = 5;
    public const STATE_DFS_READY = 6;
    public const STATE_ACTIVE = 7;

    public static function getStateName($state)
    {
        if (is_null($state)) {
            $state = 0;
        }
        $sClass = new \ReflectionClass(__CLASS__);
        $map = $sClass->getConstants();
        if (!is_array($map)) {
            return $state;
        }
        $isMapped = array_search($state, $map);
        if (false === $isMapped) {
            return $state;
        }

        return $isMapped;
    }
}
