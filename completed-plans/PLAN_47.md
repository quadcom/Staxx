# PLAN_47 — archiving a stack instead of deleting it

**Status: BUILT 2026-08-20 on branch `archive-on-delete`.** Deployed to the test box; every
server-side check passes. What only a browser can confirm is listed at the end.

## Context

Removing a stack today deletes its files. If the folder holds anything beyond the compose file, its
settings file and a review note, it stops and tells you to remove it by hand — but say yes and the
whole folder goes, including anything hand-written. It is the one irreversible act in the plugin.

Adrian's decision: **removing a stack should stop it and take it out of the stacks tree, not destroy
it.** The containers go; every file survives, zipped, somewhere outside the tree.

Decisions taken this session: archives go to a folder under appdata that you can change on the
settings panel; bringing one back is unzipping it yourself, for now; the settings panel lists what
has been archived; and there is only one button, "Remove", which always archives — the plugin loses
its ability to truly delete a stack.

## What you would notice

The stack menu's "Delete stack" becomes **"Remove stack"**. Choosing it opens one dialog that lists
every file in the folder, names the zip file it is about to write, and says three things plainly:
the containers are stopped and removed, every file is kept, and the container's own data in appdata
is untouched and still on disk. One confirmation and it happens.

There is no second, scarier step any more. Extra files in the folder used to be a reason to stop and
ask twice, because they were about to be destroyed; now they are simply part of what gets kept.

The settings panel gains a folder to put archives in — defaulting to a folder under appdata — and,
below it, a list of what is in there with dates and sizes.

## Where the archives go

A new setting, sitting beside the stack directory in the settings panel:

- **Default:** `staxx/archives` under wherever Unraid says appdata is. That is read from Unraid's own
  Docker config the same way the folder-creation button already reads it, so it matches the box
  rather than being invented. Blank in the shipped config means "work it out"; there is one small
  function that answers "where do archives go", and it is the only thing that knows this.
- **Settable**, with the same rules the stack directory already has — a full path, under `/mnt/` or
  the plugin's own config folder — **plus one new rule: it may not be the stacks tree or inside it.**
  An archive sitting in the stacks tree would be read as a folder or a stack, so that is refused with
  a message saying why.
- The help text says out loud that flash is a poor choice for a growing pile of zip files.
- The folder is created when the first archive is written, not on save. Nothing else needs to know.

**Naming.** The stack's path under the root with slashes turned into dashes, then the date and time:
`Media-jellyfin-20260820-143000.zip`. Two archives of the same stack a week apart cannot collide, and
two stacks with the same leaf name in different folders cannot either. If the same second somehow
comes round twice, `-2` is appended. The zip holds the stack's **own folder** at the top, so unzipping
it into the stacks tree puts the stack straight back.

## How it works

The delete function becomes an archive function, in the same place, with the same shape — validate,
refuse, stop the containers, then act. The order is what matters, and it is: **nothing is removed
until a verified archive exists.**

1. Refuse anything that is not a real stack path, or a stack with a set-aside container still waiting
   on an answer. Both checks already exist and are unchanged.
2. Work out where the archive goes and make sure the folder can be created and written. A failure
   here refuses the whole thing before anything is touched.
3. **Size guard.** Measure the folder. Over 250 MB it refuses, saying the folder is too big to archive
   from a web page and to move it by hand — because the page waits while this happens and there is a
   two-minute ceiling on anything the plugin runs. A stack folder is normally a few kilobytes; this
   exists for the person keeping a disk image in one.
4. Stop and remove the containers. This is exactly what happens today, including being skipped for a
   stack still needing review, and a failure still means nothing is removed.
5. Zip the folder — symlinks stored as symlinks, not followed, so a link cannot make the zip
   enormous or loop. Written to a temporary name in the archive folder, **integrity-tested**, then
   moved into place under its real name. A half-written zip is never left looking like an archive.
6. Only now remove the folder, through the existing containment-checked removal.
7. Report the archive's full path back, so the reply can name it.

Kept synchronous rather than made a detached background job. The slow part — stopping the
containers — already runs this way today, the zip of a normal stack folder is instant, and the size
guard covers the case that is not. A background job here would need its own completion handshake
just to tidy two bookkeeping lists. If real use shows the wait is too long, moving it to a job later
is a self-contained change.

**This removes PLAN_46's dependency on a shared file list.** Archiving the whole folder at disk level
means an override file needs no special handling — it is simply one of the files in the folder.

## Where the code changes

- `include/Defines.php` — one function answering where archives go (config value, else `staxx/archives`
  under `staxx_appdata_root()`).
- `include/Settings.php` — `ARCHIVE_ROOT` added to the settings allowlist. The path validator is
  currently hard-wired to the stack directory's wording and rules; it gains the field's name and the
  "not inside the stacks tree" rule. Its default is computed, not a literal, so the panel shows a
  real path on any box.
- `default.cfg` — `ARCHIVE_ROOT=""`, with a comment saying blank means derived from Unraid's appdata
  location. (Comment starts with a semicolon.)
