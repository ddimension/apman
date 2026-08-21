# iPSK: per device keys answered by the access point

Every device on the network gets its own passphrase. The access point does not
decide anything about it — it asks its own RADIUS server on every association,
and that server answers from a key set the controller shipped to it.

This is what makes three things possible at once, which the older
`wpa_psk_file` scheme could not do together:

- a key can be **withdrawn for one device** without touching anybody else,
- the network passphrase can be **rotated** without locking out everything ever
  configured with it,
- and it **works for WPA3/SAE**, where a key change used to cost every client
  of the radio its connection.

Companion documents: [ipsk-test.md](ipsk-test.md) is how to exercise all of it
against real hardware, [radius-access-accept.md](radius-access-accept.md) is
what hostapd reads out of an Access-Accept and why the keyid cannot travel in
it.

## The chain

```
controller                        access point
──────────                        ────────────
PpskService::distributeKeystore()
   │ mqtt  apman/ap/<host>/command/bulk
   │       {"method":"call","params":["","apman","keys",{…}]}
   ▼
                                  apman-status (agent)
                                    radius.apply_keys()
                                    /etc/apman/keys.json   ← tmp + rename, mode 600
                                       │
                                    radius server on 127.0.0.1:1812
                                       ▲ Access-Request (per association)
                                       │ Access-Accept: Tunnel-Password(s)
                                    hostapd  (ppsk=1 → wpa_psk_radius=2, macaddr_acl=2)
                                       │
   ◀───────────────────────────────────┘ mqtt apman/ap/<host>/radius/auth/<bssid>
SubscriptionService::handleRadiusAuthEvent()
   → radius_auth row (who used which key)
   → PpskService::recordUsed()  → binds the key to the MAC that claimed it
```

The access point never holds the truth, only a copy with a version on it. The
controller can always say which version each access point acknowledged.

## Where the keys come from, and the one rule that matters

An iPSK network has **no `wifi-station` uci sections**. What that does is
narrower than it used to say here, and the difference took a day to establish.

wifi-scripts (`/usr/share/ucode/wifi/ap.uc`) renders `wpa_psk_file` and
`sae_password_file` for **every** psk/sae network, unconditionally, and rewrites
the file from the `wifi-station` sections on every config generation with
`fs.open(path,'w')` — a truncate. With no sections the file is therefore
**present and 0 bytes**. Both hostapd parsers walk such a file with
`while (fgets(...))`, iterate zero times and return success. Measured
2026-08-21 on `ap-av-attic`: a station associated over WPA2 *and* over
WPA3/SAE, each time with the key delivered by the access point's own RADIUS
server, with the option set and the file empty.

So the empty file is not the mechanism and not a problem. **Never point the
options at `/dev/null`** — that is a character device, hostapd does not cope,
and it is a different thing from an empty regular file.

What *does* break — and breaks hard — is a key file hostapd **cannot read**.
It then refuses the whole configuration and the entire phy stays down:

```
sae_password_file '/var/run/hostapd-wap-ts0.sae' not found.
Line 93: Invalid sae_password in file
1 errors found in configuration file '<inline>'
hostapd.add_iface failed for phy phy0 ifname=wap-tp0
```

"not found" is misleading: the file was there, it was `root:root` mode 0640,
and hostapd runs as user `network` inside a ujail. wifi-scripts creates these
files world readable (0644), which is why this normally never shows; the
controller writes its own with `chown root:network` and mode 0640, which also
works. Anything that leaves them `root:root` and not world readable takes the
radio down with it — a blanket `chmod 640` over `/var/run/hostapd-*` is exactly
that mistake.

The rule that actually protects iPSK is this: `sae_get_password()` tries, in
order,

1. `sae_passwords` — the contents of `sae_password_file`,
2. **`ssid.wpa_passphrase`**,
3. `sta->psk` — the passphrases the Access-Accept delivered.

An empty file skips step 1, so RADIUS wins — *unless* step 2 has something in
it. **A `wpa_passphrase` on an iPSK bss silently disables per device keys for
every SAE client.** What keeps it out is the `ppsk` option: ap.uc's
`if (config.ppsk) … else if (length(key) == 64) … else if (8..63) wpa_passphrase`
chain never reaches the passphrase branch when `ppsk` is set. That is why an
iPSK network sets `ppsk 1` and not just the raw `wpa_psk_radius=2` — the raw
option alone would leave the passphrase in place.

