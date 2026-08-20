# "Update" means three different things, and none of the names say which

## Context

The settings panel offers "Wait for you to press Update". No control called **Update** exists.
What exists instead is three items with overlapping names and genuinely different behaviour:

| Where | Label today | What it actually runs |
|---|---|---|
| Page header | Update all | the update queue over every stack that has something waiting |
| Stack menu | Update images | `pull` **only** — images fetched, containers left on the old ones |
| Container menu | Update image | `pull` then `up -d` — the real thing, for that one container |
| Either menu, conditional | Update now | `update-apply`, which is the same `pull`+`up -d` |

So the stack-scope item promises an update and delivers a download; the container-scope item does
the job but is named as though it too were only about the image; and **Update now** appears only
after a check has found something (`updateEntry.state === 'update'`), duplicating a command that
should simply always be available.

Two further things nothing tells the reader. **Restart** is not a plain restart — it is
`up -d` then `restart`, so it rebuilds any container whose settings or image changed, which makes
it the missing second half of the stack-scope "update". And the **update pill** on the row, the
thing that lights up to say an update is waiting, is a plain `<span>`: the obvious thing to press,
and pressing it does nothing.

Verified on the server before writing this: Docker 29.5.3, Compose v2.40.3, and **no** running
compose container is on a stale image — every one is on the newest image its tag resolves to. So
`pull` + `up -d` genuinely does rebuild onto the new image. The machinery is right; the naming is
what is broken.

Decided with Adrian: **Update** means one thing everywhere — fetch the new image and rebuild the
container on it. The pull-only action stays, under Docker's own word for it, **Pull**. And the pill
becomes the button the settings text has always implied.

### The bug underneath the naming

Traced on the server while writing this. `Services/it-tools` shows **update ready** and its menu
offers nothing at all — no Update now, no Skip this version, no countdown items.

The update items on a row's menu are all gated on one condition,
`updateEntry.state === 'update' && updateEntry.image` — the row's pill must carry an image name. A
service-scope pill does carry one (`staxx_updates_for_row()` sets `$pill['image'] = $image`). A
stack-scope pill never does: `staxx_updates_aggregate()` ends with

```php
'image'  => '', // a summed-up row speaks for more than one image
```

blanked unconditionally, **including when the stack has exactly one service**. And a single-service
stack renders no container row at all (`$expandable = count($kids) > 1`), so its stack row is the
only row it has. it-tools is precisely that: one service, `sharevb/it-tools:latest`, update ready
in the data, and no reachable row whose pill carries the image.

**12 of the 17 rows currently showing "update ready" are in this state.** So for most stacks there
is genuinely no way to apply an update from the interface, which is the question that started this.

Two defects, both small:

- **The gate asks for the wrong thing.** Applying an update needs a stack name and optionally a
  service — `update-apply` never takes an image. Only *Skip this version*, the countdown items and
  *Roll back* are keyed on an image. So the update action must be gated on state alone, and only
  the image-keyed items on the image.
- **The aggregate discards the one image it has.** The comment's reasoning holds for two or more
  services and not for one. With exactly one pill, that pill's image should carry through, which
  restores Skip and the countdown on those rows too.

---

## The change

### 0. The gate, and the single-service aggregate

- `include/Updates.php`, `staxx_updates_aggregate()` (~1077): when there is exactly one pill, carry
  its `image` through instead of `''`. Reword the comment to say what is true — a row speaking for
  several images cannot name one, a row speaking for one can.
- `javascript/stacks.js`, both menus (~14219, ~14378): split the single `if`. The update action is
  offered on `state === 'update'` alone; *Skip this version*, the countdown items and *Roll back*
  stay inside a nested check on `updateEntry.image`, since each of them keys on it.

This alone fixes it-tools and the other 11. Parts 1-4 below are what stop the same confusion
recurring.

### 1. One command, one name, always there

`javascript/stacks.js`:

- **Stack menu** (`buildStackMenu()`, the run-verb block ~14184): the item currently labelled
  *Update images* becomes **Pull images** and keeps running `pull` unchanged — its existing comment
  already explains why a pre-fetch that restarts nothing is worth having on a busy stack, and that
  reasoning stands. Add **Update** beside it, always present under the same gate as Start/Stop,
  running the shared apply below.
- **Container menu** (~14367): *Update image* becomes **Update**, running the same shared apply.
  Add **Pull image** beside it for the pull-only form, so both scopes offer the same pair.
- **Delete "Update now"** from both menus (~14220, ~14379). It ran `update-apply`, which is exactly
  what **Update** now runs, so keeping both would be two labels for one command. Where a countdown
  is live (`updateEntry.due > 0`), **Update**'s hint says it installs now rather than waiting —
  that sentence is the whole of what "now" was carrying.
- Keep *Check this image again*, *Resume/Cancel the countdown*, *Skip this version*, *What changed*,
  *Roll back* and *Rebuild* exactly as they are.
