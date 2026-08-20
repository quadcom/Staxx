# PLAN_37 — phase 0 of the importer: the review lock, and the delete fix

**Status: COMPLETE 2026-08-18.** Built, deployed, and proved on the server with a real name clash
against a live Compose Manager project. 33 server-side checks pass, 1362 browser tests pass, no
console error, and the live container was untouched throughout.

### What the plan missed, found by driving the page

Refusing the buttons turned out to be only a third of the job. A locked stack still resolves a
**project name from its own folder** — and an import's folder is named after whatever it was copied
from — so three separate paths handed it the live project's data:

1. **The row's own state.** `staxx_state_for()` falls back to matching on project name, so the row
   went green and said `running(1)`.
2. **The containers underneath it.** `staxx_stack_containers()` has the same fallback, so the row
   listed the live containers, their image, their address, and offered a per-container menu against
   them.
3. **The processor and memory figures.** The stats reply is keyed by project, and the row's
   `data-project` is derived from the folder when no live project is known, so the live container's
   0.0% / 25.5 MiB painted straight onto the imported row.

Each is now guarded, in the one place that covers both the full render and the cheap poll. The third
is safe to blank because `staxx_stats()` skips any container with no project, so there is no
empty-project bucket for a blanked row to fall into.

None of these was dangerous on its own — the endpoint refusal already held — but together they made
an unreviewed import look like it was **already working**, which is exactly the green row PLAN_35
identified as the mechanism of the hazard. Demonstrated both ways: with the lock in place the row
showed dashes throughout; the moment "Mark as reviewed" removed it, the row picked up
`running(1)`, the real image, the real address and the real memory.

Sub-plan of PLAN_35, which approves the phase; this settles how it is built. Nothing else in PLAN_35
can ship safely before it.

## What it is for

The importer will write stack folders that share an identity with containers somebody else is already
running. From a folder like that, StaXX's ordinary buttons act on the *live* containers — and
deleting the stack tears them down **before it asks anything**, because the confirmation prompt only
guards extra files in the folder, and an imported folder holds nothing but a compose file.

So: a stack can be marked as **not yet reviewed**. While it is, every run action is refused and delete
removes the folder without tearing anything down.

Nothing creates such a stack yet — that is phase 2. This phase builds the lock and the delete fix, so
the thing that creates them lands on solid ground.

## The lock is a file in the stack's own folder — Adrian's design

`NEEDS-REVIEW.md`, sitting beside the compose file. Present means locked. Reviewing deletes it.

This replaces two earlier proposals, and it is better than both:

- **Inside the compose file's metadata block** — rejected. Reading that block means asking compose to
  read the file, and compose refuses an invalid file and hands back nothing, so the marker would
  vanish exactly when it matters. It also cannot be done at all for a Compose Manager import, whose
  file we copy byte for byte and promise not to touch.
- **A list in StaXX's own settings** — workable, but it needs loading, saving, a version field, and
  code in the rename and folder-move paths to carry entries across, or the lock falls off when
  somebody files a stack into a folder.

A file in the folder needs none of that:

| | A list in settings | A file in the folder |
|---|---|---|
| Survives a rename or a move | needs code in both paths | free — it moves with the folder |
| Removed when the stack is deleted | needs code | free |
| Reading it | load and parse a file | does this file exist |
| Visible to a human | no | yes, in the editor and in any file browser |
| Can explain itself | no | **yes — it is also the note** |

That last row is the real win. The file is not only a flag, it is the message: what was imported,
where it came from, what StaXX could not represent, and what to check before starting it. That is
phase 3's review screen, available as plain text on day one, and it means phase 3 becomes a nicer way
to read something that already exists rather than the first time any of it gets said.

**It fails in the safe direction.** If the folder cannot be read, the stack has no compose file
either, so it is not a stack at all — there is no path where a locked stack quietly reads as
unlocked.

### The one rule this bends, and why it is not broken

The stack model says a stack is a directory holding a compose file and *nothing else* — no sidecar.
The stated reason is that anything kept beside the compose file is **a second copy of what the file
already says, which can disagree with it**. This is not that. It says nothing about the app; it says
StaXX has not been told this import is fine yet, and it exists only until somebody says so.

