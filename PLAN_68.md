# PLAN 68 — where a stack's own record lives, and where stacks live at all

**Status: reserved 2026-08-22. Adrian's concept and his decisions, recorded so they are not lost.
Not to be designed further or built until he asks.**

Two things that arrived together and belong together, because both are about **where StaXX puts
things on disk** — and the second changes the first's main objection.

---

## Part A — a record beside each stack

### The idea

Adrian, 2026-08-22: StaXX-specific data attributed to a stack — settings, history, transactions, the
moving parts that are not compose's business — kept in a file **beside the compose file**, filtered
out of the editor.

### Why it is worth doing, with evidence from the same day

**Both bugs found in `PLAN_62` Stage 2/3 were symptoms of the central index**, and neither could occur
if the record lived with the stack:

- Findings were keyed by *image*, so two stacks sharing an image saw each other's findings.
- Findings for **deleted stacks lingered**, needing a prune written specially — and the report is
  built from that file alone, so it would have listed stacks that no longer exist.

A record that lives in the stack's own folder fixes both **by construction**: a rename carries it, a
move carries it, a delete removes it, an archive-and-restore brings it back. None of that needs code.

### The line, which matters because a sidecar was already rejected once

`CLAUDE.md` says a stack is "a directory containing a compose file, and nothing else. No database, no
index, no metadata sidecar", and `feature_56.md` records a companion file being considered and
rejected. **That reasoning still stands for half of this, and must not be swept aside:**

| Kind of data | Where it belongs | Why |
|---|---|---|
| **Describes the stack** — icon, overview, web address, update policy | Stays in `x-unraid` inside the compose file | It is a second copy of something compose can hold, so it *can* disagree. This is exactly what was rejected, and `x-unraid` solved it properly. |
| **A record of what happened to this stack** — dismissals, check history, what was kept for rollback | **The new sidecar** | None of it has any representation in compose and never could. There is nothing for it to disagree with, so the second-source-of-truth objection simply does not apply. |
| **Belongs to the server, not a stack** — the image catalogue, what is cached, node's presence | Stays central where it is | Not a property of any stack. |

### First use for it: an edit history

Added 2026-08-22 from the feature review. **Every save overwrites the file, and undo dies with the
page** — so the rule that protects an author's comments and meaning from *our* writer lets them
overwrite the whole thing themselves with no way back. Two of three reviews ranked this the most
valuable missing thing in the plugin.

It is recorded here rather than as a plan of its own because it needs exactly what Part A is: somewhere
beside the stack to keep what happened to it. It is also the best argument for Part A, since it is a
want that cannot be met any other way — a central index would face the same keying and stale-entry
bugs that Part A exists to make impossible.

What it needs deciding: how many versions are kept and for how long; whether a version is the whole
file or a difference; whether this is real version control underneath or something much smaller; and
whether an automatic save before every write is enough, or whether a person should be able to name a
point to come back to.

### What has to be got right

1. **Write on change only.** The reason this was a bad idea while stacks lived on the flash drive is
   write wear — one central file written once per pass becomes many files written per pass. **Part B
   removes that objection**, but the discipline stays.
2. **The volatile parts stay in `/tmp`** as they do now. A sidecar is for what should survive; it is
   not a cache.
3. **Genuinely invisible, everywhere.** Not merely skipped by the file list. A file you cannot see in
   a tool whose job is showing you your files is a small honesty problem unless it is consistent —
   and the archive, the copy, the move and the export must all carry or skip it deliberately rather
   than by accident.
4. **It must never be required.** Delete it and the stack still works, still starts, still edits —
   losing only its history. A stack is still "a folder with a compose file in it"; the sidecar is
   never what makes it one.
5. **Rule 1 still holds:** copy the folder to any machine with compose and it runs. An unknown extra
   file does not change that, but it does mean somebody's bookkeeping travels with a copied folder.
   Decide whether that is acceptable or whether a copy should drop it.

---

## Part B — stacks must not live on the flash drive

### Adrian's decision, 2026-08-22

**Stacks on the flash drive is too risky.** Verbatim intent:

- During installation, **a folder is chosen** that lives **off the flash drive**.
- **Preferably on a redundant cache pool**, or otherwise **off the FUSE overlay**.
- With Unraid's newer internal-drive boot, a user *could* override that — but the override is
  **behind a confirmation**, not a free choice.
- **The archive location is chosen at the same time.**
- **The two may not be the same folder.** They may be children of the same parent.

