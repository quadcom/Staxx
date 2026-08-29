# The settings panel

<!-- index: 80 | what the cog button opens, group by group: where StaXX appears, the data store and how to move it, icons, updates, sign-ins, and the self-test that sits beside it. -->

**Almost everything you can change about StaXX lives behind one button: Settings, the cog in the
row of buttons above your stack list.** Unraid's own page for StaXX, under Settings → Utilities, is
now only a signpost with a two-field escape hatch for when you cannot reach StaXX at all — see
[where StaXX keeps its things](where-things-live.md) for that route.

## The short version

1. Open StaXX and press **Settings**.
2. Change what you want. **Save wakes up only once something has actually changed** — until then it
   is deliberately dead, so a panel you only opened to look at cannot save anything.
3. Press **Save**. Everything is checked first: if one value is refused, **nothing at all is
   saved**, so you never end up half-changed.
4. **Cancel** throws your changes away. Closing the panel with something unsaved asks first.

A few settings need the page to redraw itself — where StaXX appears, and where the data store is.
StaXX reloads the page for you and says so.

## Where StaXX appears

| Setting | What it decides |
|---|---|
| **Show StaXX in** | A tab under the Docker menu, or its own button in the row along the top of the screen. As a Docker tab it sits ahead of Docker Containers and becomes the tab you land on. |
| **Docker menu** | Leave Unraid's Docker menu alone, or replace it with StaXX. Off unless you turn it on. |

**These two interact, and it catches people out.** With the Docker menu replaced, the first setting
has no effect at all — StaXX has to be a top-level item for there to be any way in.

Replacing the Docker menu takes everything that lived under it with it, Unraid's own container list
included. Nothing of Unraid's is modified and no container is touched; turning it back off puts all
of it straight back. [Where StaXX keeps its things](where-things-live.md) covers putting the Docker
tab back when you cannot reach this panel, and what to do if nothing loads at all.

## The data store

One box, holding the full path of the one folder StaXX keeps everything in. Underneath it, greyed
out and not editable, are the three things inside that folder — your stacks, the zips of stacks you
have removed, and StaXX's own settings and icons. They are not a second choice to make; they are
simply what choosing a store does. [What is actually in the store](where-things-live.md) explains
each one.

