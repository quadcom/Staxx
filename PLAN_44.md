# PLAN_44 — one click to open, and a Manage tab that runs the thing

**Status: APPROVED 2026-08-19.** Outstanding. Every decision below was Adrian's answer to a direct
question; nothing here is inferred. Tick phases off in the table near the foot as they land.

> **No work starts until the agents on PLAN_41/42/43 have finished** — this plan touches `stacks.js`,
> `StacksTable.php` and `action.php`, which those are editing now.

## Context

Two things are wrong with the shape of the app today, and one thing is missing.

Clicking a stack's icon opens a menu. The icon is the biggest, most obvious target on the row, and it
does the least interesting thing — the menu is also reachable from the row's own button. Meanwhile
the editor, the thing people actually came for, needs a menu trip to reach.

And once a stack is running, StaXX has nothing to offer. Its output goes to a panel that slides up
at the bottom of the page and scrolls the whole page down to reach it — Adrian's words: *annoying*.
There is no shell, no way to look inside a container, and no live log. Every one of those is a trip
to Unraid's own Docker page or a terminal, which is exactly the split-brain this plugin exists to
end.

So: the icon opens the editor, right-click gets the menu, the page-level output panel goes away, and
the editor grows a second tab holding a live log, a shell, a file browser and the run buttons for
whichever container you pick.

---

## Part A — what clicking does

### A1. Left-click on a stack icon opens the editor

`scaffold`'s delegated click listener (`stacks.js:12308`) currently routes any `[data-menu]` button
to `openMenu()`. For `data-menu="stack"` it routes to `editStack()` instead — the same path the
menu's *Edit compose file* entry uses today (`stacks.js:11119`), which posts `read` and calls
`openEditor()`.

Refusals stay as they are: a review-locked or unparseable stack behaves exactly as the current Edit
entry does.

### A2. Left-click on a container icon opens the editor on the form, at that service

`data-menu="container"` opens the same editor and scrolls the form to that service's section.
The button already carries `data-service` holding the compose service name (`StacksTable.php:850`,
where the deliberate difference from the row's `data-service` is documented — use the button's).

`openEditor()` gains an optional focus target, applied after `showModal()` alongside the existing
post-show `measure()/paintGutter()` block, because a closed dialog measures zero.

### A3. Right-click anywhere on a row opens that row's menu

New `contextmenu` listener on `scaffold`. It walks up from the event target to the nearest
`.staxx-row`, finds that row's `[data-menu]` button, and opens the menu built from it —
`buildFolderMenu` / `buildStackMenu` / `buildContainerMenu`, unchanged. `preventDefault()` only when
a row was found, so right-clicking the page background still gives the browser's menu, and so does
right-clicking inside a text field.

The menu button stays on every row. It is the touch route and the discoverable one.

`openMenu()` gains a cursor-point mode: positioned at the pointer instead of the trigger's
`getBoundingClientRect()`. The existing viewport flip/pull logic (`stacks.js:12098-12126`) applies to
both, unchanged.

While in here, close out what the exploration found missing on the menu, because it is minutes of
work and right-click makes the menu the primary route: `aria-haspopup`/`aria-expanded` on the
triggers, `role="menuitem"` on the items, focus moved to the first item on open, arrow-key movement,
and focus restored to the trigger on close.

### A4. The Logs button opens the Manage tab

Each row's Logs button (`staxx_row_actions_html()`) and the menu's *Logs* entry both open the editor
on **Manage**, with that container selected — the stack's Logs button selects the *All* tab. They no
longer run `compose logs --tail 200` into a panel.

---

## Part B — the page-level output panel goes away

Adrian: *"Remove it, it was annoying as it made the whole page scroll down. Instead, the state can be
displayed in the state column."*

`#staxx-log-panel` and `.staxx-log` are deleted from `StacksPage.php` and the stylesheet. `follow()`
and the single global `poller` (`stacks.js:535`) are replaced by per-row job tracking. Jobs
themselves — `staxx_start_job()`, the `job` action, the `STAXX_JOB_END` sentinel — are untouched.

