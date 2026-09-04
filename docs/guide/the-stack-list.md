# The stacks page

<!-- index: 5 | a walk round the page you land on: every button along the top, every part of a row, and every item in the menus. -->

This is the screen you land on. It lists every stack, one row each. This page walks it top to
bottom, part by part.

![The whole StaXX page: the row of buttons top right, the update summary and storage hint below it, then the column headings and a list of folder and stack rows](../images/guide/the-stack-list-whole-screen.png)

## The buttons along the top

![The row of buttons across the top: Settings, Self-test, New folder, Apps, Import, Add stack, Check for updates, Update all and Pause updates, with Add stack ringed](../images/guide/the-stack-list-button-row.png)
| Button | What it does |
|---|---|
| Settings | Opens the StaXX settings page. |
| Self-test | Runs a quick health check on StaXX itself. |
| New folder | Creates a folder to group stacks in. |
| Apps | Opens the catalogue of ready-made apps. |
| Import | Brings in a container or project StaXX does not manage yet. |
| Add stack | Starts a new, empty stack. |
| Check for updates | Checks every image against its registry, right now. |
| Update all | Installs every update currently waiting. |
| Pause updates | Freezes every update countdown on the page. Press again to say Resume updates. |

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
list — a folder's own row has nothing to do with most of them. The loose stacks below the last
folder get a row of titles of their own too.

![The column headings — Stack, Services, State, Address, CPU, Memory and Network — above an opened folder and four of its stacks, two running with live graphs in the CPU, Memory and Network columns and two stopped showing dashes](../images/guide/the-stack-list-columns.png)
| Column | What it shows |
|---|---|
| Stack | The stack's name, its icon, and its state marks. |
| Services | The service names inside it, or an error if the file will not read. |
| State | Running or stopped, plus any update or restart mark. |
| Address | Where the stack answers — an address, or a network name. |
| CPU | Processor use, with a small graph. |
| Memory | Memory use, with a small graph. |
| Network | Network traffic, with a small graph. |
| GPU | A coloured badge (Intel, AMD or NVIDIA) for a stack whose file asks for a graphics card, plus a use figure and small graph while it is running. The badge stays even when the stack is stopped, so you can see which stacks have hardware in them without starting them. The column only appears when a stack on the page has one. |
| Ports | The ports Docker is actually forwarding, plus the one a service's web page answers on. A stack with its own network address, or on host networking, shows none — there is nothing else to forward. |

![The State and Address columns over two running stacks, each address followed by its reachable port in orange](../images/guide/the-stack-list-ports.png)

## On a tablet-sized window

![The stack list on a tablet-sized window: two folder rows, then stacks as cards three across, each with its icon, name, state pill, address and small graphs](../images/guide/the-stack-list-cards.png)

Below desktop width, the list becomes cards instead of a table — three across, one card per stack,
carrying its icon, name, state, address and its little graphs. A folder becomes a heading over its
own row of cards rather than a row of its own.

A stack running more than one service shows a cubes button in its corner. Tap it to see those
services as cards of their own, without the page underneath changing shape.

![A stack's services opened from its cubes button: a small window over the card grid, headed with the stack's name and "2 services", holding one card per service with its state, image and ports](../images/guide/the-stack-list-cards-services.png)

## Stack row parts

| Example | Part | What it does |
|---|---|---|
| <img src="../images/guide/the-stack-list-row-handle.png" alt="A stack row close-up with the drag handle at the far left ringed"> | Drag handle | Drag the row to move it. Greyed out when there is nothing to move it against. |
| <img src="../images/guide/the-stack-list-row-picture.png" alt="The same row with the app picture ringed, a green dot on its lower corner"> | App picture | Click it to open the stack for editing. The dot on its corner is green when the stack is running, red when a container inside says it is unwell. |
| <img src="../images/guide/the-stack-list-row-chips.png" alt="The same row with the block of four chips, WebUI, Repo, Logs and CA, ringed"> | Four chips | Shortcuts out to the app and its project. See the table below. |
| <img src="../images/guide/the-stack-list-row-name.png" alt="The same row with the stack name ringed, a small orange bolt beneath it"> | Name | The folder this stack lives in, which is also its name. The small bolt under it means it starts when the server boots. |

Two more parts are not on every row. An arrow left of the app picture opens and closes the container
list, on a stack holding more than one container. A line under the name says how many of them are
running.

To open a stack's menu, right-click its row.

### The four chips

| Chip | Opens |
|---|---|
| WebUI | The app's own web page, when it has one and is running. Greyed out otherwise, with a reason. |
| Logs | This stack's log output. Always available, even when stopped. |
| Repo | The project's own page, when known. |
| CA | The support or discussion thread for the app, when known. |

