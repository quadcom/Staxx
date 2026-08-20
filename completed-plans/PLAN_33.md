# PLAN_33 — the app is called StaXX

**Status: COMPLETE 2026-08-18.** Built, deployed, confirmed in the browser and committed as
`9b417da` on `settings-panel`. 4,212 replacements across 76 files; every test suite passes; the
installed copy migrated with all fifteen test stacks intact and no PHP or console error.

Two notes from the build, for anyone replaying this:
- The lower-case "stack manager" review step in phase 2 turned out to be unnecessary — see phase 2.
- The Docker sub-tab URL was corrected to `/Docker/staxx` from the rule PageBuilder demonstrably
  follows, but **not tested in the browser**, because reaching that branch means switching Adrian's
  own header-menu setting off. Still outstanding.

## What you would notice

Everywhere the app used to introduce itself as "Stack Manager" it now says **StaXX**. The settings
page in Utilities, the panel headings, the install and removal messages, the docs. Nothing about
what the app *does* changes, and nothing about your stacks changes — but the folder your settings
and stacks live in on the flash drive gets a new name, so the install has to carry them across.
That move is the one part of this that can go wrong, and it is the part with a safety net.

## The name, in its five forms

`StaXX` is the name people read. Machines need a lowercase, dot-free form, and that is `staxx`.
One word, no separator — it sorts after `dynamix.docker.manager`, which is the only sorting
constraint the plugin folder has.

| Where | Was | Becomes |
|---|---|---|
| Prose, headings, titles | `Stack Manager` | `StaXX` |
| Plugin folder, package, `.plg`, config folder | `stack.manager` | `staxx` |
| PHP function prefix (867) | `stackman_` | `staxx_` |
| PHP constant prefix (199) | `STACKMAN_` | `STAXX_` |
| CSS class / DOM id prefix (2446) | `stackman-` | `staxx-` |
| Browser globals | `StackmanYaml`, `StackmanCA`, `StackmanImage` | `StaxxYaml`, `StaxxCA`, `StaxxImage` |

### What deliberately does NOT change

- **`x-unraid:`** — the compose extension key. It is named for the *platform* the metadata
  describes, not for this app; renaming it would break every file already written, the schema, and
  the docs, for nothing. Flag this to Adrian rather than deciding it silently a second time.
- **`Stacks`** — the page, the nav button, `Stacks.page`, `Stacks.css`, `StacksPage.php`,
  `Stacks.php`, `StacksTable.php`. "Stacks" is the *feature*, and the button in the header still
  says Stacks. Only the *app* is renamed.
- **`STACK_ROOT`** and the other config keys. They describe stacks, not the app.
- **`com.docker.compose.*`** and **`net.unraid.docker.*`** labels — other people's namespaces.
- **`completed-plans/` are rewritten too**, deliberately: they exist to be grepped for "how did we
  do X", and a folder half in the old vocabulary makes every future search return nothing. The
  three dated result documents at the repo root (`REVIEW-2026-08-14.md`, `CA-IMPORT-RESULTS.md`,
  `PLAN_24.md`, `PLAN_31.md`) get the same treatment for the same reason.

## Phase 1 — file and folder renames

```
src/stack.manager/                                  -> src/staxx/
src/staxx/usr/local/emhttp/plugins/stack.manager/   -> .../plugins/staxx/
  stack.manager.page                                -> staxx.page
  stack.manager.settings.page                       -> staxx.settings.page
  sheets/stack.manager.css                          -> sheets/staxx.css
stack.manager.plg                                   -> staxx.plg
```

Use `git mv` so history follows. `Stacks.page`, `Stacks.css` and every `include/*.php` keep their
names.

## Phase 2 — the text substitution

Four ordered replacements, applied to every tracked text file (not `.git`, not binaries):

1. `Stack Manager` → `StaXX`
2. `stack.manager` → `staxx`
3. `stackman` → `staxx`   *(catches `stackman_` and `stackman-` in one pass)*
4. `STACKMAN` → `STAXX`, `Stackman` → `Staxx`

