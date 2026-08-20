# PLAN_46 — bringing Compose Manager projects in

**Status: BUILT 2026-08-19 on branch `compose-manager-import`. Every phase is in; what is left is a browser pass, listed at the foot.** Sub-plan of
PLAN_35, whose phase 4 this is — the last one.

**Override support is kept separable on Adrian's instruction.** He may pull it back out if handling
two files, or fitting them to the form editor, turns out to cost more than it is worth. So the
two-file work lands in its own commits, touches nothing the single-file path needs, and can be
reverted without taking the rest of the import with it. Everything else in this plan must still work
with it gone — a project with an override would then be listed and explained, not importable.

Phases 0–3 have shipped (`completed-plans/PLAN_37.md`, `PLAN_38.md`, `PLAN_41.md`, `PLAN_42.md`).
This finishes the importer.

## Context

The import panel already lists your seven Compose Manager projects, says where each one's file is and
whether it is running — and then refuses to tick them, with the words *"Importing these is not built
yet."* Two things were missing. Compose Manager projects can carry a second, standard **override
file**, which StaXX would silently ignore because it names its compose file explicitly, and the
projects that pin their own container names had no safe way in.

Both are now answered. This plan brings the projects in, and adds the one capability the plugin has
never had: a stack whose behaviour comes from **two** files rather than one.

## What you would notice

The Compose Manager group in the import panel becomes tickable, exactly like the Unraid templates
group. Ticking a project copies its compose file across **byte for byte** — comments, ordering,
anchors, all of it — along with its override file and its settings file if it has them. It arrives
needing review, like every import.

Reviewing it and taking it over is **one button**, as it is for templates. The difference is what
that button does: because the imported stack carries the **same project name** as the original,
Docker treats it as the same project. So taking over rebuilds the containers you already run, in
place, from the copied files. No second set, no port clash, no container-name conflict. Going back is
starting the project from Compose Manager again.

Nothing of yours is written to or deleted at any point. The Compose Manager project stays exactly
where it is, and the row says so — including that starting it from there will now fight with StaXX.

---

## Your decisions, recorded

| Question | Answer |
|---|---|
| Projects with an override file | **Teach StaXX two files.** Both are named on every compose command. Byte-for-byte copies, nothing silently dropped. |
| Project name of the copy | **The same as the original.** Docker sees one project; taking over reuses the running containers. |
| What comes across | **The compose file, the override, and the settings file.** Anything else is named on the review note, not copied. |
| Reviewing and taking over | **One button**, matching template imports, with a plain warning about what it rebuilds. |
| The Compose Manager entry afterwards | **Say so, touch nothing.** No deletion, no writing into another plugin's folder. |
| Who gets two-file support | **Any stack with an override file**, edited through the file tabs the editor already has. No new screen, no setting. |
| Deleting a stack | **Out of scope, and it changes.** Deleting should archive instead — zip the folder, move it out of the stacks tree, keep every file, stop and remove the containers. That is its own plan, **built before this one**. All this plan needs is that the override counts as part of the stack, not as an unexpected extra. |

---

## Measurements this plan rests on

All taken on the server, 2026-08-19, read-only.

| Claim | Measured |
|---|---|
| Projects with an override file | **6 of 7.** Only `Wazuh` has none. Override support is not optional. |
| Projects with a settings file | 1 of 7 (`supstack-dev`) |
| Compose Manager's project name | **Lowercased, and hyphens become underscores** — `Homepage-For-Tesla` runs as `homepage_for_tesla`. StaXX keeps hyphens, so folder names must be adjusted to match. |
| What Docker records as a two-file project's source | **Both paths, comma-separated** — which is exactly what StaXX already splits on, so the primary row match keeps working. Top risk cleared. |
| Where the file actually lives | 3 of 4 running projects run from `/mnt/user/appdata/compose_projects/…`, not from the flash folder — and two of those *also* have a stale copy on flash. Resolving by the container's own label, ahead of the flash folder, is what saves this. |
| A pair split across two folders | `PenPot_Complete` — compose file in appdata, override on flash. The override must be looked for in both places. |
| Other files in a project folder | `name`, `autostart`, `description`, `indirect` — Compose Manager's own bookkeeping, none of it Docker's |

Two consequences worth stating plainly:

- **Pulling override support back out would leave one importable project out of seven.** The two-file
  work stays separable as instructed, but it is now the load-bearing half, not the optional extra.
- **The imported folder is named to match Docker, not to look pretty.** `Homepage-For-Tesla` comes in
  as `Homepage_For_Tesla`, because that is what makes Docker see one project rather than two. The row
  and the review note say why. Where a project is running, the name is read from the containers
  themselves rather than guessed.

