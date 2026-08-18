# PLAN_32 — Settings belong in the app, not on Unraid's Settings page

**Status: COMPLETE 2026-08-18.** Built on branch `settings-panel`, deployed, and confirmed in the
browser against the live server — all ten checks including the takeover switch, which was tested with
Adrian's explicit go-ahead and left **off**.

**One thing this plan got wrong, found during the takeover test and fixed.** Step 4 says "Our own page
is already `Menu="Docker:0"`, so with the stock list suppressed it is the only Docker sub-tab." That
holds only when `HEADER_MENU` is **false**. `stack.manager.page`'s `Cond` switches our Docker sub-tab
*off* whenever the header-menu marker exists — so with both settings on, the shadow page suppressed
Unraid's list and our tab was absent too, leaving the Docker tab holding **nothing at all**. Adrian's
own server is in exactly that state (`HEADER_MENU="true"`), so this was the default outcome, not an
edge case.

Fixed by projecting `TAKEOVER_DOCKER_TAB` onto its own marker file — the same trick `HEADER_MENU`
already uses, and for the same reason — and widening that `Cond` to
`!header_menu_marker || takeover_marker`. With the takeover on, Stacks appears both in the top nav and
as the Docker tab; showing it twice is the lesser fault.

**Step 4 was then rebuilt to Adrian's actual intent (2026-08-18, same day).** This plan scoped the
takeover as "suppress Unraid's *Docker Containers* sub-tab, keep the Docker menu", and explicitly
ruled the alternative out under *Left out*: "touching the nav button would put our own name on
Unraid's furniture." That was **not what he wanted**. On seeing it work he said the takeover should
always have removed the **Docker top-navigation button itself**, with Stacks as a top-level menu item
and no "Docker" label anywhere.

Rebuilt accordingly, and it is *simpler* than what this plan specified — one shadow file, not two.
Shadowing `Docker.page` (`Menu="Tasks:60"`, and note it carries **no `Title=`**, so its label comes
from the filename) removes the button, and everything under that menu goes with it because nothing
under it is reachable any more. So the `DockerContainers.page` shadow was deleted.

His decisions, taken at the same time: the button stays called **"Stacks"**; and losing *everything*
under the Docker menu — Unraid's container list and the separately-installed `compose.manager`
plugin's "Compose" sub-tab — is intended, not a cost to mitigate. (`compose.manager` also has its own
top-nav button at `Tasks:61`, which survives independently.)

Two consequences worth keeping:

- **`Stacks.page`'s `Cond` is what had to widen, not `stack.manager.page`'s.** With the Docker menu
  gone, our Docker sub-tab is irrelevant; the top-nav button is the only way in. So the takeover
  marker forces `Stacks.page` on, which makes `HEADER_MENU` inert while the takeover is on — said
  plainly in the setting's own help text.
- **`TAKEOVER_DOCKER_TAB` was added to the Unraid settings page** beside `STACK_ROOT`, on the same
  unvalidated `/update.php` form, for the same reason: a switch that can hide the entire Docker menu
  needs a way back that does not depend on the app it hides.

**The shadowing mechanism, verified in Unraid's own source rather than inferred** (`webGui/include/PageBuilder.php`
and `plugins/dynamix/template.php`): `template.php` iterates `glob('plugins/*', GLOB_ONLYDIR)` with
glob's **default alphabetical sort**, and `build_pages()` writes into `$site[basename]`, so a later
plugin folder overwrites an earlier one's same-named page. `page_enabled()` is evaluated *afterwards*,
in `find_pages()` — which is why a `Cond="false"` page still claims the slot and suppresses the stock
one. Measured on the box: `dynamix.docker.manager` is 10th, `stack.manager` is 30th.

## Context

Stack Manager's settings live on Unraid's Settings → Utilities page, separated from the UI they
govern. Adrian's call, 2026-08-18: put them behind a **Settings button on the Stacks page**, in a
modal, every setting carrying an explanation of what it does, with a **Save button**. All future
settings go there too.

Four decisions he took at the same time:

