# The `x-unraid` schema

**Status: draft, version 1. Not yet implemented.**

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

We keep it in a section named `x-unraid`, inside the compose file itself. The compose standard sets
aside any section whose name starts with `x-` for extra information that Docker must ignore, so
adding it does not stop the file working anywhere else. It is the one officially sanctioned place to
put something like this.

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
pinning. Those live in Stack Manager's own config, because they are facts about one machine, not
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
  name: Jellyfin

services:
  jellyfin:
    image: jellyfin/jellyfin:10.10.3
    x-unraid:          # service-level: describes one container, and its form fields
      name: Jellyfin
      fields: []
```

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
> out. The two are matched up using the bindings below, which is the main reason bindings key on
> something that never changes.

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

---

## Stack level

```yaml
x-unraid:
  version: 1                      # schema version. Optional, defaults to 1.
  name: Jellyfin                  # display name for the stack
  icon: https://…/jellyfin.png    # URL, or a path relative to the stack directory
  overview: |                     # markdown; shown in the stack's detail view
    Free software media system…
  category: MediaApp:Video        # Unraid's existing category taxonomy
  project: https://jellyfin.org
  support: https://forum.jellyfin.org
  readme: https://github.com/…#readme
  author: jellyfin
```

All keys optional. `name` falls back to the stack directory name.

Service display order is the order services appear in the compose file — the editor preserves
document order, so no explicit ordering key is needed.

---

## Service level

```yaml
services:
  jellyfin:
    x-unraid:
      name: Jellyfin              # defaults to the service key
      icon: https://…/icon.png
      overview: |                 # markdown
        …
      webui: "http://[IP]:[PORT:8096]/"
      display: basic              # basic | advanced — advanced hides the whole service
                                  # behind the form's advanced toggle
      fields:
        - …
```

`webui` uses Unraid's existing substitution convention, so it behaves the way users already expect:

| Token        | Replaced with                                                   |
|--------------|-----------------------------------------------------------------|
| `[IP]`       | The server's address                                             |
| `[PORT:8096]`| The **host** port currently mapped to container port 8096        |

Using `[PORT:…]` rather than a literal means the link keeps working after someone changes the host
port in the form.

---

## Fields

`fields` is a **sequence**, and its order is the form's display order. Each entry binds metadata to
one thing in the service.

### Binding

Each entry carries **exactly one** binder key, which names both the kind of thing and its target:

| Binder    | Binds to                              | Example              |
|-----------|---------------------------------------|----------------------|
| `env`     | An environment variable, by name      | `env: PUID`          |
| `port`    | A published port, by **container** port | `port: 8096`, `port: 1900/udp` |
| `volume`  | A mount, by **container** path        | `volume: /config`    |
| `device`  | A device, by **container** path       | `device: /dev/dri`   |
| `label`   | A label, by key                       | `label: traefik.http.routers.app.rule` |
| `setting` | A scalar service key                  | `setting: image`     |

Every binder is named after the compose key it binds to. That consistency is why the human-readable
name attribute below is `title` and not `label` — `label` is taken, and a key that means one thing
in one position and something else in another is a trap.

### Why bindings use the container side

This is the most important idea in the document, so here it is concretely.

A port line in a compose file has two halves:

```yaml
ports:
  - "8096:8096"
