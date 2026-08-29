# Where StaXX keeps its things

<!-- index: 30 | what lives in the data store versus the three lines on the flash drive, and the three ways to change where StaXX appears if you cannot reach its page. -->

**Almost everything StaXX knows lives in one folder you chose yourself, called the data store. Only
three lines live somewhere else — on the flash drive Unraid boots from — because those three have to
be readable before the data store is even reachable.** That split is also why there is always a way
back in if something goes wrong: this page covers both.

## The three lines on the flash drive

They sit in a small settings file, alongside every other plugin's own settings, in Unraid's own
configuration folder on the flash drive.

| Line | What it does |
|---|---|
| `STORE_ROOT` | The full path to the data store. This is the one line that says where everything else is — it has to sit somewhere StaXX can read before it knows where "everywhere else" is. |
| `HEADER_MENU` | `true` gives StaXX its own button in the row along the top of the Unraid screen. `false` puts it as a tab under the existing Docker menu instead. |
| `TAKEOVER_DOCKER_TAB` | `true` removes Unraid's own Docker button from the top row and puts StaXX there instead. `false` leaves Unraid's Docker button exactly as it is. |

Each value sits in double quotes, for example `HEADER_MENU="false"`. A line starting with a
semicolon is a comment and is ignored.

## Three ways to change these, in the order to try them

1. **StaXX's own settings panel** — the normal way. Open StaXX and press Settings. Use this whenever
   you can reach it: it is the only route that keeps every other setting in step at the same time.
2. **Unraid's own settings page for StaXX** — under Settings → Utilities → StaXX. This is the escape
   hatch. It writes straight to the flash drive, which is why it still works even when StaXX's own
   page cannot be reached at all, or while the array is still starting. It only offers the Docker-menu
   choices and the data store's location — nothing else.
3. **Editing the file by hand** — the last resort, and the only one that needs no web page at all.
   Open the settings file on the flash drive — over the network share Unraid publishes, or by putting
   the flash drive in another computer — and change the line yourself.

   How soon it takes effect depends on which line you changed. A new `STORE_ROOT` is read the next
   time you load a StaXX page, so it applies straight away. The two menu lines do not: where the menu
   appears is decided from two small marker files that get rewritten when settings are saved, and a
   hand edit does not rewrite them. To make a hand-edited menu line take effect, either reboot, or
   open Settings → Utilities → StaXX and press Apply — that page loads whatever the file now says, so
   pressing Apply saves your own edit back and rewrites the markers to match it.

## Putting the ordinary Docker tab back

Set `TAKEOVER_DOCKER_TAB` to `false`, using whichever of the three routes above you can reach.
StaXX never changes any of Unraid's own files, so nothing needs repairing — the Docker button simply
reappears exactly as it was.

## Taking StaXX's button out of the top row

Set `HEADER_MENU` to `false`. StaXX then shows up as a tab inside the existing Docker menu instead
of its own button. This is a separate question from the one above, and the two can be set
independently — you can have StaXX gone from the top row but still standing in for Docker, or StaXX
in the top row with Docker's own button left alone.

## If nothing loads at all

Reboot the server. The part of Unraid that holds a plugin's pages is rebuilt from nothing every time
the server starts, so a broken plugin page cannot survive a reboot — it is the safe reset. None of
your settings are lost, because none of them live in that part.

## While the array is still starting

For a short while after the server powers on, StaXX can read its three lines from the flash drive but
not the rest of its settings, because the data store lives on a drive pool that has not mounted yet.
StaXX says so on screen when this is happening, and shows its shipped defaults in the meantime.
Nothing is lost, and it corrects itself on its own once the array finishes starting. This is expected,
not a fault — the one thing not to do is start changing settings while it is happening, since there
is nothing there yet to change.

## What is actually in the data store

The data store is the folder you pick the first time you open StaXX, usually somewhere on a drive
pool alongside your other application data. Everything StaXX manages lives inside it:

- Your **stacks** — each in its own folder, holding an ordinary compose file (a plain text file
  listing the containers you want and how they are set up).
- **archives** — a zip of every stack you have removed.
- **config** — StaXX's own settings, the container icons it has downloaded, and its own housekeeping
  notes. A plain-text note inside that folder explains what each thing in it is, if you are ever
  looking at it directly.

## Coming from a version before the data store

Before StaXX had a data store, its things were in four or five different places: the stacks
wherever you had pointed them (out of the box, on the flash drive), the zips of removed stacks
somewhere else again, and the settings and downloaded icons on the flash drive. Upgrading does not
move any of that for you — StaXX will simply ask where its data should live, as though it were new,
and your old files will still be sitting where they were.

Moving them is a handful of commands, and nothing is deleted along the way, so you can check each
step before going on.

**First, stop your stacks.** Docker records where each running stack's files are, and moving them
out from underneath a running stack is the one thing worth avoiding here.

**Find out where your things are now.** The first two lines tell you where the stacks and the zips
were kept; a blank second line means they were under your appdata folder in `staxx/archives`:

```sh
grep -E '^(STACK_ROOT|ARCHIVE_ROOT)=' /boot/config/plugins/staxx/staxx.cfg
```

**Decide where the store goes** — a folder inside a share on a drive pool, alongside your other
application data, is the right answer for almost everyone. `/mnt/user/appdata/staxx` in the commands
below; change it if you chose differently.

**Move the stacks and the zips in.** If your stacks were already somewhere sensible, this is a
rename rather than a copy, and takes no time:

```sh
mkdir -p /mnt/user/appdata/staxx
mv /path/from/the/first/line   /mnt/user/appdata/staxx/stacks
mv /path/from/the/second/line  /mnt/user/appdata/staxx/archives
```

**Carry your settings across.** This keeps everything except the handful of retired settings and
the three that stay on the flash drive:

```sh
mkdir -p /mnt/user/appdata/staxx/config
grep -vE '^(STORE_ROOT|HEADER_MENU|TAKEOVER_DOCKER_TAB|STACK_ROOT|ARCHIVE_ROOT|STORAGE_CHOICE)='   /boot/config/plugins/staxx/staxx.cfg > /mnt/user/appdata/staxx/config/staxx.cfg
chmod 600 /mnt/user/appdata/staxx/config/staxx.cfg
```

That last line matters. If you had signed in to Docker Hub, that file holds the sign-in token, and
on the flash drive the file permissions were decoration — every file there reads as private however
it is set, because of how the drive is mounted. Off the flash drive they are real, so it is worth
setting it properly.

**Bring your icons over**, so they are not all downloaded again:

```sh
mkdir -p /mnt/user/appdata/staxx/config/icons
cp -n /boot/config/plugins/staxx/icons/* /mnt/user/appdata/staxx/config/icons/ 2>/dev/null
chmod 644 /mnt/user/appdata/staxx/config/icons/*
```

The `chmod` is for the same reason: copying off the flash drive carries that "everything is private"
appearance with it, and icons are just pictures.

**Now open StaXX.** It asks where its data should live. Give it the folder you have just filled in,
and it will recognise it, tell you how many stacks it found, and let you in. Check the number looks
right before going further.

**Nothing above deletes anything.** Your old settings file, the old icons and the old folders are
all still where they were. Leave them a few days, and once you are happy that everything came
across, they can go.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
