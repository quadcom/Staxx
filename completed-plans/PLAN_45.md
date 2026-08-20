# PLAN_45 — image updates, a countdown, and one button to do the lot

**Status: BUILT 2026-08-20.** All eight phases shipped, each one deployed to the server and its
refusals proved there rather than only read — `tests/server/updates.php` and
`tests/server/updaterun.php` both pass. Every decision in the table below was Adrian's answer to a
direct question asked on 2026-08-19; nothing is inferred.

> **What the phases actually landed as**, since the build order differed from the table at the
> bottom: 1-3 as planned (detection, settings and schedule, the grid). Phases 4-8 landed together —
> the queue, the countdown and every refusal around it, roll back and clean-up, Rebuild for a
> locally built image, and notifications — because each needed the others' state to be worth
> building. Read **Found while building** at the end first: it is where this plan was wrong.

## Context

StaXX has an *Update images* action today: it downloads and brings the stack up, and that is all. It
never volunteers that anything is out of date, so knowing whether your containers are current means
going to Unraid's own Docker page — which only knows about containers it created itself, not
compose ones. That is the split-brain this plugin exists to end.

## What you would notice

Every row that can be checked says whether it is current. When something new is waiting, the row
grows a pill: the version you have, the version on offer, and a clock counting down to when it will
install itself. Cancel it, skip that version, or press it and go now. A stack you stopped never
starts itself. A stack you are editing is left alone until you are done.

At the top of the grid: **Check all**, **Update all**, a **pause** switch that freezes every clock at
once, and a line saying when the last check ran and whether it worked — so a week of silent failures
never reads as "nothing to update".

```
▾ Media                                    3 updates
  ▾ jellyfin        running    ● 10.9.7 → 10.9.8  1h 42m
      jellyfin      running    ● 10.9.7 → 10.9.8  1h 42m
  ▾ sonarr          running      up to date
  ▾ radarr          stopped    ● update ready
```

---

## Decisions

| # | Question | Decision |
|---|---|---|
| 1 | How we ask | Ask Docker on the server, so any registry works and saved logins are reused. Existing Docker Hub code stays as fallback. |
| 2 | What counts | Only the same tag moving. A pinned version never nags. |
| 3 | Locally built | Read the base named in the build recipe; if that moved, offer **Rebuild**. Unresolvable base → "can't be checked". |
| 4 | Rate limits | Remember each answer 6h, ask each distinct image once, one at a time with a gap. Optional Hub login. A deliberate *Check all* ignores the memory. |
| 5 | When | Check-all button, plus off / daily / weekly at a chosen time. |
| 6 | Notifications | Optional, off by default: never / found / found and applied. |
| 7 | The clock | Starts when an update is **found**; installs itself at zero. Cancel and Skip on the row. |
| 8 | Where set | Global default, overridable per stack and per service, stored in the stack's own file. |
| 9 | State | One small file on flash, written only when the answer changes. Browser ticks the clock from a timestamp. |
| 10 | Version shown | Version stamped inside the image → else how fresh it is → else short fingerprint in the tooltip. |
| 11 | On the grid | Pill in the row's status area, on service **and** stack rows; folder rows sum up. No new column. |
| 12 | Doing it | Download, then bring the stack up — the existing action, unchanged. |
| 13 | Stopped stacks | Badged, manually updatable, never touched by a clock. *Update all* skips them unless "include stopped" is ticked. |
| 14 | Update all | One stack at a time, grid order, visible queue, Stop. |
| 15 | Failures | Reason on the row, queue carries on, one-press roll back. |
| 16 | Old images | Keep the **two** previous versions of each image. Cleanup beyond that is optional and scheduled, configured in settings. |
| 17 | Folder rows | Yes — Check folder, Update folder. |
| 18 | Extras | All four: what-changed link, pause-all, last-checked line, never touch a stack being edited. |
| 19 | Delay | Default 24 hours, set in settings, overridable per stack and service. |
| 20 | Quiet window | **On by default**, 03:00–05:00, configurable. A clock reaching zero outside it waits, and says so. |

---

## Part A — finding out

### A1. Asking the registry

