<?php

namespace ApManBundle\Library;

class AccessPointState {
	const STATE_OFFLINE = 0;
	const STATE_ONLINE = 1;
	const STATE_PENDING = 2;
	const STATE_FAILED = 3;
	const STATE_CONFIGURED = 4;
	const STATE_DFS_RUNNING = 5;
	const STATE_DFS_READY = 6;
	const STATE_ACTIVE = 7;

	static function getStateName($state) {
        	if (is_null($state)) $state = 0;;
		$sClass = new \ReflectionClass( __CLASS__);
		$map = $sClass->getConstants();
		if (!is_array($map)) return $state;
		$isMapped = array_search($state, $map);
		if ($isMapped === false) return $state;
		return $isMapped;
	}
}
