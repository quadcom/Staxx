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
node tests/links_detect.js          # spotting that two services need to know about each other
node tests/links_record.js          # the connection record — writing it, matching it, noticing it is stale
node tests/crosslinks.js            # the browser half of the same: wording, and the confirmed-link write
node tests/db_images.js             # the table of well-known database images
node tests/pin_image.js             # pinning an image to one exact build
node tests/export_redact.js         # what export blanks out before a stack leaves the machine
node tests/guide_coverage.js        # which shipped features the user guide still says nothing about
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
there. **Every file's own header carries the exact command, the config keys that run needs, and how
it puts them back**, so the table below is an index, not a substitute for reading the header of the
one you are about to run.

Four rules run through the whole set:

- **A suite needing a config key refuses to run without it.** Leaving it out is a first-line abort,
  never a wrong answer. `staxx_cfg()` memoises on first read, so a key has to be seeded into the
  config file *before* php starts — it cannot be changed from inside the script.
- **A suite that redirects `STORE_ROOT` points it at `/tmp` and restores the real value on every exit
  path, including a fatal error.** `STORE_ROOT` is the one key both the stacks folder and the archive
  folder derive from, so redirecting it moves both. Never point it at the real store.
- **Some suites deliberately do not redirect it**, and hand explicit `/tmp` paths to the function
  under test instead. Moving the store even for one command makes every real stack vanish from the
  webGUI for as long as it is moved, which is not acceptable on Adrian's box.
- **Seven are opt-in behind an environment flag**, marked below. An opt-in suite nobody runs is a
  suite that can rot unnoticed — run them when the code they cover is touched, and before a release.

| Suite | What it covers | Needs |
|---|---|---|
| `adopt` | Whether a compose file may be written into a folder that already exists, when the caller claims adoption of a fileless one | `STORE_ROOT` |
| `autostart` | The bridge to Unraid's boot-start list | `STAXX_AUTOSTART_FILE` at `/tmp` |
| `backup` | Whether the store is named in the Appdata Backup plugin's extras list, against the real installed file | `STORE_ROOT` |
| `bootcopy` | The shelf of compose copies on the flash drive: the copy after every save, the case-clash refusal, removal and restore | `STORE_ROOT` |
| `console` | The `recreate` and stack-scope `update` verbs, the scope refusals, the job-log tailer, the log follower and the shell — no real session is ever opened | — |
| `crypt` | The hashing container's refusals. Builds, starts, pulls and removes nothing | — |
| `detail` | What the server can find out about a stack's icon, description, category, author and links | `STORE_ROOT`, `IMAGE_LOOKUP=false` |
| `export` | The export route — placeholders, redaction, and the job that packs a bundle | `STORE_ROOT` (some cases) |
| `files` | The companion-file helpers and the archive confirmation | `STORE_ROOT` |
| `gpu` | The Intel busy-percentage maths, read from the sysfs idle counter, and its shape with no sample on disk | — |
| `handover` | Handover targets, the set-aside name, the state file's round trip, the script text, every refusal | — |
| `icons` | Copying a matched icon into a stack's own folder, and its refusals | — |
| `imagehistory` | Per-stack image history, and the keep-list image cleanup builds from it | `STORE_ROOT` |
| `import` | The importer's three readers, the write path, and the per-row icon fallbacks | — |
| `links` | What happens when a stack folder holds a symlink — needs a filesystem that can hold one, so never flash | `STORE_ROOT` at `/tmp` |
| `links_match` | The cross-stack matcher and its one-target credentials lookup | `STORE_ROOT` |
| `meta-cache` | The on-disk memory behind reading a compose file's metadata, keyed on contents plus version | — |
| `moves` | Noticing when a catalogue app's template has moved registries | backs up three real files |
| `override` | Two-file compose support, the strict pairing rule, and what it feeds | `STORE_ROOT` at `/tmp` |
| `paths` | Making and checking volume paths, including how one outside `/mnt` is judged | `STORE_ROOT` |
| `pending` | The restart-pending comparison — what is running against what the file now says — and above all its refusals | — |
| `project-links` | Working out an app's own project links | **opt-in**, several `STAXX_CA_*` |
| `record` | Each stack's own hidden record — its compose-file history — and the two doors that capture into it | `STORE_ROOT` |
| `registry_live` | A real `304` against a real registry, and that the digest matches what the docker CLI reports | **opt-in** `STAXX_LIVE_REGISTRY=1` |
| `registry_quirks` | The same read-only questions asked of nine real public registries, with the ghcr placeholder-scope guard | **opt-in** `STAXX_QUIRKS=1` |
| `registry_selfhosted` | Three throwaway registries started on the box itself — open, password-protected, and a second implementation. The only suite here that pulls anything | **opt-in** `STAXX_SELFHOSTED=1`, `REGISTRY_TRUST` |
| `releasenotes` | Release notes captured at pull time: the URL builder, the trimmer, and the one shared record-before-a-pull step | `STORE_ROOT` |
| `releasenotes_live` | The notes lookup end to end against a real project | **opt-in** `STAXX_LIVE_NOTES=1` |
| `relocate` | Moving the whole data store as one tree, and the fixed order it must happen in | `STORE_ROOT` |
| `review` | The review lock, the job-runner refusal, and that a rename or a folder move keeps the lock | `STORE_ROOT` at `/tmp` |
| `rollback` | That a rollback target must be a version this service itself recorded, not merely digest-shaped | `STORE_ROOT` |
| `settings` | The settings allowlist, validator and atomic writer, and how the two halves of the config layer together | backs up both config files |
| `storage` | What locations the store could move to | — |
| `store` | Telling a StaXX store from a bare pile of compose files from neither, and creating one | `STORE_ROOT` seeded to scratch |
| `takeover` | The route an imported Compose Manager project takes instead of a handover. Every case is a refusal, on purpose | `STORE_ROOT` |
| `unpin` | Releasing a pin, and what an automatic pass may act on afterwards | `STORE_ROOT` |
| `updateeconomy` | Reference parsing, the `Accept` list, the whole cadence table, and the failed-image notice's wording | `STORE_ROOT` (row-notice cases) |
| `updaterun` | The doing side of updates — the clock, the queue, rollback, cleanup, the build-base reader | `STORE_ROOT` |
| `updates` | The detection core — the state file, the digest probes, the per-image ask, the scope collector | **opt-in**, `STAXX_UPDATE_*` |
| `watch` | Watching what an image's own publisher publishes | — |
| `webui` | Resolving the address a service's web-page button opens, across every port and network arrangement | — |

