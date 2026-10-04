# The stacks page

<!-- index: 5 | a walk round the page you land on: every button along the top, every part of a row, and every item in the menus. -->

This is the screen you land on. It lists every stack, one row each. This page walks it top to
bottom, part by part.

![The whole StaXX page: the title bar with its three chips top right, the Find a stack box and the row of buttons beneath it, then the column headings and a list of folder and stack rows](../images/guide/the-stack-list-whole-screen.png)

## The buttons along the top

![The row of buttons across the top: the Find a stack box, then the legend button, Select, Settings, Add, Check for updates, Update all and Pause updates, with Add ringed](../images/guide/the-stack-list-button-row.png)
| Button | What it does |
|---|---|
| The icon-only button beside **Select** | Opens the key to every mark and icon on the page. See [row marks and icons](marks.md). |
| Select | Switches the list into selection mode, to act on several stacks at once. |
| Settings | Opens the StaXX settings page. |
| Add | Opens a menu: **Add a blank stack**, **From Apps**, **Import**, **New folder** and **Merge stacks…**. See the table below. |
| Check for updates | Checks every image against its registry, right now. |
| Update all | Installs every update currently waiting. |
| Pause updates | Freezes every update countdown on the page. Press again to say **Resume updates**. |

The speech-bubble button in the bottom right corner of the page sends a bug report or an idea to the StaXX team. See [sending feedback](sending-feedback.md).

**Add** opens one menu for everything that ends in a new stack or folder:

| Item | What it does |
|---|---|
| Add a blank stack | Starts a new, empty stack. |
| From Apps | Opens the catalogue of ready-made apps. |
| Import | Brings in a container or project StaXX does not manage yet. |
| New folder | Creates a folder to group stacks in. |
| Merge stacks… | Combines two or more stacks into one. Needs at least two stacks and a desktop browser. |

## Notifications

A line to the left of that button row carries any message StaXX has for you: a check that could not
run or an update that failed. It stays empty until there is
something to say, and shows a count when more than one is waiting. Press it to open the full list.

Each entry can carry a button of its own for wherever it points, and a small **×** to dismiss it.
StaXX remembers what you have dismissed in this browser for a week before showing it again. A few
messages, such as Docker not running, cannot be dismissed and clear themselves once the problem is
fixed.

![The notice line to the left of the button row, ringed: a warning icon, one message about a check that could not reach the registry, and a count of 2 at its right end, with the Database folder and two of its stacks beneath](../images/guide/the-stack-list-notices-ticker.png)

