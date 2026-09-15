# Merging stacks into one

<!-- index: 65 | joining two or more stacks that are really one application into a single stack: picking them, picking which one keeps its name, sorting out what clashes, agreeing the wiring, and reading the confirmation before anything is written. -->

An Unraid template describes one container. So an application that needs a database usually arrives
as two of them — installed separately, wired together by hand. This turns stacks like that back
into what they always were: one application, one stack.

## Steps

1. Press **Merge**, in the top button row beside **Add stack**. Below a desktop-width window this
   button is not there at all — merging needs the room to show you the files side by side as you go.
2. **Pick two or more stacks.** Every stack on the server is listed, grouped by folder, with a search
   box. A stack that cannot be merged — its file will not read, it is under review, or it has a
   second settings file — is shown anyway, greyed out, with the reason in place of its tick box.
3. **Pick which one takes the others in.** It keeps its own name, its folder, its icon and its
   history; the rest arrive as containers inside it.
4. **Answer anything that clashes.** StaXX shows you, one at a time, anything it found that needs a
   decision — the same name meaning two different things, two containers wanting the same port, and
   so on — with its recommended answer already chosen. Anything it checked and found no problem with
   is listed too, quietly, so you know it was actually looked at.

   <!-- PICTURE STILL TO BE TAKEN — uncomment once the screenshot exists:
![The clashes step of the merge window: a bordered block naming one thing that needs a decision, a plain-English explanation of what would go wrong, radio-button choices with the recommended one already selected, and a dimmed "checked and fine" list underneath](../images/guide/merging-stacks-clashes.png)
-->

5. **Agree the wiring.** Once two containers are in one stack they can talk to each other directly by
   name, instead of going out to your network and back in. StaXX shows you every place it wants to
   change for that reason, and you tick each one off — or leave it alone.
6. **Read the confirmation, then press Merge.** Nothing has been written up to this point. The last
   screen says exactly what is about to happen: what the stack you kept is about to gain, that the
   other stacks are left exactly as they are, and which containers will actually be rebuilt.

   <!-- PICTURE STILL TO BE TAKEN — uncomment once the screenshot exists:
![The confirmation step of the merge window: one block for the stack that gains containers, listing every change and rename, one block for each stack left alone, and a block naming what has to stop and what will be rebuilt](../images/guide/merging-stacks-confirmation.png)
-->

Press **Merge** and the file is written. The editor for the newly merged stack opens straight away —
starting it is still your decision; nothing is started for you.

## Storage Docker looks after

Some containers keep their data in storage that Docker manages itself, rather than in a folder on
your array. That storage is named after the stack it belongs to, so the moment a container joins a
different stack, its old storage is out of reach under its old name — the database looks empty, not
broken.

StaXX never carries this across silently. It is always asked about, on the clashes step, with the
real choice spelled out: keep using the storage that already exists under its old name, start the
folded-in container with nothing in it, or stop here and move the data to a folder on the array by
hand first.

## What happens to the stacks you merged

- **The originals are left exactly as they are.** Merging copies containers into the stack you kept;
  it does not touch the folders or files of the others.
- **StaXX offers to remove each one, once the merged stack has actually been run.** Until then, a
  leftover stack's row carries a small note saying which stack it was folded into, with a button to
  remove it when you are ready. It goes on working normally in the meantime.
- **The whole merge is one step in the kept stack's history.** Stepping back once undoes the entire
  merge, the same as undoing any other single change.
- **Nothing is written until the last button is pressed.** Every step before Merge only decides what
  would happen; you can go back, change an answer, or cancel out of the whole thing at any point.

## What this never does

- It never merges without you choosing which stack keeps its name — no new, blank stack is ever
  created for the result to land in.
- It never guesses a connection between two containers it is not sure of. Anything it cannot place is
  left alone and said so.
- It never removes a leftover stack on its own. You press its Remove button when you are ready.
- It never offers itself below a desktop-width window. There is no cut-down version of this tool.

## Not built yet

- Splitting a merged stack back into two separate ones is not built.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