Lower-case "stack manager" as generic prose — *a manager of stacks* — was checked for and **does not
occur anywhere in the tree**, so there is no hand-review step. Substitution 3 is safe for the same
reason: nothing outside this project's own identifiers contains `stackman`. Both claims were
verified before the substitution ran, not after; re-verify if this is ever replayed.

### Paths that move as a consequence

| Was | Becomes |
|---|---|
| `/usr/local/emhttp/plugins/stack.manager/` | `/usr/local/emhttp/plugins/staxx/` |
| `/boot/config/plugins/stack.manager/` | `/boot/config/plugins/staxx/` |
| `stack.manager.cfg` | `staxx.cfg` |
| `/tmp/stack.manager/` (jobs, CA cache, stats) | `/tmp/staxx/` |
| `/boot/stack.manager-dev/` (dev staging) | `/boot/staxx-dev/` |
| Settings URL `/Settings/stack.manager.settings` | `/Settings/staxx.settings` |
| Docker sub-tab URL `/Docker/stack.manager` | `/Docker/staxx` |

## Phase 3 — the one real bug this uncovers

The settings page links to the Stacks view at `/Docker/Stacks` when the header button is off.
PageBuilder names a page by its **file basename** (`$page['name'] = basename($entry, '.page')`), so
that URL has never existed — it is `/Docker/stack.manager` today and `/Docker/staxx` after this.
Fix the link while renaming it. It is invisible on the test box because the header button is on
there, which is why it survived.

**Not verified in the browser** — checking it means switching Adrian's own header-menu setting.
Leave it for him, or ask before flipping it.

## Phase 4 — migrating the installed copy

`dev-install.sh` and the `.plg` both gain the same migration, run before anything else:

```sh
old=/boot/config/plugins/stack.manager
new=/boot/config/plugins/staxx
if [ -d "$old" ] && [ ! -d "$new" ]; then
  mv "$old" "$new"
  mv "$new/stack.manager.cfg" "$new/staxx.cfg" 2>/dev/null || true
  sed -i 's#/boot/config/plugins/stack\.manager#/boot/config/plugins/staxx#g' "$new/staxx.cfg"
fi
rm -rf /usr/local/emhttp/plugins/stack.manager   # rebuilt at boot anyway; kill the stale copy now
rm -rf /tmp/stack.manager                        # a cache, regenerates
```

Three things about that block matter:

- **`mv`, not `cp`.** Two config folders that both look valid is worse than one, and flash writes
  are the thing worth being stingy with on Unraid.
- **The `! -d "$new"` guard** makes it idempotent — a second install must not clobber the migrated
  config with a stale copy of the old one.
- **`STACK_ROOT` is rewritten inside the config**, because it holds the absolute old path
  (`/boot/config/plugins/stack.manager/stacks`) and would otherwise point at a folder that no
  longer exists. On the test box that folder holds 15 numbered test fixtures — losing them loses
  the regression corpus.

The `.plg` removal block also needs its `removepkg "stack.manager-"*` and the message naming the
kept config folder updated.

## Phase 5 — checks

Everything in the CLAUDE.md list, plus:

```sh
grep -rIn 'stackman\|stack\.manager\|Stack Manager' --exclude-dir=.git .    # must be empty
```

Then deploy and, on the server:

```sh
php -l /usr/local/emhttp/plugins/staxx/include/*.php
ls /boot/config/plugins/staxx/stacks/          # 15 fixtures, still there
```

Then the browser: the Stacks page renders, no console error, all four scripts load, the settings
panel opens, and Settings → Utilities shows **StaXX**.

## Risk

Low, with one exception. The substitution is mechanical and the checks catch a broken file
immediately. The exception is Phase 4: it moves the only copy of the stacks and the config. Run it
once, verify the fixtures are present before doing anything else, and do not re-run the old
installer afterwards.