Where the traps are, none of them recoverable from the code:

- **`unpin`** — the declined-version fingerprint is filed under the image's UNPINNED name, so clearing
  the pinned one instead makes the whole feature silently do nothing.
- **`crypt`** — the two cases that matter are that a hash format is refused until the self-test has
  proved it on this machine, and that the superseded-image chooser never picks an image without
  StaXX's own stamp. That is the one place StaXX deletes without asking.
- **`detail`** — its negative cases matter most: nothing is invented for an unknown image, a
  non-`https` value is discarded at every link field, a value identical to one already stored is
  never offered again, and no catalogue or template value is ever labelled `stated`. Forcing
  `IMAGE_LOOKUP` off, rather than merely not needing it, is what keeps it off the network at all.
- **`store`** — a folder holding `stacks` beside `archives` reads as StaXX's own even before any
  stack inside it has its own record, and a bare pile of compose files never does. That is what stops
  the first-run screen warning somebody off the store they chose a moment ago.
- **`relocate`** — the order is proved, not assumed: trial run, copy, verify, only then switch the
  setting, only then delete the original. A failure injected at the verify step is checked against
  the config file on disk, not the process's own memoised copy. Its one succeeding case runs last,
  since it is the only one that switches the real config and deletes the throwaway source.
- **`releasenotes_live`** — a failure there may mean the external repository changed rather than the
  code being wrong.
- **`registry_quirks` and `registry_selfhosted`** — set `STAXX_QUIRKS_JSON` and
  `STAXX_SELFHOSTED_JSON` to save what they measured, then hand both files to
  `node tests/registry_note.js` to regenerate `tests/server/REGISTRY-BEHAVIOUR.md`, the written record
  of what each of the twelve registries turned out to do. Regenerate it as part of the run rather than
  as a chore somebody forgets, and never hand-edit it — the next run overwrites it, and it refuses to
  write anything from a run that reported failures.

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

**This is pre-alpha and deliberately not on Community Applications.** That is a listing, not a
file format: it is how people would *find* StaXX and be told it had updated. Not applying for it is
a decision, not an omission — do not do work towards it unasked.

**The packaging chain itself is complete, and the manifest is the way in people should be pointed
at.** Paste the manifest address into Unraid's **Plugins → Install Plugin** box and it installs like
any other plugin: it survives a reboot, and Unraid notices later versions because it re-reads the
manifest from `main`. **That is the only install route a user is ever shown.**