(For WPA2 the order is more forgiving: `hostapd_get_psk()` iterates the file
list *and* `sta->psk`, so a network passphrase and per device keys coexist. That
is why `kalnet`, which sets `wpa_psk_radius=2` as a raw option and keeps its
passphrase, works — but the same shortcut on an SAE network would not.)

One more thing `ppsk` does, deliberately: it suppresses ap.uc's `sae_pwe`
default. **Do not put `sae_pwe` back.** A password that came from RADIUS has no
SAE PT (`use_sta_psk` is only set from the ucode `sta_auth` hook, which the
RADIUS ACL path never reaches), so with H2E advertised a client that commits
with H2E is answered "SAE: No password available". The corollary is that iPSK
plus SAE cannot work on 6 GHz at all, where hostapd force-enables H2E.

That is not a theory. On 2026-08-21 `sae_pwe=2` was added to the iPSK feature
lines and rolled out, and `kalclients` stopped admitting SAE clients fleet-wide
within the minute:

```
sta-test: CTRL-EVENT-AUTH-REJECT 20:20:4b:e3:b4:f8 auth_type=3 auth_transaction=1 status_code=1
wap-kc2: STA 8c:fd:… IEEE 802.11: start SAE authentication (RX commit, status=126 (SAE_HASH_TO_ELEMENT))
```

The client saw H2E advertised, committed with it (`status=126`), and hostapd —
holding a RADIUS password with no PT — could only answer status 1. Removing the
line and re-provisioning restored it. The `if (!config.ppsk)` guard in ap.uc is
there for exactly this; treat it as load bearing.

`AccessPointService::publishConfig()` skips the station sections, the feature
catalog carries `ppsk 1`, `auth_server 127.0.0.1` and `auth_server_port 1812`,
and `IpskFeatureService` fills in the per access point `auth_secret` and repeats
`wpa_psk_radius=2` / `macaddr_acl=2` as raw hostapd lines. The secret is per
access point on purpose — the SSID config is shared by the whole fleet and must
not carry it; it lives in `/etc/config/apman` (`radius_secret`) and in the
`accesspoint.radius_secret` column.

### Do not patch `ap.uc` on a running access point

On 2026-08-21 six of seven access points were found with a runtime patch that
commented those two `set_default` lines out. It did what it was meant to (no
key files) and one thing it was not meant to: it killed the file path for
**every** network, so `kalnet`'s 47 per device keys were inert — hostapd knew
only its `wpa_passphrase` — and every `RELOAD_WPA_PSK` answered FAIL. The patch
was reverted from `/rom` on all of them.

Two operational notes from that: a change to `ap.uc` needs a **reboot**, not a
`wifi reload` — netifd holds the compiled ucode in memory. And if a wifi-scripts
change is ever genuinely required, it belongs in the image as a package patch,
never on a running access point.

## The key set

One complete set per SSID, not a diff. Sending a set is idempotent, and the
version tells both sides whether anything actually changed.

```json
{"ssid": "kalclients", "version": "51d01e62abfd", "sae": true,
 "ifaces": ["radio0_kalclients", "radio1_kalclients"],
 "network_key": "the passphrase offered to devices without a key of their own",
 "keys": [{"name": "ppsk_7_123", "mac": "aabbccddeeff", "psk": "…", "vid": "26"},
          {"name": "ppsk_7_124", "mac": null,           "psk": "…"}]}
```

- `ifaces` are the **uci `wifi-iface` section names** of that SSID on this
  access point. The agent maps a request's BSSID to a section and looks the key
  up inside that bucket only — a station asking on one SSID can never be given
  the key of another.
- `name` is `ppsk_<ssid id>_<row id>`; `PpskService::resolveBySectionName()`
  maps it back to the row when the accept comes home. It is also what shows up
  in the agent's log line.
- `mac: null` is a wildcard key: offered to any device that has none of its own.
- `sae` says whether this network speaks SAE. The agent needs it because
  hostapd does not reliably put `WLAN-AKM-Suite` in the MAC-ACL query — some
  versions send it, some do not — and a 64 hex raw PSK (what WPS enrolment
  produces) is unusable for SAE. With the flag set, such keys are left out of
  an SAE answer.
