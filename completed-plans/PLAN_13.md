# PLAN_13 — Files that live alongside the compose file

**Status: complete.** All five phases built, deployed and checked in a browser on the test box,
2026-08-15. The round-trip suite stands at **939 cases, none failing** — 884 when the plan started,
29 of the rise from new cases for `fileRefs()` and `varRefs()` and the rest from the two fixture
stacks added below, since the suite walks the fixture corpus and that corpus is not in git. Two new
server-only PHP checks live under `tests/server/`. Nothing is committed — the working tree is left
dirty for review.

Six things came out differently from the plan; each is noted in place below and gathered under
"What was built differently" at the end.

A stack is currently only a compose file. Real stacks need companions — a `.env`, a secret, a config
snippet — and there is no way to get one onto the server through the plugin, no way to edit one, and
no way for the compose file and those files to know about each other.

There is also a trap waiting. `staxx_delete_stack()` (`Stacks.php:1333`) refuses to delete any
stack whose folder holds anything beyond the compose file and a `.env`. That guard is right today and
becomes a wall the moment companion files exist, so it is dealt with in the first phase, before
anything can create one.

## Phase B1 — Files on disk, and fixing delete

### A filename validator, separate from the stack one

`staxx_valid_name()` (`Stacks.php:53`) requires a name to start with a letter or digit, so it
rejects `.env` outright — correct for a directory name, wrong for a file. Companion files get
`staxx_valid_filename()`: an optional leading dot, then the same safe set (`A-Za-z0-9._-`), no
slash, no `..` anywhere, 1–63 characters, and **not** one of `STAXX_COMPOSE_FILENAMES`
(`Stacks.php:33`) — a second compose file in the folder would silently change which one the stack
runs.

### Five helpers in `Stacks.php`

Each takes the stack's relative path, runs it through the existing `staxx_valid_path()`, then
confines the result with `realpath()` the way `staxx_browse_dirs()` already does (`:143`).

| Helper | Does |
|---|---|
| `staxx_list_files($rel)` | Every entry: name, size, mtime, is-compose, looks-like-text, is-directory |
| `staxx_read_file($rel, $file)` | Text as-is, or base64 for a binary |
| `staxx_write_file($rel, $file, $body)` | CRLF→LF for text, `chmod 0644`, temp file + `rename()` so a reader never sees half a file |
| `staxx_delete_file($rel, $file)` | One file |
| `staxx_rename_file($rel, $from, $to)` | Within the folder only |

"Looks like text" is the first 8 KB holding no NUL byte — what `git` does, and right often enough.

### Delete asks instead of refusing

- `delete` without `confirm=1`, when the folder holds more than the compose file, returns
  `{ok:false, needsConfirm:true, entries:[…]}` — every file with its size, every subdirectory with
  how many files it holds.
- The client lists it, and names anything unexpected in its own sentence so it gets read rather than
  scrolled past. The folder is named. The OK button is not focused by default.
- `delete` with `confirm=1` removes the lot, subdirectories included, guarded by a `realpath()`
  containment check on every entry and by skipping symlinks outright so it cannot walk out of the
  stack folder.

This is the most destructive thing in the plugin, so the confirmation wording is not decoration — it
is the only protection left once the old guard is gone.

### New actions

`files`, `file-read`, `file-save`, `file-delete`, `file-rename` — cases in the one switch in
`action.php`, POST-only like everything else. `delete` gains `confirm`.

## Phase B2 — The tab strip

A row of tabs along the top edge of the compose pane, inside `.staxx-pane--yaml`, above
`.staxx-yamlwrap`.

- The compose file is pinned leftmost and cannot be closed or renamed. The rest follow in filename
  order.
- A small menu on the active tab: Rename, Delete, Download. Deleting a file the compose file
  references warns, and offers to take the reference out with it.
- A dot while an autosave is pending, red if a write failed — visible from whatever tab you are on.
- Hovering a referenced file's tab says which services use it. A file nothing references is marked so
  an orphan is easy to spot. Derived from the model each time, never stored: the compose file stays
  the only source of truth.

**A non-compose tab has no form view.** Selecting one forces the Compose view and disables Form and
Split with a tooltip saying why. Going back to the compose tab restores the view you were in.

**Editing.** Companion tabs get the same editor — gutter, indentation, find — with plain-text
colouring, plus a small `.env` mode (comment, key, value) since that is far and away the most common
companion. Edits autosave about a second after typing stops, with a "Saved" note and no Save button.
Native Ctrl-Z still works, and the next autosave writes the undone state back out.

The compose tab keeps its explicit Save. It is the file that gets validated and the one where a
half-finished edit matters.

## Phase B3 — Uploading and creating

