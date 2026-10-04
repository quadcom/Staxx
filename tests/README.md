# The test suites — a developer note

Every check StaXX has, what each one covers, what it needs, and where the traps are. This is a
developer note: `pkg_build.sh` packages `src/staxx/` alone, so nothing under `tests/` ever reaches a
user's server, and nothing in this file is mentioned anywhere a person using StaXX would read
(`README.md`, `docs/`, a `.page`, a translation string). `CLAUDE.md` carries the rule; this file
carries the catalogue.

Everything JavaScript and schema-shaped runs on the Windows dev machine. The interpreter there is
`python`, never `python3`. Only PHP is absent locally, so the `tests/server/` suites are copied to
the server and run there.

## Local suites

```sh
node tests/run-local.js
```

Runs the same set CI runs, found by listing rather than named so a branch that lacks one of them
still gates cleanly: `node --check` on every browser script in `javascript/`, every suite at the top
level of `tests/` run bare, in order, even after one fails, then the schema self-test. Each line
shows the pass/fail count the suite itself printed; a failing one shows its whole output, indented.

| Suite | What it covers |
|---|---|
| `ca_convert` | Community Applications template -> compose conversion |
| `chip_vocabulary` | the PHP and JS chip lookups agree, the palette stays five colours, every mark used has a glyph |
| `compose_errors` | PLAN_212: the compose-errors look-up list (include/compose-errors.json): every pattern compiles as a JavaScript RegExp, every placeholder in the text has a group, each entry sample matches its own entry and none above it |
| `crosslinks` | the browser half of the same: wording, and the confirmed-link write |
| `db_images` | the table of well-known database images |
| `export_redact` | what export blanks out before a stack leaves the machine |
| `guide_coverage` | which shipped features the user guide still says nothing about |
| `health_offer` | picking a health check, and the narrow door for one found elsewhere |
| `image_import` | Docker Hub / local image -> starting compose file |
| `js_undeclared` | names assigned but declared nowhere |
| `line_endings` | every file on disk uses LF — `--fix` rewrites any that do not |
| `links_detect` | spotting that two services need to know about each other |
| `links_record` | the connection record — writing it, matching it, noticing it is stale |
| `merge_audit` | PLAN_179 part 1: `audit(sources, built, opts)`, the check every other merge suite calls; nothing to print on its own |
| `merge_audit_all` | PLAN_179 part 1: the audit (`merge_audit.js`) run over every merge fixture this project has — the three walks, every `merge-pairs/` pair, every `tests/fixtures/ca-corpus/` family, both pick orders — checking that every difference between the sources and the merged file is accounted for by a change record, rather than predicting one trap at a time |
| `merge_corpus` | PLAN_179 part 3: merges every family under `tests/fixtures/ca-corpus/` (real Community Applications templates, converted by `tests/tools/build_ca_corpus.js`) in both pick orders — parse, wiring, and `merge_audit.js`'s own `audit()` |
| `merge_examine` | the merge wizard's reading pass — storage, files, `.env` join, name/port/shorthand clashes, wiring |
| `merge_suggest` | the merge suggestions writer — `depends_on`, health check and update policy written onto the merged text |
| `merge_trip` | round two: small source pairs under `merge-pairs/r2-*`, each a way to trip the merge (sidecars, CRLF, port ranges, proxy labels, unreadable files…); SKIP lines are proven on the box; every `buildMergedText()` call here is also run through `merge_audit.js`'s own `audit()` (PLAN_179) |
| `merge_walk_dryrun` | the merge walkthrough's dry run: `examine()`/`buildMergedText()`/`apply()` against the four-stack fixture, off the box; `--check` asserts the phase 4 shape AND runs `merge_audit.js`'s own `audit()`/`compareOrders()` over both pick orders (PLAN_179). The walk itself — four running stacks merged into one, the same page checked before and after — is `tests/fixtures/merge-walk/README.md` |
| `merge_walk_six_dryrun` | the second walkthrough's dry run: six stacks behind a Traefik front door, thirteen planted traps, two pick orders; `--check` asserts every trap (PLAN_169) AND the PLAN_179 audit. The round-two torture run — every `merge-pairs/r2-*` pair walked through the actual wizard on the box, not just `buildMergedText()` — is `tests/fixtures/merge-pairs-box/README.md`; the same six stacks installed and walked on the box, before and after: `tests/fixtures/merge-walk-six/README.md` |
| `merge_walk_ta_dryrun` | the third walkthrough's dry run: three Community Applications templates (Tube Archivist, its Elasticsearch and its Redis), no traps planted, two pick orders; `--check` asserts the two address rewires, the surviving ports and mounts, a clean parse (PLAN_178) AND the PLAN_179 audit |
| `meta_scaffold` | the commented x-unraid fields a new stack starts with |
| `pin_image` | pinning an image to one exact build |
| `post_arrays` | the request body a list field is sent as: the settings scripts' `call()` must add `[]` to every repeated key, or PHP reads only the last value. The server half is `leftovers.php`'s `parse_str` cases |
| `pull_progress` | the row overlay's parser — layer/container progress, byte units, failures |
| `registry_note` | the registry-behaviour note generator's own cases |
| `stash_guard` | a set-aside may only hold the block it claims to |
| `tidy` | the service-scope layout pass — key spans, refusals, idempotence |
| `vocab-snapshot` | not a check — the value lists photographed out of `stacks.js` before PLAN_15 moved them, that `yaml_roundtrip.js` compares its own rebuilt lists against; running it alone prints nothing |
| `words` | the passphrase generator's word list — count, shape, uniqueness |
| `yaml_roundtrip` | the compose model — parse, edit, write back |
| `validate_schema.py` | x-unraid schema self-test (needs pyyaml, jsonschema) |

