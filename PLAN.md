# One name per thing

## Context

Four different things in this plugin have names — a folder, a stack, a service and a container —
and right now the UI does not keep them apart. The worst of it is the stack row's label, which is
computed from **four different sources** depending on what happens to be in the file
(`stackman_stack_title()`, `Stacks.php:655`):

1. `x-unraid: name:` if the file has one, else
2. the single service's `container_name:`, else
3. the single service's key, else
4. the directory name.

So two rows side by side can be labelled by completely different things, and neither one is
necessarily what you would type to find the stack on disk. On top of that the editor's title bar
says "Editing Media/jellyfin" (a path) while the Name box beside it shows "jellyfin" (a leaf) —
two meanings of "name" in one dialog. Nothing can be renamed except a folder.

The outcome: **every thing has exactly one name, that name is visible, and it can be changed.**

---

## The vocabulary the UI will teach

| Term | What it actually is | Where it lives | How it is renamed |
|---|---|---|---|
| **Folder** | an optional top-level directory that groups stacks | `<root>/Media/` | folder row menu (exists today) |
| **Stack** | the directory holding the compose file | `<root>/Media/jellyfin/` | **new** — the editor's Name box |
| **Service** | a key under `services:` in the compose file | inside `compose.yaml` | **new** — pencil on the form heading |
| **Container** | what Docker actually created from a service | Docker | not renamed; `container_name:` is an ordinary compose field |

Two things stay invisible, on purpose:

- **The compose filename.** New stacks always get `compose.yaml`. Files already named
  `compose.yml` / `docker-compose.yaml` / `docker-compose.yml` still load and keep their name on
  save — the UI simply never mentions it.
- **The Docker project name** (`com.docker.compose.project`, the prefix on container names). It is
  derived from the stack directory and is an implementation detail of Docker.

---

## Two contracts that cross a file boundary

Pinned here so the pieces agree.

**`renameService`**, exported from `compose-model.js` alongside `setPart`:

```js
ComposeModel.renameService(doc, oldName, newName)
// -> { ok: true,  refs: <number of references rewritten, key not counted> }
// -> { ok: false, error: '<one full sentence saying what to do next>' }
```

Mutates `doc` in place on success and leaves it **completely untouched** on failure — validate
everything before writing the first byte. The caller re-runs `buildForm(doc)`.

**`stack-rename`**, a new case in `action.php`:

```
POST action=stack-rename & name=<current rel> & stackName=<new leaf>
  -> { ok: true, name: '<new rel>' }      // new identity, e.g. "Media/jellyfin2"
  -> { error: '<one full sentence>' }
```

`stackName` mirrors `folderName` on the existing `folder-assign` / `folder-rename` cases.

---

## The changes

### 1. The stack's name is its directory. Full stop.

- **Delete `stackman_stack_title()`** (`Stacks.php:655-668`).
- In `stackman_list_stacks()` (`Stacks.php:684-729`) drop the `title` field and sort by `leaf`
  then `name`. Every consumer of `$s['title']` uses `$s['leaf']` instead —
  `StacksTable.php:610` (the row label), `:584` (`data-label`, which feeds the context-menu
  heading), `:648-653`, and `stackman_state_snapshot()` (`StacksTable.php:899` area) if it carries
  one.
- `$s['name']` (the path, `Media/jellyfin`) stays exactly as it is — it is the identity every
  command and selector is keyed on, and it is never printed as text.

### 2. `x-unraid: name:` goes away — at both levels

Stack level and service level. One name per thing means the display-name override has nothing left
to do.

- `schema/x-unraid.schema.json` — remove `name` from `$defs.stack` and `$defs.service`.
- `docs/x-unraid-schema.md` — remove both sections (around `:158-191` and `:194-223`) and the
  four-step fallback-ladder prose that goes with them.
- `javascript/compose-model.js:1055` — `title: mx.name || name` becomes just the key; the separate
  `title` property on a service goes.
- `javascript/stacks.js:597-598` — the `.stackman-svckey` span (which showed the real key beside a
  pretty name) is deleted; the heading is the service key.
- `tests/validate_schema.py` — update any case using these keys; add a negative case asserting the
  schema now rejects them.

**No runtime break.** `stackman_compose_meta()` (`Stacks.php:587-611`) harvests `x-unraid.*`
generically into `$stack['x']` and never validates against the schema, so an existing file
carrying `name:` keeps parsing — the key is simply ignored and the row falls back to its directory
name, which is the intended answer anyway.

### 3. Rename a stack — new `stack-rename` action

A rename is a directory move, so it reuses the shape `stackman_folder_assign()`
(`Folders.php:250`) already has — same `rename()`, same collision and validation checks, same
"return the new `rel` so the page can re-key itself" contract (`action.php:302-310`).

