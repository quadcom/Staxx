# Install an app from Community Applications

<!-- index: 60 | a step-by-step walkthrough of adding a catalogue app: what carries across, what needs checking before you start it, and why nothing exists until you save. -->

Community Applications is the catalogue of ready-made apps Unraid users already know. This turns
one catalogue entry into an ordinary compose file, ready for you to check before anything runs.

## Walkthrough

1. Press **Apps**, next to Add stack.

   <!-- SHOT: installing-an-app-button | full frame | the button row along the top, with Apps highlighted -->

2. Read the window that opens. It shows a curated home page — **Spotlight**, **Recently Added**
   and **Top Trending** — with a search box and a category list above them.

   ![The Add an app window: an explanation of what adding one does, a Home and a Search tab, a search box with a category dropdown beside it, then Spotlight Apps and Recently Added listed as cards with a picture, a maintainer, a pull count and an Add button each](../images/guide/installing-an-app-window.png)

3. Find the app you want. Browse the home page, pick a category, or type a name in the search box —
   typing switches you to the **Search** tab on its own.

4. Press an app's card to open it.

   ![An app's card in the Add an app window: its picture and name, Project, Support and Registry links, the description the maintainer wrote, its category, when it was added, its image name and its maintainer, with Add this app and Close in the top corner](../images/guide/installing-an-app-card.png)

5. Read the card. It shows an icon, a description, screenshots if the maintainer supplied any, its
   category, when it was added and last updated, its Docker Hub pull count and star rating where
   known, and links to the project's page, its support thread, its read-me and its registry page. An
   app the maintainer has marked as no longer kept up says so, but you can still add it.

6. Press **Add this app**.

7. Wait. StaXX turns the catalogue entry into a compose file. Nothing is installed and nothing
   contacts your server's Docker yet.

8. Land in the stack editor. The new stack has a name already, but nothing has been saved.

   ![The editor straight after pressing Add this app, titled New stack: a banner saying one value was changed because it held a dollar sign, the converted compose file with placeholder comments at the top, and below the form the check-list of things to look at before saving](../images/guide/installing-an-app-editor.png)

9. Read the banner above the form, if one appears. It lists anything worth a look before you save —
   settings with no compose equivalent, values StaXX had to fill in, or a dollar sign that was
   written twice. See [what carries over](#what-carries-over) below.

10. Check the ports and folders StaXX filled in. Each one keeps its own description, so the form
    still explains what it is for.

11. Press **Save**.

    <!-- SHOT: installing-an-app-save | close-up | the Save button in the editor -->

12. See the new stack on the list, stopped. Saving never starts it.

    <!-- SHOT: installing-an-app-stopped | close-up | the new row on the stack list, showing a stopped state pill -->

13. Press **Start** when you are ready to run it.

The editor you land in, and its buttons and tabs, are explained in
[the stack editor](the-stack-editor.md). The stack's new row, and what its marks mean, are covered
in [the stack list](the-stack-list.md).

The same search box also finds plain images — a Docker Hub search, or an image already on this
server. Those carry none of a catalogue entry's own settings, so adding one opens a bare starting
file with just the image name in it, for you to build up by hand.

## The result

![The three bars under the editor: the red check-list naming each path that was filled in with a placeholder, a bar offering to create three folders that do not exist yet, and a caution that one of the folders already has files in it](../images/guide/installing-an-app-result.png)

The result is a normal compose file — nothing about it depends on StaXX to run. It arrives already
laid out in StaXX's standard order, the same tidying described in
[editing a stack](editing-a-stack.md#tidying-a-file-into-staxxs-layout). Every setting the catalogue
app declared becomes an ordinary line: a port, a mounted folder, a variable, a device, a label. Its
description, its web address, its icon and its other links go into the block compose itself ignores
but StaXX reads to draw the form — delete that block and the file still runs with a plain
`docker compose up`.

## What carries over

| From the original app | What happens |
|---|---|
| Ports, folders, devices, variables, labels | Copied across with their own descriptions. |
| Icon, description, category, web address, project and support links | Copied into the app-information block. |
| A network set to the default (or nothing stated) | Written out as the default network, explicitly. |
| Sharing another container's network entirely | Carried over, with a note that the container it shares with must already exist. |
| A named network | Carried over, with a note that the network must already exist on this server. |
| A fixed IP address | Kept only when there is a named network for it to sit on; dropped, with a reason, otherwise. |
| A fixed MAC address | Kept wherever there is any network interface at all; dropped only when the container shares another container's network entirely. |
| Extra `docker run` options StaXX recognises (memory limits, restart policy, capabilities, health checks named as options, and others) | Translated to their compose equivalent. |
| Extra `docker run` options StaXX does not recognise | Left out, and named in the banner above the editor. |
| A value containing a dollar sign | Written twice, which is how compose keeps a real dollar sign. You are told which values changed; **Undo** puts it back. More in [making a password](passwords-and-hashes.md). |

Anything StaXX had to invent — a folder path the app never gave, a port with no host side named, a
fixed address it is keeping unchanged — is filled in as a placeholder and named separately, so you
know it needs a look. Check an empty setting too, even where nothing was flagged: StaXX fills gaps
sensibly, but it does not know your network or your other containers.

## What cannot convert

A handful of `docker run` options have no compose equivalent, and StaXX says so rather than guessing:

| Cannot convert | Do instead |
|---|---|
| Old-style container linking | Put both containers in the same stack and refer to the other by name. |
| An alias on a named network | Nothing — the network has to already exist for this to mean anything. |
| Anything typed after a flag StaXX has never seen | Named exactly as typed, so you can decide whether it still matters. |

A catalogue entry describing more than one container is not stitched into a multi-service stack —
each app becomes one service. An option with a value StaXX cannot make sense of, such as an invalid
address, is dropped rather than written in broken.

## What this never does

- It never touches the original catalogue entry.
- It never contacts your server's Docker until you press Save.
- It never starts a newly added app on your behalf. Saving creates it stopped, like any new stack.

## Not built yet

- Adding several catalogue apps as one linked stack is not built. Each addition is one app, one
  editor session, one save.
- Checking whether a port or folder the app wants is already in use elsewhere on your server is not
  built.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
