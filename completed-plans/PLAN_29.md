# PLAN_29 — PLAN_7 remnants: say what the scaling block really does

**Status: COMPLETE**, all three phases, deployed to the test box on 2026-08-17. This closes
`PLAN_7.md` section 3, and so `PLAN_7.md` entirely. Two things measured on the box while building,
worth keeping: compose 2.40.3 refuses `container_name` together with `replicas: 3` outright —
`docker compose config -q` exits 1 with *"can't set container_name and web as container name must be
unique"* — so the new warning states a fact rather than a guess. And the ⓘ descriptions behind those
five dead keys already said "(Docker Swarm only)"; the work was making that visible without a click.
Fixture stack `13-swarm-notes` on the test box holds every case in one file and is deliberately
unsaveable, like `08-deliberately-broken`.

Phase 3 turned out narrower than feared: `LEAVES` keeps precedence, so only rows the form had no
title for at all moved — 10 under `deploy:` and 15 under `build:`, nothing under `healthcheck:` or
`logging:`. One test asserted the old "Replicas" label and was updated to "Number of copies".

## Context

`PLAN_7.md` is complete except for its section 3. Two honesty problems remain in the `deploy:`
block, and both were confirmed against the code today.

**1. Settings that do nothing are presented as if they work.** Docker's `deploy:` block was built
for a swarm cluster. Measured on the test box with `docker compose up -d --dry-run` (recorded in
`PLAN_7.md:110-118`): `resources.*`, `replicas` and `restart_policy` are honoured on a single
machine; `placement`, `update_config`, `rollback_config`, `endpoint_mode` and `mode` are not.
Today `mode` and `endpoint_mode` render as **ordinary editable text boxes** in Advanced
(`compose-model.js:1197-1205` → `settingTarget`, scalar branch), and the three map-shaped ones
render as locked read-only blocks whose stated reason is *"this is written as a block of its own"* —
true, but not the reason that matters.

**2. A combination Docker refuses is not flagged.** A service may not have both `container_name`
and `deploy.replicas` above 1. The form offers both freely; the failure surfaces only on save.

**Decisions taken (2026-08-17):** warn in both cases, lock nothing. The dead settings stay
editable, because a file may legitimately be authored here for a cluster elsewhere. The clash warns
on both rows and locks neither, because clearing one of them *is* the fix and a disabled box cannot
be cleared.

That makes this a text-and-annotation change. **The existing `blocked` flag is not extended, and
`stacks.js` needs no change at all** — `adviceBlock()` is already emitted on both the locked branch
(`stacks.js:2097`) and the editable branch (`stacks.js:2249`), and refreshed in place by
`refreshRanges()` (`stacks.js:2844-2848`).

Everything below is in
`src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js`.

---

## Phase 1 — "Docker ignores this here" on the five swarm-only keys

Add one small table beside the other `deploy` tables (`LEAVES.deploy` is at `:656-661`,
`DEPLOY_LEAVES` at `:4308-4318`), keyed by full target name so it cannot collide with another
block's child:

```js
// Measured on compose 2.40.3 outside a swarm: these five are parsed and then
// ignored, while resources, replicas and restart_policy are honoured. A box that
// looks like it works and does nothing is the failure this form exists to avoid.
var SWARM_ONLY = { 'deploy.placement': 1, 'deploy.update_config': 1,
                   'deploy.rollback_config': 1, 'deploy.endpoint_mode': 1, 'deploy.mode': 1 };
```

Hook it into `harvestBlock()`'s uncovered-children pass (`:1197-1205`), where the target name is
already built as `key + '.' + childName`: after `settingTarget(...)` returns, if the name is in the
table, push the sentence onto that target's `advice` array.

- `advice` is created by `target()` at `:915` and survives onto the field object at `:1855`, so one
  push serves both the editable and the locked rows. **Confirm `lockedTarget()` routes through
  `target()`** before relying on that; if it does not, push in both branches of `settingTarget`
  (`:1121-1147`) instead.
