# PLAN_36 — the 51 settings that already work but look broken

**Status: APPROVED 2026-08-18, building.** Split out of PLAN_34 after the review found it was
neither part of that plan's problem nor blocked by any of it.

**Scoped down after pinning the work to real lines** — see "What pinning it down changed" below.
Three of the five items dissolved on contact with the code. What is left is smaller, verified, and
still the best-value change available.

## What you would notice

Open a service that has a setting the form has never been taught about — a hardware address, a CPU
limit, a platform — and it is *already there in Advanced, already editable*. What is wrong is the
name beside it. It reads `Mac Address`, `Gpus`, `Dns Opt`: the raw compose key with the underscores
knocked out. Meanwhile the little information bubble beside that very row shows the proper name and
a full plain-English explanation, because help text was written for all ninety compose settings a
long time ago.

The label lookup just never asks that table. **One line fixes the name on 42 of those rows at
once** — the other 9 already read correctly by accident.

## Why this is its own plan

PLAN_34 buried this at the bottom as "phase 5 — the read-only 18 get proper labels", described as a
writing job. Three things were wrong with that:

- It is not a writing job. The words already exist, in the project's own voice.
- It is not 18 rows, it is 42 — every uncovered setting whose written name differs from the
  mechanical one, not just the uneditable ones.
- Put last, it means every earlier phase ships next to mangled labels for no reason.

It also depends on nothing and blocks nothing. It is a day.

## What the review corrected

The belief that these 51 settings render as **read-only rows** was wrong for about two thirds of
them. Any of these keys whose value is plain text is a normal editable box today — `mac_address`,
`runtime`, `cpus`, `cpu_shares`, `cpuset`, `pids_limit`, `mem_reservation`, `platform`,
`stop_grace_period`, `cgroup`, `isolation`, `userns_mode`, `uts` and roughly twenty more. Reading
and writing them already works.

So the real deficit is narrower and cheaper than it looked:

1. **No proper name** — 42 of them. One lookup fix.
2. **No dropdown where the value is one of a known few** — six already have their vocabularies
   written and sitting unused, with a comment in the source saying so.
3. **Cannot be added when the file lacks them.** Real, but *not* fixable with a table entry — see
   below. It belongs to PLAN_34.

## What pinning it down changed

Measured against the code rather than described from it, three items went away and the numbers got
sharper.

- **The label fix is real, and it improves 42 rows, not 51.** All 51 uncovered keys do have a
  written title and explanation — `DESCRIPTIONS.service` holds 90 entries and covers every one. But
  for 9 of them the written title and the mechanical humanising already read identically
  (`Isolation`, `Runtime`, `Attach`, `Platform`, `Scale`, `Links`, `Extends`, `Annotations`,
  `Provider`), so the fix is a no-op there. The other 42 go from `Mac Address` to `MAC address`,
  `Cpus` to `CPU limit`, `Oom Kill Disable` to `Disable OOM kill`, `Sysctls` to `Kernel settings`.
- **The twenty-eight table entries are not needed.** A service key the vocabulary table says nothing
  about is handed to the same function a known scalar is: an unknown *scalar* already becomes an
  ordinary editable Advanced row, and its type is already inferred from its value. So for a plain
  scalar a table entry changes nothing that the label fix does not already fix.
- **A table entry does not make a setting addable, either.** There is no add-a-plain-setting
  affordance anywhere in the form — every Add button belongs to a list, a pair group or a
  declaration. Adding a scalar the file lacks is *not possible today at all*, and making it possible
  is PLAN_34's single-setting-sections item. The v1 claim that a table entry buys addability was
  wrong.
- **`sysctls` and the eight list-shaped keys move to PLAN_34** for the same reason. `sysctls` is
  `=`-separated pairs, but the pairs harvester routes to either the Variables or the Labels binder,
  so giving `sysctls` that shape would file it under **Labels** — wrong, and confusing. A home of
  its own is a group, a section, a caption and an Add binder: the one-key-sections work, not a
  one-liner. Same for `dns_search`, `dns_opt`, `security_opt`, `group_add`,
  `device_cgroup_rules`, `links`, `external_links` and `volumes_from`.
- **The CPU-pair swap dissolves.** It only mattered while this plan was building controls for
  scalars. It is not, so there is nothing to swap. The correction still stands and belongs in
  PLAN_34: `cpu_count`/`cpu_percent` are Windows-only, `cpu_period`/`cpu_quota` are what throttles a
  container on Linux.
- **Six dropdowns already exist, not four**, and the source says so in a comment: `uts`, `cgroup`,
  `userns_mode`, `isolation`, `attach` and `oom_kill_disable` all have a vocabulary the *editor*
  reaches and the *form* does not. Two of them are booleans and need a different fix from the other
  four.

## The work

### 1. A test fixture, first

No compose file in the corpus carries any of these keys at service level, so everything here would
otherwise ship blind. Add `scratch/test-stacks/17-uncovered-keys/`, matching the numbered corpus
convention. Note that folder is deliberately not committed — the round-trip suite already reads it
that way — so the **assertions themselves use an inline compose string**, the pattern the suite
already uses for exactly this reason. The fixture is for looking at in the browser.

### 2. The label fix — 42 rows

In `inferTitle()` (`compose-model.js`), the final fall-through humanises the raw key. Before it,
consult the same description table the ⓘ bubble beside that row already uses, so the label agrees
with its own help:

```js
    if (t.binder === 'setting') {
      var si = keyInfo(t.target, 'service');
      if (si) return si.title;
    }
    return humanise(t.target);
```

Gated on `binder === 'setting'` deliberately: a list entry's target is its own *value*, and a value
that happened to match a compose key name would otherwise be titled as that key. The dotted branch
above already does the same lookup through `dottedBucket()`, so this is that pattern extended to
undotted keys rather than a new mechanism.

### 3. Four form dropdowns

`cgroup`, `isolation`, `userns_mode` and `uts` have closed vocabularies in the model already. The
form picks a dropdown by looking up `CHOICES[binder + '/' + target]`, so this is four entries in
that table naming the existing vocabulary — **no vocabulary table entry needed**, and nothing in the
model changes.

### 4. Two booleans

`oom_kill_disable` and `attach` are booleans, and a boolean box is chosen from the field's *type*,
which for an unknown key is guessed from its value. That works while the value is `true` or `false`
and fails for `yes`/`no`. Give both a vocabulary entry carrying `type: 'boolean'` — which also stops
the value being written back quoted.

**Deliberately no `title` in either entry.** The title now comes from the description table, and
duplicating it would create a second source of truth for the same words.

## Checks

The usual list from CLAUDE.md, plus:

- The new inline assertions: every one of the 42 keys whose label changes reports its written title,
  and the 9 that already read correctly are unchanged.
- A round-trip over the fixture is byte-identical — nothing here should rewrite a file at all.
- On the server, in the browser: open the fixture and confirm the rows read with their proper names,
  that the four dropdowns offer their values, and that the two booleans offer yes/no rather than a
  text box.
