# What we could switch on, and what it would cost

Written 2026-08-22, after reading the configuration that is actually running on
the fleet against the two schemas in `config/wireless/`. Nothing here is set by
this document. It says what is reachable, what it is for, and what goes wrong —
so that switching something on is a decision somebody made rather than a thing
that happened.

Everything was measured on OpenWrt 25.12.5 (r33051-f5dae5ece4), seven access
points, `wifi-iface.json` (277 options) and `wifi-device.json` (152).

## The three ways in

| | where | reaches hostapd via |
|---|---|---|
| a bss option | network page, or `Device::$config` for one bss | `ap.uc` renders it |
| a radio option | `Radio::$config`, the "further options" box in the admin | `ap.uc` renders it |
| a raw line | `hostapd_bss_options` (bss), `hostapd_options` (radio) | pasted in verbatim |

Prefer an option over a raw line wherever the schema has one. A raw line is
invisible to the editor, to `WlanConsistencyService` and to the next person, and
`ap.uc` writes its own value for the same setting anyway — the generated file
then carries both and hostapd takes the last. Every bss of ap-av-attic held
`bss_load_update_period=60` and `bss_load_update_period=50`, one under the
other, until this was cleaned up.

**`custom_cfg` is not one of the three.** It is not a uci option and appears in
neither schema, so `ap.uc` does not know it and none of it reaches an access
point. See the commit that says so.

## Where the fleet stands

Measured, not assumed:

```
ap-av-attic    phy1 5 GHz ch100  6 bss   he_bss_color=128
               phy2 2.4 GHz ch1  5 bss   he_bss_color=128
ap-outdoor     phy1 5 GHz ch116  6 bss   he_bss_color=128
               phy2 2.4 GHz      5 bss   he_bss_color=128
ap-outdoor2    phy0 2.4 GHz ch7  5 bss   he_bss_color=128
               phy1 5 GHz ch116  5 bss   he_bss_color=128
```

Two things fall out of that table.

**Every radio in the fleet uses colour 128.** It is the schema default and the
top of the 1..128 range, so it is not a choice anybody made — `ap.uc` writes the
default and nothing here overrides it.

**ap-outdoor and ap-outdoor2 are both on channel 116, both with colour 128.**
BSS colour exists so a receiver can tell overlapping networks on one channel
apart and decide it may transmit anyway. Two co-channel access points sharing a
colour is the one case the mechanism cannot handle: it is a collision, and
spatial reuse is off for both of them.

## Worth switching on

### `he_bss_color`, `he_bss_color_enabled` — radio

Give each radio a colour of its own. Costs nothing, needs no client support to
be safe (a client that does not understand it ignores it), and without it the
two access points above are invisible to each other's spatial reuse.

Assign per access point, not per network: the colour belongs to the radio.
Neighbouring radios on the same channel must differ; radios on different
channels may repeat.

What goes wrong: a colour collision announces itself as a "BSS color collision"
event and hostapd will change colour on its own if `he_bss_color_enabled` is
set — so the failure mode is noise, not an outage.

### `he_spr_psr_enabled`, `he_spr_non_srg_obss_pd_max_offset` — radio

Spatial reuse proper: a station may transmit while it hears another BSS, as long
as that BSS is weak enough. The tool for exactly the situation above — several
access points on one channel with `txpower: 27` on both bands.

Start conservative. An OBSS-PD threshold set too aggressively turns collisions
into retransmissions, which costs more airtime than it saves. Change one radio,
measure, then the next.

### `mbssid` — radio

The largest airtime win available here. Five or six bsses per radio each send
their own beacons today; MBSSID transmits one beacon set for the whole group.
On a radio with six bsses that is most of the beacon overhead gone.

The reason it is not simply "yes": it depends on the driver, and the fleet runs
two of them (ath11k on qualcommax, mt76 on mediatek). Try it on one radio of
one access point and look at whether every bss still appears in a scan, on a
phone and on a laptop, before it goes anywhere else. `rnr` belongs with it.

### `ocv` — bss

Operating Channel Validation. Ties the handshake to the channel it happens on,
which shuts a class of multi-channel man-in-the-middle attacks. Needs PMF, which
every SAE and OWE network here already requires.

Cheap to try, and old clients that do not know it negotiate it away rather than
failing — but that is the thing to verify on a network with unknown devices
before it goes fleet-wide.

### `beacon_prot` — bss

Signs beacons so a station notices a forged one. Also PMF-based. Same shape of
risk as `ocv`: a client that does not know it should ignore it, and the ones
that get it wrong are old.

### `wnm_sleep_mode_no_keys` — bss

A workaround for clients that lose their keys coming out of WNM sleep. We
already set `wnm_sleep_mode`, so the affected clients are ours to have. Worth
knowing about the day somebody reports a device that drops after idling.

### `qos_map_set` — bss

DSCP to user-priority mapping. The schema ships a sensible default, and without
it a client's marked traffic is not necessarily in the queue it asked for.

### `airtime_mode` (radio), `airtime_bss_weight` (bss)

Fair airtime between the bsses of one radio. With five or six networks on a
radio and one of them carrying everything, this is what stops the busy one from
taking the others' share.

### `rssi_ignore_probe_request`, `rssi_reject_assoc_rssi` — radio

Turn away a station that is too far off at the door, instead of admitting it and
steering it away afterwards. Complements `SteeringService` at the root rather
than duplicating it.

Set it too high and a station in a corner cannot connect at all, and it will not
tell you why. Start well below what you think the edge is.

### `max_num_sta`, `no_probe_resp_if_max_sta` — bss / radio

A load ceiling per bss, said out loud rather than found out.

## Only through the raw passthrough

Not in either schema; `hostapd` knows them, so `hostapd_bss_options` is the way:

- **`transition_disable`** — tells a client that has once connected with WPA3
  never to accept WPA2 for this network again. The end of the downgrade window,
  and only sensible once every client can do WPA3.
- **`sae_track_password`** — tracks SAE password use.
- **`oce`** — already set here, as a raw line, correctly.

## What must not be set

**`sae_pwe`, on any network whose keys come from RADIUS.** `ap.uc` skips its own
default as soon as `ppsk` is set, and that is load bearing: a password delivered
in an Access-Accept carries no SAE PT, so hash-to-element cannot be derived from
it. Advertise H2E and a station that commits with it is answered with status 1.

Measured 2026-08-21 on kalclients: the station sent `status=126
(SAE_HASH_TO_ELEMENT)`, hostapd refused, and the fleet's clients could not come
back. There is a unit test for it now — `testNeverSetsSaePwe`.

The corollary is the finding the consistency check reports today: iPSK and SAE
cannot work on 6 GHz at all, because 6 GHz permits nothing but H2E. `wap-kc2` on
ap-av-grwz beacons on 6055 MHz and has never had a station.

## How to try one of these

1. One option, one radio or one network, on **ap-av-attic** — two radios, few
   clients, and the access point everything else has been tried on.
2. `apman:show-ap-config <ap>` first: it renders the payload without sending it.
3. `apman:config-ap <ap>` applies it, with rpcd's rollback timer armed.
4. Read the running configuration back and compare — `WlanConsistencyService`
   fetches it, or `file exec` with `cat /var/run/hostapd-phy*.conf`.
5. Then look at whether clients are still there. A setting that renders
   correctly and empties a bss is worse than one that does not render.