**While running.** The state column shows a spinner and what it is doing: *Starting…*, *Stopping…*,
*Restarting…*, *Recreating…*, *Updating…*. Labels come from the existing `BUSY_LABEL` map
(`stacks.js:10523`) extended to cover every verb. Last-line-of-output was considered and rejected —
the column stays narrow.

**On failure.** The row shows a sticky failure marker in the state column and keeps it until
acknowledged. Clicking it opens that stack's editor on Manage with the failed job's output already
in the log pane. Nothing pops up over what you were doing.

The row therefore has to remember the job id past the poll, and the job log has to survive being
read later — it already does, for an hour (`staxx_prune_jobs()`).

**Folder runs.** `folder-run` already returns `jobs[] = {name, job}`. Each stack row reports itself,
and the folder row summarises: *6 of 9 started, 1 failed*.

---

## Part C — the editor's two tabs

### C1. The shell

`.staxx-modal` is a fixed three-row grid, `auto 1fr auto`, with `.staxx-modal-body` as the only
`1fr`. It becomes four rows — `auto auto 1fr auto` — with the new tab strip as the second.

Two tabs: **Configure** and **Manage**. Copy `.staxx-tabstrip` / `.staxx-tab` (`role="tab"`,
`aria-selected`, the inset accent shadow that keeps the strip's height from shifting) — *not*
`.staxx-ca-tab`, so the dialog is internally consistent.

`.staxx-modal-body` keeps its `data-view` of `form | split | yaml` and everything that depends on it,
untouched. A new sibling `.staxx-modal-manage` holds Part D. Which of the two is visible is one new
attribute on `.staxx-modal`; the Form/Split/Compose segmented control and the file tab strip hide
with the Configure pane, since neither means anything on Manage.

The single-pane width trick (`css:1947`, the `:has()` clamp) must not fire from Manage — Manage
always wants the full `min(168rem, 96vw)`.

### C2. Unsaved edits versus a run button

Pressing a run button on Manage with unsaved changes on Configure asks first, in the existing confirm
dialog: **Save and start** / **Start anyway** / **Cancel**. "Start anyway" notes in the log pane that
the file on disk was used.

---

## Part D — the Manage tab

```
┌ All │ jellyfin ● │ postgres ● ⚠ ─────────────────────────┐   container tab row
├──────────────────────────┬─────┬───────────────────────────┤
│                          │  ▮  │                           │
│   live log               │  ▶  │   shell                   │
│                          │  ↻  │                           │
│                          │  ⟳  │───────────────────────────│
│                          │  ⊕  │   files                   │
└──────────────────────────┴─────┴───────────────────────────┘
```

### D1. The container tab row

*All*, then one tab per service. Always shown, even for a one-container stack — the layout never
jumps, and the row is where the state light lives.

The service list comes from the editor's own parsed `MODEL.services`, which is the only source that
exists for a stack that has never been started. Live facts come from the `state` action's snapshot,
already keyed by service for exactly this reason (`StacksTable.php:996`).

Each tab carries: the container's icon (same resolution path as the row), a live running light, a
warning marker for recently-restarted / bad-exit / failing-health, and live processor and memory from
what the background collector already writes (`Stats.php`).

Selecting a tab switches all three panes and re-points the buttons.

### D2. The buttons column

A vertical column between the two sides: **Stop, Start, Restart, Recreate, Update**. Five, not four —
Adrian chose to split "rebuild" rather than have anyone guess which of the two it meant.

Above them, a scope switch: **this container** / **whole stack**, always visibly saying which is
armed. Default is this container.

Verb work in `staxx_job_verbs()` (`Stacks.php:2997`):

| button | stack scope | service scope |
|---|---|---|
| Stop | `down` *(exists)* | `stop` *(exists)* |
| Start | `up -d --remove-orphans` *(exists)* | `up -d` *(exists)* |
| Restart | *(exists)* | *(exists)* |
| Recreate | **new** `up -d --force-recreate --remove-orphans` | **new** `up -d --force-recreate` |
| Update | **new** stack form `['pull','up -d --remove-orphans']` | `['pull','up -d']` *(exists)* |

The long comment at `Stacks.php:2942` explains why `restart` deliberately avoids `--force-recreate`.
That reasoning stands and Restart keeps its behaviour; Recreate is the new, separate, honest verb.

Every run reports through the state column of the underlying row (Part B) *and* into this log pane.

### D3. The log pane

**Content:** the container's own output, with anything StaXX runs interleaved and clearly marked as
StaXX's, so a failed recreate lands where you were already looking.

**The *All* tab** weaves every container in the stack together, each line prefixed with which one
said it. Adrian and I agree this is the most useful single thing on the tab — it is how you see that
A failed because B was not ready.

**Controls:** pause/follow with a jump-to-live button and a scroll-up auto-pause; search, with a
filter mode that hides non-matching lines; timestamp and wrap toggles; copy-visible and
download-all.

**A stopped container still shows its last logs** — usually the reason you came. Only the shell and
files wait for it to run.

**Server side.** `docker logs --tail N --timestamps` for the backlog, then a detached follower per
selected container writing to a file under `/tmp/staxx/`, polled with a byte offset. This is the
same shape as jobs, and it needs the one gap the exploration found closed: `staxx_job_log()` reads
the whole file every call with no offset parameter, and `follow()` replaces the pane's entire text
each tick. Both become append-from-offset. Followers die on the same heartbeat-goes-stale rule the
stats collector uses (`scripts/stats-collector.sh`) — close the tab and following stops on its own.

### D4. The shell

**A conversation, not a screen.** A real live shell: prompt, colours, history, Ctrl-C, tab
completion. Output scrolls past. Full-screen programs — nano, htop, mc — are *not* supported; when
one is launched it is detected and refused with a line pointing at Unraid's terminal. A screen
emulator is a separate later plan and nothing here has to be rewritten to add it.

That combination requires a pseudo-terminal (there is no tab completion and no prompt without one)
while only rendering a small set of control sequences. Concretely:

- A session is `setsid docker exec -it <container> sh -c 'exec bash || exec sh'` with stdin from a
  named pipe and output appended to a file, both under `/tmp/staxx/exec/<id>/`. Session ids are
  `bin2hex(random_bytes(8))`, validated by the same 16-hex-character test jobs use.
- Four new actions: open, write (a keystroke or a line), read (from a byte offset), close.
- The client renders colour, carriage return, backspace and bell. On seeing the alternate-screen
  request (`ESC[?1049h`) it stops rendering, says the program needs a full terminal, and offers to
  send Ctrl-C.
- **Root, in the running container.** An image with no shell at all fails with a plain sentence
  saying so.
- **Lifetime: per container, until the editor closes.** Switching container tabs leaves each shell
  exactly where you left it, half-typed line included. Closing the dialog closes them all, and a
  reaper clears anything abandoned — the heartbeat rule again, so a crashed browser leaves nothing
  running.

**Guarding.** A Settings switch, **on by default**, plus a one-time warning the first time a shell is
opened, saying what it is and that changes inside a container vanish on the next rebuild. The
warning's "seen" flag goes in the plugin's config on the flash drive, not in the browser, so it is
once per server. Note the plugin stores nothing in the browser today and this plan does not start.

This action is the one place in the plugin where user input reaches a shell without an allowlist. It
must be POST-only like everything else, the container name must be checked for **membership in this
stack's containers** — the same rule service names already get (`Stacks.php:3074`) — and the
`timeout` wrapper does not apply to a session that is meant to live. Those three points get comments
saying why.

### D5. The file manager

Inside the container, starting at the container's working directory.

Can: open a text file in the editor on the Configure tab and save it back; download and upload;
rename, delete and make a folder; and show owner and permissions on every entry, because almost every
container problem is a permissions problem.

**Folders that come from your server are marked as such**, with a plain note that everything outside
them vanishes on the next rebuild. Mount points come from the compose model, which the editor already
has parsed.

Delete asks first and says the same thing.

Existing shapes to copy rather than reinvent: `staxx_browse_dirs()` for a capped, refusing-outside
listing; `staxx_list_files()` / `file-read` / `file-save` / `file-rename` / `file-delete` for the
per-entry shape, base64 for binary, and `staxx_looks_text()` for the is-it-text test. The new actions
are the same contracts with a container in front of them.

### D6. Above the panes

One line per selected container: how long it has been up, how many times it has restarted, and its
health check result if it has one.

And a few one-press common jobs, so the shell is not the only route to something routine: list what
is in the config folder, fix ownership on the mounted folders, show the environment.

### D7. Sizes and narrow screens

Fixed proportions, with a click to collapse any pane so another gets the room. Not draggable — the
form/text split next door is not draggable either, and adding drag-and-remember means new storage
machinery this plan does not need.

Under the existing 45rem breakpoint the three panes become their own small tab row — logs, shell,
files, one at a time — with the buttons column pinned. The breakpoint literal must be written
identically in the stylesheet and in JS, as the comment at `stacks.js:1069` requires.

---

## Out of scope, deliberately

- **Containers StaXX did not create.** Stacks only. Unraid-native containers are what the importer is
  for.
- **A full screen emulator.** Its own plan, later.
- **Draggable panes anywhere.** Including the existing form/text split.
- **Anything stored in the browser.** The plugin stores nothing there today.

---

## Phases

Each phase is deployable and leaves the page working.

| # | Phase | Contains |
|---|---|---|
| 0 | Foundations | Offset tailing on job logs; append-not-replace on the client; `recreate` and stack-scope `update` verbs; state-column progress, sticky failure marker and folder summary; **remove the output panel** |
| 1 | Clicks | Icon opens the editor; right-click anywhere opens the row's menu; cursor positioning; menu accessibility; Logs button re-pointed *(needs phase 2's tab to exist — until then it opens the editor)* |
| 2 | Editor shell | Configure/Manage tab strip; Manage layout skeleton; container tab row with lights, icons, warnings, stats; buttons column and scope switch; unsaved-edit guard |
| 3 | Log pane | Container log follower with offset; interleaved StaXX output; *All* tab; pause/follow, search/filter, timestamps, wrap, copy, download |
| 4 | Shell | Session actions; pty; polling; control-sequence rendering; full-screen refusal; Settings switch and one-time warning; reaper |
| 5 | Files | List, read, save, upload, download, rename, delete, mkdir; owner and permissions; mounted-folder marking; open-in-editor |
| 6 | Trim | Uptime/restarts/health line; common-jobs buttons; narrow-screen pane tabs |