## Row marks

Three of these are the same orange triangle. What tells them apart is where it sits and what it says
when you hover it. See [row marks and icons](marks.md) for the full key.

| Example | Mark | Meaning |
|---|---|---|
| <img src="../images/guide/the-stack-list-mark-pin.png" alt="A small grey drawing pin under a stack name"> | Drawing pin, under the name | One or more services is held at one exact build. Hover it to see which. |
| <img src="../images/guide/the-stack-list-mark-triangle.png" alt="A small orange warning triangle under a stack name"> | Orange triangle, under the name | Either the stack was imported and its original has changed since, or a service is on a network that gives it its own address, so the ports in its file do nothing. Hover it to see which. |
| <img src="../images/guide/the-stack-list-mark-image-mismatch.png" alt="An orange warning triangle under the image name in the Services column"> | Orange triangle, under the image name | The file asks for a different image than the one running. Restart to apply it. |
| <img src="../images/guide/the-stack-list-broken-stack.png" alt="A stack row with a red warning triangle where the app picture would be"> | Red triangle in place of the app picture | Compose cannot read this stack's file, or there is no file at all. |
| <img src="../images/guide/the-stack-list-mark-needs-review.png" alt="An orange outlined tag reading needs review beside a stack name"> | needs review | Imported and not checked over yet. It will not start on its own. |
| <img src="../images/guide/the-stack-list-mark-waiting-to-confirm.png" alt="An orange outlined button reading waiting to confirm beside a stack name"> | waiting to confirm | Just switched over from an older container. Press it to say whether the app works. |

## The State column

| Example | Meaning |
|---|---|
| <img src="../images/guide/the-stack-list-pill-running.png" alt="A green pill reading Up 5 minutes"> | Running. The wording comes straight from Docker. |
| <img src="../images/guide/the-stack-list-pill-unhealthy.png" alt="A red pill reading Up 10 minutes"> | Running, but the app inside says it is not working. |
| <img src="../images/guide/the-stack-list-pill-deciding.png" alt="An amber pill reading Up 8 seconds"> | Running. Its own check has not finished deciding yet. |
| <img src="../images/guide/the-stack-list-pill-stopped.png" alt="A grey pill reading stopped"> | Not running. |
| <img src="../images/guide/the-stack-list-pill-not-created.png" alt="A grey pill reading not created"> | Never started from the file yet. |
| <img src="../images/guide/the-stack-list-pill-busy.png" alt="An orange dashed outline pill reading Updating"> | A command is running on this row. It also says Starting, Stopping, Removing or Rebuilding — or, on a first start, Downloading image…, while the image itself is still being fetched. |
| <img src="../images/guide/the-stack-list-pill-failed.png" alt="A red pill reading Update failed"> | The last command failed. Click it to see what happened. |

An amber **name clash** pill turns up here when a stack's folder shares a name with another stack
somewhere else in your store. Docker can only ever run one of them, so hovering it names the other
folder — the one that is actually running keeps its real state, and its stopped twin is not
secretly holding anything hostage. Rename one of them, or remove the one you are not using; StaXX
also now refuses to create, rename, move or import a stack under a name already in use elsewhere,
so a fresh clash should not happen again by accident.

Beside it, an update pill, when there is something to say. Nothing at all is shown when nothing
newer was found, or when nothing has been checked yet.

| Example | Wording | Meaning |
|---|---|---|
| <img src="../images/guide/the-stack-list-pill-update-ready.png" alt="An orange pill reading 1.2.3 arrow 1.2.4"> | "update ready", or an old and new version, or a version and "new build" | An update is ready. Press it to install. |
| <img src="../images/guide/the-stack-list-pill-updates-several.png" alt="An orange pill reading 3 updates ready"> | "N updates ready" | More than one service here has one waiting. |
| <img src="../images/guide/the-stack-list-pill-rebuild-ready.png" alt="An orange pill reading rebuild ready"> | rebuild ready | Built here, and its base image has moved on. |
| <img src="../images/guide/the-stack-list-pill-built-here.png" alt="A grey pill reading built here"> | built here | Built on this server. Nothing to compare it to. |
| <img src="../images/guide/the-stack-list-pill-not-installed.png" alt="A grey pill reading not installed"> | not installed | Named in the file but never pulled. |
| <img src="../images/guide/the-stack-list-pill-tag-withdrawn.png" alt="A grey pill reading tag withdrawn"> | tag withdrawn | This tag no longer exists at the registry. |
| <img src="../images/guide/the-stack-list-pill-registry-moved.png" alt="A grey pill reading registry moved"> | registry moved | The image now lives somewhere else. |
| <img src="../images/guide/the-stack-list-pill-to-look-at.png" alt="A grey pill reading 3 to look at"> | N to look at | The author's own example does something different here. |
| <img src="../images/guide/the-stack-list-pill-could-not-check.png" alt="A red outlined pill reading could not check"> | could not check | The last check failed. |

