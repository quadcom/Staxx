# PLAN — fixes from the full-tree review, 2026-08-21

## Status: COMPLETE, 2026-08-21

Every phase implemented, deployed and verified. Phase 2 grew its own sub-plan, `PLAN_60a.md`, and
had to be brought forward — Phase 1.1 removed a refusal that had been protecting ragged files by
accident, so the deliberate refusal had to exist before Phase 1 could be called done.

| | Before | After |
|---|---|---|
| Round-trip assertions | 1,420 | **1,476** |
| Catalogue conversion | 222 | **236** |
| Image import | 73 (was 68) | **73** |
| Schema self-test | pass | pass |
| `php -l` over `include/*.php` | — | clean, all 16 |
| `tests/server/` suites | — | **17 of 17 pass** |
| `buildForm` on a 120-service stack | 16.2 ms | **12.2 ms** |
| Fixture corpus | gitignored scratch | **`tests/fixtures/`, tracked** |

**Five places this plan was wrong, corrected in place rather than quietly followed:**

1. §9.1 claimed two realm-parsing sites. There is one — the two line ranges named the same block.
2. §5.5's `pointermove` bullet was withdrawn: the four listeners said to cover it do not, and
   deleting it would have made the page decide nobody was watching.
3. §5.5's Manage-icon fix would have introduced a visual bug; done a different way, same one line.
4. §7.8's `StaXX.css` cannot be added from a case-insensitive checkout without endangering the real
   stylesheet. Deliberately not done, with the reasoning recorded there.
5. Phase 2 had to run before Phase 1 was complete, not after it.

**One finding recorded rather than fixed:** a number-shaped value is written unquoted, so `1.10` or
`0755` may not survive a round trip through compose's own loader. Out-of-scope item 13, with a test
documenting today's behaviour.

## Context

A deep review read every line of the plugin: the PHP layer, both browser files, the shell
scripts, the packaging and the schema. All local suites pass (1,420 round-trip, 222
catalogue, 68 image-import, plus the schema self-test), there are no undeclared names, no
debug leftovers, every selector is prefixed and every file carries its licence header. The
review is published as an artifact for reference; this file is the executable half.

Four findings break the project's second design commitment — *never destroy a hand-authored
file* — and three of those are one-line fixes. Everything else is ordered behind them.

**Verified by execution, not inference.** node and python are present on this machine and
`compose-model.js` is requireable directly, so each compose-engine finding below was
reproduced with a throwaway probe before it was written down. Phase 1 and 2 items include
the observed wrong output.

### Do not "fix" these — three reported findings failed verification

Recorded so no agent spends time on them:

1. **Line endings are correct.** A reviewer reported the whole working copy as CRLF and the
   package build as shipping it. Byte count is **zero** CRs, in the worktree and in the
   committed blobs. `.gitattributes` is doing its job. (Adding a defensive strip to
   `pkg_build.sh` is still worth one line — item 7.7 — but nothing is broken today.)
2. **`promoteNetworksList()` on a same-column sequence does not corrupt.** It declines with
   *"There is no list of networks here to change."* and leaves the file untouched.
3. **A stray tab does not seal the form.** Neither a blank line containing a tab nor a
   tab-indented line inside a block scalar produces a seal or a warning.

Also: **`call()` in `stacks.js` cannot reject.** Its terminal `.catch` converts abort,
timeout, unreachable host and non-JSON into a resolved `{ok:false, error}`. Every "missing
`.catch`" finding that came out of the review rests on a wrong premise. Do not add error
handlers to `importRunSelected`'s `step()`, `ensureFolders`, `importOpen`, `runFileSave`,
`openFile`, `loadCompanion`, `renameFile`, `deleteFile` or `createMissingFile`.

### Working order for agents

Phases 1–4 touch `compose-model.js` and `Stacks.php`; run **1, 2, 3, 4 in sequence** because
2 and 4 sit in the same functions 1 touches. Phases 5–11 are file-disjoint from each other
and can run in parallel:

| Phase | Files owned | Parallel with |
|---|---|---|
| 5 | `stacks.js` | 6, 7, 8b, 9, 10, 11 |
| 6 | `Stacks.php`, `Folders.php`, `Settings.php`, `Autostart.php`, `action.php`, `StacksPage.php`, `Defines.php` | 5, 7, 10, 11 |
| 7 | `events-watcher.sh`, `apply_settings`, `update-check`, `dev-install.sh`, `staxx.plg`, `pkg_build.sh`, `stats-collector.sh`, `.page` | 5, 6, 8, 9, 10, 11 |
| 8 | overlaps 5 and 6 — run **after** both | — |
| 9 | `Updates.php`, `Icons.php` | 5, 6, 7, 10, 11 |
| 10 | `schema/x-unraid.schema.json`, `tests/validate_schema.py` | all |
| 11 | `tests/*` | all |
| 12 | docs, `staxx.plg`, `README.md`, `CLAUDE.md` | all |

Run after every phase:

```sh
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
node tests/ca_convert.js
node tests/image_import.js
python tests/validate_schema.py
```

and on the server, after deploy: `php -l` over `include/*.php`.

---

## Phase 1 — the three one-line file-destroyers, plus the symlink guard

### 1.1 `insertChild()` hardcodes a two-space nesting step

**`javascript/compose-model.js` ~3479.** `var indent = pair.indent + 2;`. One line later the
function computes `map` — the parent's existing child map, which already knows the real child
column — and uses it only to choose the insert position.

Observed, on a 4-space file, typing `always` into Restart:

```yaml
services:
    web:
        image: nginx
        ports:
            - "80:80"
      restart: always        # six spaces — belongs to nothing
```

The re-parse then reports services as `['0']` and raises **zero** warnings, so the form goes
blank rather than complaining (that half is Phase 2).

```js
// The parent's own children decide the column. A file that nests by four must
// not have a two-space step forced into it — the line lands at a depth that
// belongs to nothing and compose refuses the file.
var indent = map ? map.indent : pair.indent + 2;
```

`restoreSection()` ~4358 and `addService()` ~3958 already do exactly this. Blast radius of
the bug: `addSetting`, `addNested`, `addDeclNested`, `ensurePath`, `declareNetwork`,
`addDeclared`, `writeSectionEntry`, and `setPart`'s declaration branch (~3026, ~3052).

### 1.2 `emitScalar()` writes a backslash into a double-quoted scalar unescaped