## Part A — reading the projects (mostly already built)

`staxx_import_projects()` already returns everything needed per project: display name, project name,
resolved compose file with which of the three tiers found it (`indirect`, container label, flash
folder), the override path if there is one, the `.env` path if there is one, running state and icon.
Two changes only:

1. **Drop the blanket "not built yet" note.** The override note becomes informational — *"has an
   override file, which will be copied and used"* — not a reason to refuse.
2. **List what will be left behind.** Anything else in the project folder, by name, so it can go on
   the review note. Skip Compose Manager's own bookkeeping (`name`, `indirect`) from that list, since
   naming them would only confuse.

Refusals that stay: name not usable as a folder name, no compose file found, compose file holds no
services.

**Projects whose file lives in appdata** (the `indirect` tier — one of your seven) are read from
there and copied into the stack folder like any other. The folder they came from is not touched and
not scanned for extras.

## Part B — writing them in

`staxx_import_write()` writes exactly two things today: the review note first, then one compose file
through `staxx_save_stack()`. That is not enough, and the order is a trap:

> **The deadlock.** Saving validates the compose text, and a project that fills values in from its
> settings file cannot validate without it. But the settings file cannot be written until the folder
> exists, which happens as part of the same save.

So project imports need their own sequence, in this order:

1. Create the folder.
2. Write the review note.
3. Write the settings file, then the override, **byte for byte**, no validation.
4. Write the compose file, validating with the project directory pointed at the **real** folder and
   with the override named alongside it.
5. On any failure at any step, remove the half-written folder — the rollback that already exists.

The write must be a **byte copy**, not a re-save: the existing save path is fine for that (it writes
the text it is given), but nothing may normalise, reformat or reorder on the way through.

## Part C — the same project name

Docker takes a project's name from its folder, lowercased and stripped. So the imported stack's
**own folder name** must match the Compose Manager project's, and the destination folder it sits in
makes no difference. Two consequences worth stating plainly:

- **A name already in use is refused**, as it is now. There is no renaming an import to fit.
- **The row shows it as stopped until it is taken over**, even though containers under that project
  name are running. This plan originally said the opposite, and was wrong: a stack held for review
  reports no state at all, deliberately, because a green row on an import nobody has checked invites
  someone to act on it. Confirmed in the browser 2026-08-19 — the earlier decision stands and this
  plan was corrected to match it.

**Proven on the server, and the answer was "not quite".** Compose Manager does not let the folder
decide — it lowercases the name and turns hyphens into underscores. StaXX keeps hyphens. So matching
is not automatic: the imported folder is named with underscores where the original had hyphens, and
for a project that is running, the name is taken from the containers themselves rather than worked
out from the folder. Same-project matching survives; it just needs that one deliberate step.

## Part D — reviewing and taking over

For a template import, taking over means stopping a container, renaming it aside, and bringing the
stack up — because the old container owns a name the new one wants. **None of that applies here.**
Same project name means compose finds the existing containers as its own and rebuilds them in place.

Two things in the existing handover machinery are in the way:

- It only finds targets by looking for a pinned `container_name`, and most project files have none.
- It **refuses outright** any container that already belongs to a compose project — which every
  Compose Manager container does.

Rather than loosen that refusal, project imports get their own, much simpler act: **bring the stack
up.** No renaming, no set-aside, no undo state file, because nothing was moved. The existing job
runner already does exactly this. What the confirmation must say:

- Which containers will be rebuilt, by name.
- That the Compose Manager project is untouched and can start them again.
- That both places can now act on the same containers, so pick one.

Failure needs no rollback of ours — a failed bring-up leaves the containers as compose left them, and
the row will say so. The review note is cleared **only on success**.

## Part E — two files per stack

**Adrian's rule: this hangs off the file tabs the editor already has.** The override is not a new
kind of thing with its own screen — it is one of the extra files a stack folder can hold, which the
editor already lists and edits as plain text. Nothing new is built for viewing or editing it.

What changes is only how the rest of the plugin treats it. Today an override file is an unrelated
extra: never named on a compose command, invisible to the row, and enough to make delete stop and
ask. It becomes **part of the stack** — named second on every compose command, so its settings win,
which is what Docker does when left to find the files itself.

It applies to **any** stack that has one, not just imports. The file's presence is the switch; there
is no setting and no button, and a stack with one file behaves exactly as it does now.

### How it is done

**One small function decides the pair**, and everything else asks it. Given a stack's main file, it
looks for the matching override beside it — `compose.yaml` pairs with `compose.override.yaml`, and
`docker-compose.yml` with `docker-compose.override.yml`. Strict matching, so an unrelated file that
merely has "override" in its name is never picked up. A second function turns that pair into the
flags a compose command needs, in order.

