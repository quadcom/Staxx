# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

StaXX is an Unraid 7.2+ webGUI plugin that replaces Unraid's proprietary XML Docker
templates with standard Compose files, and renders those compose files as a form so non-technical
users can configure containers without touching YAML. Pre-alpha; see `README.md` for the design
commitments and `docs/README.md` for the plain-English overview.

Two rules override most other judgement calls:

1. **A file authored here must run unmodified under plain `docker compose up` anywhere.** UI
   metadata lives only in optional `x-unraid:` extension keys, which the compose spec ignores.
2. **Never lose what the author wrote.** The thing under protection is their *meaning and their
   annotations*, not the byte order. Comments, anchors, values and intent survive a write-back.
   Rearranging is not the harm — two files holding the same configuration in a different order are
   the same stack. **Losing** something is the harm, and so is changing a file without saying so.
   A file that is genuinely wrong should be *fixed*, not refused: say what was wrong, say what
   changed, and make it undoable. A silent correction is the real danger, not an edit.

   Two places where order is genuinely not neutral, so this is not a licence to normalise:
   a comment sits on a line rather than on a key, so it has to travel with what it annotates; and a
   YAML alias must come after its anchor, so some reorderings turn a valid file into one that will
   not load.

## Development environment

Development happens on **Windows**; the code runs on a **Linux Unraid server**. There is no PHP,
no Docker, and no browser on the dev machine, so a webGUI page can never be driven locally. But
node and python are both present here, and every JavaScript and schema suite — the compose model's
own round-trip tests, the Community Applications converter, the image importer, the undeclared-name
check, the schema self-test — runs on the dev machine, not just a syntax check of it. `compose-
model.js` is requireable from node directly, so a suspected round-trip bug can be proven with a
throwaway probe instead of guessed at. Only PHP is genuinely absent locally.

**CRITICAL:** Never rewrite entire files. Provide targeted patch diffs or isolated code blocks only.
**Execution:** Before executing any multi-file changes, write your proposed architecture to `PLAN.md` and wait for user approval. If during the process there are new sub-plans built. Create PLAN_X.md incrementing 'X' to keep track of all the steps that are outstanding. Once the plan(s) are complete then the plan files can be marked as complete. Keep the plans for future quick reference but move them into a complerted plans folder.
**COMMENTS AND DOCUMENTATION** Comments and documentation should reflect what something does not what it used to do along with what it now does.
**WRITING CODE** When writing code, Opus always makes the plan and Multiple SOnnet agents will write the code. Opus will then verify the code that was written. Just before writing starts tell me "Sonnet agents are writing".
**TOKEN USAGE** At all times be conservative on token usage.


```sh
python tests/validate_schema.py     # x-unraid schema self-test (needs pyyaml, jsonschema)
node tests/yaml_roundtrip.js        # the compose model — parse, edit, write back
node tests/ca_convert.js            # Community Applications template -> compose conversion
node tests/image_import.js          # Docker Hub / local image -> starting compose file
node tests/stash_guard.js           # a set-aside may only hold the block it claims to
node tests/meta_scaffold.js         # the commented x-unraid fields a new stack starts with
node tests/js_undeclared.js         # names assigned but declared nowhere
node tests/words.js                 # the passphrase generator's word list — count, shape, uniqueness
node tests/registry_note.js         # the registry-behaviour note generator's own cases
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
```

`stacks.js` is one big IIFE, so a single typo kills the whole page's behaviour silently —
`node --check` is the cheapest guard there is. There is no PHP linter locally; run `php -l` on the
server after every deploy, over `include/*.php`.

Run **both** JavaScript checks, because they catch different things. Both browser files are strict
mode, where assigning to a name nothing declared throws instead of quietly making a global — and
`node --check` cannot see that, since the file parses perfectly and the error only exists at run
time. One such line inside a function every render calls kills the whole page.

