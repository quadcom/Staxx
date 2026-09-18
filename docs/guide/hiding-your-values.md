# Sanitise mode

<!-- index: 45 | the Sanitise tick that hides values marked secret while you photograph the editor, exactly what it leaves showing, what it switches off while it is on, and the one tab it cannot cover. -->

**Sanitise** hides the values you marked secret so you can take a screenshot. Find it as a tick box
on the top row of [the stack editor](the-stack-editor.md), beside the stack's name and just left of
the **Password** button. It starts off.

Sanitise hides values from a picture. It does not hide them from anyone already using this browser.

## Steps

![The Sanitise tick box on the editor's top row, unticked, outlined, with the Password button beside it](../images/guide/hiding-your-values-tick.png)

1. Open the stack you want to photograph.
2. Tick **Sanitise**.
3. Take your picture, then check it yourself before you send it.
4. Untick **Sanitise** to return to editing.

## What it hides, and where

![The compose file as text, with two values swapped for the word REDACTED while the names of both settings and the notes written beside them stay perfectly readable](../images/guide/hiding-your-values-file.png)

![The same two settings shown as boxes on the form: their names and the notes underneath are readable, and only the two values themselves are blurred out](../images/guide/hiding-your-values-form.png)

| Where | What happens to a hidden value |
|---|---|
| The file, shown as text | Replaced on screen with `**REDACTED**`, in the exact spot the real value sat. |
| The form | The box is blurred, keeping its real width. |

Only a value the file itself marks **Secret** is hidden. Mark your values first; see
[editing a stack](editing-a-stack.md).

## What stays visible

| Still visible |
|---|
| The **name** of a setting |
| The **note** written beside a secret |
| The **container side of a port** |
| The whole **Versions** tab |
| **Everything behind the dialog** |

The real values are still in the page. They come straight back the moment the tick comes off.

## Second file tabs

A stack can hold more than a compose file: a settings file such as `.env`, or anything else kept
beside it. These open as extra tabs. Opening one turns **Sanitise** off. Return to the first tab
before you can turn it back on.

Settings files often hold passwords. Check a second tab for values before you photograph it; a
picture taken there shows everything in that file, in full.

## What is switched off while it is on

![The "Sanitised for screenshots" banner across the top of the editor, with the History tab greyed out beside the still-usable Configure, Manage and Versions tabs](../images/guide/hiding-your-values-banner.png)

The editor locks while **Sanitise** is on. The file and the form both show placeholders in place of
your real values.

| Turned off | What that means |
|---|---|
| **Save** and **Save and start** | Disabled. Turn Sanitise off to save. |
| The file pane | Read-only. You can scroll and select, not type. |
| Every box and button on the form | Disabled. Anything already disabled before you turned Sanitise on stays disabled after you turn it off. |
| **Undo** | Disabled. |
| **Replace** in the find bar | Disabled. **Find** still works. |
| **Fill** on the password generator | Disabled, with the message "Sanitise is on, so writing to the file is turned off. Turn it off to fill a box." **Copy** still works. See [password generator and hashing tool](passwords-and-hashes.md). |
| The **History** tab | Disabled, with the message "Not available while Sanitised. An old version holds the real, unhidden values, so history is hidden until Sanitise is turned off." See [recovery and redundancy](recovery-and-redundancy.md). |

To send a stack to somebody else, use [sharing a stack](sharing-a-stack.md), which replaces your
values with placeholders in the file itself.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
