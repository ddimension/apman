#!/bin/bash
# The iPSK test bed: two iPSK SSIDs on a free radio of one access point, a
# station on a free radio of another, and the matching rows in the controller
# database. See docs/ipsk-test.md for what it proves and how to drive it.
#
#   ipsk-testbed.sh up          <ap-host> <station-host> [channel]
#   ipsk-testbed.sh station-key <station-host> <psk> [tpsk|tsae]
#   ipsk-testbed.sh down        <ap-host> <station-host>
#   ipsk-testbed.sh console     <console arguments...>
#
# The access point keeps its own radius secret; the SSIDs carry ppsk=1 and
# auth_server=127.0.0.1 and deliberately no wifi-station sections, so hostapd
# has no psk/sae file and every key comes from the Access-Accept.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SSH="ssh -o BatchMode=yes"
RADIO="${RADIO:-radio0}"        # the free radio on both hosts
AP_IF_SECTIONS="tpsk tsae"

# The console needs pdo_mysql and redis, which a workstation php often lacks.
# REDIS points at the production cache so the controller sees real device state.
console() {
	if php -m 2>/dev/null | grep -q pdo_mysql; then
		( cd "$ROOT" && php bin/console --env=prod "$@" )
		return
	fi
	docker image inspect apman-cli >/dev/null 2>&1 || {
		printf 'FROM php:8.4-cli\nRUN docker-php-ext-install pdo_mysql sockets && pecl install redis && docker-php-ext-enable redis\n' \
			| docker build -q -t apman-cli - >/dev/null || return 1
	}
	docker run --rm --network host -e REDIS="${REDIS:-192.168.203.38}" \
		-v "$ROOT:/app" -w /app -u "$(id -u):$(id -g)" apman-cli \
		php bin/console --env=prod "$@"
}

