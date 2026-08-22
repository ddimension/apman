# What hostapd does with a RADIUS Access-Accept

Written 2026-08-17, after asking whether the keyid could travel in the RADIUS
answer. The short answer is no, and finding out why turned up several things
worth keeping.

Everything here was read out of the source our own access points run —
`hostapd-2025.08.26~ca266cc2`, as built for `openwrt_mediatek-filogic`. Line
numbers refer to that tree. It is a build directory and it will be gone one
day; the point of the quotes is that the claims can be checked against whatever
version is current, not that the numbers stay right.

Scope is the MAC-ACL path we use: `macaddr_acl=2` together with
`wpa_psk_radius=2`, which OpenWrt writes when a wifi-iface carries the `ppsk`
option. The access point asks once per association attempt, before the
handshake, and the answer decides what key the station may use.

## The six things hostapd reads from an Accept

All of it in `src/ap/ieee802_11_auth.c:535-590`. Nothing else in the packet has
any effect.

| Attribute | What hostapd does with it |
|---|---|
| `Session-Timeout` | station must authenticate again after n seconds |
| `Acct-Interim-Interval` | accounting interval; under 60 it is discarded with a log line |
| `Tunnel-Type` / `Tunnel-Medium-Type` / `Tunnel-Private-Group-Id` | the **untagged** VLAN — see below |
| `Egress-VLANID` (RFC 4675) | **tagged** VLANs, and an alternative way to say untagged |
| `Tunnel-Password` (all of them) | the keys the station may use |
| `User-Name` | kept as `sta->identity` |
| `Chargeable-User-Identity` | kept as `sta->radius_cui` |

One rule that is easy to miss: with `wpa_psk_radius == PSK_RADIUS_REQUIRED`, an
Accept that carries no `Tunnel-Password` is turned into a **reject**
(`ieee802_11_auth.c:580`). An empty Accept is not a permissive answer.

## The keyid cannot travel in the answer

`decode_tunnel_passwords()` (`ieee802_11_auth.c:409`) walks the
`Tunnel-Password` attributes by index and appends each to a list:

```c
struct hostapd_sta_wpa_psk_short {
	struct hostapd_sta_wpa_psk_short *next;
	unsigned int is_passphrase:1;
	u8 psk[PMK_LEN];
	char passphrase[MAX_PASSPHRASE_LEN + 1];
	int ref;
};
```
`src/ap/ap_config.h:156`

There is no field for a name, and no code anywhere associates a
`Tunnel-Password` with another attribute of the same packet — they are read
positionally and nothing else about them is remembered.

The `keyid=` that shows up in `AP-STA-CONNECTED` comes from somewhere else
entirely. `ap_sta_wpa_get_keyid()` (`src/ap/sta_info.c:1516`) takes the
negotiated PMK and looks for it in `hapd->conf->ssid.wpa_psk` — the list built
from **`wpa_psk_file`**:

```c
for (psk = ssid->wpa_psk; psk; psk = psk->next)
	if (os_memcmp(pmk, psk->psk, PMK_LEN) == 0)
		break;
if (!psk || !psk->keyid[0])
	return NULL;
```

The keys that came over RADIUS live in `sta->psk`, a different list of a
different type, which this function never looks at. So a key delivered by
RADIUS has no keyid by construction, and an SAE network — which can only get
per-device keys over RADIUS — reports no identity at all.

Measured on 2026-08-17: of 27 kalnet stations (WPA2, reads the psk file) 13 had
both `keyid` and `identity` in the access point's event. Of the kalclients
stations (SAE) none did — while our own `radius_auth` held 28 answers with a
keyid for four of their MACs. We know which key was used; the access point does
not say it.

### Three ways to close that

1. **Join it ourselves.** With SAE hostapd uses only the *first*
   `Tunnel-Password`, so the key we put first is provably the one in use — the
   newest `radius_auth` row for a MAC is the identity. Costs nothing and needs
   no change on the access points.
2. **Send accounting to ourselves.** `User-Name` and `Chargeable-User-Identity`
   are kept per station and travel into the accounting requests, so the keyid
   would come back on its own. No patch, but it collides with leaving
   accounting where it is.
3. **Patch hostapd.** A `keyid` field in `hostapd_sta_wpa_psk_short`, filled
   from `User-Name` or an attribute of our own, and `ap_sta_wpa_get_keyid()`
   extended to fall back to `sta->psk`. Around twenty lines, and afterwards
   every existing consumer of `keyid=` works unchanged for SAE too.

## VLANs: one untagged and up to 32 tagged

```c
#define MAX_NUM_TAGGED_VLAN 32

struct vlan_description {
	int notempty;
	int untagged;                      /* >0 802.1q vid */
	int tagged[MAX_NUM_TAGGED_VLAN];   /* first k items, ascending order */
};
```
`src/ap/vlan.h:12`

They come from **different attributes**, which is the part that surprises.

**Untagged comes from the Tunnel-\* triplet.** `radius_msg_get_vlanid()`
(`src/radius/radius.c:1706`) parses up to `RADIUS_TUNNEL_TAGS` (32) tagged
triplets, and then:

```c
/* Use tunnel with the lowest tag for untagged VLAN id */
```
`radius.c:1778` — it takes the first valid one and breaks. Sending more
Tunnel-\* triplets achieves nothing.