**None of this is a shipped component, and it must never be presented as one.** `pkg_build.sh`
packages `src/staxx/` alone, so nothing under `tests/` ever reaches a user's server. Keep every
mention of the suites — and of `tests/server/` in particular, which has to be copied to a machine to
run at all — inside developer notes: this file, the plan files, and source comments. It does not
belong in `README.md`, in `docs/`, in a `.page`, in a translation string, or anywhere else a person
using StaXX would read it. The one testing-shaped thing that *is* user-facing is
`staxx_selftest()`, the cheap health check on the settings page, and that is a different thing
with a different audience.

`tests/server/` holds PHP checks that can only run **on the server** — copy them up and run them
there. `files.php` covers the companion-file helpers and the archive confirmation; `record.php` and
`imagehistory.php` cover each stack's own hidden record — its compose-file history, and the image
versions kept for a rollback, including the keep-set that decides what may be deleted;
`pending.php` covers the restart-pending comparison — whether what is running still matches what the
file now says — and above all its refusals; it needs no config keys and changes nothing, because the
cases it builds are handed explicit `/tmp` paths rather than moving `STORE_ROOT`;
`unpin.php` covers releasing a pin, including the one trap in it: the declined-version
fingerprint is filed under the image's UNPINNED name, so clearing the pinned one instead makes the
whole feature silently do nothing;
`rollback.php` covers the image rollback's refusals — above all that the version asked for must be
one this service itself recorded rather than merely digest-shaped;
`crypt.php` covers the hashing container's refusals, and needs no config keys at all: every case
either calls a pure function with made-up data or asks a read-only question, so it builds, starts,
pulls and removes nothing. The two that matter most are that a hash format is refused unless the
self-test has actually proven it, and that the superseded-image chooser never picks an image without
StaXX's own stamp on it — the one place StaXX deletes without asking;
`releasenotes_live.php` is the one suite that talks to the network, so it is opt-in — it runs only
with `STAXX_LIVE_NOTES=1` and needs no config keys, because it asks read-only questions and records
nothing. It proves the notes lookup end to end against a real project, and pins the gap that a
rolling tag finds no release at all, so those cases flip to green the day PLAN_82a lands. A failure
there may mean the external repository changed rather than the code being wrong;
`updateeconomy.php` covers PLAN_90's registry economy — reference parsing including the ghcr/lscr
no-rewrite rule, the OCI-index-first `Accept` list, the whole cadence table with its churn/floor/
ceiling clamps, and the failed-image notice's wording once `fails` is already in state. It is
offline (no stub of a registry exists in this repo, so anything that needs a real HTTP reply is
left to `registry_live.php` below) and needs no config keys for most of it, but its row-notice
section reads a real stack off the stacks folder, so `STORE_ROOT` is pointed at `/tmp` and restored the
same way `record.php` does; `registry_live.php` is `releasenotes_live.php`'s sibling for the same
plan — opt-in behind `STAXX_LIVE_REGISTRY=1`, needs no config keys, and proves a real `304` against
a real registry plus that the digest matches what the docker CLI reports, for a Hub, a ghcr and an
lscr image; `registry_quirks.php` is PLAN_92 Stage 1 — opt-in behind `STAXX_QUIRKS=1`, needs no
config keys, and asks the same read-only questions of nine real public registries (Docker Hub gets
just one image; its allowance is the only tight one), printing a summary table of what each one
turned out to do. It carries the regression guard for the ghcr placeholder-scope fix, run against
ghcr and codeberg's Gitea-hosted registry alike, and is worth a run whenever the registry code is
touched, or before a release — an opt-in suite nobody runs is a suite that can rot unnoticed;
set `STAXX_QUIRKS_JSON=/tmp/quirks.json` alongside it to also save what it measured;
`registry_selfhosted.php` is PLAN_92 Stage 2 — opt-in behind `STAXX_SELFHOSTED=1`, needs
`REGISTRY_TRUST` pointed at `127.0.0.1:45000,127.0.0.1:45001,127.0.0.1:45002`, and is the only suite
here that pulls anything: it starts and removes three throwaway registries on the box itself (open,
password-protected, and a second implementation) to prove what a self-hosted registry does that a
public one cannot show. Worth a run alongside `registry_quirks.php` whenever the registry code is
touched, or before a release — the same "an opt-in suite nobody runs" trap applies twice over here, and
`STAXX_SELFHOSTED_JSON=/tmp/selfhosted.json` saves what it measured the same way. Hand those two
files to `node tests/registry_note.js /tmp/quirks.json /tmp/selfhosted.json` to regenerate
`tests/server/REGISTRY-BEHAVIOUR.md`, the written record of what each of the twelve registries turned
out to do — regenerate it as part of running the suites rather than as a separate chore somebody
forgets, and never hand-edit it, since the next run overwrites it. It refuses to write anything from
a run that reported failures;
`detail.php` covers PLAN_84 Phase 2's resolver — what the server can find out about a stack's icon,
description, category, author and links — and needs `STORE_ROOT` pointed at `/tmp/zzdetail-store`
and `IMAGE_LOOKUP` forced to `"false"`, both refused-without like every other key here; forcing the
network setting off, rather than merely not needing it, is what keeps this suite from ever touching
the network at all, since every fixture image is fictional and local inspect always then comes back
empty. Its negative cases matter most: nothing is invented for an unknown image, a non-`https` value
is discarded at every link field, an ambiguous icon name yields nothing, a value identical to one
already stored is never offered again while a genuinely different found value now surfaces as a
conflict honestly labelled by its real source, and no catalogue or template value is ever labelled
`stated` — asserted as one invariant over every case's output, alongside every value passing the
schema's own pattern;
`links.php` covers
what happens when a stack folder holds a symlink, and needs `STORE_ROOT` pointed at `/tmp/b1-store`
for the run because a store left on flash is vfat and cannot hold one; `autostart.php` covers the
bridge to Unraid's boot-start list, and points `STAXX_AUTOSTART_FILE` at `/tmp` so the real one is
never touched. Each file's header gives the exact commands. `files.php`, `links.php` and
`record.php` all point `STORE_ROOT` (the one config key the stacks folder and the archive folder
both derive from) at a `/tmp` folder, the same way, and each refuses to run without it — leaving it
out is a first-line abort, not a wrong answer.