### What is true on his box today

- `STACK_ROOT` is `/boot/config/plugins/staxx/stacks` — **the flash drive**, holding **16 stacks**.
  So this is a migration, not merely a change of default.
- `ARCHIVE_ROOT` is `/mnt/user/appdata/staxx/archives` — already off the flash, and already defaulted
  that way. Part of this decision is therefore already the shipped behaviour for archives.
- The box has real pools to choose from: `cache`, `cache-big`, `cache-small`, `m2cache`, plus
  `/mnt/user` (the FUSE overlay) and the individual disks.
- `/mnt/user` is `fuse.shfs`. A pool path such as `/mnt/cache-small/...` is a direct filesystem and
  avoids the overlay, which is what "off the FUSE overlay" means in practice.

### The objection that has to be answered, not glossed

**Flash was chosen originally for a stated reason**, still in the settings help today:

> *"Keeping this on the flash device means stacks are readable before the array starts, which matters
> for autostart. Placing it on an array share gives more room but is unavailable until the array is
> up."*

So moving off flash **delays or changes autostart**. Before this plan is built, settle on the server:

1. **When is a pool actually mounted** relative to array start and to the Docker service starting?
   If pools mount with the array, then "readable before the array starts" is lost wherever stacks go,
   and the autostart bridge has to cope — which may be fine, since Docker itself is not up before the
   array either. **Measure it; do not reason about it.**
2. **What does StaXX's autostart bridge do today** if the stack root is unreadable at boot? If it
   writes an empty list, that is a data-loss path, not an inconvenience.
3. Whether the honest answer is that autostart simply happens after the array starts, which is when
   Docker can run anything at all.

### The rest of the work

4. **The chooser at install time.** A first-run step, not a silently applied default — the point of
   the decision is that somebody *picks*. It must offer what the machine actually has (the pools, and
   the overlay), say plainly why a pool is preferred, and refuse a flash path unless confirmed.
5. **The confirmation for a flash path.** Same shape as `PLAN_65`'s start guard: not a single OK. It
   says what the risk is — flash has finite writes and is the least redundant thing in the machine —
   and Unraid's internal-drive boot is the case where somebody may legitimately mean it.
6. **The two-location rule.** Not the same folder; siblings under one parent allowed. Note that a
   related rule already exists and is enforced — the archive may not sit *inside* the stack root, or
   the zip would be read back as a stack — so this extends an existing check rather than inventing
   one.
7. **Migrating the 16 stacks already on flash.** The hardest part and the one most likely to be
   skipped. A stack is a folder; moving it is a directory move; but a moved stack keeps its compose
   project name while Docker remembers the old path — the very problem
   `staxx_compose_state()`'s three-way index already exists to solve. Read that before designing
   anything, and treat "his running containers keep working across the move" as the acceptance test.
8. **What happens to an existing install that says nothing.** Silently relocating somebody's stacks
   is not acceptable; nor is leaving them on flash while telling them flash is risky. An offer, once,
   that explains and does it for them.

---

## Part C — StaXX must know the difference between "nothing" and "cannot look"

Adrian, 2026-08-22: *"StaXX also needs to understand when the array is down or it will either
complain or crash if it can't access the location where it needs to look."*

**This is already true today and already caused a bug**, found the moment he said it.

`staxx_scan_stacks()` returns an **empty list** both when there genuinely are no stacks and when it
cannot look at all — a missing directory, an unmounted pool, an array that is not started. It reports
no error and draws no distinction. Everything downstream inherits that ambiguity.

**The bug that caused, live in the code within the hour:** `PLAN_62`'s prune deletes the stored
history of any stack it cannot see. The background pass runs from cron **regardless of array state**,
so the first check after a reboot — before the array is up, with the stack root unreadable — would
have quietly deleted **every stack's history**, on the grounds that no stack existed. Guarded, and
proved on the server with a scratch config pointed at an unmounted pool: two stacks' history before,
two after.

The pages themselves are already safe — `Stacks.page` and `StaXX.page` both test
`$var['fsState'] == 'Started'` in their `Cond`, so nothing renders against a stopped array. **It is
everything that runs out of band that is exposed**, and Part B makes it far more exposed, because
today the stack root is on flash and is readable when almost nothing else is.

### The rule to establish

**An empty answer must carry why it is empty.** "There are no stacks" and "I could not look" must be
different answers everywhere they are produced, not merely where somebody remembered.