New `include/Updates.php`, everything prefixed `staxx_`, guarded by `defined()` like its siblings.

`staxx_image_remote(string $image): array` returns `['digest'=>…, 'version'=>…, 'source'=>…,
'created'=>…]`, every field missing rather than faked. Three routes, first that works:

1. `docker buildx imagetools inspect <ref>` — prints the manifest digest; `--format '{{json .Image}}'`
   gets the labels. Registry-agnostic, uses the daemon's saved logins.
2. `docker manifest inspect <ref>` — older CLIs, same digest.
3. The existing Docker Hub token-and-manifest path (`staxx_registry_config()`), Hub images only.

`staxx_docker_inspector()` probes which of 1/2 exists **once** and caches the answer in the state
file, because probing per image is sixty pointless processes. Everything goes through `staxx_sh()`,
so nothing can hang. A failure returns `[]` and is recorded as *could not check* — never as
*up to date*, which is the whole point of decision 18's last-checked line.

### A2. What we compare against

`staxx_image_local(string $image): array` reads `docker image inspect` for the running image's
`RepoDigests` entry for that repository. That is the digest of the manifest Docker pulled, which is
exactly what A1 returns — comparable without conversion. Different → update available.

Empty `RepoDigests` means the image was built here or side-loaded, so it falls to A3.

### A3. Locally built images

`staxx_build_base(string $stack, string $service): string` uses the compose model to find
`build.context` / `build.dockerfile`, reads the recipe, and takes the **final stage's** `FROM`.
`ARG` defaults declared in the recipe are substituted; a `FROM` still holding an unresolved variable
returns `''`, and the row says *can't be checked*.

The base's current digest comes from A1. What it is compared to, in order: the base image still
present locally, or the digest recorded in state at the last check. Neither → record it now, report
nothing this round. When it has moved, the row's action is **Rebuild** (`build --pull` then `up -d`),
not Update.

### A4. Staying under Docker Hub's ceiling

`staxx_update_check(string $scope, bool $force): void` — the whole pass, run as a detached job so the
page never waits on it:

- Collect every distinct image across the scope first, so three stacks sharing an image cost one
  question.
- Skip any image answered within 6 hours unless `$force` (which *Check all* and the row's own
  re-check both set).
- Sleep briefly between questions; one at a time, never a fan-out.
- Atomic `mkdir` lock under `/tmp/staxx/updates/`, same trick as the stats collector, so two tabs
  cannot start two passes.
- Progress and errors go to a job log the page follows with the existing `job` action.

Optional Hub credentials (`HUB_USER`, `HUB_TOKEN`) are used by running `docker login` from
`scripts/apply_settings`, so the token lives in Docker's own config rather than being re-sent by us.
**Risk to flag:** the token sits in plain text in the config on flash. The file is written `0600`
and the settings field is a password box, and that is the honest limit of it.

---

## Part B — where the answer is kept

`/boot/config/plugins/staxx/updates.json`, written temp-then-rename so a reader never sees half of
one, and **only when its content actually differs** — a handful of writes a week, not thousands.

```json
{
  "checked": 1755600000,
  "ok": true,
  "error": "",
  "inspector": "imagetools",
  "paused": false,
  "images": {
    "jellyfin/jellyfin:latest": {
      "local": "sha256:aaa…", "remote": "sha256:bbb…",
      "version": "10.9.8", "was": "10.9.7",
      "source": "https://github.com/jellyfin/jellyfin",
      "created": 1755500000, "seen": 1755600000, "asked": 1755600000,
      "skip": "sha256:bbb…"
    }
  },
  "history": { "Media/jellyfin::jellyfin": ["sha256:aaa…", "sha256:999…"] },
  "bases":   { "Media/custom::app": "sha256:ccc…" }
}
```

Keyed by image reference, because that is the unit we ask about. `history` is keyed by
stack-path plus service — it is what roll back and retention need, and two entries is the whole of it
(decision 16). `seen` is when the update first appeared, and it is the only thing the countdown is
computed from: the browser subtracts, so watching a clock costs nothing and a refresh cannot restart
it. `skip` remembers a fingerprint you dismissed, so it is silent until something newer arrives.