`store.php` covers PLAN_97 Phase 2's Store.php — telling apart a folder that is already a StaXX
store, a bare pile of compose files, and one that is neither; writing the note a new store carries;
and creating the store itself. It needs `STORE_ROOT` seeded to a scratch value first, the same
first-line-abort-if-missing rule as the other suites, but that value is never a real store root here
— it only proves a stray run cannot be mistaken for one holding Adrian's real data. Its own fixtures
for the read-only inspector all live under `/tmp`, but the creation cases cannot: the store's own
placement rules refuse anything outside a real share or pool, so those live under a disposable
folder nested inside the real appdata share, cleaned up on every exit path the way
`tests/server/storage.php` already does for its pool fixtures, and its one write to the real flash
file is backed up and restored the same way `settings.php`'s is — `settings.php` itself backs up
both halves of the config since PLAN_97 Phase 4 split it in two (the flash pointer file and, once a
store exists, its own settings file inside `<store>/config`), and proves how the two layer together
with the shipped defaults, using scratch flash-file states rather than this box's real one. Its two
negative cases matter
most: a folder holding a `stacks` folder next to an `archives` folder reads as StaXX's own even
before any stack inside it has its own hidden record, and a bare pile of compose files with no
hidden record and no `archives` folder never reads as one. Running creation twice over the same
folder proves adopting an existing store disturbs nothing already inside it, and a store whose three
folders exist but hold nothing yet — exactly what creating one leaves behind — still reads as
StaXX's own rather than as somebody else's folder, which is what stops the first-run screen warning
a person off the store they chose a moment ago.