## Risks worth naming before starting

- **`stacks.js` is 13,229 lines in one IIFE.** Manage is a large addition. It should go in its own
  file the way the parser did, or the next typo takes the whole page down with it.
- **The shell is the plugin's first unallowlisted path to a command.** Treated above; it is the part
  to review hardest.
- **A log follower per open container** is a process the browser is responsible for ending. The
  heartbeat rule is the answer, and it is already proven by the stats collector.
- **Part B is a removal.** Deleting the output panel touches every caller of `run()` and `follow()`.
  It is phase 0 precisely so nothing is built on top of it first.

## Verification

Nothing here can run on Windows. Per phase:

- `node --check` on both browser files, plus `tests/js_undeclared.js` — Manage will be a lot of new
  names in strict mode, which is exactly what that test catches.
- `php -l` over `include/*.php` on the server after each deploy.
- New server-side checks under `tests/server/`, following the existing headers-give-the-commands
  pattern: `console.php` covering the new verbs' refusals (wrong scope, unknown container, review
  lock), the session id validation, the offset reader, and the container-file path guards. Every
  refusal path must be provable without reaching a shell — `staxx_start_job()` returning `''` plus an
  error string is what makes that testable.
- Row markup and the state column by rendering rows to a file with a stub `_()`, stripping comments
  before counting tags.
- The three panes need a browser. Deploy and look — one stopped container, one running, one
  multi-container stack, and one image with no shell.
