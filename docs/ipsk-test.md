# Testing iPSK against real hardware

Written 2026-08-21, when the iPSK path was taken end to end for the first time:
a key created in the controller, delivered by the access point's own RADIUS
server, bound to the station that used it, and withdrawn again — for WPA2 and
for WPA3/SAE.

Everything below was measured, not reasoned about. The numbers are what the
fleet does; where a claim has a time or a log line next to it, that is where it
came from.

The design it exercises is described in [ipsk.md](ipsk.md).

## Two rules before you touch anything

**A change to `/usr/share/ucode/wifi/ap.uc` needs a reboot.** netifd holds the
compiled ucode in memory, so `wifi reload` keeps running the old logic — the
file on disk and the behaviour disagree, which is a confusing place to debug
from. And do not patch it on a running access point at all: if wifi-scripts has
to change, it goes into the image as a package patch. `/rom/usr/share/ucode/wifi/ap.uc`
is the pristine copy; `cmp -s /rom$f $f` says whether an access point has been
tampered with.

**Check that hostapd can read the key files.** They must be world readable
(as wifi-scripts creates them) or group `network` (as the controller writes
them) — hostapd runs as `network` in a ujail, and a file it cannot open is
reported as *"not found"*, which fails the whole configuration and leaves the
radio down. `ls -la /var/run/hostapd-*.psk /var/run/hostapd-*.sae`.

**Avoid DFS channels for anything in a test.** They only come up reliably once.
Use 2.4 GHz channel 11, or 5 GHz 36/40/44/48. Know which production radios sit
on DFS before you reboot an access point — on this fleet that was `ap-av-attic`
radio1 (ch 100), `ap-av-klwz` radio3 (ch 52) and both outdoor radios (ch 116);
each of them re-runs a 60 s CAC on every restart.

## What the test bed is

Three roles, and they must be in radio range of each other:

| role | host in the 2026-08-21 run | what it does |
|---|---|---|
| access point | `ap-av-attic`, free radio `radio0` (phy0, 2.4 GHz ch 11) | carries the two test SSIDs and answers their RADIUS queries itself |
| station | `ap-av-klwz`, free radio `radio0` (phy0) as `mode sta` | associates, nothing else — `proto none`, so no DHCP and no address |
| controller | this working tree, in a container against the production database | creates keys, distributes them, revokes them |

`dsl-modem` also has a free radio and the agent, and the RADIUS layer was
verified there first — but it is **not in radio range of `ap-av-klwz`**, so the
over the air part cannot use the two together. Check reachability before
choosing hosts:

```sh
ssh root@<station> 'iw dev sta-test scan dump | grep -c "^BSS"'   # sees anything?
ssh root@<station> 'iw dev sta-test scan dump | grep -i "^BSS <ap-bssid-prefix>"'
```

Two SSIDs, so both key deliveries are covered by the same bed:

- `apman-tpsk` — `encryption psk2` (WPA2), uci section `tpsk`, ifname `wap-tp0`
- `apman-tsae` — `encryption sae` (WPA3), uci section `tsae`, ifname `wap-ts0`

Both carry `ppsk 1`, `auth_server 127.0.0.1` and the AP's own
`radius_secret`, and **no `wifi-station` sections at all**. That is the whole
point: with no station sections, wifi-scripts renders no `wpa_psk_file` and no
`sae_password_file`, and hostapd takes every key from the Access-Accept — for
SAE as well as for WPA2. The files must not be pointed at `/dev/null` either;
they simply must not exist.

`contrib/ipsk-testbed.sh` sets all of this up and tears it down again.

## Running it

```sh
contrib/ipsk-testbed.sh up ap-av-attic ap-av-klwz     # ap + station + db rows
contrib/ipsk-testbed.sh station-key ap-av-klwz <psk>  # point the station at a key
contrib/ipsk-testbed.sh down ap-av-attic ap-av-klwz   # leave nothing behind
```

The controller side runs through `apman:ipsk-test`, which uses the real
services (`PpskService`, `RadiusAuthService`) rather than reimplementing them:

```sh
php bin/console apman:ipsk-test flags      --ssid=apman-tsae
php bin/console apman:ipsk-test create     "Teststation" --ssid=apman-tsae
php bin/console apman:ipsk-test distribute --ssid=apman-tsae
php bin/console apman:ipsk-test show       --ssid=apman-tsae
php bin/console apman:ipsk-test revoke <id> --ssid=apman-tsae
```

On a workstation without `pdo_mysql` the console runs in a container; the
script prints the exact `docker run` line (`--network host`, `REDIS` pointed at
the production cache, the working tree mounted at `/app`).