Folders holding more than a compose file are already ordinary — `.env` files, secrets and companion
files all live there, and there is a whole fixture about them.

## What gets built

### 1. The lock itself

Two small helpers beside the other stack-file functions — no new file, no new module:

- is this stack locked: does `NEEDS-REVIEW.md` exist in its folder
- unlock it: delete that file

Checked **case-insensitively**, because the default stack root is the flash drive, which does not
distinguish case, while an appdata root does. One rule that is right on both.

### 2. The lock — the job runner

`staxx_start_job()` refuses **every** verb, at both whole-stack and single-service scope, for a locked
stack. Placed with the other early refusals, before any command is built.

This is the actual safety property. Hiding buttons is not — the endpoint is the only thing that can
guarantee it, and everything else here is presentation on top of this one check.

The refusal says what to do next: that this stack came from an import, has not been checked yet, and
what to press.

### 3. The delete fix

`staxx_delete_stack()` skips the `compose down` entirely for a locked stack.

It must also treat `NEEDS-REVIEW.md` as **ours to remove**, alongside the compose file and `.env`.
Today anything else in the folder is a stranger's file and blocks the delete with *"remove it by hand
if you are sure"* — which would be wrong about a file StaXX wrote itself, and would make every single
import refuse to delete.

The teardown stays for every stack StaXX owns. It is correct there and always has been.

### 4. The row and the editor

- A distinct badge and tile, so a locked stack does not read as an ordinary one.
- No Start, Stop, Restart or Update in the stack menu.
- One new menu entry: **"Mark as reviewed"**, which deletes the file. The real review screen is phase
  3; this is what stops a locked stack being a trap in the meantime.
- In the editor, the file sorts **immediately after the compose file** rather than alphabetically
  among the companions, so it is the first thing seen. One clause in the existing sort.
- Deleting it from the editor is already possible with no new code — the companion-file delete path
  handles it, and the name passes the filename rules unchanged.

### 5. Self-test

One line: how many stacks are awaiting review. It runs no external commands, so it stays instant, and
it makes this checkable from the server with no browser.

## Tests

Server-side, in `tests/server/`, since all of this is PHP — following `files.php`'s shape and header.

1. A folder with the file reads as locked; without it, not; and the check is case-insensitive.
2. **Every verb refused** for a locked stack, at both scopes — walked over the whole verb list rather
   than a sample, so a verb added later cannot quietly escape the lock.
3. A locked stack's delete removes the folder and **runs no compose command**. Proved with the stack
   root pointed at a temp folder, asserting on what is left on disk, never by running Docker.
4. The lock file alone does not block a delete, while a genuine stranger's file still does.
5. Renaming a stack and moving it into a folder both keep it locked, with no code doing anything —
   this is the claim the whole design rests on, so it gets pinned rather than assumed.
6. An ordinary stack is completely unaffected — same verbs, same delete behaviour as today.

Plus `php -l` on the server over every changed file, which is the only PHP check that exists.

## What this phase deliberately does not do

- **No review screen.** No collision checks, no "what StaXX could not represent". Phase 3.
- **No importer.** Nothing creates a locked stack; the tests create them directly.
- **No change to the metadata format**, and no version bump.
- **No change to how an ordinary stack behaves**, anywhere.

## Files

| File | Change |
|---|---|
| `include/Stacks.php` | the two helpers, the job-runner refusal, the delete fix |
| `include/StacksTable.php` | the badge, the row attribute |
| `include/action.php` | nothing new — unlocking is the existing file-delete action |
| `javascript/stacks.js` | the menu entry, and hiding the run verbs |
| `sheets/staxx.css` | the badge |
| `tests/server/review.php` | new — the tests |
| `scratch/test-stacks/README.md` | correct its wrong note about `compose config` stripping metadata |

Note that the endpoint gains **no new action at all** — "mark as reviewed" is deleting a companion
file, which already exists, is already guarded and is already tested.
