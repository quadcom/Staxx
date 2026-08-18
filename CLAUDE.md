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
2. **Never destroy a hand-authored file.** Comments, ordering, anchors and formatting survive a
   write-back. A normalised round-trip over someone's file is a bug, not a trade-off.

## Development environment

Development happens on **Windows**; the code runs on a **Linux Unraid server**. Nothing in the
plugin can be executed locally — there is no PHP, no Docker, and no browser on the dev machine.
Anything beyond a syntax check has to happen on the server.

**CRITICAL:** Never rewrite entire files. Provide targeted patch diffs or isolated code blocks only.
**Execution:** Before executing any multi-file changes, write your proposed architecture to `PLAN.md` and wait for user approval. If during the process there are new sub-plans built. Create PLAN_X.md incrementing 'X' to keep track of all the steps that are outstanding. Once the plan(s) are complete then the plan files can be marked as complete. Keep the plans for future quick reference but move them into a complerted plans folder.
**COMMENTS AND DOCUMENTATION** Comments and documentation should reflect what something does not what it used to do along with what it now does.
**WRITING CODE** When writing code, Opus always makes the plan and Multiple SOnnet agents will write the code. Opus will then verify the code that was written. Just before writing starts tell me "Sonnet agents are writing".
**TOKEN USAGE** At all times be conservative on token usage.


```sh
python tests/validate_schema.py     # x-unraid schema self-test (needs pyyaml, jsonschema)
node tests/yaml_roundtrip.js        # the compose model — parse, edit, write back
node tests/js_undeclared.js         # names assigned but declared nowhere
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

`tests/server/` holds two PHP checks that can only run **on the server** — copy them up and run
them there. `files.php` covers the companion-file helpers and the delete confirmation;
`links.php` covers what happens when a stack folder holds a symlink, and needs `STACK_ROOT`
pointed at `/tmp/b1-root` for the run because /boot is vfat and cannot hold one. Each file's
header gives the exact commands.

`validate_schema.py` has no runner or framework. It prints one line per case and exits non-zero on
failure; its negative cases (what the schema must *reject*) matter more than the positive ones.

## Deploying to the test server

Credentials for the test box live in `local/dev-server.md`, which is gitignored via `/local/`.

`sshpass` is not installed on Windows, so OpenSSH cannot take a password unattended. PuTTY's
`plink` and `pscp` are present and can — use `plink -ssh -batch` (the `-batch` flag is what stops
it hanging forever on a host-key prompt).

`dev-install.sh` runs **on the server**, from `/boot/staxx-dev/`, with a copy of the plugin
folder staged beside it. So a deploy is always two steps: `pscp` the plugin folder up, then `plink`
the script. Delete the staged copy first or stale files survive the upload.

```sh
bash /boot/staxx-dev/dev-install.sh            # install or update
bash /boot/staxx-dev/dev-install.sh --remove   # remove, keep settings
bash /boot/staxx-dev/dev-install.sh --purge    # remove settings too
```

Nothing under `/usr/local/emhttp` survives a reboot — that tree is rebuilt at boot, so a reboot is
the panic button if a change breaks the webGUI. Settings do survive; they live on the flash drive
at `/boot/config/plugins/staxx/`.

`pkg_build.sh` builds the real `.txz` package and is only needed for a release. It must run on
Linux, since the package carries Unix permissions and ownership.

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

- `staxx.page` — `Menu="Docker:0"`, a tab ahead of the stock Docker Containers tab
- `Stacks.page` — `Menu="Tasks:59"`, its own top-nav button just left of stock Docker (`Tasks:60`)
- `staxx.settings.page` — Settings → Utilities

Both view pages `include` the same `include/StacksPage.php`, and their `Cond` expressions test for
`/boot/config/plugins/staxx/header_menu` in opposite directions, so exactly one is ever
live. That marker file is a **projection of the `HEADER_MENU` config key**, written by
`scripts/apply_settings` (run by `/update.php` after a settings save). The indirection exists
because `Cond` runs constantly and parsing an ini file there — or quoting a config lookup inside an
ini header — would be wasteful and fragile.

### The stack model

A stack is **a directory containing a compose file, and nothing else**. No database, no index, no
metadata sidecar. Drop a compose file in a folder and it is a stack; delete the folder and it is
gone. The compose file is the source of truth, so anything kept alongside it is a second copy that
can disagree with it. Root defaults to `/boot/config/plugins/staxx/stacks`, overridable via
`STACK_ROOT`.

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
and rejected. `staxx_compose_meta()` already parses `x-unraid` blocks; **nothing renders them as
a form yet**, and that renderer is the whole point of the project.

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