**Server** — `stackman_rename_stack(string $rel, string $newLeaf, &$error): string` in `Stacks.php`,
returning the new `rel` or `''` plus an error. It reuses `stackman_valid_name()` (`:53`),
`stackman_valid_path()` (`:73`), `stackman_path_folder()` (`:86`) and `stackman_stack_dir()`
(`:97`). It refuses on: an invalid name, a target that already exists (case-insensitively, same
check `stackman_folder_taken()` `Folders.php:113` makes), or a missing source.

**A running stack is stopped and started again**, and this is sequenced by the page rather than by
new shell, so the existing job machinery does all the slow work:

1. If running, confirm: *"jellyfin is running. Renaming it will stop the containers and start them
   again under the new name. Continue?"*
2. `run` with the existing `down` verb — existing endpoint, existing log polling.
3. On success, `stack-rename` — pure PHP `rename()`, instant, no timeout risk.
4. `run` with the existing `up` verb against the **new** name.
5. A `rows` refresh, because the set of rows changed.

A stopped stack is step 3 alone. No new job verb, no new shell command, nothing added to
`stackman_job_verbs()`.

**Client** — `stacks.js:1828`: `nameInput.readOnly = !isNew` becomes always editable, and the
comment at `:1817-1821` explaining why it is locked is replaced by one explaining the
stop-and-start. In `save()` (`stacks.js:1948-1952`): write the compose file **first**, to the old
path, then rename — so the content is safe on disk before the directory moves.

### 4. Rename a service — with the references fixed up

**Model** — new `renameService()` in `compose-model.js` (contract above), exported alongside
`setPart` (`:1369-1384`). A service key's pair already has a `keySpot()` (`:559`), so the key
itself is one `writeScalar()`. Afterwards the caller re-runs `buildForm()` rather than reindexing
`field.service` and ranges by hand — that is what `structuralEdit()` in `stacks.js` already does
after every structural change.

**What gets fixed up**, by scanning for these five keys and rewriting matching scalars:

- `depends_on:` — both the list form and the map form, in every service
- `links:` — bare `service` and `service:alias`
- `volumes_from:`
- `network_mode: service:<name>`
- `extends: service:` where no `file:` is set (an in-file reference)

**What deliberately is not touched**, and the UI says so: `container_name:` (a separate compose
field you control), and any mention inside an env var value, a `command:` or a healthcheck —
guessing there would corrupt a working file.

**UI** — a small pencil button beside `<h4 class="stackman-svchead">` (`stacks.js:597`) opening a
`window.prompt`, the same pattern folder rename already uses (`stacks.js:2800-2807`). Validate
against compose's rule (`[a-zA-Z0-9._-]+`) and against collision with a sibling service, with a
plain-English refusal.

**Recreating the container.** A renamed service leaves its old container orphaned. Service-scoped
`up` deliberately omits `--remove-orphans` (`Stacks.php:1435-1440`), so after a save that included
a service rename the page offers a **whole-stack** recreate. If the stack-scoped `up` verb in
`stackman_job_verbs()` (`Stacks.php:1464`) does not already carry `--remove-orphans`, that is the
one place to add it — stack scope only, never service scope.

### 5. The Services column

`StacksTable.php:628-661` currently prints the *image* when there is exactly one service, because
the service name used to duplicate the row title. It no longer does.

- First line: always `implode(', ', $s['services'])` — the service keys.
- Sub-line: the image, **only when there is exactly one service**. Listing five images under a
  five-service stack would swamp the table, and they are all visible on the expanded child rows.
- The error and empty strings at `:630-635` stay as they are.

### 6. The filename sub-line goes

`StacksTable.php:619-621` prints the compose filename when it is not `compose.yaml`. Delete it.
`$s['filename']` stays in the model because `stackman_save_stack()` needs it to preserve the
existing name.

### 7. The editor's header stops competing with the Name box

- `stacks.js:1809` — `'Editing ' + name` (which is a *path*) becomes a static `'Edit stack'`,
  matching the existing `'New stack'`. The Name box is now the single, editable statement of what
  the stack is called, and the folder chip beside it (`#stackman-name-folder`, `:1824-1825`) says
  where it lives.
- `StacksPage.php:199` — the label `_('Name')` becomes `_('Stack name')`, with a short hint
  underneath: *"The folder that holds this stack's compose file."*

### 8. Three naming bugs found while mapping this

Fixing them is in scope because each one makes a name display wrongly.

