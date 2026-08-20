# PLAN_53 — the shell that never worked, and three buttons in the wrong place

**Status: BUILT 2026-08-20.**

## Context

Adrian reported four things from one visit to the Manage tab on `MQTTExplorer`. They turn out to be
one serious bug, one design fault of mine, and one gap.

**The shell has never worked in any image without bash.** A session is launched as
`sh -c 'exec bash || exec sh'`, meaning "run bash, or fall back to sh". It does not fall back. A
failed `exec` terminates a non-interactive shell outright, so the `||` branch is unreachable and the
session leader dies the instant it starts. Proved on the server:

```
sh -c 'exec nosuchbash || exec echo FALLBACK-RAN'   →  exec: not found, exit 127, no fallback
sh -c 'command -v nosuchbash >/dev/null 2>&1 && exec nosuchbash; exec echo FALLBACK-RAN'  →  FALLBACK-RAN
```

`glances` reports `NO-BASH`, and so will most Alpine-based images — which is most lightweight
containers. Everything downstream follows from this: the shell shows `bash: not found`, the next
write reports *"That session has ended"*, and there is no way to start another because nothing ever
offered one.

**I saw this and did not register it.** The `bash: not found` line was visible in the glances shell
during PLAN_44's verification. I read it as noise because I had proved the shell against MariaDB,
which has bash. It was the bug, in plain text, in a screenshot I looked at.

**"Fix ownership" put its message in the wrong pane.** It writes its explanatory note through
`noteLine()`, which is the *log* pane's note function — hence a log entry reading "Ready to run once
you fill in the owner…" while the shell showed nothing. It then types a `chown -R <uid>:<gid>`
command into the shell for the user to complete by hand. With the shell dead, nothing appeared at
all; and even with a working shell, editing placeholders inside a half-typed command line is a poor
way to ask someone a question.

**The button strip is unbalanced.** `renderJobsBar()` *hides* Config folder and Fix ownership when
All is selected but only *disables* Show environment, so it is left alone on the left. When a
container is selected all three appear and the row right-aligns, so the same button jumps across the
window.

**And ownership is missing where it belongs.** The file listing shows owner and permissions on every
row but offers no way to change them.

Adrian's own suggestion, taken: each button belongs in the heading of the pane it acts on.

## What you would notice

The strip above the panes holds only the container's status line. The three buttons move into the
headings of the panes they act on, right-aligned, and each one is simply absent when it cannot work
rather than greyed out:

- **Log** heading — *Show environment*
- **Shell** heading — *Reconnect*, shown only when the session has ended
- **Files** heading — *Config folder*

The shell works in Alpine images. When a session does end — the container stopped, or the shell
exited — the pane says so plainly and Reconnect starts a new one.

Every row in the file listing gains **Owner**, beside Rename and Delete. It asks who should own the
file, warns you when the folder is one of your server's own, and then does it. On a folder it applies
right through and says so before it acts.

*Fix ownership* is gone. The row button does the job properly and the placeholder-in-a-shell trick
was never a good answer.

## How it works

### The shell's fallback

`staxx_exec_start()`'s inner command becomes
`command -v bash >/dev/null 2>&1 && exec bash; exec sh`. Nothing else about the session changes —
the pty, the read-write fifo, the heartbeat and the reaper are all untouched. The existing comment
there claims the fallback works; it is replaced with one that says why `exec ... || ...` cannot.

### Reconnect

`staxx_exec_read()` already reports `alive:false` when a session has ended, and `pollShell()` already
stops polling on it. What is missing is anything visible. The shell session gains an `ended` flag set
from that same reply; the pane renders a note when it is set, and the Shell heading shows
**Reconnect**, which discards the session object and calls `ensureShellSession()` again. The
one-time shell warning is not re-asked — it was accepted for this editor already.

### Pane headings that can hold a button

`pane()` currently *is* a `<button>`, so nothing can be nested inside it. It becomes a `<div>`
holding the collapse toggle (a button carrying the title) and a right-aligned actions slot. The
collapse listener moves to the title button, which is also what `aria-expanded` moves to — so
`revealPane()` needs its one line adjusting. A click on an action button cannot toggle the pane,
because the toggle now lives on a sibling. `.staxx-manage-pane--collapsed` and its existing
heading rules are unaffected.

`buildAbove()` keeps the status line and loses the jobs row. `renderJobsBar()` becomes the thing
that shows or hides each heading button: hidden when All is selected, and Config folder and Owner
also hidden for a container with no mounts, exactly as today.

### Changing ownership

New, following the shape of the existing delete path end to end:

- `staxx_cfile_chown_cmd()` beside the other command builders — `docker exec <c> chown -R <owner>
  -- <path>`, both arguments `escapeshellarg`'d, `--` before the path as everywhere else here.
- `staxx_cfile_chown()` beside `staxx_cfile_delete()`, validating the owner against
  `^\d+(:\d+)?$` — numeric only. That is what the listing shows anyway, and a name may not exist
  inside the container even when it does on the host.
- One `cfile-chown` case in `include/action.php`.
- Client side: an `Owner` button per row, using `window.prompt` then `window.confirm`, which is
  already how Rename and Delete ask. The confirmation names the file, the owner, and says the change
  reaches the whole folder when it is one.

**One limit to be honest about.** The client knows only the *container* side of each mount — the
paths come from the compose file's volume fields — so the warning can say "this comes from your
server and the change reaches those files", but it cannot print the host path. Making it do so means
the listing returning each mount's source, which is a bigger change than this warrants.

