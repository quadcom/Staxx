# The takeover is a one-way door, and clearing the lock shuts it

## Context

Importing an Unraid template leaves the template's own container in place, still holding the
container name the new stack pins. Two menu items exist for that moment: **Take over and start**,
which switches the old container off, renames it aside and starts the stack in its place, and
**Clear the lock only**, which just removes the "needs review" marker and — as its own hint admits —
"does not deal with a container already using this name".

Both items are gated on the review marker, in the browser and again on the server. So clearing the
lock is irreversible: the takeover disappears from the menu, `staxx_start_handover()` refuses
outright (*"This stack is not awaiting review, so there is nothing for a handover to take over."*,
`Stacks.php:3112`), and a plain **Start** fails on Docker's raw "name already in use" with no
explanation and nothing offered. There is no way back short of hand-creating `NEEDS-REVIEW.md`,
which nothing hints at.

Confirmed live on the server. `Services/GoAccess-NPM-Logs/` holds `compose.yaml` and nothing else —
no review marker — its file pins `container_name: GoAccess-NPM-Logs`, and `docker ps -a` shows a
container of that name, exited, carrying no `com.docker.compose.project` label at all. That last
detail is the whole cure: **the review lock was never the real safety gate.** The gate is already
`staxx_start_handover()`'s own check at `Stacks.php:3129-3136`, which refuses any target whose
container belongs to a compose project. Only a label-less container — precisely what an Unraid
template or a hand-made container leaves behind — can ever be taken over. The lock adds nothing to
that, and costs the only way out.

So: **let the takeover stand on its own check instead of the lock.** Whenever a container outside
any compose project holds a name this stack pins, the takeover is offered and works, review marker
or no review marker. Nothing new is permitted that was not already permitted; only the ordering of
two clicks stops mattering.

Decided with Adrian: menu item only, no new chip on the row, and no extra warning in front of
"Clear the lock only" — once the takeover is always available, clearing the lock is a recoverable
mistake rather than a permanent one.

---

## The change

### 1. One helper for "someone outside compose holds a name we need"

New in `include/Stacks.php`, beside `staxx_handover_targets()` (~line 2865):

```
staxx_foreign_holders(string $rel): array
```

The subset of `staxx_handover_targets($rel)` whose container's `project` in
`staxx_docker_container_names()` is `''`. That is exactly the set a handover will accept, so three
callers below can ask the same question the same way and none of them can drift from what
`staxx_start_handover()` actually allows. Both underlying reads are statically cached (one
`docker ps -a` per request, `Stacks.php:2831`; `staxx_compose_meta()` is cached per file), so this
costs array lookups per stack and no extra shelling out.

### 2. The server stops requiring the lock

`include/Stacks.php`:

- **`staxx_start_handover()`** — delete the `!staxx_review_locked($rel)` refusal (~3111-3114) and
  rewrite its comment: the guard is the label-less-container check below, which the lock was only
  ever standing in front of.
- Its review-note handling becomes conditional. `$reviewName` empty is now the ordinary unlocked
  case, not the *"review note has gone missing"* refusal (~3139-3144) — drop that refusal, leave
  `$heldPath`/`$reviewPath` as `''`, and skip the `@rename()` and its rollback when there is no
  note. Everything else in the function is unchanged, including that nothing is written until every
  refusal has passed.
- **`staxx_handover_script()`** — emit the undo's `mv <held> <review>` line (~3037) and the success
  `rm -f <held>` line (~3073) only when `$heldReviewPath !== ''`. Every other line is untouched.
- **`staxx_start_takeover()`** — the same review gate at ~3349, dropped the same way, and its
  inline `if [ "$ec" -eq 0 ]; then rm -f … else mv … fi` (~3405) made conditional on there being a
  held note. Needed for symmetry: a stack can have both a live project *and* a label-less holder,
  and `handover-check` answers `rebuild` for that, which routes to this function.
- **`staxx_start_job()`** (~3846, after the existing review-lock refusal) — for the whole-stack
  `up` and `restart` verbs only, refuse when `staxx_foreign_holders()` is non-empty, with a
  sentence naming the container and pointing at "Take over and start". This is the part that
  explains the failure instead of leaving Docker to. `down`/`stop`/`pull` must not be touched, and
  a stack's own running containers carry the project label so they are never caught by this.

