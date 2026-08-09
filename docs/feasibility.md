# Feasibility Report — Compose-Native Docker Management for Unraid

New to the terminology? See the [glossary](glossary.md). For what the project is trying to do at
all, start with [the docs overview](README.md).

---

## In plain terms

**The question:** can an Unraid add-on replace how Unraid handles Docker — using standard compose
files instead of Unraid's own template format, showing a friendly form for people who don't want to
edit files, and optionally taking over the Docker button in the top menu?

**The answer: yes, all of it.** Nothing on the list needs permission from Unraid's makers, a special
version of Unraid, or anything hidden. Most of it is already done by other add-ons, which is the
strongest evidence there is — it isn't theory, it's running on people's servers today.

**Two parts are genuinely hard**, and they are worth knowing about early:

1. **Saving your changes back into the file without wrecking it.** Reading a compose file to build a
   form is straightforward. Writing the form's changes back out is not — most tools that do this
   quietly throw away comments and reformat everything. If someone hand-wrote their file with notes
   in it, that would destroy their work. Doable, but it needs a specific and less common approach,
   and underestimating it is the most likely way this project stalls.

2. **The 2000 existing apps in Community Applications.** Converting them is mechanical and mostly
   straightforward. The hard part isn't technical — it's that Community Applications is the main
   reason people choose Unraid, it's built by volunteers, and it's built entirely on templates.
   Anything that looks like replacing it wholesale will meet resistance. The plan sidesteps this:
   convert one app on demand, when a user asks, which needs nobody's agreement and is useful
   immediately.

**One risk worth naming.** Unraid's Docker screens are being actively worked on by their developers.
Anything built tightly around how those screens work today will need ongoing upkeep. The design
reduces this exposure by drawing its own screen rather than reaching into theirs.

Everything below is the detailed evidence, aimed at a technical reader. The findings were read
directly from Unraid's source code, because there is very little written documentation for add-on
authors.

---

## Context

The goal is to replace how Unraid manages Docker. Today Unraid uses a proprietary XML template format; if no template exists for a container, the user must build one by hand inside the Unraid UI. The proposal inverts this: **industry-standard compose files become the underlying representation for every container**, so any compose file found anywhere can be dropped in and run.

To keep this accessible to users who don't know compose, the UI parses a pasted compose file and renders it as a **form** — not by converting it into an Unraid template, but as a form *view over the compose file itself*, which remains the source of truth on disk.

Delivery is **plugin first, PR later**: ship as a community plugin, build adoption, and only then propose it upstream to Lime Technology for adoption into Unraid OS.

Scope confirmed with the user:

- Target **Unraid 7.2+** only (responsive WebGUI, built-in API).
- **Build fresh**, borrowing patterns from Compose Manager Plus rather than forking it.
- Take over the **top-nav Docker menu item** (not Settings → Docker). Additive tabs by default, full takeover opt-in.
- Feature scope: compose engine, compose→form UI, container lifecycle control, **collapsible stack grouping** in the container list, and **CA template → compose conversion**.

---

## Verdict

**Feasible in full.** Every capability on the list is achievable with the standard Unraid plugin mechanism — no private API, no patched OS image, nothing Lime Tech has to grant in advance. Four of the six are already demonstrated by shipping third-party plugins.

The novel engineering is concentrated in two places: the **compose→form round-trip** and the **CA template conversion**. Everything else is assembly of known-good patterns.

Findings below are read from `unraid/webgui` source (master ≈ 7.3 line) and from the source of the relevant prior-art plugins, since the plugin API is largely undocumented.

---

## Capability-by-capability

### 1. Compose files as the universal representation — **Feasible, proven**

Unraid does not bundle the `docker compose` CLI plugin, even in 7.3; the plugin must install the binary itself. Compose Manager Plus does exactly this, so the install path is proven. The engine is then shelling out to `docker compose` from PHP — see its `scripts/compose.sh` and `include/ComposeCommandBuilder.php` for the shape.

Stacks are ordinary directories of ordinary compose files. Nothing forces non-standard syntax on advanced users, satisfying the "source any compose file and it just works" requirement literally.