**`javascript/compose-model.js` ~194.** `if (style === 'double') return '"' + value + '"';` —
the guard above covers an embedded `"` and never `\`.

Observed:

```yaml
RULE: "^/api\d+"     # \d is not a YAML escape — the file will not load
P:    "C:\data"      # loads, and silently means C: + backspace + "ata"
```

After that edit the field is **no longer found by `buildForm`** — the user types a value and
the box empties.

Live on every catalogue-imported stack: `ca-convert.js` `dq()` (~51) double-quotes every
`Variable`, `Label` and port it writes, and `setPart` (~3123) passes the spot's existing
style straight through.

Fix at the top of `emitScalar`, before the style branches:

```js
// Single-quoted YAML has no escape sequences at all, so a backslash survives
// literally. Double-quoted does, and an unescaped \ either makes the file
// unreadable (\d) or silently changes the value (\b, \t).
if (value.indexOf('\\') >= 0) return "'" + value.replace(/'/g, "''") + "'";
```

### 1.3 Duplicate field ids — an edit lands on the wrong line

**`javascript/compose-model.js` ~2135–2139.** The index suffix is appended only when
`t.binder === 'list'`. The comment there explains precisely why it is needed; the same
argument applies verbatim to `port`, `volume`, `device`, `env` and `label`, whose target
(container port, container path, variable name) is not unique.

Observed, editing the **second** of two `PUID` entries:

```yaml
environment:
  - PUID=CHANGED   # the first line was rewritten
  - PUID=100       # the one being edited, untouched
```

Confirmed duplicate ids: `web/env/PUID`, `web/port/80/tcp`. `fieldById()` (~2875) returns the
first match; `fieldAtLine()` maps both lines to the same field.

Append the index whenever `typeof t.index === 'number'`, and give `harvestPairs` /
`harvestLongForm` an index the way `harvestList` already has one.

**Care:** field ids are used as DOM ids in `stacks.js`. Grep for hand-built id strings there
before changing the shape, and re-run the round-trip suite — several cases assert exact ids.

### 1.4 A symlinked stack folder is archived as a link, then its target is deleted

**`include/Stacks.php` `staxx_archive_stack()` ~2650–2683.** Two contradictory steps:

- `zip -r -y` stores a symlink **as a link**, so the archive holds ~30 bytes and none of the
  target's contents;
- `$real = @realpath($dir)` then `staxx_rmtree($real, $real)` resolves *through* the link and
  recursively deletes the entire target tree.

Reachable because `staxx_scan_stacks()` ~708 gates on `is_dir()`, which follows symlinks — at
both the top level and the folder level. There is no `is_link()` check on a stack or folder
directory anywhere.

Scenario: a user links a stack folder into appdata so the compose file sits beside its data,
then presses Remove. The confirmation lists the real files, the archive looks fine, the
appdata folder is gone, and the function reports success.

Two-part fix:

1. `staxx_scan_stacks()` — skip a linked directory at both levels, so it never appears as a
   stack. Comment must say why: `is_dir()` follows links, and everything downstream assumes
   the directory it was handed is the thing it may delete.
2. `staxx_archive_stack()` — refuse outright when `is_link(staxx_stack_dir($name))`, with a
   sentence saying what to do next (remove the link by hand; the plugin will not delete
   something it cannot archive faithfully).

Keep `staxx_rmtree()` exactly as it is — its link-before-realpath ordering is correct and its
reasoning is recorded. The bug is the resolved path handed *to* it.

**Add to `tests/server/links.php`:** a stack directory that *is* a symlink — archive must
refuse, the target must still exist afterwards, and the stack must not be listed at all. The
existing cases cover a link *inside* a stack folder, which is already right.

---

## Phase 2 — the parser stops partway through and says nothing

**`javascript/compose-model.js` ~526** discards `parseMap(...).next`. `parseMap` ~372 and
`parseSeq` ~427 `break` on the first line they cannot key, with no `ctx.warnings.push` and no
`seal()`.

Observed, with a multi-line plain scalar (legal YAML, compose reads it):

```yaml
services:
  web:
    image: nginx
    command: echo hello
      world
  db:                  # invisible: no field, no warning, no seal
    image: postgres
```

`buildForm` reports services as `['0']`, `warnings` is `[]`, `sealed` is `[]`, and no `db/`
field exists. Two knock-ons: `addService(doc, form, 'db')` succeeds and writes a **second**
`db:` key, and cross-references to `db` are told no such service is defined.

The common trigger is not exotic — it is one sibling indented 3 where its neighbours use 4.

This is the largest change in the plan and the one most likely to surface more. Give it its
own sub-plan (`PLAN_60a.md`) covering:

1. After the root parse, compare `.next` against `lines.length`, ignoring trailing blanks and
   comments.
2. When they differ, `seal()` the remainder with a new reason (`unparsable`) and push a
   warning naming the first line number that could not be read.
3. Decide and record what the form does with a partially-sealed document. Recommended: render
   what was read, show the sealed tail read-only in the raw editor, and refuse `addService`
   for a name that may exist inside the unread region.
4. `lint()` must report it — a file the form cannot fully read is exactly what the margin is
   for.

**Do not** attempt to parse multi-line plain scalars properly as part of this. The goal is
"never silently lose content", not full YAML coverage.

---

## Phase 3 — save safety

Three faults in one place; do them as one change.

### 3.1 No conflict detection

**`include/action.php` `case 'save'` ~183** takes only `name` and `body`. Nothing records what
the file looked like when it was opened, so the last save always wins, silently. Three live
routes: two browser tabs; the image updater rewriting the image line under an open editor
(the `editing` marker only defers the updater, it does not protect the editor); a hand edit on
the server while the page is up.

Add a fingerprint round-trip:

- the page sends the hash it was given when it loaded the file;
- the endpoint hashes what is on disk now and refuses on mismatch, with a sentence offering to
  reload rather than overwrite;
- a save with no fingerprint is refused too, except on the `new` path.

Content hash, not mtime — the flash filesystem's timestamp granularity is too coarse to trust.
`staxx_meta_cache_*` (~1085) already keys on a content hash; reuse that helper.

### 3.2 The write is not atomic

**`include/Stacks.php` ~2406** — `@file_put_contents($file, $yaml)` in place. A short write on
a full flash drive is read as success, leaving a half-written compose file. Mirror the
temp-then-rename in `staxx_settings_save()` (`Settings.php` ~365–375), including comparing the
byte count written against `strlen($yaml)`.

### 3.3 The file is world-readable

**`include/Stacks.php` ~2410** — `@chmod($file, 0644)`. Compose files hold every application
password on the server. The settings file was correctly tightened to `0600` because it holds
one Hub token; the same reasoning applies with more force here.

Change to `0600`. Note in the comment that on `/boot` the mode is moot (vfat takes it from the
mount) and that this matters the moment `STACK_ROOT` points at an array share — which the
setting invites. See the existing `boot-flash-file-modes` reasoning.

Also fix **`staxx_write_file()` ~3615**, which uses a fixed temp name `$path.'.staxx-tmp'`:
two concurrent saves of one companion file interleave, A's rename places B's bytes, then B's
rename fails and B reports an error for content that did land. Use a pid suffix as the meta
cache does.

---

## Phase 4 — a failed write leaves its half-built lines behind

**`javascript/compose-model.js` `ensurePath()` ~3539–3564.** Its docblock admits that in a
file whose indentation it gets wrong the inserted level is not read back, so the level still
looks missing on the next pass. The `tries` counter stops the recursion; nothing removes the
lines already inserted. `addNested()` and `addDeclNested()` just return `-1`.

So ticking on a health check in a 4-space file inserts a wrong-indent `healthcheck:`, fails to
find it, inserts a second, gives up, reports failure — and leaves both lines in the file. The
UI says "could not write" having already damaged the document.

Move `writeSectionEntry()`'s snapshot/`refuse()` pattern (~4156) down into `ensurePath` itself,
so every caller gets the rollback. Phase 1.1 removes the common trigger; this makes the
failure safe rather than merely rare.

**This is not hypothetical.** The *Find the project link…* action shipped in PLAN_55 reaches
`ensurePath` through `addNested()`, and on a 4-space file it reports failure while leaving an
orphaned `x-unraid:` line that silently costs the form every service below it. Worked example and
observed output in §13.3.

---

## Phase 5 — the page that looks healthy and is lying (`stacks.js` only)

### 5.1 Folder run leaves skipped stacks spinning, and frozen

**`folderRun` ~14751–14830.** `setBusy(rows, …)` marks every stack row in the folder; the
server skips any stack that does not parse or that `staxx_start_job()` refuses, and returns
jobs only for the rest. Nothing clears the busy state of the skipped rows — and `paintState()`
returns early on `row.dataset.busy`, so those rows stop accepting **any** state update for the
life of the page.

After computing `total`: `clearBusy(rows)`, then re-apply busy only to the union of
`stackRows(item.name)` for the jobs actually returned. Report the skipped ones — the user
pressed Start all and some did not start.

### 5.2 The live feed gives up permanently

**`startPush` ~16537–16572.** `pushFailures` resets only in `onopen`; once the source is
nulled nothing ever calls `startPush()` again, and there is deliberately no fallback timer
anywhere on the page. EventSource retries roughly every 3s, so ~15 seconds of interruption — an
nginx reload — is enough. Same dead end when nchan is absent (`!res.available` returns).

Re-arm after a cool-down, **and** fall back to a slow `refreshState()` interval so the page
degrades to "occasionally stale" rather than "frozen". Surface the degraded state somewhere the
user can see.

### 5.3 A thrown listener gets no dialog

**~563** handles `unhandledrejection` well. There is no `window.addEventListener('error', …)`,
which leaves exactly the failure mode `CLAUDE.md` names as the project's chief fear visible
only in a console the user will never open. One listener into the same
`openLogDialog('Script error', …)`. Do this early — it makes every future instance visible.

### 5.4 The one escaping gap

**`caCardHtml` ~5848** — `title="' + app.st + ' Docker Hub stars"`. Catalogue feed data,
numeric today, and the only unescaped remote value in ~198 escape sites. `esc(app.st)`.

While there: `gpuBadge` ~16104 and `applyStats` ~16296 put a vendor key and GPU engine names
into a class/title/innerHTML unescaped; `writeProjectLink` ~12428 and the handover dialogs
~13097+ do the same with container names. All currently unreachable, all one call each.

### 5.5 Smaller, same file

- **`caSearch` guard ~7055** tests the one-shot `caSearched` latch instead of whether it has
  data, so glancing at Home mid-download leaves search showing "nothing matches" with no active
  poll. Test `!caApps.length`, as `caHomeFetch` does. Add the staleness stamp its siblings have.
- **`askConfirm` ~12895** never clears `confirmMsg`; only `removeStack` clears it before asking,
  so a failed removal's error sits under the next, unrelated question. Clear it in `askConfirm`
  and let the one retry path re-set it.
- **`importRunSelected` ~7885** never cross-checks two ticked rows against the same destination.
  De-duplicate `(destFolder, leaf)` across `idxs` before `step(0)`.
- **Companion autosave ~9338–9388** can double-POST to the flash drive when the debounce fires
  and the tab changes before the reply lands. Hold the in-flight promise and have the flush
  await it. The code's own comment justifies the dirty check on flash write endurance.
- **"Add dependency" ~4042–4062** reads `MODEL` before `flushPending()`, unlike every sibling
  handler in the same listener. Move the flush to the top of the branch.
- **`run()` ~11423** sets `dataset.busy` but never checks it before issuing, so a double-click
  sends two jobs. **`refreshRows` ~12504** has no in-flight guard, unlike `refreshState`.
- **Null guards** matching their already-guarded twins: `openUpdateQueueConfirm` ~12215,
  `toggleFolder` ~15309, modal cancel ~8863.
- **`save()` ~11035 and `manageSaveThenRun` ~13553** treat a legitimate 0-byte write as failure
  (`!res.bytes`); use `res.bytes == null`.
- **Maps keyed by compose names** (`netPresent`/`taken`/`seen`) are plain `{}`, so a network
  named `constructor` reads as present. `Object.create(null)`.
- **Manage-tab icons never self-heal** (~12658–12667 vs `manage.js` ~2452): the fallback needs
  `.staxx-icon` or `.staxx-fstrip-item`, and the Manage wrapper is neither. **Done differently from
  the plan, 2026-08-21:** this said to add `staxx-icon` to the wrapper, which would have been a
  visual bug — `.staxx-icon img` sets full width, height and a 0.4rem padding, and the Manage tab's
  icon lives in a 1.1rem box with its own `max-width` rule. The wrapper's own class was added to the
  fallback's selector list instead, which is the same one-line change with no styling side effect.
- ~~**Drop the site-wide `pointermove` idle detector ~15946** — the other four presence listeners
  already cover it.~~ **Withdrawn 2026-08-21: this plan was wrong.** The other four are
  `pointerdown`, `keydown`, `wheel` and `scroll`, and none of them fires when a pointer simply moves.
  Someone reading the page without clicking, typing or scrolling for two minutes is exactly the case
  `pointermove` exists to catch, and deleting it would have made the page decide nobody was there.
  Left in place.

---

## Phase 6 — server-side correctness

### 6.1 `staxx_finish_handover()` always reports success

**`include/Stacks.php` ~3248–3297.** Steps joined with `"\n"`, and the sentinel is
`echo "STAXX_JOB_END $?"` where `$?` is the exit of the preceding `echo` — always 0. On the
dangerous branch (user says it did not work) a failed `docker rename` + `docker start` still
removes the state file, logs "Put back exactly as it was.", clears the row, and leaves the
original container switched off under `-before-staxx` with nothing pointing at it.

Chain the real steps with `&&`, capture `ec=$?` immediately, emit `STAXX_JOB_END $ec`.
`staxx_start_takeover()` ~3433 and `staxx_start_job()` are the correct models.

### 6.2 `folders.json` — non-atomic, unlocked, unchecked

**`include/Folders.php` `staxx_folders_save()` ~100–113.** Bare `file_put_contents` of the
whole document, no temp-then-rename, no lock, and `json_encode` unchecked (on failure the file
becomes `"\n"`). Nine mutators do read-modify-write-everything: `folder-collapse`,
`folder-assign`, `folder-rename`, `folder-delete`, `start-order`, `autostart`,
`autostart-wait`, `archive`, `stack-rename`. Two at once and the second silently discards the
first; an interrupted write is read as "empty" by `staxx_folders_load()` ~76 and every
collapsed flag, drag order, boot order and boot wait is lost at once. The file is on flash.

Temp-then-rename with a pid suffix, check `json_encode` for `false` before writing anything,
and take the atomic-`mkdir` lock around read-modify-write. Model: `Stacks.php` ~1085–1094.

### 6.3 A settings save can truncate the config

**`include/Settings.php` ~356** — `$existing = @parse_ini_file(STAXX_CFG) ?: [];`. The comment
directly above states the intent (keys from a newer version must survive), and `?: []` defeats
it: `parse_ini_file()` returns `false` for the whole file on one syntax error, so the save
writes only the posted keys and drops `STACK_ROOT`, `ARCHIVE_ROOT`, `HUB_TOKEN` and the entire
update block back to defaults. The stacks list then reads as empty.

Distinguish `false` from `[]` and refuse the save with a sentence naming the file.

**Related, same pass:** all three `parse_ini_file()` calls use the normal scanner, which
rewrites unquoted `true/false/on/off/yes/no` and interpolates `${…}`. Dormant while the plugin
writes everything quoted — but a hand-edited `ICON_FETCH=false` returns `""`, and
`staxx_icon_fetching()` tests `'' !== 'false'` → **true**, so a setting switched off stays on.
Add `INI_SCANNER_RAW` here and at `Defines.php` ~71–72.

**Note, do not change:** `staxx_settings_validate()` ~255 already blocks `"`, `\` and control
characters with a comment naming the exact hazard, so the "value corrupts the ini line" chain
is not reachable. That guard is correct.

### 6.4 Autostart

**`include/Autostart.php`.**

- **~306–321** — membership is decided per service (`!empty($onMap[$rel][$svc])`) but the lines
  emitted are `$idx['names'][$rel][$svc]`, the service's **whole** container list. A service
  scaled to three with one in Unraid's boot file gets all three written back — and projection
  runs on every render, so it happens unasked. Emit only the names present in `$onMap`.
- **~126 + ~151** — the no-container fallback name is `staxx_project_name($leaf).'-'.$svc.'-1'`
  and `$leaf` ignores the folder, so `Media/jellyfin` and `Test/jellyfin` produce identical
  names and `$owner[$n]` is overwritten by whichever is enumerated last. Toggling one shows and
  changes the other. Skip the guessed name when the guess is already claimed, and surface the
  ambiguity rather than picking.
- **~455–469 and ~339** — read-modify-write of Unraid's boot file with no lock, while
  projection also fires on every render. A toggle made while another tab loads is lost. Use the
  same atomic-`mkdir` lock.
- **`StacksPage.php` ~32** — `staxx_autostart_sync($stacks)` sets `&$error` when the boot-list
  write fails and the page ignores it, rendering boot markers for a state never written.
- **~360–367** — the comment claims foreign lines "keep their place"; anything *between* StaXX
  lines is pushed after the whole run. Comment fix.

### 6.5 Validation gaps

- **`include/action.php` ~341–356** — the override guard is
  `isset($pair[1]) && basename($pair[1]) === $file`, and `staxx_compose_files()` only returns a
  second element when an override **already exists**. So the first save of a new override skips
  validation entirely and the stack becomes unstartable. `check` ~236–245 has the mirror gap.
  Derive the expected basename from the main file's name instead.
- **`include/Stacks.php` ~2099–2105** — `staxx_validate_compose()` returns `true` when
  `tempnam` or `mkdir` fails, and does not check the scratch write. A full or read-only `/tmp`
  silently disables the only real validation in the plugin. Return false with a sentence; keep
  "compose not installed" as the only accept-path.
- **`include/action.php` ~67** — `staxx_reply()` does not check `json_encode()`, so any invalid
  UTF-8 makes the endpoint answer with an **empty body** — the exact failure the file header
  exists to prevent. Reachable via `file-read` (`staxx_looks_text` only checks the first 8 KB
  for NUL), `job`, `log-read`, `exec-read`, `cenv`, `cfile-read`, `log-download`. Use
  `JSON_INVALID_UTF8_SUBSTITUTE` and fall back to a plain error object.

### 6.6 Two stacks mistaken for each other

- **`include/Stacks.php` ~822, ~829–831** — `staxx_file_tail()` is
  `basename(dirname($file)).'/'.basename($file)`, so `Media/jellyfin/compose.yaml` and
  `TV/jellyfin/compose.yaml` collide and the second `compose ls` row overwrites the first.
  `byFile` normally saves it — except after a move, which is the only reason `byTail` exists.
  One stack then shows the other's state and `staxx_exec_resolve_container()` resolves against
  the wrong project. Record the first, then **remove** the key when a second file maps to it.
- **`include/Folders.php` ~529 vs ~349–362** — `staxx_folder_taken()` compares with
  `strcasecmp`; `staxx_folder_assign()` guards only with `file_exists()`. On a case-sensitive
  root, `Media/Jellyfin` can exist beside `Media/jellyfin`, and `staxx_project_name()`
  lowercases — so both resolve to one compose project and each row can show the other's
  containers. Use the case-insensitive check for the destination in `staxx_folder_assign()` and
  `staxx_rename_stack()`.
- **`include/Stacks.php` ~4421–4430** — `staxx_exec_resolve_container()` returns an error on the
  first project+service match that is not running instead of continuing, so an exited leftover
  hides a running sibling.

### 6.7 `staxx_folder_delete()` abandons its bookkeeping

**`include/Folders.php` ~440–496.** The docblock promises "nothing is moved unless everything
can be", but the "folder still contains…" check at ~471 runs **after** every member has been
renamed to the top level, and both late returns (~464, ~476) skip the `folders.json` update
below them. A folder holding two stacks and a stray `notes.txt`: both stacks move, the error
says the folder was kept, and their drag positions, service order and boot waits are still
filed under the old path — silently unreachable.

Move the leftover check above the rename loop, and run the bookkeeping for whatever actually
moved before returning false.

### 6.8 Low, batch with the above

`staxx_yaml_flatten()` fed a hand-written file (~1169, violates its own docblock);
`$stored['meta']['error']` read after checking only `isset($stored['meta'])` (~1140);
`staxx_read_file()` skips its size cap when `filesize()` fails (~2566–2574);
`staxx_prune_jobs()` can delete a live job's log (~4083, cosmetic);
`staxx_start_order_set()` accepts a non-existent parent (`Folders.php` ~226–233);
`staxx_handover_setaside_name()` unbounded `for(;;)` (~2933);
unguarded `$a['ip']`/`$a['ports']` at `StacksTable.php` ~84/~143 where ~80–81 guard the same
keys; empty CSRF token renders a dead page with no explanation (`StacksPage.php` ~41–42);
folder drag grip disabled by `$row['count'] < 2` when only `$folderCount` is relevant
(`StacksTable.php` ~858–861); `data-update-source` emitted with escaping but without the
`^https?://` gate every `href` in that file uses (~299).

---

## Phase 7 — shell, packaging, pages

### 7.1 `events-watcher.sh` busy-loops when Docker goes away

**~182–232.** `read -r -t 1 action <&3` cannot distinguish a timeout from EOF and `read_ok=$?`
is treated as one tick either way. Once the writer closes, the read returns rc=1 *instantly*.
`ticks` reaches `CHECK` (30) in ~200 ms, the producer-dead branch at ~227 forks a fresh
`docker events` against the dead daemon, and `continue` **skips the subscriber check** — so the
exit condition is never evaluated. A NAS burns a core forking ~30 processes a second until
Docker returns. Nothing reaps it.

Treat only `rc > 128` as a timeout tick; on rc=1 back off before restarting the producer; run
the subscriber check *before* the restart, not after.

**~201–211** (low, same file): `pending` is flushed only on a read that returned nothing, so a
sustained event stream with no 1-second gap — a mass restart — withholds refreshes and never
runs the subscriber check. Flush on a wall-clock deadline instead of a tick count.

### 7.2 Five external commands with no time limit

**`apply_settings` ~98 (`docker info`), ~110 (`docker login`), ~119 (`docker logout`);
`update-check` ~60, ~75, ~95.** Every other external call in this project is wrapped — the
reasoning is written down, including a collector that survived every attempt to stop it for
fourteen minutes. A wedged dockerd makes a settings save hang indefinitely in `/update.php`,
and makes the quarter-hourly job pile up one stuck process per fifteen minutes.

Wrap in `timeout -k 2 10`.

**Related:** `apply_settings` runs under a 15-second `staxx_sh` budget (`Settings.php` ~396)
while doing `docker info` + `docker login` over the network. A slow link reports "saved but not
applied" and `update_cron` may not run, so the schedule silently does not update. Raise the
budget or move the Hub login out of the save path.

### 7.3 `update-check` has no overlap lock, and truncates before allowlisting

No lock of any kind, which is striking next to the collector's careful atomic-`mkdir` idiom.
Two passes can run over the same queue, and `: > "${LOG}"` runs before the `case`, so the
second wipes the first's output mid-flight. Take the collector's lock around the whole pass.

Separately, **~20 and ~28** build `LOG` from `${1:-check}` and truncate it **before** the
allowlisting `case` at ~113, so `update-check ../../../etc/foo` truncates that file. Root-only
and cron passes fixed words, so hygiene rather than vulnerability — move the allowlist first.

**Also verify** whether `staxx_update_queue_tick()`'s lock covers `queue_start()` (~634–699)
and `apply_pass()` (~835–864) in `UpdateRun.php`; the review found it does not, so a manual
"update all" landing on the cron tick lets both pass the "nothing running" check and the later
write discards the earlier queue.

### 7.4 The cron time is shape-checked, not range-checked

**`apply_settings` ~164–170** — `^([0-9]{1,2}):([0-9]{1,2})$` accepts `99:99`, writing
`99 99 * * *` into a file `update_cron` concatenates into root's crontab alongside every other
plugin's jobs. The comment three lines above states exactly why that matters. `Settings.php`
already has the correct pattern (`^([01][0-9]|2[0-3]):[0-5][0-9]$`); range-check here too and
fall back to the default.

### 7.5 `--purge` never signs out of Docker Hub

**`dev-install.sh` ~59–76.** `rm -rf "${CFG_DIR}"` at ~61, then `[[ -f "${CFG_DIR}/hub_login" ]]`
at ~73 — already deleted. So purge destroys the marker without running `docker logout`, leaving
the plugin's own Hub session live on the box, which is the one thing that guard exists to
prevent. `--remove` is correct. Capture `had_login=1` before the `rm -rf`.

### 7.6 `removepkg "staxx-"*` probably does not remove the package record

**`staxx.plg` ~144–145.** The glob expands against the current working directory, which is
unspecified for a plugin script, not against `/var/log/packages`; unexpanded, `removepkg` is
handed a literal and finds nothing, and `removepkg` does no globbing of its own. The following
`rm -rf` cleans the files so removal looks successful, while a stale record stays in the
package database and a later `upgradepkg` sees a version that is not installed. Use
`removepkg /var/log/packages/staxx-* 2>/dev/null || true`, or the `&packageName;` entity.

### 7.7 Two latent packaging hazards

- **`dev-install.sh` ~110–116** runs `sed -i 's/\r$//'` over **every** file with no filter. No
  binary assets exist today, so nothing is harmed — the first icon, font or screenshot added
  under the plugin folder is silently corrupted by a dev install. Restrict to text extensions
  or skip what `grep -qI` calls binary.
- **`pkg_build.sh`** has no CR strip. Not a live problem (see the corrections at the top), but
  the dev installer strips defensively and the packager should too — it is the artefact a user
  installs. Add the same pass before the permission pass, skipping binaries.
- **`.gitattributes`** has no `*.tmpl` rule; `Docker.page.tmpl` falls through to `text=auto`.
  It resolves to LF today, and it is the one file destined to *become* a `.page`, where a CRLF
  is fatal. Add `*.tmpl text eol=lf`.

### 7.8 Both view pages can be live at once

**`Stacks.page` ~5** (`dockerd.pid && !header_menu`) vs **`StaXX.page` ~9**
(`fsState=='Started' && dockerd.pid && (header_menu || takeover_docker_tab)`). Nothing couples
the two settings — `Settings.php` ~48–49 treats them as independent — so with takeover **on**
and header **off**, both are true. `/Docker/Stacks` stays routable while the Docker menu it
hangs off has been shadowed away, and `staxx.settings.page` ~26 picks its link off
`HEADER_MENU` alone, sending the user to that orphan. `CLAUDE.md`'s "exactly one is ever live"
does not hold.

Add `&& !file_exists(…/takeover_docker_tab)` to `Stacks.page`'s `Cond`, make `$stacksUrl` test
the takeover marker, and use the **same** array-state gate in both pages (today only one gates
on `fsState`, so neither page is live when the array is stopped but Docker is up). Use
`($var['fsState'] ?? '')` — an unguarded array read inside a `Cond` warns on every render of
every page in PHP 8.

Also: **`staxx.settings.page` ~63** reads `$cfg['STACK_ROOT']` with no `??`, unlike the two
reads above it. If `default.cfg` ever becomes unparseable — the failure the file's own header is
dedicated to — that is a warning plus `htmlspecialchars(null)` on the one page that must still
work.

**`sheets/StaXX.css` — deliberately not added, decided 2026-08-21 during implementation.** The idea
was that PageBuilder's name-based auto-load may 404 once per page load in the header-menu
configuration, and an empty file would settle it. It cannot be done safely from this machine: the
development checkout is on a case-insensitive filesystem, so `StaXX.css` and the existing 8,274-line
`staxx.css` are the *same file* here. A first attempt truncated the real stylesheet, and forcing the
name into git's index instead left a phantom entry whose content git read from `staxx.css` — a commit
away from shipping two full copies of the stylesheet under two names. Both pages already emit their
stylesheet tags explicitly, with `filemtime()` cache-busting, so the auto-load is not how the styling
arrives; a possible cosmetic 404 is a far smaller cost than a case collision nobody can see on the
machine the code is written on. Leave it.

### 7.9 The AMD sampler contradicts its own file

**`stats-collector.sh` ~317–325.** The 12-line comment at ~202–213 explains that
`intel_gpu_top` was removed because repeatedly attaching a performance monitor to a card and
killing it hard is a credible cause of the GPU freezes seen. `sample_amd` does exactly that —
`timeout -k 1 5 radeontop` every round, for as long as a tab is open — and its numbers are
described elsewhere in the same file as unreliable (0% during a real encode, which is why
`sample_gpu_metrics` exists). Drop it, or gate it behind a setting that is off by default.

Same file, low: `INTERVAL=1` at ~29 is dead and its comment refers to a `sleep` that no longer
exists; the raw/prev swap at ~161–162 and ~261–262 leaves a window with no `.raw` at all
(cosmetic graph flicker — copy rather than move); `cleanup()` never removes `*.raw.tmp`;
`VERSION=$(stat -c %Y "$0")` is unguarded at ~95 (and `events-watcher.sh` ~91) so an empty
value exits after one round, silently.

---

## Phase 8 — performance (run after 5 and 6)

### 8.1 The big one: the stack tree is walked ~200 times per render

`staxx_scan_stacks()` (`Stacks.php` ~698) has **no** request-scoped cache — the statics in that
file cover only docker/compose output. Each `staxx_list_stacks()` costs, per stack: up to four
`is_file` in `staxx_find_compose_file`, two more in `staxx_compose_files`, a `scandir` for
`staxx_review_file`, a **second** `scandir` for `staxx_handover_file`, plus
`staxx_foreign_holders`.

`staxx_updates_for_row()` (`Updates.php` ~1159) opens with `foreach (staxx_list_stacks() …)`
just to find one file path, and is called once per stack row and once per container row
(`StacksTable.php` ~864, ~1035, ~1283). `staxx_updates_for_folder()` adds M+1 per folder
(~1240); `staxx_update_clock()` adds one per pending service (`UpdateRun.php` ~260).

On the 64-stack server the code's own comments cite, that is roughly 200 full tree walks — tens
of thousands of `stat`/`scandir` calls against vfat. For calibration, `staxx_state_snapshot()`'s
docblock measures **316 ms** for merely one compose-meta read per stack.

Add a request-scoped static to `staxx_scan_stacks()` with explicit invalidation in the mutating
paths (save, delete, folder move, rename, import), **or** build one `name => file` map before
the row loop and pass it down. Prefer the map if invalidation looks error-prone — a stale cache
here is worse than the cost.

Also memoise `staxx_review_file()` / `staxx_handover_file()` per directory, which removes the
two `scandir`s from the hot path on their own.

### 8.2 The cheap poll pays twice for the same answer

`StacksTable.php` ~1497 recomputes what ~1506 already has in `$mine`; the same duplication at
~435 vs ~1228. `staxx_state_snapshot()` — the function whose docblock insists it must stay
fast, polled every few seconds — also pulls a whole extra `staxx_scan_stacks()` via
`staxx_folder_names()` at ~1566.

### 8.3 Four near-identical `docker ps -a` reads per render

`Defines.php` ~659 (`staxx_containers_by_project`), `Stacks.php` ~1384
(`staxx_container_index`), ~2843 (`staxx_docker_container_names`), ~1839 (`ps -aq | xargs
inspect`), plus `UpdateRun.php` ~520. Each is individually memoised — good — but
`StacksPage.php` ~28–29 triggers three in consecutive lines. Derive them from one shared read.

`staxx_import_projects()` and `staxx_device_claims()` also lack the per-request memoisation
their siblings have.

### 8.4 Unbounded reads and unbounded `/tmp`

`Stacks.php` ~4056, ~4221, ~4652 all do `file_get_contents($file, false, null, $offset)` with
no length cap, and the `job` batch form accepts up to 64 ids in one reply (`action.php` ~419) —
so a first poll at offset 0 against a long `pull` returns every log whole. Pass a cap (256 KiB)
and advance the offset by what was actually read.

A `compose logs --follow` log file has **no size ceiling** and `/tmp` is RAM on Unraid; it is
removed only on stale heartbeat or 1 h mtime, and an open tab refreshes the heartbeat
indefinitely. Add a size ceiling to `staxx_log_reap()` that kills a follower which passes it.

### 8.5 Browser-side

- **Both drag paths** (`stacks.js` `gripSiblingUnits` ~15225, port drag ~4411) re-run
  `querySelectorAll` plus a rect/offsetHeight read per row on **every** `dragover`, though
  nothing moves until the drop. Compute once on `dragstart`.
- **`buildForm()`** runs three O(F²) passes — the `service_healthy` scan (~2776–2792), the
  `container_name` × `deploy.replicas` scan (~2800–2820), and `resolveNetDriver` (~2390) once
  per attached network per service — and is re-run on every committed edit (`setPart` ~3477 →
  `refreshRanges` ~3489 → ~3366), on top of a full re-parse. On a 20-service stack (~1,500
  fields) that is millions of iterations per commit. Build one `id → field` map and one
  `service → fields` map at the top and index both checks off them. `fieldById` is also a
  linear scan per call.

### 8.6 The icon cache grows for ever, on flash

`Icons.php` ~472 keys local icons as `local-md5(path|mtime)`, so every time Unraid re-downloads
a container icon a new copy is written **to the flash device** and the old is orphaned. No
eviction exists anywhere in the repo. Prune entries untouched for N days during the icon sweep.

`Icons.php` ~543–546 also returns early for an unresolvable local ref, skipping
`staxx_icon_mark_missed()`, so a failed local copy is retried on every page's first sweep.

---

## Phase 9 — anything reached over the network

### 9.1 A registry can point the server anywhere

The realm is parsed out of the remote registry's own `WWW-Authenticate` header and passed verbatim
to `staxx_hub_json()`, which runs `curl -fsSL` (`Defines.php` ~287) with no scheme restriction — so
`file:///…` and internal addresses are reachable. A compose file naming a hostile registry can make
the server read a local file or hit a metadata address.

**Corrected 2026-08-21, during implementation: there is one site, not two.** This plan and §13.4
both said two, giving `include/Updates.php` ~219–236 and ~218–236 — the same physical block, counted
twice. PLAN_55 Part A merged its tags lookup *into* that function rather than adding a second one, so
one guard covers every caller. Grepping the whole tree for `realm`, `tokenUrl` and
`WWW-Authenticate` confirms it: the only other two token fetches, in `staxx_registry_config()` and
`staxx_image_remote()`'s `hub` branch, both hardcode `auth.docker.io` and never read a realm.

Require the realm to match `^https?://` before use, and add `--proto '=https,http'` to
`staxx_hub_json()` so the transport is bounded regardless of any future caller.

### 9.2 Remote SVGs become same-origin files

**`include/Icons.php` ~529–561**, reached from `Import.php` ~706 via the catalogue feed's `Icon`
field (`scripts/ca-index.php` ~275). `staxx_icon_wanted()` is correctly server-derived — no
client-supplied URL, which is right — but the feed itself is remote and untrusted. Its URL is
fetched with no host or IP restriction and stored under the plugin's icon folder, served
same-origin at `/state/…`. `staxx_icon_is_picture()` accepts any SVG containing `<svg` and
lacking `<html>`, and an SVG carrying `<script>` executes if the URL is opened directly.

Refuse SVG from a remote URL, or strip `<script`, `on*` and `<foreignObject`.

**Same file ~372:** `CURLOPT_MAXFILESIZE` is only enforced when the response declares
`Content-Length`, so a chunked reply bypasses the 2 MB cap and writes an unbounded file **to
the flash device**. Add a write-callback size guard alongside it.

Also ~539–540 guesses the extension from the URL and defaults to `png`, so a valid SVG at an
extension-less URL fails the picture check and never caches.

### 9.3 The image-inspector probe is cached for ever

**`include/Updates.php` ~115–135.** The first successful probe is written to state and
short-circuits every later call with no TTL, so a transient `buildx` failure just after install
permanently downgrades the route to the slower path; recovery needs a hand edit. Give it the
same TTL as the digest cache.

### 9.4 The catalogue lock lives inside the directory it protects

**`include/CA.php` ~40** (`STAXX_CA_LOCK = STAXX_CA_DIR.'/.lock'`) vs `scripts/ca-index.php`
~587–589: the swap `rename(CA_DIR, $oldDir); rename($newDir, CA_DIR)` carries the lock away and
`rmtree($oldDir)` deletes it, so the lock is released *before* the new cache is in place. Both
`rename()` calls are unchecked — if the first succeeds and the second fails the cache directory
is gone entirely while `status.json` is about to be written `ready`.

Move the lock to `/tmp/staxx/ca.lock`, outside the swapped tree, and check both renames.

`CA.php` ~72 also does `array_merge($empty, $decoded)` then `count(...['apps'])` at ~99 with no
shape check, so a corrupt `/tmp` file with `apps` as a scalar is a PHP 8 `TypeError` fatal.

### 9.5 Smaller

`UpdateRun.php` ~468–555 builds its keep-set from the state file, which only catches up after a
job finishes, so cleanup can delete an image an in-flight job just pulled (narrow window — skip
cleanup while the queue has a running or waiting item). `Defines.php` ~620 calls Docker Hub even
for `source === 'local'`. `UpdateRun.php` ~931 indexes `$dockerfile[0]` with no empty guard.
Header-only registry probes omit `-L`. `apply_settings` ~110 `docker login` can replace an
administrator's own Hub credential (the marker correctly prevents logging *out* a session StaXX
did not create, but not this) — note it in the settings help, or skip when a docker.io
credential exists with no marker.