1. **Unraid's Settings page becomes a signpost** — one line saying settings live in the app, plus a
   link that opens the panel. It keeps working as the Plugins-list launch target.
2. **A Save button**, greyed until something differs; closing with unsaved changes asks first.
3. **`TAKEOVER_DOCKER_TAB` gets built**, not deleted. It is declared in `default.cfg:32` and read by
   *nothing* today — a switch that looks real and does nothing.
4. **The stack folder is validated before saving**, offers the existing folder browser — **and also
   stays on the signpost page** as a recovery route, worded for the case where somebody moved the
   stacks folder by hand and the page can no longer find them.

### What was measured first

- **Nothing in the plugin writes the config today.** The settings page posts to Unraid's
  `/update.php` into a hidden iframe, with two hidden fields doing the work
  (`stack.manager.settings.page:27-29`): `#file` names the cfg, `#command` runs
  `scripts/apply_settings` afterwards. `/update.php` writes every non-`#` field verbatim with **no
  allowlist** — `docs/settings-ideas.md:18-20` states this outright.
- **`apply_settings` does exactly two things** (41 lines): touches or removes
  `/boot/config/plugins/stack.manager/header_menu` from `HEADER_MENU`, and `mkdir -p`s `STACK_ROOT`.
  It is run from three places: the settings form's `#command`, `stack.manager.plg:81`, and
  `dev-install.sh:93`.
- **No setting reaches the browser at all** (`docs/settings-ideas.md:32-35`).
- **`stackman_cfg()`** (`include/Defines.php:55-62`) merges `default.cfg` under the user's file and
  caches per request in a `static`. `stackman_cfg_bool()` beside it has **zero callers** and its
  absent-default is `false`, which is wrong for `ICON_FETCH`/`IMAGE_LOOKUP` — do not press it into
  service without changing it.
- **`STACK_ROOT` is validated nowhere.** `stackman_stack_root()` (`include/Stacks.php:45-49`) only
  trims and strips a trailing slash. A nonsense value yields an empty list plus self-test errors;
  `/mnt/user` would present every directory holding a compose file as a stack.
- **`.stackman-field` already exists and is used by nothing** (`sheets/stack.manager.css:1567-1620`):
  label above, full-width box, hint below. It is the settings row, already written.
- **Takeover is viable, and smaller than `docs/feasibility.md:132-140` implies.** Verified on the
  server: plugin folders load alphabetically and `stack.manager` (31st) loads after
  `dynamix.docker.manager` (10th), so a same-named `.page` file in our folder wins. The stock list is
  `dynamix.docker.manager/DockerContainers.page`, `Menu="Docker:1" Title="Docker Containers"`.

---

## Step 1 — The server owns the write

### `include/Settings.php`, new

A small file rather than more weight in `Defines.php`. Guard against double-inclusion with the
`defined()` early return every other include uses, and `require_once` by absolute path.

**`stackman_settings_keys(): array`** — the allowlist, and the single source of truth for what a
setting *is*: key ⇒ `['type' => 'choice'|'path', 'default' => …, 'choices' => [...]]`. Five entries:
`HEADER_MENU`, `TAKEOVER_DOCKER_TAB`, `STACK_ROOT`, `ICON_FETCH`, `IMAGE_LOOKUP`.

**`stackman_settings_read(): array`** — the current value of each allowlisted key, from
`stackman_cfg()`, defaults filled in. Nothing else; the browser never sees a key we do not name.

**`stackman_settings_save(array $posted, ?string &$error): bool`**

- Validate every key **before writing anything**; one bad value saves none of them.
- A `choice` value must be one of its listed choices.
- **`STACK_ROOT` rules:** must be absolute; must not contain `..`; must sit under `/mnt/` or under
  `/boot/config/plugins/stack.manager`; must be an existing directory, or have an existing parent so
  `mkdir -p` can succeed. Anything else is refused with a full sentence saying what to do — house
  style, and this is the one setting that can empty the page.
- **Write atomically**: read the existing cfg into a map, overlay the validated keys, write every
  key back as `KEY="value"` to a temp file in the same directory, then `rename()`. A half-written cfg
  is worse than any value in it. (`stackman_folders_save()` at `include/Folders.php:83-95` is *not*
  atomic — do not copy it here.) Unknown keys already in the file are preserved rather than dropped,
  so a key from a newer version survives a save by an older one.
