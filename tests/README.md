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
python tests/validate_schema.py     # x-unraid schema self-test (needs pyyaml, jsonschema)
node tests/yaml_roundtrip.js        # the compose model — parse, edit, write back
node tests/ca_convert.js            # Community Applications template -> compose conversion
node tests/image_import.js          # Docker Hub / local image -> starting compose file
node tests/stash_guard.js           # a set-aside may only hold the block it claims to
node tests/meta_scaffold.js         # the commented x-unraid fields a new stack starts with
node tests/tidy.js                  # the service-scope layout pass — key spans, refusals, idempotence
node tests/js_undeclared.js         # names assigned but declared nowhere
node tests/line_endings.js          # every file on disk uses LF — --fix rewrites any that do not
node tests/chip_vocabulary.js       # the PHP and JS chip lookups agree, the palette stays five colours, every mark used has a glyph
node tests/words.js                 # the passphrase generator's word list — count, shape, uniqueness
node tests/registry_note.js         # the registry-behaviour note generator's own cases
node tests/links_detect.js          # spotting that two services need to know about each other
node tests/links_record.js          # the connection record — writing it, matching it, noticing it is stale
node tests/merge_examine.js         # the merge wizard's reading pass — storage, files, .env join, name/port/shorthand clashes, wiring
node tests/merge_suggest.js         # the merge suggestions writer — depends_on, health check and update policy written onto the merged text
node tests/crosslinks.js            # the browser half of the same: wording, and the confirmed-link write
node tests/db_images.js             # the table of well-known database images
node tests/health_offer.js          # picking a health check, and the narrow door for one found elsewhere
node tests/pin_image.js             # pinning an image to one exact build
node tests/export_redact.js         # what export blanks out before a stack leaves the machine
node tests/guide_coverage.js        # which shipped features the user guide still says nothing about
node tests/pull_progress.js         # the row overlay's parser — layer/container progress, byte units, failures
node tests/merge_walk_dryrun.js     # the merge walkthrough's dry run: examine()/buildMergedText()/apply() against the four-stack fixture, off the box; --check asserts the phase 4 shape
                                    # the walk itself — four running stacks merged into one, the same page checked before and after — is tests/fixtures/merge-walk/README.md
node tests/merge_walk_six_dryrun.js # the second walkthrough's dry run: six stacks behind a Traefik front door, thirteen planted traps, two pick orders; --check asserts every trap (PLAN_169)
node tests/merge_walk_ta_dryrun.js  # the third walkthrough's dry run: three Community Applications templates (Tube Archivist, its Elasticsearch and its Redis), no traps planted, two pick orders; --check asserts the two address rewires, the surviving ports and mounts, and a clean parse (PLAN_178)
node tests/merge_trip.js            # round two: small source pairs under merge-pairs/r2-*, each a way to trip the merge (sidecars, CRLF, port ranges, proxy labels, unreadable files…); SKIP lines are proven on the box
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
- **Six are opt-in behind an environment flag**, marked below. An opt-in suite nobody runs is a
  suite that can rot unnoticed — run them when the code they cover is touched, and before a release.