---

## Phase 10 — the schema does not enforce what it appears to

Verified with a real Draft 2020-12 validator: `format: "uri"` is **not enforced** — `uri` is
absent from `FormatChecker.checkers` without the optional `rfc3987`/`rfc3986-validator`
package, which is not installed. Stack-level address keys also have no `pattern` of their own,
unlike their service-level twins. All of these validate clean:

```
project:   "javascript:alert(1)"     ACCEPTED
readme:    "not a link"             ACCEPTED
icon:      "../../../etc/passwd"    ACCEPTED
overvieww: "typo"                   ACCEPTED
```

The two "not a URI" negative cases in `validate_schema.py` pass only because of the pattern on
the *service*-level `project`/`support`, so the harness reads as stronger than the schema is.
The render side does gate `href`s on `^https?://`, so this is not a live hole — but the schema
is not the guard it looks like.

1. Add `"pattern": "^https?://"` to the three stack-level address keys, and a shape pattern to
   `icon` and `webui`. **Note the asymmetry's origin:** PLAN_55 Part B added the *service*-level
   `project` and `support` already carrying that pattern, so this item is bringing stack level up to
   the standard the newer keys already meet — not introducing a new convention. Do not re-pattern the
   service-level keys.
2. Stop relying on `format` for enforcement; keep it as documentation only, and say so.
3. `validate_schema.py` — drop the misleading `format_checker=FormatChecker()` comment about
   draft 2020-12, and add negative cases that now genuinely fail.