- `keys: null` removes the SSID from the key store.

The answer is `{"versions": {"<ssid>": "<version>"}, "keys": n, "errors": [...]}`.
`distributeKeystore()` compares the version it sent against the one it gets
back, per access point, and logs anything that did not acknowledge.

**The store is merged, not replaced.** An access point carries migrated
networks next to ones that still keep their keys in `wifi-station` sections;
the agent loads both and lets the key store win only for the interfaces it
names. Without that, shipping a key set for one SSID would strand every other
SSID on that access point.

## What the access point answers

`RadiusAuthService` and the agent follow the same rule, and the order matters:

1. the station's own key, if it has one;
2. otherwise every wildcard key;
3. plus `network_key`, when the SSID has `radius_fallback` set.

They go into the packet **least specific first**, because hostapd *prepends*
each `Tunnel-Password` to the station's list — so the last attribute of the
packet is the first key it tries. With WPA2 that only saves PBKDF2 runs; with
SAE it decides everything, because `sae_get_password()` takes the first
passphrase and never looks at another one.

A VLAN belongs to the key and only the first key can carry one (`Tunnel-Type` /
`Tunnel-Medium-Type` / `Tunnel-Private-Group-Id`, tag 0). The agent suppresses
it when the id equals the VLAN the bss already lives on.

## The life of a key

**Created** as an identity with no owner: wildcard MAC, `pin_mac` set
(`PpskService::createIpsk()`).

**Distributed** — one command per access point, acknowledged with a version.

**Bound** the first time it is used. The agent reports every decision on
`apman/ap/<host>/radius/auth/<bssid>`; the subscriber resolves the key name,
writes a `radius_auth` row carrying the keyid, and `recordUsed()` sets the MAC.
From then on the key works for that device only — a copy of the code on a
second phone is refused (visible as `AP-STA-POSSIBLE-PSK-MISMATCH`), and the
pinning triggers a redistribution so every access point learns the binding.

For an SAE network this event is the *only* trace a key's use leaves: hostapd's
`keyid=` in `AP-STA-CONNECTED` comes from `wpa_psk_file`, which does not exist
here — see [radius-access-accept.md](radius-access-accept.md).

**Withdrawn** with `PpskService::revoke()`: the row is disabled (or purged),
the key set goes out without it, and the station is thrown off. What that takes
is the next section.

## Roaming, and 802.11r

Roaming needs nothing special: every access point of the network holds the same
key set, so a station is admitted wherever it goes. The part that has to work is
the **redistribution after a key binds itself** — the moment a wildcard key is
pinned to an address, every access point must learn the bound version. Verified
2026-08-21 across two access points: after the pinning both key stores carried
the bound version, and a station kicked off one associated on the other with the
same key.

**802.11r works on an iPSK network** — measured, for both deliveries:

```
wap-ts0: AP-STA-CONNECTED 8c:fd:f0:19:ba:a2 auth_alg=ft     (FT-SAE, AKM 00-0f-ac-9)
wap-tp0: AP-STA-CONNECTED 8c:fd:f0:19:ba:a2 auth_alg=ft     (FT-PSK, AKM 00-0f-ac-4)
```

Since 2026-08-21 it is also measured on the **production** `kalclients`
network, in both directions between `ap-av-klwz` and `ap-av-attic`:

```
wap-kc2: AP-STA-CONNECTED 8c:fd:f0:19:ba:a2 auth_alg=ft
wap-kc2: STA 8c:fd:f0:19:ba:a2 WPA: FT authentication already completed - do not start 4-way handshake
wap-kc2: AKMSuiteSelector=00-0f-ac-9
```

The FT key hierarchy derives from the PMK, and the PMK is whatever the
Access-Accept delivered, so per device keys over RADIUS are no obstacle. Three
things have to be right; two of them were wrong in this fleet until 2026-08-21
and are now fixed by the provisioning:

1. **`nas_identifier` must be unique per access point.** It is the R0KH-ID.
   Every access point here reported the same `kalnet-ap.loc`, which makes every
   one of them accept a broadcast PMK-R1 pull and answer it — the ones without
   the key answer "No matching PMK-R0-Name found" and race the correct answer.
   `AccessPointService::getDeviceConfig()` now overrides `nasid` with the access
   point's own name whenever the network carries `ieee80211r` or a
   `mobility_domain`. It has to run **after** the feature merge, or the flag it
   tests is not set yet.