Places to go through before Part B ships, each of which currently treats the two alike:

1. **`staxx_scan_stacks()`** itself — the source of the ambiguity, and the right place to fix it.
2. **The autostart bridge.** If it writes Unraid's boot-start list from an empty scan, a boot with an
   unreadable root would erase what should start. **Check this first; it is the one that loses
   somebody's configuration rather than merely their history.**
3. **The six-hourly pass** in every branch that decides something is gone: the prune above, the
   registry-move state, the update state.
4. **`PLAN_62` Stage 4's report**, when it is built — it reads the state file alone, so an empty scan
   must not read as "everything is fine".
5. **The self-test**, which should say the array is down rather than reporting a healthy nothing.

### And say it, rather than failing quietly

Where a person is present, the honest line is that the array is not started so StaXX cannot see the
stacks — not a blank list, and not a crash. Where nobody is present, the pass should do **nothing at
all** and leave the state exactly as it found it, which is what the guard above now does.

---

## Sequencing

**Part B before Part A.** The main objection to a per-stack record is write wear on the flash drive,
and Part B removes it. Building Part A first would mean building it under a constraint that is about
to disappear, and probably designing around the wrong thing.

Both are foundational, like the rewrite of rule 2 on 2026-08-21. Neither should be started from this
file alone: it records the decisions, not a design.

---

## Measured on the server, 2026-08-22 — the boot-order objection does not survive

Part B question 1 said *measure it; do not reason about it*. Measured, from the last boot in the
rotated syslog:

| Time | What happened |
|---|---|
| 17:56:59 | `Mounting disks...` — the array's own disks |
| 17:57:03–04 | **every pool mounts** — `cache-big`, `cache-small`, `m2cache` |
| 17:57:04 | `/mnt/user` — the FUSE overlay — comes up |
| 17:57:11 | `rc.docker start` |
| 17:57:14 | Docker daemon reports started |

**Every pool is mounted seven seconds before Docker exists.** So the settings help's stated reason
for flash — "readable before the array starts, which matters for autostart" — protects nothing:
nothing can be started before Docker, and Docker is last. The honest answer is question 3's:
autostart happens after the array starts, because that is the only time anything *can* start.

There is a second, stronger reason the objection is empty. **StaXX does not start anything at boot.**
It writes names into Unraid's boot-start list and Unraid starts them, from `/var/lib/docker`, which is
nothing to do with the stack root. So the stack root does not need to be readable at boot at all.

**Conclusion: Part B is unblocked.** A pool path is available before Docker, and the flash rationale
was answering a question that never applied.

## Part C item 2, probed rather than argued — the boot list is safe

Item 2 said *check this first; it is the one that loses somebody's configuration*. Checked, with a
throwaway probe on the server against the real installed code, the config directory and the boot-list
file both pointed at `/tmp` and the stack root pointed at an unmounted path.

**The boot list came back byte-identical.** The reason matters: the bridge decides which lines are
*ours* from the same scan that came back empty, so with no stacks visible, **every line reads as
somebody else's and is therefore left exactly where it is.** The empty case is safe because it is
indistinguishable from a machine with no StaXX stacks on it.

That is **accidental safety, not designed safety** — nothing states it, no test holds it, and it
turns on ownership and membership being derived from the same source. Record it and test it; do not
rely on it silently.

Two real but smaller things the probe did expose:

1. **The "already seen" marker is still updated.** After an empty run the bridge records the boot
   list's current fingerprint as seen. A switch flipped on Unraid's own Docker page while the stacks
   were unreachable would therefore be **swallowed** rather than adopted. Not lost configuration —
   the line stays in Unraid's file — but a change StaXX should have noticed and did not.
2. **The stored order lives on the flash drive and should stay there.** It survived the probe
   untouched and is readable whatever else is mounted. Part B moves the *stacks*; it should not move
   the bookkeeping that has to be readable when the stacks are not.

So the order within Part C changes: the autostart bridge moves from *first, because it loses
configuration* to **still worth doing, because it hides a change** — and `staxx_scan_stacks()`
itself becomes the one that matters most, since the prune bug was the real instance of this and the
bridge only ever looked like one.

---

## Adrian's decisions, 2026-08-22 — after the measurements above

1. **Stacks go on `m2cache`.** Mirrored NVMe, a direct filesystem rather than the overlay, and
   mounted before Docker. Compose files are tiny, so redundancy and directness decided it, not room.