4. **Judgement call, not a bug:** `additionalProperties` is absent from `$defs/stack` and
   `$defs/service`, so `overvieww` and `webUI` both validate. That is deliberate and asserted by
   two positive tests (tolerating a leftover `name:`). The cost is that no typo in any metadata
   key is ever caught. A `propertyNames` pattern would keep the forward tolerance without the
   silence — decide explicitly, then record the decision. `$defs/update` does set it, and its
   negative cases genuinely fail.

Also `harvest` (~1883) and `checkSpecKeys` (~5173) in `compose-model.js` skip **any** `x-` key,
so `x-unriad:` (typo) is silently accepted everywhere while the schema defines only `x-unraid`.
Everything the code actually reads matches the schema — no real mismatch was found.

---

## Phase 11 — tests the fixes need

### 11.1 Move the corpus into the repository

The round-trip suite walks a fixture corpus in a gitignored scratch folder; a clean clone has
two example files, and the fixtures deliberately named as the hard cases (the YAML-quirks file,
the deliberately-broken one) are the missing ones. So the headline number is not reproducible
by anyone — not by a contributor, not by whoever reviews the plugin, not on a new machine.

Move them under `tests/fixtures/`. This is the single highest-value test change here: the
project's most important promise is currently proven by something nobody else can run.

