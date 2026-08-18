# PLAN_17 — Search Community Applications, and import an app as a compose file

**Status: COMPLETE, 2026-08-16.** Built, deployed and verified on the test box — search, import,
form render and save all work end to end, and `docker compose config -q` accepts the generated file.

Six things in the plan below turned out to be wrong once measured against the live feed, and the
build follows what was measured rather than what was assumed. They are written up in
`CA-IMPORT-RESULTS.md`, which is the document to read; the most consequential are that the plugin
filter proposed here catches nothing, that a build takes 1.3 s rather than "about a minute", and
that ranking a browse by download count is wrong because that figure belongs to the image, not the
app.

---

## What you would notice

Today the only way to get a compose file is to type one. "Add stack" opens the editor on a six-line
skeleton — `my-app`, `alpine:3.20` — and everything after that is yours to write.

This adds a second button, **Apps**. It opens a search box over the Community Applications
catalogue, about 4,100 of them. Pick one and it becomes a compose file: image, ports, paths,
variables, icon, web interface link, and the description beside every setting. That file opens in
the editor you already have, as a normal new stack, for you to look over before saving.

`docs/feasibility.md:142-150` already recommends exactly this shape — single-template, on-demand
conversion first, as "the proving ground for the mapping logic". `README.md:49` lists it under
planned scope.

## The two findings that decided the shape of it

**Nothing here can write YAML from a data structure.** `compose-model.js` is an editor over an
existing array of lines — its own header says "there is no code path that rebuilds the file from the
tree", and `addService()` refuses outright to invent a `services:` key from nothing. PHP has no YAML
emitter, and Unraid's PHP has no YAML extension. So the converter has to produce YAML **text**, and
text is already the browser's job: JavaScript holds the document, PHP receives a finished string,
validates it with `docker compose config -q` and writes it. The converter therefore lives in JS.

**The catalogue already holds the parsed template.** `applicationFeed.json` gives every app a
`Config` array of `{"@attributes": {Name, Target, Default, Mode, Type, Description, Display,
Required, Mask}, "value": "…"}`. No XML parsing is needed anywhere. And the three attributes that
matter most land on things this project already has: `Description` becomes the comment beside the
setting, `Required` becomes `-!R`, `Mask` becomes `-!S`. That is what makes an imported app render
as a proper form rather than a wall of YAML — the reason to do this at all.

**Decisions taken, Adrian's call:** catalogue search only for now; cache in `/tmp`, fetched on first
use, nothing written to the flash drive; its own button on the page.

---

## Part A — The catalogue cache

The feed is 24 MB. Too big to decode on every search, and far too big to decode inside a page
render. It is downloaded and split **once**, on first use, by a detached CLI script — the same
bargain `Icons.php` strikes, and for the same reason: nothing reaches the network during a page
render.

`/tmp/staxx/ca/`, a sibling of the existing `jobs/`:

| File | Contents |
|---|---|
| `apps.jsonl` | one app's full JSON entry per line, fat fields stripped (`trends`, `downloadtrend`, `pluginStats`, `Screenshot`, `CAComment`, `Moderator*`) |
| `index.json` | `{built, count, categories:[…], apps:[{n,r,a,i,c,d,o,len}, …]}` — name, repo, author, icon, category, downloads, and the byte **o**ffset and **len**gth of that app's line in `apps.jsonl` |
| `status.json` | `{state: 'building'\|'ready'\|'failed', message, built}` |
| `.lock/` | atomic-`mkdir` lock, the trick `scripts/stats-collector.sh` already uses |

`index.json` lands around 1 MB, so a search decodes 1 MB rather than 24. An import seeks one line.
Entries carrying `PluginURL` and no `Repository` are plugins, not containers, and are dropped during
the split.

The split walks the file with a **brace-depth scanner** rather than `json_decode`-ing the whole
thing — it cuts one element out of `applist` at a time, so peak memory is one app, not 400 MB, and
no `memory_limit` fiddling is needed anywhere.

Staleness is `filemtime` on `index.json` against a 24 h TTL, the same shape as
`STAXX_ICON_INDEX_TTL` at `Icons.php:62`.

### `include/CA.php` — new

Cache paths and TTL constants, then five functions:

- `staxx_ca_status(): array` — reads `status.json`; a missing or stale index reads as `'stale'`.
- `staxx_ca_refresh_start(): void` — takes the `mkdir` lock, then detaches
  `php <STAXX_ROOT>/scripts/ca-index.php` with `setsid … </dev/null &`, copying
  `staxx_start_job()` at `Stacks.php:1673`. Does nothing at all if the lock is held.
- `staxx_ca_search(string $q, string $cat, int $limit = 60): array` — decodes `index.json` and
  ranks: exact name, name prefix, name contains, repository contains, author contains; ties broken
  by download count. Returns index **ordinals**, never byte offsets.