2. **His own 16 stacks stay on flash for now.** The chooser is built first; his box is not migrated
   as a side effect of building it.
3. **But the chooser must offer to move what is already there** — that is part of the feature, not a
   one-off migration script, and it is the part that needs testing.
4. **The move is copy, verify, then delete.** Verbatim: *"a byte-for-byte copy then delete after a
   success test passes."* Never a rename, never a `mv`, and nothing removed from the old location
   until the new one has been proved good.
5. **Part A waits** until this lands, as sequenced.

### What decision 4 rules out, and why it matters

A rename is a single step that either happens or does not — which sounds safer and is not, because
across two filesystems it is not a rename at all: the tools fall back to copy-then-delete internally,
**with no gate between the two halves**. An interrupted one leaves a partial copy at the destination
and a deletion already begun at the source. Doing the two halves explicitly puts a check between
them, and that check is the whole point.

The success test has to be stated, not assumed. Weakest acceptable: **every file present, every size
equal, every content hash equal**, and the count of files matching. Anything less than a content
comparison is not "byte-for-byte" and should not claim to be.

Two things the move must carry beyond the compose file: whatever else is in the stack's folder — the
promise is that a stack is a *directory* — and the fact that **a companion record will live there
later**, so the move must copy the folder wholesale rather than the files it recognises.

### The one thing that will look like a failure and is not

A moved stack keeps its compose project name, but Docker recorded the **old** path. This is already
solved: what compose reports is indexed three ways, including by the tail of the path, which a move
does not change. **The acceptance test is that his running containers keep working across the move**
— untouched, unrestarted, still showing as up. If they read as stopped, that index is where to look
first, not the move.

### Still open, and not decided here

1. **Where archives go.** Already off flash, on the overlay. Moving them to sit beside the stacks
   satisfies the same-parent-different-folder rule neatly, but archives are zips and can be large,
   which is the one place capacity does argue. Ask before assuming.
2. **What the chooser offers on a machine with no pool at all** — array-only, or booted from an
   internal drive. The confirmed-flash path exists for exactly this, but the wording matters.
3. Whether declining the offered move can be revisited later, or is a one-time offer.

---

# Part B — the design

**Status: design written 2026-08-22, awaiting approval. No code until Adrian says so.**

Grounded in what was read rather than assumed. Four findings shaped it:

1. **There is no first-run flow anywhere in the plugin.** Nothing runs once and asks a question. So
   "a chooser at install time" cannot be built where the phrase suggests: the installer is a shell
   script with nobody watching it, and plugin installation is silent by design.
2. **The page already warns when stacks are on flash** — the volume-path note in the page shell. There
   is a hook to build on, not a blank field.
3. **A set-once config key already has precedent** — the shell warning is exactly that shape.
4. **Unraid publishes pool redundancy itself.** Its disk state file carries, per pool, the name, the
   filesystem, whether it is mounted, and the redundancy profile. On his box: `cache-big` mirror,
   `m2cache` mirror, and `cache-small` reporting **no profile at all**. So redundancy is *readable*
   and never has to be inferred — and an absent profile means "not reported as redundant", which is
   not the same claim as "not redundant" and must not be worded as one.

---

## 1. Where the chooser lives

**A one-time offer on the page, not a step in the installer.** The only place a person exists is the
page, so that is where a question can honestly be asked.

- Shown when the stacks folder is **on the flash drive** and the offer has not been settled.
- Settled state is a single set-once key, following the existing precedent. Three values, because two
  cannot express what is needed: **unset** (never asked), **chosen** (moved, or deliberately placed),
  **declined** (asked, said no).
- **Declining is remembered and is not nagged.** It reappears only where the person went looking for
  it — in the settings panel, as a line saying stacks are on flash and offering the move again.
- The existing flash warning on the page becomes the entry point rather than a second, competing
  message. One voice about flash, not two.

## 2. What the chooser offers

Built from Unraid's own disk state, so every claim on screen is one the server made:

| Offered | Shown as | Condition |
|---|---|---|
| Each mounted pool | name, filesystem, free space, **"redundant (mirror)"** only where the profile says so | mounted, and not a memory filesystem |
| The overlay (`/mnt/user/...`) | offered, with the note that it goes through Unraid's share layer | always, when the array is up |
| Staying on flash | offered **last**, behind the confirmation below | always |

