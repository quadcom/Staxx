# Configure tab

<!-- index: 7 | the three ways to see a stack's file, the form's sections, and what sits on a row inside them. -->

The Configure tab is where you change a stack's settings, either as a form or as the raw compose
file itself. It opens by default when you open a stack; see [the stack editor](the-stack-editor.md)
for the header row above it and the buttons along the bottom.

![The whole editor window in Split view: the header with the stack name and tools, the four tabs, the form on the left showing the Container and Ports sections, the compose file on the right, and the buttons along the bottom](../images/guide/the-stack-editor-whole.png)

## The three views

![The three view buttons — Form, Split and Compose — with Split selected](../images/guide/the-stack-editor-views.png)

Three buttons switch how you see the same file.

| View | Shows |
|---|---|
| Form | Only the form. |
| Split | The form on one side, the raw compose file on the other. |
| Compose | Only the raw file, as text. |

![The bar between the form and the compose file in Split view, outlined, with a small grip mark in its middle](../images/guide/the-stack-editor-divider.png)

In Split, drag the bar between the two panes to give one side more room, or double-click it to put
it back in the middle. Its position is remembered in your browser: the editor reopens where you
left it.

A file other than the compose file itself, such as a `.env` file, opens beside the form. With the
`.env` tab open, click a setting whose value uses one of its variables and the line that defines
that variable lights up, the same way the compose file's lines do.

Two orange buttons sit at the left of the file tab strip: **New file** adds a new, empty file to
the stack, and the upload button beside it adds a file from your computer. Every file's tab also
carries its own menu arrow. Press it to rename, download or delete that file.

![The file tab strip in the editor: the orange New file and upload buttons at the left, the compose.yaml and .env tabs, and the .env tab's menu open with Rename, Delete and Download](../images/guide/the-stack-editor-file-menu.png)

## The form, section by section

![The Stack section opened, showing its four groups — Networks, Volumes, Secrets and Configs — each with an add button on the right](../images/guide/the-stack-editor-stack-section.png)

The form opens with a **Stack** section — settings that belong to the whole file, not to one
service — then one section per service.

| Group | For |
|---|---|
| Networks | Named networks declared for the whole stack to share. |
| Volumes | Named volumes declared for the whole stack to share. |
| Secrets | Secrets declared for the whole stack to share. |
| Configs | Configs declared for the whole stack to share. |

![A service's section: its name with a pencil to rename it, its description, then the Container group with Image, Container name, Restart policy and Web page port, a Sections button, and the Ports group heading below](../images/guide/the-stack-editor-service-section.png)

Each service then gets its own set of groups. Press a service's **Sections** button to choose which
of them to show; a tick marks each one already showing.

Press the arrow beside a section's heading, or the heading itself, to fold the section away. Press
it again to open it. Every section except **Container** folds. StaXX remembers which kinds of
section you folded, in this browser, so folding **Ports** folds it on every stack you open.

![Two section headings in the editor: Updates folded, with its arrow pointing right, and Proxy and DNS open below it, with its arrow pointing down](../images/guide/the-stack-editor-fold.png)

| Group | For |
|---|---|
| Container | The image, the name and how it restarts. Always shown — every service must have it. |
| Updates | When to update this service, and who is told. Always shown. |
| Proxy and DNS | Shown only once Nginx Proxy Manager is set up in [Settings](settings-integrations.md). See [proxy and DNS](proxy-and-dns.md). |
| Networks | Which networks this service joins. |
| Ports | Which ports it publishes. On by default. |
| Volumes | Folders and files it shares with the server. On by default. |
| Variables | Environment variables. On by default. |
| Devices | Hardware devices handed to it. On by default. |
| Labels | Docker labels. On by default. |
| Health check | How Docker decides the container is working. |
| Resource limits | CPU and memory limits. |
| Build | Building the image here instead of pulling it. |
| Depends on | Which other services must start first. |
| Secrets | Secrets this service can read. |
| Configs | Configs this service can read. |
| Profiles | Which profiles start this service. |
| DNS servers | DNS servers to use instead of the server's own. |
| Extra permissions | Linux capabilities added. |
| Dropped permissions | Linux capabilities removed. |
| Internal ports | Ports open to other containers only, not published. |
| Variable files | Files environment variables are read from. |
| Logging | How this service's logs are kept. |
| Advanced | Anything else the file sets, with no better home. Always shown. |

## The Image box

![The Image box in the Container group holding mariadb:11.4, with its list open underneath: 11.4, latest and beta at the top, then the folded headings Other rolling tags (4), Version numbers (45) and On this server (104)](../images/guide/editor-configure-image-list.png)

Click the **Image** box, or start typing, to open its list. The usual choices sit at the top.
**Other rolling tags**, **Version numbers** and **On this server** are folded below them; click a
heading to open it. Type a colon and part of a tag to narrow every group. You can still type any
value yourself.

## The Updates box

![The Updates section of a service in the form: the When to update box with Pinned, Default, Manual and Automatic, Default ticked, and the Notifications box beneath it](../images/guide/editor-configure-when-to-update.png)

Each service's **Updates** group carries a **When to update** choice: **Pinned**, **Default**,
**Manual** or **Automatic**, in that order. Choosing **Pinned** fixes this service to the exact
build it is running now, and it is never checked for an update again. See
[checking for updates](update-policy.md) for what each choice does and how a pin is released.

## What sits on a row

![A Volumes group with two rows: the column captions above the boxes, a folder button to browse the server, a read and write dropdown, and a Notes box with its caption underneath](../images/guide/the-stack-editor-row-parts.png)

| Part | What it is |
|---|---|
| Note under a box | A sentence explaining what the file already says, or a warning about it. |
| Help mark | A small circled "i" beside a label. Click it for a sentence about that setting. |
| Remove | A cross at the end of a row. Removes that one entry. |
| Reorder grip | On a port row only. Drag it, or focus it and use the up and down arrow keys, to change the order ports are tried in. |
| "more settings" fold | A row's own extra, less common settings, folded away until opened. |
| Pickers | A small button beside a box, for choosing a value instead of typing it: **Choose a folder** browses the server, **Choose a timezone** picks one from a map, **Choose a device** lists the server's own devices. |

For how to change a setting and save it, see [editing a stack](editing-a-stack.md).

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
