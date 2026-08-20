# The `x-unraid` schema

**Status: version 1. The `x-unraid` blocks below describe a stack and its containers. What each
individual setting is for is written in the comment beside it, not here.**

New to the terminology? See the [glossary](glossary.md).

---

## In plain terms

A compose file tells Docker everything it needs. It tells a *person* almost nothing.

It will say a container uses port 8096. It will not say "this is the web interface, and it's the one
you'll want to change if something else is already using that number". It will say there's a setting
called `ADMIN_PASSWORD`. It won't say "hide this as you type it".

That missing information — friendly names, descriptions, which boxes matter, which are secret — is
exactly what makes Unraid's template form pleasant to fill in. This document defines where we keep
it.

It goes in two places, and which one depends on what is being described.

**Facts about the whole app or one container** — its name, its logo, a paragraph explaining what it
is, the address of its web interface — go in a section named `x-unraid`, inside the compose file
itself. The compose standard sets aside any section whose name starts with `x-` for extra
information that Docker must ignore, so adding it does not stop the file working anywhere else. It
is the one officially sanctioned place to put something like this.

**Facts about one setting** — what this port is for, that this box is a password — go in the
ordinary YAML comment beside that setting. See [Notes and markers](#notes-and-markers). A comment is
already the place a person writes down what a line is for, and the form simply reads what is there.

Three things worth knowing before the detail:

- **All of it is optional.** A compose file with none of this still produces a working form. The
  labels are just worked out from the file instead of written by a human, so it's plainer.
- **It describes the app, not your server.** Names and descriptions go here, because they mean the
  same thing on anyone's machine. Settings like "start this automatically on boot" do not — those
  are facts about *your* server and live in the plugin's own settings.
- **It is a published format.** Once people have it in their files, changing it breaks their files.
  So it carries a version number and new versions only add things.

The rest of this document is the exact format, and is aimed at someone writing this by hand or
building something that produces it.

---

## What it is for

A compose file says *what a container is*. It does not say *how to present that to a human*: which
port is "the web interface", which environment variable is a password that should be masked, which
volume is the one you actually need to set and which is fine at its default.

Unraid's XML templates carry that presentation metadata, which is why the template form is pleasant
to fill in. `x-unraid` carries the same information inside an ordinary compose file, using the
extension-field mechanism the compose spec provides for exactly this purpose. Compose ignores any
key beginning with `x-`; a file carrying this metadata still runs unmodified under plain
`docker compose up` anywhere.

**Every part of it is optional.** A compose file with no `x-unraid` at all still renders a form —
see [Inference](#inference-when-metadata-is-absent).

### The dividing line

`x-unraid` describes **the application**. It travels with the file and means the same thing on
anyone's server.

It does **not** describe *this server's* operational policy — autostart, start order delays, CPU
pinning. Those live in StaXX's own config, because they are facts about one machine, not
about the app. Putting them in the compose file would make a portable artefact quietly
machine-specific.

Deliberate omission, not an oversight. Adding keys later is backward compatible; removing them is
not.

---

## Where it lives

Two levels, both permitted by the compose spec:

```yaml
x-unraid:              # stack-level: describes the whole stack
  version: 1
  icon: jellyfin

services:
  jellyfin:
    image: jellyfin/jellyfin:10.10.3
    ports:
      - "8096:8096"    # the page you open -!R   ← per-setting notes live here
    x-unraid:          # service-level: describes one container
      overview: |
        Free software media system…
```

Both blocks describe *things* — a stack, a container. Neither describes a single setting; that is
what the comment on the setting's own line is for.

> **Read `x-unraid` from the file, not from `docker compose config`.**
>
> `docker compose config` is a command that prints a tidied-up copy of your compose file — every
> shorthand expanded, every setting resolved. Useful, but that printout leaves the `x-` sections out
> ([docker/compose#11528](https://github.com/docker/compose/issues/11528),
> [#9682](https://github.com/docker/compose/issues/9682)), and exactly what it drops varies between
> versions.
>
> This is a limitation of that one command's *output*. It does not touch your file — nothing removes
> `x-unraid` from the file itself, ever.
>
> So the two are used for different jobs. Metadata comes from **your actual file**, which is where
> the editor is reading and writing anyway. `docker compose config` supplies only the **resolved
> settings** — what the containers will really run with, after every shorthand and override is worked
> out. The two are matched up on the container side of each setting, which is the half that never
> changes; see [How the form finds a setting again](#how-the-form-finds-a-setting-again).

---

## Decisions already made

Settled, and recorded here so they are not reopened without new information.

**Metadata lives inside the compose file** (2026-08-09). Two alternatives were considered:

- **A comment block at the bottom of the file.** Rejected. Comments are the *first* thing lost when
  any program reads a YAML file and writes it back out — which is precisely why this project needs a
  special kind of parser in the first place. Putting the metadata in comments would place it in the
  most fragile part of the file, not the safest.
- **A separate companion file next to the compose file.** Rejected, though it was the stronger of
  the two: it would leave the compose file completely untouched. But metadata kept inside the file
  travels with it. Copy, back up, or share the compose file and the friendly form comes too. With a
  companion file, the copy arrives bare.

**There is no per-setting block, and notes live in comments** (2026-08-11). An earlier draft of this
document gave each service a `fields:` list, one entry per box on the form, each restating the port
or variable it described in order to hang a title on it. That is gone. Two reasons, and the first is
the fatal one:

- **It said the same thing twice.** A `fields:` entry naming `port: 8096` is a second copy of a port
  declared a few lines above it. Two copies of one fact can disagree, and this pair did so on the
  most ordinary edit there is: change a port in a text editor and the entry describing it is
  silently orphaned. Every rule about matching entries to reality, warning about ones that match
  nothing, and inventing boxes for things no entry claimed existed only to paper over that gap.
- **A note beside the line needs no matching at all.** It cannot be orphaned, because it is not
  pointing at anything — it is *on* the thing. Delete the line and the note goes with it. Move the
  line and the note moves too.

This does not reopen the comment-block idea rejected above. That proposal put the *whole* metadata
document into a comment at the bottom of the file, far from anything it described, on the assumption
that comments are safe to write into. This is the opposite: one sentence, on the line it is about,
in the place a person would already have written it. And comments are safe here for a reason that
was not true when the idea was first weighed — the editor keeps this file as its own lines and
rewrites only the span it is changing, so a comment is not something it has to remember to preserve.
It never touches it in the first place.

---

## Stack level

```yaml
x-unraid:
  version: 1                      # schema version. Optional, defaults to 1.
  icon: jellyfin                  # see "Icons" below; leave it out and one is found for you
  overview: |                     # markdown; shown in the stack's detail view
    Free software media system…
  category: MediaApp:Video        # Unraid's existing category taxonomy
  project: https://jellyfin.org
  support: https://forum.jellyfin.org
  readme: https://github.com/…#readme
  author: jellyfin
```

All keys optional.

A stack is named after its directory — `Media/jellyfin` is called "jellyfin" in the list, full
stop. There is no display-name override.

Service display order is the order services appear in the compose file — the editor preserves
document order, so no explicit ordering key is needed here. Dragging a service into a different
position does not change the file: that order is also the order the services start in at boot,
which is this server's own policy rather than the app's, so it is kept in StaXX's config with the
rest of it. See the dividing line above.

### Sections

Some parts of the form — Health check, Resource limits, DNS servers, and so on — can be switched
off from the form without deleting what was written there. Doing that moves the section into
`sections`, keyed first by service name and then by the compose key it came from:

```yaml
x-unraid:
  sections:
    web:
      healthcheck: '{"after":"image","lines":["healthcheck:","  test: [\"CMD\", \"curl\", \"-f\", \"http://localhost\"]"]}'
      ports: false
```

Each entry means one of three things:

| Written like this | Means |
|---|---|
| `ports: false` | Switched off deliberately, holding nothing. |
| `healthcheck: '{"after":"image","lines":[…]}'` | Switched off, holding the section's own lines — comments, indent and all — so switching it back on restores them exactly. `after` names the compose key the block used to follow, so it goes back in the same place; `null` means it was the service's first key. Two more fields ride along and record spacing, so a block separated from its neighbour by a blank line comes back on the right side of it: `gap`, how many lines sat between the two, and `blank`, whether a blank line was removed along with the block. Both may be left out, which reads as "it sat flush against the key above". |
| No entry at all | No opinion. The form falls back to whether the compose file has the block, then to the section's own default. |

That middle value is [JSON](https://www.json.org/) — a plainer, quote-heavy way of writing
structured data — wrapped up as a single string so a whole block, comments included, survives as
one YAML line rather than being pulled apart into nested keys. The schema checks that it *is* a
string; it does not look inside it. That is deliberate, not a gap: reparsing it back into YAML would
recreate the very thing this format exists to avoid.

Deleting one of these lines by hand is a supported thing to do — the section just reverts to its
default, and switching it on again starts it empty.

An entry holding no lines at all — `'{"after":null,"lines":[]}'` — means the same as no entry:
shown, and empty. Earlier versions wrote one the moment a section was switched on. Nothing writes
one now, because a section with nothing in it has nothing worth recording, and an entry saying so
would put an `x-unraid:` block into a file that had no need of one; the tick is remembered by the
editor until something is actually put in the section. One already sitting in a file is still read.

**The compose file is the source of truth.** If the block is still actually present in the file, the
file wins outright and any `sections` entry naming the same key is ignored. An entry naming a
service that no longer exists is ignored too, never quietly deleted.

---

## Service level

```yaml
services:
  jellyfin:
    x-unraid:
      icon: jellyfin               # see "Icons" below
      overview: |                  # markdown
        …
      webui: "http://[IP]:[PORT:8096]/"
      display: basic               # basic | advanced — reserved; see below
```

All keys optional. A service's heading in the form is its key in the compose file — full stop;
there is no display-name override. `display: advanced` is accepted and stored, and is intended to
fold a sidecar such as a database away behind a toggle — but nothing acts on it yet, so setting it
today changes nothing you can see.

`webui` uses Unraid's existing substitution convention for the scheme and the address:

| Token        | Replaced with                                                        |
|--------------|-----------------------------------------------------------------------|
| `[IP]`       | The server's address, or a service's own fixed address when it has one |
| `[PORT:…]`   | The port StaXX opens — see below. The number written inside the token is not read. |

The number inside `[PORT:8096]` was meant to be the **container** port, with the host port
substituted in its place. Checked against 64 real templates, it agrees with that reading 15 times,
means the **host** port instead 10 times, and matches neither 3 times — template authors do not
agree with each other, and the disagreement is silent: a link to the wrong port on a page that looks
fine. So **StaXX ignores the number and opens the first port in the service's `ports:` list
instead** — a rule that is always true and visible in the file itself, rather than a token that is
only right four times out of five. `webui` still supplies the scheme and any path, `https://` or a
trailing `/admin`; StaXX fills in only the address and the port. A `webui` with no tokens at all is
honoured verbatim.

Which port is first is yours to choose: the form gives every port row a handle you can drag, or
focus and move with the arrow keys, and the row that ends up at the top is marked **WebUI**. The
order is written back to `ports:` in the compose file, so the choice is visible in the file rather
than kept in a setting somewhere. A service with no published ports has no web page button.

A container that shares the server's own network, or sits on a macvlan network with its own address
on the LAN, never gets a port mapping at all — there is nothing to publish, because nothing stands
between the container and the network. For those, write only the number the application listens on
inside the container, with no host-side number beside it. StaXX reads that single number and uses it
as-is, because it is the only number that was ever going to answer.

**Known gap.** `webui` itself cannot be edited in the form — nothing renders any of `x-unraid` as
form fields yet, which is the piece this whole project is still missing. So a service whose file
carries no `webui` shows the button greyed out with no way inside the app to give it one; the
Compose view is the only route today. Every hand-written file is in that position, as is every
Compose Manager import and, measured across the catalogue, 19 templates in 85.

---

## Icons

`icon:` appears at both levels and takes the same four forms at either. They are told apart by their
shape, so there is no extra key saying which kind it is:

| Written like this | What it means |
|---|---|
| `jellyfin` | A name from the [selfh.st icon collection](https://selfh.st/icons/) — about 2,900 logos of self-hosted software. This is usually the shortest thing to write. |
| `./icon.png` | A file sitting in the stack's own directory, next to the compose file. |
| `https://example.org/icon.png` | Any address on the web. |
| `fa-database` | A [Font Awesome](https://fontawesome.com/v4/icons/) glyph — the same form Unraid's own XML templates accept, kept working so a converted template does not lose the icon it already had. |

**Leaving it out is the normal case.** With no `icon:`, one is worked out from the container's image
name: `lscr.io/linuxserver/jellyfin:latest` finds the Jellyfin logo on its own. Roughly three
containers in four match something. The rest show a coloured tile with their initials — the colour
comes from the name, so the same container is always the same colour, and nothing shuffles about
between page loads.

The matching is deliberately strict. It will not guess at a near-miss, because **a wrong icon is
worse than no icon**: no icon reads as "not recognised", while the wrong logo on a container reads
as a bug in the page. When a name would fit more than one entry — `node` begins six of them — none
is chosen.

A **stack** with no `icon:` of its own does not fall back to a generic box. Its tile is built from
the icons of the containers inside it, shrunk and tiled together, up to four; beyond that the fourth
cell counts what did not fit.

Downloaded icons are cached on the flash device and never fetched twice. The whole thing can be
turned off under **Settings → StaXX**, in which case icons already saved keep working.

---

## Notes and markers

A form has to say more about a setting than the compose file does: what this port is for, that this
box holds a password, that this one must not be left blank. All of that is written in the ordinary
YAML comment beside the setting.

```yaml
ports:
  - "8096:8096"                    # the page you open -!R
volumes:
  - /mnt/user/appdata/app:/config  # settings and state -!R
environment:
  TZ: America/Toronto              # timezone for scheduled tasks and timestamps
  ADMIN_TOKEN: not-a-real-token    # used to reach the admin page -!S -!R
  LOG_LEVEL: info                  # how chatty the logs are
```

The form shows that comment as the help text beneath the box, and gives you a box to edit it in.
Type a sentence there and it is written back as the comment on that line. One copy of the sentence,
on the line it describes, readable by someone who opens the file in a text editor and never sees the
form at all.

Everything else — what to call the box, what kind of control to draw — is worked out from the file.
See [Inference](#inference-when-metadata-is-absent).

### The two markers

Two facts about a value cannot be worked out by reading it, so those two, and only those two, are
written down. They sit at the **end** of the comment, after the prose, out of the way of the sentence
someone actually wrote:

| Marker | Means |
|---|---|
| `-!S` | **Secret.** Blurred when Sanitise is switched on, so the file can be screenshotted or pasted into a forum post without the value going too. |
| `-!R` | **Required.** An empty box blocks the save, and the form names the box. |

- Either may appear alone, and the order does not matter — they are read off the end one at a time.
- A comment can be all marker and no prose (`# -!S`), or all prose and no marker.
- They are the last thing on the line. Everything before them is the note.

**Nothing is guessed from a name.** A variable called `ADMIN_PASSWORD` is not hidden unless it
carries `-!S`. Guessing was considered and dropped, because a guess is wrong in both directions —
`SSH_KEY_PATH` holds no secret and `HA_LONG_LIVED_TOKEN` does, and no rule about spelling separates
them. A wrong guess about a secret is also the kind of mistake that surfaces in somebody else's
screenshot rather than in a test. So it is stated, or it is not true.

`-!S` is a deliberately odd thing to write: it has to be something nobody has already typed into a
comment by accident, short enough not to crowd out the sentence, and obviously intentional rather
than a typo.

### How the form finds a setting again

Each box on the form is tied to one thing in the file, and what it is tied *by* matters, because the
file gets edited between visits.

A port line has two halves:

```yaml
ports:
  - "8096:8096"
#    ^^^^ ^^^^
#    |    └── container side: the port the app listens on inside its container.
#    |        Fixed by whoever built the app. Never changes.
#    └── host side: the port you type into your browser.
#        This is the number a user changes, when 8096 is already taken.
```

Volumes work the same way — in `/mnt/user/appdata/jellyfin:/config`, your folder is on the left and
the app's expected folder on the right.

The form identifies that row by its **container side**: `8096`, `/config`, `PUID`. Change the host
port to `8097` and it is still the same row, because the half that names it did not move. Had the
row been identified by the whole line, every edit would have produced a row the form no longer
recognised as the one it was showing a moment ago.

**Rows key on the container side, never the host side.** This is why the reasoning survived the
`fields:` block being deleted: the identity problem is real whether or not anything is written down
about the row.

Six kinds of thing get a row, in the order the file lists them:

| Kind | Identified by |
|---|---|
| Environment variable | its name — `PUID` |
| Published port | the **container** port — `8096`, `1900/udp` |
| Mount | the **container** path — `/config` |
| Device | the **container** path — `/dev/dri` |
| Label | its key — `traefik.enable` |
| Service setting | the key itself — `image`, `restart`, `network_mode`, `command`, `entrypoint`, `user`, `hostname`, `privileged`, `shm_size` |

Anything else in a service is left to the Compose view. A row the form cannot safely edit — one
whose value comes from a shared block, is spread over several lines, or is written in a way the
parser will not touch — is shown read-only with the reason on it, rather than hidden or guessed at.

### Reading rules

1. **Unknown keys in an `x-unraid` block are ignored.** A file written for a later version still
   renders on an older StaXX, minus the parts it does not understand.
2. **A higher major `version` degrades, it does not fail.** If `version` is higher than the
   implementation knows, it falls back to working everything out from the file and says why, rather
   than reading metadata under rules it may have misunderstood.

---

## Inference when metadata is absent

Design commitment #3 is that a compose file with nothing written for the form still produces a usable
one. These are the rules that make that true — and since there is no per-setting block any more, they
are the rules for *every* file, not just an unannotated one.

**Titles** — the name of the thing, tidied up. Underscores, dashes, dots and slashes become spaces;
`RunTogetherWords` are split apart; each word is capitalised. An all-capitals word of five letters or
fewer is left exactly as it is, because it is almost certainly meant that way.

| In the file | On the form |
|---|---|
| `PUID` | **PUID** — four letters, all capitals, so it is left alone rather than becoming "Puid" |
| `ADMIN_TOKEN` | **ADMIN TOKEN** — the same rule applied to both halves |
| `JELLYFIN_PublishedServerUrl` | **JELLYFIN Published Server Url** |
| container path `/config` | **Config** |
| container port `8096` | **Port 8096** |

**Control types** — a port gets a port box; a mount gets a path box with the folder picker beside it;
`privileged` gets a tick box. A variable is judged by the value it currently holds: `true` or `false`
gets a tick box, a whole number gets a number box, anything else a plain text box.

A device gets its own picker, which is a different thing from the folder one. The folder picker walks
directories under `/mnt`; the device picker asks the server what hardware is attached and offers it by
name — "Intel graphics", or a USB stick by its make and model. It writes one row rather than two
boxes, because for nearly every device both halves of the mapping are the same path, and where they
differ the picker has already set both. A device row also says **not found on this server** when the
path is not on the machine, which is the usual reason a compose file written elsewhere starts a
container that cannot see its hardware.

**Secret and required** — never inferred. See [the two markers](#the-two-markers): guessing which
variable is a password is wrong in both directions, and guessing that a box is required blocks a save
the user has no way to resolve.

**Icons** — matched from the container's image name against the selfh.st collection, then from the
service name, then the stack name; a coloured tile of initials when nothing matches. See
[Icons](#icons) above for why the matching refuses to guess.

---

## Validation

`schema/x-unraid.schema.json` (JSON Schema 2020-12) validates the two metadata blocks. It permits
every other compose key, so it can be run against a whole compose file converted to JSON without
needing a full compose-spec schema.

It is a small schema, and it got smaller when `fields:` went away. **Notes and markers are outside
its reach entirely** — they live in comments, and comments are not part of the document a YAML parser
hands to a validator. Nothing checks them, and nothing needs to: a mistyped marker is simply prose,
which is what a comment is anyway.

Check the user's real file, not `docker compose config` output — that command drops `x-` sections, as
explained above.

---

## A note on the name

`x-unraid` is the right name if this becomes the way Unraid manages Docker, which is where the
project is heading. Until then it is presumptuous, and it carries a real risk: if Lime Technology
one day defines its own `x-unraid` meaning something different, files out in the world would mean
two things at once.

Accepted knowingly. The `version` key exists partly for this — if that day comes, we adopt whatever
they define and use the version number to tell old files from new. Worth raising with them before
many people are relying on this format, rather than after.

---

## Open questions for later versions

- **Named volumes and networks.** These can carry their own `x-` sections too. Left unused for now,
  as there is no form-level need yet.
- **A chosen list of values for a box.** A drop-down of tagged image versions, say, instead of a text
  box. It was in the deleted `fields:` block and nothing replaced it, because a comment is prose and
  a list of choices is not. If it comes back it needs a home of its own, and the honest place to
  start is with a real file that wants one.
- **More marker letters.** Two is the whole vocabulary, on purpose. Any third has to earn itself by
  being something that genuinely cannot be read out of the file — the bar `-!S` and `-!R` cleared.
- **Grouping and an advanced toggle per setting.** Both were in the deleted block. Order on the form
  is document order, which is a grouping the author already controls by moving lines about, and that
  may well be enough.
- **Other languages.** Notes are written in whatever language the file's author wrote them in, and
  there is nowhere to put a second one. A translations block would not break any existing file.
- **Secrets.** `-!S` hides a value on screen. It does nothing about that value sitting in plain text
  in the file. Handling that properly means Docker's own secrets mechanism, which is a bigger job.