Each offer becomes a concrete suggested path — a `staxx/stacks` folder on the chosen pool — which the
person may edit. The **existing path validator does the checking**; nothing new validates paths. It
already refuses a relative path, a `..` segment, anything outside `/mnt` and the plugin's own config
folder, a non-existent parent, and — the subtle one it already gets right — a path that would land on
a memory filesystem and vanish at the next reboot.

**A pool is not offered while it is not mounted.** Choosing an unmounted pool would write the stacks
somewhere that is about to be shadowed by a mount, which is the memory-filesystem trap wearing a
different hat.

### Preference, stated honestly

A pool is preferred for two reasons and they should both be said, because they are different: it is a
**direct filesystem** rather than the share layer, and — where the profile says so — it is
**redundant**. A pool with no redundancy is a legitimate choice that is fast and direct; the wording
must let somebody pick it knowingly rather than imply it is wrong.

### The flash confirmation

The `PLAN_65` shape: not one OK. It says what the risk actually is — finite write cycles, and the
least redundant thing in the machine — and acknowledges the one case where somebody means it:
**booting Unraid from an internal drive**, where "flash" is not flash at all. That case deserves
detecting rather than lecturing: if the boot device is not removable, say so and drop the warning to a
note.

## 3. The move

### The shape, and where the commit point sits

Not per-stack, and this is the crux. **Copy everything, verify everything, switch the setting, then
delete.**

    copy   every stack folder to the new location, wholesale
    verify every file, every size, every content hash
           -> any failure here: stop, delete nothing, remove the partial copy, report
    switch the stacks folder setting to the new location   <-- THE COMMIT POINT
    delete the old folders
           -> any failure here: the stacks are already live in the new place;
              report what could not be removed, lose nothing

**The setting change is the commit point**, and it deliberately sits between verification and
deletion. Everything before it is reversible by discarding the copy. Everything after it is a tidy-up
whose worst outcome is a harmless duplicate. There is no window in which a stack exists in neither
place.

Per-stack copy-verify-delete was rejected: an interrupted run would leave half the stacks in each
location with the setting naming only one, and nothing on disk recording which half went.

### Verify means verify

The success test, stated so it cannot drift: **the same set of files, each the same size, each the
same content hash.** A count and a size comparison is not byte-for-byte and must not be described as
one. The comparison runs against what is actually on disk at the destination, not against what the
copy believed it wrote.

### Copy the folder, not the files we recognise

A stack is a *directory*. Whatever else is in it travels — an environment file, a note the author
left, data somebody put there against advice, and the companion record Part A will add later.
**Copying only the files the tool understands would quietly discard the rest**, which is rule 2 in
its plainest form. Symlinks need deciding rather than defaulting: there is already handling for a
symlink inside a stack folder, and the move must not turn a link into a copy of its target, or follow
one out of the tree.

### Never a rename

Recorded above under decision 4 and restated here because it is the thing most likely to be
"simplified" later by somebody who sees two steps where one would do.

### It runs as a job

The existing detached job machinery, for one reason: nobody knows how big a stack folder is. Sixteen
compose files are instant; one stack where somebody put a database in the folder is not. A job reports
progress, survives the page being closed, and cannot be half-abandoned by a browser navigation. The
verbs are an allowlist, so this needs a new one — whole-operation only, with no per-service form,
since it is not that kind of verb.

### Running containers must not notice

A moved stack keeps its project name while Docker still records the **old** path. Already solved: what
compose reports is indexed three ways, one of them by the tail of the path, which a move does not
change. **Acceptance test: his containers are up before the move and up after it, untouched and
unrestarted.** If any reads as stopped, that index is the first place to look — not the move.

## 4. Two smaller pieces of the same work

### The two-location rule is currently half-enforced

The archive folder is checked against the stacks folder — an archive inside the stacks tree would be
read back as a stack. **The reverse is not checked:** nothing stops the stacks folder being set to the
archive folder, or to somewhere inside it. Same hazard, one direction covered. Extend the existing
check rather than adding a second one.

His archives stay where they are, off both flash and the new location, so his own box satisfies the
rule without doing anything — but the check is what makes that true for everybody.

### The help text now says something untrue

The stacks-folder help still gives the flash rationale — readable before the array starts, which
autostart needs. **The measurements above disprove it.** It has to be rewritten to say what is
actually true: a pool is available before Docker is, so nothing is gained by flash and write wear is
lost by it. Leaving it would be a document arguing against the feature beside it.