### 11.2 Cases that would have caught the findings above

| Case to add | Catches |
|---|---|
| `insertChild`/`addSetting`/`addNested` into a **4-space** file, asserting the result re-parses | 1.1, 4 |
| `emitScalar`/`setPart` writing a value containing `\` into a `'double'` spot | 1.2 |
| Two `environment` entries with the same name; two ports sharing a container port; two mounts sharing a container path — assert **distinct** field ids | 1.3 |
| A stack directory that **is** a symlink — archive refuses, target survives, not listed | 1.4 |
| A **multi-line plain scalar**, asserting the services *after* it still appear | 2 |
| A mapping with **inconsistent sibling indents** (4 and 3), asserting a warning or seal rather than silent truncation | 2 |
| Block-scalar variants `\|-`, `\|+`, `>-`, `>+`, and an explicit indicator (`\|2`) | hardening |
| A block scalar whose body contains a line starting with `#` | `blockEnd`/`flatOf` |
| Bare `yes`/`no`/`on`/`off`, `1.10`, `0755`, `*` as **values** through `emitScalar` | quoting |
| A value containing `\n` handed to `setValue` | export API |
| Non-ASCII values through parse → edit → serialise | none exists |
| `${VAR}` / `${VAR:-x}` inside a **ports/volumes short-form** entry | `splitOutsideVars` |
| `labels` in **both** list and map form (env has this, labels does not) | `harvestPairs` |
| A comment at **end of file**, as a named case | `leadOf`/`spliceBlock` |
| A large-file parse-cost bound (only a regex-hang guard exists) | 8.5 |