Hover an update pill, or tab onto it with the keyboard, for a small card with more detail: the
version running now, the version on offer, when it was last checked, when it is next due, and why
it is checked that often. A newly-added image can be worth checking every hour; a settled one only
once a week.

![An update pill with its hover card open: a sentence saying a newer version is available, then rows for last asked, next check, how often it is checked and why](../images/guide/the-stack-list-hover-card.png)

A chip appears when the file has been saved but not yet restarted. Nothing is broken until you press
it.

<img src="../images/guide/the-stack-list-chip-restart.png" alt="A grey chip reading Restart to apply, with a circular arrow">

## The row menu

![The whole stack menu open: the stack name at the top, then Start, a greyed-out Stop, Update, Pull images, Check this image again, Logs, Edit compose file, Fill in details, Export, the list of folders to move it to, New folder, Remove from folder, an Autostart switch, a Delay box, What do these marks mean, and Remove stack](../images/guide/the-stack-list-row-menu.png)
Click the app picture to open it. Items appear in this order, and only when they apply.

| Group | Items |
|---|---|
| Waiting to confirm only | It works, It does not work |
| Needs review only | Take over and start, Clear the lock only |
| Running stack | Take over and start (only if something outside StaXX holds its name), Start/Restart, Stop, Update, Pull images, Check this image again |
| If an update is waiting | Resume the countdown or Cancel the countdown, Skip this version, What changed |
| If a tag was withdrawn | Fix the tag… |
| Always | Logs |
| Has a file | Edit compose file, Fill in details…, Export… |
| Has no file | Start a compose file here |
| Folders | Move to folder (a list), New folder…, Remove from folder (if filed) |
| Boot | Autostart (on/off switch), Delay |
| Reference | What do these marks mean? |
| Last | Remove stack |

### A service's own menu

Right-click a service row inside an expanded stack for a menu scoped to that one container. Most
items match the stack menu above, at container scope, plus two of its own:

| Item | What it does |
|---|---|
| Rebuild | Only for a container built here, once its base image has moved on. Pulling will not fetch that, so it has to be built again. |
| Test web page | Fetches the service's own web page, right now, and says whether it answered — without opening it yourself. |

Autostart and Delay work the same way here as on the stack menu, but apply to this one service only.

## Folders

![A collapsed folder row named Media, reading 16 stacks and 12 running, with the row of small app pictures for everything inside it ringed](../images/guide/the-stack-list-folder-row.png)

![The folder menu open: Start everything, Stop everything, Check this folder, Update this folder, Rename folder, Delay and Delete folder](../images/guide/the-stack-list-folder-menu.png)
A folder row has no Services, State or Address of its own — those columns show totals for everything filed inside it instead. In their place it shows small icons for every stack it holds, so a collapsed folder still shows what is inside.

Click its picture to open the folder menu.

| Item | What it does |
|---|---|
| Start everything | Starts every stack in the folder. |
| Stop everything | Stops every stack in the folder. |
| Check this folder | Checks every image in the folder for updates. |
| Update this folder | Installs every update waiting in the folder. |
| Rename folder | Renames it in place. |
| Delay | How long to wait before the next thing starts. |
| Delete folder | Deletes the folder. Stacks inside are moved back to the top level first, not deleted. |

## Broken stacks

| Example | What it means |
|---|---|
| <img src="../images/guide/the-stack-list-no-compose-file.png" alt="A stack row with a red warning triangle for its picture and a red outlined button reading No compose file in this folder"> | This folder has no compose file. Click the red button to start one. |
| <img src="../images/guide/the-stack-list-broken-stack.png" alt="A stack row with a red warning triangle for its picture, the words Compose cannot read this file, and the reason underneath"> | The file exists but will not parse. The message underneath says why. |

Neither row can be started until it is fixed. Fixing it and saving brings the row back to normal.

## What this never does

- It never starts, stops or updates anything without you pressing a button for it.
- It never invents a web page, project link or support thread for an app it does not recognise.
- It never restarts a container on its own just because a health check fails.
- It never deletes a folder's stacks when the folder itself is deleted.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