## 5. What must never be guessed

- **Never claim redundancy that was not read.** An absent profile means not reported, and the wording
  must say that rather than promoting a guess into a fact.
- **Never delete before verifying.** Not "verify shortly after"; verify, then delete.
- **Never move part of a folder.** Wholesale or not at all.
- **Never relocate anybody's stacks without being asked**, including on upgrade. An existing install
  that says nothing keeps exactly what it has.
- **Never offer an unmounted pool**, and never write to a path that lands on a memory filesystem —
  the validator already refuses the second and must not be bypassed by the chooser building a path
  itself.
- **An empty answer must still carry why it is empty.** Part C's rule matters more once this ships,
  because a pool can be unmounted and flash could not. The chooser must not read an unmounted new
  location as "you have no stacks".

## 6. Verification

- The path validator's new direction, and the existing one, in the server-side settings checks.
- The move: a scratch tree under `/tmp`, moved to another `/tmp` location, with the verification
  deliberately made to fail — **the failure case is the point**, so the case that must be proved is
  that a failed verify deletes nothing and leaves the source complete.
- The chooser's offer list built against the real disk state file, and against a copy of it edited to
  hold an unmounted pool and a pool with no profile.
- A syntax check on every include, both JavaScript checks, and the round-trip suite.
- On his box, read-only: the offer list rendered, showing three pools with two marked redundant and
  one not. **His stacks are not moved as part of building this.**

## 7. Size and order

Medium. Four separable pieces, in this order:

1. The path validator's missing direction, and the corrected help text. Small, independent, no UI.
2. The move itself, as a job, with its verification — testable entirely in a scratch folder.
3. The offer list, read from Unraid's disk state.
4. The one-time offer on the page, the settled-state key, and the flash confirmation.

Pieces 1 and 2 are worth landing before 3 and 4 exist, because the move is the part that can lose
something and it can be proved without any interface at all.

### Piece 1 landed 2026-08-22, and found one more hole

Both directions of the overlap rule are now enforced, and the help text says what the measurements
showed rather than what was believed before them.

**Outstanding, found while doing it:** each direction is checked against the **stored** value of the
other, read through a cached config. So a single save that changes both paths at once compares each
new value against the other's *old* one, and an overlap between the two new values passes both
checks. Narrow — it needs both changed in one submit — but it is a real hole in the rule just
completed. The fix is to validate the two as a pair against what is actually being submitted, which
is a change to how the validator is called rather than a patch inside it.

**Closed the same day.** The save now settles the pair once, after it has worked out every value it
is about to write and before anything reaches disk, comparing the two paths this save will actually
leave in force. The per-key checks stay: they catch the ordinary single change, and the move
machinery calls one of them directly as its own guard, where only one of the two paths is in play.

Worth recording, because it is the second time today a test would have passed for the wrong reason:
**the first version of these cases never reached the new check at all.** Both nested paths had
parents that did not exist, so the older "that folder does not exist" rule refused them first, and a
test that only asks *was it refused?* cannot tell the two apart. Fixed by creating the folders for
real and asserting on the message. Its sibling: the accepted case leaves a stacks folder behind,
because setting the path makes the folder on demand — and removing it inline does not work, since
the config is memoised for the rest of the run and the next thing to ask simply makes it again.

### Piece 2 landed 2026-08-22 — the move, with the failing case proved

Copy, verify, switch the setting, delete — with the commit point exactly where the design put it.
28 cases pass on the server, and the ones that matter are the failures: a corrupted file and a
missing file at the destination are both caught, the partial copy is removed, and the source is
proved still byte-identical afterwards with the setting never touched.

Nothing reaches this from the page yet, deliberately. It can be run by hand on the server, which is
the easiest way to watch what it does.

**Two things caught in review, not by the tests:**

1. **The test would have created six new top-level shares.** Its scratch folders sat directly under
   `/mnt/user`, where a directory *is* a share with whatever defaults happen to apply — the very
   thing `staxx_folder_create()` refuses to do, for the reason written in its own comment. Rehomed
   inside an existing share before it was ever run. A test has no more business inventing shares
   than the plugin does.
2. **File modes are not carried across, and that is deliberate** — but it needs saying, because it
   is a real limitation. The flash drive is vfat and invents a mode for every file it holds, so
   copying the source's mode onto a real filesystem would stamp that invention on everything.
   Letting the destination take a sensible default is the better answer for the move this feature
   exists to do. **The cost:** a pool-to-pool move later would not carry an executable bit. Nothing
   in a stack folder needs one today. Revisit if that changes.

