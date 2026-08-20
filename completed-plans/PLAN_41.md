# PLAN_41 — phase 2 of the importer: writing Unraid templates in

**Status: BUILT 2026-08-19**, shipped in one commit with `PLAN_42.md`. Sub-plan of PLAN_35, whose phase 2 this is. Phases 0
(`completed-plans/PLAN_37.md`, the review lock) and 1 (`PLAN_38.md`, the list) have shipped and this
builds directly on both.

**Built together with `PLAN_42.md`**, which is the other half — clearing the note hands the running
container over to the imported stack. Adrian chose on 2026-08-18 to ship the two as one piece of
work, because this half on its own produces stacks that cannot start.

## What you would notice

The Import panel's template rows get **tick boxes**. Choose a folder to put them in, press Import,
and StaXX writes each one as a stack — one at a time, with a running count, so a run of eighty is
watchable rather than a frozen page. Every stack it writes **arrives locked**: it appears on the
Stacks page marked as needing review, with every run button refused, and beside its compose file is a
plain-text note saying where it came from, what could not be translated, and what to check.

**Nothing is started, nothing is stopped, and no container is touched at any point.** The container
Unraid built goes on running exactly as it was. This phase only writes files.

## Scope, and what is deliberately left

Unraid templates only — that is what PLAN_35 phase 2 is. Compose Manager projects and the one
container belonging to neither stay as read-only rows, each saying which phase covers it. Taking a
container over is phase 3, and until it exists a locked import of a template whose container still
lives cannot usefully be started. **The note file says so in as many words**, which is the whole
reason phase 0 made the lock a note rather than a flag.

---

## 1. Two corrections in the converter

### Provenance

The generated file's first line reads *"Converted from the Community Applications template for X"*,
which is false on every import from this source. The conversion gains an option naming where the
template came from; the Apps dialog keeps today's wording, an import writes *"Converted from the
Unraid template for X"*. Both the preview and the write pass the same option, so what a row previews
is byte-for-byte what lands on disk.

### A path that points at a bare word

Measured on the box rather than taken from the plan: **one template, MQTT Explorer, with three of
them** — `SSL_KEY_PATH`, `SSL_CERT_PATH` and `INITIAL_CONFIG`, each declared a Path but targeting a
bare word instead of a folder inside the container. None has a value, so today's placeholder rule
concatenates and emits

```
      - "/mnt/user/appdata/MQTTExplorerSSL_KEY_PATH:SSL_KEY_PATH"
```

which is invalid, does not read as invalid, and fails at start-up with a message naming neither the
template nor the setting. A Path whose container side is not an absolute path is now **skipped with a
warning naming it**, alongside the existing skip for a Path with no target at all — which is the
other shape found on the box (NitroSS, two settings), and is already handled.

---

## 2. The write, server side

A new function in `include/Import.php` and one new endpoint action, `import-write`. **One stack per
call**, driven by the browser, because the converter runs in the browser and the server has no way to
produce the file itself.

**It refuses rather than overwrites.** PLAN_35 flagged this precisely: the existing refusal lives in
the endpoint's save case, so an importer that called the save function directly — the obvious thing
to write — would silently overwrite a hand-authored compose file and break the project's first rule.
So this action checks the target itself, before anything, and refuses if there is already anything
there.

**The order is lock first, file second.** Create the folder, write `NEEDS-REVIEW.md`, then write the
compose file through the ordinary save path so it gets the same validation every other save gets. Any
other order leaves an instant where a folder holding a compose file — one whose project name matches
somebody's live containers — is not yet locked, and a page render landing in that instant is exactly
the green row phase 0 exists to prevent. If validation refuses the file, the note and the folder are
removed again, so a refused import leaves nothing behind.

### What the note says

Written by the server in one place, from what the browser sends about the row:

- what it was imported from, and when;
- that it is **not running and cannot be started** until the lock is cleared, and which button clears
  it;
- **the container situation**, in plain words: that Unraid's own container of that name still exists
  and is untouched, and that starting this stack before that container is dealt with will fail,
  because Docker will not give two containers the same name — with a pointer at the phase that
  builds the switch-over;
- what could not be translated, and what was filled in for you — the two lists the conversion already
  produces, which today are only ever seen in a dialog that closes.

---

## 3. The panel

- **Tick boxes on template rows**, with select-all and none on that group's heading. Nothing is
  ticked to start with.
- **A destination folder** in the footer, the same list a stack move already offers, defaulting to
  the top level.
- **The full path stated before it runs**, per PLAN_35's second decision — including that a
  template's own values, passwords and API keys among them, are copied into a file in that folder.
  This needs the stack root sent to the browser; it is not there today.
- **Import writes one stack at a time**, with a count and the name being written. Close becomes
  **Stop** while it runs, and stops after the current one rather than mid-write.
- **A row whose stack already exists is not offered**, and that check follows the chosen folder —
  which means the reply now carries every existing stack path rather than just the top-level names.
- When it finishes, a summary of what was written and what was refused, and the page behind reloads
  its rows so the new stacks are there.

---

## 4. Tests

- **`tests/ca_convert.js`** — the provenance line under both options, and the bare-word path skipped
  with a warning rather than emitted. This is the suite that owns the converter.
- **`tests/server/import.php`** — extended, run with the stack root pointed at a temp folder: a write
  produces a folder holding exactly the compose file and the note; a second write to the same name is
  refused and leaves the first untouched; an invalid file leaves nothing behind; the written stack
  reads as locked. Read-only about Docker throughout — this test never calls it.
- `php -l` on the server over every changed file, plus the whole existing suite.

## 5. Documentation

The plain-English overview still never mentions bringing existing containers in, which PLAN_35 called
out as a headline feature going unsaid. One section there, and a line in `README.md`.

## Files

| File | Change |
|---|---|
| `javascript/ca-convert.js` | the provenance option, the bare-word path skip |
| `include/Import.php` | the write, the refusal, the note |
| `include/action.php` | `import-write`; `import-list` gains the root and the existing paths |
| `javascript/stacks.js` | tick boxes, destination, the run loop, the summary |
| `include/StacksPage.php` | the footer controls; the panel's hint text is no longer true |
| `sheets/staxx.css` | the tick boxes and the footer |
| `tests/ca_convert.js` | two cases |
| `tests/server/import.php` | the write tests |
| `docs/README.md`, `README.md` | what importing is |

## What this phase deliberately does not do

- **No Docker, of any kind.** No stopping, no removing, no renaming, no starting.
- **No Compose Manager import** — PLAN_35 phases 4a and 4b, which need multi-file support first.
- **No switch-over and no review screen** — phase 3. The note file is the review until then.
- **No icons on the list.** 85 rows still meets a budget built for a dozen.