- Match the house style of existing advice strings (`:1546-1548`, `:1562-1570`): a lowercase
  fragment, no trailing full stop. Use:
  `'Docker ignores this unless the server is part of a swarm cluster, so setting it here has no effect'`
- Do not touch the locked rows' `lockReason`. The advice sits underneath it, which reads correctly:
  what the form cannot edit, then why it would not matter.

## Phase 2 — the fixed-name versus several-copies clash

This is a **cross-field** check, so it belongs in the pass that already does cross-field advice —
the one producing *"no network called X is defined in this file"* (`:2102`) and *"X has no health
check, so this will never come true"* (`:2126-2127`). That pass runs with the whole field list in
hand, which is exactly what this needs and what `harvest()` cannot offer (`container_name` is
harvested in the fixed ALWAYS pass at `:1538-1574`, `deploy.replicas` in `harvestBlock` at
`:1197-1205` — different passes, different depths).

In that pass: find the `container_name` field and the `deploy.replicas` field. If the name has a
non-empty value **and** replicas parses as an integer greater than 1, push a sentence onto both.

- **Only fire on a plain integer.** If replicas is `${COPIES}` or anything else that does not parse,
  say nothing — the interpolation note at `:1814-1819` already tells the reader the value comes from
  outside the file, and guessing past that would produce a warning that is sometimes wrong.
- Two sentences, each naming the other row, so whichever one the reader is looking at tells them the
  whole story:
  - on `container_name`: `'Docker refuses this together with more than one copy below — clear one of the two'`
  - on `deploy.replicas`: `'Docker refuses more than one copy together with a fixed container name — clear one of the two'`
- Reuse whatever the existing pass already uses to locate a field by target name; do not add a
  second lookup.

## Phase 3 — the label that disagrees with its own help (optional, drop it freely)

Found while looking, not part of `PLAN_7`. A `deploy.mode` row is labelled **"Mode"** because
`inferTitle()` (`:1743-1750`) consults only `LEAVES.deploy`, while its ⓘ bubble is headed
**"Replication mode"** from `DESCRIPTIONS.deploy` (`:4734-4748`). Same mismatch on `endpoint_mode`,
`placement`, `update_config` and `replicas`.

Fix in one place: in `inferTitle()`, for a dotted target, prefer the heading the description table
already carries before falling back to `humanise()` of the last segment. The headings were written
as titles, so this makes label and help agree.

**It also changes labels under `healthcheck:`, `logging:` and `build:`**, which is why it is its own
phase. Dump the before/after label list and read it — a list to agree with, not an equality to
assert, the same treatment `PLAN_15` phase 3 used.

## Deliberately left out

- **A note on `restart_policy` overlapping `restart:`.** It is honoured outside swarm, so it is not
  a dead setting, and which of the two wins when both are set has not been measured. A note that
  guesses is worse than none. `PLAN_7.md:127-128` says decide which control wins before offering
  either — unchanged.
- **Controls for any swarm-only key.** The sentence is more use than a box.
- **Extending `excludes`/`blocked` to take a value condition.** Nothing now needs it.

## Verification

Local, before deploying:

```sh
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
python tests/validate_schema.py
```

Advice changes no bytes on save, so **the null-edit round-trip must keep passing untouched** — that
is the main regression guard here. If `tests/yaml_roundtrip.js` already has a field-inspection
helper, add cases there for: the five swarm sentences present; the clash sentences present with
`replicas: 3`; absent with `replicas: 1`; absent with `replicas: ${N}`; absent when
`container_name` is missing.

Then deploy to the test box (`pscp` the plugin folder, `plink` `dev-install.sh`) and open a stack
whose compose file carries, in one service, `container_name`, `deploy.replicas: 3`, `mode`,
`endpoint_mode` and a `placement` block. Hard-refresh, then check: the two mode boxes are still
typeable and each carries the swarm sentence; the placement block shows both its lock reason and the
swarm sentence; the name row and the copies row each warn about the other; set copies to 1 and both
warnings go away without redrawing the form.
