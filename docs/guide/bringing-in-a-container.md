# Bringing in a container you already run

<!-- index: 65 | taking the containers already on your server into StaXX: what can come across, what arrives locked, and what is left untouched. -->

You almost certainly have containers running already — set up through Unraid's own templates, or
through the Compose Manager plugin. **Import** takes those and writes each one out as an ordinary
StaXX stack, without switching anything off, starting anything, or disturbing the original. You
reach it from the **Import** button in the row of buttons at the top right of the stacks page,
between **Apps** and **Add stack**.

Every import arrives **locked**. StaXX will not start it until you have read what it wrote and
said so — that is the whole point. Nothing runs, and no existing container is touched, until you
choose to take it over later.

## Not everything can come across yet

Three kinds of thing are listed, and only two of them can actually be imported. If your containers
are the third kind, this feature has nothing for you yet.

| What it is | Can it be brought in? |
|---|---|
| Something set up through an Unraid template | Yes |
| A project in the Compose Manager plugin | Yes |
| A container someone started by hand, belonging to neither | **No — listed for reference only** |

That third group is shown greyed out, under the heading *"Importing these is not built yet."* It is
there so you can see the whole picture of what is on your server, not because you can act on it.
There is more on this at the bottom of the page.

## The short version

1. Press **Import**. A window opens listing everything on the server, in groups.
2. Tick the templates and projects you want. Expand a template row first if you want to see the
   file it would write.
3. Choose where they land with the **Import into** picker at the bottom.
4. Press **Import**. Each one becomes a new stack marked **needs review** — nothing starts. Open
   each stack, read what StaXX wrote, then use its own menu to take it over.

## What you see in the window

Everything on the server is sorted into five groups. The first three are open when the window
opens; the last two start collapsed, because they are background information rather than something
to act on.

| Group | What is in it |
|---|---|
| **Unraid templates** | Apps installed the Unraid way. Tickable. |
| **Compose Manager projects** | Projects belonging to the Compose Manager plugin. Tickable. |
| **Containers with nothing behind them** | Started by hand, belonging to neither. Reference only. |
| **Already imported** | Things whose name is already taken by a stack in StaXX. |
| **Left over from a removed container** | Leftovers with no container behind them any more. |

Each row shows an icon, the name, where it came from — **Unraid template**, **Compose Manager
project** or **Not managed by StaXX** — and what its container is doing right now: **Running**,
**Stopped** or **No container**. A row whose name is already taken by an existing stack is greyed
out and flagged **Already in StaXX**; pick a different name or remove the existing stack if you
want that one after all.

Expanding a template row shows a preview of the compose file it would write, together with two
lists: *"Could not be translated automatically:"* and *"Filled in for you — check these before
starting:"*. **The preview is not a certificate.** It shows you what would be written and names
what StaXX could not work out — it does not say the result is correct or that the app will run.
The judgement stays with you, which is exactly why every import arrives locked.

### Your passwords come with it

Whatever a template already had filled in — **passwords and API keys included** — is copied
straight into the new file. That is deliberate: a copy that dropped them would not run. But it
does mean the new stack's file holds your secrets in plain text, so treat it the way you would
treat the original.

## Choosing where they land

The **Import into** picker at the bottom offers:

| Choice | What it does |
|---|---|
| **Match my Docker folders** | Each template goes into a StaXX folder named after the Docker folder it is already in, or the top level if it has none. |
| **(top level)** | Everything lands at the top level of your stacks. |
| Any existing StaXX folder | Everything lands in that folder. |

A Compose Manager project always lands at the top level while matching is on, since it has no
Docker folder of its own to match. Its own folder name is fixed rather than chosen — the row tells
you what it will be — because Docker has to keep seeing the same project name.

If you have a Docker folder whose membership is decided by a pattern rather than a plain list,
StaXX says so and puts anything from it at the top level instead. It cannot work out that pattern
without guessing, and a wrong guess would file your stack somewhere you would not think to look.

