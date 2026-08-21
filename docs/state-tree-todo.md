# State tree: what is left to move over

The tree — access point → radio → bss — is in place and read-only. This is the
list of everything that could stop deciding for itself and ask it instead,
written down on 2026-08-21 while it was still fresh.

## Where it stands

Done and deployed:

- `StateTreeService` composes the three levels and writes the composed state
  once per status message; `NodeState` holds the three vocabularies.
- The housekeeping tick re-composes every access point, so a node decays when
  its access point falls silent instead of keeping its last state for a week.
- `apman:state` prints the tree, `apman:monitor` (the nagios check) judges by
  it, Sonata shows a state on `AccessPoint`, `Radio` and `Device` — the lower
  two never had one — and `/aps` and `/ap/<name>` show it.
- The flat `AccessPointState` still runs alongside and is still the truth for
  the activation path.

## 1. Three answers to the same question

These do not need the tree so much as they need to stop hand-rolling it.

**`ClientCommandService.php:110–120`** keeps its own 120 second window and a
comment explaining why the online marker cannot be trusted. The reasoning is
right and the place is wrong: it is `bss(...)['fresh']`.

**`AccessPointService::isLive()` (`:1512`)** — a 300 second window on
`received`, called from the activation block (`:1371`) and
`assignAllNeighbors()` (`:1563`). Both become `BSS_READY` / `BSS_ACTIVE` and the
method goes.

**`PpskService` `:548`, `:661`, `:1125`** read a bss's station list and skip
when there is none, which cannot tell "this bss is down" from "no data yet".
The one at `:661` is the blind sweep a revocation falls back on: today it can
aim a kick at a bss that is not running, and treats a missing entry like an
empty one. Skip `ABSENT`/`UNKNOWN`, sweep `READY`/`ACTIVE`.

## 2. Gates that do not exist yet

**`PpskService::distribute()`** is where the keys were lost on 2026-08-20 at
21:50 local: it found the access points' uci stations differing from the
database and rewrote them, and the database no longer had the real per device
keys. Two preconditions belong here — do not write to a bss that is not
`READY`, and do not silently replace a per device key with the network
passphrase. The second rule already existed in the RADIUS import that has since
been deleted; the distribution path never had it.

**`AccessPointService::publishConfig()` / `applyConfig()`** — provisioning an
access point that is `OFFLINE` or `UNKNOWN` burns the rollback window for
nothing, and `CAC` is a bad moment for a different reason. A precondition on the
access point node turns "no answer from the access point" into "the access
point is offline", which is a different sentence for whoever reads it.

**`SteeringService`** — steering a client to a bss that is not `READY` fails by
construction. A precondition on the target node.

**`WlanConsistencyService`** — it compares what was configured against what
runs, and a bss that is `ABSENT` or `DISABLED` produces findings that are not
drift. Skipping by node state cuts the false positives.

## 3. The status pages

Two notions of age live side by side today. The pages compute their own from
`received` per device; the tree keeps `seen` per node. They can disagree, and
when they do neither is obviously right.

- **`/aps`** (`DefaultController::apsAction`, `:2035` and the row it builds) —
  shows the flat state, the online marker and a per device age. The tree column
  is there; the rest of the row can follow, and `online` becomes a fact of the
  access point node rather than a second opinion.
- **`/ap/<name>`** (`:334`) — `age` per device, computed inline. The tree block
  above it already says the same thing better; the device headings should use
  the node's `seen` so the page cannot contradict itself.
- **`/grid`** (`templates/default/grid.html.twig:210`, `:255`) — colours a cell
  stale past 60 seconds from its own `age`. Same source, same fix.
- **`/ssid/<id>`** — lists every access point and interface carrying the
  network. This is where a bss state would say the most: a network that is
  `ABSENT` on one access point and `READY` on the others is exactly the failure
  that took all evening to find, and the page that should have shown it.
- **`/consistency`** — see the gate above: absent and disabled bsses should not
  appear as drift.
- **`StatusService.php:226`** — the same `received` arithmetic, one layer down.

## 4. The stages that were planned

- **Activation on the event.** `bss: * → READY` enables management for that one
  interface instead of a fleet-wide sweep on an access point transition. The
  band ordering that sits there today is not band steering — `start_disabled`
  never reaches hostapd (`ap.uc:540` overwrites it), so every bss beacons as
  soon as it is up. Steering belongs with BTM and neighbour reports, separately.
- **Neighbours per bss** instead of `assignAllNeighbors()` on every access point
  transition — twelve fleet-wide runs in six hours, measured.
- **Escalation** on `radio: → FAILED` and `ap: → OFFLINE`, once, with `since`.
  `lifetimeHouseKeeping()` counts and logs a line today and does nothing;
  `ap-hv-klwz` was offline for a whole day without anything noticing.
- **Retire the flat state**: `AccessPointState`, `changeLifetimeState()` and the
  state part of `lifetimeMessageHandler()`.

## Order I would take it in

1. `ClientCommandService` and `isLive()` — three definitions of "fresh" become
   one, no behaviour to get wrong.
2. The two preconditions in `distribute()` — this is the outage of 2026-08-20,
   and the rule against it already existed elsewhere.
3. The `publishConfig()` precondition — saves the pointless attempts and makes
   the message honest.
4. `/ssid/<id>` with a bss state — the page that would have shown the outage.
5. Everything else.