### 2. Compose → form presentation — **Feasible; the hardest piece**

**Parsing.** There is no YAML parser available to PHP on Unraid — no `ext-yaml`, no bundled `symfony/yaml`. Confirmed by Compose Manager Plus, which does zero server-side YAML handling and bundles `js-yaml` client-side instead. Two paths, and you want both:

- **Server-side, canonical:** `docker compose config --format json` emits the fully-resolved Docker Engine model — merges overrides, applies `extends`, interpolates `.env`, normalises shorthand. This is the right input for *rendering* a form because it removes every ambiguity. Companion flags `--services`, `--profiles`, `--variables`, `--no-interpolate` are all useful.
- **Client-side, for editing:** a browser YAML library, because the canonical output is lossy exactly where editing needs fidelity.

**Writing back is the hard half.** `docker compose config` output is normalised: comments stripped, anchors expanded, variables baked in. Writing that back over a user's hand-authored file would destroy it — unacceptable given requirement 1. The form editor needs a comment- and formatting-preserving round-trip, i.e. a CST-level YAML library (eemeli/`yaml`'s `parseDocument` + `toString` preserves comments; `js-yaml` does not). Surgical edits into an existing document is a materially different problem from serialising an object, and underestimating it is the most likely way this project stalls.

**The metadata gap.** Unraid's template form is pleasant because the XML carries UI metadata compose has no equivalent for. Stock templates live at `/boot/config/plugins/dockerMan/templates-user/my-*.xml` (`$dockerManPaths`, `DockerClient.php:25-35`), and each `<Config>` carries `Name`, `Target`, `Default`, `Mode`, `Description`, `Type` (Path/Port/Variable/Label/Device), `Display` (basic/advanced), `Required`, `Mask`. Raw compose gives you target and value and nothing else — no human label, no description, no "mask this password", no basic/advanced split.

The clean answer: the **compose spec permits `x-` extension fields at any level** and ignores them. An optional `x-unraid:` block carries the missing metadata without making the file non-standard — it still runs under plain `docker compose up` anywhere. Files lacking it degrade to an inferred form (types guessed from key shape, everything "basic"), which is still usable for the beginner case.

