# Going back

<!-- index: 75 | how to return to an earlier version of a stack's file or to an earlier build of one of its images, what each of the two lists holds, and why some things cannot be gone back to. -->

Two different things can go wrong after a change, and they need two different answers. Either **you**
changed the file and want the old wording back, or **the publisher** shipped a new build of an app
and you want the build you had yesterday. Both live in the stack editor, on two tabs sitting beside
Configure — **History** for your own earlier files, **Versions** for the publisher's earlier builds.

Keeping them apart is deliberate. One mixed list would have rows that mean completely different
things sitting next to each other, and picking the wrong one is exactly the mistake you would be
trying to undo.

## The short version

1. Open the stack and pick **History** for a file you changed, or **Versions** for an app build.
2. On History, click a version on the left to read it, then **Restore into Configure** — it only
   loads it into the form, nothing is written until you press **Save**.
3. On Versions, pick a service on the left, find the build you want, and press **Put this back**.
4. That one writes to the file, naming that exact build — which is what makes it stick.

---

# History — going back to a file you changed

Every time you save a stack, the file as it was a moment before is kept first. A save that changed
nothing at all is not kept, so the list is a list of real changes rather than a list of times you
opened the editor.

## What the list shows

Newest first. Each row carries:

| On the row | What it is |
|---|---|
| A time | Relative if it happened today, otherwise the full date. |
| A name, in bold | Only if you gave that version one. See [naming a version](#naming-a-version-keeps-it-forever). |
| Which file | The stack's main file, or its override file. |
| A size | How big that version was. |

Click a row and that version's text appears on the right, read-only. Before you have picked
anything, the right-hand side simply says *"Pick a version on the left to look at it."*

![The History tab: a list of kept versions down the left with one picked out, the "Name to keep forever" box and the "Restore into Configure" button beneath it, and that version's text filling the right-hand side](../images/guide/going-back-history.png)

If nothing has ever been saved, you get *"No history yet. The next time this stack is saved, that
version starts being kept."*

## Restoring one

**Restore into Configure** loads that old text into the editor as an unsaved change and switches you
back to Configure. **It does not write anything to disk.** Afterwards the editor says *"Restored
version N from history — Save keeps it, or Undo puts the previous version back."* — so you can read
it over, change your mind, or edit it further before committing to it.

If you already had unsaved edits on screen, it asks first: **"Discard changes?"**, explaining that
*"Configure has changes that have not been saved. Restoring version N replaces what is on screen with
it."* Press **Discard and restore** to go ahead.

Because restoring is just a normal save when you finally press Save, the file it replaces is itself
kept — so undoing an undo works exactly like anything else here.

## Naming a version keeps it forever

Under the list there is a box marked **Name to keep forever**. Type something into it — "before I
touched the ports", "the one that worked" — and that version is kept indefinitely.

The rule is worth knowing in full, because it decides what quietly disappears:

| Kind of version | How long it is kept |
|---|---|
| Unnamed | The newest twenty are kept. Older ones are deleted as newer saves arrive. |
| Named | Kept forever, and it does not use up one of the twenty. |

Clearing a name asks first, and the warning is the important part: *"A named version is kept forever.
Clearing its name puts it back in the ordinary queue of the last 20 saves, where it can be deleted
the moment a newer one is saved — possibly straight away."* If you have been saving a lot, clearing
a name can mean losing that version within seconds.

## Two things to know

**An override version is listed but cannot be restored from here yet.** The button is switched off
for those rows, and hovering it says *"An override version cannot be restored here yet — open its
own tab and copy the text in by hand."* That is an honest gap rather than a hidden one.

**History is unavailable while Sanitise is on.** An old version holds the real, unhidden values, so
showing it would defeat the point of the blur — see [hiding your values](hiding-your-values.md).
Turn Sanitise off and the tab comes back.

---

# Versions — going back to an earlier build of an app

Apps get republished, and a new build occasionally breaks something that worked. This tab lists the
builds of each of a stack's images that StaXX has actually seen on this server, and offers to put one
back.

Services are listed on the left; picking one shows that service's recorded builds on the right.

There is a shortcut. Where a row on the stack list is offering an update it can undo, its own menu
has **Roll back…** — that opens this tab with the right service already picked, so you do not have
to find it yourself. It only ever brings you here; the choosing and the confirming still happen on
this tab.

## What a build's row shows

| On the row | What it is |
|---|---|
| A heading | The version name the publisher gave that build — or, where they gave none, simply its date. Many images never name their builds, so a date is the ordinary case, not a missing value. |
| Date and a short fingerprint | Enough to tell two unnamed builds apart. |
| **See the source** | A link to where the image was published, where the registry offered one. |
| **What changed** | The release notes for that build. |
| **Running now**, or **Put this back** | The build you are on has no button; every other one offers to return you to it. |

The notes under **What changed** were captured at the moment that build was pulled and stored then —
nothing is fetched when you open the tab. Where the publisher wrote no release notes, you may get the
raw list of commits instead, prefaced by *"The project published no release under this build's name,
so this is the raw list of commits that went into it."*

## What "Put this back" actually does

This is the part people guess wrong. It does not simply reinstall an old image and leave things as
they were. **It edits the compose file so that it names that exact build** — and that is precisely
what makes it stick, because a later update has nothing vague left to move on to.

The confirmation says so plainly: *"This edits the compose file so it names that exact version, which
is what makes it stick — a pull will not move off it. The file as it stands now is kept in History,
so this can be undone."* It also warns that *"The version you are moving away from will not come back
on its own."*

Where the stack has an override file, it adds a further line: *"This stack has an override file,
though, and an image set there can win over this pin and make it look as though nothing happened."*
Worth reading — that is the one case where the whole thing appears to do nothing.

Because it is an ordinary edit, the file it replaced goes into History, and you can undo it there.

## Pinning and releasing live here too

Naming an exact build is what "pinned" means, so a pinned service and a service you put an old build
back on are the same thing. A pinned service shows a band reading **Pinned to …** with a **Release
this pin** button beside it. A pin you typed into the file yourself is recognised in exactly the same
way and released just the same.

Releasing explains what follows: *"The compose file will stop naming an exact build, so this service
follows its tag again. Nothing restarts now — it keeps running what it is running until the next
update or recreate moves it."* Nothing changes on the spot; the service simply becomes free to move
again the next time something moves it.

## Expect this list to be empty at first

A build is only recorded the first time StaXX itself updates that image, so a stack you have just
made or just imported shows *"Nothing has been recorded yet. A version is recorded the first time
StaXX updates one of this stack's images."*

That is not a fault. The list fills up as you use it — after a few [updates](updates.md) there is
something to go back to, and not before.

## What you can and cannot go back to

**You can only go back to a build StaXX itself recorded for that service.** Anything else is refused:
*"That version is not one recorded for this service, so it cannot be rolled back to."*

The reason is worth spelling out. Without that check, a request could point a service at any image
sitting anywhere on this server, not only one it has genuinely run — so the rule is what keeps going
back from turning into a way of quietly installing something else entirely.

The other refusals you may meet:

| Message | What it means |
|---|---|
| *"There is no earlier version recorded for this service, so it cannot be rolled back."* | Nothing has been recorded for it yet. |
| *"The previous version is no longer present on this server, so it cannot be rolled back to."* | The build was recorded but the image itself has since been removed from the server. |
| *"This service is not pinned to a version, so there is nothing to release."* | You asked to release a pin on a service whose file does not name an exact build. |

Two settings decide how much is available to go back to: how many previous versions of each image are
kept on the server, and whether images left behind by updating are removed automatically. That second
one only ever removes an image that nothing is running and that no recorded version still needs. Both
are on the [settings page](settings.md).

## What this never does

- **Restoring a file never writes to disk.** It puts the old text on screen as an unsaved change;
  nothing is committed until you press Save, and Undo puts back what you had.
- **It never deletes a named version.** Naming one takes it out of the queue entirely, and it can
  only rejoin that queue if you clear the name yourself, after being asked.
- **Releasing a pin never restarts anything.** The container carries on running exactly what it was
  running until something else moves it.
- **It never fetches release notes when you look at them.** They were saved when the build was
  pulled, so opening this tab does not go near the internet.
- **The record it keeps is not load-bearing.** It sits hidden inside the stack's own folder, and
  nothing in StaXX needs it. Delete it and everything still works — you simply lose the history.

**None of this certifies that going back will fix anything.** An earlier file or an earlier build is
just what you had before; whether it still works alongside everything else that has changed since is
a judgement only you can make.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
