<p align="center"><img src="../images/guide/staxx-lockup.png" alt="StaXX" width="360"></p>

# The StaXX guide

StaXX is the page where every container on your server lives, drawn as a form so you can change a
setting without editing a file.

This guide is a manual. It starts with a walk round each screen — every button, column, mark and
menu item named, with a picture of each. After that come the tasks: adding an app, bringing in a
container you already run, editing, updating, sharing, going back.

**Start with [your first run](first-run.md)**, the one screen you see before anything else. Then
[the stack list](the-stack-list.md): it is the screen you land on, and most of the
rest of the guide points back at it.

This guide is written in English only.

<!-- pages:start -->

- **[Your first run](first-run.md)** — the one screen you see before anything else: choosing where
  StaXX keeps its data, and what the choice means.
- **[The stacks page](the-stack-list.md)** — a walk round the page you land on: every button along
  the top, every part of a row, and every item in the menus.
- **[The stack editor](the-stack-editor.md)** — a walk round the window that opens when you open a
  stack: the header row across the top, its four tabs, and the buttons along the bottom.
- **[Configure tab](editor-configure.md)** — the three ways to see a stack's file, the form's
  sections, and what sits on a row inside them.
- **[The Manage tab](the-manage-tab.md)** — what the Manage tab is for, how to open it, and what
  each of its three panes — log, shell and file browser — does and refuses to do.
- **[History tab](editor-history.md)** — undoing your own edits to a stack's file, what the list of
  kept versions holds, naming one to keep it for good, and what to do when the compose file is
  missing.
- **[Versions tab](editor-versions.md)** — undoing an app's own update, reading a build's row,
  pinning a service to one exact build and releasing it again.
- **[Update checking](updates.md)** — answers what a check does, why images are asked about at
  different rates, what N to look at means, and how the countdown to an automatic install works.
- **[Choosing how a container updates](update-policy.md)** — letting one container update itself, or
  wait for you, and whether it is mentioned in update messages — set on its own page or from its
  row.
- **[Row marks and icons](marks.md)** — a quick key to every mark and colour on the stacks page:
  what each one means and what to do about it.
- **[File locations and the data store](where-things-live.md)** — what is in the data store, what is
  on the flash drive, how to move the store, and how to reach StaXX if you cannot get to its page.
- **[Editing a stack](editing-a-stack.md)** — changing a setting and saving it, tidying a file into
  StaXX's layout, being offered a health check, and ports on a container with its own address.
- **[Password generator and hashing tool](passwords-and-hashes.md)** — the Password button in a
  stack's editor: making a password or a passphrase, turning one into the scrambled form some apps
  ask for, and why a dollar sign is written twice in a compose file.
- **[Sanitise mode](hiding-your-values.md)** — the Sanitise tick that hides values marked secret
  while you photograph the editor, exactly what it leaves showing, what it switches off while it is
  on, and the one tab it cannot cover.
- **[Proxy and DNS](proxy-and-dns.md)** — giving an app a web address through Nginx Proxy Manager
  and a local DNS name in Pi-hole, and what the DNS mark on the stack list means.
- **[Folders](folders.md)** — grouping stacks on the list: making a folder, moving a stack in or
  out, running everything inside one at once, renaming, and deleting.
- **[Dashboard tile](dashboard-tile.md)** — putting a StaXX tile on Unraid's Dashboard: arranging
  folders and stacks on it, choosing folder icons, and using it.
- **[Create a new stack](making-a-stack.md)** — starting a stack with nothing but a name: the
  skeleton you are given, the settings offered as comments, every refusal and why, and the single
  folder that comes out of it.
- **[Install an app from Community Applications](installing-an-app.md)** — a step-by-step
  walkthrough of adding a catalogue app: what carries across, what needs checking before you start
  it, and why nothing exists until you save.
- **[Import an existing container](bringing-in-a-container.md)** — a walkthrough of Import, from
  pressing the button to opening the new stack and taking it over.
- **[Merging stacks into one](merging-stacks.md)** — joining two or more stacks that are really one
  application into a single brand new stack, step by step through the six screens of the Merge tool:
  picking, naming, the compose files, the settings and files, the suggestions, and the confirmation.
- **[Example: merging Tube Archivist](merging-tube-archivist.md)** — a worked example of the Merge
  tool: joining the three Tube Archivist apps from Community Applications into one stack, with every
  card you will see and the answer to give.
- **[Removing a stack](removing-a-stack.md)** — taking a stack off the list, what actually happens
  to it, and how to get it back.
- **[Export and import a stack](sharing-a-stack.md)** — how Export blanks your passwords and paths
  out of a copy, what it refuses to send, and what the other person has to fill in.
- **[Recovery and redundancy](recovery-and-redundancy.md)** — undoing your own edits or an app's own
  update from the stack editor's History and Versions tabs, and bringing every stack back if the
  data store is lost.
- **[Settings](settings.md)** — how to open the settings panel, what its seven tabs are, and how to
  save or cancel a change.
- **[General tab](settings-general.md)** — where StaXX appears, installing apps, and switching
  container shells and file browsing on or off.
- **[Storage tab](settings-storage.md)** — the Storage tab: the data store, moving it, checking your
  backup, flash-drive copies, image clean-up and archived stacks.
- **[Icons and images tab](settings-icons-and-images.md)** — the Icons and images tab: container
  icons, reading an image's documentation and StaXX fields.
- **[Updates tab](settings-updates.md)** — the Updates tab: the check schedule, the default action,
  install timing, notifications and the update-check activity table.
- **[Integrations tab](settings-integrations.md)** — Docker Hub sign-in, your own registries,
  StaXXCrypt, and connecting to Nginx Proxy Manager and Pi-hole.
- **[Self-test tab](settings-self-test.md)** — the health check on the Settings panel, and what its
  backup line does and does not tell you
- **[About tab](settings-about.md)** — the version of StaXX and Docker Compose you are running, the
  work by others StaXX uses, and the services it contacts
- **[Sending feedback](sending-feedback.md)** — How to report a bug, suggest a feature or suggest an
  improvement from inside StaXX, and what is sent with it.

<!-- pages:end -->

## Something wrong, or missing?

This guide is a work in progress. StaXX is still being built, and new sections are added here every
day, so some things may not have a page yet or may be described a step behind the screen.

Anything that is broken, confusing or not there yet goes on the feedback board at
<https://staxxfb.quadcom.ca> — including a page here that turned out to be wrong. It is read.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
