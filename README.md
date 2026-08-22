# StaXX — Docker on Unraid, built on compose files

> **Pre-alpha.** Everything shown below works on a real server today, but StaXX is not packaged or
> listed yet. Installing it means copying the files onto a server yourself — see
> [Installing](#installing). Nothing here is a mock-up: every screenshot is a live server.

Unraid describes containers with its own template format. Templates exist nowhere else, work nowhere
else, and are written by volunteers — so if nobody has written one for the app you want, you build it
by hand, field by field.

StaXX uses **[compose files](https://compose-spec.io/)** instead: the format every containerised
project in the world already publishes. Paste one in from a project's documentation and it runs. And
if you have never read a compose file in your life, StaXX reads it for you and draws it as a form —
the same kind of form you fill in today, except the file underneath stays a normal compose file that
would run on anybody else's machine.

![The stacks page](docs/images/stacks-overview.jpg)

---

## Contents

- [Why](#why) · [The stacks page](#the-stacks-page) · [The editor](#the-editor)
- [Installing something new](#installing-something-new) · [Bringing in what you already run](#bringing-in-what-you-already-run)
- [Keeping images up to date](#keeping-images-up-to-date) · [Running containers](#running-containers)
- [Starting with the server](#starting-with-the-server) · [Removing a stack](#removing-a-stack) · [Settings](#settings)
- [What it promises](#what-it-promises) · [Roadmap](#roadmap) · [Installing](#installing) · [For contributors](#for-contributors)

---

## Why

A template is a list of an app's settings with friendly labels, which Unraid turns into a form. When
one exists, it works nicely. The trouble is everything around that:

- Around two thousand templates exist, all needing upkeep, all duplicating information the app's own
  developers already published in a different format.
- An app's own documentation never gives you a template. It gives you a compose file.
- No template, no form. You are on your own.

Compose files solve that by already existing. What they do not carry is the friendly part — a compose
file says a container uses port 8096, not "this is the web interface" or "this one is a password,
hide it as you type".

StaXX puts that missing information in two places, both of them ordinary parts of a compose file.
**What a setting is for** goes in the comment beside it, which is where a person would write it
anyway; the form shows it as help text, and typing help text into the form writes the comment back.
Two short marks say the only two things a comment cannot: one for "this is a secret", one for "this
must not be left empty". **What the app is** — its logo, a description, the address of its web page —
goes in a section named `x-unraid`, and the compose standard sets aside `x-` sections for exactly
this: extra information every other tool ignores.

None of it is required. A file with no notes and no `x-unraid` section still produces a working form,
just a plainer one.

---

## The stacks page

A **stack** is a folder with a compose file in it. That is the whole model — no database, no index,
no hidden metadata. Drop a compose file in a folder and it is a stack; delete the folder and it is
gone.

Every stack is a row. Expand it and each container inside gets its own row.

![A folder opened up, with the stacks inside it](docs/images/stacks-grid.jpg)

**On each row**

- **Live figures** — processor, memory, network and graphics use, each with a small graph of the last
  few minutes. Graphics use is read per container out of the kernel's own accounting for Intel and
  AMD cards, not divided up from a whole-card figure and hoped for. **Nvidia cards show no figures
  yet** — Nvidia's driver does not keep that per-program tally, so the same trick cannot work. A
  container given an Nvidia card is recognised and labelled as such; it just has no numbers beside it.
- **State**, container by container, including how long it has been up and what its own health check
  says about it.
- **Four buttons, always in the same place**: the app's web page, its logs, its project page and its
  Unraid support thread. A button with nowhere to go is greyed and says why, rather than vanishing
  and shuffling everything else along.
- **Its address**, with Unraid's own address-and-port substitution honoured.
- **Badges that tell you something is off** — an update waiting, a tag the publisher withdrew, an
  image that moved to a different registry, a copy that has drifted from what it was copied from, a
  running container built from a different image than the file now names.

![Badges on the rows](docs/images/row-states.jpg)

**Grouping.** Stacks can sit one level deep in folders you create. A folder is a directory, so making
one is making a folder and moving a stack is moving its folder — there is nothing to get out of step.
A folder row totals up what is inside it and can start, stop, check or update the lot.

**Everything a row can do** is on one menu, reached by right-clicking it.

![The row menu](docs/images/stack-row-menu.jpg)

**It does not poll.** The page listens to Docker's own stream of events, so a container that stops
outside StaXX shows as stopped straight away, and a page nobody is looking at costs nothing. The
statistics sampler is the same: it starts when a page asks for figures and stops by itself about
forty-five seconds after the last one goes away.

---

## The editor

Opening a stack gives you three ways to look at the same file: the **form**, the **file**, or both
side by side with the highlight following you between them.

![Form and file side by side](docs/images/editor-split.jpg)

The form is drawn from the file. More than twenty groups of settings are covered — the image and its
web page port, networks, ports, folders, variables, devices, labels, health checks, resource limits,
build instructions, start-up order between containers, secrets, configuration files, profiles, name
servers, permissions granted and dropped, internal ports, variable files and logging — plus the
file's own top-level declarations of networks, volumes, secrets and configs. You choose which groups
are on screen, and that choice is remembered in the file itself.

Anything the form cannot confidently take apart is shown in an **Advanced** block rather than hidden.
Nothing in your file is ever invisible.

**Things it does while you type**

- Suggests keys and values, and explains what a compose key means when you hover it.
- Says when a value is not one the field accepts — and, for the two or three it cannot judge, says so
  plainly instead of guessing.
- Checks that the folders you name actually exist on the server, and offers to create the ones that
  do not.
- Points out a placeholder that nothing will ever fill in, and a companion file you referred to but
  never created.
- Warns when a port is already taken by something else already running.
- Asks Docker itself, as a courtesy, whether the file makes sense — which catches a plain misspelling
  like `restart: alwyas`.
- None of it blocks a save. It tells you; you decide.

**Buttons where typing is the wrong tool.** Pick a folder from the server, pick a timezone off a
world map, pick a device from the hardware this server actually has.

**Everything beside the compose file is editable too** — notes, certificates, settings files — each
one a tab. Add a settings-shaped file and StaXX offers to wire it in for you.

**Asking for help without handing over your passwords.** One switch hides every value marked as a
secret, in the form *and* in the file, so you can screenshot your own stack for a forum thread. While
it is on the whole window is locked, because a masked value must never be saved back over a real one.

![The file with secrets hidden](docs/images/sanitise-file.jpg)

The picture above this section was taken that way — the database name and user are still readable,
because they are not secrets. Only what is marked gets hidden, and the mark is the same one the form
uses to decide whether to hide a field as you type it.

---

## Installing something new

The **Apps** button searches the Unraid Community Applications catalogue — about 3,700 apps — along
with Docker Hub and the images already on your server.

![The Apps browser](docs/images/apps-home.jpg)

![Searching](docs/images/apps-search.jpg)

Pick one and StaXX turns it into an ordinary compose file, opened in the editor for you to look over
before anything is saved or started.

![An app's details](docs/images/apps-detail.jpg)

The point is not the image name — that part is easy. It is everything around it. Each setting's
description becomes the comment beside that setting, so the form has real help text. "Required" and
"masked" become the two marks. The logo, category, project page and support thread become metadata.
An imported app arrives as a proper form rather than a wall of YAML, which is the whole reason to
import rather than retype.

**What converts, and what does not.** Ports, folders, variables, devices, labels, extra arguments,
privileged mode and the web page address all convert. Around thirty of the loose command-line flags
an app can carry have a clean equivalent and are translated. **Every other flag is named in a warning
above the form** rather than dropped quietly — a mistranslated health check or memory limit would be
worse than an honest "this was not applied". Values are guessed only where the catalogue left one
empty, and every guess is warned about too.

**What it costs.** The catalogue is one 24 MB file with no search interface — the file is the whole
interface, and Unraid's own Apps plugin downloads exactly the same thing. StaXX fetches it once, the
first time you press the button, and keeps it in temporary storage, so **nothing is written to your
flash drive**. Checking for a newer catalogue costs 37 bytes.

There is also a second door: StaXX can catch an app installed through Unraid's *own* Apps page and
make it a stack instead. That is a setting with three positions — bring them in, ask first, or leave
them to Unraid — and StaXX leaves an Unraid template behind for each one, so removing StaXX later
does not orphan your apps.

---

## Bringing in what you already run

You do not have to start from nothing.

![The import window](docs/images/import.jpg)

StaXX looks at what is on the server and sorts it into what it can bring in and what it cannot: apps
set up from an Unraid template, projects belonging to the Compose Manager plugin, containers with
nothing behind them (listed so you can see them, but there is nothing to convert), and things already
imported. Each row says where it came from and where it will land — one folder for everything, or
**"Match my Docker folders"**, which puts each one where Unraid already had it.

**Ticking one only ever copies.** Nothing at the source is deleted or changed. What StaXX writes
arrives **held still** — marked as needing a look, with every start and stop button refused, so
nothing runs and nothing of yours is touched until you have read it and said so.

Taking it over afterwards means different things depending on where it came from, because the two
genuinely differ:

- **From a template**, the running container is stopped and set aside under another name, and the new
  stack starts in its place. Nothing is deleted, and if starting the new one fails the old one is put
  straight back.
- **From a Compose Manager project**, the new stack takes exactly the name Docker already knows those
  containers by, so it rebuilds the ones you are already running rather than making a second copy.
  Going back means starting it from Compose Manager again.

One honest limit: a copy can drift out of step with the thing it was copied from, if that original is
later edited. The row says so when it happens, worked out by comparing the two files there and then
rather than by keeping a record that could itself go stale.

---

## Keeping images up to date

**Finding out.** StaXX asks each image's registry whether a newer version of the same tag exists.
Nothing is downloaded and nothing is restarted by asking. It can tell an ordinary update from an
image you built yourself whose base has moved, from a tag the publisher withdrew, from an app that
moved to a different registry.

**Watching what the author publishes.** For an app whose image points at a public project, StaXX can
find the compose file that project holds up as its *own* example and compare it with yours — through
the same reader the form uses, so it compares meaning rather than text. It reports only settings the
author has **added or dropped**, never a value you have simply chosen differently. It never changes
anything; it points, and each finding can be waved away.

**Deciding.** Three positions: just show it, wait for you to press Update, or let it install itself
once a delay you choose has passed. A stack, or one container inside it, can overrule the setting in
its own file. There is a visible countdown you can pause, a quiet time of day updates are allowed to
install in, a global pause switch, a queue with its own progress, "skip this version", and release
notes where the publisher provides them.

**Undoing.** StaXX can keep up to five previous versions of each image on disk, so a bad update is
one menu item away from being put back. Old images are cleaned up only when nothing is running them
and no roll-back still needs them.

Checks are scheduled daily or weekly at a time you pick, and Unraid's own notification system tells
you what was found — one message per check, never one per container.

![Update settings](docs/images/settings-updates.jpg)

Optionally, signing in to Docker Hub with a read-only token raises how many images can be checked per
hour from roughly ten to roughly a hundred. The page tells you when that limit is what stopped it.

---

## Running containers

Start, stop, restart, update and remove work on a whole stack or on one container inside it. Long
commands run detached, so closing the tab does not kill them, and their output is shown as it
happens.

**Restart means what Apply means on an Unraid template.** A container's settings are fixed when it is
built, so restarting one that already exists could never apply an edit you just made. StaXX rebuilds
whatever no longer matches the file and restarts the rest, leaving the others — and their logs —
alone.

The **Manage** tab gives you, per container: a live log you can search and download, a root command
line inside the running container, and a file browser inside it that can read, edit, rename and
delete.

![The Manage tab](docs/images/manage.jpg)

The command line is off unless you turn it on, and when it is off the server refuses it rather than
merely hiding the tab. The first time you use it, it warns you that changes made this way vanish the
next time the container is rebuilt.

---

## Starting with the server

Unraid already keeps a list of which containers start at boot, in what order, with an optional pause
after any of them. StaXX does not build a second one — it writes into that same list and reads it
back, so the two can never disagree, and a change made on Unraid's own Docker page is picked up
rather than overwritten.

What that buys is an order you can see. Folders, the stacks in them and the containers in a stack are
all dragged into position, and the order you are looking at is the order things start in. A database
can be made to come up before the thing that needs it, with a pause in between if it needs a moment.
A row warns you when a stack's containers have ended up scattered through the list.

One thing worth knowing: Unraid *starts* a container at boot, it does not build one. So a stack has
to have been started once by hand before it can start on its own. After that it is automatic.

---

## Removing a stack

Removing a stack does not delete anything. It stops the containers and takes them away, then zips the
**whole folder** — the compose file, notes, certificates, anything else living beside it — into an
archive folder outside the stacks tree, and only then takes the folder away.

The confirmation names exactly where the zip will go before you agree, and the settings panel lists
what has been archived. Getting a stack back is unzipping its archive by hand; there is no undo
button for that yet, and StaXX says so rather than implying otherwise.

---

## Settings

Everything StaXX can be told is in one panel, each setting with a paragraph beside it explaining what
it does and what it costs.

![Settings](docs/images/settings.jpg)

- **Where it lives** — a tab under Docker, or its own button in the top navigation bar. Optionally it
  can take the Docker menu over entirely; turning that back off puts everything back, and **no
  Unraid file is ever modified either way**.
- **Where stacks live**, and where removed ones are archived to.
- **What is allowed to leave the server**, one switch each for looking up container logos, reading an
  image's documentation, and the container command line. Each says exactly what gets sent.
- **Everything about updates**, as described above.

---

## What it promises

These are the constraints the project is built around, in priority order.

1. **Never require non-standard syntax.** A file authored by StaXX runs unmodified on any machine
   with Docker Compose. The friendly extras live in comments and in an `x-unraid` section the compose
   standard already sets aside for exactly this and every other tool ignores.
2. **Never lose what the author wrote.** Editing through the form keeps their comments, their
   shortcuts, their values and their intent. What is protected is the *meaning* of the file, not the
   order of its bytes — the same configuration written in a different order is the same stack. Losing
   something is the bug, and so is changing a file without saying so. A file that is genuinely wrong
   gets fixed rather than refused, and you are told what changed and can put it back.
3. **Degrade gracefully.** A compose file with no extras of either kind still produces a usable form,
   with names and control types worked out from the file itself.
4. **Own the render, do not inject into someone else's.** Grouping, controls and layout are drawn by
   StaXX rather than surgically inserted into somebody else's page.

The second one is not a slogan. There is a body of test files in this repository, each built to
exercise one awkward thing — comments in odd places, shortcuts and references, strange indentation,
duplicate names, deliberately broken files — which are parsed, edited and written back on every
change, so the promise is reproducible rather than claimed.

---

## Roadmap

**None of this exists yet.** It is written down, argued through, and waiting its turn. Everything
above this line works today; nothing below it does.

- **A whole application, not eight containers.** Install a multi-container app wired up in one step,
  set the handful of settings that matter across all of it on one screen, and update it as one thing.
- **Parts that know about each other.** Show how the containers in a stack are connected — a shared
  password, a shared folder, one naming another — and warn when an edit is about to break that.
- **What is running versus what the file says.** Mark a stack whose containers were built from an
  older version of its file, and show what will change before you restart it.
- **Choosing which parts of a stack start**, for files that offer optional pieces.
- **Explaining a file you did not write.** Show what a variable actually resolved to and where the
  value came from, translate Docker's own warnings into plain English, and summarise what a stack is
  asking for — the whole disk, the host's network, control of Docker itself.
- **Making a secret, and keeping it out of the file.** Generate a strong password on a field already
  marked secret, and where an image supports it, move that secret into a file of its own.
- **Marking a folder as real data**, so anything destructive names it and asks harder.
- **Noticing what you have not set** — a log with no size limit that will eventually fill a disk, a
  container waiting on another that has no health check to wait for.
- **Clash warnings that can see the machine itself**, so a reverse proxy wanting port 80 is told what
  already holds it.
- **Graphics figures for Nvidia cards**, by asking Nvidia's own tool and matching what it reports
  back to the containers it belongs to — the roundabout route, since the direct one is closed.
- **Acting on several stacks at once**, with each one's progress shown separately.
- **Giving somebody your stack** — export it as a recipe with your passwords blanked and your own
  paths generalised, and a screen showing exactly what was taken out.
- **Stacks off the flash drive**, onto a pool with room, and an edit history you can undo after the
  page has been closed and reopened.

---

## Installing

**Requires Unraid 7.2 or later** — that is the release with the responsive WebGUI. Earlier versions
are out of scope.

**StaXX does not install Docker Compose itself.** If compose is missing, the page says so and points
you at the Docker Compose Manager plugin in Community Applications.

There is no packaged install yet. For testing, `dev-install.sh` copies the files into place so a
change shows up on a browser refresh. Put it and the plugin folder on the flash drive and run it
there:

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

Nothing installed this way survives a reboot, because that part of the filesystem is rebuilt at boot
— which makes a reboot the panic button if something goes wrong. Settings do survive; they live on
the flash drive.

---

## For contributors

A few things worth knowing before touching the code.

**One endpoint, POST only.** The page talks to a single place, which answers JSON always and buffers
its output so a stray warning lands inside the reply rather than corrupting it. Every action is named
in one list; there is no path from what a user types to a command that is not on it. It is POST only
because Unraid's own cross-site protection covers POST, and accepting anything from the address bar
would hand somebody a way around it.

**No client-side libraries.** The browser code is plain JavaScript. Every style class is prefixed, so
no stock Unraid rule is borrowed for layout — those rules are invisible to us and change between
releases.

**Nothing may hang.** Every external command is time-limited. A page that waits forever on Docker is
worse than one that fails visibly.

**Tests.** The compose reader and writer, the catalogue converter, the image importer and the
metadata schema all have suites that run on a normal desktop; the server-side pieces have twenty-odd
more that run on the server, each pointed at temporary paths so nothing real is touched.

```sh
python tests/validate_schema.py     # metadata schema — what it must reject, not just accept
node tests/yaml_roundtrip.js        # the compose reader and writer
node tests/ca_convert.js            # Community Applications template -> compose
node tests/image_import.js          # image -> starting compose file
node tests/js_undeclared.js         # names assigned but declared nowhere
```

```
docs/README.md              Plain-English overview
docs/glossary.md            Every term used in this project, defined
docs/feasibility.md         Whether this is possible, and the evidence
docs/x-unraid-schema.md     Format of the extra information that makes the form friendly
schema/                     Machine-readable definition of that format
examples/                   Worked compose files carrying that metadata
tests/                      Suites, plus the corpus of awkward files
src/staxx/                  Mirrors the install paths on the server
completed-plans/            Every piece of work that has landed, and why
```

---

## Direction

**Plugin first, proposal later.** This ships as a community plugin and earns adoption on its own
merits. Only once that is demonstrated does proposing it to
[unraid/webgui](https://github.com/unraid/webgui) as the built-in Docker experience make sense.

That order matters technically too: a few mechanisms that suit a plugin living alongside stock Unraid
are scaffolding, and would not belong in an upstream contribution.

## Licence

GPL-2.0, matching `unraid/webgui`, which keeps an eventual upstream contribution clean.

## Prior art

Studied while scoping this. None of their code is used here; the patterns informed the design.

- [Compose Manager Plus](https://github.com/mstrhakr/compose_plugin) — compose engine, header-menu
  toggle, per-release patching of the stock Docker client
- [FolderView3](https://github.com/chodeus/folder.view3) — collapsible grouping, done by injecting
  into the stock container table
- [unraid-plugin-composerize](https://github.com/llalon/unraid-plugin-composerize) — template →
  compose conversion