**Deliberately not a file upload.** `call()` (`stacks.js:397`) posts
`application/x-www-form-urlencoded` and carries a warning against switching it to `FormData`. So we
do not. The browser reads the dropped file itself with `FileReader` and the content goes up as an
ordinary POST field — text as text, binary as base64. No `$_FILES`, no multipart, no second path
through Unraid's CSRF gate, everything on the one code path already proven.

The cost is a size cap, which was wanted anyway: **256 KB**, about 350 KB once base64'd. That needs
`post_max_size` in the server's `php.ini` comfortably above it — **check this on the test box during
B1**, because a silent truncation would be a miserable bug to chase.

- A file picker on the tab strip, plus dropping files anywhere on the editor.
- A `+` that asks for a name and opens an empty tab.
- Over the cap, or refused, gets a sentence naming the file and the reason.
- **Binaries are accepted**, and get a tab showing name, size and type with Download and Replace
  rather than an editor. Certificates and key files are the reason.

## Phase B4 — Wiring an uploaded `.env` in

A file that looks like an env file — named `.env`, `.env.*` or `*.env`, or whose content is
overwhelmingly `KEY=value` — raises a short prompt listing the services, "all of them" preselected.
Confirming adds `env_file` entries with the existing `addItem()` (`compose-model.js:2344`), so it is
a line insertion and nothing else in the file moves. They then show under the existing "Variable
files" group (`stacks.js:75`).

The env file's contents stay text-only. No key/value form rows for it.

**The prompt has to be explicit about one thing.** A file named exactly `.env` is *already* special
to compose: it is read automatically to fill in `${VAR}` placeholders **inside the compose file**,
with no `env_file:` at all. `env_file:` is a different thing — it passes variables **into the
container**. Both are useful and they are confused constantly. So for a file named exactly `.env` the
prompt says so and offers both, rather than silently picking one.

## Phase B5 — References that point at nothing

One scan of the compose text against the folder's actual contents drives four behaviours:

- **On paste**, content referencing a missing file raises a bar: *"This references `.env`, which isn't
  in this stack — upload it, or create it?"*
- **Not only on paste** — the same check runs on every parse, so a reference broken by deleting a
  file is flagged too, in the footer beside the existing error box (`showError`, `stacks.js:591`).
- **Create it prefilled** — writes the file with every referenced variable stubbed (`DB_PASS=`), so
  only the values are left.
- **Undefined `${VAR}`** — any `${VAR}` with no value in an attached env file and no default gets an
  amber gutter dot. Compose substitutes these with an empty string in silence, which is a common and
  baffling failure. `${VAR:-default}` is fine; `$$` is an escaped dollar and is ignored.

Scanned: `env_file`, `secrets` `file:`, `configs` `file:`, `build` `context`/`dockerfile`, and
relative-path volume host sides.

## Not in scope

- `schema/x-unraid.schema.json` is not touched. Every hint here is derived from the compose file and
  the folder's real contents, which is what keeps the compose file the only source of truth.
- No key/value form for env files.
- No editing files outside the stack's own folder.
- No versioning or `.bak` files.

## Test fixtures

Two were added, `11-companion-files` and `12-missing-companions`, and both are on the test box.
The first holds a `.env`, a binary, an orphan and a subdirectory; the second names two companions
that are not there and carries one `${VAR}` of each kind.

**The symlink fixture cannot exist.** Stacks live on `/boot` by default, which is vfat and refuses
to hold a symlink at all — `symlink()` there simply returns false, so every link case would pass
for the wrong reason. That is `tests/server/links.php` instead, which points `STACK_ROOT` at `/tmp`
for the length of one run. It earned its keep immediately: see below.

---

## What was built differently

1. **`staxx_rmtree()` had a real bug, found by that symlink test.** It resolved the path with
   `realpath()` before testing `is_link()`, and a link's target is outside the tree by definition —
   so one symlink anywhere made the whole delete refuse, with nothing removed. The link check now
   happens on the path as given, before anything resolves it.

2. **No separate paste bar.** The footer note appears in the reparse that follows a paste anyway, so
   a second mechanism saying the same thing was not built. What a paste gets instead is the note
   scrolling itself into view when a name is newly missing.

3. **Deleting a referenced file does not offer to remove the reference.** It names the services and
   says they will stop working, and leaves the compose file alone. Editing someone's compose file as
   a side effect of deleting something else is a bigger action than it looks, and the missing
   reference is flagged the moment the file goes — which is the same information, arriving where it
   can be acted on.

4. **This plan was wrong about what defines a `${VAR}`.** It said "no value in an attached env
   file"; `env_file:` has no effect on substitution inside the compose file. Only a file named
   exactly `.env` settles it, which is the distinction phase B4 above spends three paragraphs
   explaining. The code follows the correct rule and says so where the decision is made.

