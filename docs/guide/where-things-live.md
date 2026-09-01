# Where StaXX keeps its things

<!-- index: 30 | what is in the data store, what is on the flash drive, how to move the store, and how to reach StaXX if you cannot get to its page. -->

Almost everything StaXX knows sits in one folder you chose yourself: the data store. Only three
lines live on the flash drive instead, because they have to be readable before the data store is
even reachable.

## The data store

![The settings panel showing the data store's full path in its own box, with the three folders inside it — the stacks, the archives and the config folder — listed underneath, and a link offering to move the whole store elsewhere](../images/guide/settings-data-store.png)

| Folder | What is in it |
|---|---|
| `stacks` | Every stack, each in its own folder. |
| `archives` | A zip of every stack you have removed. |
| `config` | StaXX's own settings and the container icons it has downloaded. |

A plain-text note inside `config` explains what each folder is, if you ever open the store directly.

You choose the store's location the first time you open StaXX, and can move it later. See
[the settings panel](settings.md) for both.

## What is inside a stack's own folder

A stack's folder holds its compose file — an ordinary text file, readable by `docker compose`
anywhere, not just here. Alongside it may sit whatever else that stack needs: an environment file,
a data folder, anything the author wrote.

One thing is hidden: a small folder holding the stack's own saved history. See
[going back](going-back.md) for what it keeps and how to use it.

## The three lines on the flash drive

They sit in a small settings file, alongside every other plugin's own settings.

| Line | What it does |
|---|---|
| `STORE_ROOT` | The full path to the data store. It has to sit somewhere StaXX can read before it knows where everything else is. |
| `HEADER_MENU` | `true` gives StaXX its own button in the row along the top of the screen. `false` puts it as a tab under the Docker menu instead. |
| `TAKEOVER_DOCKER_TAB` | `true` removes Unraid's own Docker button and puts StaXX there instead. `false` leaves it alone. |

## Moving the data store

Open [the settings panel](settings.md) and press **Move the data store**. It copies everything to
the new place, checks it byte for byte, and only removes the old folder once that check has passed.
If the move fails, your data is still exactly where it was.

## Three ways to reach StaXX, in the order to try them

1. **StaXX's own settings panel.** Open StaXX and press Settings. Use this whenever you can — it is
   the only route that keeps every other setting in step. See [the settings panel](settings.md).
2. **Unraid's own settings page for StaXX**, under Settings → Utilities → StaXX. An escape hatch: it
   writes straight to the flash drive, so it still works even when StaXX's own page cannot be
   reached, or while the array is still starting. It only offers the Docker-menu choices and the
   data store's location.
3. **Editing the file by hand**, the last resort. Open the settings file on the flash drive — over
   the network share Unraid publishes, or by putting the drive in another computer — and change the
   line yourself.

   A new `STORE_ROOT` is read the next time you load a StaXX page. The two menu lines are not: they
   are decided from two small marker files, rewritten only when settings are saved through one of
   the other two routes. To make a hand-edited menu line take effect, reboot, or open Settings →
   Utilities → StaXX and press Apply.

## If nothing loads at all

Reboot the server. The part of Unraid that holds a plugin's pages is rebuilt from nothing every time
the server starts, so a broken page cannot survive a reboot. None of your settings are lost, because
none of them live in that part.

## While the array is still starting

For a short while after the server powers on, StaXX can read its three flash-drive lines but not the
rest of its settings, because the data store lives on a drive pool that has not mounted yet. StaXX
says so on screen, and shows its shipped defaults in the meantime. It corrects itself once the array
finishes starting.

## What this never does

- It never moves or deletes your data on its own — moving the store is always something you press.
- It never removes a file the copy hasn't already proved good, when moving the store.
- It never changes any of Unraid's own files. Taking over the Docker tab hides it; turning that back
  off puts it back exactly as it was.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