- Rename `updateNow()` (~12080) to `applyUpdate()`; it already does the right thing — busy state,
  `update-apply`, job tracking, `refreshUpdates()` — and becomes the one route for the menu items
  and the pill alike.

### 2. A stopped container is pulled, never started

Today the container menu picks the verb itself: `run(stack, up ? 'update' : 'pull', …)`, so Update
never starts a stopped container. That choice must survive, but it belongs on the server now that
one client route serves every caller. In `include/action.php`, `case 'update-apply'` (~768): when
the target is a single service whose container is not running, run `pull` instead of `update`. A
handful of lines, reusing `staxx_container_index()`. The client stops choosing verbs at all.

The menu item's hint says so when it applies: this container is stopped, so the image is fetched
and its next start uses it.

### 3. The pill becomes the button

- `include/StacksTable.php`, `staxx_update_pill_html()` (~283): emit a `<button type="button">`
  instead of a `<span>` **only** for the two states there is something to do about — `update` and
  `rebuild`. Every other state (`built`, `missing`, `error`, `tagmissing`) stays a `<span>`, because
  a button that does nothing is worse than text. Same classes, same `data-update-*` attributes
  either way, so `paintUpdatePill()` and `paintPillClock()` need no change.
- `sheets/staxx.css` (~2053): give the button form a pointer cursor, a hover and a visible focus
  ring. The span form keeps exactly today's appearance.
- `javascript/stacks.js`: one delegated click handler on the scaffold. `update` → the same
  confirmation **Update** uses, then `applyUpdate()`. `rebuild` → the existing `update-rebuild`
  route, which is what that state's own menu item already uses. The handler must find its stack and
  service from the row the pill sits in, the same way the menus do.

### 4. Say what Update does

- `include/Stacks.php`, `staxx_job_verbs()` (~3840): the `pull` verb's label becomes **Pull
  images**, the `update` verb's becomes **Update**. These strings appear in the job dialog's title,
  so they have to agree with the menu.
- The `update` and `pull` entries in that function's docblock (~3820): state plainly that Update
  fetches the image and rebuilds the container on it, and that Pull fetches only.
- `javascript/stacks.js` (~13540), the `UPDATE_MODE` setting: *Wait for you to press Update* becomes
  a sentence naming where Update is — the row's pill or its menu.
- `include/Updates.php` (~1017), the pill's own tooltip: say that pressing it fetches the new image
  and rebuilds the container on it.

---

## Files

| File | Change |
|---|---|
| `javascript/stacks.js` | the two menus' items and labels; `updateNow()` → `applyUpdate()`; the pill click handler; the settings sentence |
| `include/StacksTable.php` | the pill rendered as a button for the two actionable states |
| `sheets/staxx.css` | the actionable pill's cursor, hover and focus |
| `include/action.php` | `update-apply` pulls rather than updates when a single stopped service is the target |
| `include/Stacks.php` | the two verb labels and their docblock |
| `include/Updates.php` | the single-service aggregate keeps its image; the pill's tooltip |

Order: **0 first** — it is the actual bug, it stands alone, and it is what makes it-tools
updatable. Then **4** (the copy settles the vocabulary everything else follows), then **2**, then
**1**, then **3** — the pill's handler reuses what 1 builds.

## Verifying it

Locally, after every edit:

```sh
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node tests/js_undeclared.js
```

Both, every time — one strict-mode IIFE, and a name declared nowhere throws where a syntax check
cannot see it.

On the server after deploying: `php -l` over `include/*.php`, then `php /boot/staxx-dev/tests/updates.php`
and `updaterun.php`, which cover the update state and the queue.

Then on screen, and this is the part only a browser can show. **`Services/it-tools` is the case to
check first** — one service, update ready, and nothing on its menu today:

- Its menu offers a working Update, and *Skip this version* is there too now that its row's pill
  knows its image. Pressing Update fetches `sharevb/it-tools:latest` and recreates the container on
  it; `docker inspect` should then show the container's image ID matching what the tag resolves to.
- A multi-service stack's row still shows a summed-up pill with no single image, so it offers
  Update but not *Skip this version* — that item belongs on the container rows underneath, where
  each one names its own image.
- A stack menu offers **Update** and **Pull images**; a container menu offers **Update** and
  **Pull image**. Neither shows *Update now* any more.
- A row whose pill says an update is waiting: the pill has a pointer cursor, reachable by keyboard,
  and pressing it opens the same confirmation the menu's Update does.
- A pill saying anything else (`built`, `error`, a withdrawn tag) is not pressable and looks exactly
  as it does today.
- **Update** on a running container: the image is fetched and the container is recreated on it —
  check with `docker inspect` that the container's image ID now matches what the tag resolves to.
- **Update** on a stopped container: the image is fetched, and the container is still stopped
  afterwards.
- **Pull images** on a stack: images fetched, nothing restarted, containers still on their old
  image IDs until Update or Restart.
- The job dialog's title reads *Update* or *Pull images*, matching whichever was pressed.
