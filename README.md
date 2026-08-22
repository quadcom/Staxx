# StaXX — Docker on Unraid, built on compose files

> **Where this is up to.** In daily use on the author's own server, and far enough along to judge on
> its merits. What it is not yet is *packaged* — there is no one-click install and it is not listed in
> Community Applications, so trying it means copying the files onto a server yourself. See
> [Installing](#installing). Every screenshot here is a live server.
>
> Version 2026.08.21 · [changelog](CHANGELOG.md)

Unraid describes containers with its own template format. StaXX uses
**[compose files](https://compose-spec.io/)** instead — the format containerised projects already
publish. Paste one in from a project's documentation and it runs.

If you have never read a compose file, StaXX reads it for you and draws it as a form. The file
underneath stays an ordinary compose file that runs anywhere.

![The stacks page](docs/images/stacks-overview.jpg)

---

## Contents

- [The stacks page](#the-stacks-page) · [The editor](#the-editor) · [Adding an app](#adding-an-app)
- [Importing what you already run](#importing-what-you-already-run) · [Updates](#updates) · [Running containers](#running-containers)
- [Autostart](#autostart) · [Removing a stack](#removing-a-stack) · [Settings](#settings)
- [What it promises](#what-it-promises) · [Roadmap](#roadmap) · [Installing](#installing) · [For contributors](#for-contributors)

---

## The stacks page

**This page is a view of one folder on your server.** A subfolder with a compose file in it is a
**stack**; one without is just a folder. Add a compose file by hand and its row appears; delete the
folder and it goes.

![A folder opened up, with the stacks inside it](docs/images/stacks-grid.jpg)

Every stack is a row, and each container inside it gets its own row.

- **Live figures** per container — processor, memory, network and graphics, each with a small graph.
  Graphics figures cover Intel and AMD cards; **Nvidia shows no figures yet**, though a container
  given an Nvidia card is still labelled as such.
- **State** per container, with uptime and what its health check says.
- **Four buttons** — web page, logs, project page, Unraid support thread. One with nowhere to go is
  greyed.
- **Its address**, with Unraid's own address-and-port substitution.
- **Badges** for an update waiting, a withdrawn tag, an image that moved registry, a copy that has
  drifted from its source, a container built from an image the file no longer names.

![Badges on the rows](docs/images/row-states.jpg)

**Folders.** Stacks can sit one level deep in folders you create. A folder row totals what is inside
it and can start, stop, check or update the lot.

**Right-click a row** for everything it can do.

![The row menu](docs/images/stack-row-menu.jpg)

Rows follow Docker's own events, so a container stopped elsewhere shows as stopped straight away.

---

## The editor

Three views of the same file: the **form**, the **file**, or both side by side with the highlight
following you between them.

![Form and file side by side, with the stack's other files as tabs above](docs/images/editor-split.jpg)

The form covers more than twenty groups of settings — image and web page port, networks, ports,
folders, variables, devices, labels, health check, resource limits, build, start-up order, secrets,
configs, profiles, name servers, permissions, internal ports, variable files and logging — plus the
file's own top-level networks, volumes, secrets and configs. You pick which groups are on screen.

Anything the form cannot take apart appears in an **Advanced** block.

**While you type**

- Key and value suggestions, and hover help on compose keys.
- Flags a value the field does not accept.
- Checks the folders you name exist, and offers to create the ones that do not.
- Flags a placeholder nothing will fill in, and a missing companion file.
- Warns when a port is already taken.
- Runs the file past Docker itself.
- None of it blocks a save.

**Pickers** for a folder on the server, a timezone off a world map, and a device from this server's
hardware.

**Every file in the stack folder is a tab** — the compose file, `.env`, an override file, anything
else. Create, rename, delete, download, or upload several at once. Certificates, keys and other
binary files are allowed; a non-text file gets a download-and-replace panel. Add a settings-shaped
file and StaXX offers to wire it in.

**Sanitise** hides every value marked secret, in the form and the file. The window locks while it
is on.

![The file with secrets hidden](docs/images/sanitise-file.jpg)

---

## Adding an app

The **Apps** button searches Community Applications (about 3,700 apps), Docker Hub, and the images
already on your server.

![The Apps browser](docs/images/apps-home.jpg)

![Searching](docs/images/apps-search.jpg)

Pick one and it becomes an ordinary compose file, opened in the editor before anything is saved or
started.

![An app's details](docs/images/apps-detail.jpg)

Each setting's description becomes the comment beside it. Required and masked settings get their
marks. Logo, category, project page and support thread become metadata.

Ports, folders, variables, devices, labels, extra arguments, privileged mode and the web address all
convert, along with about thirty of the loose command-line flags an app can carry. **Any flag that
does not convert is named in a warning above the form**, and so is any value StaXX had to guess.

The catalogue is downloaded once, on first use, and kept off your flash drive.

StaXX can also catch an app installed from Unraid's *own* Apps page and make it a stack. Three
settings: bring them in, ask first, or leave them to Unraid.

---

## Importing what you already run

![The import window](docs/images/import.jpg)

StaXX lists what is on the server, sorted into what it can bring in and what it cannot: apps from an
Unraid template, Compose Manager projects, containers with nothing behind them, and things already
imported. Each row says where it came from and where it will land — one folder for everything, or
**"Match my Docker folders"**, using the folders from FolderView.

**Importing only copies** — nothing at the source changes. What arrives is locked until you have
reviewed it.

---

## Updates

**Checking.** StaXX asks each image's registry whether a newer version of the same tag exists —
nothing is downloaded. It tells an ordinary update from a locally built image whose base moved, a
withdrawn tag, and an app that changed registry.

**Watching the author.** Where an image points at a public project, StaXX compares your file with
the example that project publishes and reports settings the author has added or dropped. Findings can
be waved away.

**Applying.** Three modes: show it, wait for you to press Update, or install itself after a delay you
set. A stack or a single container can overrule the setting in its own file. There is a countdown you
can pause, a quiet time of day, a global pause, a queue, "skip this version", and release notes where
the publisher provides them.

**Rolling back.** Up to five previous versions of each image are kept. Old images are cleaned up
when nothing needs them.

Checks run daily or weekly at a time you pick, and Unraid's notifications report what was found.

![Update settings](docs/images/settings-updates.jpg)

Signing in to Docker Hub with a read-only token raises the hourly check limit from roughly ten images
to roughly a hundred.

---

## Running containers

Start, stop, restart, update and remove work on a whole stack or one container. Long commands run
detached and stream their output.

**Restart applies your edits** — it rebuilds whatever no longer matches the file and restarts the
rest.

The **Manage** tab gives you, per container: a live log you can search and download, a root command
line inside the container, and a file browser inside it that can read, edit, rename and delete.

![The Manage tab](docs/images/manage.jpg)

The command line is off until you turn it on.

---

## Autostart

StaXX writes to Unraid's own boot list and reads it back, so a change made on Unraid's Docker page
is picked up rather than overwritten.

Folders, stacks and containers are dragged into position, and that order is the order they start in.
Any entry can be followed by a pause in seconds. A row warns you when a stack's containers have ended
up scattered through the list.

A stack has to be started by hand once before it can start at boot.

---

## Removing a stack

Nothing is deleted. The containers are removed, then the whole folder is zipped into an archive
folder outside the stacks tree and the folder taken away.

The confirmation names where the zip will go, and the settings panel lists what has been archived.
Getting a stack back is unzipping it by hand.

---

## Settings

![Settings](docs/images/settings.jpg)

- **Where StaXX lives** — a tab under Docker, or its own button in the top navigation bar. It can
  also take the Docker menu over entirely; turning that off puts everything back.
- **Where stacks live**, and where removed ones are archived.
- **What may leave the server** — one switch each for container logos, image documentation and the
  container command line.
- **Everything about updates.**

---

## What it promises

1. **Never require non-standard syntax.** A file StaXX writes runs unmodified anywhere Docker Compose
   does.
2. **Never lose what the author wrote.** Comments, shortcuts, values and intent survive an edit.
   A file that is genuinely wrong gets fixed, and you are told what changed.
3. **Degrade gracefully.** A file with no extras still produces a usable form.
4. **Own the render.** Layout and controls are drawn by StaXX, not injected into another page.

---

## Roadmap

**None of this exists yet.** Everything above this line works today; nothing below it does.

- A multi-container app installed, configured and updated as one application.
- Showing how the containers in a stack are connected, and warning when an edit breaks that.
- Marking a stack whose containers were built from an older version of its file, and showing what
  will change before a restart.
- Choosing which parts of a stack start.
- Explaining a file you did not write — resolved values, Docker's warnings in plain English, and what
  a stack is asking for.
- Generating a secret, and moving it out of the file.
- Marking a folder as real data, so destructive actions ask harder.
- Advisory notices for a log with no size limit, and a container waiting on one with no health check.
- Clash warnings that can see the machine's own ports.
- Acting on several stacks at once.
- Exporting a stack as a shareable recipe with your own details stripped.
- Graphics figures for Nvidia cards.
- Stacks off the flash drive, and an edit history you can undo.

---

## Installing

**Requires Unraid 7.2 or later.** StaXX does not install Docker Compose; if it is missing, the page
points you at the Docker Compose Manager plugin.

There is no packaged install yet. `dev-install.sh` copies the files into place. Put it and the plugin
folder on the flash drive and run it there:

```
/boot/staxx-dev/
    dev-install.sh
    staxx/          <- src/staxx/usr/local/emhttp/plugins/staxx/
```

```sh
bash /boot/staxx-dev/dev-install.sh            # install or update
bash /boot/staxx-dev/dev-install.sh --remove   # remove, keep settings
bash /boot/staxx-dev/dev-install.sh --purge    # remove settings too
```

Nothing installed this way survives a reboot. Settings do — they live on the flash drive.

---

## For contributors

- **One endpoint, POST only.** Every action is named in one list.
- **No client-side libraries.** Plain browser JavaScript, and every style class prefixed.
- **Nothing may hang.** Every external command is time-limited.
- **Tests.** The compose reader and writer, the catalogue converter, the image importer and the
  metadata schema run on a desktop; the server-side pieces have their own suites.

```sh
python tests/validate_schema.py     # metadata schema
node tests/yaml_roundtrip.js        # the compose reader and writer
node tests/ca_convert.js            # Community Applications template -> compose
node tests/image_import.js          # image -> starting compose file
node tests/js_undeclared.js         # names assigned but declared nowhere
```

```
CHANGELOG.md                What changed, and the current version
docs/README.md              Plain-English overview
docs/glossary.md            Every term used in this project, defined
docs/feasibility.md         Whether this is possible, and the evidence
docs/x-unraid-schema.md     Format of the extras that make the form friendly
schema/                     Machine-readable definition of that format
examples/                   Worked compose files carrying that metadata
tests/                      Suites, plus a corpus of awkward files
src/staxx/                  Mirrors the install paths on the server
completed-plans/            Every piece of work that has landed
```

---

## Direction

Ship as a community plugin first. Once that is proven, propose it to
[unraid/webgui](https://github.com/unraid/webgui) as the built-in Docker experience (Bold move, I know!). 

## Licence

GPL-2.0, matching `unraid/webgui`.

## Prior art

Studied while scoping this. None of their code is used here.

- [Compose Manager Plus](https://github.com/mstrhakr/compose_plugin) — compose engine, header-menu
  toggle
- [FolderView3](https://github.com/chodeus/folder.view3) — collapsible grouping
- [unraid-plugin-composerize](https://github.com/llalon/unraid-plugin-composerize) — template →
  compose conversion