`-R` is unconditional. On a single file it is the same as no flag; on a folder it is what anyone
asking to fix ownership means.

## Where the code changes

- `include/Stacks.php` — the session launcher's shell fallback; `staxx_cfile_chown_cmd()` and
  `staxx_cfile_chown()`.
- `include/action.php` — one new `cfile-chown` case.
- `javascript/manage.js` — `pane()` gains an actions slot; `buildAbove()` loses the jobs row;
  `renderJobsBar()` becomes heading-button visibility; `prepareChownCommand()` is deleted along with
  `pendingShellCmd` and `flushPendingShellCommand()` if nothing else needs them; a shell `ended`
  flag, its note and Reconnect; `chownEntry()` and its row button.
- `sheets/manage.css` — the heading becomes a flex row with a right-aligned actions slot; a style for
  a heading action button (`.staxx-scaffold`-prefixed, per the button trap at the head of the file).
- `tests/server/console.php` — cases for the new command builder and the owner validation, including
  the refusals: a name rather than a number, an empty owner, a path outside the rules.

## Verification

Locally: `node --check` on both browser files, `tests/js_undeclared.js`.

On the server: `php -l` over the two changed PHP files, then `tests/server/console.php` in full — it
already covers the session and container-file helpers, so a regression in either shows there rather
than in the browser.

In the browser, on a **bash-less** container (`glances` is one) and a bash one (`Bambuddy`):

- The shell opens to a working prompt in the Alpine image — the thing that has never worked. Run
  something and see it answer.
- Stop the shell from inside it (`exit`), confirm the pane says the session ended and that Reconnect
  brings back a prompt.
- With All selected, the strip above the panes holds the status line only and no heading carries a
  button. Select a container and each button appears in its own heading, right-aligned.
- Show environment prints into the log; Config folder moves the listing to the mounted folder.
- Owner on a file: cancel at the prompt, cancel at the confirmation, then go through and see the
  listing come back with the new owner in its column.
- Owner on a folder inside a mount: the confirmation says it reaches the whole folder and that the
  files are your server's.
- A refused owner ("nobody", blank) is reported in the pane's own error line, and nothing changes.
- Narrow the window under the collapse point and confirm the heading buttons still fit and work.

**A scratch target, not appdata.** Every write test runs against a folder created for the purpose
inside a container — never against a real mounted appdata folder, since a recursive chown there is
exactly the thing that is hard to undo.

## What changed while building it

**Permissions came in alongside ownership**, asked for mid-build. One button labelled `O/P` asks two
questions — owner, then permissions — each skippable by clearing the box, so wanting one without the
other (the common case: a folder whose owner is right but which nothing can write to) takes one
trip. `staxx_cfile_chmod()` and its command builder sit beside the chown pair, octal only: a symbolic
mode like `u+x` is refused, because the listing shows permissions in neither notation and octal is
the one that says exactly what the result will be.

**A refused change had already changed something.** Tested through the UI with a good owner and a bad
mode, the endpoint reported the permissions refusal — correctly — but the owner had already been
changed by then, because each helper validated only its own value just before acting. Confirmed on
disk: the scratch folder read `99:100` after an action that reported failure. Both answers are now
checked before either command runs, which is what `staxx_cfile_valid_owner()` and
`staxx_cfile_valid_mode()` exist for. Re-tested: the same case now leaves the folder at `0:0`.

## What was proved, and where

On the box, against `glances` (Alpine, no bash) and `Bambuddy` (bash, with mounts):

- **The shell in an Alpine image.** A root prompt at `/app #`, then `echo ALPINE-SHELL-OK` returning
  its own output. This is the thing that had never worked.
- **Reconnect.** `exit` inside the shell produced "That session has ended. Press Reconnect above for
  a new one.", the heading grew a Reconnect button, and pressing it gave a fresh prompt that ran a
  command. The button disappears again once a session is alive.
- **The headings.** With All selected, the strip above the panes is empty and no heading carries a
  button. With a container selected: Show environment in the Log heading, Config folder in the Files
  heading, each six pixels from the right edge — the heading's own padding.
- **`O/P`.** Cancel at either prompt or at the confirmation changes nothing. A name for an owner and
  a symbolic mode are both refused with the pane's own error line. Owner alone, permissions alone,
  and both together each do exactly what they say, and reach a file inside the folder.
- **The mount warning** appears for a file in the container's real config folder — and that test was
  cancelled at the confirmation, so nothing in appdata was touched. Verified afterwards: still
  `1000:1000`, still `-rw-------`.
- **Server suites.** `console.php` passes in full, including twenty new cases. Five other suites
  (`files`, `links`, `override`, `review`, `takeover`) need the flash config temporarily rewritten to
  point at `/tmp`, which was **not** done on a production box for a change that touches none of what
  they cover; the remaining eight pass.

Scratch folders were created inside containers under `/tmp` for the write tests and removed
afterwards. Nothing was ever run against a real mounted appdata folder.

## What this deliberately does not do

- **No multi-select in the file listing.** "Selections" is one row at a time here; a checkbox column
  and a bulk action is its own piece of work.
- **No permission editing.** Ownership only. `chmod` has the same shape and can follow if it is
  wanted, but it was not what was asked for.
- **The mount warning does not name the host path**, for the reason given above.