### A RADIUS client for the wire level

`contrib/radius-probe.lua` builds a valid Access-Request (Message-Authenticator
and all), sends it to the agent and decrypts every `Tunnel-Password` of the
answer, printing them in the order hostapd will try them. Copy it to the access
point and ask a question directly:

```sh
lua /tmp/radius-probe.lua "$(uci -q get apman.main.radius_secret)" \
    127.0.0.1 1812 <sta-mac-hex> <bssid-hex> <ssid> [sae|psk]
```

Useful because it needs no client: it answers "what would this station be
offered right now", which is the question behind most iPSK problems.

## The full run, and what each step proves

1. **`create`** — an iPSK is a key with the wildcard MAC `00:00:00:00:00:00`
   and `pin_mac` set, so it belongs to nobody yet.
2. **`distribute`** — one MQTT command per access point, `apman keys`, one
   complete key set per SSID, answered with the version now in force
   (`{"versions":{"apman-tsae":"…"},"keys":n}`). No uci is written, no
   `RELOAD_WPA_PSK`, no runtime file.
3. **The station associates.** The agent logs which key it handed out and why:
   `radius-debug psk=… from=wildcard n=2 …` and
   `radius accept 8a:… key=ppsk_156_540 bss=… ssid=apman-tsae`. hostapd logs
   `AP-STA-CONNECTED … auth_alg=sae` (or `auth_alg=open` plus
   `EAPOL-4WAY-HS-COMPLETED` for WPA2).
4. **The key binds itself.** The agent publishes the accept on
   `apman/ap/<host>/radius/auth/<bssid>`; the subscriber resolves
   `ppsk_<ssid>_<row>` back to the row, writes a `radius_auth` line with the
   keyid, and `PpskService::recordUsed()` pins the MAC. Verify with `show`:
   the wildcard is gone and `mac=` is the station.
5. **The binding is enforced.** Ask the probe with a different MAC: the bound
   key is no longer offered, only the network passphrase (measured — the
   station gets `passwords=1`, the bound one `passwords=2`).
6. **`revoke`** — the key set goes out without the key, the station is kicked
   with a ban, the PMKSA caches are flushed, and it stays out. Measured 80 s
   later: `Not connected`, and the agent only offers `key=network`.

## Three hostapd behaviours that decide whether a withdrawal works

All three were found by watching a station refuse to go away. They are the
reason `PpskService` does what it does, in the order it does it.

**1. The RADIUS answer is cached per station, about half a minute.**
A new key set was pushed at 09:00:33 and the station reassociated at 09:00:46 —
hostapd never asked again and let it in with the *old* key. The next query only
came at 09:01:57. So a key change takes effect at the next association *after*
that cache expires, not immediately. The kick therefore carries
`ban_time` (`PpskService::KICK_BAN_MS`, 45 s) — long enough that the station
cannot come back before hostapd has to ask again.

