# PLAN_36 — the 51 settings that already work but look broken

**Status: DRAFT, recommended to build first. Written 2026-08-18**, split out of PLAN_34 after the
review found it was neither part of that plan's problem nor blocked by any of it.

## What you would notice

Open a service that has a setting the form has never been taught about — a hardware address, a CPU
limit, a platform — and it is *already there in Advanced, already editable*. What is wrong is the
name beside it. It reads `Mac Address`, `Gpus`, `Dns Opt`: the raw compose key with the underscores
knocked out. Meanwhile the little information bubble beside that very row shows the proper name and
a full plain-English explanation, because help text was written for all ninety compose settings a
long time ago.

The label lookup just never asks that table. **One line fixes the name on all 51 rows at once.**

## Why this is its own plan

PLAN_34 buried this at the bottom as "phase 5 — the read-only 18 get proper labels", described as a
writing job. Three things were wrong with that:

- It is not a writing job. The words already exist, in the project's own voice.
- It is not 18 rows, it is 51 — every uncovered setting, not just the uneditable ones.
- Put last, it means every earlier phase ships next to mangled labels for no reason.

It also depends on nothing and blocks nothing. It is a day.

## What the review corrected

The belief that these 51 settings render as **read-only rows** was wrong for about two thirds of
them. Any of these keys whose value is plain text is a normal editable box today — `mac_address`,
`runtime`, `cpus`, `cpu_shares`, `cpuset`, `pids_limit`, `mem_reservation`, `platform`,
`stop_grace_period`, `cgroup`, `isolation`, `userns_mode`, `uts` and roughly twenty more. Reading
and writing them already works.

So the real deficit is narrower and cheaper than it looked:

1. **No proper name** — all 51. One lookup fix.
2. **Cannot be added when the file lacks them** — all 51. Needs a table entry each.
3. **No dropdown where the value is one of a known few** — and four of these already have their
   vocabularies written and sitting unused, with a comment in the source saying so.

## The work

### 1. The label lookup (one line, all 51 rows)

Make the row-title lookup consult the ninety-key help table for a key it does not otherwise know,
instead of falling through to the mechanical humaniser. Check whether the same fall-through affects
dotted keys (`deploy.resources.limits.cpus`) — if it does, that is more rows again for free.

### 2. About twenty-eight table entries

One line each in the form's vocabulary table, giving a plain scalar key a title, a type and a home.
Read and write already work; this is what makes them *addable* and gives them a sensible group.
Triage by the **shape of the value**, not by a guess about editability:

- **Plain scalar** — one line each. This is most of them.
- **Plain list of strings** — one line plus a home. `dns_search`, `dns_opt`, `security_opt`,
  `group_add`, `device_cgroup_rules`, `volumes_from`, `links`, `external_links`.
- **`=`-separated pairs** — reuses the Variables/Labels shape as-is: `sysctls`, `annotations`.
- **Everything else** — out of scope here. See "Not in this plan".

### 3. Switch on the dropdowns that are already written

`cgroup`, `isolation`, `userns_mode`, `uts` and `attach` have finished vocabularies in the code that
nothing currently reaches. Wire them up. PLAN_34 had all five in a "stays read-only forever" bucket,
which would have left finished code dead.

### 4. Two corrections to fold in while here

- **`sysctls` is missing from PLAN_34's triage table entirely** — the table listed 50 keys, not 51.
  It matters more than most: StaXX's own template converter *writes* `sysctls` into files, as a
  list, so a Community Applications app carrying one produces a file with a row the form refuses to
  touch. Worst of both worlds.
- **`cpu_count`/`cpu_percent` and `cpu_period`/`cpu_quota` were the wrong way round.** The repo's own
  help text says the first pair is Windows containers only; the second pair is the Linux scheduling
  knobs that actually throttle a container on Unraid. Build the second pair, not the first.

## Not in this plan

Structured shapes have **no writer in this codebase at any depth** — a list of maps cannot be
produced at all. So `blkio_config`, `ulimits`, `post_start`, `pre_stop`, `credential_spec`,
`develop` and `gpus` beyond its simple `all` form stay as they are. Each is its own project.
`extends` and `volumes_from` stay read-only for a different reason: editing them restructures the
file in ways a form cannot safely undo.

`extra_hosts` is deliberately excluded too, and PLAN_34 had it priced as a cheap reuse. It is not:
it has **three** forms (a list of `host:ip` strings, a map, and a map of host to a *list* of IPs),
and the pairs shape that superficially fits splits on `=`, so it would read `somehost:10.0.0.1` as
one name with no value. Price it separately or defer it.

## Before any of it: a test fixture

**Not one of the 51 keys appears at service level anywhere in the test corpus.** Every change here
ships blind without one. Add a fixture stack carrying them first — it is cheap, and it is also what
PLAN_34 will need later.

## Checks

The usual list, plus: open the fixture and confirm every row reads with its proper name and its
help bubble; add a setting from each of the three shapes and confirm the file round-trips
byte-identically apart from the added line.