2. **`r0kh` / `r1kh` must be set explicitly and identically.** Left to itself,
   ap.uc derives the FT key as `md5(mobility_domain + '/' + auth_secret)` — and
   on an iPSK network `auth_secret` is the **per access point** RADIUS secret,
   so two access points derive different keys and never agree. `kalclients`
   only works because the controller writes `r0kh`/`r1kh` explicitly.
3. The **station** must offer FT (`ieee80211r`), or it negotiates plain
   SAE/WPA-PSK whatever the access points advertise.

### The first roam to an access point always fails

This is the part that hid the whole feature, and it is worth knowing before
blaming FT for anything.

`macaddr_acl=2` puts a RADIUS query in front of the authentication frame. When
the target access point has no cached ACL entry for that station, hostapd does
not answer the frame — it starts the RADIUS query and replies only once the
answer is in. The station retransmits three times in about 330 ms and gives up:

```
sta-test: send auth to 2a:d1:27:4d:85:3b (try 1/3 … 3/3)
sta-test: authentication with 2a:d1:27:4d:85:3b timed out
```

…while the target logs, in the same second, `radius accept 8c:fd:… key=ppsk_189_573`.
The answer was right; it was just too late. The station falls back to a full
authentication, and **that** warms the cache — so the *next* roam to the same
access point is a real fast transition. Both `auth_alg=ft` lines above were the
second attempt.

The cache lifetime is `RADIUS_ACL_TIMEOUT`, a compile-time constant of 30
seconds in `src/ap/ieee802_11_auth.c`; it cannot be tuned from the
configuration. In practice this means the first association of a device at each
access point is slow and non-FT, and everything after it within the window is
fast. If that matters, the fix is a hostapd patch shipped in the image.

Note also that **every `FT:` log line in hostapd is `MSG_DEBUG`**, so their
absence proves nothing about whether FT was attempted. The only trustworthy
signal is `auth_alg=` in `AP-STA-CONNECTED` on the access point the station
moved to.

## Withdrawing a key actually works — in this order

A withdrawal is not finished when the key is gone from the key store. Three
hostapd behaviours will hand the device its old key back, and only one order
defeats all three. All three were measured on the fleet on 2026-08-21.

1. **hostapd caches the RADIUS answer per station, roughly half a minute.** A
   station that reassociates inside that window is admitted again with the key
   that was just withdrawn — it never asks. So the kick carries a ban
   (`PpskService::KICK_BAN_MS`, 45 s), long enough that hostapd must ask again
   before the device is allowed back.
2. **A deauthenticated station returns through its cached PMKSA.** On an SAE
   bss a kicked station came back with `auth_alg=open` — no SAE run at all, the
   withdrawn key still in force. `PMKSA_FLUSH` on the bss closes that door. It
   costs connected stations nothing: their PTK lives on, only their next
   reassociation is a full one.
3. **Flushing only helps after the last successful association.** Flush first
   and the station associates once more on the cached answer, building a fresh
   PMKSA which it then uses.

Hence: **push the key set → kick with a ban → flush the PMKSA caches.**
`distributeKeystore()` does this whenever the version changed, `revoke()` does
it for the one station. `PMKSA_FLUSH` is in the agent's ctrl allowlist for
exactly this purpose.

The kick itself must not depend on the status cache alone. When the cache does
not know where the station is — a device that has not reported yet, a station
that associated since the last report — `deauthenticate()` sends `del_client`
to every bss of the network instead; hostapd ignores it for a station it does
not have. A kick that silently does nothing is the worst outcome of a
revocation.

Residual window: up to the RADIUS answer cache (~30 s) for a device that is not
currently connected and therefore not kicked. `Session-Timeout` in the accept
would bound it further; we do not send one today.

## Putting an existing network on iPSK

`apman:ipsk-migrate` assigns the feature and provisions the access points. The
order inside it matters and is not the obvious one:

**keys first, configuration second.** Provisioning removes the `wifi-station`
sections, so an access point that has not received its key set yet would sit
there with no keys at all until the distribution catches up. The command
distributes, then provisions.

Provisioning restarts the bss of every flipped SSID once — that is unavoidable,
the security configuration changes. Afterwards key changes never restart
anything again.

## When something is wrong