- Comments are lost, exactly as they are lost today the first time `/update.php` saves. If any
  comment is ever written back, **it must start with `;` and never `#`** — the reason fills the top
  of `default.cfg` and it is not a style preference.
- Then run `scripts/apply_settings` through `stackman_sh()`, so the marker file and the shadow page
  land the same way they do from the Unraid form. Report its failure rather than swallowing it.
- Return which of the three page-affecting keys changed (`HEADER_MENU`, `TAKEOVER_DOCKER_TAB`,
  `STACK_ROOT`) so the browser knows whether a reload is needed.

### `include/action.php`, two new cases

Beside the existing cluster, matching `folder-collapse`'s shape exactly (`:687-694`): POST only,
validation in the callee, `stackman_reply()` which `exit`s, booleans arriving as the string `'1'`.

```php
case 'settings':
  stackman_reply(['ok' => true, 'settings' => stackman_settings_read()]);

case 'settings-save':
  $error = '';
  $reload = false;
  if (!stackman_settings_save($_POST, $error, $reload)) {
    stackman_reply(['ok' => false, 'error' => $error]);
  }
  stackman_reply(['ok' => true, 'settings' => stackman_settings_read(), 'reload' => $reload]);
```

`stackman_cfg()`'s per-request `static` cache means the re-read above sees the old values — so
`stackman_settings_read()` here must re-parse, or the reply must be built from the validated input.
**Build the reply from the validated input**; it is simpler and cannot go stale.

## Step 2 — The panel

### Markup — `include/StacksPage.php`

A sixth `<dialog id="stackman-settings">`, **a direct sibling of the others inside
`.stackman-scaffold`**. The reason is written out three times already (`:194-205`, `:472-479`,
`:718-728`) and applies unchanged: outside the table wrapper, whose `container-type: inline-size`
would trap a fixed-position descendant; inside the scaffold, because the `--sm-*` colour tokens are
scoped there. **No `<form>` wrapper** — Enter would implicitly submit and a `method="dialog"` submit
closes and discards.

Copy `#stackman-confirm` (`:729-745`) as the shell — head / body / foot, with a
`role="status" aria-live="polite"` line in the foot and `.stackman-buttons--inline` holding Cancel
and Save.

A Settings button in the toolbar (`:122-138`), matching the others exactly:

```html
<button type="button" class="stackman-btn" id="stackman-settings-btn">
  <i class="fa fa-cog"></i> <?= _('Settings') ?>
</button>
```

### Rows — one table, in `stacks.js`

Build the body from an ordered table of `{key, label, control, choices, help}` so a future setting is
one entry. Each row is **`.stackman-field`** — the unused style at `sheets/stack.manager.css:1567-1620`
gives label above, box full width, hint below, which is exactly the shape asked for. The help text
is a visible line, never a `title` attribute: the rule is stated at `StacksPage.php:214-217` — a
tooltip cannot be reached on a phone.

**Move the explanation prose across verbatim** from `stack.manager.settings.page` (`:37-38`, `:43-45`,
`:53-62`, `:70-75`). It is already written, already in the right voice, and includes the selfh.st
CC BY 4.0 attribution, which must survive the move.

The stack-folder row gets the existing folder browser beside it — `pickerOpen(input)`
(`stacks.js:4488`), already wired for `data-tool="browse"` chips at `:6590-6593`.

### Behaviour — `stacks.js`