ap_up() {
	local host="$1" ch="${2:-11}"
	$SSH "root@$host" "S=\$(uci -q get apman.main.radius_secret)
		[ -n \"\$S\" ] || { echo 'no radius_secret on this access point'; exit 1; }
		uci set wireless.$RADIO.disabled=0; uci set wireless.$RADIO.channel=$ch
		for n in $AP_IF_SECTIONS; do
			uci -q delete wireless.\$n
			uci set wireless.\$n=wifi-iface; uci set wireless.\$n.device=$RADIO
			uci set wireless.\$n.mode=ap; uci set wireless.\$n.disabled=0
			uci set wireless.\$n.ppsk=1
			uci set wireless.\$n.auth_server=127.0.0.1; uci set wireless.\$n.auth_port=1812
			uci set wireless.\$n.auth_server_port=1812; uci set wireless.\$n.auth_secret=\"\$S\"
		done
		uci set wireless.tpsk.ssid=apman-tpsk; uci set wireless.tpsk.encryption=psk2; uci set wireless.tpsk.ifname=wap-tp0
		uci set wireless.tsae.ssid=apman-tsae; uci set wireless.tsae.encryption=sae;  uci set wireless.tsae.ifname=wap-ts0
		uci commit wireless; wifi reload $RADIO >/dev/null 2>&1; sleep 12
		for i in wap-tp0 wap-ts0; do
			printf '%s: ' \"\$i\"
			ubus call hostapd.\$i get_status 2>/dev/null | grep -E '\"status\"|\"bssid\"|\"freq\"' | tr -d '\n\t '
			echo
		done
		# wifi-scripts always renders wpa_psk_file/sae_password_file and
		# truncates them from the wifi-station sections — an iPSK network has
		# none, so the files must be present and EMPTY. A non-empty one would
		# outrank the key store, because hostapd reads the file first.
		echo 'key files (must exist and be empty):'
		for f in /var/run/hostapd-wap-tp0.psk /var/run/hostapd-wap-ts0.sae; do
			[ -f \$f ] && echo \"  \$f = \$(wc -c < \$f) bytes\" || echo \"  \$f missing\"
		done"
}

sta_up() {
	local host="$1" ch="${2:-11}"
	$SSH "root@$host" "uci set wireless.$RADIO.disabled=0; uci set wireless.$RADIO.channel=$ch
		uci set wireless.$RADIO.band=2g; uci set wireless.$RADIO.htmode=HT20; uci set wireless.$RADIO.country=DE
		for s in \$(uci show wireless | grep '=wifi-iface' | sed 's/wireless\.//;s/=.*//' | grep \"^${RADIO}_\"); do
			uci set wireless.\$s.disabled=1
		done
		uci -q delete network.stanet; uci set network.stanet=interface
		uci set network.stanet.proto=none; uci set network.stanet.device=sta-test
		uci -q delete wireless.stest; uci set wireless.stest=wifi-iface
		uci set wireless.stest.device=$RADIO; uci set wireless.stest.mode=sta
		uci set wireless.stest.ifname=sta-test; uci set wireless.stest.network=stanet
		uci set wireless.stest.ssid=apman-tsae; uci set wireless.stest.encryption=sae
		uci set wireless.stest.ieee80211w=2; uci set wireless.stest.key=placeholder; uci set wireless.stest.disabled=0
		# without this the station negotiates plain SAE/PSK and no FT test is
		# possible; the uci option is honoured for stations too
		uci set wireless.stest.ieee80211r=1
		uci commit wireless; uci commit network; wifi reload $RADIO >/dev/null 2>&1; sleep 12
		echo \"station mac: \$(cat /sys/class/net/sta-test/address 2>/dev/null || echo '-')\""
}

case "${1:-}" in
up)
	ap="${2:?ap host}"; sta="${3:?station host}"; ch="${4:-11}"
	echo "== access point $ap"; ap_up "$ap" "$ch"
	echo "== station $sta";     sta_up "$sta" "$ch"
	echo "== controller rows";  console apman:ipsk-test setup 2>&1 | tail -4
	echo
	echo "the station mac above is what a key binds itself to; drive the run with:"
	echo "  $0 console apman:ipsk-test create 'Teststation' --ssid=apman-tsae"
	;;
station-key)
	sta="${2:?station host}"; psk="${3:?psk}"; which="${4:-tsae}"
	if [ "$which" = tpsk ]; then s=apman-tpsk; e=psk2; else s=apman-tsae; e=sae; fi
	$SSH "root@$sta" "uci set wireless.stest.ssid=$s; uci set wireless.stest.encryption=$e
		uci set wireless.stest.key='$psk'
		[ '$e' = sae ] && uci set wireless.stest.ieee80211w=2 || uci -q delete wireless.stest.ieee80211w
		# keep FT on: without it the station negotiates plain SAE/WPA-PSK and
		# no fast transition can happen, whatever the access points offer
		uci set wireless.stest.ieee80211r=1
		uci commit wireless; wifi down $RADIO >/dev/null 2>&1; sleep 4; wifi up $RADIO >/dev/null 2>&1; sleep 30
		iw dev sta-test link | head -3
		# a station kicked moments ago backs off for ~30 s (SSID-TEMP-DISABLED);
		# if it says 'Not connected', give it one more bounce before believing it
		"
	;;
down)
	ap="${2:?ap host}"; sta="${3:?station host}"
	# also any wifi-station section an older, file based distribution left
	# behind for the test interfaces
	$SSH "root@$ap" "for n in $AP_IF_SECTIONS; do uci -q delete wireless.\$n; done
		for s in \$(uci show wireless | grep '=wifi-station' | sed 's/wireless\.//;s/=.*//'); do
			for n in $AP_IF_SECTIONS; do
				[ \"\$(uci -q get wireless.\$s.iface)\" = \"\$n\" ] && uci -q delete wireless.\$s
			done
		done
		uci commit wireless; rm -f /tmp/radius-probe.lua
		wifi reload $RADIO >/dev/null 2>&1; echo 'access point cleaned'"
	$SSH "root@$sta" "uci -q delete wireless.stest; uci -q delete network.stanet
		uci set wireless.$RADIO.disabled=1
		for s in \$(uci show wireless | grep '=wifi-iface' | sed 's/wireless\.//;s/=.*//' | grep \"^${RADIO}_\"); do
			uci -q delete wireless.\$s.disabled
		done
		uci commit wireless; uci commit network; wifi reload $RADIO >/dev/null 2>&1; echo 'station cleaned'"
	console apman:ipsk-test teardown 2>&1 | tail -3
	;;
console)
	shift; console "$@"
	;;
*)
	sed -n '2,20p' "$0"; exit 1
	;;
esac