## What arrives, and the lock on it

Each import becomes an ordinary stack folder with a normal compose file in it — the sort of file
that would run anywhere, with no dependence on StaXX. A Compose Manager project is copied **exactly
as you wrote it**, byte for byte, with no reformatting and no rearranging.

Alongside it, StaXX writes a file called `NEEDS-REVIEW.md`, and writes it *first*, before the
compose file exists. While that file is there:

- The row shows a **needs review** tag next to the name.
- Every **Start**, **Stop** and **Restart** on that stack is refused, with this message:

  > This stack was imported and has not been reviewed yet. Open it, read NEEDS-REVIEW.md, then
  > choose "Take over and start" or "Clear the lock only" before starting it.

The file itself is worth reading — it says where the stack came from, whether a container of that
name already exists and what it is doing, what could not be brought across, and what StaXX filled
in for you.

### Getting out of the lock

Two items appear on the stack's own menu while it is locked:

| Menu item | What it does |
|---|---|
| **Take over and start** | Switches the old container off, sets it aside under another name, starts this stack in its place, then asks you afterwards whether it worked — so there is a way back if it did not. |
| **Clear the lock only** | Removes the lock and nothing else. For when nothing else holds the container's name. |

**Do not delete the file by hand.** It is the obvious move and it is the wrong one: deleting it
lifts the lock but does none of the switching, so the stack then simply fails to start against
whatever still holds its container name. Docker will not let two containers share a name. Use the
menu.

## What this never does

Importing reads. It does not act on anything already running.

- **Nothing is started, stopped, or deleted.** A running container is still running, untouched,
  after an import — including the very container the new stack describes.
- **The original Unraid template stays exactly where it is.** It is not moved, edited or removed.
- **A Compose Manager project stays in Compose Manager.** It is still listed there and still works
  from there. Its file is copied, never taken. (Because both places can then act on the same
  containers, pick one to use going forward — the review file says the same.)
- **Nothing overwrites an existing stack.** An import refuses outright if there is already anything
  where it would write, and if any step fails after that, the half-made folder is cleared away
  rather than left behind.

## Refusals you may run into

Any of these appear against the row, before or during the import, rather than leaving you guessing:

| What it says | Why |
|---|---|
| *A stack called "…" already exists. Pick a different name, or delete the existing stack first.* | The name is taken. Nothing is ever written over an existing stack. |
| *No compose file could be found for this project.* | StaXX could not find the file the project actually runs from, so there is nothing to copy. |
| *This project's compose file holds no services — importing it would create an empty stack.* | The file describes no containers, so the result would do nothing. |
| *Docker is currently running this project as "…", not "…" — importing it under this name would not line up with those containers.* | Docker knows the running containers by a different project name, so the new stack would not control them. It can still be imported; you just need to know it will not adopt what is running. |
| *This project has an override file, which will be copied and used.* | A second file adds to the first. Both come across, and StaXX renames the second one if it must, so Docker actually pairs the two. |
| *This project's folder also holds: … — these will not be copied across.* | Only the compose file, its settings file and its override come over. Anything else in that folder stays put. |
| *Another ticked row already writes to the same place.* | Two of the rows you ticked would land in the same folder under the same name. Untick one, or send them to different folders. |

## What is not built yet

**Containers started by hand cannot be imported.** If you created a container directly with Docker,
or it belongs to no Unraid template and no Compose Manager project, StaXX lists it under
**Containers with nothing behind them** and greys the row out. It is telling you it knows the
container exists, and that it cannot yet write a stack for it.

That is a real gap, and worth being clear about: if most of your containers were made that way,
Import will look almost empty to you. The route across for now is to build the stack yourself with
**Add stack**, copying the settings from the container by hand, and then use **Take over and
start** on it — that item is offered on any stack whose container name something outside StaXX is
already holding, not only on an imported one.

There is also no way to bring several containers in as one combined stack. Each row you tick
becomes its own separate stack.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
