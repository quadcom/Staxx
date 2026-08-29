# Hiding your values for a screenshot

<!-- index: 45 | the Sanitise tick that hides the values you marked secret while you photograph the editor, exactly what it leaves showing, what it switches off while it is on, and the one tab it cannot cover. -->

Sooner or later you want to show somebody your screen — a post on a forum, a message to a friend,
a picture attached to a question. The trouble is that the settings you need to show sit right beside
the ones you do not: a password, a key, a token you would rather nobody read off a photograph.

**Sanitise** is the answer to that one problem. Tick it and the values you have marked as secret
stop showing on screen, so you can take the picture. Untick it and everything is back. It is a way
of looking at the screen and nothing more — see [what this never does](#what-this-never-does).

You will find it as a tick box on the top row of a stack's editor, beside the stack's name and just
left of the **Password** button. You need a stack open to reach it, and it always starts off — it is
never remembered from the last time.

## The short version

1. Open the stack you want to photograph.
2. Tick **Sanitise** on the top row. A banner appears and the editor locks.
3. Take your picture — then look at it yourself before you send it.
4. Untick **Sanitise** to get back to editing.

## What you see while it is on

A banner sits across the top of the editor for as long as it is on:

> **Sanitised for screenshots.** Values marked sensitive are hidden and nothing can be changed.
> Turn Sanitise off to make edits. The real values are still in the page — this hides them from a
> picture, not from anyone with access to this browser.

And the values themselves change, differently in each of the two panes:

| Where | What happens to a hidden value |
|---|---|
| The file, shown as text | The value is replaced on screen with `**REDACTED**`, in the exact spot the real one sat. |
| The form | The box is blurred, keeping its real width so the shape of the page stays honest. |

## It never guesses what is secret

Nothing here is worked out from the look of a name. A box called `ADMIN_PASSWORD` is hidden only if
the file itself says that value is secret — which is the **Secret** mark described in
[editing a stack](editing-a-stack.md). If you have not marked a value, Sanitise leaves it in plain
sight, because a guess about a secret is a guess that gets it wrong in somebody else's direction.

So the first thing to do, before you photograph anything, is check that the values you care about
actually carry the mark.

## What it does not hide

This is the part worth reading twice. Sanitise covers less than a quick glance suggests.

| Still visible | Why |
|---|---|
| The **name** of a setting | `ADMIN_TOKEN` still reads as `ADMIN_TOKEN`; only the value goes. Hiding both would make the picture unreadable for no gain. |
| The **note** written beside a secret | The sentence you wrote about a setting is not the setting's value. |
| The **container side of a port** | The port inside the container is not the secret half of the pair. |
| The whole **Versions** tab | An image name and a version number are not values you wrote, so there is nothing there for this to hide. |
| **Everything behind the dialog** | The stack list underneath is untouched. If it is in the frame, it is in the picture. |

And one thing that is not about the screen at all:

**The real values are still in the page.** They have not been removed, replaced or scrambled —
they are simply not being drawn. Anyone sitting at this browser can still reach them, and they come
straight back the moment the tick comes off. This hides your values from a photograph. It does not
hide them from a person.

### The one it cannot cover: a second file

A stack can hold more than a compose file — a settings file such as `.env`, or anything else you
keep beside it. Those open as extra tabs along the top of the editor, and this is where Sanitise
stops:

**Opening one of those tabs switches Sanitise off by itself.** The tick clears, everything is
visible again, and the tick box goes dead with the note *"The compose file is on the first tab."*
You cannot turn it back on until you return to the first tab.

So a screenshot taken while one of those tabs is on screen shows **everything in that file, in
full**, and there is no way to hide it. A settings file is usually where the passwords actually
live, which makes this the easiest mistake on the whole page to make. If you are photographing a
second file, you are photographing all of it.

## What is switched off while it is on

While Sanitise is on the editor is locked. That is the safety of it, not an inconvenience: what you
are looking at is a screen full of placeholders, so if anything could be saved in that state,
`**REDACTED**` would be written into your real file.

| Turned off | What that means |
|---|---|
| **Save** and **Save and start** | Nothing can be written to the file while placeholders are on screen. |
| The file pane | Read-only — you can scroll and select, but not type. |
| Every box and button on the form | All disabled. Anything that was already disabled before stays that way when you switch back. |
| **Undo** | There is nothing to undo, because nothing can be changed. |
| **Replace** in the find bar | Replacing would edit the placeholder copy on screen, which is thrown away. **Find** still works — searching hidden text is just less useful. |
| **Fill** on the password generator | The panel and its **Copy** button keep working; only writing into a box stops, with the note *"Sanitise is on, so writing to the file is turned off. Turn it off to fill a box."* |
| The **History** tab | Disabled, with the message *"Not available while Sanitised. An old version holds the real, unhidden values, so history is hidden until Sanitise is turned off."* |

That last one is worth its own sentence. Every older copy of your file holds the values before you
hid them, so leaving history open would undo the whole exercise in a single click — it is closed
along with everything else, whether or not you were already looking at it.

## What this never does

- **It never changes your file.** Nothing is written, nothing is saved, and the file on your server
  is the same file when you untick it as it was when you ticked it.
- **It never sends anything anywhere.** No copy is made and nothing leaves your server. It is purely
  a change to what is drawn on your screen.
- **It never removes a value.** The values are all still there, in the page, one tick away.
- **It never tells you a picture is safe to share.** It hides what you marked, and only what you
  marked. Whether the screenshot you just took is fit to post is your judgement and stays your
  judgement — look at the picture itself before you send it, every time.

## The related thing that is not this

Giving somebody a copy of a stack to run themselves is a different job, done by **Export**, which
writes `REPLACE-ME` into a copy it hands you and lets you choose what goes. The two share the same
machinery for working out what to hide, but Sanitise produces nothing and changes nothing, while
Export produces a file you send. See [sharing a stack](sharing-a-stack.md) for that.

## Left out, for now

There is no way to hide a value just for one screenshot without marking it secret in the file
first, and no way to hide the extra tabs at all. Both are honest gaps rather than oversights: the
first keeps Sanitise from ever guessing, and the second is why the warning above is on this page.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
