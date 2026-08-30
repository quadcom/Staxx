# Making a stack from scratch

<!-- index: 55 | starting a stack with nothing but a name: the skeleton you are given, the settings offered as comments, every refusal and why, and the single folder that comes out of it. -->

Sometimes there is no catalogue entry for what you want to run — you have an image in mind, or a
compose file already written somewhere else, and you just need somewhere to put it. **Add stack**,
the blue button at the top right of the stacks list, is that starting point. There is no
intermediate window: pressing it opens the ordinary stack editor straight away, titled **New
stack**, with a working skeleton already in it.

If you have never made one, the empty list says so itself: *No stacks yet. Use "Add stack" to paste
a compose file in, or drop one into the folder above.* Both routes end up in the same place — a
folder with a compose file in it.

## The short version

1. Press **Add stack**. The editor opens on a small working file.
2. Type a name in the **Stack name** box at the top. That is the only thing you are asked for.
3. Change the file — rename the service, put your own image in, paste your own compose file over
   the whole thing.
4. Press **Save**, or **Save and start** if you want it running immediately.

## The one box you have to fill in

The box is labelled **Stack name**, suggests `jellyfin` as an example, and carries the note *"The
folder that holds this stack's compose file. Renaming it moves the folder."* That is literally
what it is — the name is the folder, not a label kept in a list somewhere, so changing it later
moves the folder on disk.

**There is no folder picker on this screen.** A new stack always lands at the top level of your
stacks. If you want it filed inside one of your folders, that is a separate step afterwards: **Move
to folder** on the stack's own menu. And **there are no templates or starter gallery here** — this
screen always gives you the same skeleton. Ready-made apps come from the **Apps** button beside it
instead; see [adding an app from Community Applications](installing-an-app.md).

## What you start with

The editor is not blank. It holds one service called `my-app`, running `alpine:3.20`, set to
restart unless you stop it, with a comment above it telling you to rename it to whatever you are
actually running. It is a real, valid compose file — it would run as it stands — but it is there to
be replaced, not kept.

Above that sits a block of **settings written as comments**. These are the things that make a stack
look right on the list and on its own page: its logo, what it is for, where its home page is. They
are commented out, so the file runs exactly as-is and nothing is required of you; uncomment a line
and type a value when you want one.

![The whole starting file: a block of commented-out settings for the logo, description, category and links at the top, and below it a single service called my-app running the alpine image, set to restart unless you stop it](../images/guide/making-a-stack-scaffold.png)

| Offered for the stack as a whole | What it is |
|---|---|
| Icon | The logo shown on the list — a known app name, an image file beside the compose file, a web address, or an icon name. |
| Description | A sentence saying what this stack is for. |
| Category | Which section of Unraid's own app categories it belongs to. |
| Project page | The app's own home page. |
| Support page | Its forum thread or issue tracker. |
| Documentation page | Where its instructions live. |
| Author | Who made it. |
| Update policy | Whether StaXX leaves updates alone, tells you about them, or applies them for you — and how many hours to wait first. |

Each service inside the stack gets its own smaller set: an icon and a description of its own, a
project page and a support page where they differ from the stack's, and the web address its
interface is reachable on.

**Nothing is ever overwritten.** If a setting is already filled in — or already sitting there as a
comment — StaXX does not offer it a second time. That holds for a compose file you paste in from
somewhere else too: what you wrote stays as you wrote it, and only genuinely absent settings are
added as comments.

## Saving

The footer offers **Save**, **Save and start**, and **Undo**, under a standing note: *"Saved exactly
as written — comments, spacing and ordering are kept as they are. This stays a standard compose
file that runs anywhere."* If your data store is sitting on the USB flash drive, an extra amber
warning appears there too, about where container data will end up.

**StaXX checks the file with the real compose before saving it.** That means a file that would not
run is caught the moment you save, rather than the first time you try to start it — and the message
you get is compose's own wording, not a translation of it.

## When it says no, and why