`relocate.php` covers PLAN_97 Phase 3's Relocate.php — relocation now moves the whole data store
as one tree, not the stacks folder alone, so the fixture it builds is a whole store: two stacks
under `stacks` (one carrying its own hidden `.staxx` record folder), a file under `archives`
standing in for a removed stack's zip, and a note under `config`. The Phase 1 blanket refusal is
gone, so a clean destination is accepted rather than turned away outright, and a destination is
still refused both for being the store itself and for sitting inside its `stacks` or `archives`
folders. Its cases that matter most: all three folders and the hidden record folder arrive intact,
the archive travels byte for byte since it is the only copy of a removed stack, and the fixed order
— trial run, copy, verify, only then switch the setting, only then delete the original — is proved
rather than assumed: a failure injected at the verify step is checked against the config file on
disk, not the process's own memoised copy, so it actually proves `STORE_ROOT` was never touched. A
failed trial or copy also leaves the destination exactly as it was found, whether that means absent
or present-and-empty. It needs `STORE_ROOT` seeded to a scratch value first, the same
first-line-abort-if-missing rule as the other suites, and lives under the real appdata share for the
same "the store's own placement rules refuse anything outside a real share or pool" reason
`store.php` does, with one exception: the case-clash cases need a filesystem that folds case, which
only the flash drive offers, so those live there instead, briefly. The one case that actually
succeeds runs last, since it is the only one that switches the real config and deletes the throwaway
source — everything before it must leave both alone.

`validate_schema.py` has no runner or framework. It prints one line per case and exits non-zero on
failure; its negative cases (what the schema must *reject*) matter more than the positive ones.

`tests/fixtures/test-stacks/` is the corpus that proves the never-destroy-a-file promise: real
compose files, each built to exercise one quirk (comments, anchors, odd indentation, duplicate
field names, and so on), that `yaml_roundtrip.js` and others parse, edit and write back to prove
nothing is lost. It lives in the repository so anyone can reproduce the numbers rather than trust
a claim. `completed-plans/PLAN_60a-parser-reads-part-of-a-file.md` records the parser work that
corpus was built to check, including the two writers that splice lines themselves and so need their
own guard against editing a file only partly read.

## Deploying to the test server

Credentials for the test box live in `local/dev-server.md`, which is gitignored via `/local/`.

`sshpass` is not installed on Windows, so OpenSSH cannot take a password unattended. PuTTY's
`plink` and `pscp` are present and can — use `plink -ssh -batch` (the `-batch` flag is what stops
it hanging forever on a host-key prompt).

`dev-install.sh` runs **on the server**, from `/boot/staxx-dev/`, with a copy of the plugin
folder staged beside it as `/boot/staxx-dev/staxx/`. That folder holds the plugin's **contents** —
`include`, `javascript`, `sheets`, the `.page` files — so what gets uploaded is
`src/staxx/usr/local/emhttp/plugins/staxx`, not `src/staxx`. Staging `src/staxx` instead installs
the mirrored path *inside* the plugin folder, leaving the webGUI with a plugin directory holding
nothing but a `usr` tree, and no obvious error to say so. So a deploy is always two steps: `pscp` the plugin folder up, then `plink`
the script. Delete the staged copy first or stale files survive the upload.

```sh
bash /boot/staxx-dev/dev-install.sh            # install or update
bash /boot/staxx-dev/dev-install.sh --remove   # remove, keep settings
bash /boot/staxx-dev/dev-install.sh --purge    # remove settings too
```

Nothing under `/usr/local/emhttp` survives a reboot — that tree is rebuilt at boot, so a reboot is
the panic button if a change breaks the webGUI. Settings do survive; they live on the flash drive
at `/boot/config/plugins/staxx/`.

## How StaXX is actually delivered right now

**This is pre-alpha and deliberately not on Community Applications.** Adrian offers it as a test
case people install by hand, and that shapes what a "release" means here — so do not go looking for
Unraid's packaging chain to be complete, and do not do work to complete it unasked.

