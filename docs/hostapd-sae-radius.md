# SAE with a RADIUS password: the sae_pwe rule now depends on the build

Written 2026-08-28. This is the document that retires a rule two other documents
state as absolute — [ipsk.md](ipsk.md) ("**Do not put `sae_pwe` back**") and
[ipsk-test.md](ipsk-test.md) ("`sae_pwe` on an iPSK network takes the whole
network down, immediately"). Both are still right for the hostapd our access
points run today. Neither is right for the one in the feed.

The patches live in <https://github.com/ddimension/hostapd>, branch
`sae-radius-h2e`, and are shipped as `wpad-saeradh2e` in the openwrt-repo feed.
They are written for the hostap mailing list, so the intent is that they
disappear again into a future version bump.

## What was actually wrong

The diagnosis in ipsk.md is correct and worth keeping: a password that arrives
in a `Tunnel-Password` has no SAE PT, and OpenWrt's own PT derivation for
`sta->psk` passwords sits behind `sta->use_sta_psk`, which only the ucode
`sta_auth` hook sets and the RADIUS ACL path never reaches
(`601-ucode_support.patch`, `sae_get_password()`).

What that document could not see is that upstream has the same hole one level
up. `auth_build_sae_commit()` does derive a PT on the fly when none is
precomputed, but only for a password that belongs to a `struct
sae_password_entry`:

```c
if (!pt && pw) {          /* pw is NULL for a RADIUS password */
        tmp_pt = sae_derive_pt(groups, ssid, ssid_len,
                               (const u8 *) pw->password, ...);
}
if (!pt) {
        wpa_printf(MSG_DEBUG, "SAE: No PT available");
        return NULL;
}
```

`sae_get_password()` returns the passphrase from `sta->psk` but leaves both the
entry pointer and the PT at NULL, so the exchange falls through to the error
return. The fix is five lines: derive from the returned password string instead
of from the entry, which is a strict generalisation — when an entry exists,
`password` points at `entry->password` and the derived PT is identical.

**Read the log line, it distinguishes two different faults.** This matters when
diagnosing, and the two are easy to confuse:

| Line on the access point | Meaning |
|---|---|
| `SAE: No PT available` | password was found, H2E has no PT — the bug above |
| `SAE: No password available` | no usable password at all — Accept carried no `Tunnel-Password`, or the entry was consumed (see below) |

ipsk.md quotes the second for the 2026-08-21 outage. If that quote is accurate
rather than a paraphrase, a second bug was in play as well, and it is also
fixed — see *The shared list* below.

## What the patched build changes for the controller

**`sae_pwe` becomes safe, and on 6 GHz becomes necessary.** H2E works with a
RADIUS-delivered password. The corollary in ipsk.md — "iPSK plus SAE cannot
work on 6 GHz at all" — no longer holds for this build; 6 GHz permits nothing
but H2E, so it was exactly the missing piece.

**The three values, because they are easy to get backwards.** From
`hostapd.conf`: `0` is hunting-and-pecking only, `1` is hash-to-element
**only**, `2` is **both**. So `2` is the permissive value and `1` the
restrictive one. `WlanConsistencyService` claimed the opposite until
2026-08-29 and reported it confidently; the rule is corrected and the tests
now pin the mapping.

This is also the honest reading of the 2026-08-21 outage. `sae_pwe=2` does not
force H2E — it advertises it, and a station that can do H2E then chooses it.
Without a PT that station is refused with status 126 while hunting-and-pecking
is, in principle, still on offer. What fell off was exactly the capable half of
the fleet, not everything.

One consequence worth carrying: a station that uses an SAE Password Identifier
gets H2E **regardless of `sae_pwe`** — `hostapd.conf` says so outright — which
now matters, because `sae_password_radius=1` is on.

**But `ap.uc` still suppresses it.** The patches are in hostapd; the
`if (!config.ppsk)` guard is in `wifi-scripts`, a different package that has not
been touched. So on an access point running `wpad-saeradh2e` nothing changes by
itself — the guard keeps `sae_pwe` out of the generated config and hostapd falls
back to its default of 0, hunting and pecking. To actually get H2E, `sae_pwe`
has to be set explicitly on the wifi-iface; `set_default` in ap.uc does not
overwrite a value that is already there.

That gives a safe rollout order, and it should be walked in this order:

1. ship `wpad-saeradh2e`, change nothing else — behaviour is identical to today
2. set `sae_pwe=2` on **one** access point's iPSK network and confirm SAE
   clients still associate
3. only then consider it fleet-wide, and only then 6 GHz

The verification line in ipsk-test.md inverts for a patched access point:
`grep -hc '^sae_pwe=' /var/run/hostapd-phy*.conf` must stay **0** on stock
access points and becomes the thing you *want* to see non-zero on patched ones.
Until the fleet is homogeneous, that check has to know which build it is
looking at. `wpad-saeradh2e` is a distinct package name precisely so this is
answerable with `apk info -e wpad-saeradh2e`.

## The shared list

Second patch, independent of SAE, and a real bug in the path we use every day.

`hostapd_copy_psk_list()` takes a reference rather than copying, and
`hostapd_acl_cache_get()` copies `struct radius_sta` by value — so the
`hostapd_sta_wpa_psk_short` entries behind `sta->psk` are the *same objects*
that sit in the RADIUS ACL cache for 30 seconds
(`RADIUS_ACL_TIMEOUT`, `ieee802_11_auth.c:29`).

`hostapd_wpa_auth_get_psk()` converted the passphrase to a PSK in place and then
cleared `is_passphrase` so the next handshake would skip pbkdf2. That write goes
straight into the cached entry. `sae_get_password()` only uses a RADIUS password
**if `is_passphrase` is set** — so once a station had completed a WPA2 four-way
handshake, SAE for that same station failed with `SAE: No password available`
until the cache entry aged out.

Reachable on any `psk-sae` iPSK network where a station connects over WPA2 and
then tries SAE within 30 seconds — a band steer, a roam, a client that retries
with a different AKM. The fix records the derived PSK in a separate `psk_set`
bit and leaves `is_passphrase` alone. There is an hwsim regression test for it
(`radius_psk_sae_same_sta`).

## Password identifiers over RADIUS

New, optional, off by default: `sae_password_radius=1`. When a station sends an
SAE Password Identifier that no local `sae_password` matches, hostapd suspends
the Commit, asks RADIUS for that identifier and resumes when the answer arrives.

The identifier travels as the **`User-Name`** of the Access-Request — and, like
the MAC in the `macaddr_acl=2` case, also as the `User-Password`. The station
stays identified by `Calling-Station-Id`. No new attribute and no vendor space
was invented, which was a deliberate constraint: hostap has no PEN.

This is a different shape of question than what the controller asks today.
Present iPSK asks *"what may this MAC use"*; this asks *"what is the password
called X"*, which is the case a Password Identifier exists for — onboarding a
station whose MAC is not known in advance. If the controller's RADIUS server
grows that, it has to answer requests whose `User-Name` is an identifier rather
than a MAC, and it must not confuse the two.

Enabling it does **not** force the BSS to H2E. `hostapd_sae_pw_id_in_use()`
reports identifiers as in use but not exclusively so, which leaves hunting and
pecking available for stations that do not use one. That was a deliberate
decision; the first cut restricted the BSS and would have excluded older
clients.

## Which key was used, on the access point

[radius-access-accept.md](radius-access-accept.md) established that the keyid
cannot travel in the Accept, and that stands — nothing associates a
`Tunnel-Password` with any other attribute. But that document also lists
`User-Name` → `sta->identity`, and that value was simply never reported
anywhere.

It is now, in both the STA info and the `AP-STA-CONNECTED` event:

```
AP-STA-CONNECTED 8c:fd:… identity="alice" sae_password_id="guest-week33"
```

`sae_password_id` comes from `sta->sae_pw_id`, so it covers locally configured
identifiers as well as ones fetched from RADIUS. Both values are attacker- or
server-controlled, so both are `printf_encode()`d, and both are **quoted** in
the event because an identifier may legitimately contain spaces (hostapd.conf's
own example is `id=pw identifier`). Any parser that consumes AP-STA-CONNECTED
has to handle the quoting; the line-oriented STA info is unquoted.

For the controller this is a second, local channel for something it already
learns centrally through `radius_auth` and `PpskService::recordUsed()`. Its
value is diagnostic: it answers "which credential did this station use" on the
access point itself, without correlating against the RADIUS server.

## State and open ends

| Item | Where |
|---|---|
| patch series, 11 commits | `ddimension/hostapd`, branch `sae-radius-h2e` |
| OpenWrt package | `ddimension/openwrt-repo`, `wpad-saeradh2e` (commit `f882e7b`) |
| upstream submission | not sent — `hostap@lists.infradead.org` |

Verified: 115 hwsim SAE tests and the RADIUS module pass with no regression
against an unpatched baseline, the six OpenWrt patches apply cleanly onto
`831364bf0` with the src overlay, and `wpad-saeradh2e` builds in the official
`openwrt/sdk:x86_64` snapshot container (`scripts/local-build.sh`). The build
resolves `hostapd-common` and the `CONFIG_WPA_*` symbols out of the SDK's
`base` feed, and comes out with `CONFIG_DRIVER_11AC/11AX/11BE_SUPPORT` and
`CONFIG_WPA_MBO_SUPPORT` set, so it is not a feature-stripped package.

Two things a reader should not assume:

- **Nothing here has run on a real access point.** Everything is hwsim, source
  reading and an SDK build. `ipsk-test.md` is the procedure that would establish
  it, and the `sae_pwe` step there is the one that matters.
- **PT derivation is not cached on the RADIUS ACL path.** OpenWrt's
  `use_sta_psk` path stores the PT on `sta->sae_pt` and reuses it; ours derives
  per Commit frame. That is an elliptic curve operation per authentication
  attempt on hardware that is not fast. It has not been measured, and a station
  that retries SAE repeats it each time. If H2E is enabled fleet-wide, this is
  the thing to watch.