1. **`action.php:191-194` treats folder names as arrays.** `stackman_folder_names()`
   (`Stacks.php:348`) returns `string[]`, but the `rows` reply does `$f['id']` / `$f['name']` on
   each. On PHP 8 that is an illegal string offset on every folder. `StacksPage.php:74-76` gets it
   right. Meanwhile `folder-list` (`action.php:277`) returns bare strings — a third shape for the
   same concept. **Unify all three on `{id, name}`.**
2. **`StacksTable.php:511`** builds the project fallback from `strtolower($s['name'])` — the full
   path, `media/jellyfin` — where the server uses `strtolower($s['leaf'])`
   (`Stacks.php:1066`, `StacksTable.php:899`). Use `leaf`.
3. **`strtolower($leaf)` is not how compose normalises a project name.** Compose lowercases *and*
   strips anything outside `[a-z0-9_-]`, so a stack directory `my.stack` — which
   `stackman_valid_name()` permits — becomes project `mystack`, and the fallback lookup misses
   while the stack is down. Add `stackman_project_name(string $leaf): string` doing the real
   normalisation and use it at all three fallback sites.

### 9. Docs, so the vocabulary is written down once

- **`docs/glossary.md`** has no entry for Folder, Stack name, Service, Container or Project name —
  the five terms this whole area turns on — and defines a Stack as "all the services in one compose
  file" rather than as a directory, which is what every other document means. Add the table from
  the top of this plan.
- **`README.md:46-48`** still promises "stacks self-group, with no folders for the user to
  configure". That design was superseded by user-created folder directories. Correct it.
- **`CLAUDE.md`** — the stack model section gains one line: the stack's name is its directory, and
  there is no display-name override.

---

## Files touched

| File | What changes |
|---|---|
| `include/Stacks.php` | delete `stackman_stack_title()`; drop `title`; add `stackman_rename_stack()`, `stackman_project_name()`; sort by `leaf` |
| `include/StacksTable.php` | row label from `leaf`; Services column; drop the filename sub-line; project fallback fix |
| `include/action.php` | new `stack-rename` case; folder-shape fix in `rows` and `folder-list` |
| `include/StacksPage.php` | Name label and hint |
| `javascript/compose-model.js` | `renameService()` + reference fix-ups; drop service `title` |
| `javascript/stacks.js` | editable Name box + rename sequence; service rename pencil; drop `.stackman-svckey`; static header |
| `sheets/stack.manager.css` | pencil button; drop `.stackman-svckey` |
| `schema/x-unraid.schema.json`, `docs/x-unraid-schema.md` | remove `name` at both levels |
| `docs/glossary.md`, `README.md`, `CLAUDE.md` | the vocabulary, written down |
| `tests/yaml_roundtrip.js`, `tests/validate_schema.py` | new cases below |

---

## Verification

**Locally**

```sh
node tests/yaml_roundtrip.js     # existing cases must all still pass
node tests/js_undeclared.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
python tests/validate_schema.py
```

New `yaml_roundtrip.js` cases for `renameService()`, because this is the one change that can
silently damage someone's file:

- rename with a `depends_on:` list, a `depends_on:` map, `links:` with an alias, `volumes_from:`,
  and `network_mode: service:<name>` — every reference follows
- rename in a comment-heavy, anchor-using file — comments, blank lines, ordering and indentation
  all survive byte-for-byte apart from the names
- a name that is a substring of another service (`db` and `dbbackup`) — only the whole key matches
- a refused rename (collision, illegal characters) leaves the document untouched

**On the server** (`php -l include/*.php` after deploying, then a throwaway PHP script)

`stackman_rename_stack()` refusals, which never reach the shell so they are safe to run through
exhaustively: illegal name, `..`, a name containing `/`, a target that already exists, a target
differing only in case, a source that does not exist, and a stack inside a folder (the folder must
survive).

**In the browser**, the only place the rest can be judged:

1. A stack with `x-unraid: name:` in it now shows its directory name in the list.
2. Rename a stopped stack — instant, row re-labels, no containers touched.
3. Rename a running stack — the warning appears, containers stop and come back under the new name,
   and the row shows running throughout the refresh cycle.
4. Rename a stack that lives in a folder — it stays in the folder.
5. Rename a service that another service `depends_on` — the reference follows, the recreate offer
   appears, and after recreating there is no orphan container left behind.
6. The Services column: a single-service stack shows the service name with its image beneath; a
   multi-service one shows the names only.
7. The editor header reads "Edit stack", and the Name box is the only place a name appears.

---

## Order of work

Sections 1–2 (collapse the title ladder), 3 (stack rename), 8 (the three bugs) and 5–7 are
independent. Section 4 is the only one that can damage a file, so it lands behind the new
round-trip tests.