5. **`fileRefs()` and `varRefs()` went into the model, not the page.** Both are `classify()`-only
   scans in `compose-model.js`, built on `hostPaths()`'s pattern and covered by the round-trip suite
   — 29 new cases between them. They are the shared machinery behind the tab hints, the missing-file
   note and the gutter dots, and putting them in the page would have made all three untestable.

6. **A data-loss bug was found and fixed during the browser check.** Opening a binary file's tab and
   clicking away wrote an empty file over it: the empty box counted as an edit, and the autosave
   duly saved it. Nothing may be written back now unless a read has actually succeeded and handed
   the box that file's own text.

`post_max_size` was the thing to check first, and it is **8M** on the test box — comfortably above
the 256 KiB cap and the ~350 KB a base64'd file at that cap becomes.

---

## Changed after review, 2026-08-15

Two things were wrong once it was used in anger.

**A new file was not blank.** It showed the compose example — which is the compose box's own
placeholder (`StacksPage.php:376`), correct for the file it belongs to and showing straight through
an empty companion. `fileChrome()` now drops the placeholder on the way into a companion tab and
puts it back on the way out.

**The form no longer disappears.** Phase B2 above forced Compose view whenever a companion file was
open. It stays on screen in Split instead, because it says which service reads that file and what
it has to define. It is **for reference only** — the form edits the compose file and nothing else —
so while a companion is open every text box is `readOnly` (values can still be selected and copied)
and every tick box, dropdown and button is disabled, with a note above the pane saying why. Form on
its own is still the one view a companion tab cannot have: it would hide the very file the tab
opened.

Locking runs one way only. The way back to the compose file calls `reparse()`, which rebuilds the
form's markup from scratch and unlocks it, so nothing has to remember what was already disabled.

Three latent bugs came out with it, all reachable through find/replace, which works on a companion
tab: `reparse()` parsed the box rather than the compose file, `pushUndo()` snapshotted the box (so
Undo could restore a `.env`'s text into the compose file), and `paintDots()` returned without
clearing, leaving the compose file's lint dots over a companion's lines.

### The blank compose editor

Reported the same evening: opening a companion file and going back left the compose editor showing
nothing. The text was there and the file on disk was untouched — the *colour layer* was empty, and
that layer is all you can see, since the textarea's own text is transparent.

`paintInk()` is incremental and keeps `inkLines`/`carryAfter` as a record of what it last drew.
`plainInk()` rewrites the same layer for a companion file and did not touch that record — so on the
way back `paintInk()` compared the compose file against a record that still described it, found
every line unchanged, and repainted none of them. An empty companion is the worst case, because the
layer is first cut down to one line. `plainInk()` now clears both arrays before it writes, which is
what `paintInk()`'s own oversized-file branch already does.

Two data-loss holes went with it. `commit()` had no check on which file the box was showing, and it
runs on a 250 ms timer that a tab switch could outlive — a form edit could therefore serialise the
whole compose file into a companion's box, where the autosave wrote it. `openFile()` now flushes a
pending edit before it switches, so it lands in the compose file, and `commit()` refuses to write
while a companion is open. Separately, `updateUndo()` did not know about companion tabs and switched
Undo back on after a find/replace there, where undoing writes compose text into that file.

A binary file's tab deliberately leaves the stale ink underneath: the Download/Replace panel covers
the box completely, and the next paint replaces it.

### A file on disk with no tab

Reported next: a companion file present in the folder had no tab on a freshly opened editor, and no
error. **Not reproduced** — that stack opens correctly, before and after these changes. What was
found instead is that every way the listing can fail was silent, which is why there was nothing to
go on:

- a refusal collapsed to `FILES = []`, throwing away the sentence `action.php` sends back;
- an exception inside `renderTabs()` — it reads the compose text through `YAML.fileRefs()` — was
  swallowed by a bare `.catch(function () {})` at each call site, leaving the strip undrawn;
- a reply for a stack the editor had since left could overwrite the strip, which `envLoad()` beside
  it already guards against and `filesLoad()` did not.

An empty listing is also why the missing-file note went quiet at the same time: `missingRefs()`
returns nothing when `FILES` is empty, a guard against flashing a false warning before the first
listing lands. One failure, two symptoms — not two parts of the code disagreeing.

`filesLoad()` now guards the reply, keeps the previous listing rather than blanking it, and says
what went wrong. The listing was also fetched exactly once per editor open: it is now re-read when
the window regains focus, and on demand from **Refresh file list** on the compose tab's menu. That
button is also the diagnosis if it recurs — if refreshing brings the file back it was a lost
listing, and if it does not, the message names the reason.