**A release is a zip of the plugin folder, the installer script, and instructions for running it.**
That is the whole of it. Point anybody asking at that, not at Unraid's plugin manager.

**The Unraid-specific packaging rules therefore do not apply yet.** `pkg_build.sh` builds the real
`.txz`, and `staxx.plg` carries a version, two checksums and a `packageURL` pointing at a GitHub
release asset — none of which is currently the route anybody installs by. Keeping the manifest
truthful is still worth doing (its `CHANGES` block is the release notes Unraid would show, and the
version should match `CHANGELOG.md`), but **building a package, stamping checksums and publishing a
tagged release are not steps in shipping a change** at this stage. Treat them as work for the day
the project goes public, and ask before doing any of it.

Two facts worth not rediscovering: `pkg_build.sh` must run on Linux, since the package carries Unix
permissions and ownership; and the existing `v1.1.0` GitHub release is a **draft**, so its asset has
never been publicly downloadable. CI publishes a rolling pre-release per push carrying
`staxx-main.tar.gz` — that is the tarball the by-hand install route uses, and it is a different
thing from the `.txz`.

## Verifying a change with no browser

For server-side logic, calling plugin functions from a throwaway PHP script beats driving the UI.
`staxx_start_job()` returns `''` plus an error string for every refusal, so guard and allowlist
behaviour can be tested exhaustively — and safely, since a refusal never reaches the shell.

To check row markup, data attributes and tag balance, call
`staxx_render_rows(staxx_folder_layout(staxx_list_stacks()), true)` with a stub `_()`
defined first and dump the HTML to a file. Strip HTML comments before counting tags — the markup is
full of explanatory comments that mention `<span>` and throw off a naive balance check.

## Architecture

### Source tree mirrors install paths

`src/staxx/` is a Slackware-style tree: `src/staxx/usr/local/emhttp/plugins/staxx/`
lands at `/usr/local/emhttp/plugins/staxx/` on the server. The nesting is not decorative —
both the packager and the dev installer copy it verbatim.

The directory name `staxx` is load-bearing: it must sort alphabetically after
`dynamix.docker.manager`, which is what makes shadowing stock pages possible.

### How pages get mounted

Unraid's PageBuilder reads `.page` files: an ini header, a literal `\n---\n`, then the body. The
header's `Menu="X:N"` decides placement and rank, and `Cond` is a PHP expression evaluated on every
render of every page.

Three pages, two of them mutually exclusive:

- `Stacks.page` — `Menu="Docker:0"`, a tab ahead of the stock Docker Containers tab (`/Docker/Stacks`)
- `StaXX.page` — `Menu="Tasks:59"`, its own top-nav button just left of stock Docker (`Tasks:60`), at `/StaXX`
- `staxx.settings.page` — Settings → Utilities

Both view pages `include` the same `include/StacksPage.php`. Their `Cond` expressions test the
*same two* marker files — `header_menu` and `takeover_docker_tab` — in opposite directions of one
combined condition, so exactly one page is ever live, including the case where the takeover
setting is on but the header-menu setting is off. Both markers are **projections of the
`HEADER_MENU` and `TAKEOVER_DOCKER_TAB` config keys**, written by `scripts/apply_settings` (run by
`/update.php` after a settings save). The indirection exists because `Cond` runs constantly and
parsing an ini file there — or quoting a config lookup inside an ini header — would be wasteful and
fragile.

### The stack model

A stack is **a directory containing a compose file, and nothing else**. No database, no index, no
metadata sidecar. Drop a compose file in a folder and it is a stack; delete the folder and it is
gone. The compose file is the source of truth, so anything kept alongside it is a second copy that
can disagree with it. Stacks live at `<store>/stacks`, derived from the one `STORE_ROOT` setting —
which ships **blank**, meaning nobody has chosen where StaXX keeps its data yet. Blank is not a
default to fall back from: `staxx_stack_root()` and `staxx_archive_root()` both return `''`, and
`staxx_store_ready()` is the gate every call site ahead of a derived folder checks.

