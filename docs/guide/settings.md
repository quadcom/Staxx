# Settings

<!-- index: 80 | every setting behind the cog, group by group, plus the self-test and the first-run screen. -->

Press **Settings**, the cog above your stack list, to open this panel. Unraid's own Settings →
Utilities page is now just a signpost to it. The panel is the same size as the stack editor, and is
split into five tabs across its top: **General**, **Storage**, **Icons and images**, **Updates**,
and **Registries and security**.

![The Settings panel open at its top: Show StaXX in, the Data store box with its folder button and the three folders derived from it, Docker menu, and Installs from the Apps page](../images/guide/settings-panel-open.png)

![The settings panel's tab strip: General, Storage, Icons and images, Updates, Registries and security, with the first box of the General tab beneath](../images/guide/settings-tabs.png)

## Using the panel

1. Change what you want. **Save** stays dead until something actually changes.
2. Press **Save**. Everything is checked first. One bad value refuses the whole save — nothing is
   half-changed.
3. **Cancel** throws your changes away. Closing with something unsaved asks first.

Some settings need the page to reload — where StaXX appears, and where the data store is. StaXX
reloads for you and says so.

Every setting sits in its own titled box, with a short explanation above the control rather than
below it. A few boxes hold more than one setting where the settings belong together — the update
check's schedule, when an update installs itself, and Docker Hub sign-in are each one box like
this.

## General tab

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Show StaXX in | A tab under the Docker menu / Its own button in the top bar | A tab under Docker | As a tab it sits ahead of Docker Containers and is the tab you land on. Has no effect while Docker menu (below) is on. |
| Docker menu | Leave the Docker menu alone / Replace it with StaXX | Leave it alone | Replacing it takes everything under it with it, including Unraid's own container list. Nothing is changed underneath; turning it off puts it straight back. Greyed out and locked off while Show StaXX in is set to "a tab under Docker", since there is then no top-level button for it to replace. |
| Installs from the Apps page | Bring them into StaXX / Ask first / Leave them to Unraid | Bring them into StaXX | What happens when you install something from Unraid's own Apps page. Turning it off restores Unraid's own install route; nothing already installed changes. See [adding an app](installing-an-app.md). |
| Container shells | Allow opening a shell / Do not allow shells | Allow opening a shell | Off **refuses every shell on the server**, not just the tab. There is no way round it from the page. |

Under Show StaXX in and under Docker menu, a small row of pictures sits beneath the dropdown, one
for each choice, with the one you have picked outlined. Clicking a picture is a second way to pick
that choice — the dropdown and the pictures always agree.

![Two small pictures under the Show StaXX in setting: StaXX as a tab under Docker, and StaXX as its own button in the top bar, the chosen one outlined in orange](../images/guide/settings-pictures.png)

## Storage tab

![The top of the settings panel: the "Show StaXX in" choice, then the "Data store" box holding the folder's full path, the three greyed-out folders listed underneath it, and the "Move the data store" link below those](../images/guide/settings-data-store.png)

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Where stacks may live | Guide me / Get out of the way | Guide me | Guide me hides and refuses risky locations when choosing a folder — a single array disk, an unassigned or network drive, a share bound for the array. Get out of the way shows everything and warns instead. **Refused either way:** a location that lives in memory, and a whole share as the stacks folder — every folder in it would be read as a stack. |
| Data store | A folder path | blank | The one folder holding your stacks and their archives. See [file locations](where-things-live.md). |
| Copies on the flash drive | Keep a copy of every compose file there / Do not write copies | Keep a copy | After every save, a plain copy of the stack's file — with its override and its separate values file, if it has them — is written to the flash drive, which Unraid already backs up. Losing the data store then never means losing the definition of what you run; StaXX never reads these copies back while the store is present. |

The **Choose a folder** button sits beside the Data store box — a small folder icon. Press it to
browse the server instead of typing a path.

![The Data store box with its path and the folder-icon button beside it outlined](../images/guide/settings-data-store-row.png)

Two links sit under the data store box:

- **Move the data store** opens *Where should stacks live?*. It suggests a place on a storage
  pool and lists anywhere it declined to offer, with the reason next to it. The move copies
  everything, **checks it byte for byte, and only then removes the original** — nothing is
  deleted before the copy is proved good. A failed move leaves your data exactly where it was.

  ![The Where should stacks live dialog: the current location, a Move it to box with Browse, the Move the data store button, and a Not offered list explaining why two pools are not suggested](../images/guide/settings-move-dialog.png)

- **Check these are in your backup** opens *Add these folders to your backup*, which says whether
  these folders are named in the Appdata Backup plugin. Being named is not the same as having been
  backed up — see the self-test below.

  ![The Add these folders to your backup dialog, reporting that the stacks and archives folders were found in the backup plugin's list](../images/guide/settings-backup-dialog.png)

Keeping the store on the flash drive is possible through a separate, quieter button. It makes you
tick a box first: flash wears out with use, and it is the least redundant place in the machine.

A read-only list of the zips of stacks you have removed, with dates and sizes, sits at the bottom
of this tab as **Archived stacks**.

## Icons and images tab

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Container icons | Download them automatically / Do not download anything | Download automatically | Off, containers with no icon get a coloured tile with their initials. The only thing sent out, when on, is the name of the icon being asked for. |
| Image documentation | Read it automatically / Do not look anything up | Read it automatically | Whether adding an image reads its own documentation to build a fuller starting file, and to fill in a stack's description, category, author and links. Only runs the moment you add something, never in the background. The only thing sent out is the image's name. |
| Watch the publisher's own examples | Look, during the same check / Do not look | Look | Whether the update check also asks GitHub if the image's own project publishes an example file — never Docker Hub, so it never spends the update-checking allowance. |

**Add missing StaXX fields to every stack**, at the bottom of this tab, reads every stack and
offers to add its icon, links and description fields as commented placeholders. It shows what
would be added before writing anything, and never changes what is already in a file.

## Updates tab

This tab is explained fully in [checking for updates](updates.md). What the panel decides:

![The Updates tab: Check for image updates with how often and time of day, What to do with what is found, a greyed-out When to install box, and Notify me](../images/guide/settings-image-updates.png)

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Check for image updates | Never / Every day / Once a week | Every day | Off means nothing is ever looked up. Shares a box with the time of day below it. |
| Time of day to check | A 24-hour time | 04:00 | When the daily or weekly check runs. |
| What to do with what is found | Just show it on the row / Wait for you to press Update / Install it by itself | Wait for you to press Update | A stack or a single service can override this in its own file. |
| Delay before installing | 0 to 720 hours | 24 | Only used by "Install it by itself" — how long an update counts down on its row first. |
| Only install during a quiet time | Yes / No | Yes | An update whose delay runs out outside quiet hours waits for them to open. |
| Quiet time starts / ends | A 24-hour time | 03:00 / 05:00 | The quiet window. It may run past midnight. |
| Notify me | Never / When a check finds something / That, and again once installed | Never | One message per check or queue, never one per container. |
| Previous image releases to keep | 0 to 5 | 2 | How many older image versions stay on disk so an update can be undone. See [version history](going-back.md). |
| Remove old images automatically | No / Yes, once a week | No | Only ever removes an image nothing is running and no roll-back still needs. |

The "when to install" box — Delay before installing, through the quiet time — folds away out of
sight unless "What to do with what is found" is set to "Install it by itself". There is nothing to
set there otherwise, so it is not shown.

At the bottom of this tab, **Update-check activity** shows as a table instead of a paragraph: one
row per registry, with how many times it has been asked this hour and today, how many of those
asks counted against its allowance, and a note beside any registry worth a closer look. Worth
checking whenever a `could not check` pill keeps turning up; see
[checking for updates](updates.md#docker-hubs-limit).

## Registries and security tab

A username, an access token, and a list of registries you run yourself — used only when checking
your images for updates. **Without signing in, Docker Hub allows this server about ten of those
checks an hour; signed in, about a hundred.**

![The Registries you run yourself box with its address field and Add button, and the StaXXCrypt hashing container box below it](../images/guide/settings-registries.png)

| Setting | What it does |
|---|---|
| Docker Hub username | Your Docker Hub account name. Leave blank to stay signed out. Shares a box with the access token below it. |
| Docker Hub access token | Make it in Docker Hub's own account settings, under Security → Personal access tokens, with the **read-only, public repositories** permission. StaXX only ever asks an image its current version, so a leaked token could look but never change or delete anything. It is kept in StaXX's own settings file inside the data store, readable only by the administrator account. Leave both boxes blank to sign out. |
| Registries you run yourself | Naming a registry here makes StaXX trust that machine's own certificate, or talk to it with **no encryption at all** if it has none. Only ever name a registry you run yourself. Added one at a time to a list — type an address and press Add, or remove one with its own cross. A password is never sent to a registry reached without encryption. Left empty, nothing beyond the ordinary public internet is trusted. |
| StaXXCrypt hashing container | Only while hashing, or keep it running instead. The container behind [making a password hash](passwords-and-hashes.md). Kept running, hashing is near-instant; on demand it costs nothing idle but takes a couple of seconds per hash. |

![The StaXXCrypt block on the Settings panel: a setting for whether the container stays running, its state reading Built, and running now with a recipe number, and each hash format listed with its own result — bcrypt, SHA-512 crypt, SHA-256 crypt and argon2id all passing](../images/guide/settings-staxxcrypt.png)

Underneath the StaXXCrypt setting, a short plain-words list called **What is inside it** says
exactly what the container is built from — a small Linux base, the two extra packages it adds
for the hash types Unraid's own PHP cannot make, nothing of StaXX's own copied in, and that it
runs with no network connection, no ports and no shared folders. A **Show the recipe** link below
that reveals the exact file it is built from and the exact commands run against it, for anyone who
wants to check for themselves rather than take the summary's word for it.

## First-run screen

Before a data store is chosen, the stack list is replaced by one notice: **No data store has been
chosen yet.** Two buttons sit under it — **Choose a data store**, which opens the same *Where
should stacks live?* dialog as the settings panel's own link, and **Go to Settings**.

The dialog suggests a place on a storage pool and lists anything it will not offer, with the reason
next to it. If the machine has no pool it can reach, it says so and offers the safer of the two
remaining choices instead. Typing your own path shows what StaXX can tell about it — free space,
and a warning if it goes through Unraid's share layer rather than a pool directly.

![The page before a data store is chosen: an orange-edged notice saying no data store has been chosen yet, with Choose a data store and Go to Settings buttons](../images/guide/settings-first-run.png)

## Self-test

**Self-test**, the button beside Settings, answers "why did nothing happen?" with facts, in two
stages.

![The Self-test output: a list of checks with their answers, then a list of commands run one at a time, each ending in ok](../images/guide/settings-self-test-output.png)

**First, everything answerable without running a command** — where the stacks live, whether that
folder exists and is writable, free space, how many stacks and folders there are, how many are
waiting to be reviewed, and whether Docker and compose are on disk. This stage cannot hang, which is
the point: it is what you reach for *when* Docker is hanging.

**Then the commands, one at a time** — a trivial command, the time-limit tool, the Docker client,
the Docker daemon, compose, listing containers, listing projects. They run in order on purpose: the
last line printed is the last thing that worked, so a stall shows exactly where it happened.

Where something genuinely cannot be seen — the stacks folder is on a pool that has not mounted yet,
say — the answer is **UNKNOWN**, not nought. "0 stacks" while the array is down would be a healthy
nothing dressed up as a fact.

**The backup line needs care.** It reports whether your compose files are *named in* the Appdata
Backup plugin's list of extra files. **Being listed is not the same as having been backed up.**
Whether a backup has ever run, finished, or reached a destination that still exists is something
StaXX cannot see. If the line says the folders are not listed, act on that straight away; if it
says they are, whether you really have a backup is still your call.

## Save refusals

| What it says | Why |
|---|---|
| The data store cannot be moved in the same save as another setting | The other settings live inside the store, so moving it and changing them at once cannot be done coherently. Change the location on its own, or use the move link, which copies everything first. |
| The data store cannot be reached right now, so this cannot be saved | The store is on a pool that has not finished starting. Only the store's location and the two menu settings can be changed until it comes back. |
| The data store must be somewhere under /mnt/ | It has to be a real share or disk. Choose a location there instead. |
| It would be created on a filesystem that lives in memory | Such a path looks normal right up to the reboot that empties it. Put the store on a share, or a drive that is actually mounted. |
| That is the whole of the share, and every folder in it would be read as a stack | Pointed at appdata, every container's own config folder would appear as a stack. Use a folder inside the share instead — the message names the exact path to use. |
| The new location already holds something | A move needs somewhere empty. Choose an empty folder, or a path that does not exist yet. |
| Settings were saved, but applying them failed | Your settings did save. The small step that puts menu changes into effect failed. Reboot the server to finish applying them. |

## What this never does

- It never changes any of Unraid's own files. Replacing the Docker menu hides it; turning that off
  puts it back exactly as it was.
- It never saves some of your changes and refuses others. One bad value saves nothing.
- It never sends anything about this server, its containers or its settings out, beyond an icon's
  name, an image's name, or a watched project's address.
- It never deletes your data to make room for a move. The copy is proved good first.
- It never certifies that you have a backup — only that a folder is, or is not, on a list.

## Not built yet

- Exporting or importing your settings.
- Resetting them all to how they started.
- A record of what you changed and when.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