| Suite | What it covers | Needs |
|---|---|---|
| `adopt` | Whether a compose file may be written into a folder that already exists, when the caller claims adoption of a fileless one | `STORE_ROOT` |
| `autostart` | The bridge to Unraid's boot-start list | `STAXX_AUTOSTART_FILE` at `/tmp` |
| `backup` | Whether the store is named in the Appdata Backup plugin's extras list, against the real installed file | `STORE_ROOT` |
| `bootcopy` | The shelf of compose copies on the flash drive: the copy after every save, the case-clash refusal, removal and restore | `STORE_ROOT` |
| `bundle` | The `.staxx` bundle importer's refusals — a crafted entry name, a planted record-folder file, a bad marker, an oversized or unreadable bundle — plus the two accept cases and the write into a fresh store | `STORE_ROOT` (only the two write cases) |
| `clash` | Two stacks claiming the same compose project name — the list-time detector, the state guard that stops a dormant twin reading as the running one, the delete guard that refuses to tear down a project it does not own, and the one check every creation door calls | `STORE_ROOT` at `/tmp` |
| `compose_ensure` | Whether StaXX installs its own Docker Compose only when none already answers, verifies it against the pinned checksum, and removes only what it installed | opt-in live case `STAXX_LIVE_COMPOSE=1` |
| `console` | The `recreate` and stack-scope `update` verbs, the scope refusals, the compose-profile flags, the job-log tailer, the log follower and the shell — no real session is ever opened | `STORE_ROOT` at `/tmp` |
| `crypt` | The hashing container's refusals. Builds, starts, pulls and removes nothing | — |
| `detail` | What the server can find out about a stack's icon, description, category, author and links | `STORE_ROOT`, `IMAGE_LOOKUP=false` |
| `export` | The export route — placeholders, redaction, and the job that packs a bundle | `STORE_ROOT` (some cases) |
| `expose` | Nginx Proxy Manager and Pi-hole (PLAN_176): certificate resolution, the proxy host payload's owned fields, the create/adopt/refusal plan built against in-memory hosts and records, and the plain-http refusal | store's own `config/staxx.cfg` (not the flash file) forced to `EXPOSE_ALLOW_INSECURE="no"`; live half **opt-in** `STAXX_EXPOSE_LIVE=1`, against whichever NPM/Pi-hole are already configured |
| `files` | The companion-file helpers and the archive confirmation | `STORE_ROOT` |
| `handover` | Handover targets, the set-aside name, the state file's round trip, the script text, every refusal | — |
| `handover_unraid` | The Unraid-template half of a handover: finding a template by name, holding and releasing it and its Auto Update entry, the `Unraid-N` note line's round trip, and which targets a foreign rebuild leaves unsafe to answer | `STAXX_UNRAID_TEMPLATES_DIR` and `STAXX_AUTOUPDATE_FILE` at `/tmp` |
| `health` | Reading an image's own declared health check, and every refusal of the trial that decides whether a candidate check may ever be offered | — |
| `icons` | Copying a matched icon into a stack's own folder, and its refusals | — |
| `imagehistory` | Per-stack image history, and the keep-list image cleanup builds from it | `STORE_ROOT` |
| `import` | The importer's three readers, the write path, and the per-row icon fallbacks | — |
| `links` | What happens when a stack folder holds a symlink — needs a filesystem that can hold one, so never flash | `STORE_ROOT` at `/tmp` |
| `links_match` | The cross-stack matcher and its one-target credentials lookup | `STORE_ROOT` |
| `merge` | The write half of merging several stacks into one (PLAN_148 phase 4): the companion-file copy and its refusal, the one named history entry, image history carried across under the arriving service's own name, the leftover's own record mark, and every refusal before anything is written | `STORE_ROOT` at `/tmp` |
| `meta-cache` | The on-disk memory behind reading a compose file's metadata, keyed on contents plus version | — |
| `moves` | Noticing when a catalogue app's template has moved registries | backs up three real files |
| `networks` | Spotting the networks a compose file names that this server does not have, against fake network lists, and the refusal that check feeds into the job runner | `STORE_ROOT` at `/tmp` |
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
| `startorder` | The top level's own order — folders and loose stacks interleaved by `root`, both directly and through the layout — plus the refusals on save and what a folder rename or removal does to it | `STORE_ROOT` |
| `storage` | What locations the store could move to | — |
| `store` | Telling a StaXX store from a bare pile of compose files from neither, and creating one | `STORE_ROOT` seeded to scratch |
| `takeover` | The route an imported Compose Manager project takes instead of a handover. Every case is a refusal, on purpose | `STORE_ROOT` |
| `unpin` | Releasing a pin, and what an automatic pass may act on afterwards | `STORE_ROOT` |
| `unraid_templates` | The sweep for stacks taken over before this plan: classifying every Unraid template still naming a StaXX stack's container (`ours`/`absent`/`unraid`), and reclaiming only the first two | `STAXX_UNRAID_TEMPLATES_DIR` and `STAXX_AUTOUPDATE_FILE` at `/tmp` |
| `update_mode_convert` | The one-pass rewrite of old `update.mode` spellings (`off`, `notify`) to `manual`, run once at install, and its undo | `STORE_ROOT` at `/tmp` |
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
a claim. `plans/completed-plans/PLAN_60a-parser-reads-part-of-a-file.md` records the parser work that
corpus was built to check, including the two writers that splice lines themselves and so need their
own guard against editing a file only partly read.