**Tagged comes from `Egress-VLANID`** (`radius.c:1763`), a four octet value
whose first octet decides:

| first octet | meaning |
|---|---|
| `0x31` | tagged — appended until 32 are full |
| `0x32` | untagged |

Order of evaluation matters: an `Egress-VLANID` of `0x32` sets the untagged id
during the attribute walk, and the Tunnel-\* loop runs *afterwards* and
overwrites it. **Tunnel-\* wins over Egress-untagged.** The tagged list is
sorted ascending at the end, so the order they are sent in does not matter.

Two conditions, or none of it has any effect:

- `dynamic_vlan` must not be `DYNAMIC_VLAN_DISABLED`, or the VLAN attributes
  are never even parsed (`ieee802_11_auth.c:558`).
- Every id must be known to the access point. `hostapd_vlan_valid()`
  (`ieee802_11_auth.c:585`) checks against `hapd->conf->vlan`, and one unknown
  id makes the whole assignment fail with *"Invalid VLAN %d received from
  RADIUS server"*. For us that is the `wifi-vlan` sections — ap-av-attic
  already runs four AP/VLAN interfaces from them (vid 22 "opennet", vid 26
  "privacy").

We send Tunnel-\* only, so today exactly one untagged VLAN. `Egress-VLANID`
appears nowhere in `RadiusAuthService`, and the tagged capability is unused. It
would become interesting for a station that carries several networks behind
itself — an access point or a host with tagged guests — not for an ordinary
client.

## Session-Timeout is a lever we do not pull

Withdrawing a key currently takes effect when `PpskService::deauthenticate()`
sends `del_client` over ubus. A `Session-Timeout` in the answer would give
every station a lease instead: after at most n seconds the access point asks
again by itself, without anyone being thrown off, and with a guaranteed upper
bound on how long a withdrawn key keeps working. It costs one attribute and
some thought about the interval — short enough to matter, long enough not to
turn into a request storm.

## Where this is implemented on our side

`src/Service/RadiusAuthService.php` builds the answer. Today it sends
`Tunnel-Password` per candidate (network passphrase first, MAC-specific last,
because hostapd prepends — `decode_tunnel_passwords()` does
`psk->next = cache->info.psk`), a `Reply-Message`, and the Tunnel-\* triplet
when a `vid` is set. `src/Radius/ApmanTunnelPasswordHandler.php` does the RFC
2868 encryption including the length octet the library forgets.

## What `duration_ms` measures — and what it hides

`radius_auth.duration_ms`, the "Answer" column on the RADIUS page, is the time
the access point's own server spent **deciding**. The clock starts in the
agent's `radius.handle()`, which is where the packet is taken out of the
socket. It ends when the answer is written.

That leaves out the part a station actually feels. If the agent is busy
somewhere else when the request arrives — its status cycle used to run
blocking, 677 ms out of every ten seconds on an access point with eleven
bsses — the request sits in the kernel's receive buffer and the clock has not
started yet. The wait is real, the station may give up over it, and the number
on the page stays at one and a half milliseconds.

Measured 2026-08-22, before and after the agent's status cycle was rebuilt to
stop blocking:

```
before (03:00-07:00)   530 requests   0.59 ms mean   1.86 ms max   0% over 10 ms
after  (from 08:00)    343 requests   0.64 ms mean   2.32 ms max   0% over 10 ms
```

Identical, across a change that removed a blockade of two thirds of a second
recurring every ten seconds. The metric is blind to it by construction.

**So do not read this column as "stations get in quickly".** It answers a
narrower question: once the server looks at a request, does it answer fast.
It has never not, and it is still worth watching for the day it stops.

### What to measure instead

Round-trip time of a cheap ubus command sent **from the controller**, because
that includes the waiting. Space the samples at an interval coprime to the
status cycle — 1.1 s against a 10 s cycle — or every sample lands in the same
phase and measures the same moment over and over.

The numbers that move are the tail, not the median: p90, p99, and the share
above 300 ms, which is roughly where a station that retries three times gives
up. First measurement of the rebuilt agent, 150 samples each, `system board`
over `call`:

```
                 min   median    p90    p99    max   over 300 ms
ap-av-grwz      41 ms   127 ms  220 ms  240 ms  437 ms   0.7 %
ap-av-attic     16 ms   123 ms  213 ms  241 ms  242 ms   0.0 %
```

ap-av-grwz has three radios, eleven bsses and 6 GHz on DFS; ap-av-attic has two
radios and eleven bsses and is the quiet one. Read them together, because apart
they mislead:

- **The median is the path, not the agent.** 123 against 127 ms across two very
  differently loaded devices is the broker round trip and the QoS 1 handshakes,
  and it will not move whatever the agent does. Nobody should celebrate it
  falling or worry about it rising by ten milliseconds.
- **The tail is the agent.** Both flatten at a p99 near 240 ms — and then attic
  stops at 242 ms while grwz reaches 437 ms. That gap, not the median, is where
  a blocked agent shows up.
- **0.7 % is one sample out of 150.** It is a baseline, not a rate. Anyone
  comparing against it should take more samples before calling a difference
  real.

All of it includes the broker and the network. It is a number to compare
against, not a measurement of the agent alone.