**The copy-to-flash-and-run-the-script route is development tooling, and stays out of anything a
user reads** — the same rule the test suites are under. It is how Adrian and every agent deploy to
the test box, and it is worth keeping precisely because it is the opposite of a release: no build,
no commit, no tag, no version number, and it will carry uncommitted work. It also does not survive
a reboot, which is a feature here rather than a shortcoming, since a reboot is the panic button when
a change breaks the webGUI. It belongs in this file and in `local/dev-server.md`; it does not belong
in `README.md`, in `docs/`, or in a release note.

**The manifest install route now exists, but it is a separate act from an ordinary change.**
`pkg_build.sh` builds the real `.txz`, and `staxx.plg` carries a real version, real checksums and a
`packageURL` pointing at a GitHub release asset. A `v*` tag push runs `publish.yml`, which builds
that package, stamps the manifest and publishes both as a proper GitHub release — but that only
happens on a deliberate tag, never as a side effect of an ordinary commit. **Building a package,
stamping checksums and publishing a tagged release are still not steps in shipping a change** —
they are a separate, occasional act of cutting a release, done when a version is ready to be
installable by manifest, not on every push. Ask before cutting one.

Two facts worth not rediscovering: `pkg_build.sh` must run on Linux, since the package carries Unix
permissions and ownership; and `v1.1.0` is a public release that **does** carry its `.txz`, so the
manifest route worked at 1.1.0 — it was `v1.2.0` that was cut without running the packager, leaving
the manifest naming a package nobody uploaded and still carrying 1.1.0's two checksums. That is the
exact failure `publish.yml`'s agreement checks exist to make impossible.

CI (`release.yml`) runs every gate on each push to `main` and `dev` and publishes nothing. It used
to publish a rolling tarball of the deploy bundle; that was retired when `dev` became a real install
channel, because it was the only thing that ever put `dev-install.sh` in front of the public. The two
workflows are deliberately separate and neither should grow into the other.

## Two release channels

**`RELEASING.md` is the runbook — follow it to the letter when cutting either kind of release.** It
carries the ordered steps, every refusal the build can produce and what each one means, and the two
per-branch values a merge will get wrong. What follows here is the reasoning behind it, which is
what you need when changing the machinery rather than using it.


Same plugin, two channels, chosen by which manifest address somebody pastes into **Install Plugin**.
Switching is pasting the other one.

| | `main` | `dev` |
|---|---|---|
| Version | `00.02.00` | `00.02.00_dev20260830` |
| Tag | `v00.02.00` | `v00.02.00_dev20260830` |
| Cut how | tag it, deliberately | press the button on `publish.yml`; it dates and tags itself |
| Release notes | hand-written, checked | generated from commit subjects |
| Frequency | seldom | often |

Two facts decide the shape of all of this and neither is guessable, so they are recorded here rather
than rediscovered:

- **Unraid compares plugin versions with `strcmp`, not `version_compare`.** Read it in
  `dynamix.plugin.manager/include/ShowPlugins.php`: for a plugin it is `strcmp($latest,$version) > 0`
  — only Unraid's *own* OS version gets a numeric comparison. So a version string has to sort
  correctly **as plain text**. That is why the dev marker is a date: it is fixed width, so text order
  and time order are the same thing. A counter would need padding and would break silently the day it
  overflowed it.
- **A Slackware package filename cannot hold a hyphen in its version.** `upgradepkg` splits the name
  on hyphens from the right, so `staxx-01.04.00-dev...-noarch-1.txz` reads as a package *named*
  `staxx-01.04.00`, and it would stop recognising new packages as replacing the old one. Hence
  `_dev`.

**This is why every component is two digits.** Unpadded, `1.10.0` sorts BELOW `1.5.0` — `1` comes
before `5` one character in — so the day a minor version reached double digits Unraid would silently
stop offering updates to everybody. Fixed-width fields make text order and number order the same
thing, and the padded scheme is enforced by `publish.yml` rather than remembered.

Belt and braces on top of that: `publish.yml` also refuses to publish a version that does not sort
above the newest release already out on that channel, under `LC_ALL=C` — a locale-aware comparison
can reorder punctuation, and this has to be the byte comparison `strcmp` actually performs. The
padding should make that gate unreachable; it is there for the day something else is got wrong.

**A live consequence of the same rule, which cannot be fixed from here:** versions were dates up to
`2026.08.26`, and `2026.08.26` sorts *above* every `1.x`. Anyone still running a dated build will
never be offered a numbered one. The sort gate skips the dated tags for that reason — left in, they
would refuse every release for ever — but the people on them, if any exist, are stranded and would
have to reinstall from the manifest address by hand.

