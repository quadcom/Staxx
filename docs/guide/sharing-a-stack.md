# Export and import a stack

<!-- index: 70 | how Export blanks your passwords and paths out of a copy, what it refuses to send, and what the other person has to fill in. -->

Your compose file has things in it that belong only to you: passwords, keys, your own folder paths.
**Export** makes a copy with those taken out. Your own stack is never touched.

## 1. Open the menu

Right-click a stack's row and choose **Export…**. See [the stack list](the-stack-list.md) for the
rest of that menu.

<!-- SHOT: sharing-a-stack-menu | close-up | a stack row's right-click menu with Export… visible -->

## 2. Choose what goes

The first screen lists every file in the stack's folder.

<!-- SHOT: sharing-a-stack-files | full frame | the export dialog's first screen, file list and compose settings list both visible -->

| Kind | What happens |
|---|---|
| Compose file | Always goes. Cannot be unticked. Its settings can be blanked one at a time. |
| `.env` file | Same as the compose file — names and values, so it can be blanked entry by entry. |
| Other text file | Must be read on screen before it can be ticked. There is no safe way to blank one automatically. |
| Icon | Ticked already, if the stack has one. Untick it to leave it out. |
| Refused | Keys, certificates, anything not text, anything too large, folders, links. Listed with the reason. No tick. |

A file the compose file actually needs is marked, so you can see what the other end depends on.
That mark never turns a refused file into an allowed one.

**Reading a file:** lines that look like they carry something private are marked. This only ever
means "look here". It never means the rest of the file is safe. The judgement stays with you.

**Why keys are always refused.** A private key is plain text, so the usual text check waves it
through. StaXX checks the file's name and its first line instead — that second check still catches
a key renamed to something else.

### Blanking values

Below the file list is every setting in the compose file. Anything the file already marks as
secret — see [marking a value sensitive](hiding-your-values.md) — is ticked for you. Paths start
unticked; whether a folder name gives something away is your call.

Tick the `.env` file and its entries join the list, all ticked, since a `.env` exists to hold what
differs machine to machine.

## 3. Check what is about to leave

Press **Next**. Nothing is written yet.

<!-- SHOT: sharing-a-stack-summary | full frame | the export dialog's second screen: Going, Left behind and why, Values blanked -->

| Section | Shows |
|---|---|
| Going | Every file that will leave. |
| Left behind, and why | Every file staying, with a reason. |
| Values blanked | Every setting that will be replaced. |

This screen also tells you one thing it does on your behalf: values you ticked get marked secret
**in your own stack too**, so the next export already starts correct. It only marks compose
settings — a `.env` file has nowhere to keep that mark.

## 4. Press Export

<!-- SHOT: sharing-a-stack-export | close-up | the Export button on the summary screen -->

| What you sent | What you get |
|---|---|
| Just the compose file | Downloads as plain text — paste it anywhere. |
| More than one file | Downloads as a single `.staxx` file — an ordinary zip, rename it to `.zip` to look inside. |

StaXX cannot open a `.staxx` file back in yet, so today this is a way to hand a stack over, not a
round trip.

## The exported file

<!-- SHOT: sharing-a-stack-comment | close-up | the exported compose file open in a text editor, showing the comment block at the top and a REPLACE-ME value further down -->

A blanked value becomes `REPLACE-ME` — never a value that looks real. StaXX refuses to start any
stack still holding one, and says which settings are still waiting.

A note is written into the compose file itself, as comments at the top: what it is, when it was
made, which stack it came from, every value blanked, every file left behind and why. No machine
name, no username, no address. It rides inside the file because the usual way to send one of these
is a paste into a chat, and a paste carries nothing but the text. Delete the note once nothing still
reads `REPLACE-ME`.

Everything else survives untouched — comments, blank lines, the order you wrote it in. It is your
real file with holes punched in it, not one rebuilt from scratch.

The person who receives it fills those holes in through [the stack editor](the-stack-editor.md).
For a value that needs a real password, see [making a password or passphrase](passwords-and-hashes.md).

## The icon

An icon lives in a hidden folder tucked inside the stack, alongside every earlier saved copy of the
compose file — including the passwords those copies still hold. Export reaches into that folder for
exactly one thing: the picture your compose file names. Nothing else in there ever leaves, by name,
not by rule — there is no "everything except the risky bits" here.

## What Export never does

- It never changes the stack you are exporting, beyond marking ticked values as secret.
- It never sends anything anywhere. It hands you a file; where it goes next is up to you.
- It never includes a file you did not tick.
- It never says a file is clean — only ever "look here".

## Opening one you receive

<!-- SHOT: sharing-a-stack-preview | full frame | the import preview shown after dropping a .staxx file: what it holds, where it will land, and the sender's note listing values to fill in -->

Drag a `.staxx` file onto the stack list and drop it. Drop it on a folder to land there; drop it on
a stack to join that stack's folder; drop it anywhere else to land at the top level.

Nothing is written on drop. You get a preview first — what it holds, where it will land, what it
will be called, and the sender's note listing every value to fill in. Press **Import** and the stack
appears, stopped, still holding its `REPLACE-ME` values.

If the name is taken, StaXX asks for another. It never merges into or overwrites an existing stack.
Every part of a bundle is checked before anything is unpacked; a bundle with anything outside the
shapes StaXX allows is refused, with a reason. Nothing in it can reach outside the new stack's own
folder, and nothing it claims about its own past is believed.

Prefer to do it by hand? Make a new stack and paste the compose text in.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