![The top of the settings panel: the "Show StaXX in" choice, then the "Data store" box holding the folder's full path, the three greyed-out folders listed underneath it, and the "Move the data store" link below those](../images/guide/settings-data-store.png)

Two links sit under that box:

- **Move the data store** opens its own dialog, *Where should stacks live?*. It suggests a place on
  a storage pool, and lists anywhere it declined to offer **with the reason next to it**, so you can
  see what it left out and why. The move copies everything to the new place, **checks it byte for
  byte, and only removes the original once that check has passed** — nothing is deleted before the
  copy is proved good. If the move fails, your data is still exactly where it was.
- **Check these are in your backup** asks whether these folders are named in the Appdata Backup
  plugin. It can only report what that plugin has been told to include — see the self-test below,
  where the same caution applies.

Keeping the store on the flash drive is possible, but it is a separate, quieter button, and it asks
you to tick a box saying you understand: flash can only be written a limited number of times before
it wears out, and it is the least redundant thing in the machine.

## Installs from the Apps page

Bring them into StaXX, ask first, or leave them to Unraid — what happens when you install something
from Unraid's own Apps page. "Ask first" shows an offer before anything is converted. Turning it off
puts Unraid's own install route back exactly as it was, and nothing already installed changes.
[Adding an app from Community Applications](installing-an-app.md) has the detail.

## Icons

| Setting | What it decides |
|---|---|
| **Container icons** | Whether your server downloads the logo of the software each container runs. It happens once per icon and then never again, and **the only thing sent out is the name of the icon being asked for**. Turned off, containers with no icon get a coloured tile with their initials. |
| **Keep icons with the stack** | Whether the icon's name is written into the stack's own file, with a copy of the picture saved beside it. The stack then owns its icon and looks the same on any server you copy the folder to. An icon you named yourself is never touched. |

## Image updates

This whole group is explained properly in [checking for updates](updates.md) — including why some
images are asked about far more often than others. What the panel decides:

| Setting | What it decides |
|---|---|
| **Check for image updates** | Never, every day, or once a week. Off means nothing is ever looked up. |
| **Time of day to check** | A 24-hour time. The middle of the night is a sensible answer. |
| **Watch the publisher's own examples** | Whether the check also looks at whether an image's own project publishes an example compose file. It talks to GitHub, never Docker Hub, so it never spends your update-checking allowance. |
| **What to do with what is found** | Just show it on the row, wait for you to press Update, or install it by itself. A stack or a single service can override this in its own file. |
| **Delay before installing** | Only used by the last of those — how many hours an update counts down on its row first. |
| **Only install during a quiet time**, and the two times | An update whose delay runs out outside your quiet hours waits for them to open. |
| **Notify me** | Never, when a check finds something, or that and again once it is installed. One message per check, never one per container. |
| **Previous versions to keep** | How many older versions of each image stay on disk so an update can be undone. Nought to five. See [going back](going-back.md). |
| **Remove old images automatically** | Off, or once a week. It **only ever removes an image nothing is running and no roll-back still needs** — it is not a general clean-up of everything unused. |

## Docker Hub sign-in

A username and an access token, used only when checking your images for updates. **Without signing
in, Docker Hub allows this server about ten of those checks an hour; signed in, about a hundred.**
That is the whole reason it is here.

Make the token in Docker Hub's own account settings, under security, and give it the **read-only,
public repositories** permission. That is all StaXX ever does with it — ask an image what its
current version is — so a token that leaked could look, but never change or delete anything. It is
kept in StaXX's own settings file inside the data store, readable only by the administrator account.
Leave both boxes blank to sign out. (The hint on screen still says the token lives on the flash
drive. It does not any more; it lives in the data store with everything else.)

## Registries you run yourself

Naming a registry here makes StaXX trust that machine's own certificate — or talk to it with **no
encryption at all** if it has not been given one. So only ever name a registry you actually run,
never somebody else's. Several are separated by commas, each a bare name or address with an optional
port and nothing else. **A password is never sent to a registry reached without encryption.** Left
blank, nothing beyond the ordinary public internet is trusted.

## Everything else

| Setting | What it decides |
|---|---|
| **Where stacks may live** | *Guide me* greys out and refuses risky places when you are choosing a folder — a single array disk, an unassigned or network drive, a share Unraid is set to move onto the array. *Get out of the way* shows everything and warns instead of stopping you. **Two things are refused either way:** a location that lives in memory, because everything in it would be gone at the next reboot, and a whole share as the stacks folder, because every folder in that share would then be read as a stack. |
| **Container shells** | Whether a root command line inside a running container is offered at all. Turning it off **refuses every shell on the server, not just hides the tab** — there is no way round it from the page. |
| **Image documentation** | Whether, the moment you add an image, your server reads that image's own documentation to build a fuller starting file instead of four bare lines. It never runs in the background, and the only thing sent out is the name of the image. |
| **StaXXCrypt hashing container** | Whether the small container StaXX builds for making password hashes stays stopped between uses, or keeps running. On demand costs nothing while idle but takes a couple of seconds per hash; kept running, hashing is near-instant. |

## Also on the panel, but not settings

- **Add missing StaXX fields to every stack** reads every stack, then offers to add its icon, links
  and description fields as commented placeholders. It shows you exactly which stacks would gain
  what before anything is written, and **nothing already in a file is changed**.
- **Archived stacks** — a read-only list of the zips of stacks you have removed, with dates and
  sizes. There is nothing to save here, only to look at.
- **The hashing container's own state** — whether it is built and running, which hash formats it has
  actually proven it can make, and buttons to Build, Recreate or Rebuild it. A newer recipe is only
  ever a notice; nothing rebuilds until you press the button.

## The self-test

**Self-test** is the button beside Settings, not part of the panel. It answers "why did nothing
happen?" with facts rather than guesses, in two stages.

**First, everything it can answer without running a single external command** — where the stacks
live, whether that folder exists and can be written to, free space, how many stacks and folders are
there, how many are waiting to be reviewed, and whether Docker and compose are on disk at all. This
stage cannot hang, which is the point: it is what you reach for *when* Docker is hanging.

**Then the commands, one at a time** — a trivial command, the time-limit tool, the Docker client,
the Docker daemon, compose, listing containers, listing projects. They run one after another **on
purpose**. Run together they would tell you something stalled, but not which; run in order, the last
line printed is the last thing that worked and the missing one is the culprit. If it stops, it says
so plainly.

Where it genuinely cannot see something — the stacks folder is on a pool that has not mounted yet,
say — it answers **UNKNOWN** rather than nought. That is the honest answer and not a fault: a
report of "0 stacks" while the array is down is a healthy nothing dressed up as a fact.

**The backup line needs care.** It reports whether your compose files are *named in* the Appdata
Backup plugin's list of extra files to include. **Being listed is not the same as having been backed
up.** Whether a backup has ever actually run, finished, or reached a destination that still exists
is something StaXX cannot see and does not claim to know. If the line says the folders are not
listed, that is worth acting on straight away; if it says they are, the judgement about whether you
really have a backup is still yours.

## When a save is refused

A refusal always says what to do next. The ones people actually meet:

| What it says | Why |
|---|---|
| The data store cannot be moved in the same save as another setting | The other settings live *inside* the store, so moving the store and changing them at once cannot be done coherently. Change the location on its own, or use the move link, which copies everything across first. |
| The data store cannot be reached right now, so this cannot be saved | The store is on a pool that has not finished starting. Only the store's location and the two menu settings can be changed until it comes back. Wait for the array to finish starting and try again. |
| The data store must be somewhere under /mnt/ | It has to be a real share or disk. Choose a location there instead. |
| It would be created on a filesystem that lives in memory | Such a path looks and behaves perfectly normally right up to the reboot that empties it. Put the store under a share, or on a drive that is actually mounted. |
| That is the whole of the share, and every folder in it would be read as a stack | Pointed at appdata, every container's own config folder would appear as a stack. Use a folder inside the share instead — the message names the exact path to use. |
| The new location already holds something | A move needs somewhere empty, so nothing of yours can be mixed in with what arrives. Choose an empty folder, or a path that does not exist yet. |
| Settings were saved, but applying them failed | **Your settings did save.** What failed was the small step that puts the menu changes into effect. Reboot the server to finish applying them. |

## What this never does

- It never changes any of Unraid's own files. Replacing the Docker menu hides it; turning that back
  off puts it back exactly as it was.
- It never saves some of your changes and refuses others. One bad value saves nothing.
- It never sends anything about this server, its containers or its settings out. An icon download
  sends the icon's name, reading an image's documentation sends the image's name, and watching a
  publisher's examples sends that project's address. That is the whole list.
- It never deletes your data to make room for a move. The copy is proved good first.
- It never certifies that you have a backup — only that a folder is, or is not, on a list.

## Not built yet

There is no way to export or import your settings, and no way to reset them all to how they started.
There is also no record of what you changed and when.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