#    ^^^^ ^^^^
#    |    └── container side: the port the app listens on inside its container.
#    |        Fixed by whoever built the app. Never changes.
#    └── host side: the port you type into your browser.
#        This is the number the user changes, when 8096 is already taken.
```

Volumes work the same way — `/mnt/user/appdata/jellyfin:/config` is your folder on the left, the
app's expected folder on the right.

Now suppose we attached the label "Web interface" to the whole line, `"8096:8096"`. A user hits a
clash, changes the host side to `8097:8096`, saves — and the line no longer matches what we recorded.
The label is lost. The next time they open the form, the field they just carefully set is unlabelled.

So metadata attaches to the **container side only**: `port: 8096`, `volume: /config`, `env: PUID`.
Those are properties of the app itself and never change. The parts the user edits are never used to
find anything.

**Bindings key on the container side, never the host side.**

`setting` is limited in v1 to keys where a form control is meaningful: `image`, `restart`,
`network_mode`, `command`, `entrypoint`, `user`, `hostname`, `privileged`, `shm_size`. Anything else
is edited as YAML.

### Attributes

| Key           | Type    | Default          | Meaning |
|---------------|---------|------------------|---------|
| `title`       | string  | inferred         | Human name for the field |
| `description` | string  | —                | Help text. Markdown. |
| `display`     | enum    | `basic`          | `basic` \| `advanced` — `advanced` hides it behind the toggle |
| `required`    | boolean | `false`          | Blocks save when empty |
| `mask`        | boolean | inferred         | Renders as a password input and redacts in logs and exports |
| `type`        | enum    | inferred         | `text` \| `password` \| `number` \| `boolean` \| `path` \| `port` \| `select` |
| `options`     | list    | —                | Choices. Each `{value, label}` or a bare scalar. Presence implies `type: select`, so stating the type as well is optional — but stating a *contradictory* type is an error. `type: select` without `options` is also an error. |
| `mode`        | enum    | from the file    | `volume` only: `rw` \| `ro` |
| `group`       | string  | —                | Section heading; consecutive fields sharing a group render together |

### Example

```yaml
fields:
  - port: 8096
    title: Web interface
    description: Port the Jellyfin web UI listens on.
    required: true

  - volume: /config
    title: Configuration storage
    description: Database, settings and metadata. Back this up.
    required: true
    group: Storage

  - volume: /media
    title: Media library
    mode: ro
    group: Storage

  - env: PUID
    title: User ID
    display: advanced
    type: number

  - env: JELLYFIN_PublishedServerUrl
    title: Published server URL
    description: External URL, if you reach this through a reverse proxy.
    display: advanced

  - setting: image
    title: Version
    type: select
    options:
      - { value: "jellyfin/jellyfin:10.10.3", label: "10.10.3 (stable)" }
      - { value: "jellyfin/jellyfin:latest",  label: "Latest" }
```

(`options` entries keep `label` for the choice text — that is a plain string in a list, never a
binder, so there is no ambiguity.)

### Resolution rules

These are the behaviours an implementation must get right, and they are all about surviving files
that have been edited by hand.

1. **Unmatched metadata is ignored, with a warning.** A field entry naming something the service no
   longer has does not error — someone removed a port, and the file is still valid. Warn, don't fail.
2. **Unmatched compose entries are inferred and appended.** Anything real in the service that no
   field entry claims still gets a form control, generated by the rules below, placed after all
   declared fields in compose document order. Adding a port to a file with metadata never makes that
   port invisible.
3. **First match wins.** If two entries bind the same target, the first is used and the rest warn.
4. **Unknown keys are ignored.** Forward compatibility: a file written for a later schema version
   still renders on an older Stack Manager, minus the parts it doesn't understand.
5. **A higher major `version` degrades, it does not fail.** If `version` exceeds what the
   implementation knows, fall back to full inference and tell the user why, rather than rendering
   metadata under rules it may have misread.

---

## Inference when metadata is absent

Design commitment #3 is that a compose file with no `x-unraid` still produces a usable form. These
rules generate one, and also fill any attribute a field entry leaves out.

**Titles** — humanise the target: `PUID` → "PUID"; `JELLYFIN_PublishedServerUrl` → "Jellyfin
Published Server Url"; container path `/config` → "Config"; container port `8096` → "Port 8096".

**Types** — `port` → `port`; `volume`/`device` → `path`; `setting: privileged` → `boolean`;
`env` whose current value is `true`/`false` → `boolean`, or numeric → `number`; otherwise `text`.

**Masking** — an `env` field is masked when its name matches, case-insensitively:

```
(^|_)(PASS|PASSWD|PASSWORD|SECRET|TOKEN|APIKEY|API_KEY|PRIVATE_KEY|CREDENTIALS?)($|_)
```

Deliberately anchored on word boundaries rather than a bare substring match, so `SSH_KEY_PATH` and
`KEYBOARD_LAYOUT` are not masked into uselessness. Inference can be wrong in both directions, which
is why `mask` is explicitly settable.

**Required** — nothing is inferred as required. Guessing wrong here blocks a save the user cannot
resolve, so an unannotated file demands nothing.

**Display** — everything is `basic`. A file with no metadata shows all its fields; hiding things the
author never marked as advanced would conceal what someone needs.

---

## Validation

`schema/x-unraid.schema.json` (JSON Schema 2020-12) validates the metadata blocks. It permits every
other compose key, so it can be run against a whole compose file converted to JSON without needing a
full compose-spec schema.

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
- **Fields that depend on other fields.** "Only show this box when that one is ticked" is a genuine
  want. Deferred — conditional forms are easy to design badly, and there is no evidence of demand
  yet.
- **Other languages.** `title` and `description` are English-only today. Adding a translations block
  later would not break any existing file.
- **Secrets.** `mask` hides a password on screen. It does nothing about that password sitting in
  plain text in the file. Handling that properly means using Docker's own secrets mechanism, which
  is a bigger design job.
