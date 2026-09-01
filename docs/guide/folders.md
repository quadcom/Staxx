# Working with folders

<!-- index: 50 | grouping stacks on the list: making a folder, moving a stack in or out, running everything inside one at once, renaming, and deleting. -->

A folder groups stacks under one heading, so you can collapse it when you are not looking at it.
See [the folder row and its menu](the-stack-list.md#folders) for what it looks like on the list.

Folders are one level deep. You cannot put a folder inside a folder.

## Making a folder

1. Press **New folder** above the list, or **New folder…** on a stack's own menu — that also files
   the stack straight into it.
2. Type a name.

A folder is [a real directory](where-things-live.md) in your data store, so the name has to work as
one:

| Refused when… | Because… |
|---|---|
| The name is empty | There is nothing to call it. |
| The name is longer than 63 characters | A folder is stored the same way a stack is. |
| The name uses anything but letters, numbers, dots, dashes and underscores, or does not start with a letter or number | Spaces, slashes and other punctuation are not safe directory names. |
| Something is already called that at the top level | A folder and a stack share one list of names ("Media" and "media" count as the same name). |

## Moving a stack into a folder

1. Open the stack's own menu.
2. Choose **Move to folder**.
3. Pick the folder.

This moves the stack's files. It does not stop the stack, and does not touch its containers.

## Moving a stack out of a folder

1. Open the stack's own menu.
2. Choose **Remove from folder**.

The stack goes back to the top level. A move either way is refused when something else already has
that name at the destination — rename it first.

## Renaming a folder

1. Open the folder's own menu.
2. Choose **Rename folder**.
3. Type the new name.

The same name rules above apply. Every stack inside moves with it. Nothing about how they run
changes.

## The row of pictures on a folder

A collapsed folder's own row carries a picture for every app inside it:

![Six app logos in a row on a folder's own row, each in its own rounded square, standing for the six stacks inside that folder](../images/guide/folders-row-pictures.png)

A stack running several things at once keeps its pictures together as one group, with a count on
the end when there are more than will fit:

![Two app logos overlapping, the left one tucked behind the right, with a grey "+8" tag beside them](../images/guide/folders-group-closed.png)

Rest your mouse on a group to open it into a grid showing every app separately:

![The same group opened out into a grid of ten app logos, four across and three down, each in its own square](../images/guide/folders-group-open.png)

Hovering a picture names the stack and service it belongs to. Clicking it opens the folder, if it
was collapsed, and jumps to that stack's row.

A stack StaXX cannot read is drawn as a red warning triangle instead of a picture:

![A red warning triangle with a red outline sitting between two dimmed app logos](../images/guide/folders-broken-stack.png)

Clicking it goes to the editor, where the problem can be fixed.

## Running everything in a folder

Open the folder's own menu:

| Item | What it does |
|---|---|
| Start everything | Starts every stack in the folder. |
| Stop everything | Stops every stack in the folder. |
| Check this folder | Checks every image in the folder for updates. |
| Update this folder | Installs every update waiting in the folder. See [updating everything at once](updates.md). |

Each stack still runs its own outcome. One failing to start does not stop the others, and each row
shows its own result.

## Deleting a folder

1. Open the folder's own menu.
2. Choose **Delete folder**.

**This never deletes what is inside it.** Every stack is moved back to the top level first. Only
once every one of them has moved does the empty folder itself go.

Nothing moves unless all of them can:

| Refused when… | Because… |
|---|---|
| Moving a stack back to the top level would land on a name already used there | Two things cannot share one spot. StaXX names which stack clashes, so you can rename it and try again. |
| The folder holds something that is not a stack | StaXX only knows how to move stacks — anything else has to be dealt with by hand first. |

## What this never does

- It never deletes a stack, or touches its containers, when you delete the folder holding it.
- It never stops a stack, or changes its settings, when you move it into or out of a folder.
- It never disturbs the containers inside a folder when you rename it.
- It never lets you nest one folder inside another.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