Run by hand, for what the runner does not do:

```sh
node tests/merge_walk_dryrun.js --check       # fixture README: tests/fixtures/merge-walk/README.md
node tests/merge_walk_six_dryrun.js --check   # fixture README: tests/fixtures/merge-walk-six/README.md
node tests/merge_walk_ta_dryrun.js --check    # fixture README: tests/fixtures/merge-walk-ta/README.md
node tests/line_endings.js --fix
node tests/registry_note.js <quirks.json> <selfhosted.json>
```

`tests/lib/` holds shared helpers, not suites (`check.js`, `dryrun.js`, `schema_check.js`);
`tests/tools/` holds the corpus builder (`build_ca_corpus.js`). The runner enters neither. A new
top-level `tests/*.js` file is a suite, and runs everywhere, by being there.

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
  folder derive from, so redirecting it moves both. Never point it at the real store. Such a suite is
  run through `tests/server/run-with-store.sh`, whose header is the whole routine (part C).
- **Some suites deliberately do not redirect it**, and hand explicit `/tmp` paths to the function
  under test instead. Moving the store even for one command makes every real stack vanish from the
  webGUI for as long as it is moved, which is not acceptable on Adrian's box.
- **Nine are wholly or partly opt-in behind an environment flag**, marked below (`compose_ensure`, `expose` and
  `import` run their offline cases always and only their live half behind the flag). An opt-in suite nobody runs is a
  suite that can rot unnoticed — run them when the code they cover is touched, and before a release.

