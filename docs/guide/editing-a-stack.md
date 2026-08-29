# Editing a stack

<!-- index: 40 | the form you get when you open a stack: the three ways to look at the same file, what the sections do, the note under each box, the two marks, and what saving does and does not change. -->

This is the screen you get when you open a stack: the file it runs from, drawn as a form so you can
change a setting without touching a line of it. Open it by clicking the stack in the list. It always
opens on the **Configure** tab, whatever tab you were last looking at — the other tabs (**Manage**,
for a live console into a running container; **History** and **Versions**, for what has changed
over time) sit alongside it, but this page is about Configure alone.

## The short version

1. Click the stack to open it. You land on Configure, looking at the form.
2. Change a box, tick a section on or off, or type a sentence under a box to leave a note about it.
3. A box outlined in red and named at the bottom of the screen is empty and must be filled in before
   you can save.
4. Press **Save**. The file is rewritten with only what you changed; everything else survives
   untouched.

## The three ways to look at the same file

A strip of three buttons switches how much of the file you see. All three are looking at the same
file at the same time — switching does not convert anything, and an edit made in one shows up in the
others the moment you switch.

| Button | Shows |
|---|---|
| **Form** | Only the form — boxes, ticks and notes, no raw text at all. What a phone or a narrow window falls back to, because two panes side by side would be too cramped to read. |
| **Split** | The form on one side, the actual file on the other, scrolling together. The usual view on a normal-sized window. |
| **Compose** | The file on its own, full width, for typing directly into it. |

![The editor in Split view: the form of boxes on the left and the compose file's text on the right, with the Form, Split and Compose buttons above them and the Configure, Manage, History and Versions tabs across the top](../images/guide/editing-a-stack-split.png)

An **Outline** button above the panes jumps straight to a block or a service in a long file, rather
than scrolling to find it.

## Sections and the fields inside them

The form is organised into groups — Ports, Volumes, Variables, Devices, Labels, and further ones
such as Health check, Resource limits, Networks, Secrets, Configs, DNS servers and more — each
holding the boxes for one part of the file. A group only appears once there is a reason to show
it: either the file already has something in it, or you have switched it on yourself.

Where a group has a handful of answers most people want, it offers them by name rather than leaving
you to type a path from memory.

![The device picker's graphics section, offering "All graphics cards", "Intel graphics only" and "AMD graphics only", each showing the path it would add and a one-line explanation of what it is for](../images/guide/editing-a-stack-devices.png)

**Switching a group off does not delete what was in it.** It sets it aside, kept exactly as
written — comments and all — so switching it back on later restores it precisely as it was. One
group, named **Container**, always shows: the image, and the handful of settings every container
has, which is why it is the one group with no switch to turn it off.

Anything the form cannot safely show as a box — a value spread over several lines, built from a
shared block, or written in a shape the form will not touch — is shown as read-only text with a
plain sentence saying why, rather than hidden or guessed at. Fixing it means switching to the
Compose view and editing the line yourself.

## The note under a box

Most boxes carry a smaller box underneath labelled **Notes**. That is not a caption the form
invented — it is the actual comment sitting on that line in the file, the same kind of note you
might scribble there yourself in a text editor. Type a sentence into it and that sentence is written
back as the comment on that exact line, nowhere else. Leave it blank and no comment is written at
all.

## The two marks

Two small facts about a box cannot be worked out just by looking at the value, so — and only these
two — are recorded, as two short marks at the end of that same comment:

| Mark | Means |
|---|---|
| Secret | The value is hidden whenever you turn on Sanitise (the button that blurs sensitive values so a screenshot is safe to share) — the rest of the time it shows in plain text like everything else. |
| Required | The box cannot be left empty. Clearing it outlines the row in red and names it at the bottom of the screen, and Save stays switched off until it is filled back in. |

Neither mark is ever guessed from a box's name. A password-shaped name is not treated as secret
unless the file actually says so, because a guess about a secret is exactly the kind of mistake
that shows up in someone else's screenshot rather than on your own screen first.

## Saving

Save writes the file back with only the lines your changes actually touched — nothing is
reformatted, and nothing you did not change moves. The dialog stays open afterwards, so a run of
small edits is one visit rather than a reopen after every single one; the Save button itself reads
"Saved" for a moment as the only sign anything happened.

**Saving is not the same as restarting.** A container already running keeps running on its old
settings until you restart it — saving only changes what the file says should happen next. If the
two have drifted apart, the row shows a "Restart to apply" mark once you close the editor; see the
[key to every mark](marks.md) for what that looks like on the list.

Save refuses to run in three situations, each for its own reason: a required box is still empty, so
there is nowhere safe to write to; Sanitise is switched on, so what you are looking at has values
hidden from you and saving over them would be a guess; or you have switched to look at a second
file belonging to the same stack, in which case Save is waiting for you to switch back to the main
one before it writes anything.

**Whatever the form flags — a gap, a mismatch, a suggestion — is a judgement call left to you.** The
form points things out; it never decides for you that something is safe, wrong, or fine to ignore.

## What this never does

- **It never reformats your file.** Whitespace, indentation, quoting style and line order all stay
  exactly as you wrote them, everywhere you did not touch.
- **It never loses a comment.** A comment on a line you did not edit travels with that line
  untouched, whether or not the form itself has any idea what the comment means.
- **It never changes a setting you did not touch.** A save only rewrites the exact spans that
  actually changed.
- **It is not a second copy of your file.** The form is a view drawn from the file itself, not a
  conversion into some other format — the file you started with is still the same file, in the same
  place, readable in any text editor, whether or not you ever open this screen again.

## Left out, for now

Some of what a stack records about the app itself — its description, its logo, its project links —
has no box on this form yet. There are two ways round that in the meantime. **Fill in details**, at
the top of the screen, goes and looks those up from the image, from the app's catalogue entry and
from its own page, and shows you what it found before writing any of it. Typing your own wording
instead still means switching to the Compose view and editing it by hand. That is an honest gap,
not a hidden one.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