Ask the access point what it would answer *right now* — that is the question
behind most iPSK problems, and it needs no client:

```sh
lua /tmp/radius-probe.lua "$(uci -q get apman.main.radius_secret)" \
    127.0.0.1 1812 <sta-mac-hex> <bssid-hex> <ssid> [sae|psk]
```

(`contrib/radius-probe.lua`, copy it to the access point.) It prints every
`Tunnel-Password` of the answer, decrypted, in the order hostapd will try them.

The agent logs one line per decision:

```
radius accept 8a:fd:f0:19:ba:a2 key=ppsk_156_540 bss=9e:9d:7e:75:ba:33 ssid=apman-tsae
radius reject aa:bb:cc:dd:ee:ff … (no key for this station)
```

Common causes, in the order they actually occur:

| symptom | look at |
|---|---|
| every station rejected on one SSID | the BSSID→section map: an unresolvable BSSID means no bucket, and no bucket means reject. It is rebuilt on the agent's periodic tick and on hostapd reload events |
| a key does not work although it was distributed | ask the agent for its versions: an rpc `{"method":"call","params":["","apman","keys_status",{}]}` on `apman/ap/<host>/command` — does the version match what the controller sent? |
| a withdrawn key still works | see the ordering above; measure how long ago the last `radius accept` for that MAC was |
| keys go stale and nothing updates | the key store is only reloaded from disk on the tick; `apply_keys` loads it immediately. Check the agent log for `radius keys reloaded` |
| the identity of a station is unknown | for SAE, only the `radius/auth` event carries it — is the subscriber consuming `apman/ap/+/radius/auth/#`? |

The agent currently logs the delivered passphrase in clear
(`radius-debug psk=…`). That is deliberate for now; it must go before the log
leaves the site.

## Networks that still use the file path

Not every PPSK network is an iPSK network. Where the keys live in
`wifi-station` sections and there is no on-AP RADIUS,
`PpskService::distribute()` still writes the runtime `wpa_psk_file` and calls
`RELOAD_WPA_PSK`. Worth knowing about that path:

- `RELOAD_WPA_PSK` re-reads the file and drops **only** the stations whose key
  no longer matches. Adding a key disturbs nobody; withdrawing one hits exactly
  the device it belonged to. No hostapd patch is or was needed for this.
- The uci commit is done with the `uci` command line rather than over ubus, on
  purpose: committing through ubus raises a config change event, netifd reloads
  the wireless config and regenerates every psk file from uci — without the
  keyids, which uci cannot store.
- Which is also why the distribution reads the runtime file back before
  reloading: after a wifi reload the file has been regenerated from uci and has
  lost every keyid, and a remembered hash would never notice.
- **The file has to be group readable.** hostapd runs as user `network` inside
  a ujail; a file it cannot open is reported as *"WPA PSK file not found"* and
  `RELOAD_WPA_PSK` answers `FAIL`. The distribution hands the group over with
  `chown root:network`, but the mode passed to the write does not survive the
  move into place — the file ended up `0600` and every reload failed until an
  explicit `chmod 640` was added (measured 2026-08-21; the failure had been
  invisible while the patched `ap.uc` meant there was no file to reload at
  all).
- SAE has no equivalent. `sae_password_file` is only parsed when hostapd reads
  its whole configuration, and that throws every client of the radio off. This
  is the limitation iPSK removes, and the reason a hostapd patch for a
  `RELOAD_SAE` command was once planned and is now unnecessary.

## Where the code is

| what | where |
|---|---|
| key set, distribution, binding, revocation | `src/Service/PpskService.php` |
| the marker written into the wireless config | `src/Service/IpskFeatureService.php` |
| skipping the station sections, per-AP secret | `src/Service/AccessPointService.php` |
| the accept coming home | `src/Service/SubscriptionService.php::handleRadiusAuthEvent()` |
| the controller's own RADIUS server (external auth_server case) | `src/Service/RadiusAuthService.php` |
| migration | `src/Command/IpskMigrateCommand.php` |
| the agent: RADIUS server and key store | `apman/files/usr/lib/lua/apman-radius.lua` (ddimension-openwrt-repo) |
| the agent: `apman keys` command, ctrl allowlist | `apman/files/usr/lib/lua/apman.lua` |
| the wire format of the command channel | `apman/docs/controller-api.md` |