`staxx_update_state()` / `staxx_update_state_save(array $state)` are the only two ways in.

---

## Part C — the clock, and what it is allowed to do

### C1. When a clock is running

Per image, per service: `mode` and `delay` resolved in order — service metadata, stack metadata,
global setting. Modes are `off`, `notify`, `auto`. Only `auto` starts a clock.

`staxx_update_due(array $state): array` returns what is past its clock **and** permitted right now.
Every one of these is a refusal, not a delay-and-hope:

- global pause on → nothing is due;
- the stack is not running → never due (decision 13);
- outside the quiet window → not yet, and the pill says *waiting for 03:00*;
- the stack has unsaved edits or a review lock → skipped, retried next tick;
- the fingerprint is in `skip` → not an update at all.

### C2. What runs it, with no browser open

Two cron entries, written to `/etc/cron.d/staxx` by `scripts/apply_settings` followed by
`update_cron`, and re-written on install because `/etc` does not survive a reboot:

- the **check** pass, daily or weekly at the chosen time (or absent when set to off);
- an **apply** pass every 15 minutes, which is `staxx_update_due()` and nothing else. Cheap, no
  network. Without it, a clock that runs out between daily checks would sit there until someone
  opened the page.

Both live in `scripts/update-check`, which takes the pass as an argument.

### C3. Doing the update

The existing `update`/`pull` job verbs, whole stack at a time (decision 12) — download, then bring up
so only changed containers are recreated. Before it runs, the current fingerprint is pushed onto that
service's `history` list, capped at two. Nothing new in the job runner; the timer just presses the
button that already works.

### D — the queue

`staxx_update_queue(string $scope, bool $includeStopped): string` walks the scope in **grid order**
and runs one stack at a time, each as its own job so each has its own readable log. Scope is
`all`, one folder, or one stack — the same three shapes the folder-run work already uses.

Queue position lives in `/tmp`, not on flash: it is worthless after a reboot. The page shows done,
running and waiting, and **Stop** lets the current stack finish and halts the rest.

A failure records the reason against the row, keeps the badge, and moves on (decision 15). *Roll
back* re-pins that service to the previous fingerprint from `history` and brings it up — two steps
back available, since two are kept.

### E — retention and cleanup

`staxx_update_retain()` keeps the last two fingerprints per service and no more in `history`.
Anything falling off the end becomes eligible for removal, and eligible is all it becomes: cleanup is
optional (`UPDATE_CLEANUP` off / weekly) and only ever removes images that are both unused by any
container *and* absent from every `history` list. There is no general prune button — that is a
foot-gun on a server with hand-built images, and Unraid already has one for anyone who wants it.

---

## Part F — settings

New keys in `default.cfg` (semicolon comments only, or the whole file is silently rejected):

```
UPDATE_CHECK="daily"          ; off | daily | weekly
UPDATE_CHECK_TIME="04:00"
UPDATE_MODE="notify"          ; off | notify | auto — the global default
UPDATE_DELAY_HOURS="24"
UPDATE_WINDOW="true"
UPDATE_WINDOW_START="03:00"
UPDATE_WINDOW_END="05:00"
UPDATE_NOTIFY="off"           ; off | found | applied
UPDATE_RETAIN="2"
UPDATE_CLEANUP="off"          ; off | weekly
HUB_USER=""
HUB_TOKEN=""
```

`scripts/apply_settings` gains the cron write and the `docker login`. Global pause is a *state*, not a
setting, so it lives in `updates.json` — a switch on the grid should not need a settings save.

Notifications go through Unraid's own `notify` (decision 6): one message per pass, listing what was
found or applied, never one per container.

## Part G — the stack's own file

Schema additions to `schema/x-unraid.schema.json`, prose to `docs/x-unraid-schema.md`. Same shape at
stack and service level, every key optional:

```yaml
x-unraid:
  update:
    mode: auto        # off | notify | auto
    delay: 6          # hours; omit to inherit
```

This is configuration, so it belongs in the file and travels with the stack. Found updates, clocks
and skips are *state* and stay out of it — a compose file that changes because a registry changed
would be a file that is never quiet in git.

