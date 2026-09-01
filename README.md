<p align="center"><img src="Logo/staxx-lockup-1024.png" alt="StaXX" width="420"></p>

# StaXX — Docker on Unraid, built on compose files

<!-- dev-banner -->
> [!CAUTION]
> ## Development branch — the bleeding edge, not the stable release
>
> This is where work in progress lands. Features here may be half-finished, broken, or in the middle
> of being changed, and the text and screenshots below may describe things that do not work yet.
>
> You *can* install this — but take it only if you want to see things early and do not mind the
> occasional rough edge. For the stable release, read [`main`](../../blob/main/README.md).
>
> You are on one channel at a time. Going back to stable means pasting main's address into
> **Install Plugin** yourself: it will never appear as an available update, because a development
> build always sorts as the newer of the two.
>
> ### To install this branch
>
> **Every install address further down this page is the stable one.** This is the address for
> *this* branch — paste it into Unraid's **Plugins → Install Plugin** box:
>
> ```
> https://raw.githubusercontent.com/quadcom/Staxx/dev/staxx.plg
> ```
>
> **The user guide for this branch** is at
> **[quadcom.github.io/Staxx/dev](https://quadcom.github.io/Staxx/dev/)** — it describes what is
> here, rather than what is in the release. The [stable guide](https://quadcom.github.io/Staxx/)
> covers the release instead.
>
> **A new build goes out most nights.** Whenever there is new work here, a release is cut
> automatically overnight, and your server will offer it to you like any other plugin update. That
> is the point of this channel — but it does mean updates arrive often, and each one carries whatever
> landed that day. Quiet days produce nothing at all.
<!-- /dev-banner -->

> **Where this is up to.** In daily use on the author's own server and far enough along to judge on
> its merits. Not on Community Applications, and not meant to be yet — but numbered releases do
> install the ordinary Unraid way. See [Limitations](#limitations).
>
> Version 00.01.00 · [changelog](CHANGELOG.md)

## Why this exists

Unraid describes containers in its own template format. Almost nothing else does. When a project
publishes its setup — and nearly all of them publish a **[compose file](https://compose-spec.io/)**
— you cannot just use it: you retype it into a template, field by field, and hope you read it right.

The obvious fix is to run compose files directly. That works right up until you want to change a
port, and find yourself editing indented text where one stray space breaks the file.

StaXX takes the compose file as it is and draws it as a form. Change the port in a box. The file
underneath stays an ordinary compose file that still runs anywhere.

![The stacks page](docs/images/stacks-overview.jpg)

## What it does

- **Paste in a project's own compose file and it runs** — no retyping into a template, no
  conversion step.
- **Never read a line of it if you don't want to.** Twenty-odd groups of settings — ports, folders,
  variables, health checks, start-up order — as ordinary form fields, with the file beside them if
  you prefer. The author's own comments become the help text next to each box.
- **Change things without the fear.** There is an Undo button, every save is kept, and every image
  that has actually run is kept, so a bad decision is one click back.
- **Bring in what you already run.** One window lists your existing Unraid apps, Compose Manager
  projects and loose containers, and imports them as copies — the originals stay put, and one click
  puts them back.
- **Search 3,700 apps without leaving the page.** Community Applications, Docker Hub and the images
  already on your server; pick one and get a compose file to look over before anything starts.

## The 60-second start

Copy this address:

```
https://raw.githubusercontent.com/quadcom/Staxx/main/staxx.plg
```

In Unraid, go to **Plugins → Install Plugin**, paste it in, and press Install. That is the whole of
it. You get a plugin Unraid looks after like any other: it survives a reboot, it appears on your
Plugins page, and it will tell you when there is a newer version.

You do not need Community Applications for this, and StaXX is not in it — see
[Limitations](#limitations).

Open **Docker → Stacks**. The first thing it does is ask where it should keep its data — one folder
holding your stacks, the zips of any you remove, and its own settings. It suggests a sensible place
and explains the choice; take the suggestion if you are in a hurry.

After that, make a folder inside the `stacks` folder of the place you chose, drop a compose file in
it, and it is there. Or press **Apps** and pick something.

New to the page? The [user guide](docs/guide/README.md) explains what you are looking at.

To remove it again, use **Remove** on the Plugins page. Your settings and your stacks are left
alone; deleting the data store folder is a separate, deliberate act.


## A tour of what's here

Every item below has its own page in the [user guide](docs/guide/README.md) — enough here to see
whether StaXX does what you need.

- **The stacks page** shows every container, with live figures, health and update state at a
  glance — see [what every mark means](docs/guide/marks.md).
- **The editor** shows one file three ways — form, file, or both side by side — with the author's
  comments turned into help text; see [editing a stack](docs/guide/editing-a-stack.md).
- **Health checks** can be worked out for you: where nothing is watching a running container, StaXX
  finds a real question to ask that image, tries it inside the container first, and tells you what
  the answer would actually prove — see
  [letting StaXX work out a health check](docs/guide/marks.md#letting-staxx-work-out-a-health-check).
- **One house layout** puts every file in the same order as it arrives, so your stacks all read the
  same way. Order only — nothing you wrote is rewritten, and comments stay with what they annotate;
  see [tidying a file](docs/guide/editing-a-stack.md#tidying-a-file-into-staxxs-layout).
- **Adding an app** searches Community Applications, Docker Hub and your own server's images, and
  hands you a compose file to check before anything starts; see
  [adding an app](docs/guide/installing-an-app.md).
- **Starting from scratch** gives you a working skeleton from nothing but a name; see
  [making a stack from scratch](docs/guide/making-a-stack.md).
- **Bringing in what you already run** copies your existing Unraid apps, Compose Manager projects
  and loose containers, leaving the originals untouched; see
  [bringing in a container](docs/guide/bringing-in-a-container.md).
- **Folders** group stacks that belong together and can be started, stopped or updated as one; see
  [working with folders](docs/guide/folders.md).
- **Passwords and their scrambled form** are generated for you, in the box that needs them; see
  [passwords and hashes](docs/guide/passwords-and-hashes.md).
- **Hiding your values** blanks out anything marked secret so a stack can be screenshotted safely;
  see [hiding your values](docs/guide/hiding-your-values.md).
- **Checking for updates** compares your images against their registries on a schedule you set; see
  [checking for updates](docs/guide/updates.md).
- **Going back** keeps every saved file and every image build that has run, so a bad change is one
  click undone; see [going back](docs/guide/going-back.md).
- **Sharing a stack** exports a copy with your passwords, keys and paths taken out, ready to drop
  onto somebody else's StaXX; see [sharing a stack](docs/guide/sharing-a-stack.md).
- **The settings panel** is where almost everything about StaXX lives — where it appears, where your
  data is kept, icons, updates and sign-ins; see [the settings panel](docs/guide/settings.md).

## What it promises

1. **Never require non-standard syntax.** A file StaXX writes runs unmodified anywhere Docker Compose
   does.
2. **Never lose what the author wrote.** Comments, shortcuts, values and intent survive an edit.
   A file that is genuinely wrong gets fixed, and you are told what changed.
3. **Degrade gracefully.** A file with no extras still produces a usable form.
4. **Own the render.** Layout and controls are drawn by StaXX, not injected into another page.

## Limitations

- Pre-alpha, and not in Community Applications — you install it from its address; Unraid still
  tells you when a newer version is out.
- Requires Unraid 7.2 or later, and Docker Compose already on the server — StaXX does not install it.
- Graphics figures cover Intel and AMD cards; Nvidia shows none, though a container given an Nvidia
  card is still labelled as such.
- Without a Docker Hub read-only token, update checks are limited to roughly ten images an hour.
- A stack has to be started by hand once before it can start at boot.
- Getting an archived stack back means unzipping it yourself.

---

## Configuration (optional)

Everything here has a switch on the settings page, so this is reference only. Settings live in
`staxx.cfg` in your data store, except three lines that stay on the flash drive so they are readable
before the array starts — see [where StaXX keeps its things](docs/guide/where-things-live.md).

| Key | Default | What it does |
|---|---|---|
| `STORE_ROOT` | *(blank)* | The one folder StaXX keeps everything in — stacks in `stacks`, removed ones zipped into `archives`. Blank means you have not chosen yet. |
| `HEADER_MENU` | `false` | `true` gives StaXX its own button in the top bar instead of a tab under Docker. |
| `TAKEOVER_DOCKER_TAB` | `false` | `true` replaces the Docker button entirely. No stock Unraid file is modified either way. |
| `CATCH_INSTALLS` | `true` | What happens when something is installed from Unraid's own Apps page: `true` brings it in as a stack, `prompt` asks first, `false` leaves it to Unraid. |
| `ICON_FETCH` | `true` | Fetch container logos. Only the icon's name is sent. |
| `ICON_ADOPT` | `true` | Record a matched logo in the compose file so it travels with it. |
| `IMAGE_LOOKUP` | `true` | Read an image's documentation when adding it, for a fuller starting file. |
| `WATCH_EXAMPLES` | `true` | Compare your file with the publisher's own example during an update check. |
| `SHELL_ENABLED` | `true` | The root command line inside a container. `false` removes it everywhere. |
| `UPDATE_CHECK` / `UPDATE_CHECK_TIME` | `daily` / `04:00` | How often to look, and when. `off` never looks. |
| `UPDATE_MODE` / `UPDATE_DELAY_HOURS` | `notify` / `24` | `off` shows only, `notify` says so, `auto` installs after the delay. Any stack can overrule this in its own file. |
| `UPDATE_WINDOW` + `_START` / `_END` | `true`, `03:00`–`05:00` | Only install automatically inside a quiet window. |
| `UPDATE_NOTIFY` | `off` | Unraid notifications: `found`, or `applied` as well. |
| `UPDATE_RETAIN` | `2` | How many previous builds of each image to keep for a rollback. |
| `UPDATE_CLEANUP` | `off` | `weekly` removes images nothing uses and no history needs. |
| `HUB_USER` / `HUB_TOKEN` | *(blank)* | A Docker Hub read-only token. Without one, checks are limited to roughly ten images an hour. |

`CRYPT_MODE` decides whether the password-hashing helper stays running (`always`) or starts only
when needed (`ondemand`). The `PWGEN_*` keys are the password generator's own remembered choices,
set from its panel rather than here. Comments in this file must start with a semicolon.

---

## For contributors

The plugin is plain PHP and browser JavaScript with no client-side libraries, talking to the page
through a single endpoint. `src/staxx/` mirrors the install paths on the server; `docs/` and
`schema/` describe the file format StaXX reads and writes.

## Licence

GPL-2.0, matching `unraid/webgui`.

## Prior art

Studied while scoping this. None of their code is used here.

- [Compose Manager Plus](https://github.com/mstrhakr/compose_plugin) — compose engine, header-menu
  toggle
- [FolderView3](https://github.com/chodeus/folder.view3) — collapsible grouping
- [unraid-plugin-composerize](https://github.com/llalon/unraid-plugin-composerize) — template →
  compose conversion