- `staxx_ca_app(int $i): ?array` — looks the ordinal up in `index.json`, `fseek`/`fread`s that
  one line out of `apps.jsonl`, decodes it. The client only ever sends an ordinal, so a bad value is
  a missing array key rather than an arbitrary file read. That is the whole reason offsets stay
  server-side.
- `staxx_ca_categories(): array` — the feed's own category list, for the filter dropdown.

### `scripts/ca-index.php` — new

CLI only. Writes `status.json` = building; downloads the feed with PHP curl straight to a file
(`CURLOPT_FILE`, 180 s, the `StaXX (Unraid plugin)` user agent that `staxx_icon_get()`
already uses at `Icons.php:339`); brace-depth splits it into `apps.jsonl` and `index.json` inside a
`ca.new/` directory; `rename()`s that into place so a reader never sees a half-built cache — the
reasoning behind `staxx_icon_write()` at `Icons.php:326`; writes `status.json` = ready; releases
the lock in a `finally`, including on failure.

---

## Part B — The converter

### `javascript/ca-convert.js` — new

Pure, no DOM, dual-target exactly like `compose-model.js` — `window.StaxxCA` in the browser,
`module.exports` under node, so the test harness can require it directly.

```js
convert(app) → { name, service, yaml, warnings: [] }
```

| Source | Compose |
|---|---|
| `Name` | service key and `container_name`, normalised: lowercase, invalid characters → `-`, ≤63, must start alphanumeric, to satisfy `staxx_valid_name()` at `Stacks.php:53` |
| `Repository` | `image` |
| `Network` | `bridge` → omitted, since compose makes its own; `host` / `none` → `network_mode` |
| `Privileged` | `privileged: true` |
| `PostArgs` | `command` |
| `Config Type="Port"` | `ports: - "<host>:<Target>/<Mode>"`, a `/tcp` suffix dropped |
| `Config Type="Path"` | `volumes: - "<host>:<Target>:<Mode>"` |
| `Config Type="Variable"` | `environment: <Target>: "<value>"` |
| `Config Type="Device"` | `devices` |
| `Config Type="Label"` | `labels` |
| `Icon`, `Category` (first token), `Project`, `Support`, `ReadMe`, `Repo`, `Overview` | top-level `x-unraid` |
| `WebUI` | service `x-unraid.webui`, passed through unchanged — `[IP]` and `[PORT:n]` are already this schema's own syntax (`schema/x-unraid.schema.json:72-75`) |

Per-setting rules:

- The value is `entry.value` when non-empty, else `@attributes.Default`. A `Default` holding
  `a|b|c` is Unraid's choice-list convention — take the first when there is no value.
- `Description` becomes the trailing comment, flattened to one line and truncated to about 160
  characters. `Required="true"` appends ` -!R`; `Mask="true"` appends ` -!S`. Comments align to a
  fixed column, as in `examples/jellyfin/compose.yaml`.
- A **Path** with no value gets `/mnt/user/appdata/<name><Target>`; a **Port** with no value gets
  the container port. Both go into `warnings`, so the guess is visible and never silent.
- A **Device** with no value is skipped and warned — there is no sane guess for one.
- Variables are emitted even when empty (`KEY: ""`). The form then shows them with their
  descriptions, which is the entire point of importing the metadata.
- CA's per-setting `Display: advanced` is **dropped**. The x-unraid schema deliberately has no
  per-setting block (`docs/x-unraid-schema.md:135-155`) and this plan does not add one.

### `ExtraParams` is the lossy edge, and it must stay visible

`docs/feasibility.md` names it: arbitrary `docker run` flags with no compose equivalent, and "any
conversion needs an explicit *couldn't translate this* path rather than silent dropping."

A small mapping table covers the flags that do have one — `--restart`, `--cap-add`/`--cap-drop`,
`--device`, `--shm-size`, `--hostname`, `--runtime`, `--user`, `--network`, `--security-opt`,
`--sysctl`, `--dns`, `--group-add`, `--pid`, `--ipc`, `--read-only`, `--init`, `--tmpfs`,
`--log-driver`/`--log-opt`. **Anything unrecognised goes into `warnings` verbatim.** Nothing is
dropped quietly. `restart: unless-stopped` is the default when `--restart` says nothing.

### The output has to be a file this codebase can read back

The generated text must survive `parse()` → `serialise()` byte for byte and `buildForm()` cleanly.
So: block mappings only, two-space indent, no anchors, aliases, merge keys or flow collections —
all of which `parse()` seals as opaque — and `x-unraid` last within the service, where
`addSetting()` already keeps it.

### `tests/ca_convert.js` — new

No framework; the same `ok(name, cond, detail)` harness as `tests/yaml_roundtrip.js:35`. Fixtures
are real feed entries written into the test file: `binhex-emby`, one with `ExtraParams` junk, one
with empty Paths and Ports, one on `network: host`. It asserts that

