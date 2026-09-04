# Create a new stack

<!-- index: 55 | starting a stack with nothing but a name: the skeleton you are given, the settings offered as comments, every refusal and why, and the single folder that comes out of it. -->

Use this when there is no catalogue entry for what you want — an image you already have in mind, or a compose file written elsewhere.

## Steps

1. Press **Add stack**, top right of the [stack list](the-stack-list.md).

   ![The top button row with Add stack outlined](../images/guide/making-a-stack-button.png)

2. [The stack editor](the-stack-editor.md) opens straight away, titled **New stack**.
3. Type a name in the **Stack name** box. This is the only thing you are asked for. It becomes the folder's name, so renaming it later moves the folder.
4. Look at the skeleton you are given — see below.
5. Uncomment any of the settings offered, and type a value, if you want them.
6. Put in your own image, service name and settings, or paste a whole compose file over the skeleton.
7. Press **Save**, or **Save and start** to run it straight away.

   ![The editor footer: Tidy this file, Undo, Save and Save and start](../images/guide/making-a-stack-save.png)

There is no folder picker here. A new stack always lands at the top level. To file it in a folder afterwards, use **Move to folder** on the stack's own menu — see [folders](folders.md). There is no template gallery either; every new stack starts from the same skeleton. For a ready-made app instead, use the **Apps** button — see [installing an app](installing-an-app.md).

## Starting file

![The whole starting file: a block of commented-out settings for the logo, description, category and links at the top, and below it a single service called my-app running the alpine image, set to restart unless you stop it](../images/guide/making-a-stack-scaffold.png)

One service, called `my-app`, running `alpine:3.20`, set to restart unless you stop it. It is a real, working compose file — it would run as it stands — but it is there to be replaced.

## Commented settings

Above the service sits a block of settings, commented out. They make a stack look right on the list and its own page. Uncomment a line and type a value to use one; leave them alone and the file runs exactly as it is.

| Offered for the stack | What it is |
|---|---|
| Icon | The logo shown on the list. |
| Description | A sentence saying what the stack is for. |
| Category | Which Unraid app category it belongs to. |
| Project page | The app's own home page. |
| Support page | Its forum thread or issue tracker. |
| Documentation page | Where its instructions live. |
| Author | Who made it. |
| Update policy | Whether StaXX leaves updates alone, tells you, or applies them for you, and how long to wait first. |

Each service gets a smaller set of its own: an icon, a description, a project and support page where they differ, and the address its interface answers on.

Nothing already filled in — or already sitting there as a comment — is offered a second time. That holds for a file you paste in too.

## Saving

The footer offers **Save**, **Save and start**, and **Undo**. If your data store is on the flash drive, an amber warning appears too, about where container data will end up.

StaXX checks the file with the real compose program before saving. A file that would not run is caught at save time, and the message shown is compose's own wording.

A new stack is created **stopped** unless you chose Save and start.

## Refusals

| What it says | Why |
|---|---|
| `Give the stack a name.` | The name is the folder; there is nowhere to put the file without one. |
| `A stack called "<name>" already exists. Pick another name, or edit the existing one.` | Two stacks cannot share a folder. |
| `Stack names may contain letters, numbers, dots, dashes and underscores, must start with a letter or number, and must be 63 characters or fewer.` | The name becomes a real folder name and part of what Docker calls the project, and both have limits. A slash above all is refused, since it could point outside your stacks. |
| `This file still has a REPLACE-ME placeholder in it. Fill it in before starting.` | Something is a gap StaXX filled in and cannot guess at. This blocks **Save and start** only — **Save** still works. |
| `The compose file is empty.` | There is nothing to save. |
| Compose's own error, shown word for word | Compose read the file and would not accept it. |
| `Compose took too long to read this file and was stopped. The file was not saved.` | Nothing in StaXX is allowed to hang. Nothing is written; try again. |
| `Close without saving?` | You are closing the editor with changes that were never written. |

If no data store has been chosen yet, the whole page is replaced by a prompt to choose one instead — see [where things live](where-things-live.md).

## The result

![The stack's compose file opened as plain text outside StaXX: an x-unraid block, then one service with its image, container name, restart policy, environment and command](../images/guide/making-a-stack-result.png)

A folder named after your stack, holding an ordinary compose file. Nothing else — no database, no hidden index. Open it in any text editor, copy it anywhere, or run it with a plain `docker compose up` on a machine that has never heard of StaXX, and it behaves the same. Delete the commented settings and nothing about how the stack runs changes.

## Giving a folder back a compose file it lost

A stack's folder can end up with no compose file — deleted, renamed, or lost with its drive. Until you put a file back there is nothing to edit, so it gets the same starting file a new stack does. Three things lead here:

- **"Start a compose file here"** on that stack's menu — the only thing offered for such a stack:

  ![A stack's menu headed 20-lost-compose-file, with a single item reading "Start a compose file here"](../images/guide/making-a-stack-menu-item.png)

- The red words on its row, reading "No compose file in this folder" — themselves the button that fixes it.
- The red warning triangle standing in for the stack's picture, in its folder's row.

It works like Add stack, with one difference: the name is filled in and cannot be changed, because the folder is the thing being repaired.

If StaXX kept an earlier version of the file, you are offered your last working copy instead of the blank one — see [recovery and redundancy](recovery-and-redundancy.md). If an override file is still sitting in the folder, it says so, since a new main file changes what that override applies to.

![The editor open on a folder being repaired: the stack name filled in and greyed, a line saying the folder has no compose file yet so the name cannot be changed, and beneath the tabs an offer reading "Your last working copy of this stack was saved 2 minutes ago. Load it here instead."](../images/guide/making-a-stack-lost-file.png)

If the folder already has a compose file, this is refused, and points you at Edit instead.

## What this never does

- It never starts anything on Save. Only **Save and start** starts a container.
- It never reformats what you paste in.
- It never fills a setting in for you — everything offered stays a comment until you type it.
- It never keeps a record of the stack anywhere but the folder itself.
- It never tells you a file is fine. Compose accepting it means it can be read and run — not that the ports, paths or images are the ones you meant.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