**What makes this cheap and safe: for a stack with one file, the flags come out character for
character identical to what is built today.** So a single-file stack runs the same command, keeps the
same cached reading of its file, renders the same row, and validates the same way. Nothing to test
for regressions because there is nothing different to regress.

Four places change, and they are the four PLAN_35 named:

1. **Running a stack.** Every step of every verb names both files. The job log's first line names both
   too, so what ran is visible.
2. **Reading a stack's settings.** The one place that asks Docker what a compose file means now asks
   about the pair. This is the change that makes everything else pair-aware at once: the row's
   services, its icon, its web link, which services you can act on individually, and the container
   names the takeover looks for.
3. **Checking and saving.** See below — it is the sharp edge.
4. **Deleting.** The override counts as part of the stack rather than an unexpected extra.

The one thing that must *not* change: the override stays a plain-text tab. It is not folded into the
form, and the form still edits the main file only.

### Checking and saving — the sharp edge

The rule: **neither half is ever checked alone, because the pair is what Docker will run.** A main
file that leaves out something the override supplies is *correct*, and checking it on its own would
wrongly fail it. An override on its own is usually not valid compose at all, and checking it alone
would wrongly pass it.

So the checker gains the ability to put a real file either side of the text being checked — the
override after, when you are editing the main file; the main file before, when you are editing the
override. Editing either half is judged as the pair, in Docker's order.

Two consequences, both accepted:

- **A mistake in the override blocks saving the main file.** Docker's message names the file at fault,
  and the refusal says the two are checked together.
- **The override becomes the one extra file whose save can be refused.** Right, because it is a
  compose file this plugin runs — and there is no way to get stuck, since deleting it is always
  allowed.

### One wrinkle for imports

Compose Manager's override may not be named to match its main file. The copy is therefore written
with the **matching** name, so Docker actually uses it — the contents are byte for byte the original,
only the filename changes, and the review note says so.

### What must be proven on the server first

- That Docker reports **both** paths when asked what a running two-file project was built from. StaXX
  matches a running project to its row partly by that path; if only one comes back, the primary match
  quietly stops working and a slower fallback carries it.
- That a main file which is invalid alone passes as a pair, with the override's settings winning.
- That `down` given the pair removes everything the `up` created.
- That a server with no overrides at all renders byte-identical rows before and after the change.

---

## Phases

**0. Not this plan: archiving instead of deleting.** Written and built first, as a separate plan.
This plan assumes it exists only in that the override is one of the stack's own files.

**1. Prove the project name. — DONE 2026-08-19.** See the measurements above: names match only after
hyphens become underscores, and Docker records both paths of a pair.

**2. Two files per stack. — BUILT 2026-08-19, deployed.** In its own two commits, as instructed, so
it can be reverted without taking the import with it. Twenty-five server-side cases pass, including
the one that is the whole point: a base file invalid on its own passes once its override is layered
on. Two things the design missed and the build caught: creating an override through the editor's New
file button was impossible, because that button creates a file by saving nothing to it and empty text
is refused — empty is now allowed through and the live check goes red instead; and an override
setting a value to nothing is *cancelled* by the base file when Docker merges, which is why the first
test written for it wrongly passed.

Still to prove in a browser: that the pair's own tab checks live, that Start's log names both files,
and that a single-file stack looks and behaves exactly as before.

**3. Reading and writing projects in. — BUILT 2026-08-19, deployed.** Part A and Part B. Proved
against all seven real projects: six import, and Wazuh is refused because Docker rejects its
ten-byte file. Copies come out byte-identical, the pair is recognised, the review lock holds, and the
project name matches what Docker already calls it in every case.

Three things this plan had wrong, found by running it against the real projects:

- **A project's files can live in appdata while an older copy sits on flash**, and for one of yours
  those copies differ — the compose file *and* the settings file. The override and settings file are
  now taken from beside the file actually in use, falling back to the project's own folder, which is
  where one project genuinely keeps its override. Left as written, the import would have quietly
  carried settings the project itself stopped using.
- **A file Docker rejects is not a file with nothing in it.** The refusal now quotes Docker rather
  than calling a broken file empty.
- **Whether a project can be imported is stated by the server**, not worked out in the browser by
  pattern-matching the sentences it also sent.

Still to prove in a browser: ticking a project, the fixed destination name, and the preview's
account of what will and will not be copied.

**4. The takeover. — BUILT 2026-08-19, deployed.** Part D, as designed: one bring-up, no renames, no
undo state, no follow-up question, and the review lock still the only door in. Which of the two acts
a stack gets is decided by the server, and a project match wins over a pinned container name — the
four projects that name their own containers would otherwise hit the handover's refusal, when compose
will simply reuse those very containers. That refusal was not loosened.