### 3. The row carries whether a takeover applies

- **`staxx_list_stacks()`** (`Stacks.php` ~1315, beside `'review'` and `'handover'`) gains
  `'takeover' => staxx_foreign_holders($rel) !== []`.
- **`include/StacksTable.php`** (~1057) puts it on the stack row's icon button as
  `data-takeover="…"`, beside `data-review` and `data-handover`.

### 4. The menu offers it

`javascript/stacks.js`, `buildStackMenu()` (~14132):

- Read `var takeover = d.takeover === '1';` alongside the existing flags.
- The `if (handover) … else if (review) …` block is unchanged — a locked stack keeps exactly the
  two items it has today.
- Inside the run-verb block `if (parses && !review && !handover)` (~14166), when `takeover` is
  true put **Take over and start** *first*, above Start, with a hint saying a container outside
  StaXX holds this stack's name. Start remains where it is; it will now be refused with the
  sentence from part 2 rather than failing obscurely.
- `openTakeover()`'s `mode === 'none'` branch (~12943) offers a "Clear the lock" button, which is
  wrong on an unlocked stack. Pass the caller's `review` flag in and use it: locked keeps today's
  wording, unlocked says nothing holds one of its names so it will start normally, with no
  clear-the-lock button.

### 5. Text that no longer matches the menu

Five server refusals (`Stacks.php:3848`, `4064`, `4264`, `4399`, `4762`) and the review badge's
tooltip (`StacksTable.php:1114-1121`) tell the reader to choose **"Mark as reviewed"**. No such
item exists — it is "Clear the lock only", with "Take over and start" beside it. Correct the
wording to name what is actually on the menu.

---

## Files

| File | Change |
|---|---|
| `include/Stacks.php` | the new holders helper; the review gate dropped from handover and takeover, with the note handling made conditional; the collision refusal on `up`/`restart`; `'takeover'` on the row; five stale sentences |
| `include/StacksTable.php` | `data-takeover` on the stack row's icon button; the review badge's tooltip |
| `javascript/stacks.js` | the menu item on an unlocked stack; the "nothing to take over" dialog's two wordings |
| `tests/server/handover.php` | line 209 asserts the very refusal being removed |
| `tests/server/takeover.php` | the same assertion for the project route |

Order: **1 and 2 first** (the server is the thing that actually refuses today), then **3**, then
**4**, then **5**. Each part stands on its own — part 2 alone already makes the endpoint work for
anyone calling it.

## Verifying it

Locally, after every edit:

```sh
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node tests/js_undeclared.js
```

Both, every time — `stacks.js` is one strict-mode IIFE and a name declared nowhere throws at run
time where a syntax check cannot see it.

On the server, after deploying:

```sh
php -l /usr/local/emhttp/plugins/staxx/include/Stacks.php
php -l /usr/local/emhttp/plugins/staxx/include/StacksTable.php
php /boot/staxx-dev/tests/handover.php
php /boot/staxx-dev/tests/takeover.php
php /boot/staxx-dev/tests/review.php
```

Then the real case, which is already sitting there waiting: **`Services/GoAccess-NPM-Logs`**, not
locked, with an exited label-less container of that name.

- Its row's menu shows **Take over and start**. Almost every other stack's does not — check a
  running stack and a plain stopped one both look exactly as they do today.
- Pressing **Start** on it refuses with the sentence naming the container, rather than Docker's
  "name already in use".
- **Take over and start** names `GoAccess-NPM-Logs` in its confirmation, and on going ahead the old
  container is stopped, renamed to `GoAccess-NPM-Logs-before-staxx` and the stack comes up under
  the real name. The row then asks whether it works.
- Answering **It does not work** must put it back exactly: original name, original state, and a
  fresh `NEEDS-REVIEW.md` explaining what happened — which is the existing behaviour and must not
  have changed for a stack that had no marker to begin with.
- Answering **It works** removes the set-aside container and clears `HANDOVER.md`.
- A locked stack still behaves as it does today: no run verbs, the two review items only, and a
  handover through them still moves the marker aside and deletes it on success.
