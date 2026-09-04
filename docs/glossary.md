# Glossary

Plain definitions of the terms that keep coming up in this project. Each one says why it matters
here, not just what it means in general.

---

## The Docker side

**Image** — a packaged, ready-to-run copy of an application, downloaded from the internet. Think of
it as the installer. `jellyfin/jellyfin:10.10.3` is an image.

**Container** — a running copy of an image, with your settings applied. The image is the installer;
the container is the installed, running program. One image can run as many containers. A
container's name is whatever `container_name:` says in the compose file, or one Docker makes up if
that key is absent — an ordinary compose field, not something StaXX renames.

**Compose file** — a text file listing the containers you want and how they should be set up. Its
filename is usually `compose.yaml`. It is the standard way to describe containers, understood by
Docker on any machine — Windows, Mac, Linux, a server, a laptop.

*Why it matters here:* this is the whole premise of the project. Instead of Unraid's own private
format, we use the one everybody else already uses.

**Service** — one entry inside a compose file, describing one container. A compose file for a photo
app might have two services: the app itself and the database it needs. Its name is the key you
write above it in the file, e.g. `jellyfin`; there is no separate display name.

**Stack** — a directory holding a compose file, and nothing else. Starting a stack starts every
container the file describes. A stack's name is its directory name, full stop — there is no
override.

*Why it matters here:* stacks are how the interface groups containers, so related containers stay
together instead of scattered through one long list.

**Folder** — an optional directory one level above a stack, grouping several stacks together, e.g.
`Media/` holding `Media/jellyfin/`. Created and renamed from the UI; a stack not placed in one sits
at the top level.

**Project name** — the label Docker stamps on every container it creates from a compose file
(`com.docker.compose.project`), used to prefix container names and to match containers back to
their stack. Worked out from the stack's directory name, lowercased with anything outside
`a-z0-9_-` stripped out — not something you set directly.

**Label** — a small note attached to a container, in the form `name = value`. Docker itself uses
labels to keep track of things.

*Why it matters here:* Docker automatically stamps every container it creates from a compose file
with a project-name label, which is how a running stack is matched back to its directory.

**Docker socket** — the connection a program uses to talk to Docker and ask it to do things. Not
something users see.

---

## The file format

**YAML** — the style of text formatting compose files are written in. It uses indentation to show
what belongs inside what, a bit like a nested bullet list. Pronounced "yamel".

**Parser** — a program that reads a file and turns it into something a program can work with. When
you read a shopping list and picture the items, you are parsing it.

**Round-trip** — reading a file in and then writing it back out again. It sounds harmless, but most
parsers quietly lose things on the way — comments especially.

*Why it matters here:* the form has to save your changes back into your compose file **without
wrecking it**. If someone hand-wrote that file with comments and careful spacing, a careless
round-trip destroys their work. This is the single hardest part of the project.

**CST** — short for *concrete syntax tree*. A parser that remembers the file exactly as written,
including comments, blank lines and spacing, rather than just the information in it.

*Why it matters here:* it is the kind of parser that survives a round-trip, so it is the kind we
have to use.

**Extension field / `x-`** — a section in a compose file whose name starts with `x-`. The compose
standard promises that Docker will ignore anything named this way. It exists so tools can add their
own information without breaking the file.

*Why it matters here:* `x-unraid` is where we keep what a stack and its containers *are* — the name,
the logo, the paragraph describing it. Docker ignores it, so the file still runs anywhere. What each
individual setting is for is not kept there; see **Note** and **Marker** below.

**Schema** — a written-down definition of what a file is allowed to contain. A form's "required
fields" rules are a schema.

**Validation** — checking a file against a schema and reporting anything wrong.

*Why it matters here:* `schema/x-unraid.schema.json` defines our extra section, and
`tests/validate_schema.py` checks it. That means a bad file gets a clear error instead of a
half-broken form.

**Note** — the ordinary `#` comment beside a setting, which is also the help text the form shows for
it. There is only ever one copy of that sentence, and it is on the line it describes.

**Marker** — a short mark at the end of a note saying something a sentence cannot: `-!S` for "this is
a secret, hide it when Sanitise is on", `-!R` for "this must not be left empty". Those are the only
two, and nothing is ever guessed — an unmarked value is not treated as a secret however it is spelled.

**Binding** — connecting a box on the form to the specific thing in the compose file it edits. It is
always done by the half of a setting that does not change: the container port `8096` rather than the
whole line `"8096:8096"`, so changing the host port does not lose track of the box.

**`.staxx`** — the file Export produces when a stack is more than one file. It is an ordinary zip
underneath — rename it to `.zip` and it opens like any other — with the extension marking it as a
StaXX bundle rather than an arbitrary zip.

*Why it matters here:* it is a round trip, not just a way of handing a stack over. Drop one onto
the stack list and StaXX opens it, shows what it holds and where it will land, and imports it.

