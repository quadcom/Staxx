# Create a new stack

<!-- index: 55 | starting a stack with nothing but a name: the skeleton you are given, the settings offered as comments, every refusal and why, and the single folder that comes out of it. -->

Use this when there is no catalogue entry for what you want: an image you already have in mind, or a compose file written elsewhere.

## Steps

1. Open **Add** and choose **Add a blank stack**.

   ![The top button row with the Add menu open and Add a blank stack outlined](../images/guide/making-a-stack-button.png)

2. [The stack editor](the-stack-editor.md) opens straight away, titled **New stack**.
3. Type a name in the **Stack name** box. This is the only thing you are asked for. It becomes the folder's name, so renaming it later moves the folder.
4. Look at the skeleton you are given, shown below.
5. Uncomment any of the settings offered, and type a value, if you want them.
6. Put in your own image, service name and settings, or paste a whole compose file over the skeleton.
7. Press **Save**, or **Save and start** to run it straight away.

   ![The editor footer: Tidy this file, Undo, Save and Save and start](../images/guide/making-a-stack-save.png)

A new stack always lands at the top level. To file it in a folder afterwards, use **Move to folder** on the stack's own menu (see [folders](folders.md)). Every new stack starts from the same skeleton. For a ready-made app instead, use **From Apps** (see [installing an app](installing-an-app.md)).

## Starting file

![The whole starting file: a block of commented-out settings for the logo, description, category and links at the top, and below it a single service called my-app running the alpine image, set to restart unless you stop it](../images/guide/making-a-stack-scaffold.png)

One service, called `my-app`, running `alpine:3.20`, set to restart unless you stop it. It is a real, working compose file, ready to be replaced.

## Commented settings

Above the service sits a block of settings, commented out. Uncomment a line and type a value to use one; leave them alone and the file runs exactly as it is.

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

Each service in the file gets a smaller set of the same commented settings, for anything that differs from the stack as a whole.

Nothing already filled in, or already sitting there as a comment, is offered a second time. That holds for a file you paste in too.

## Saving

The footer offers **Save**, **Save and start**, and **Undo**. If your data store is on the flash drive, a warning appears about where container data will end up.

A file that will not run is refused when you press **Save**, with compose's own wording shown.

A new stack is created stopped unless you choose **Save and start**.

## Refusals

| What it says | What to do |
|---|---|
| `Give the stack a name.` | Type a name before saving. |
| `A stack called "<name>" already exists. Pick another name, or edit the existing one.` | Choose a different name, or open the existing stack instead. |
| `Stack names may contain letters, numbers, dots, dashes and underscores, must start with a letter or number, and must be 63 characters or fewer.` | Choose a name that follows this pattern. |
| `This file still has a REPLACE-ME placeholder in it. Fill it in before starting.` | Fill in the placeholder before pressing **Save and start**. **Save** still works. |
| `The compose file is empty.` | Write something in the file before saving. |
| Compose's own error, shown word for word | Fix the fault it names. |
| `Compose took too long to read this file and was stopped. The file was not saved.` | Try saving again. |
| `Close without saving?` | Save your changes, or close without them. |

Choose a data store first if none is set (see [where things live](where-things-live.md)).

## The result

![The stack's compose file opened as plain text outside StaXX: an x-unraid block, then one service with its image, container name, restart policy, environment and command](../images/guide/making-a-stack-result.png)

A folder named after your stack, holding an ordinary compose file. Open it in any text editor, copy it anywhere, or run it with a plain `docker compose up` on a machine that has never heard of StaXX, and it behaves the same. Delete the commented settings and nothing about how the stack runs changes.

## Missing compose file

A stack's folder can end up with no compose file: deleted, renamed, or lost with its drive. Until you put a file back there is nothing to edit, so it gets the same starting file a new stack does. Three things lead here:

- **"Start a compose file here"** on that stack's menu, the only thing offered for such a stack:

  ![A stack's menu headed 20-lost-compose-file, with a single item reading "Start a compose file here"](../images/guide/making-a-stack-menu-item.png)

- The red words on its row, reading "No compose file in this folder", themselves the button that fixes it.

![A stack row whose folder has no compose file, with the red No compose file in this folder message outlined](../images/guide/making-a-stack-broken-row.png)

- The red warning triangle standing in for the stack's picture, in its folder's row.

![The same row with the warning triangle outlined where the icon would be](../images/guide/making-a-stack-broken-icon.png)

It works like **Add a blank stack**, with one difference: the name is filled in and cannot be changed.

If StaXX kept an earlier version of the file, you are offered your last working copy instead of the blank one (see [recovery and redundancy](recovery-and-redundancy.md)). If an override file is still sitting in the folder, it says so.

![The editor open on a folder being repaired: the stack name filled in and greyed, a line saying the folder has no compose file yet so the name cannot be changed, and beneath the tabs an offer reading "Your last working copy of this stack was saved 2 minutes ago. Load it here instead."](../images/guide/making-a-stack-lost-file.png)

If the folder already has a compose file, this is refused, and points you at **Edit** instead.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
