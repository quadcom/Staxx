# Stack Manager — compose-native Docker management for Unraid

**Status: pre-alpha. Nothing here is installable yet.**

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
   any machine with Docker Compose. UI metadata (labels, descriptions, masked fields, basic vs.
   advanced) lives in optional `x-unraid:` extension fields, which the compose spec permits and
   ignores.
2. **Never destroy a hand-authored file.** Editing through the form preserves comments, ordering,
   anchors, and formatting. Saving a normalised round-trip over someone's file is a bug, not a
   trade-off.
3. **Degrade gracefully.** A compose file with no `x-unraid:` metadata still produces a usable form,
   with types inferred from the file itself.
4. **Own the render, don't inject into someone else's.** Grouping, lifecycle controls, and layout
   are rendered by this plugin rather than surgically inserted into the stock Docker tab's DOM.

## Planned scope

- Compose engine — install the compose CLI, manage stacks as ordinary directories of ordinary files
- Compose → form UI, with comment-preserving write-back
- Container and stack lifecycle control (start / stop / restart / logs / console)
- Collapsible stack grouping, keyed on `com.docker.compose.project` — stacks self-group, with no
  folders for the user to configure
- Community Applications template → compose conversion, on demand

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
docs/feasibility.md         Research findings the design rests on
```

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