Stacks self-group by `com.docker.compose.project`, the label compose stamps on every container it
creates. Containers without it (Unraid templates, hand-created) collect under `''`.

*Folders are directories.* A stack at `<root>/Media/jellyfin/` is in the folder "Media" because
that is where it is — there is no index and no membership file. A directory at the top of the root
holding a compose file is a stack; one that does not is a folder. One level only. A stack's identity
is its path under the root, `jellyfin` or `Media/jellyfin`, and `staxx_valid_path()` gates it by
splitting on `/` and handing every segment to `staxx_valid_name()` — never by a regex that
permits a slash, which is the obvious way to write it and also the way out of the stack root.

`include/Folders.php` now holds only which folders are shown collapsed, because an empty folder has
nowhere else to keep it.

Moving a stack is a directory move, which does not change its compose project name — but Docker
recorded the old config path, so `staxx_compose_state()` indexes what compose reports three ways:
by full path, by the tail (`jellyfin/compose.yaml`, which a move does not change), and by project
name. Without the tail index a moved stack reads as stopped until it is recreated.

A stack's name is its directory name — `jellyfin`, the leaf of `Media/jellyfin` — and there is no
display-name override.

### PHP layer

Everything is prefixed `staxx_`. Files are guarded against double-inclusion by a `defined()`
early return, and each `require_once`s by absolute path.

| File | Role |
|---|---|
| `Defines.php` | Config, `staxx_sh()`, docker/compose discovery, project grouping |
| `Stacks.php` | The stack model — list, read, save, delete, validate, self-test, the job runner |
| `Folders.php` | The presentational folder layer |
| `StacksTable.php` | Renders table rows; `staxx_state_snapshot()` for cheap refreshes |
| `StacksPage.php` | Page shell, asset tags, CSRF handoff to the client |
| `Icons.php` | Icon resolution — selfh.st index, caching, initials fallback |
| `Stats.php` | Reads what the background collector wrote; GPU/CPU/mem/net |
| `action.php` | The single JSON endpoint |

`staxx_sh()` wraps every external command in `timeout -k 2 N sh -c '<cmd>' </dev/null`. Nothing
may hang: a page that waits forever on `docker` is worse than one that fails visibly. The command
goes to `sh -c` as one argument rather than trailing `timeout` directly — written the other way,
`timeout 120 cd /x && foo` time-limits the `cd`, which fails, short-circuits the `&&`, and reports
success while `foo` never runs.

### The endpoint

`include/action.php` is the only thing the page talks to. It answers JSON always, buffers output so
a stray PHP notice lands *inside* the reply rather than corrupting it, and registers a shutdown
handler so a fatal error still produces a readable response.

**POST only.** CSRF is already enforced by Unraid — `/etc/php.ini` sets `auto_prepend_file` to
`webGui/include/local_prepend.php`, which validates `csrf_token` on every POST and then `unset()`s
it. Re-checking it here cannot succeed, because the field is gone by then. But that gate covers
POST only, so accepting query-string parameters would hand anyone a way around it.

Every action is named in one `switch`; there is no path from user input to a command that is not on
that list. Two refresh sizes exist deliberately: `state` is one `compose ls` for the whole machine
and is what start/stop/restart use; `rows` re-renders the whole table body and re-reads every
compose file, so it is only for changes to the *set* of rows.

### Long-running commands

Compose commands can take minutes, so `staxx_start_job()` detaches them with `setsid`, writes
output to `/tmp/staxx/jobs/<id>.log`, and returns a job id the page polls via the `job`
action. Completion is signalled by a `STAXX_JOB_END <exit-code>` sentinel appended to the log.

Verbs are an allowlist (`staxx_job_verbs()`) with separate whole-stack and single-service forms;
a verb missing a form for a given scope is refused rather than falling back to the other. A service
name is checked for *membership in the compose file's services*, not just shape, and is
`escapeshellarg`'d on top of that. Multi-step verbs join with `&&` (not `;`) and attach `2>&1` to
every step (not once at the end of the chain) — both matter for reporting real failures.