**Not covered by any test:** the detached run actually completing — spawning the process and watching
for the finished marker. Only the up-front refusal is tested. That gap closes when the interface
piece wires it up and there is something to watch it with.

### Why the suggested pool path goes straight to the pool, not through the share layer

Adrian raised this and was right. Recorded because the reasoning is not recoverable from the code.

Reaching a share **around** the share layer is genuinely dangerous when the share lives on the array:
the layer is what decides which drive each new file lands on, so stepping around it means choosing a
drive by hand, possibly against the share's own rules — and the same share can end up holding two
files of the same name on different drives, with no defined answer for which one is seen.

**On a pool none of that applies.** A pool is one filesystem. There is no drive to choose, so the
direct path is not one of several candidate homes; it is the only one. Nothing is bypassed except
the translation, which is why reaching container data directly on a pool is ordinary practice rather
than a trick.

The qualification: this holds only while the share's data really does live on that pool alone. A
share set to keep data on the pool does; one the mover drains onto the array does not, which is why
the offer list refuses to suggest a path inside such a share.

**And the edge case argues for the direct path rather than against it.** A share set to *prefer* —
which is his appdata, and the common case — spills onto the array if the pool fills. Through the
share layer, a full pool means a new stack quietly lands on the array where the setting is not
looking, and the stack simply appears to be gone. Written straight to the pool, a full pool refuses
the write and says so. **A loud failure beats a silent relocation**, which is the same principle the
copy-verify-switch-delete order exists to serve.

### Piece 3 landed 2026-08-22 — what the offer list actually says on his box

```
OFFERED
  pool     m2cache   /mnt/m2cache/appdata/staxx-stacks   zfs        247 GiB   redundant (mirror)
  overlay  appdata   /mnt/user/appdata/staxx-stacks      fuse.shfs   19 TiB   no profile reported
  flash    flash     /boot/config/plugins/staxx/stacks   vfat             -   no profile reported

NOT OFFERED
  cache-big     no "appdata" folder yet, and making one would create a new share
  cache-small   same
```

Read entirely from Unraid's own records. 19 cases pass, including the one most likely to be got
wrong: **a pool's member drives report the same type as the pool itself**, and the only reliable
difference is that a member carries no mount status at all. Mistake them and the chooser offers a
bare drive.

Two deliberate choices worth recording:

1. **The suggested folder is named for the plugin, not "stacks".** It is offered inside somebody's
   real appdata share, and "stacks" is a name other compose tools plausibly already own there. A
   colliding suggestion is refused later for holding something — safe, but a poor thing to offer.
2. **A missing storage policy and a policy saying "move it" are different answers** and no longer
   share a message. Saying the wrong one is the confident-wrong-answer failure this project keeps
   catching in itself.

**Known limitation, and the hook for piece 4.** A pool is only offered when the appdata share
already lives on it, which on his box means one pool of three. That is the safe direction — it never
invents a share and never suggests a folder the mover would drain — but somebody with a pool and no
appdata on it is told to make a share first rather than being offered anything. The refinement, if
it is wanted: offer such a pool anyway and let the person supply the folder themselves, with the
share warning attached. That belongs with the chooser, not here.

### Piece 4 landed 2026-08-22 — Part B is built

The offer, the chooser, the flash confirmation, and the remembered answer. Verified on the server:
every include lints, the settings suite passes, his config is byte-identical afterwards, and the
banner's own condition returns true on his box — stacks on flash, question unanswered.

**Where the chooser lives, and why not the installer.** There is no first-run flow in this plugin and
the installer has nobody watching it, so the only honest place to ask is the page. The answer is
remembered in three states — never asked, settled, declined — because two cannot say what is needed:
a decline has to be remembered *and* distinguishable from never having asked, or the banner either
nags forever or vanishes for people who never saw it.

**The reload is driven off the job finishing, not off the reply.** This was a mistake in the brief,
caught by the half that could see it: starting the move answers immediately, long before the setting
is switched inside the job, so the reply cannot know whether anything worked. The page reloads only
on a successful exit code; a failure leaves the log open, and the stacks are still where they were.

**Two things corrected in review:**