### 11.3 Add the two missing files to `js_undeclared.js`

`tests/js_undeclared.js` ~23–27 covers `stacks.js`, `compose-model.js` and `manage.js`.
`ca-convert.js` and `image-import.js` are strict-mode IIFEs with the same failure mode and are
not covered. Both are clean today; the gap is that nothing keeps them so.

### 11.4 Server-side

`tests/server/links.php` — the symlinked-stack-directory case (1.4). Add a `files.php` case for
the fingerprint refusal (3.1) and one asserting the compose file lands at `0600` on a non-flash
root (3.3). `tests/server/autostart.php` — a scaled service with one container in the boot
list, asserting the other two are not added (6.4).

### 11.5 Importer fixes, with cases

- **`ca-convert.js` ~695** — `if (app.Repository)` is not guarded by `scalarPresent()`. The
  file's own docblock (~214) explains that an empty XML element arrives as an empty **array**,
  which is truthy, and that the identical bug on `PostArgs` "would have hit 83 of 85 real
  templates". `Repository`, `Project`, `Support`, `ReadMe`, `Author`/`Repo` all use bare
  truthiness. An empty `Repository` emits `image:` with no value *and* suppresses the "no image
  name" note. Use `scalarPresent()` + `scalarOut()`.
- **`ca-convert.js` ~62/~51** — `isSafeBare()` tests only leading/trailing whitespace and
  `dq()` escapes only `\` and `"`, with no newline collapsing (unlike `buildComment` and
  `cleanOverview`, which both collapse after entity-decoding). A `Default` containing a real
  newline emits a quoted scalar broken across two lines. Collapse or reject `[\r\n]`.
- **`ca-convert.js` ~79** — `keyOut()` passes YAML keywords bare, so a variable literally named
  `yes`/`no`/`on` is emitted unquoted. Harmless under compose's loader, wrong under a 1.1 one.
- **`image-import.js` ~368** — `correctPathLine` does not normalise `opts.appdata` the way
  `buildConfigRoute` ~196 does, so `/mnt/user/appdata` yields `/mnt/user/appdataconfig`. It also
  rewrites `/path/to/` on the **container** side of a mount, not just the host side.
- **`image-import.js` ~325/~345** — `ENV_MAP_RE` uses `:\s?` (one optional space), so an aligned
  `PUID:   1000` gets no PUID/PGID correction and no note — the container silently runs as uid
  1000 and cannot write to the shares. Use `:[ \t]*`.
