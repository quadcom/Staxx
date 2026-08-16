# Stack Manager — compose-native Docker management for Unraid

**Status: pre-alpha. Nothing here is installable yet.**

**New here? Start with [docs/README.md](docs/README.md)** — a plain-English explanation of what this
is and why. The [glossary](docs/glossary.md) defines every term used across the project.

## What this is

Unraid manages Docker through a proprietary XML template format. If no template exists for a
container, you build one by hand in the Unraid UI, field by field. Templates are an Unraid-only
artefact — they don't come from upstream projects, they don't work anywhere else, and the ~2000
that exist are community-maintained.

Stack Manager inverts that. **Standard [Compose](https://compose-spec.io/) files are the underlying
representation for every container.** Any compose file — from a project's README, from a blog post,
from another machine — can be dropped in and run, unmodified.

For users who don't know compose, the UI parses the compose file and renders it as a **form**,
comparable to today's template editor. The form is a *view over the compose file*, not a conversion
into some other format: the compose file on disk stays the source of truth, and it stays a valid,
portable compose file that runs under plain `docker compose up` anywhere.

## Design commitments

These are the constraints the project is built around, in priority order.

1. **Never require non-standard syntax.** A file authored by Stack Manager must run unmodified on
   any machine with Docker Compose. What a stack and its containers *are* — icon, overview, and (per
   container) its web interface address — lives in optional `x-unraid:` extension fields, which the
   compose spec permits and ignores. What an individual setting is *for* lives in the ordinary
   comment beside it, with two marks (`-!S` secret, `-!R` required) for the only two things a comment
   cannot say.
2. **Never destroy a hand-authored file.** Editing through the form preserves comments, ordering,
   anchors, and formatting. Saving a normalised round-trip over someone's file is a bug, not a
   trade-off.
3. **Degrade gracefully.** A compose file with no metadata of either kind still produces a usable
   form, with names and control types worked out from the file itself.
4. **Own the render, don't inject into someone else's.** Grouping, lifecycle controls, and layout
   are rendered by this plugin rather than surgically inserted into the stock Docker tab's DOM.

## Planned scope

- Compose engine — install the compose CLI, manage stacks as ordinary directories of ordinary files
- Compose → form UI, with comment-preserving write-back
- Container and stack lifecycle control (start / stop / restart / logs / console)
- Collapsible stack grouping — stacks can be placed one level deep into user-created folders, and a
  running stack is matched back to its directory via `com.docker.compose.project`
- Community Applications template → compose conversion, on demand — **built**; see
  [Importing from Community Applications](#importing-from-community-applications)

## Importing from Community Applications

The **Apps** button on the Stacks page searches the Unraid Community Applications catalogue — about
3,700 containers — and turns the one you pick into an ordinary compose file, opened in the editor as
a new stack for you to look over before saving.

The point is not the image name. It is everything around it: CA's `Description` for each setting
becomes the comment beside that setting, so the form has real help text; `Required` becomes `-!R`
and `Mask` becomes `-!S`; the icon, category, project and support links become `x-unraid` metadata.
An imported app renders as a proper form rather than a wall of YAML, which is the whole reason to
import rather than retype.

**What converts.** `Repository` → `image`; Port, Path, Variable, Device and Label settings → `ports`,
`volumes`, `environment`, `devices` and `labels`; `PostArgs` → `command`; `Privileged` → `privileged`;
`WebUI` → the service's `x-unraid.webui`, with Unraid's own `[IP]` and `[PORT:n]` substitution left
intact. `bridge` networking is omitted, because compose makes its own; `host` and `none` become
`network_mode`; a custom network such as `br0` is declared `external: true` and flagged, because it
has to already exist on the server.

**What does not, and how you find out.** `ExtraParams` holds arbitrary `docker run` flags. Roughly
thirty of them have a clean compose equivalent and are translated. Anything else — `--gpus`, the
`--health-*` family, `--memory`, `--ulimit` and so on — is **reported in a warning above the form**,
naming the flag, rather than dropped quietly. A mistranslated healthcheck or resource limit would be
worse than an honest "this was not applied".

Values are guessed only where CA left one empty, and every guess is warned about too: a path with no
value becomes `/mnt/user/appdata/<name><target>`, a port with no value takes the container port, and
a device with no value is skipped entirely, because there is no sane guess for a device node.

**The catalogue.** CA publishes one 24 MB JSON file and offers no search API — the file is the whole
interface, and CA's own plugin downloads exactly the same thing. Stack Manager fetches it once, on
first use of the Apps button, and splits it into a small search index plus one line per app. That
lives in `/tmp`, so **nothing is written to the flash drive** and it costs nothing at reboot.
Refreshes are cheap: CA also publishes a 37-byte file saying when the catalogue last changed, so a
refresh that finds nothing new costs 37 bytes rather than 24 MB. Nothing is downloaded until you
press the button.

## Direction

**Plugin first, PR later.** This ships as a community plugin and earns adoption on its own merits.
Only once that's demonstrated does a pull request to
[unraid/webgui](https://github.com/unraid/webgui) proposing it as the built-in Docker management
experience make sense.

That sequencing matters technically too: some mechanisms appropriate for a coexisting plugin —
notably relying on plugin load order to shadow stock pages — are scaffolding, and would not belong
in an upstream contribution.

## Repository layout

```
docs/README.md              Plain-English overview — start here
docs/glossary.md            Every term used in this project, defined
docs/feasibility.md         Whether this is possible, and the evidence
docs/x-unraid-schema.md     Format of the extra info that makes the form friendly
schema/x-unraid.schema.json Machine-readable definition of that format
examples/                   Worked compose files carrying x-unraid metadata
tests/validate_schema.py    Schema self-test — checks what it rejects, not just what it accepts
src/stack.manager/          Slackware-style tree, mirrors install paths on the server
  usr/local/emhttp/plugins/stack.manager/
    *.page                  WebGUI pages (Docker sub-tab, header button, settings)
    include/                PHP — compose engine, parsing, form model
    javascript/             Client-side — YAML round-trip, form rendering
    scripts/                Shell — compose invocation, install helpers
    sheets/                 CSS
    event/                  Unraid event hooks (array start, docker start/stop)
    langs/                  Translations
stack.manager.plg           Plugin installer manifest
pkg_build.sh                Builds the .txz package from src/
dev-install.sh              Copies src/ straight onto a server for testing
```

## Testing on a server

`dev-install.sh` skips the package entirely and copies the plugin files into place, so a change is
visible on a browser refresh. Copy it and the plugin folder to the flash drive, then run it there:

```
/boot/stack.manager-dev/
    dev-install.sh
    stack.manager/          <- src/stack.manager/usr/local/emhttp/plugins/stack.manager/
```

```sh
bash /boot/stack.manager-dev/dev-install.sh            # install or update
bash /boot/stack.manager-dev/dev-install.sh --remove   # remove, keep settings
bash /boot/stack.manager-dev/dev-install.sh --purge    # remove settings too
```

Nothing installed this way survives a reboot — `/usr/local/emhttp` is rebuilt at boot. Settings do
survive, since they live on the flash drive.

## Compatibility

Targets **Unraid 7.2 and later**, which introduced the responsive WebGUI. Earlier versions are out
of scope.

## Licence

GPL-2.0. Matches `unraid/webgui`, which keeps an eventual upstream contribution clean.

## Prior art

Studied while scoping this, and worth reading before touching related code:

- [Compose Manager Plus](https://github.com/mstrhakr/compose_plugin) — compose engine, header-menu
  toggle, per-OS-version patching of the stock Docker client
- [FolderView3](https://github.com/chodeus/folder.view3) — collapsible grouping, achieved by DOM
  injection into the stock container table
- [unraid-plugin-composerize](https://github.com/llalon/unraid-plugin-composerize) — template →
  compose conversion

None of their code is used here; the patterns informed the design.
