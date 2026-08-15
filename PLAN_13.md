# PLAN_13 — Files that live alongside the compose file

**Status:** outstanding. Starts after PLAN_12 lands.

A stack is currently only a compose file. Real stacks need companions — a `.env`, a secret, a config
snippet — and there is no way to get one onto the server through the plugin, no way to edit one, and
no way for the compose file and those files to know about each other.

There is also a trap waiting. `stackman_delete_stack()` (`Stacks.php:1333`) refuses to delete any
stack whose folder holds anything beyond the compose file and a `.env`. That guard is right today and
becomes a wall the moment companion files exist, so it is dealt with in the first phase, before
anything can create one.

## Phase B1 — Files on disk, and fixing delete

### A filename validator, separate from the stack one

`stackman_valid_name()` (`Stacks.php:53`) requires a name to start with a letter or digit, so it
rejects `.env` outright — correct for a directory name, wrong for a file. Companion files get
`stackman_valid_filename()`: an optional leading dot, then the same safe set (`A-Za-z0-9._-`), no
slash, no `..` anywhere, 1–63 characters, and **not** one of `STACKMAN_COMPOSE_FILENAMES`
(`Stacks.php:33`) — a second compose file in the folder would silently change which one the stack
runs.

### Five helpers in `Stacks.php`

Each takes the stack's relative path, runs it through the existing `stackman_valid_path()`, then
confines the result with `realpath()` the way `stackman_browse_dirs()` already does (`:143`).

| Helper | Does |
|---|---|
| `stackman_list_files($rel)` | Every entry: name, size, mtime, is-compose, looks-like-text, is-directory |
| `stackman_read_file($rel, $file)` | Text as-is, or base64 for a binary |
| `stackman_write_file($rel, $file, $body)` | CRLF→LF for text, `chmod 0644`, temp file + `rename()` so a reader never sees half a file |
| `stackman_delete_file($rel, $file)` | One file |
| `stackman_rename_file($rel, $from, $to)` | Within the folder only |

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

A row of tabs along the top edge of the compose pane, inside `.stackman-pane--yaml`, above
`.stackman-yamlwrap`.

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

## Test fixtures to add

`scratch/test-stacks/` already has `05-busybox-http/.env` and `04-ubuntu-secrets/`. Add: a stack with
a deliberately undefined `${VAR}`; one with `env_file` pointing at a file that is not there; and one
holding a binary file, a subdirectory and a symlink pointing outside the folder — that last is the
delete confirmation's test case.