- **`image-import.js` ~381** — `SECRET_KEY_RE` (`/PASS|SECRET|TOKEN|KEY/i`) is tested against
  whatever `ENV_MAP_RE` matched, and that matches *any* `key:` line, so a README block
  containing a bare `secrets:` produces *"The setting "secrets" has no value…"* as a permanent
  comment in the file.
- **`image-import.js` ~73/~84** — two `var slash` declarations in one function scope. Legal and
  shadow-free; tidy while there.

### 11.6 Note on an existing assertion — leave it alone

`tests/ca_convert.js` ~289 filters `doc.sealed` down to `reason !== 'block-scalar' && reason
!== 'escape'`, i.e. it **accepts** that `dq()` output containing a backslash comes back sealed.
That is a deliberate accepted cost (the field renders locked) and is **not** the same thing as
finding 1.2, which is compose-model writing an unescaped backslash *out*. Do not "fix" the
filter as part of 1.2; revisit only after 1.2 lands, and expect the sealing to become
unnecessary rather than the assertion to become wrong.

---

## Phase 12 — the paperwork

Cheapest, highest-return hour in the plan. Anyone assessing this project from its documents
would conclude almost nothing works.

1. **`staxx.plg`** — version stamp is `2026.08.09`, `<CHANGES>` has one entry, and the install
   banner still says *"pre-alpha scaffold — no stack management yet."* That banner is the last
   thing a user reads before installing. The `TODO-` placeholders (author, repo, MD5, SHA256,
   support) stay as they are — a premature publish must still fail loudly.
2. **`CLAUDE.md`** — "`staxx_compose_meta()` already parses `x-unraid` blocks; **nothing renders
   them as a form yet**, and that renderer is the whole point of the project." The renderer
   exists and is the largest piece of engineering in the repo (22 field groups). Also fix the
   claim that exactly one view page is ever live (7.8), and the statement that nothing beyond a
   syntax check can run locally — node and python are both present and every JS/schema suite
   runs here.
3. **`StacksPage.php` ~965** — *"Not found. Unraid does not ship compose; StaXX will install
   it."* Either build the install or say exactly how to get it. The false promise has to go
   either way; see the feature note below.
4. **`README.md`** — "Planned scope" still lists as planned several things that are built.
5. **`default.cfg` ~4** — says values here "are read directly by `.page` Cond expressions". They
   are not: `apply_settings` projects two of them onto marker files precisely so `Cond` never
   parses this file. Comments must describe what is.
6. **`SUBMISSION.md`** — the "line endings are correct throughout" line is true; leave it. Add
   the install banner to its list, which currently names only the changelog.
7. **`Autostart.php` ~360–367** — the "foreign lines keep their place" comment (6.4).
8. **`Settings.php` ~371** — the `chmod($tmp, 0600)` comment is right about intent but the mode
   is a no-op on vfat; the token is protected by the mount, not by that call.

---

## Phase 13 — leftovers inherited from PLAN_55 and PLAN_59

Both earlier plans were verified item by item against the code on 2026-08-21, rather than trusted.
PLAN_59 is complete bar one defect; PLAN_55's approved half is complete bar two small items. Those
three land here so there is one list of outstanding work, and the two plan files now point at this
phase instead of describing it themselves.

**PLAN_59 result:** 14 of 15 sub-items genuinely implemented — uninstall clears the flash cron file
and re-runs `update_cron`, the config is `0600` in all three writers, the takeover branch and the
installer's `apply_settings` call are both non-fatal, the SHA256 entity/element and build stamping
are present, and the README is staged by both the packager and the dev installer. All four of its
own verification checks pass (`bash -n` ×3, plus the XML parse).

**PLAN_55 result:** Part A (withdrawn tag) complete and tested end to end. Part B (project links)
complete bar 13.2 and 13.3. Part C (chips) shipped, with two deliberate deviations — see 13.4.
Part One (registry-move detection) is entirely unstarted and stays out of scope; see the list below.

### 13.1 The purge never signs out of Docker Hub

Already written up as **item 7.5** — do it there, not twice. Recorded here only so PLAN_59's single
remaining item is visibly accounted for. Confirmed by reading the file: `dev-install.sh` deletes the
config folder at ~61 and only then tests `[[ -f "${CFG_DIR}/hub_login" ]]` at ~73, so on `--purge`
the marker is already gone, the branch can never be true, and the Hub session StaXX itself created
stays signed in. `--remove` is correct, and `staxx.plg` does not share the bug — it never deletes
the config folder. `update_cron` does still run after the deletion, which is what PLAN_59 required.

### 13.2 Imported apps get their links written once for the whole stack, not per container

**`javascript/ca-convert.js` ~784–787.** The service-level `x-unraid:` block emits `webui` only.
`project`, `support` and `readme` are written at stack level only (~795–797). So a three-service
stack shares one project page, which is exactly what PLAN_55's decision 4 and its service-level
schema keys exist to avoid — and the schema, the docs, the resolver, the endpoint and the chips are
all already built and waiting for it (`schema/x-unraid.schema.json` ~73–74 defines service-level
`project`/`support` with an `^https?://` pattern).

Write them per service when a value is known. For a catalogue app the values are already on `app`;
a non-CA import has no resolver in the browser, so leave those to the existing on-demand
*Find the project link…* action rather than growing a second resolver.

**Do this together with item 11.5's first bullet — it is the same three lines.** `app.Project`,
`app.Support` and `app.ReadMe` are tested with bare truthiness, and an empty XML element arrives as
an empty **array**, which is truthy. The file's own docblock (~214) explains that the identical bug
on `PostArgs` "would have hit 83 of 85 real templates". Use `scalarPresent()` + `scalarOut()` for
both the stack-level and the new service-level writes.

### 13.3 The link write is not tested on the file that matters

`tests/yaml_roundtrip.js` covers `addNested()` inserting above an **existing** `x-unraid` block
(~676–691, ~944–954) and creating `x-unraid.webui` where none exists (~1513), so the primitive is
proven. PLAN_55's named scenario is not: a hand-authored file with comments, ordering **and
anchors**, and no `x-unraid` block at all, coming back byte-intact after a `project` insert.

Add it to Phase 11.2's table. This is the round-trip rule applied to the one write path that
inserts into someone's file without the user having asked for that line — worth a named case.

**Sequencing, proven by running it 2026-08-21 — this test must not be written before Phases 1 and
4.** `addNested()` → `ensurePath()` → `insertChild()`, so PLAN_55's link write runs straight through
both the two-space indent bug (1.1) and the no-rollback bug (4). On PLAN_55's own named scenario —
a 4-space hand-authored file with comments and an anchor, no `x-unraid` block — *Find the project
link…* today does this:

```yaml
services:
    web:
        image: nginx           # the web front end
        environment: &common
            TZ: Europe/London
      x-unraid:                 # <- orphaned at six spaces, and no project line
    api:
        image: api:1.0
        environment: *common
```

`addNested` returned `-1`, so the user is told the write failed — and the half-built line was left
behind anyway. Re-parsing the result reports services as `['0']` with **no warnings**, so the `api`
service silently disappears from the form.

That means Part B's shipped feature is **already damaging 4-space files today**, from a menu item
that exists in the UI. It is a live instance of 1.1 + 4 rather than a new bug, so it needs no
separate entry — but it does fix the order: Phases 1 and 4 first, then this test, then 13.2.

### 13.4 How this plan and PLAN_55 avoid breaking each other

PLAN_55 was written against the code as it stood; this plan changes some of that code. The exposure
runs **both** ways, and the reverse direction is the bigger risk because it is the one nobody looks
for: PLAN_55's *shipped* code sits inside this plan's blast radius.

**Rule: PLAN_55 is not re-planned. This plan owns every interaction.** PLAN_55's only remaining work
is 13.2 and 13.3, which already live here, so there is nothing left in that file to keep in step.
Part One is unstarted and gets a forward-looking note instead (below).

#### What this plan must not break — check each after the phase lands

