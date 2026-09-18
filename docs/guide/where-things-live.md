# File locations and the data store

<!-- index: 30 | what is in the data store, what is on the flash drive, how to move the store, and how to reach StaXX if you cannot get to its page. -->

Almost everything StaXX knows sits in one folder you chose yourself: the data store. Three lines
live on the flash drive instead.

## If you cannot reach StaXX

1. **StaXX's own settings panel.** Open StaXX and press Settings. Use this whenever you can. It is
   the only route that keeps every other setting in step. See [Settings](settings.md).
2. **Unraid's own settings page for StaXX**, under Settings → Utilities → StaXX. It writes straight
   to the flash drive, and works even when StaXX's own page cannot be reached, or while the array is
   still starting. It only offers the Docker-menu choices and the data store's location.
3. **Editing the file by hand**, the last resort. Open the settings file on the flash drive (over the
   network share Unraid publishes, or by putting the drive in another computer) and change the line
   yourself.

   A new value for the data store's location takes effect the next time you load a StaXX page. The
   two menu lines do not: reboot, or open Settings → Utilities → StaXX and press **Apply**, to make a
   hand-edited menu line take effect.

## If nothing loads at all

Reboot the server. The part of Unraid that holds a plugin's pages is rebuilt every time the server
starts. Your settings live elsewhere, and are not lost when this happens.

## While the array is starting

For a short while after the server powers on, StaXX can read its three flash-drive lines but not the
rest of its settings. It says so on screen, and shows its shipped defaults in the meantime. It
corrects itself once the array finishes starting.

## The data store

![The settings panel showing the data store's full path in its own box, with the three folders inside it — the stacks, the archives and the config folder — listed underneath, and a link offering to move the whole store elsewhere](../images/guide/settings-data-store.png)

| Folder | What is in it |
|---|---|
| `stacks` | Every stack, each in its own folder. |
| `archives` | A zip of every stack you have removed. |
| `config` | StaXX's own settings and the container icons it has downloaded. |

A plain-text note inside `config` explains what each folder is, if you open the store directly.

You choose the store's location the first time you open StaXX, and can move it later. See
[first run](first-run.md) and [Settings](settings.md).

## Inside a stack's folder

A stack's folder holds its compose file. Alongside it may sit whatever else that stack needs: a
`.env` file, a data folder, anything the author wrote.

One thing is hidden: a small folder holding the stack's own saved history. See
[version history](recovery-and-redundancy.md) for what it keeps and how to use it.

## Flash drive settings

The three lines sit in a small settings file, alongside every other plugin's own settings.

| Line | What it does |
|---|---|
| `STORE_ROOT` | The full path to the data store. |
| `HEADER_MENU` | `true` gives StaXX its own button in the row along the top of the screen. `false` puts it as a tab under the Docker menu instead. |
| `TAKEOVER_DOCKER_TAB` | `true` removes Unraid's own Docker button and puts StaXX there instead. `false` leaves it alone. |

## Moving the data store

Open [Settings](settings.md) and press **Move the data store**. It copies everything to the new
place and checks it byte for byte before removing the old folder. If the move fails, your data is
still exactly where it was.

![The Where should stacks live dialog: the current location, a Move it to box with Browse, the Move the data store button, and a Not offered list explaining why two pools are not suggested](../images/guide/settings-move-dialog.png)

## Copies on the flash drive

Every stack's compose file, override and `.env` file is also copied to the flash drive, at
`/boot/staxx/stacks/<the stack's own path>`. Unraid backs up the whole flash drive on its own, taking
this copy with it.

The copies are refreshed whenever you save, start or update a stack here, and, depending on the
choice made on the **Storage** settings tab, either once an hour or the moment a change is made
outside StaXX. A copy can be a little behind a file you edited by hand until then.

If the data store is ever lost, StaXX offers to bring every stack back from these copies. See
[if the data store is lost](recovery-and-redundancy.md).

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