Two doors the design had left open: a takeover cannot start while a handover is waiting on an answer,
and the running check no longer assumes how Docker capitalises a state.

Proved on the server by refusals only — the one thing this does when it is *not* refused is rebuild
containers that are really running, which no test may do here. Of the six importable projects, four
take the rebuild route and two — never brought up, so nothing exists to rebuild — take the plain
"clear the lock and start it yourself" route.

**Still to prove in a browser, and it is the last real risk in this plan:** one actual takeover, on
one project, watched. Everything up to the bring-up is proved; the bring-up itself is not.

**5. The loose ends PLAN_35 flags. — BUILT 2026-08-19, deployed.** All three.

- **The row says when the source has changed.** Worked out live by following the shared project name
  and comparing the files, with nothing recorded — a stored source would be the second copy that can
  disagree, which is the problem being reported. The first attempt cost 799ms on every table render
  because it reached for the full import reader; it now costs under a millisecond by resolving each
  project's real file from what the page has already worked out.
- **The self-test** says how many projects resolve to a readable compose file and how many do not,
  and admits that a project only findable by asking Docker counts as "do not" — its no-external-
  command promise is worth more than the extra precision.
- **The docs** cover imports at all, for the first time, including the part most worth getting right:
  taking over means something different depending on the source.

One bug found by importing a real project rather than a fixture: factoring the override lookup into a
shared helper left the settings-file lookup borrowing a list that no longer existed, so a project
that fills its values in from one could not be imported at all.

---

## What is left

Nothing in this plan is unbuilt, and **the browser pass was done on 2026-08-19** against the real
server. What it proved, in order:

1. **The panel.** All seven projects listed, six tickable, Wazuh refused with Docker's own complaint
   quoted. Fixed destination names shown, with the reason spelled out where the name differs.
2. **An import.** `Tesla_Tools` written in, arriving held for review; both files byte-identical to the
   project on disk afterwards.
3. **The pair, checked live.** Typing a bad line into the override produced *"cannot override
   services.tesla_http_proxy.ports"* — a merge complaint, which can only come from checking the
   override together with the main file. Nothing was saved; the files on disk stayed identical.
4. **The drift mark.** Appeared on the row when the copy was deliberately changed, and cleared when
   it was put back.
5. **A real takeover**, on `supstack-dev`, whose two containers were running. The job's first line
   was `compose -f docker-compose.yml -f docker-compose.override.yml up -d --remove-orphans` — both
   files named, in a real run. Both containers came back up, still two of them and not four, the
   review lock cleared on success, and the Compose Manager project was left exactly as it was.

Two things found by looking rather than testing: the import preview called a file by Compose
Manager's internal name for it, now fixed; and this plan's claim about a locked row showing as
running was wrong, corrected above.

Two things deliberately left out of scope, both recorded rather than forgotten: **containers
belonging to neither source** remain reference-only, as PLAN_35 decided; and **drift from an Unraid
template** is not detected, only drift from a Compose Manager project — a template has no shared name
to follow, so it would need the stored record this design refuses to keep.

---

## Verification

There is no PHP, Docker or browser on the development machine, so all of this proves out on the
server.

- **Local, before deploying:** `node --check` on both browser files, plus `tests/js_undeclared.js`
  and `tests/yaml_roundtrip.js`.
- **On the server:** `php -l` over every changed file. Extend `tests/server/import.php` with the
  project cases — a project with an override, one with a settings file, one resolved through
  `indirect`, and the rollback when the pair fails to validate. Its existing cases already cover
  refusals, which matter more than the happy path.
- **A round-trip test that must pass:** import a project, then compare the copied compose file
  byte for byte against the original. Any difference is a bug, not a tidy-up.
- **In the browser:** tick a project with an override, review it, take it over, confirm the
  containers were rebuilt and not duplicated, then start it from Compose Manager again and confirm
  that works too.

## Risks

- **The project-name assumption is load-bearing.** If it does not hold, Part C and Part D both change
  shape. Phase 1 exists to find out first.
- **Taking over rebuilds containers you are already running.** That is the point, but it is the most
  consequential button in the plugin. Behind the review lock, behind a confirmation that names them.
- **Two places can start the same stack.** Unavoidable without touching Compose Manager, which we are
  not doing. It gets said on the row and in the note, and that is the honest limit.
- **A copy that can disagree with its source.** The stack model exists to avoid second copies, and an
  import is one by definition. Phase 5's "the source has changed" row is the mitigation, and it is
  last, not first.