1. **Stepping into the chooser from the settings panel would have binned unsaved edits silently.**
   The justification offered was that it matched the Browse button — but Browse does not close the
   panel at all, so there was nothing to match. It now asks, worded for this case, because "discard"
   reads oddly when the reason for closing is that something else is opening.
2. **A pool with no reported profile is worded as exactly that** — never "not redundant", which is a
   stronger claim than the server can support. A profile that exists but is not a redundant kind is
   named outright rather than folded into either of the other two sentences.

**Not verified, and cannot be from here:** that the thing looks right and behaves right in a browser.
Every helper and every CSS class it uses was checked to exist, the markup balances, and both
JavaScript checks pass — but no click has been tested. **The one to try first is the failing one:**
point it at a path that will be refused and confirm the server's own sentence appears verbatim.

## Part B status: complete, pending a look in a browser

| Piece | State |
|---|---|
| 1. The overlap rule's missing direction, and the corrected help | done, tested on the server |
| 2. The move — copy, verify, switch, delete | done, 28 cases, the failures are the point |
| 3. The offer list, read from Unraid's own records | done, 19 cases |
| 4. The offer, the chooser, the flash confirmation | done, needs a browser |

**His own stacks have not been moved.** That was the decision: build the chooser, do not migrate his
box as a side effect of building it.

---

## Part B piece 5 — checking before the click, and a trial run

**Status: proposed 2026-08-22 from Adrian's own description of how he wants it to work. Awaiting
approval.**

He described the sequence he expects and asked whether it matches. **Two of five already do** — the
pre-move fingerprint of every source file, and the post-move comparison with deletion only on
success. **Two do not**, and are this piece. One step he did not mention sits in the middle and is
kept exactly where it is: the setting switch between the comparison and the deletion, which is the
hinge the whole design turns on.

### 5a. Check the path as it is typed, not when Move is pressed

Today the click is the trigger: the server checks the path and refuses with a sentence. That works,
but it makes the person guess and be told no, when the answer could have been on screen already.

**A checking action, called as the box is edited, with Move disabled until it comes back clean.**

- **The server does the checking, not the browser.** Every rule that matters — under `/mnt` or the
  plugin's own folder, no relative path, a parent that exists, not a filesystem that lives in memory,
  not overlapping the archive folder, not inside or containing the current stacks folder, nothing
  already in it — lives on the server and must stay there. A second copy of those rules in the
  browser would be two answers to one question, and the browser's would be the one that goes stale.
- So this is a **new read-only action that answers the same refusal in advance**, built from the
  same check the move itself runs. Not a reimplementation of it — the same function, called with
  nothing committed.
- Debounced, because it runs per keystroke otherwise, and each call reads the disk.
- **The disabled button must never be the only guard.** The move re-checks everything when it
  actually runs, exactly as it does now. A disabled button is a courtesy to the person, not a
  security boundary — anything else would put the only check in the one place a person can edit.
- While a check is in flight the button stays disabled, so a fast click cannot outrun the answer.

### 5b. A trial run before any bytes move

The valuable thing here is not extra caution about space. It is that **the destination may not be
able to represent the tree at all**, and finding that out halfway through a copy leaves exactly the
half-written mess this design exists to prevent.

Real cases: a filesystem that cannot create a symlink; a name length or a character the target
refuses; two names that differ only in case landing on a case-insensitive filesystem; a folder that
is writable at the top and not further down.

**So: replay the whole manifest at the destination as empty placeholders** — every directory, every
file as a zero-byte file, every symlink as a symlink — inside one temporary folder, confirm every
single one could be created, then remove the lot.

- Runs **after** the fingerprint pass, which is where he put it, and it is the right place: the
  fingerprint pass has already proved every source file can be read end to end, so the two together
  prove both halves before anything is committed.
- **Any failure stops the move with nothing copied**, naming the path that could not be created and
  what was being attempted. That message is the whole value.
- The trial folder is removed on success and on failure. A leftover trial folder would make the
  destination "already hold something" and refuse the next attempt.
- Cheap: no file content is written, so this is metadata work even for a large stack folder.
- **It is not a guarantee.** It proves the shape can be created, not that the bytes will land. The
  post-move comparison remains the thing that actually decides, and nothing about this weakens it.

### What must not change

- The order stays: refuse, fingerprint, **trial run**, copy, compare, switch the setting, delete.
- The setting switch stays between the comparison and the deletion.
- The comparison stays a full content comparison. A trial run is not a reason to check less
  afterwards — it moves failures earlier, it does not replace the one that counts.