![The Notifications panel opened under that line: two entries, each with an icon, its message, how long ago it arrived and a dismiss cross, one carrying a Check now button, and a Dismiss all button in the panel's footer](../images/guide/the-stack-list-notices-panel.png)

## The title bar

![The right end of the title bar: a grey chip saying when updates were last checked, an orange chip counting updates waiting, and an outlined chip counting author-example findings, with the button row beneath](../images/guide/the-stack-list-title-chips.png)

At the right end of the title bar, next to "StaXX", small tags say how the last check went. See
[update checking](updates.md) for what a check does.

| Tag | Meaning |
|---|---|
| Never checked / Checked N ago / Could not finish the last check | Whether a check has run yet, and how it went. |
| N updates waiting | How many services have something newer. Not shown when there is nothing waiting. |
| N author-example finding(s) | The author's own published example does something different somewhere. Press it to see where. |

## The columns

The column titles sit directly above each folder's own stacks, not once at the top of the whole
list. The loose stacks below the last folder get a row of titles of their own too.

![The column headings, Stack, Services, State, Address, CPU, Memory, Network and GPU, above two stacks, one running and one stopped](../images/guide/the-stack-list-columns.png)
| Column | What it shows |
|---|---|
| Stack | The stack's name, its icon, and its state marks. |
| Services | The service names inside it, or an error if the file will not read. |
| State | Running or stopped, plus any update or restart mark. |
| Address | Where the stack answers — an address, or a network name. |
| CPU | The stack's share of the whole processor, from 0 to 100%, with a small graph. |
| Memory | Memory use, with a small graph. |
| Network | Network traffic, with a small graph. |
| GPU | A coloured badge (Intel, AMD or NVIDIA\*) for a stack whose file asks for a graphics card, plus a use figure and small graph while it is running. The badge stays even when the stack is stopped. The column only appears when a stack on the page has one. |
| Ports | The ports Docker is actually forwarding, plus the one a service's web page answers on. A stack with its own network address, or on host networking, shows none. |

\* NVIDIA is coming in a later update.

## On a tablet or a phone

![The stack list on a tablet-sized window: two folder rows, then stacks as cards three across, each with its icon, name, state pill, address and small graphs](../images/guide/the-stack-list-cards.png)

Below desktop width, the list becomes cards instead of a table. Each card carries a stack's icon,
name, state, address and its little graphs. A folder becomes a heading over its own row of cards
rather than a row of its own. How many cards sit across depends on the width: three on a tablet held
sideways or a small laptop, two on a tablet held upright, one on a phone.

![The same folder on a tablet held upright: the Database heading, then its stacks as cards two across](../images/guide/the-stack-list-cards-two.png)

![The Database folder on a phone: the heading gives its name, its update marker and its "6 stacks, 5 running" line the full width of the screen, with its six app icons on a line of their own beneath; then its stacks one card per row, each with the WebUI, Logs, Repo and CA buttons on a line of their own at a finger's size and the running chip on the line beneath them](../images/guide/the-stack-list-cards-phone.png)

On a phone, a card's four small buttons take a line of their own at a size a finger can hit, with
the state chips on the line beneath them.

The folder heading changes shape here too. Its name, its update marker and its "N stacks, N running"
line take the full width, with the icons of the apps inside it on their own line underneath rather
than in a column beside them.

A stack running more than one service shows a cubes button in its corner. Tap it to see those
services as cards of their own.

![A row for a stack running more than one service, with the cubes button outlined at its left edge](../images/guide/the-stack-list-cubes-button.png)

![A stack's services opened from its cubes button: a small window over the card grid, headed with the stack's name and "2 services", holding one card per service with its state, image and ports](../images/guide/the-stack-list-cards-services.png)

## Stack row parts

| Example | Part | What it does |
|---|---|---|
| <img src="../images/guide/the-stack-list-row-handle.png" alt="A stack row close-up with the drag handle at the far left ringed"> | Drag handle | Drag the row to move it. Greyed out when there is nothing to move it against. |
| <img src="../images/guide/the-stack-list-row-picture.png" alt="The same row with the app picture ringed, a green dot on its lower corner"> | App picture | Click it to open the stack for editing. The dot on its corner is green when the stack is running, red when a container inside says it is unwell. |
| <img src="../images/guide/the-stack-list-row-chips.png" alt="The same row with the block of four chips, WebUI, Repo, Logs and CA, ringed"> | Four chips | Shortcuts out to the app and its project. See the table below. |
| <img src="../images/guide/the-stack-list-row-name.png" alt="The same row with the stack name ringed, a small orange bolt beneath it"> | Name | The folder this stack lives in, which is also its name. The small bolt under it means it starts when the server boots. |

Two more parts are only on a stack holding more than one container. A cubes button left of the app
picture opens and closes its container list. A line under the name counts its containers.

![The buildbox stack opened with its cubes button: its own row with both app pictures, 2 containers under the name and a running chip, then one indented row each for buildbox and buildbox-web with their image, state and address, between the NPM, homarr and vaultwarden rows](../images/guide/the-stack-list-row-expanded.png)

Each container gets its own row under the stack, with its image, state and address.

To open a stack's menu, right-click its row.

### The four chips

| Chip | Opens |
|---|---|
| WebUI | The app's own web page. Available only when the app has one and is running. |
| Logs | This stack's log output. Always available, even when stopped. |
| Repo | The project's own page, when known. |
| CA | The support or discussion thread for the app, when known. |

## Row marks

Three of these are the same orange triangle. What tells them apart is where it sits and what it says
when you hover it. See [row marks and icons](marks.md) for the full key.

| Example | Mark | Meaning |
|---|---|---|
| <img src="../images/guide/the-stack-list-mark-pin.png" alt="A white drawing pin under a stack name"> | Drawing pin, under the name | One or more services is held at one exact build. Hover it to see which. |
| <img src="../images/guide/the-stack-list-mark-triangle.png" alt="A small orange warning triangle under a stack name"> | Orange triangle, under the name | Either the stack was imported and its original has changed since, or a service is on a network that gives it its own address, so the ports in its file do nothing. Hover it to see which. |
| <img src="../images/guide/the-stack-list-mark-image-mismatch.png" alt="An orange warning triangle under the image name in the Services column"> | Orange triangle, under the image name | The file asks for a different image than the one running. Restart to apply it. |
| <img src="../images/guide/the-stack-list-broken-stack.png" alt="A stack row with a red warning triangle where the app picture would be"> | Red triangle in place of the app picture | Compose cannot read this stack's file, or there is no file at all. |
| <img src="../images/guide/the-stack-list-mark-needs-review.png" alt="An orange outlined tag reading needs review beside a stack name"> | needs review | Imported and not checked over yet. It will not start on its own. |
| <img src="../images/guide/the-stack-list-mark-waiting-to-confirm.png" alt="An orange outlined button reading waiting to confirm beside a stack name"> | waiting to confirm | Just switched over from an older container. Press it to say whether the app works. |

## The State column

The State column shows whether the stack is running, with an update chip beside it when there is
something to report. Every chip, its colour and what to do about it is listed in
[row marks and icons](marks.md), along with what a soft orange or soft red row means.

Hover an update pill, or tab onto it with the keyboard, for a small card with more detail: the
version running now, the version on offer, when it was last checked, when it is next due, and why
it is checked that often.

![The amber 8.37.0 update chip on the paperless-gotenberg row with its hover card open: a sentence saying a newer version is available, then Running 8.36.0, Available 8.37.0, Last asked, Next check, how often it is checked and why](../images/guide/the-stack-list-hover-card.png)

When the file has been saved but the stack not yet restarted, an amber chip with a circular arrow
appears beside the state. Click it to see which services no longer match the file. Press **Recreate now** to rebuild
only those services. The others keep running.

![The demo-web restart pending window over the dimmed list: when the file was last changed, the line saying nginx no longer matches the file, the sentence that Recreate now rebuilds only these services, and Close and Recreate now at the foot](../images/guide/the-stack-list-restart-pending.png)

An amber **name clash** pill appears here when a stack's folder shares a name with another stack
elsewhere in your store. Hover it to see the other folder's name; the stack that is actually running
keeps its real state here. Rename one of the two stacks, or remove the one you no longer use. Give
every new stack a name not already used elsewhere in your store: StaXX refuses to create, rename,
move or import one under a name already in use.

![The amber name clash pill in the State column, outlined, with its tooltip open](../images/guide/the-stack-list-name-clash.png)

## The row menu

![The whole stack menu open, in two columns: the stack name across the top; on the left Start, a greyed-out Stop, Recreate with the hint Rebuilds every container, Update, Pull images, Check this image again, Skip this version, What changed, Logs, then Edit compose file, Fill in details, Export, then an Autostart switch, a Delay box, the Updates row with Default ticked, the New image found, Image installed and Installation failed switches with their Default line, What do these marks mean, and Remove stack; on the right the Move to folder list, New folder and Remove from folder](../images/guide/the-stack-list-row-menu.png)
Right-click the row to open it. Items appear in this order, and only when they apply, in two
columns: what you can do to the stack on the left, where it lives on the right.

| Group | Items |
|---|---|
| Waiting to confirm only | It works, It does not work |
| Needs review only | Take over and start, Clear the lock only |
| Running stack | Take over and start (only if something outside StaXX holds its name), Start/Restart, Stop, Recreate, Update, Pull images, Check this image again |
| If an update is waiting | Resume the countdown or Cancel the countdown, Skip this version, What changed |
| If a tag was withdrawn | Fix the tag… |
| Always | Logs |
| Has a file | Edit compose file, Fill in details…, Export… |
| Has no file | Start a compose file here |
| Folders | Move to folder (a list), New folder…, Remove from folder (if filed). Unlock Unraid's padlock to see these. |
| Boot | Autostart (on/off switch), Delay |
| Updates | Updates — Pinned/Default/Manual/Automatic on a container's own menu, or on a stack's own menu when it holds only one container; Default/Manual/Automatic when it holds several — then a Notifications box with three switches, New image, Image installed and Installation failed, each on or off, starting at the server's own answers until you change one. A pinned service has one switch instead, **Remind me it is still pinned**. See [choosing how a container updates](update-policy.md). |
| Profiles | One switch per profile the file declares. See below. |
| Reference | What do these marks mean? |
| Last | Remove stack |

**Recreate** rebuilds every container in the stack from the file as it stands now.

### Profiles

A file can group optional services into a **profile**, off unless you switch it on. When a file
declares one or more, a **Profiles** group appears under Autostart and Delay: one switch per
profile, off by default, with a note underneath saying a service tagged with a profile that is off
will not start.

Switch one on and StaXX includes it the next time you start, stop, restart, update or recreate the
stack.

![A stack's row menu with the Profiles group outlined: the Profiles heading, one switch labelled extras, and the line saying a service tagged with a profile that is off will not start](../images/guide/the-stack-list-profiles-menu.png)

### A service's own menu

Right-click a service row inside an expanded stack for a menu scoped to that one container. Most
items match the stack menu above, at container scope, plus two of its own:

| Item | What it does |
|---|---|
| Rebuild | Only for a container built here, once its base image has moved on. Pulling will not fetch that, so it has to be built again. |
| Test web page | Fetches the service's own web page, right now, and says whether it answered. |

Autostart and Delay work the same way here as on the stack menu, but apply to this one service only.

## Folders

![A collapsed folder row named Media, reading 16 stacks and 12 running, with the row of small app pictures for everything inside it ringed](../images/guide/the-stack-list-folder-row.png)

![The folder menu open: Start everything, Stop everything, Check this folder, Update this folder, Rename folder, Folder icon…, Remove icon, Delay and Delete folder](../images/guide/the-stack-list-folder-menu.png)
A folder row shows totals for everything filed inside it in place of its own Services, State and
Address, plus small icons for every stack it holds.

Click its picture to open the folder menu.

| Item | What it does |
|---|---|
| Start everything | Starts every stack in the folder. |
| Stop everything | Stops every stack in the folder. |
| Check this folder | Checks every image in the folder for updates. |
| Update this folder | Installs every update waiting in the folder. |
| Rename folder | Renames it in place. |
| Folder icon… | Opens the icon picker so you can put a picture beside the folder's name. See [Folders](folders.md). |
| Remove icon | Puts the plain folder back. Shown only when the folder has an icon. |
| Delay | How long to wait before the next thing starts. |
| Delete folder | Deletes the folder. Stacks inside are moved back to the top level first, not deleted. |

## Acting on several stacks at once

Press **Select** on the button row to switch the list into selection mode. Every stack row and every
folder header gains a switch at its left edge, the same on/off glyph used for Autostart elsewhere.
Switching a folder on or off switches every stack inside it; a folder with only some of its stacks on
shows its switch on but dimmed.

![The list in selection mode: the DEV-TESTING folder header with its switch on but dimmed, two stacks beneath it switched on and a third off, and the bar at the foot saying 2 stacks chosen, in the order shown, with Start, Stop, Restart, Check for updates and Update](../images/guide/the-stack-list-selection-mode.png)

A row of buttons slides open under **Select** once something is chosen: **Start**, **Stop**,
**Restart**, **Check for updates**, **Update**, **Updates…** and **Notifications…**.

**Updates…** and **Notifications…** open a small window under that row with the same controls the
editor has. Choose **Default**, **Manual** or **Automatic** for when updates install — not
**Pinned**: this window can set several services at once, and a pin fixes each one to its own build
— with **Immediate** or **Delayed** under Automatic; or set the three notification switches, which
start from the server's own answers, then press **Apply**. The update choice is written to every
service in every chosen stack except one already pinned to a build, which is left alone with no
message. The notification switches go to every chosen service. The window reports how many stacks
changed and names any that refused.

When your selection holds a pinned service, **Notifications…** adds a fourth switch, **Remind me it
is still pinned**, with a line saying how many pinned services it applies to. It is written to those
services only.

Press a button and each chosen stack runs on its own row, exactly as it does anywhere else on the
page. One stack failing does not stop the rest, and the bar keeps a running tally as they finish,
then a final line naming anything that failed.

It runs the stacks in the order shown on the list. Put a stack that another depends on, such as a
database before the app that uses it, earlier in the list, or start them one at a time.

Press **Select** again, or the Escape key, to leave selection mode. Every switch clears; nothing is
remembered between visits.

### Finding a stack by name

A search box sits on the button row, reading *Find a stack… (press /)*. Press **/** anywhere on the
page, outside a text box, to jump straight to it.

![The search box with "postgres" typed in it and a dropdown beneath listing two matches: postgresql17 in the Database folder, and PenPot_Complete matched through its penpot-postgres service](../images/guide/the-stack-list-find.png)

Type part of a name and a dropdown opens beneath the box: one line per match, showing the stack's
icon and name, its folder in grey, and, when the match was not the stack's own name, the service,
container or image that matched instead. Press Enter to open the first match, use the arrow keys to
move between them, or click one directly. Any of these opens that stack for editing, the same as
clicking its picture. Escape closes the dropdown and clears the box.

The list itself does not change while you search. When nothing matches, the dropdown says **Nothing
called that**.

## Broken stacks

| Example | What it means |
|---|---|
| <img src="../images/guide/the-stack-list-no-compose-file.png" alt="A stack row with a red warning triangle for its picture and a red outlined button reading No compose file in this folder"> | This folder has no compose file. Click the red button to start one. |
| <img src="../images/guide/the-stack-list-broken-stack.png" alt="A stack row with a red warning triangle for its picture, the words Compose cannot read this file, and the reason underneath"> | The file exists but will not parse. The message underneath says why. |

Fix the file and save it to bring either row back to normal.

Give a stack a network that exists on this server before you start it. If it names one marked as
already existing that this server does not have, StaXX shows the missing network's name and the
nearest one you do have, for example:

*This stack needs a network called "eth0.2", and this server has no network by that name. The
nearest is "br0.2". Open the stack and change its network, then try again.*

**Pull images** still works. The editor offers the same fix as a button. See
[the stack editor](the-stack-editor.md#messages-you-may-see).

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