---

## The Unraid side

**WebGUI** — Unraid's web interface, the thing you see in your browser.

**Bridge network** — the ordinary way a container is connected: it sits behind your server, and you
reach an app inside it at the server's own address on whichever outer port you chose. `br0`-style
networks are the exception, below.

**macvlan / ipvlan network** — a network that gives a container an **address of its own** on your
home network, as though it were a separate machine plugged into your switch. On Unraid these are the
`br0` networks. The two names are two ways of doing the same thing, and nothing here treats them
differently.

*Why it matters here:* a container with its own address is reached directly on that address, so
Docker refuses to publish a port for it — and a compose file that asks it to will not start at all.
StaXX keeps such ports as a note in the file rather than as a live setting — the guide's
"Editing a stack" page shows what that looks like.

**Plugin** — an add-on that extends Unraid. It can add pages, buttons and background tasks. This
project is a plugin.

**`.plg` file** — a plugin's installer. A small file listing where to download the plugin and what
to do after. Unraid reads it when you install or update.

**Page file** (`.page`) — one screen in the Unraid interface. Unraid finds these automatically by
scanning its plugin folders, so adding a page is a matter of putting a file in the right place.

*Why it matters here:* this is how we add our screen to the Docker tab, and how a full takeover of
that tab would work later.

**Template** — Unraid's own format for describing a container, written in XML (a different, older
text format). Every container installed through Unraid's interface has one.

*Why it matters here:* templates are what we are replacing. They only work on Unraid, and if none
exists for the app you want, you fill in every field by hand.

**Community Applications (CA)** — Unraid's app store. Around 2000 apps, each with a template,
maintained by volunteers.

*Why it matters here:* it is the main reason people choose Unraid, and it is built entirely on
templates. Any replacement has to answer what happens to those existing apps — StaXX answers it
with a converter, already built, that turns a template into a compose file on demand.

**Array** — the Unraid disks that hold your data, which are unavailable until you start the array.
Relevant because anything stored there cannot be read at boot time.

**Data store** — the one folder, chosen the first time you open StaXX, that holds everything StaXX
manages: your stacks, an archive of anything you have removed, and StaXX's own settings and icon
cache. It normally sits on a drive pool rather than the flash drive.

*Why it matters here:* a drive pool is not available in the first seconds after the server powers
on, and is not reachable at all if something has gone wrong. That is why three settings that have to
work even then — including the data store's own location — are kept separately, on the flash drive,
rather than inside the data store itself.

---

## Bringing containers in

**Import** — turning a container you already run into a stack, by copying its setup into a compose
file. The source is one of three things: an Unraid template, a Compose Manager project, or neither.
Only the first two can be imported; a container matching neither has nothing to convert it from.

*Why it matters here:* it means starting from what you already have rather than from nothing.

**Needs review** — the state an imported stack arrives in. Every start and stop button is refused,
and the row says plainly that it is waiting to be checked. Nothing runs and nothing of yours is
touched until you look it over and clear the mark.

*Why it matters here:* it is what makes bringing a container in safe — the copy exists, but it cannot
do anything until you have said so.

**Taking over** — the one button that clears a "needs review" mark and puts the new stack in charge.
What it actually does depends on where the container came from:

- From a **template**, the running container is stopped and set aside under another name, and the
  new stack starts in its place. Reversible — nothing is deleted, and a failed start puts the
  original back.
- From a **Compose Manager project**, the new stack is given the same name Docker already knows
  those containers by, so taking over rebuilds the containers you already run, in place, rather than
  starting a second copy. There is no going back to a stopped original — going back means starting
  the project from Compose Manager again.

*Why it matters here:* the two sources look the same in the list but taking over behaves
differently, and that difference is the one thing worth knowing before pressing the button.

---

## Terms used about this project

**Prior art** — existing projects that already solve part of the same problem. Studied so we do not
repeat their mistakes or reinvent their solutions. Compose Manager Plus and FolderView3 are the two
that matter here.

**Shadowing** — quietly replacing one of Unraid's own screens with ours, by giving our file the same
name. Unraid loads plugin folders in alphabetical order and the last one wins, so a plugin whose
folder sorts later can take over a screen without modifying any Unraid file.

*Why it matters here:* it is how the optional Docker tab takeover works, and it is why the plugin
folder is named `staxx` — the name has to sort after `dynamix.docker.manager`.

**DOM injection** — reaching into a page that another program built and inserting your own bits into
it. It works, but it breaks every time the other program changes its page.

*Why it matters here:* it is how the existing FolderView plugin groups containers, and the reason it
needs frequent fixes. We avoid it by drawing our own screen instead.

**Upstream** — the original project, as opposed to our copy or add-on. Here it means Unraid itself,
maintained by Lime Technology.

**Pull request (PR)** — a formal proposal to add your changes to someone else's project. The
long-term goal is a pull request offering this to Lime Technology.