| Concern | Reuse |
|---|---|
| open | `call('settings', {})` first, fill the rows, then `showModal()`, then **focus the first control explicitly** — every dialog here does, and the reason is at `:5967-5970`: the browser's own first-focusable choice is never where anyone wants to land |
| dirty | compare the controls against the values the open fetched; enable Save the way the editor does — a bare `.disabled` assignment (`:8888-8898`) |
| Escape | join the existing chain and `preventDefault()` on your own handler, as the sections panel, outline, tab menu, devices panel and find bar all do (`:3619`, `:3736-3743`, `:7596-7597`, `:6609-6610`, `:8716`) |
| discard | **use `askConfirm()` (`:9596`), not `window.confirm()`.** The editor's `confirmDiscard()` (`:6762`) is a native dialog; the project has a real one and it is better |
| backdrop click | hand-rolled, as in all six existing dialogs (`:6833-6842`) — `<dialog>` fires no backdrop event |
| save | `call('settings-save', {...})`; on failure show the message in the foot's status line and leave the panel open with the values intact |
| after save | if the reply says `reload`, say so in one sentence and `location.reload()`. Otherwise close, and `refreshRows()` (`:9290`) only if the folder changed. **This would be the first `location.reload()` in `stacks.js`** — justified because the nav, the page's own prose about where stacks live, and the flash-drive warning are all render-time |

**Deep link:** on load, `location.hash === '#settings'` opens the panel. That is what the signpost
page links to.

### Style — `sheets/stack.manager.css`

One new dialog shell copying `.stackman-confirm`'s recipe (`:5372-5513`) — its own class, its own
`:not([open])`, `::backdrop` and `@starting-style` block, and **no z-index**: `showModal()` promotes
to the top layer, which outranks everything including the context menu's 9999 (`:1677-1685`).

Two traps at the top of that file that bite here (`:10-62`): only `--background-color`,
`--text-color`, `--border-color` and the `--orange/red/green-500`, `--gray-NNN` variables are safe —
Unraid 7's Vue bundle defines `--background`, `--border` and `--muted` as bare HSL triplets, so a
`var(--background, #1c1c1c)` fallback never fires. And Unraid styles every `<button>`, which is what
the `unapi` class on the scaffold and the local `all: unset` reset exist to escape.

## Step 3 — The signpost, which is also the way back

Rewrite `stack.manager.settings.page` to hold two things and nothing else:

1. One short paragraph — Stack Manager's settings are in the app now — and a link to
   `/Docker/Stacks#settings`, which opens the panel.
2. **The stack folder, still editable here**, with its own explanation in Adrian's words: set it here
   only if you moved where stacks are stored by hand and the Stacks page can no longer find them.

**Keep this field on the existing `/update.php` form** with its `#file` and `#command` hidden fields
(`:27-29`) rather than routing it through our own endpoint. That is the entire point of keeping it:
if the Stacks page, its JavaScript or `action.php` are broken, this still works and can put the path
back. Say so in a comment so nobody "tidies" it into the new endpoint later.

`stack.manager.plg`'s launch entity (`:6`, `Settings/&name;.settings`) is unchanged, so the Plugins
list still lands somewhere useful.

## Step 4 — Build the dead switch

Verified on the server, so this is mechanism rather than guesswork: pages are keyed by bare filename
and the **last plugin folder alphabetically wins**; `stack.manager` loads after
`dynamix.docker.manager`. So a `DockerContainers.page` in our own folder replaces the stock one.

**The whole feature is one file, appearing and disappearing.** Ship a dormant template —
`shadow/DockerContainers.page.tmpl`, holding a header whose `Cond` can never be true — and have
`apply_settings` copy it to `<plugin>/DockerContainers.page` when `TAKEOVER_DOCKER_TAB="true"` and
`rm -f` it when false. Our own page is already `Menu="Docker:0"`, so with the stock list suppressed
it is the only Docker sub-tab. `Docker.page` — the top-nav button itself — is **not** touched, so the
navigation looks unchanged.

Four things that matter:

- **The template must not be named `*.page` while dormant.** Every `.page` file in the plugin folder
  is picked up by PageBuilder, so a dormant one would be permanently live. Hence `.tmpl`.
- **LF endings.** Unraid splits a `.page` on a literal `\n---\n`; a CRLF file is silently discarded
  with one line in the syslog.
- **It fails in the safe direction.** If Unraid ever renames its file, our shadow stops matching and
  the stock list simply comes back.
- **A reboot does not undo it.** Unraid reinstalls plugins at boot, which re-runs
  `stack.manager.plg:81` → `apply_settings`, which re-creates the shadow while the setting is on. For
  everything else in this project a reboot is the panic button; here it is not. The way out is the
  switch, from either the panel or by deleting the file.