### Background stats

`scripts/stats-collector.sh` samples `docker stats` and GPU tools out-of-band, because
`docker stats --no-stream` takes ~2s on a 60-container server. It is **not a daemon**: the page
writes a timestamp into a heartbeat file each time it asks for stats, and the collector exits once
that goes stale (45s). Close the tab and sampling stops on its own. Snapshots are written to a temp
file and moved into place, so a reader never sees half of one. Locking is an atomic `mkdir`.

### `x-unraid` metadata

`schema/x-unraid.schema.json` (JSON Schema Draft 2020-12) with prose in `docs/x-unraid-schema.md`.
Metadata lives *inside* the compose file — comment blocks and a companion file were both considered
and rejected. `staxx_compose_meta()` parses `x-unraid` blocks, and the form renderer built on top of
it — 22 field groups, covering everything from ports and volumes to update policy — is the largest
piece of engineering in the repository, and the reason the rest of this exists.

## Constraints that bite

- **LF line endings, always.** `.gitattributes` forces this. A CRLF `.page` file breaks Unraid's
  `\n---\n` split and the page is silently discarded with one line in the syslog; shell scripts fail
  just as quietly with `\r: command not found`. `dev-install.sh` strips CRs defensively rather than
  trusting the copy.
- **`default.cfg` comments must start with `;`, not `#`.** PHP's `parse_ini_file()` treats a `#`
  line as content, and one syntax error makes it reject the *whole file* and return `false` —
  silently. The failure only surfaces the day a new key is added, because existing user configs
  already hold every older key.
- **Asset URLs carry `filemtime()`.** Without it an edited stylesheet or script sits in the browser
  cache and looks exactly like a change that did not work.
- **Own the render.** Stock Unraid CSS classes are not borrowed for layout — their rules are
  invisible to us and change between releases. Every class used is `staxx-`-prefixed.
- `staxx.plg` still carries `TODO-` placeholders for author, repo and MD5. That is
  deliberate, so a premature publish fails loudly.

## Writing code

**Keep it light. Given two ways to reach the same result, take the shorter, simpler one.** Fewer
functions, fewer layers, fewer moving parts. Do not add abstraction for a second case that does not
exist yet, and do not build configurability nobody asked for. If a patch is getting long, that is a
signal to look for the smaller version of it before continuing.

Other conventions:

- Comments explain **why**, not what — especially where a subtle failure was diagnosed the hard way,
  since that reasoning is not recoverable from the code. Keep them brief and factual. Some existing
  comments run long; match their intent, not their length.
- British spelling in prose and comments.
- User-facing strings are full sentences that say what to do next, not error codes.
- No client-side libraries. `stacks.js` is plain browser JavaScript.
- GPL-2.0 header on every source file, matching `unraid/webgui` for an eventual upstream PR.

## Explaining things

Adrian is smart, and is not a professional developer. He owns and directs this project and makes
its decisions, so anything he cannot read easily is a document that fails at its job. Explain in
layman's terms — pitch the *vocabulary* low, never the substance.

- **Layman's English.** Say what something does, not what it is called. If a technical term is
  genuinely unavoidable, define it the first time it appears.
- **No file names, function names or line numbers.** He does not read the code, so
  `compose-model.js:1822` tells him nothing — say "the one line that made Image required" instead.
  Precision like that belongs in code comments, plan files and briefs to other agents, not in an
  explanation written for him.
- **Short analogies are welcome.** One sentence that makes a thing click is worth more than a
  paragraph of accuracy. What is not welcome is the extended metaphor that runs for a paragraph
  and has to be maintained.
- **Short and sweet.** Keep an explanation to 3-4 sentences.
- Lead with the summary, then the detail but only if asked for it. This is not a reason to leave
  detail out; it just gets an understandable way in.
- Applies to conversation, commit messages, comments and docs alike.
