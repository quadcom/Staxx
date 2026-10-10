# Versions tab

<!-- index: 9.5 | undoing an app's own update, reading a build's row, pinning a service to one exact build and releasing it again. | parent: the-stack-editor.md -->

[StaXX guide](README.md) › [The stack editor](the-stack-editor.md) › Versions tab

**Versions** undoes an app's own update. It sits in the [stack editor](the-stack-editor.md), beside
**Configure** and [**History**](editor-history.md).

## The short version

1. Open the stack. Click **Versions**.
2. Pick a service on the left. Its recorded builds appear on the right.
3. Find the build you want and press **Put this back**.

![The Versions tab: a list of services down the left with each one's image, and on the right the selected service's one recorded build with its date, fingerprint and a Running now mark](../images/guide/recovery-and-redundancy-versions-tab.png)

A row on the [stack list](the-stack-list.md) offering an update it can undo carries **Roll back…**
on its menu, which opens **Versions** with the right service already picked.

![A stack row's own menu open, with Roll back… outlined between Skip this version and Logs](../images/guide/recovery-and-redundancy-rollback-menu.png)

## A build's row

![One build's row in the Versions tab: the version name as its heading, then "1 minute ago" and the build's fingerprint, a "See the source" link, the "Commits in this build" list opened to show what went into it, and the "Put this back" button](../images/guide/recovery-and-redundancy-build-row.png)

| On the row | What it is |
|---|---|
| A heading | The build's version name, or its date where the publisher gave none. |
| Date and a fingerprint | Enough to tell two unnamed builds apart. |
| **See the source** | Where the image was published, when known. |
| What changed | The release notes, captured at the moment that build was pulled. |
| **Running now**, or **Put this back** | The build you're on has no button; every other one offers to return you to it. |

Where the publisher wrote no release notes, you get the raw list of commits that went into the
build instead.

## Put this back

Press **Put this back** and StaXX edits the compose file to name that exact build. The file it
replaced goes into **History**, so you can undo the change there.

The confirmation tells you a later pull will not move the service off this build on its own, and
that the version you are moving away from will not come back by itself.

Where the stack has an override file, read the confirmation carefully — an image set there can
override the pin. **Put this back** only ever writes the main compose file.

## Pinning and releasing

![The orange-edged "Pinned to" band above a service's builds, naming the pinned version, with the "Release this pin" button at its right end and the pinned build's own row beneath it](../images/guide/recovery-and-redundancy-pinned-band.png)

Naming an exact build is what "pinned" means — see the pin mark on
[the stack list](the-stack-list.md#row-marks). A pinned service shows **Pinned to …** with
**Release this pin** beside it, however the pin was made: **Put this back** above, the **Pinned**
choice in [choosing how a container updates](update-policy.md), or typed into the file by hand.

A pin is written into whichever file sets that service's image (the main file or its override), and
the value it replaced is kept in a comment on the same line, for example `# was nginx:1.25.3`.

Press **Release this pin** and StaXX opens a window listing the image's tags. The top row always
shows the tag it was pinned from, marked **(before the pin)**, together with any of **latest**,
**main**, **master**, **develop**, **dev**, **stable**, **beta**, **nightly** or **edge** the image
has. Below that, **Other rolling tags** and **Version numbers** each fold shut behind a count; tap
either heading to open it. Where no tags can be read, a box lets you type one instead.

![The Choose a tag for nginx window open over the editor: a top row with alpine (before the pin), then latest and stable; below it the folded headings Other rolling tags (11) and Version numbers (38); a box to type a tag, Use this tag, and Cancel](../images/guide/editor-versions-tag-picker.png)

Click a tag to put it into that service's image field on the **Configure** tab, and StaXX switches
you there. Press **Save** to keep it. Where an override file sets the image, the change is written
to that file at once instead.

The service keeps running on its current build until an update or a recreate brings the new tag in.

## Nothing recorded yet

A build is only recorded the first time StaXX updates that image. See [updates](updates.md).

## Rollback limits

Only a build StaXX itself recorded for a service can be restored to.

| Message | What it means |
|---|---|
| *"There is no earlier version recorded for this service, so it cannot be rolled back."* | Nothing has been recorded for it yet. |
| *"The previous version is no longer present on this server, so it cannot be rolled back to."* | Recorded, but the image has since been removed from the server while **Keep the images** is on. |
| *"This version is no longer available at the source, so it cannot be rolled back to."* | **Keep the images** is off, and the place the image came from no longer has that version. |

Two settings decide how much is available: how many
[previous versions of each image are remembered](settings-updates.md), and whether
[**Keep the images**](settings-storage.md) keeps them on the server or downloads them again when
you roll back.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