Then remove the "dead setting" entry from `docs/settings-ideas.md:75-82`, which exists only because
of this.

## Step 5 — Docs

`docs/settings-ideas.md`'s "How a setting works here" (`:14-35`) documents the mechanism this plan
replaces and will be actively misleading. Rewrite it: a setting is now an entry in the allowlist plus
a row in the panel's table; there **is** a server-side allowlist now, so adding a control is no
longer enough; and settings do reach the browser, through the read action rather than a `data-*`
attribute. Fix the sibling framing line in both idea files, which sorts entries by "does it need a
switch on the settings page".

## Files

| File | Change |
|---|---|
| `include/Settings.php` | **new** — allowlist, read, validate, atomic write, run `apply_settings` |
| `include/action.php` | `settings` and `settings-save` |
| `include/StacksPage.php` | the dialog, the toolbar button, `require_once` the new include |
| `javascript/stacks.js` | the row table, open/dirty/save/close, the `#settings` deep link |
| `sheets/stack.manager.css` | the dialog shell; reuse `.stackman-field` as-is |
| `stack.manager.settings.page` | rewritten as signpost + the folder recovery field |
| `scripts/apply_settings` | install or remove the shadow page |
| `shadow/DockerContainers.page.tmpl` | **new**, dormant |
| `default.cfg` | keep all five keys; `TAKEOVER_DOCKER_TAB` is real now |
| `tests/server/settings.php` | **new** — the validator and the writer, run on the server |
| `docs/settings-ideas.md` | rewrite the mechanism section; drop the dead-setting entry |

## Verification

Locally — none of these can see any of this, which is worth stating plainly; the server pass is the
test:

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
node tests/image_import.js
node tests/ca_convert.js
python tests/validate_schema.py
```

On the server, before the browser: `php -l` over every `include/*.php`, then
`tests/server/settings.php` — the validator against good values and against `''`, `..`, a relative
path, `/`, `/etc`, a choice that is not on its list, and a key that is not in the allowlist; and the
writer, confirming the cfg keeps its unknown keys, is never left half-written, and that
`apply_settings` ran.

Then in the browser (Claude for Chrome reaches the box and works — the editor was driven this way on
2026-08-17):

1. The Settings button opens the panel; every setting shows its current value and its explanation.
2. Save is grey until something changes, and closing with a change pending asks first.
3. Change the icon setting alone → saves, closes, **no reload**, and the setting is in the cfg.
4. Change the stack folder to something invalid → refused with a sentence, panel stays open, nothing
   written. Then to something valid → saved, page reloads, the table reads from the new folder.
5. The folder browser opens from the panel and fills the box.
6. Change where Stacks appears → saved, page reloads, the navigation has moved.
7. Unraid's Settings → Stack Manager shows the signpost; its link opens the panel; its folder field
   still saves on its own, with the Stacks page's JavaScript irrelevant to it.
8. Both Unraid themes.

**The takeover switch is tested last, and deliberately:**

9. Turn it on. Unraid's own container list disappears from the Docker tab and ours is the only one.
   Turn it off. The stock list comes back. Confirm the shadow file appears and disappears.
10. **This is Adrian's production server, with roughly 65 running containers he manages from that
    stock list.** The blast radius is UI only — no container is touched and nothing stock is
    patched — but while it is on, his usual list is not there. Get his explicit go-ahead before
    turning it on, do it last, confirm the way back first, and **leave it off**.

## Left out

- Deleting Unraid's Settings page. It is the recovery route and the Plugins-list target.
- Saving as you type. A Save button was the decision; the folder path is why.
- Shadowing `Docker.page`, `AddContainer.page` or `UpdateContainer.page`. Suppressing the container
  list is the whole of "replace the stock Docker tab", and touching the nav button would put our
  own name on Unraid's furniture.
- Making the Apps dialog's third-party icon loading obey `ICON_FETCH`. A real inconsistency
  (`docs/settings-ideas.md:93-95`), and its own decision.