## Part H — the grid

`StacksTable.php` renders the pill in the existing status area of folder, stack and service rows —
no new column. `staxx_updates_for_row()` resolves one row's pill: current / update with versions /
counting down / waiting for window / rebuild / failed with reason / can't be checked / never checked.
Stack and folder rows sum their children.

`stacks.js` gets **one** interval for the whole page that re-renders every visible clock from `seen`
plus the resolved delay — not one timer per row. Clicking a pill opens a menu: Update now, Cancel,
Skip this version, What changed (the link from the image's own labels, shown only when it has one),
Roll back. Folder menus gain Check folder and Update folder. All new CSS is `staxx-` prefixed.

New endpoint cases, all POST, all named in the one switch:
`update-state`, `update-check`, `update-apply`, `update-cancel`, `update-skip`, `update-pause`,
`update-rollback`, `update-cleanup`.

---

## Phases

| # | Phase | Lands |
|---|---|---|
| 1 | Detection core — `Updates.php`, remote/local digests, inspector probe, state file, the check pass | Testable on the server with no UI |
| 2 | Settings keys, settings panel, cron install, Hub login | |
| 3 | Grid pills, one page clock, row menu, last-checked line, pause switch | |
| 4 | Queue — Update all, folder scope, progress, Stop | |
| 5 | The apply pass — due, quiet window, pause, running-only, edit-lock refusals | |
| 6 | Roll back, retention, optional cleanup | |
| 7 | Locally built images — base parsing and Rebuild | |
| 8 | Notifications and the what-changed link | |

## Verifying it

No browser and no Docker on the dev machine, so: `node --check` on both browser files plus
`tests/js_undeclared.js` after every JavaScript phase; `python tests/validate_schema.py` with new
negative cases for the `update` block (a bad mode, a non-numeric delay); `php -l` on the server after
every deploy.

New `tests/server/updates.php`, run on the server, pointing the state file at `/tmp` so the real one
is never touched. It must cover the refusals rather than the happy path: a stopped stack is never
due, pause makes nothing due, the quiet window closes and opens, a skipped fingerprint stays silent
until a newer one arrives, `history` never exceeds two, a failed inspection reports *could not check*
and not *up to date*, and an unresolvable `FROM` refuses rather than guessing.

## Risks

- **Hub token in plain text on flash.** Mitigated by file mode and a password field, not solved.
- **Digest comparison across registries.** Some registries answer the same tag with a different
  manifest shape; the comparison must be manifest-digest to manifest-digest or it will report phantom
  updates. This is the one thing that needs proving on the server before phase 3 is worth building.
- **An update that breaks a container at 4am.** Roll back is the answer, and it is why retention is
  two deep rather than none.

---

## Found while building

- **Phase 1 in progress** (started 2026-08-20): `include/Updates.php` and `tests/server/updates.php`.
- **Part G needs a parser change first.** The compose reader only keeps *flat* `x-unraid` values —
  one level at stack scope, one at service scope. A nested `update: { mode, delay }` block would be
  read and silently dropped, so whichever phase lands Part G has to widen that reader (or store the
  two keys flat as `update-mode` / `update-delay`) before the metadata can be resolved at all.
- **`manifest inspect` cannot answer a multi-architecture tag.** Its reply carries no digest for the
  index itself, only for each architecture, and comparing one of those against what Docker recorded
  when it pulled would report an update that does not exist. That route therefore declines rather
  than guesses, and such a box falls through to the Docker Hub path — which is why the inspector
  probe order puts `buildx imagetools` first.
- **Docker Hub's ceiling is far lower than decision 4 assumed, and this is proven on the box.** Asking
  about seven images twice inside two minutes was enough to be refused with *429 Too Many Requests*:
  an unsigned-in server gets roughly ten questions an hour, not a hundred. Two consequences. The
  pass now **stops** the moment it is refused, leaving every remaining image untouched, because a
  question we know will be turned away only makes the next hour worse. And the optional Hub sign-in
  of Part F is not really optional on any server with more than a handful of Hub images — it should
  be presented as the thing that makes checking work, not a nicety. A server whose images come from
  elsewhere (ghcr.io, lscr.io, a private registry) is unaffected.
- **Digest comparability is proven** — the risk PLAN_45 said had to be settled before Phase 3.
  Over seven real images, four matched their registry fingerprint exactly and three genuinely
  differed. A phantom-update bug would have shown all seven as different, so the two sides are
  comparing the same kind of thing. Verified for the `buildx imagetools` route against Docker Hub
  only; another registry still wants a look when one is to hand.
- **A re-check does not restart the clock** — verified on the box: the "first seen" stamp held while
  the last-checked time moved on.
- **Phase 2 landed only two of its ten settings** (2026-08-20): when checking runs, and at what time.
  The other eight — automatic mode, delay, quiet window, notifications, retention, cleanup — are read
  only by phases that do not exist, and a control that does nothing is worse than a missing one. Each
  arrives with the behaviour behind it.
- **Unraid does not read `/etc/cron.d`, and the plan's C2 is wrong about it.** Its own `update_cron`
  gathers `*.cron` files from each *registered* plugin's folder on the flash drive and merges them
  into root's single crontab. So the schedule is written as `staxx.cron` beside the settings, with no
  user field on the line, and only when its content actually changed — it lives on flash now. The
  registration part bites on a development install too, which is why `dev-install.sh` now leaves the
  marker a real install would.
- **This box has 67 distinct images, which breaks a fixed asking order.** Ten questions an hour
  against 67 images means a pass is always cut off — and asked in disk order, it is always cut off at
  the *same* point, so everything past it would never be checked at all. The pass now asks the
  least-recently-asked first, so each one resumes where the last stopped. Signed in, about a hundred
  an hour, the whole set fits in one nightly pass; this is the number that makes the sign-in
  non-optional rather than a nicety.

### Phases 4-8, built 2026-08-20

- **The plan's C2 was wrong about the timezone too, and this one nearly shipped silently.** Unraid's
  web pages set the server's real timezone from `/etc/localtime` before any plugin code runs, but a
  cron pass is plain CLI, where PHP falls back to UTC. So the quiet window — the one setting whose
  value only means anything locally — was read as 03:00 in Greenwich, four hours out on this box.
  `UpdateRun.php` now reads the same source Unraid reads, so both paths agree. Nothing else in the
  plugin cared, because nothing else compares a stored clock time against now.
- **`docker ps -a --format '{{.Image}}'` cannot tell you what is in use.** It prints the reference a
  container was started with, normally `repo:tag`, so looking that up as `repo@digest` or as an image
  id never matches and the clean-up pass's in-use guard was doing nothing at all. It asks for the
  image **id** now and compares on the leading twelve characters, since `image ls` prints the short
  form and `ps` may print the long one. A function that deletes images has to fail closed: anything
  it cannot positively account for is kept.
- **Roll back does not need to touch the compose file, and must not.** Re-pointing the tag at the
  kept digest and running the existing recreate verb does the whole job — no new job verb, no write
  to a hand-authored file. It also records the version it backed out of as that image's skip
  fingerprint, or the clock would reinstall the very update just undone.
- **A finished job whose log has been pruned is not a failed update.** The job reader reports
  `done` with a null exit code for both a missing log and a malformed id, and reading that as failure
  would blame the queue for its own housekeeping. It says what actually happened instead.
- **Phase 7 was dead code until wired to the check pass.** `staxx_rebuild_due()` asks a registry, so
  it can never run while a page is being drawn; it runs in the check pass, for exactly those services
  whose image has no local digest, and stores its answer in the state file for the row to read. The
  same rule as everything else here: the page reads, the pass asks.
- **A rate-limited box makes registry-dependent tests flap.** A probe can succeed and the very next
  request be refused, so guarding a case on a probe taken moments earlier proves nothing. The two
  rebuild cases are gated on the reason *their own* call handed back.
- **Nested `x-unraid` needed the reader widened by one level**, as this plan predicted, and
  `STAXX_META_VERSION` bumped with it — every stack already cached on disk would otherwise have kept
  answering without the new keys until its file happened to change.