- the generated text round-trips through `compose-model.js` unchanged and `buildForm().ok` is true
  with nothing sealed;
- every row of the mapping table lands where the table says;
- `-!R` and `-!S` appear exactly where `Required` and `Mask` said, and nowhere else;
- every unmapped `ExtraParams` flag appears in `warnings`.

Dropping one converted file into `examples/` also gets it picked up free by `findComposeFiles()` and
by `tests/validate_schema.py`.

---

## Part C — Wiring it to the page

- **`include/action.php`** — `require_once` `CA.php`, and two cases beside the existing `tags` case
  at line 314, which is already the one outbound-HTTP action:
  - `ca-search` → `{ok, state, apps:[…], categories:[…]}`. When the cache is stale or missing it
    fires `staxx_ca_refresh_start()` and returns `state:'building'` with no results, and the
    client polls. Same protocol as `staxx_icon_sweep()`'s `done:false`.
  - `ca-app` → `{ok, app:{…}}` for one ordinal.
- **`include/StacksPage.php`** — an **Apps** button beside `staxx-add` (L120-128); a
  `<dialog id="staxx-ca">` modelled on `#staxx-tz` (L454-560), with a search box, a category
  select, a result list and a status line; and a `<script>` tag for `ca-convert.js` carrying the
  `filemtime()` cache-buster, loaded **before** `stacks.js` (L675-678).
- **`javascript/stacks.js`** — `caOpen()` / `caClose()` / `caSearch()`, following `tzOpen()` at
  L4051: `showModal()`, a debounced `call('ca-search', …)`, results rendered into the list, a
  backdrop click and a `close` handler to clean up. Clicking a result calls `ca-app`, hands the
  entry to `StaxxCA.convert()`, closes the dialog and calls **`openEditor(name, yaml, true)`**
  at L4559. Everything downstream — `save()`, `staxx_save_stack()`, `compose config -q` — is
  already built and needs no change. Warnings go to `showError()` (L624) so they sit above the form
  where they cannot be missed. Search is server-side, so the page never holds a large payload.
- **`sheets/staxx.css`** — `staxx-ca-*` rules for the result rows: icon, name, repo, a
  one-line overview. All prefixed, as the house rule requires.
- **`README.md`** and **`docs/`** — move CA conversion out of planned scope, and a short section on
  what converts, what does not, and where the `ExtraParams` warnings come from.

---

## Verifying

Everything that can run on Windows, before any deploy:

```sh
node --check src/.../javascript/ca-convert.js
node tests/ca_convert.js
node tests/yaml_roundtrip.js          # must still pass; a new examples/ file is picked up free
node tests/js_undeclared.js           # stacks.js is one strict-mode IIFE — this is the real guard
python tests/validate_schema.py
```

On the server, after `pscp` and `bash /boot/staxx-dev/dev-install.sh`:

```sh
php -l include/CA.php && php -l include/action.php && php -l scripts/ca-index.php
time php /usr/local/emhttp/plugins/staxx/scripts/ca-index.php
ls -la /tmp/staxx/ca/          # index.json ~1 MB, apps.jsonl ~12 MB, status.json ready
```

Then the no-browser check `CLAUDE.md` describes — a throwaway PHP script beats driving the UI:

```php
require '/usr/local/emhttp/plugins/staxx/include/CA.php';
print_r(staxx_ca_search('jellyfin', ''));   // ranking, and jellyfin first
print_r(staxx_ca_app(0));                   // one entry, decoded
var_dump(staxx_ca_app(999999));             // null — not a warning, and not a file read
```

Finally, in the browser: open **Apps**, search, watch the "building the catalogue" state resolve on
first use, import an app, confirm the editor opens on the generated file, that the form renders the
descriptions and the required markers, and that Save succeeds. Import `binhex-emby` in particular —
it carries `ExtraParams --restart=always`, a choice-list `Default="yes|no"` and `<Config/>` rows
with no value, so it exercises three of the awkward paths at once.

---

## Left out

- **Converting the containers you already run.** Their templates sit at
  `/boot/config/plugins/dockerMan/templates-user/my-*.xml`. Once `ca-convert.js` exists this is a
  second tab on the same dialog plus a small `simplexml` adapter reshaping the XML into the feed's
  entry shape. Deliberately deferred so the mapping is proved against the catalogue first —
  `docs/feasibility.md` open decision 4.
- **Multi-container apps.** One template is one container, so one import is one service. Adding a
  database beside it is what the editor is for.
- **A setting to switch the catalogue fetch off.** Nothing downloads until the Apps dialog is
  opened, so the fetch is already opt-in by action. A second `ICON_FETCH`-style switch would be
  configurability nobody asked for.