| Suite | What it covers | Needs |
|---|---|---|
| `adopt` | Whether a compose file may be written into a folder that already exists, when the caller claims adoption of a fileless one | `STORE_ROOT` |
| `archive_images` | PLAN_181 Part B — an archived stack's own (repo, digest) pairs, read from its record before its folder is touched, and the keep-set screen that runs before any Docker call | `STORE_ROOT` at `/tmp` |
| `autostart` | The bridge to Unraid's boot-start list | `STAXX_AUTOSTART_FILE` at `/tmp` |
| `backup` | Whether the store is named in the Appdata Backup plugin's extras list, against the real installed file | `STORE_ROOT` |
| `bootcopy` | The shelf of compose copies on the flash drive: the copy after every save, the case-clash refusal, removal and restore | `STORE_ROOT` |
| `bundle` | The `.staxx` bundle importer's refusals — a crafted entry name, a planted record-folder file, a bad marker, an oversized or unreadable bundle — plus the two accept cases and the write into a fresh store | `STORE_ROOT` (only the two write cases) |
| `clash` | Two stacks claiming the same compose project name — the list-time detector, the state guard that stops a dormant twin reading as the running one, the delete guard that refuses to tear down a project it does not own, and the one check every creation door calls | `STORE_ROOT` at `/tmp` |
| `compose_ensure` | Whether StaXX installs its own Docker Compose only when none already answers, verifies it against the pinned checksum, and removes only what it installed | opt-in live case `STAXX_LIVE_COMPOSE=1` |
| `console` | The `recreate` and stack-scope `update` verbs, the scope refusals, the compose-profile flags, the job-log tailer, the log follower and the shell — no real session is ever opened | `STORE_ROOT` at `/tmp`; `SHELL_ENABLED` seeded by the script itself into the scratch store's own `config/staxx.cfg` (not the flash file), from `STAXX_SHELL_ENABLED` (default `true`) |
| `crypt` | The hashing container's refusals. Builds, starts, pulls and removes nothing | — |
| `dashboard` | PLAN_183 — dashboard.json's own normalising rules (grid limits, cell collisions, a project kept once and dropped once gone, an icon field coerced back to `auto`), the icon picker's address allowlist refusing a bad prefix or a `..` path with no fetch ever attempted, an "SVG" that is really HTML refused, and the save/prune round trip (needs docker compose reachable; skips that part otherwise), plus PLAN_225's theme-following icons: dark or light theme read from a `/tmp` ini via `STAXX_DYNAMIX_CFG`, the `-light`/`-dark` sibling chosen by both address builders, prune keeping a used icon's siblings, and the temporary variants backfill returning at once on its marker (that case goes with the backfill, PLAN_226) | `STORE_ROOT` at `/tmp` |
| `detail` | What the server can find out about a stack's icon, description, category, author and links | `STORE_ROOT`; `IMAGE_LOOKUP=false` seeded by the script itself into the scratch store's own `config/staxx.cfg` (not the flash file) |
| `composeerrors` | PLAN_212: include/ComposeErrors.php, the message shape rules, every look-up entry explaining its own sample, a newer store copy winning, a broken one ignored, and the needs-a-fix mark set, carried through a history save and cleared in a stack record | `STORE_ROOT` at `/tmp/p212-store` (via run-with-store.sh) |
| `errorreports` | PLAN_212: include/ErrorReports.php, the report queue (matched, StaXX's own refusals and the switched-off setting queue nothing; a new shape waits, a sent one is sent), an unreachable intake leaving shapes waiting, and the daily fetch keeping a good copy against an older, unparseable or failed one | `STORE_ROOT` at `/tmp/p212r-store` (via run-with-store.sh); the intake address is a dead local port |
| `devices` | The two compose readers behind the device badge and the GPU column: device paths, reservations, runtime and gpus keys | — |
| `export` | The export route — placeholders, redaction, and the job that packs a bundle | `STORE_ROOT` (some cases) |
| `expose` | Nginx Proxy Manager and Pi-hole (PLAN_176): certificate resolution, the proxy host payload's owned fields, the create/adopt/refusal plan built against in-memory hosts and records, and the plain-http refusal | store's own `config/staxx.cfg` (not the flash file) forced to `EXPOSE_ALLOW_INSECURE="no"`; live half **opt-in** `STAXX_EXPOSE_LIVE=1`, against whichever NPM/Pi-hole are already configured |
| `feedback` | PLAN_213 — the bug button's server half (include/Feedback.php) against a stand-in feedback board on a throwaway local `php -S`: connecting (pending, then connected once), the token file's mode 600, a non-image upload refused, the card and its footer, 401 ending the connection and 429 passing the board's message on, Disconnect, and no reply ever carrying the token or pairing secret | — (points its own board address and connection file at `/tmp`; never touches the real store) |
| `files` | The companion-file helpers and the archive confirmation | `STORE_ROOT` |
| `handover` | Handover targets, the set-aside name, the state file's round trip, the script text, every refusal | — |
| `handover_unraid` | The Unraid-template half of a handover: finding a template by name, holding and releasing it and its Auto Update entry, the `Unraid-N` note line's round trip, and which targets a foreign rebuild leaves unsafe to answer | `STAXX_UNRAID_TEMPLATES_DIR` and `STAXX_AUTOUPDATE_FILE` at `/tmp` |
| `health` | Reading an image's own declared health check, and every refusal of the trial that decides whether a candidate check may ever be offered | — |
| `icon_serve` | The picture-serving page's own refusals — an unknown stack, `..`, a subfolder inside `.staxx`, a dotfile, a non-picture extension, a symlink pointing out — plus a real file serving | `STORE_ROOT` at `/tmp` |
| `icons` | Service icons and their refusals, `staxx_icon_fetch_and_write()`'s own refusals with no network ever reached, and `staxx_icons_into_stacks()`'s dry run and real run putting an old-shape stack right | `STORE_ROOT` at `/tmp`; `ICON_FETCH` seeded by the script itself into the scratch store's own `config/staxx.cfg` (not the flash file) |
| `imagehistory` | Per-stack image history, and the keep-list the Scan stored images window and the storage alert build from it | `STORE_ROOT`; `UPDATE_RETAIN="3"` seeded by the script itself into the scratch store's own `config/staxx.cfg` (not the flash file) |
| `images_unused` | The "Scan stored images" window's grouping (include/Images.php): dangling vs. built-here vs. rollback-protected, the crypt-image label exclusion, and rule 2's refusal of an id not on the server's own current list. Builds and removes only its own labelled throwaway images | — |
| `import` | Brings its own data, so it passes on any box: fake Unraid templates, Compose Manager projects and FolderView3 file under `/tmp/staxx-imp-<pid>` (Import.php lets a suite define those constants first). Covers the three readers, PLAN_219's reader of projects other tools started (map-path, compose-projects from canned rows and mounts, look-in-a-folder refusals and limits), the write path into the scratch store, and the import log's 5-run and 256 KB limits. **Opt-in** `STAXX_IMPORT_CONTAINERS=1` also `docker create`s six never-started dummy containers (from `nginx:alpine`, needs it already present; removed by exact id with a label check) to prove the reader through real Docker | `STORE_ROOT` at `/tmp` (via run-with-store.sh) |
| `links` | What happens when a stack folder holds a symlink — needs a filesystem that can hold one, so never flash | `STORE_ROOT` at `/tmp` |
| `links_match` | The cross-stack matcher and its one-target credentials lookup | `STORE_ROOT` |
| `merge` | The write half of merging several stacks into one (PLAN_148 phase 4): the companion-file copy and its refusal, the one named history entry, image history carried across under the arriving service's own name, the leftover's own record mark, and every refusal before anything is written | `STORE_ROOT` at `/tmp` |
| `hostport` | PLAN_224 part B — explaining a port clash for an app on the server's own network after a start: the log matcher (nginx, Go, Node), the four who-holds-it sentences from handed-in `ss` and cgroup text, the whole check against injected container rows, host-mode service detection, and the job's shell wrapper carrying the exit code (run through a real `sh` with stand-in commands). Starts nothing and opens no port; the live case is run by hand | `STORE_ROOT` at `/tmp` (via run-with-store.sh) |
| `meta-cache` | The on-disk memory behind reading a compose file's metadata, keyed on contents plus version | — |
| `notify` | PLAN_214 — include/Notify.php: every failure-reason row, the three text layouts (icons on and off, each Release notes choice, major version, size, digests, bullets), the agent length cut and the overrule check, routing between straight away, the summary and quiet hours (across midnight, failures never held, held events carried), and the summary's due rules (daily, weekly, a missed time, a quiet day, pinned only on its turn, Needs a look and the old-images line only beside other content). Never sends: `STAXX_NOTIFY_BIN` is a recording stub and the digest, `dynamix.cfg`, notify script, agents folder and clock are scratch values under `/tmp` | `STORE_ROOT` at `/tmp` (via run-with-store.sh); settings varied through `STAXX_NOTIFY_OPTS` |
| `appwatch` | PLAN_221 — include/AppWatch.php: the pure comparison of two snapshots (restart loop at 3 in an hour reported once and re-armed, stops by themselves with the 0/137/143 exemption and the memory reason, health going unhealthy once and healthy again, vanished containers, the first-run baseline), the stack lookup by working folder, and one whole pass against a fake docker (baseline, unreadable Docker keeps the state, a held lock leaves, a change is saved and handed on). Never sends: `STAXX_DOCKER_BIN` is a stub script, `STAXX_APPWATCH_FILE` is under `/tmp`, and `STAXX_NOTIFY_BIN`, `STAXX_MAIL_FILE`, the digest, `dynamix.cfg` and the clock are scratch values | `STORE_ROOT` at `/tmp` (via run-with-store.sh); nothing in the real store |
| `moves` | Noticing when a catalogue app's template has moved registries | backs up three real files |
| `networks` | Spotting the networks a compose file names that this server does not have, against fake network lists, and the refusal that check feeds into the job runner | `STORE_ROOT` at `/tmp` |
| `override` | Two-file compose support, the strict pairing rule, and what it feeds | `STORE_ROOT` at `/tmp` |
| `paths` | Making and checking volume paths, including how one outside `/mnt` is judged | `STORE_ROOT` |
| `pending` | The restart-pending comparison — what is running against what the file now says — and above all its refusals | — |
| `pinned_due` | What an automatic pass does with a pinned image — reported the same as any other, never offered as a candidate (formerly `unpin`, alongside the now-removed `staxx_update_unpin()`) | `STORE_ROOT` |
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
| `startorder` | The top level's own order — folders and loose stacks interleaved by `root`, both directly and through the layout — plus the refusals on save and what a folder rename or removal does to it, and the Stacks-page folder icons (survive an unrelated save, follow a rename, go on delete, a version 3 file loads with none, pruning keeps a file only folders.json references) | `STORE_ROOT` |
| `storage` | What locations the store could move to | — |
| `storage_alert` | PLAN_181 Part D — the pure alert rule over (percent, clutter bytes, oldest days, thresholds), and the clutter-since merge (an id keeps its remembered date, a new one is stamped today, one no longer in the clutter is dropped), over in-memory lists only | — |
| `leftovers` | PLAN_211 — the clear-out of Unraid templates and Compose Manager's folder: the listing (orphaned, plain-container, at-risk and compose rows, the working_dir rule), clear, put back (refuses to overwrite), forget, and the job-start refusals; PLAN_220's Docker restart (stop, start, retry order; the wait-limit path; the lock refusal; the "will stay stopped" names) against a **stub `rc.docker`** under `/tmp/staxx-left-rc`, never the real one. The container-removal case is **opt-in** (`STAXX_LEFTOVERS_CONTAINER=1`, `docker create` only, needs Adrian's OK on the production box) | `STORE_ROOT`, `STAXX_UNRAID_TEMPLATES_DIR`, `STAXX_AUTOUPDATE_FILE`, `STAXX_COMPOSE_MANAGER_DIR` and `STAXX_COMPOSE_MANAGER_PLUGIN_DIR`, `STAXX_AUTOSTART_FILE` at `/tmp`, and the `STAXX_RC_DOCKER`, `STAXX_DOCKERD_PID`, `STAXX_DOCKER_WAIT` and `STAXX_RESTART_LOCK` constants defined by the suite itself; run through `run-with-store.sh`. The "Docker comes back" case needs the real `docker info` to answer (read-only) |
| `store` | Telling a StaXX store from a bare pile of compose files from neither, and creating one | `STORE_ROOT` seeded to scratch |
| `taken_facts` | PLAN_190 item 2 — staxx_import_taken_facts()'s own parsing, fed hand-written rows in the shared inspect template's shape rather than a real Docker call: the port-on-every-address dedup, empty ports/mounts, a read-only mount left out, a host path with a space, a missing compose label, and a short (nine-field) row dropped | — |
| `takeover` | The route an imported Compose Manager project takes instead of a handover. Every case is a refusal, on purpose | `STORE_ROOT` |
| `unraid_templates` | The sweep for stacks taken over before this plan: classifying every Unraid template still naming a StaXX stack's container (`ours`/`absent`/`unraid`), and reclaiming only the first two | `STAXX_UNRAID_TEMPLATES_DIR` and `STAXX_AUTOUPDATE_FILE` at `/tmp` |
| `update_mode_convert` | The one-pass rewrite of old `update.mode` spellings (`off`, `notify`) to `manual`, run once at install, and its undo | `STORE_ROOT` at `/tmp` |
| `updateeconomy` | Reference parsing, the `Accept` list, the whole cadence table, and the failed-image notice's wording | `STORE_ROOT` (row-notice cases) |
| `updaterun` | The doing side of updates — the clock, the queue, rollback, the build-base reader, plus PLAN_181 Part A (keep-digests' local half only protecting a ref a current stack still names) and Part C (the pure refuse-vs-pull decision for an absent roll-back target), plus PLAN_214 §20: the events the queue tick and the check hand to the notifier (one call, the stored failure reason, the size from a stubbed docker, the incoming-version notes fetch with its cap of 10 and a stubbed fetcher). Stubs `STAXX_NOTIFY_BIN`, `STAXX_NOTIFY_DIGEST`, `STAXX_NOTES_STUB`, `STAXX_DOCKER_BIN` itself | `STORE_ROOT` |
| `updates` | The detection core — the state file, the digest probes, the per-image ask, the scope collector | **opt-in**, `STAXX_UPDATE_*` |
| `watch` | Watching what an image's own publisher publishes | — |
| `webui` | Resolving the address a service's web-page button opens, across every port and network arrangement | — |

Where the traps are, none of them recoverable from the code:

- **`pinned_due`** — staxx_update_due()'s pinned-image exclusion cannot be proved live on this server
  (it needs a running container, which this suite may not start); the case it feeds is a note, not a
  pass, and the exclusion is really verified by reading the code (see the suite's own comment).
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
a claim. `plans/completed-plans/PLAN_60a-parser-reads-part-of-a-file.md` records the parser work that
corpus was built to check, including the two writers that splice lines themselves and so need their
own guard against editing a file only partly read.