One consequence to accept rather than fix: a dev user is never *offered* the stable release, because
`00.02.00` sorts before `00.02.00_dev20260830` as text. Switching to stable means pasting main's address,
which installs it outright. That is a deliberate act, which is the right shape for a channel switch.

**The `branch` entity in `staxx.plg` is the channel switch, and it is per-branch content — treat it
exactly like the README's development banner.** It decides which branch an installed plugin polls
for updates, so a `dev` value carried into main's manifest by a merge would quietly start offering
development builds to everyone on the stable channel. `publish.yml` therefore *sets* it from the
branch it is publishing and refuses to publish if it did not take. Never trust the committed value.

**So a merge from `dev` to `main` has two things to put back, not one:** the readme's banner (which
`cleanreadme` removes) and this entity, which the merge will have carried over as `dev`. Do both in
the merge commit. Forgetting is not silent — the `Check` workflow reads the entity on every push and
fails on `main` if it does not say `main` — but the window between the merge and noticing is a window
where the stable channel's manifest is pointing people at development builds, so put it back at the
same time as the banner rather than waiting to be told.

**What the deploy route cannot prove**, and so is worth an occasional real install: the manifest's
own install and removal scripts. `dev-install.sh` mirrors the parts that matter day to day — seeding
the config, running `apply_settings`, writing the registration marker — but the legacy
`stack.manager` → `staxx` settings migration, the older-package cleanup, and the whole removal path
only ever run through a genuine plugin install. The migration is the one that touches somebody's
existing settings, so it is the one worth actually exercising rather than reasoning about.

## Version policy

Ordinary semver, and it is enforced by the release workflow rather than left to memory:

**Every component is two digits.** `00.01.00`, `01.00.00`, `01.10.00` — never `1.10.0`. This is
enforced by `publish.yml` and is not a style preference; see the two-channel section above for why
text comparison makes fixed width the only safe shape. It also keeps the scheme clear of the tag
names burnt while releases were immutable, since `01.02.00` is not `1.2.0`.

- **Patch** (`00.01.01`) — fixes only. Nothing new, and nothing already on disk changes shape.
- **Minor** (`00.02.00`) — new features. Everything StaXX has already written still reads exactly as
  before.
- **Major** (`01.00.00`) — the user must act. Something stored on their server changes shape and
  needs migrating, or a setting now behaves differently than it did.

`01.00.00` is the first release meant for general use; the `00.xx.xx` line is the run-up to it.

**While StaXX is in alpha, every release is a minor one on the `00.xx.xx` line. Never propose a
`01.00.00`, and never argue a change up to major because of its shape.** Adrian's standing
instruction, 2026-08-30. Nobody is running the plugin — it is not listed on Community Applications,
which is the only way anyone would find it. **Applying for that listing is what starts the `1.0.0`
conversation, and it is his call and his alone.** Until he says so, a change that would otherwise be
major is simply the next minor.

The same reasoning retires the migration rule that used to sit below — see the next heading.

**The number is decided by what has accumulated on `dev`, and it is decided once.** A dev build
carries the number `main` is heading towards — cut `00.02.00_dev...` and you have declared the next
stable release to be `00.02.00`. If something landing later turns out to be a major change, the base
number moves and the next dev build says so; nothing is burnt either way, because a dev tag can
never collide with the stable tag it is heading towards.

**While StaXX is in alpha, a plan does not have to migrate what is already on disk.** Adrian's
standing instruction, 2026-08-30, and the same reasoning as the version rule above: nobody is running
the plugin, so there is no installed base to carry forward. Take the clean shape and leave the old
one behind. **Do not build migration machinery, upgrade paths, or code that goes on reading a shape
StaXX no longer writes** — every one of those is permanent weight bought for nobody.

The one real server is Adrian's, and it is **patched by hand, as needed**: when a change would make
something on his box read wrong, say so plainly and offer a one-off fix for those files. That is a
deliberate act at the time, not a feature in the plugin.

What still holds is the *saying*: a change that alters the shape of something already written must
state plainly what now reads differently, so the hand-patch can be aimed. Silence is the failure
here, not the absence of a migration.

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
- `staxx.plg` is fully populated — real author, real repo, real checksums. Nothing there guards
  against a premature publish any more, so that job now falls to judgement: cut a tagged release
  only when a version is actually ready to be installed by manifest.

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