| PLAN_55 code | Threatened by | Check |
|---|---|---|
| *Fix the tag…* opening the editor on a service's image field | **1.3** (field ids gain an index) | It addresses the field by service + key (`'image'`), not by a composed id, so it should be unaffected — but `image` is a `setting` binder and 1.3 only touches `port`/`volume`/`device`/`env`/`label`. Confirm no hand-built id strings in the two menu builders assume the old shape. |
| *Find the project link…* → `addNested()` | **1.1** and **4** | Already broken today (see 13.3). After both land, re-run the scenario in 13.3 and require a correctly-indented insert and a byte-identical file on refusal. |
| `staxx_registry_tags()` realm handling | **9.1** | Corrected during implementation: this is the *only* realm-parsing site, not a second one — PLAN_55 merged its tags lookup into the existing function. One guard covers it. See §9.1. |
| Service-level `project`/`support` schema keys | **10** | Already carry `^https?://`. §10 brings stack level up to match; it must not re-pattern these. |
| `staxx_links_ca_map()` reading the catalogue index | **9.4** (lock moves, renames checked) | Map is built once per request from the index data; a relocated lock does not change that. Confirm the map still builds when the index is mid-swap — it should return empty, never a partial map. |
| Repo/CA chip data via `staxx_stack_children()` | **8.1** (memoised tree walk) | The chips read `$declared`, already in hand, with no extra metadata call. Confirm memoisation does not serve a stale `project` after a link write — the write goes through the ordinary `save`, so invalidation must cover it. |
| `tests/ca_convert.js`'s sealed-reason filter | **1.2** | See 11.6 — leave the filter alone; it is a read-side behaviour and 1.2 is a write-side fix. |

#### How 13.2 and 13.3 must be specified, given the fixes

- **13.3 is written against post-fix behaviour, not today's.** Assert the insert *succeeds* with the
  indent taken from the parent's children, and that a refusal leaves the file byte-identical. Written
  before Phases 1 and 4 it would fail — and the real danger is that it gets "adjusted until it
  passes", which would bake today's corruption into the suite as the expected result.
- **13.2 lands in the same change as 11.5's first bullet.** Both edit the same three lines, and
  `scalarPresent()`/`scalarOut()` are what 11.5 introduces there. Doing them separately means writing
  the per-service keys with the truthiness bug still in place, then editing the same lines again.

#### The registry-move feature — now sequenced behind this plan by design

PLAN_55's unstarted half has been lifted out to **`FEATURE_55.md`** (concept, measurements and
decisions) with its code plan reserved as **`PLAN_61.md`**, deliberately not written yet. That
ordering is the fix for the staleness problem rather than a warning about it: its decision 7
(*rewrite the one `image:` line through the compose model*) depends on the very write path Phases 1
and 4 correct, so drafting it now would specify a write that stops existing — and would be drafted
against a writer that currently corrupts any 4-space file.

When `PLAN_61.md` is written, the contract to write it against is: indentation comes from the
parent's existing children, a failed insert rolls back rather than leaving half a line, and a value
containing a backslash is emitted single-quoted. Nothing else in that feature is affected, because
everything else in it only reads.

### 13.5 Decide the two chip deviations, then correct the plan text

The Repo and CA chips shipped differently from PLAN_55's decisions, in both cases with a comment
explaining why:

| Plan said | Code does | Stated reason |
|---|---|---|
| Service rows only (decision 4) | Both call sites — service rows, **and** stack rows deriving from a sole child or greyed with *"More than one container here has its own project page…"* (`StacksTable.php` ~998–1000, ~1148) | not recorded |
| Omitted when there is no URL | Rendered as a disabled `staxx-webbtn--off` | *"so the icon column never reflows"* (~381) |

The two-column chip grid is also unconditional (`sheets/staxx.css` ~532, applied at
`StacksTable.php` ~409) rather than "when there are more than two chips", which follows from the
widening: every row now always carries four chips.

**Decided 2026-08-21: both stay exactly as shipped. This is a documentation task only — no code
change.** The stack-row chip is wanted deliberately: a link that belongs to the stack as a whole is
how a stack can point at where its compose template came from, which matters if compose templates
ever get a repository of their own. That is a direction, not a plan — nothing is to be designed for
it yet, and this note exists so the chip is not "tidied away" later by someone reading decision 4
and assuming it was a slip.

So the only work here is to correct PLAN_55's decision 4 and its Part C section to describe what
actually ships. A plan that disagrees with the shipped code is worse than no plan.

---

## Out of scope — deliberately not in this plan

These came out of the review and are real, but they are features or refactors rather than
fixes. They belong in their own plans, in roughly this order of value:

1. **Install the compose command.** Without it nothing runs on a stock server. Ranked first of
   everything not in this plan — item 12.3 only removes the false claim.
2. **A first-run screen with three doors** — browse the catalogue, bring in what you already
   run, paste a file. Today's empty state offers only the hardest of the three.
3. **Restore from the archive list.** The zip is already kept and already listed; recovery is a
   hand-unzip the target user cannot do.
4. **Make the friendly bits editable** — icon, overview, project and support links. Today only
   the importer can author the metadata that makes a form pleasant.
5. **Show the 134 field-help strings already written.** The form shows none of them.
6. **A search box over the stacks table.** Nothing exists today; felt on every visit.
7. **Keep the previous version of a compose file, once.** Note the trap: a stack is a folder
   containing a compose file *and nothing else*, so the kept copy must live in the temp or
   archive area keyed by path, never beside the file.
8. **"Show me what will change" before Restart or Recreate.** Compose can answer it without
   touching anything.
9. **A Download button on a stack** — makes the portability promise visible.
10. **Split `stacks.js`.** Not urgent, but it is the main thing that would deter a second
    contributor. ~5,000 of its 16,600 lines are comment, so it is ten well-separated subsystems
    sharing one scope rather than sprawl. The five that would come out with almost no glue —
    the Apps browser (boundary at the `/* ---- Import ---- */` banner ~7094), the importer, the
    form-rendering block (~1246–3100), the grip-drag block (~15025–15303), and the timezone and
    device pickers — total roughly 6,000 lines. `manage.js` already demonstrates the pattern
    that works: one `window.Staxx*` object with a small declared contract.
11. **Translations.** The wrapper is used in the two rendering files only (127 and 62 calls);
    every user-facing string in the model layer is untranslatable and `langs/` is empty. Matters
    for an eventual upstream PR, not before.
12. **Registry-move detection — noticing when a publisher has left Docker Hub.** A feature, not a
    fix, so it is deliberately not folded in here. Its concept, measurements, decisions and risks
    live in **`FEATURE_55.md`**; its code plan becomes **`PLAN_61.md`**, to be written **after this
    plan has landed** so it can be specified against the corrected write path rather than against
    behaviour Phase 1 removes. Two gates before it starts: decision 10 in that document still needs
    Adrian's word, and this plan must be in. Honest scale, measured on the live server: three
    affected images out of seventy — the argument is that the two publishers involved are among the
    largest still on Docker Hub, so the number only moves one way.

13. **A number-shaped value is written bare, and compose may not read it back as itself.** Found
    2026-08-21 while adding Phase 11's quoting cases. `emitScalar` deliberately writes a plain number
    unquoted — the comment there explains why, and it is right for an ordinary number. But `1.10`,
    `0755`, `007` and `.5` are all written bare too, and while *our* parser hands them back
    unchanged, a YAML loader is entitled to read `1.10` as the number 1.1 and `0755` as octal. A
    version pin or a file mode typed into a text box could therefore mean something else by the time
    compose reads it. Left out of PLAN_60 on purpose: the fix is a narrow extra quoting rule, but
    which values actually change meaning depends on compose's own loader, and that can only be
    settled by testing on the server rather than reasoned about here. Phase 11 added a case that
    documents today's behaviour, so the gap is recorded rather than silent.

**Rejected, recorded so they are not revisited casually:** a markdown library for catalogue
descriptions (third-party code in a page that has none, and it forfeits today's guarantee that
the catalogue cannot inject markup — the half-hour version is to strip the stray marks on the
cards); per-stack notes, tags, favourites or a saved dashboard layout (all want a sidecar or an
index, which the whole model exists to avoid — put remembered state in the plugin's own
settings); a second boot-order list; real text folding in the editor. Also worth raising with
Lime Technology before many files rely on it: `x-unraid` as the key name, since if they ever
define it differently, files in the wild mean two things.

---

## Definition of done

- All seven local checks pass, plus the new cases in Phase 11.
- The server-side suites in `tests/server/` pass on the box, with the new cases.
- `php -l` clean over `include/*.php` after deploy.
- A 4-space compose file survives adding a setting, a nested block and a section, and
  re-parses to the same form.
- A value containing a backslash round-trips and the field is still readable afterwards.
- A file with an unreadable line reports it, and loses nothing.
- A symlinked stack folder cannot be archived, and is not listed.
- Two tabs cannot overwrite each other, and the compose file is owner-only.
- Nothing in Phase 12 still describes a feature that exists as unbuilt.
- A purge signs out of Docker Hub (the one item PLAN_59 had left).
- An imported multi-service app carries a project link per container, and an empty template field
  writes no key at all rather than a bare one.
- A hand-authored file with comments and anchors and no metadata block survives a link write intact.
- PLAN_55's decision table matches the chips that actually shipped.