> **Correction (2026-08-09).** This section originally claimed that `x-` sections survive the
> `docker compose config` command. They do not, reliably.
>
> To be clear about what that means, because it is narrower than it sounds: `docker compose config`
> prints a tidied-up copy of a compose file to the screen. That printout leaves the `x-` sections
> out ([docker/compose#11528](https://github.com/docker/compose/issues/11528),
> [#9682](https://github.com/docker/compose/issues/9682)). **Your file is untouched** — Docker never
> writes to it. Only the printout is affected.
>
> The verdict is unchanged. What changes is where each piece of information comes from: the friendly
> labels are read from the real file, and `docker compose config` is used only to work out the
> settings the containers will actually run with. See [x-unraid-schema.md](x-unraid-schema.md).

### 3. Container lifecycle control — **Feasible, routine**

Start / stop / restart / pause / logs / console, per-container and per-stack. Two levers: `docker compose` subcommands for stack-level operations, and the Docker socket / CLI for per-container. Compose Manager Plus already ships all of this including a ttyd-backed terminal for live command output (`include/ShowTtyd.php`), plus background execution with notifications. No unknowns.

### 4. Collapsible stack grouping — **Feasible, and better under takeover**

FolderView3 (`chodeus/folder.view3`) proves collapsible grouping works in the Docker tab. But look at *how*: `scripts/docker.js` is ~1400 lines of jQuery DOM surgery injecting `<tr>` rows into the stock `#docker_list` table, driven by a `MutationObserver`, with position arithmetic against `#docker_list > tr.sortable`. The repo shipped seven releases on a single day in August 2026 — that release cadence is the signature of DOM-injection fragility against a moving target.

**This is a strong argument for takeover rather than injection.** If you render the container list yourself, grouping stops being surgery and becomes the natural layout. And compose gives you the grouping key for free: every compose-created container carries `com.docker.compose.project`, so stacks self-group with zero user configuration — whereas FolderView requires users to define folders by manual selection or regex. That's a real, demonstrable UX win over the incumbent, not just a different architecture.

Note FolderView3's plugin directory (`folder.view3`) sorts after `dynamix.docker.manager`, and it ships `folder.view3.Docker.page` with `Menu="Docker"` — the same integration surface discussed below.

### 5. Taking over the top-nav Docker menu item — **Feasible, three mechanisms, all verified**

How Unraid builds its UI (`emhttp/plugins/dynamix/template.php`):

```php
$site = [];
build_pages('webGui/*.page');                              // line 68
foreach (glob('plugins/*', GLOB_ONLYDIR) as $plugin) {     // line 69 — alphabetical
  if ($plugin != 'plugins/dynamix') build_pages("$plugin/*.page");
}
...
$myPage = $site[basename($path)];                          // line 94 — URL → page
```

`PageBuilder.php`'s `build_pages()` stores each page as `$site[basename($entry,'.page')]`. Three consequences:

1. **Pages are keyed by bare filename, last writer wins.** `glob()` here is *not* `GLOB_NOSORT`, so plugin directories load alphabetically. A plugin sorting after `dynamix.docker.manager` that ships its own `Docker.page` **silently replaces the stock Docker tab** — icon, condition, content, everything. No stock file touched, survives OS upgrades. Undocumented emergent behaviour, not a supported API, but the cleanest takeover available.
2. **The header tab is just a page.** Stock `dynamix.docker.manager/Docker.page` is `Menu="Tasks:60" Type="xmenu" Code="e90b" Lock="true"`, gated by `Cond` on `DOCKER_ENABLED=yes`. `DefaultPageLayout.php:50` calls `find_pages('Tasks')` to build the nav; `Navigation/Main.php` renders each as `<a href="/{name}">`. Your own header button is a five-line page header.
3. **Sub-tabs are ranked.** `DockerContainers.page` is `Menu="Docker:1" Title="Docker Containers"`. `find_pages()` ksorts by rank and `MainContentTabbed.php` selects the **first titled page**. So `Menu="Docker:0"` makes your page the default landing tab under the existing Docker button, replacing nothing.

`Cond` is arbitrary PHP eval'd via `DefaultPageLayout/evalContent.php`, so any of these can be gated on your own config at runtime. Compose Manager Plus ships this exact toggle today: `Compose.page` (`Menu="Tasks:61" Type="xmenu"`) and `compose.manager.page` (`Menu="Docker:2"`) are mutually exclusive via inverse `Cond` checks on a `SHOW_COMPOSE_IN_HEADER_MENU` key.

The chosen design — additive by default, takeover opt-in — maps cleanly: ship `Menu="Docker:0"` plus your own `Tasks:` xmenu button, and let a settings toggle drop in or remove shadow `Docker.page` / `DockerContainers.page` files. Nothing stock is patched; toggling off is a file deletion.

⚠️ **Decide before writing any code:** the plugin's directory name under `/usr/local/emhttp/plugins/` must sort **after** `dynamix.docker.manager` for shadow-override to work. `unraid.compose` or `zz.compose` work; `compose.*` silently would not. The name is baked into every URL, config path, and user install — effectively irreversible once published.

### 6. CA template → compose conversion — **Feasible mechanically; the political risk, not the technical one**

Prior art exists: `llalon/unraid-plugin-composerize` already converts CA templates to compose. The Community Applications catalogue is ~2000+ apps, aggregated into a single `applicationFeed.json` (`Squidly271/AppFeed`), so bulk conversion is a batch job over one well-known feed, not 2000 scraping targets.

Field mapping is mostly mechanical: `Repository`→`image`, `Config Type="Port"`→`ports`, `Path`→`volumes`, `Variable`→`environment`, `Device`→`devices`, `Label`→`labels`, `Network`→`network_mode`, and the UI metadata (`Description`, `Display`, `Required`, `Mask`) lands naturally in `x-unraid:` — which is precisely the case for defining that extension block.

**The lossy edge is `ExtraParams`**, which holds arbitrary `docker run` flags. Some map to compose keys, some have no compose equivalent, and some are genuinely free-form. Any conversion needs an explicit "couldn't translate this" path rather than silent dropping. Same for `<Command>`/`post_arguments` and templated `WebUI` values using `[IP]`/`[PORT:n]` substitution (see `DockerClient.php:351-398`).

**Two conversion directions, and they serve different purposes.** Converting the *catalogue* is the migration story for a future upstream PR. Converting a *single template on demand* — "point at this CA app, get a compose file" — is a plugin feature that delivers value immediately and needs no one's cooperation. The second is the one to build first; it also functions as the proving ground for the mapping logic the bulk conversion would later need.

---

## Risks and constraints

| Risk | Severity | Notes |
|---|---|---|
| Comment-preserving YAML round-trip | **High** | Largest novel engineering item. Get a spike done early. |
| CA ecosystem inertia | **High** (strategic, not technical) | CA is the main reason people choose Unraid, and it is template-driven and community-maintained. On-demand conversion sidesteps this; wholesale catalogue replacement does not. |
| Shadow-override relies on undocumented load order | Medium | `glob()` sort is stable in practice, but Lime Tech could add `GLOB_NOSORT` at any time. Additive-first means a break degrades to "extra tab", not "no Docker tab". |
| Docker manager under active development | Medium | 7.3.2 reworked Docker tab loading for performance; 7.3.0-rc.2 fixed Add Container hangs; the built-in GraphQL API is expanding. A takeover page pinned to today's internals needs upkeep — though owning the render (vs. injecting into it) reduces the exposed surface considerably, as FolderView3's release cadence illustrates. |
| `ExtraParams` / free-form template fields | Medium | Needs an explicit untranslatable path, not silent loss. |
| 7.2 responsive DOM rules | Low | No title-bar buttons (break on mobile); dashboard tiles use `.tile-ctrl` / `.tile-header-right-controls`; wrap button groups in `.buttons-spaced`; use `var(--token, fallback)` colours. `ResponsiveLayout="false"` is an escape hatch that forfeits mobile. |
| Compose containers look template-less to the stock UI | Low, given takeover | Only bites if the stock container list stays in place. Compose Manager Plus patches `DockerClient.php` per OS version (`patches/6.9|6.10|6.11/`) to cope — a burden takeover avoids. |
| Licensing | Low | `unraid/webgui` is GPLv2; Compose Manager Plus and FolderView3 are GPL. Patterns are free to study; copied code carries obligations. Matters more for an eventual upstream PR. |
| CSRF | Low | WebGUI POSTs require `$var['csrf_token']`. Standard, just don't forget it. |

**Not a risk:** privileged operations. Plugin PHP runs as root under php-fpm, so installing binaries, writing `/boot/config`, and driving the Docker socket are unremarkable.

---

## Open decisions before any build starts

1. **Plugin directory name** — must sort after `dynamix.docker.manager`. Effectively permanent. Decide first.
2. **`x-unraid:` schema** — this is the project's real public API. Users' files will encode it and third parties will emit it; changing it later breaks them. It also has to be good enough to carry converted CA metadata (decision 5).
3. **Where stacks live** — `/boot/config/plugins/<name>/` (flash, survives reboot, small, available before array start) vs. an array share (roomy, but unavailable until the array starts, which constrains autostart).
4. **Adopt-vs-import for existing containers** — what happens to a user's existing template-created containers on install. Leave them alone, offer conversion, or adopt them into a generated compose file.
5. **CA conversion direction to build first** — on-demand single-template conversion (recommended: immediate value, no dependencies, proves the mapping) vs. bulk catalogue conversion (only meaningful alongside an upstream push).
6. **Distribution** — CA listing needs a public repo, a `.plg` with a version scheme, and a support thread. Note the mild irony of shipping a CA-replacement through CA.

---

## Phase 0 — repo scaffold (next step)

Initialise the project in `D:\!! Working Projects\!!VSCode\MyWSpaces\Unraid Compose Rev2` (currently empty):

- `git init`, `.gitignore`, `README.md` stating the compose-first premise and the plugin-first/PR-later sequencing, `LICENSE` (**GPL-2.0**, to keep an upstream PR and any borrowed webgui patterns clean).
- Slackware-style source tree mirroring install paths, per the convention Compose Manager Plus and FolderView3 both use:
  `src/<plugin>/usr/local/emhttp/plugins/<plugin>/` with `include/`, `javascript/`, `scripts/`, `sheets/`, `event/`, `langs/`.
- `<plugin>.plg` installer skeleton + `pkg_build.sh`.
- Copy this report into `docs/feasibility.md` so the findings live with the code.

**Plugin directory name** — decision #1, and it gates everything. It must sort after `dynamix.docker.manager`, which in practice means any name starting with `dz`–`z`. Proposed default: **`stack.manager`** (descriptive, sorts safely, doesn't imply official Unraid endorsement the way an `unraid.*` prefix would). Easy to change now, effectively permanent after first release — say the word if you want a different one.

Deliberately **not** in Phase 0: the `x-unraid:` schema. It's the project's real public API and deserves its own design pass rather than being improvised alongside scaffolding.

First real milestone after scaffolding is the five-minute experiment below, run on your server, before any substantial code.

---

## How to verify these findings on your own server

Everything above was read from source rather than executed. To confirm on real hardware:

```bash
# Page/menu system behaves as described
grep -n "build_pages\|glob('plugins/\*'" /usr/local/emhttp/plugins/dynamix/template.php
head -8 /usr/local/emhttp/plugins/dynamix.docker.manager/Docker.page
head -8 /usr/local/emhttp/plugins/dynamix.docker.manager/DockerContainers.page

# No server-side YAML; note the PHP version
php -v && php -m | grep -i yaml          # expect: no yaml module

# Compose absent by default; canonical parse once installed
docker compose version
docker compose -f /path/to/compose.yaml config --format json | head -40

# Stacks self-group by this label — the grouping key that makes folders unnecessary
docker ps --format '{{.Names}}\t{{.Label "com.docker.compose.project"}}'

# Load order gives a later-sorting plugin the last word
ls -1 /usr/local/emhttp/plugins/
```

**The decisive five-minute experiment:** drop a two-line `.page` file with `Menu="Docker:0"` and `Title="Test"` into `/usr/local/emhttp/plugins/zz.test/`, reload the Docker tab, and confirm it appears as the first sub-tab. That validates the entire UI-integration premise before a line of real code is written.

---

## Sources

- [unraid/webgui](https://github.com/unraid/webgui) — `template.php`, `PageBuilder.php`, `DefaultPageLayout.php`, `Navigation/Main.php`, `MainContentTabbed.php`, `dynamix.docker.manager/*.page`, `DockerClient.php`
- [Plugin Development Docs for Unraid](https://plugin-docs.mstrhakr.com/) — `.page` format, filesystem layout
- [mstrhakr/compose_plugin (Compose Manager Plus)](https://github.com/mstrhakr/compose_plugin) — compose engine, header-menu toggle, `DockerClient` patching, ttyd terminal
- [chodeus/folder.view3](https://github.com/chodeus/folder.view3) — collapsible grouping via DOM injection; cautionary release cadence
- [llalon/unraid-plugin-composerize](https://github.com/llalon/unraid-plugin-composerize) — template→compose prior art
- [Squidly271/community.applications](https://github.com/Squidly271/community.applications) + [AppFeed](https://github.com/Squidly271/AppFeed) — CA catalogue and `applicationFeed.json`
- [Responsive WebGUI Plugin Migration Guide](https://forums.unraid.net/topic/192172-responsive-webgui-plugin-migration-guide/) — 7.2 DOM changes
- [docker compose config reference](https://docs.docker.com/reference/cli/docker/compose/config/) — canonical model output
- [Unraid 7.3.2](https://docs.unraid.net/unraid-os/release-notes/7.3.2/) / [7.2.0 release notes](https://docs.unraid.net/unraid-os/release-notes/7.2.0/) — Docker tab churn, built-in API