- `include/Stacks.php` — `staxx_delete_stack()` becomes `staxx_archive_stack()`, per the sequence
  above. `staxx_stack_extras()` is unchanged and still supplies the dialog's file list. Existing
  helpers reused as-is: `staxx_rmtree()`, `staxx_find_compose_file()`, `staxx_compose_cmd()`,
  `staxx_review_file()`, `staxx_handover_file()`, `staxx_sh()`.
- `include/action.php` — the `delete` case becomes `archive`: with no confirmation it answers with the
  file list *and the planned archive path*; with `confirm=1` it acts and answers with the path
  written. The folder and start-order bookkeeping that follows a successful removal is unchanged. One
  new case, `archive-list`, returns what is in the archive folder (name, size, date, newest first,
  capped) for the settings panel.
- `javascript/stacks.js` — the menu item is renamed; `deleteStack()` becomes `removeStack()` and asks
  the server for the plan *first*, so a single dialog opens already knowing every file in the folder
  and the folder the zip goes to. On success the same dialog stays open, naming the archive's full
  path with nothing left to press but Done — which is why the dialog promises the destination folder
  rather than a filename it would have to guess the second of. The existing dialog machinery (`askConfirm`, `extraLine`, the busy state, the
  row-leave animation and `refreshRows`) is reused untouched; what goes away is the escalating
  second step. The no-dialog fallback path keeps its plain browser confirmation with new wording.
  A new settings row for the archive folder, and a read-only list below it fed by `archive-list`.
- `include/StacksPage.php` — only if the dialog needs a container for the list; it likely does not.

Nothing in `staxx.settings.page` changes: it is a signpost plus the two recovery fields, and this is
not one of them.

## Documentation

`docs/` and `README.md` say removal archives rather than deletes, and where archives land. The stack
model's "delete the folder and it is gone" stays true — that is about a folder removed by hand, which
still works and always will.

## Verification

Nothing here runs on the development machine.

**Locally, before deploying:** `node --check` on both browser files, `tests/js_undeclared.js`,
`tests/yaml_roundtrip.js`, `python tests/validate_schema.py`.

**On the server:** `php -l` over every changed file. Then extend `tests/server/files.php`, whose
delete cases this replaces — pointing `ARCHIVE_ROOT` at `/tmp` for the run, the way `links.php`
already points `STACK_ROOT` there:

- a stack with extra files and a subfolder archives, and the zip lists every one of them
- the stack folder is gone afterwards, and the zip is in the archive folder under the expected name
- two removals of the same stack name produce two archives, neither overwriting the other
- an archive folder that cannot be written refuses, and the stack folder is still there
- an archive folder pointed inside the stacks tree is refused by the settings validator
- the size guard refuses, and removes nothing
- a stack with a set-aside container waiting on an answer is still refused first

`tests/server/links.php` needs the same treatment for its confirmed-delete case, and should gain the
one thing only it can check: **a symlink in the stack folder is stored as a link in the zip, not
followed.**

**In the browser:** remove a stack with an extra file in it and confirm the dialog lists it and names
the zip; check the zip on disk; confirm the row goes and the archive appears in the settings list.
Then unzip it back into the stacks tree by hand and confirm the stack returns and starts.

## What this deliberately does not do

- **No restore.** Getting one back is unzipping it. Its own plan later, once you have lived with
  archives — the awkward part is what happens when a stack of that name already exists.
- **No real delete.** There is no destructive button left to press by mistake. Deleting an archive,
  or a stack folder, is a file manager's job.
- **Appdata is not archived**, and cannot be — it is not in the stack folder. The confirmation says so.

---

## Built — what was proved, and what only a browser can

Deployed and checked on the server. Passing: PHP syntax over every changed file; the archive suite in
`tests/server/files.php` (unconfirmed refusal, pending-handover refusal, extras and a subfolder all
landing in the zip under the stack's own folder name, two same-leaf stacks in different folders not
colliding, a claimed name bumping rather than being overwritten, an unwritable archive folder
refusing with the stack left intact, and the archive listing); the symlink case in
`tests/server/links.php` (stored as a link — `lrwxrwxrwx` in the zip — with its target untouched);
`tests/server/review.php` rewritten for archiving (a locked stack archives without its containers
being torn down, and a stranger's file no longer blocks anything); and `tests/server/settings.php`
extended for the sixth setting, including the refusal of an archive folder inside the stacks tree.

End to end with the live config: a scratch stack archived to
`/mnt/user/appdata/staxx/archives/zzsmoke-20260820-010428.zip`, the hand-written file inside it
intact, and the settings list reading it straight back.

`tests/server/import.php` reports two failures, both counts of what is running on the box rather than
anything this changed — it expects 70 templates with a container present and finds 67.

**Only a browser can check:** that the menu says Remove; that the one dialog lists the folder's files
and names the archive folder; that Remove and archive shows the finished zip's path with Done as the
only thing left; and that the settings panel's archive folder field, its Browse button and the list
of archives below it all render and save.

Merged with `main`, which had meanwhile grown two-file stacks. The one conflict was the file scan
this plan deletes; resolving it kept the deletion, and the teardown now names both halves of a pair
so an override's services cannot be left running. Proved on the server: a stack whose main file is
only valid *with* its override is torn down and archived as a pair, with both files in the zip.
