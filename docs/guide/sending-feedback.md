# Sending feedback

<!-- index: 90 | How to report a bug, suggest a feature or suggest an improvement from inside StaXX, and what is sent with it. -->

A speech-bubble button sits in the bottom right corner of every StaXX screen, the stack editor and
**Settings** included. Press it to report a bug or suggest an idea. Your report goes to StaXX's
feedback board, where the StaXX team reads it.

![The round orange-ringed speech-bubble button in the bottom right corner of a StaXX screen, with a band of the page around it.](../images/guide/sending-feedback-corner-button.png)

## The short version

1. Press the speech-bubble button.
2. The first time, press **Connect** and allow StaXX on the feedback board.
3. Choose the kind of report, give it a title and write what you want to say. Paste screenshots with Ctrl+V.
4. Press **Send**.

## Connect to the feedback board

![The Connect to the feedback board window over the stack list, with a short explanation of signing in with Google or GitHub and a Connect button.](../images/guide/sending-feedback-connect.png)

The first time you press the button, a small window asks you to connect. Reports go under your own
feedback board account. You do this once.

1. Press **Connect**.
2. The board opens in a new tab. If it does not, press **Open the board**.
3. Sign in with Google or GitHub and check the board shows the same code as the StaXX window.
4. Allow StaXX.

![The connect window showing the code KXRT-7QPM, an Open the board button and the line Waiting for you to allow StaXX.](../images/guide/sending-feedback-connect-code.png)

StaXX then tells you that you are connected. Press **Write your report** to carry on.

![The connect window after success, reading You are connected as Alex, with a Write your report button.](../images/guide/sending-feedback-connected.png)

## Write your report

![The Report a problem window open over the stack list with Bug Report chosen, a title filled in, a pasted screenshot and a sentence in the box, the Include details that help fix this list, and Cancel and Send buttons.](../images/guide/sending-feedback-form.png)

Choose what you are sending at the top of the window. Each kind goes to its own section of the board.

| Kind | Use it for |
|---|---|
| **Bug Report** | Something that is broken or behaves wrongly. |
| **Feature Requests** | Something StaXX does not do yet that you would like it to. |
| **Improvements** | Something that works but could work better. |

![The top of the report window with Feature Requests chosen, so the window title reads Suggest a feature and the box heading reads What would you like StaXX to do?](../images/guide/sending-feedback-kinds.png)

Give the report a title
and write what you want to say in the box. Press Ctrl+V to paste a screenshot into it.

The window floats above the page. Drag it by its top bar to move it. Press the up arrow at the top
of the window to roll it up to the top of the screen while you take a screenshot of the page
underneath. Press the down arrow to bring it back.

![The report window rolled up into a small Report a problem bar at the top middle of the screen, with a band of the Unraid header and the toolbar below it.](../images/guide/sending-feedback-rolled-up.png)

**Cancel** asks before throwing the report away. **Keep writing** takes you back to it.

![The bottom of the report window asking Throw this report away? What you typed will be lost, with Keep writing and Discard report buttons.](../images/guide/sending-feedback-cancel.png)

## Details that help fix a bug

![The report window open over a stack editor, with the Include details that help fix this box showing two ticked files, each with a Preview link, and the note that they are kept private.](../images/guide/sending-feedback-details.png)

A **Bug Report** has an **Include details that help fix this** box under the text. Tick the files
you want to send. Which files are offered depends on where you are. On a stack's editor, for
example, the stack's own files are offered. If you have used **Import** in the last week,
**What happened the last few times you used Import** is offered too, on any screen.

Passwords, keys, email addresses and network addresses on your home network are replaced before
anything is sent. These files are private. Only you and the StaXX team can see them, and they
are deleted after 90 days.

### Preview a file

Press **Preview** beside a file to see exactly what will be sent.

![The preview window showing a stack compose file, with some values replaced by highlighted tags such as home address, hidden and secret, and the Hide this button at the top right.](../images/guide/sending-feedback-preview.png)

- To hide something else, select the text and press **Hide this**.
- To show a highlighted part again, click it. It then shows everywhere it appears.
- Select a value to hide it, not a setting name.

![The bottom of the preview window with the message that restart is a setting name, not a value, so it stays.](../images/guide/sending-feedback-preview-setting-name.png)

StaXX asks you to confirm when you hide something that would make the problem much harder to find,
and when you show a part it hid on its own.

![The bottom of the preview window asking Hiding this makes the problem much harder to find. Hide it anyway? with Keep it and Hide anyway buttons.](../images/guide/sending-feedback-preview-hide-anyway.png)

### Check this file

A file marked **Check this file** holds a number that may be a network address or a version
number. StaXX hides it until you decide. Press **Preview**, click any highlighted number you want
sent as it is, then press **Approve**. Approve every marked file, or untick it, before you press
**Send**.

![The details box with the compose file marked Checked with a green tick, the container logs file marked Check this file in amber and the versions file with no mark, and under it the message Preview the files marked Check this file and approve them before sending, beside Cancel and a greyed-out Send.](../images/guide/sending-feedback-check-file.png)

![The lower part of the preview window: a highlighted possible address 1 tag in the container log text, and the footer with Close and Approve buttons.](../images/guide/sending-feedback-approve.png)

## Send

Give the report a title, then press **Send**. Every report carries the StaXX and Unraid versions
and the screen you were on.

![The Report sent window: the thanks line, the link to the report on the board, What was sent with a copy of the report and its versions line, and the Private files sent with it list.](../images/guide/sending-feedback-sent.png)

The window then shows:

- a thank-you line;
- a link to your report on the board, which opens in a new tab;
- a copy of what you sent, with the versions line;
- **Private files sent with it**, listing each file and its size.

![The Private files sent with it list showing compose.txt and versions.txt with their sizes and the note that they are deleted after 90 days.](../images/guide/sending-feedback-sent-files.png)

Press **Close** when you are done.

## Disconnect

To disconnect, open **Settings**, choose the **Integrations** tab and press **Disconnect** on the
**Feedback board** line. To connect again, press the speech-bubble button and press **Connect**. See
the [Integrations tab](settings-integrations.md).

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