**2. A deauthenticated station comes back through its cached PMKSA.**
On an SAE bss, a kicked station reassociated with `auth_alg=open` — PMKSA
caching, no SAE run at all, so the withdrawn key kept working. `PMKSA_FLUSH` on
the bss closes it (added to the agent's ctrl allowlist for exactly this). It
costs the stations that stay connected nothing: their PTK lives on, only their
next reassociation is a full one.

**3. Flushing only helps *after* the last successful association.**
Flush, then kick, and the station associates once more on the cached answer and
builds a fresh PMKSA — which is what it then uses. The working order is
**kick with a ban, then flush**. With the flush first the station was back
after 45 s; with it after the kick it stayed out.

## Checking a change end to end

The order below is what a full check looks like; each step has a cheap version
that needs no hardware and an expensive one that does.

**1. The controller decides correctly.** `apman:ipsk-test flags --ssid=<name>`
prints `usesOnApRadius`, `managesOwnKeys`, `isIpsk` and the delivery. Everything
downstream follows from these.

**2. Provisioning writes the right configuration — without writing it.**

```sh
php bin/console apman:ipsk-test provision-dry <ap-name>
```

builds the whole command list (`publishConfig($ap, true)`) and publishes
nothing. What to look for:

- **no `wifi-station` sections for an iPSK network** — group the output by
  SSID; an iPSK SSID must contribute zero, a file based one its usual keys ×
  interfaces;
- `uci set apman.main.radius_secret=…` plus `radius_enabled`, `radius_port`
  and a `uci commit apman`, with a secret that stays the same between runs;
- the `wifi-iface` for the iPSK SSID carrying `ppsk`, `auth_server=127.0.0.1`
  and `auth_secret`.

**3. Provisioning for real — keys first.** `applyConfig()` stages everything in
one uci transaction, asks the access point for the resulting diff, reverts when
nothing changed or staging failed, and only then applies with a rollback timer
armed. It is safe by construction, but the *order* is not: provisioning removes
the `wifi-station` sections of an iPSK network, so the key set has to be on the
access point first.

```sh
php bin/console apman:ppsk-distribute <ssid> --force   # keys into the key store
# verify the agent answers from the key store: the key name in its log turns
# from ppsk_<iface>_<row> into ppsk_<ssid id>_<row>
php bin/console apman:ipsk-test provision-apply <ap-name>
```

**4. Roaming.** Needs the same SSID on two access points that the station can
hear. Give the second one a `wifi-iface` with the same section name as its
device row, add that device row, distribute, and the key set goes to both.
Then:

- connect on the first, let the key bind itself, and check that **both** key
  stores carry the bound version (`/etc/apman/keys.json`) — the redistribution
  after pinning is what makes roaming work;
- move the station: `del_client` with a `ban_time` on the current access point
  is the reliable trigger, and it proves the second one admits the station on
  the same key.

**5. FT (802.11r).** Add `ieee80211r`, a shared `mobility_domain` and the same
`r0kh`/`r1kh` key to the test SSID on both access points:

```sh
uci set wireless.tsae.ieee80211r=1
uci set wireless.tsae.mobility_domain=a1b2
uci set wireless.tsae.ft_over_ds=0
uci set wireless.tsae.ft_psk_generate_local=0
uci set wireless.tsae.pmk_r1_push=0
uci set wireless.tsae.r0kh='ff:ff:ff:ff:ff:ff,*,<shared key>'
uci set wireless.tsae.r1kh='00:00:00:00:00:00,00:00:00:00:00:00,<shared key>'
```

**Driving the test client.** There is no `wpa_cli` on these images, but
`hostapd_cli` speaks the same control protocol — point it at the supplicant's
socket and it drives the station:

```sh
C() { hostapd_cli -p /var/run/wpa_supplicant -i sta-test raw "$@"; }
C STATUS                          # wpa_state, current bssid
C SCAN; sleep 8; C SCAN_RESULTS   # the target must be in the results to roam to it
C "BSS <bssid>"                   # proves arguments are passed through
C "LOG_LEVEL DEBUG"
C "ROAM <target-bssid>"           # quote command and argument as ONE string
C LIST_NETWORKS                   # the id of the current network
C "SET_NETWORK <id> bgscan \"simple:15:-55:60\""   # connected-state scanning
```

An unknown command answers `UNKNOWN COMMAND` and a known one that fails answers
`FAIL` — the difference is how to tell whether a feature is compiled in at all.

**Making the station answer BSS transition requests.** wpa_supplicant does not
advertise 802.11v BSS Transition unless `bss_transition=1` is in its
configuration, and OpenWrt's wifi-scripts only offer that option for AP mode
(`ap.uc`), not for a station. Add the line to the generated config and the
station announces the capability at its next association:

```sh
sed -i '1i bss_transition=1' /tmp/run/wpa-supplicant-sta-test.conf
```

(`wifi reload` regenerates the file, so this has to be repeated.) Whether it
took is visible on the access point:

```sh
hostapd_cli -p /var/run/hostapd -i <ifname> sta <mac> | grep ext_capab
```

Extended capabilities bit 19 is BSS Transition — byte 2 of the hex string,
mask `0x08`. `ext_capab=04000a…` has it (`0x0a & 0x08`).

**Steering it.** From the access point the station is on:

```sh
NR=$(ssh <target-ap> 'ubus call hostapd.<ifname> rrm_nr_get_own' | grep -o '"[0-9a-f]\{20,\}"' | tr -d '"')
ubus call hostapd.<ifname> bss_transition_request \
  '{"addr":"<mac>","disassociation_imminent":false,"validity_period":60,
    "abridged":true,"neighbors":["'"$NR"'0301ff"]}'
```

`0301ff` appended to the neighbour report is the candidate preference
subelement (id 3, length 1, value 255 = most preferred). With
`disassociation_imminent: true` plus a `disassociation_timer` the station has
to leave; without it, it decides for itself.

**Prerequisites, or the result is meaningless.** Two of these were wrong on
this fleet and made every FT attempt fail for reasons unrelated to iPSK:

```sh
uci set wireless.<sec>.ieee80211r=1
uci set wireless.<sec>.mobility_domain=a1b2        # identical on both APs
uci set wireless.<sec>.ft_over_ds=0
uci set wireless.<sec>.ft_psk_generate_local=0     # SAE only; PSK derives locally
uci set wireless.<sec>.nas_identifier=$(uname -n)  # UNIQUE per AP — it is the R0KH-ID
uci -q delete wireless.<sec>.r0kh; uci -q delete wireless.<sec>.r1kh
uci add_list wireless.<sec>.r0kh='ff:ff:ff:ff:ff:ff,*,<64 hex>'   # identical on both
uci add_list wireless.<sec>.r1kh='00:00:00:00:00:00,00:00:00:00:00:00,<64 hex>'
```

`r0kh`/`r1kh` are **mandatory** on an iPSK network: left alone, ap.uc derives
the FT key from `md5(mobility_domain + '/' + auth_secret)`, and `auth_secret` is
the per access point RADIUS secret — the two access points would never agree.
And the station needs `ieee80211r=1` too, or it negotiates plain SAE/WPA-PSK.

**Then roam twice.** The first roam to a given access point fails: with
`macaddr_acl=2` the target does not answer the authentication frame until its
RADIUS query returns, and the station gives up after three tries in ~330 ms.
That failure warms the ACL cache, and the second roam is a real transition:

```sh
C() { hostapd_cli -p /var/run/wpa_supplicant -i sta-test raw "$@"; }
C SCAN; sleep 8
C "ROAM <target-bssid>"     # cold: times out, station falls back
C "ROAM <target-bssid>"     # warm: fast transition
```

Read the verdict on the access point the station moved **to**:

```
AP-STA-CONNECTED <mac> auth_alg=ft      # a fast transition
AP-STA-CONNECTED <mac> auth_alg=sae     # a full authentication
```

`hostapd_cli -i <if> sta <mac> | grep AKMSuiteSelector` says which AKM was
negotiated: `00-0f-ac-9` FT-SAE, `00-0f-ac-4` FT-PSK, `00-0f-ac-8` plain SAE,
`00-0f-ac-2` plain WPA-PSK, `00-0f-ac-6` PSK-SHA256. Note that **every `FT:`
line in hostapd is `MSG_DEBUG`**, so their absence proves nothing — only
`auth_alg=` does.

**Result on 2026-08-21** (two access points, keys from the access point's own
RADIUS server): `auth_alg=ft` for **both** FT-SAE and FT-PSK. Per device keys
delivered over RADIUS do not stand in the way of 802.11r.

**A shorter way to steer it.** The `rrm_nr_get_own` dance above builds a proper
neighbour report, but for a two access point check `hostapd_cli bss_tm_req` on
the access point the station currently sits on is enough, and it needs nothing
on the station beyond `bss_transition=1`:

```sh
hostapd_cli -i <ifname> bss_tm_req <mac> pref=1 abridged=1 \
  disassoc_imminent=1 disassoc_timer=30 \
  neighbor=<target-bssid>,0x0000,<op_class>,<channel>,7
```

Operating class 81 is 2.4 GHz, 115/116 the lower 5 GHz band; the trailing `7`
is the phy type (HT). It answers `OK` and the station moves — with a warm ACL
cache, inside the same second.

**Verified on the production network, 2026-08-21.** The same procedure was run
on `kalclients` itself rather than on a test SSID, with a throwaway key bound to
the test station's address, roaming `ap-av-klwz` → `ap-av-attic` and back:

```
17:56:42  wap-kc2: AP-STA-CONNECTED 8c:fd:f0:19:ba:a2 auth_alg=ft
17:56:42  wap-kc2: STA 8c:fd:… WPA: FT authentication already completed - do not start 4-way handshake
17:56:42  radius accept 8c:fd:f0:19:ba:a2 key=ppsk_140_585 bss=20:20:4b:e3:b4:f8 ssid=kalclients
17:57:30  wap-kc2: AP-STA-CONNECTED 8c:fd:f0:19:ba:a2 auth_alg=ft        (back on klwz)
```

Both transitions were immediate — the station had associated to both access
points before, so neither ACL cache was cold. `AKMSuiteSelector=00-0f-ac-9`
(FT-SAE) on both, and the per device key came from the key store in each case.

**About the test client.** It negotiates FT-SAE and FT-PSK, and it handles
802.11v BSS transition requests once `bss_transition=1` is added to
`/tmp/run/wpa-supplicant-sta-test.conf` (OpenWrt's wifi-scripts only offer that
option for AP mode, and `wifi reload` regenerates the file). What it will not do
is roam of its own accord: it ignored a candidate 9 dB stronger with preference
255, and background scanning changed nothing. `ROAM <bssid>` through the control
interface is the reliable trigger, and it is enough.

**6. The interface.** The controller can be served straight from the working
tree, which is the fastest way to see whether a page still renders what it
claims:

```sh
docker run -d --name apman-web --network host -e REDIS=<redis> \
  -v $PWD:/app -w /app -u $(id -u):$(id -g) apman-cli php -S 127.0.0.1:8899 -t public public/index.php
docker exec apman-web php -r 'echo file_get_contents("http://127.0.0.1:8899/ppsk");'
```

(With rootless docker the port is not reachable from the host, hence the
`docker exec`.) Run `bin/console cache:clear` after touching a template, or the
old one is served. Worth checking after every change to the key path: the
`/ppsk` and `/ipsk` pages, and an SSID detail page of an iPSK network — the
last one because its RADIUS card is assembled from several sources and is the
easiest to get wrong.

## Other things the run turned up

- **Whether hostapd sends `WLAN-AKM-Suite` in the MAC-ACL query depends on the
  version.** `ap-av-attic` logged `akm=nil` for both the WPA2 and the SAE bss,
  while `dsl-modem` (OpenWrt 25.12.5) logged `akm=SAE` for kalclients. So the
  agent cannot rely on the request to tell it whether it is answering an SAE
  association — and a 64 hex raw PSK (what WPS enrolment produces) handed to an
  SAE station is unusable. The key set therefore carries an explicit `sae` flag
  from the controller, which knows the encryption; the AKM attribute is used as
  a second source when it is there.
- **A keystore must not replace the whole store.** An access point carries
  migrated networks next to ones that still keep their keys in `wifi-station`
  sections; the agent merges both and lets the keystore win only for the
  interfaces it names. Verified live: pushing a test key set on `ap-av-attic`
  left its 106 production keys answering (`radius accept … key=ppsk_radio2_kalnet_360`).
- **wifi-scripts always renders the key files** — the apparent inconsistency
  between access points was a runtime patch to `ap.uc`, not a version
  difference. With no `wifi-station` sections the file is truncated to 0 bytes,
  and hostapd treats that as "no entries" and falls through to the RADIUS
  answer. Measured for WPA2 and for SAE. What must **not** appear on an iPSK
  bss is a `wpa_passphrase`: `sae_get_password()` prefers it over the
  RADIUS-delivered key, and `ppsk=1` is what keeps it out.
- **The controller reprovisions access points on its own.** A station
  interface added by hand to a managed AP disappears at the next config push
  (`publishConfig` deletes every `wifi-iface` section first). If the test bed
  vanishes mid-run, that is why — `ipsk-testbed.sh up` puts it back.
- **A station that was just kicked backs off by itself.** After a few failed
  attempts wpa_supplicant logs `CTRL-EVENT-SSID-TEMP-DISABLED … duration=30`
  and ignores the BSSID for a while. During a revoke test that looks exactly
  like a broken key; bounce the station's radio (`wifi down`/`wifi up`) before
  concluding anything from a failed association that follows a kick.
- **`sae_pwe` on an iPSK network takes the whole network down, immediately.**
  Rolled out on 2026-08-21 as `sae_pwe=2` in the iPSK feature lines; every SAE
  client that supports hash-to-element stopped associating within the minute
  (`RX commit, status=126 (SAE_HASH_TO_ELEMENT)` on the access point, `AUTH-REJECT
  … status_code=1` on the station). A RADIUS-delivered password has no SAE PT.
  ap.uc's `if (!config.ppsk)` guard around its `sae_pwe` default is load
  bearing; do not work around it. The check after any provisioning change is one
  line per access point:
  `grep -hc '^sae_pwe=' /var/run/hostapd-phy*.conf` must be 0 on every access
  point whose SAE networks are iPSK.
- **An access point the controller calls offline can still be broadcasting.**
  `ap-hv-klwz` was unreachable through the whole rollout: it kept serving
  `kalclients` from its old configuration and its old key store, so it was
  neither fixed nor re-keyed. A fleet-wide verification loop has to list which
  access points it could **not** reach, or it reports a clean fleet it never saw.
- **A hung agent looks exactly like an offline access point.** `dsl-modem`
  answered `no answer from the access point, nothing was applied` twice; its
  `apman-status` process was spinning in state `R` since the previous
  provisioning, last log line a `uci changes` call. `/etc/init.d/apman-status
  restart` (the service is not called `apman`) and the next attempt went
  through. Check `ps w | grep apman-status` and the timestamp of its last log
  line before concluding an access point is down.
- **Two key paths on one network fight.** While the test ran, a deployed
  subscriber still using the file path wrote a `wifi-station` section for the
  test SSID onto the access point. That is the conflict the keystore removes;
  after upgrading the subscriber it stops happening.