| What it says | Why |
|---|---|
| `Give the stack a name.` | The name is the folder; there is nowhere to put the file without one. |
| `A stack called "<name>" already exists. Pick another name, or edit the existing one.` | Two stacks cannot share a folder, and silently merging into the existing one would put your new file on top of somebody's working stack. |
| `Stack names may contain letters, numbers, dots, dashes and underscores, must start with a letter or number, and must be 63 characters or fewer.` | The name becomes a real folder name and part of what Docker calls the project, and both have limits. Anything outside that list — a slash above all — could point somewhere outside your stacks entirely. |
| `This file still has a REPLACE-ME placeholder in it. Fill it in before starting.` | Something in the file is a gap StaXX filled in for you and cannot guess at. This blocks **Save and start** only — plain **Save** still works, so you can keep the file and come back to it. |
| `The compose file is empty.` | There is nothing to save. |
| Compose's own error, shown word for word | Compose read the file and would not accept it. The wording is left exactly as compose gave it so it matches anything you search for. |
| `Compose took too long to read this file and was stopped. The file was not saved.` | Nothing in StaXX is allowed to hang. When the check runs long it is stopped, and nothing is written — the file is still yours to fix and try again. |
| `Close without saving?` | You are closing the editor with changes in it that were never written. |

Before any of this can happen at all, StaXX needs somewhere to keep your stacks. If no data store
has been chosen yet, the whole page is replaced by a prompt to choose one — see [where StaXX keeps
its things](where-things-live.md).

## What comes out of it

A folder named after your stack, with an ordinary compose file inside it. That is the whole of it.
No database, no hidden index, no second copy of your settings somewhere else — the file is the
stack. You can open it in any text editor, copy it to another machine, or run it with a plain
`docker compose up` on a computer that has never heard of StaXX, and it behaves the same. The
commented settings are ignored by compose entirely; delete them and nothing about how the stack
runs changes.

A new stack is created **stopped** unless you chose Save and start.

## Giving a folder back a compose file it lost

A stack folder can end up with no compose file in it — deleted by accident, renamed, or lost with the
drive it was on. Nothing about such a stack can run, and until you put a file back there is nothing
to edit, so it gets the same starting file a new stack does. Three things lead there, whichever you
happen to be looking at:

- **"Start a compose file here"** on that stack's own menu.
- **The red words on its row**, which say "No compose file in this folder" and are themselves the
  button that fixes it.
- **The red warning triangle** standing in for that stack in its folder's row of pictures.

It works exactly like Add stack, with one difference: the name is filled in and cannot be changed,
because the folder is the thing being repaired. Typing a different name would mean creating a stack
somewhere else, which is what Add stack is for.

Two things it tells you when they apply. If StaXX kept an earlier version of that file, you are
offered your last working copy instead of the blank one — see [going back](going-back.md). And if an
override file is still sitting in the folder, it says so, because a new main file changes what that
override applies to.

If the folder already has a compose file, this is refused and points you at Edit instead. Nothing
here ever writes over a file that is already there.

## What this never does

- **It never starts anything on Save.** Only **Save and start** starts a container.
- **It never reformats what you paste in.** Spacing, quoting, ordering and comments are kept as you
  wrote them.
- **It never fills a setting in for you.** Everything offered is offered as a comment, and a
  setting you have already written is left alone.
- **It never keeps a record of the stack anywhere but the folder itself.** Delete the folder and
  the stack is gone, with nothing left behind pointing at it.
- **It never tells you a file is fine.** Compose accepting a file means it can be read and run — it
  says nothing about whether the ports, paths or images in it are the ones you meant. That
  judgement stays yours.

## Being honest about what's not built

Two things Add stack does not offer:

- A library of starting points — no template gallery, no "make me a stack like that one". Every new
  stack begins from the same small skeleton, and anything richer than that comes from the Apps
  button or from pasting a file you already have.
- Choosing the folder while you are making the stack. It lands at the top level and moves
  afterwards.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
