# One key table, nothing hidden, and the file's declarations as a namespace

**Status: COMPLETE** — both phases built and deployed to the test box on 2026-08-13. Kept for
reference. Phase 3 is `PLAN_5.md`, phases 4–6 are `PLAN_6.md`.

`tests/yaml_roundtrip.js` sections L and M cover these two phases, taking the suite from 278 to
**362** cases.

Five things came out differently from the approved plan, all noted in place below.

- Notes became **`advice`, an array**, rather than riding on the single `lockReason` string, so a row
  can carry two at once and `refreshRanges()` can keep them live without a redraw.
- `buildForm`'s `declared` is **seeded in the initialiser**, because both early returns happen before
  it would otherwise be filled and a caller must never find it undefined.
- **1g was added** — a brace-aware split, found while verifying 1f.
- A `'list'` field's id carries **its index**, always: `web/list.networks#0/frontend_net`. Keying on
  the value alone gave two entries sharing a value one id, and `fieldById` returns the first, so
  editing the second row rewrote the first row's line — in a hand-written file as much as in one the
  form had just added to. `docs/x-unraid-schema.md:317` asks a row to key on the half that does not
  move when it is edited; a plain list entry has no such half, because the entry *is* the value, so an
  index is the more stable choice rather than the less. It is unconditional on purpose: suffixing only
  the colliding subset meant editing one entry changed a **sibling's** id the moment the two matched.
- `newEntry()`'s plain-list placeholders go through `freeName()` like every other binder's, so a second
  press of a list's Add button cannot write the same line twice. `expose` increments its port number
  and `dns` its last octet instead of taking a suffix, because `8080-2` and `1.1.1.1-2` read as valid
  and are not.

## Context

`scratch/test-stacks/10-advanced-compose-test/compose.yaml` is an edge-case file. Its `web` service
declares nine keys; the form shows five of them, and offers a tenth — an empty `network_mode`
dropdown — that is not in the file at all. `networks`, `depends_on`, `deploy` and `healthcheck`
are **not shown read-only with a reason** — they are not shown at all. Nothing is destroyed by
saving, because every write is a surgical line splice, but a form that silently omits a service's
health check, resource limits, dependencies and networks is telling the user something untrue about
their own file. `docs/x-unraid-schema.md:336` already promises the opposite.

The reframing that drives this: **the top-level `networks:`, `volumes:`, `secrets:` and `configs:`
blocks are declarations — a namespace of names declared once, then referenced by services.** Read
them and the form stops guessing. `db_data` is not a host path that happens to look odd, it is a
declared volume, because the file says so eight lines further down.

That collapses three problems into one solution: free-text boxes become lists of real names, a
reference to an undeclared name becomes something we can point at, and named-volume-versus-folder
becomes a lookup rather than a regex.

## Why it happens — one missing `else`, and six tables

`harvest()` (compose-model.js:850–880) ends:

```js
if (listKeys[key]) { … }          // ports, volumes, devices
if (pairKeys[key]) { … }          // environment, labels
if (SETTINGS.indexOf(key) >= 0) out.push(settingTarget(p, key, lines));
// ← nothing here
```

The whole vocabulary is 10 settings and 5 list keys. That knowledge is spread over six literals —
`SETTINGS` (:526), `ALWAYS` (:532), `listKeys`/`pairKeys` (:853–854), `LIST_KEY` (:1224) — so adding
one key today means edits in four places.

## Three things already true, which is what makes this affordable

1. **`settingTarget()` (:821) already handles a map or list value correctly** — locks the row,
   rebuilds the raw block from the file's own lines dedented by the key's indent (:844–846), and
   picks a plain-English reason. It needs nothing but a pair and a key name.
2. **The parser is recursive with no depth limit** (`parsePair:330`) and `writeScalar` (:512) needs
   only a spot — which is what makes `PLAN_5.md` cheap.
3. **A volume's host half already rewrites the whole scalar around itself** (:645), which is what
   makes phase 6 a renderer-only change.

---

# Phase 1 — one table, nothing hidden, declarations read

## 1a. The `KEYS` table

Replaces all six literals. Per key: the shape it may take, what to call it, which namespace its
values come from.

```js
var KEYS = {
  image:          { shape: 'scalar', always: 1 },
  container_name: { shape: 'scalar', always: 1 },
  restart:        { shape: 'scalar', always: 1, choices: 'restart' },
  network_mode:   { shape: 'scalar', always: 1, choices: 'netmode', excludes: 'networks' },

  ports:          { shape: 'list',  entry: 'port'   },
  volumes:        { shape: 'list',  entry: 'volume', from: 'volumes' },
  devices:        { shape: 'list',  entry: 'device' },
  environment:    { shape: 'pairs' },
  labels:         { shape: 'pairs' },

  networks:       { shape: 'list',  entry: 'plain', from: 'networks' },
  secrets:        { shape: 'list',  entry: 'plain', from: 'secrets'  },
  configs:        { shape: 'list',  entry: 'plain', from: 'configs'  },
  depends_on:     { shape: 'list',  entry: 'plain', from: 'services', title: 'Starts after' },
  profiles:       { shape: 'list',  entry: 'plain' },
  dns:            { shape: 'list',  entry: 'plain', title: 'DNS servers' },
  cap_add:        { shape: 'list',  entry: 'plain', title: 'Extra permissions' },
  expose:         { shape: 'list',  entry: 'plain', title: 'Internal ports' },
  env_file:       { shape: 'list',  entry: 'plain', title: 'Variable files', tool: 'browse' },

  healthcheck:    { shape: 'block', title: 'Health check' },
  deploy:         { shape: 'block', title: 'Resource limits' },

  command:        { shape: 'scalar' },
  entrypoint:     { shape: 'scalar' },
  user:           { shape: 'scalar' },
  hostname:       { shape: 'scalar' },
  privileged:     { shape: 'scalar', type: 'boolean' },
  shm_size:       { shape: 'scalar' },
  working_dir:    { shape: 'scalar', title: 'Working folder' },
  mem_limit:      { shape: 'scalar', title: 'Memory limit' }
};
```

`always: 1` replaces `ALWAYS`. `shape` replaces `listKeys`/`pairKeys`/`SETTINGS`. `entry` replaces
`LIST_KEY`. `from` is the namespace.

- `harvest()` becomes a dispatch on `KEYS[key].shape`; the ALWAYS pass iterates keys where
  `KEYS[k].always`, **in table order**, which must stay `image`, `container_name`, `restart`,
  `network_mode`.
- `inferTitle` (:979) gains one line reading `KEYS[t.target].title`.
- `inferType` (:985) reads `KEYS[t.target].type` instead of special-casing `privileged`.
- `LIST_KEY` (:1224) is derived from the table rather than written twice.
- Shapes `list` with `entry: 'plain'`, and `block`, are stubs in this phase — phase 2 and `PLAN_5.md`.

## 1b. The catch-all floor

```js
// Compose has far more keys than the form has controls, and a key the form
// says nothing about reads as a key the file does not have. Hand every one
// left to settingTarget: a scalar becomes an editable Advanced row, a block
// becomes a read-only one that shows itself and says why.
if (key === '<<' || key.slice(0, 2) === 'x-') continue;
out.push(settingTarget(p, key, lines));
```

Two exclusions, both because the row would be a duplicate. `x-` keys are extensions, and `x-unraid`
is already rendered as the service's overview, icon and web-UI link (`buildForm:1080`). `<<` is the
merge key, and `buildForm:1086–1090` already sets `service.shared` and shows the note — **this
exclusion is what protects `07-yaml-quirks`**, the file with anchors and merges.

For nothing but this, every remaining scalar service key (`init`, `tty`, `stdin_open`, `pid`,
`runtime`, `stop_signal`, `cpus`, `mac_address`) becomes an editable Advanced row with a working note
box and both markers, because `settingTarget` returns a `commentSpot`. Every remaining block
(`logging`, `ulimits`, `sysctls`, `tmpfs`, `extra_hosts`) becomes a read-only row showing itself.

## 1c. Reading the declarations

In `buildForm()`, one level deep, no recursion. For each of `networks`, `volumes`, `secrets`,
`configs`: `doc.root.pairs[kind]` → `.value.keys` when it is a map. Plus `services`, already built.

```js
out.declared = { networks: [], volumes: [], secrets: [], configs: [], services: [] };
```

A name whose value is null (`frontend_net:` with nothing under it) still appears — `.keys` lists it.
`external: true` names count: declared here, created elsewhere, still a valid reference.

**The server's own docker networks are a different namespace and must not be mixed in.** A service
may only join a network its own file declares, or `default`, which compose creates. `netLoad()`
(stacks.js:2135) appends the server's networks to the `network_mode` dropdown and stays exactly as
it is — but they must never reach the `networks:` list, or the form would offer a value that makes
the file invalid.

## 1d. Bug — the form can write an invalid file

Compose **refuses a service carrying both `network_mode` and `networks`**. `network_mode` is
`always`, so it shows as an empty dropdown on every service, including one that already has
`networks:`. Choosing `bridge` there stops the stack starting.

`excludes` drives the fix. In the ALWAYS pass, before the absent case:

```js
// Compose refuses a service with both of these, so an empty slot here is a
// trap: filling it in would make a working file invalid.
if (spec.excludes && serviceMap.pairs[spec.excludes]) {
  out.push(target('setting', akey, {
    parts: { value: part('', null) }, range: null, blocked: true,
    advice: ['this service joins the networks listed below instead']
  }));
  continue;
}
```

`blocked` is a new flag, **not** `locked` — locked would render an empty `<pre>` and lose the
explanation. Three edits carry it:

- `target()` (:651) — `blocked: !!opts.blocked`
- `fieldsFor()` (:1014) — `blocked` counts toward `usable`, so the row does not fall into `locked`
- `boxHtml()`'s `dead` test (stacks.js:565) — `|| f.blocked`

The field count per service stays constant, which is the hazard `PLAN_1.md` recorded: a row must never
appear or disappear without a redraw.

## 1e / 1f. Two kinds of advisory note

**Changed from the approved plan.** Both were to ride on `lockReason`, but that is a single string
already carrying `hostNote` for volumes and ports — a dangling named volume would need to say two
things at once. So notes become **`advice`, an array of strings**, carried by `target()` and
`fieldsFor()` and rendered as one `.stackman-fieldnote` each. `lockReason` keeps its one job: the
"not editable here" sentence. `advice` is additive and composable, and blocks nothing.

**1e — dangling references.** A service naming a network, volume, secret or service the file never
declares is an error compose reports only at start. With `from` in the table it is one lookup:

> no network called `frontend_nett` is defined in this file

Never a save-blocker: a file can legitimately be mid-edit, and a file already broken when opened must
still be saveable. `default` is always accepted for networks. A value containing `${` is never
flagged as dangling — we cannot know what it expands to.

**1f — interpolation.** Nothing in the JavaScript handles `${...}` at all today, so
`05-busybox-http`'s `"${HTTP_PORT}:80"` shows `${HTTP_PORT}` as ordinary editable text. Typing
`15107` over it hard-codes the value, which `scratch/test-stacks/README.md` forbids.

> this value uses a variable defined outside the compose file — typing over it replaces the variable
> with a fixed value

The box stays editable: changing `${HTTP_PORT}` to `${HTTP_PORT:-8080}`, or editing text around a
variable, are both legitimate, and refusing would be worse than explaining. Detection covers
`${VAR}`, `${VAR:-default}`, `${VAR-default}`, `${VAR:?err}`, `${VAR?err}` and bare `$VAR`, and must
**exclude `$$`** — an escaped literal dollar, not a variable.

### 1g. Found while verifying 1f — the splitters break on a variable's default

**Added to the phase, not in the approved plan.** `splitPortShort` (:589) and `splitPathShort`
(:621) split on every `:`, including one **inside** a `${...}`. So `"${ALT:-8081}:81"` splits into
three parts, and the host box shows `-8081}` — a mangled value presented as editable. Writing it back
produces `${ALT:<whatever was typed>:81` and the expression is destroyed. Worse than the hazard 1f
was written to close, and in exactly the same area.

Both splitters take their `:` positions from one brace-aware helper instead:

```js
// Split on ':' only outside a ${...}, because a variable's default value can
// contain one — "${PORT:-8080}:80" is two fields, not three, and splitting it
// naively hands the host box "-8081}" and destroys the expression on write.
function splitOutsideVars(s) { … }
```

`"${ALT:-8081}:81"` then yields host `${ALT:-8081}` and container `81`, the row stays correctly
editable, and 1f's note appears on it — which is the outcome 1f was for.

## Two mechanisms that are easy to confuse

| | Fills in | Reaches |
|---|---|---|
| `.env` in the stack folder | `${...}` **inside the compose file** | wherever the variable was written |
| `env_file: ./app.env` | nothing in the compose file | the **container's** environment |

`Stacks.php:1345` already treats `.env` and `.env.*` as legitimate members of a stack folder.
`env_file` here is a list of paths with the folder picker, and nothing more.

**Not doing yet:** showing what a variable resolves to, or listing an env file's contents as
read-only Variables rows. Both need a server round-trip. Recorded decision for when they arrive —
ask `docker compose config`, which `stackman_compose_meta()` (`Stacks.php:569`) already shells, and
do **not** hand-parse `.env`: compose gets `${VAR:-default}`, shell-versus-`.env` precedence and
`env_file` merge order right because it is the same code that runs the stack, and a hand-written
parser that disagrees with the container fails silently. So a service using `env_file:` still shows
an empty Variables group; the `env_file` row above it is what points at the file.

---

# Phase 2 — namespace dropdowns, and reference lists editable

## The splitter

```js
// A plain list entry is one whole value, so it keys on itself. Unlike a port
// or a mount there is no container half to bind to, which means renaming an
// entry IS replacing it.
function splitPlain(text, spot) {
  var s = String(text).trim();
  return s ? { key: s, parts: { value: part(s, spot) } } : null;
}
```

`harvestList` hardcodes `parts: { host: s.host, container: s.container }` (:700). One line lets a
splitter bring its own: `parts: s.parts || { host: s.host, container: s.container }`. Plus two
guards — skip a null return, and for `entry: 'plain'` do **not** route a map item to
`harvestLongForm` (:695), which would bail on the missing `target:` and drop it silently.

## Dispatch, and why the floor must exist first

```js
if (spec.shape === 'list' && spec.entry === 'plain') {
  if (p.value && p.value.kind === 'seq') { harvestList(out, 'list', p, splitPlain, lines, key); continue; }
  out.push(settingTarget(p, key, lines));   // depends_on's long form, etc.
  continue;
}
```

The fall-through is the point: `depends_on:` as a block of conditions is a map, not a seq, and must
still be shown in full. Without phase 1's floor it would go back to being invisible.

## One binder, many keys

`harvestList` gains a trailing `listKey`; `target()` and `fieldsFor()` carry it. `removeItem` (:1415)
becomes `f.listKey || LIST_KEY[f.binder]`; `addItem` (:1289) takes the same optional argument.
`groupFor()` returns `'list:' + f.listKey`.

A list group is emitted **only when the file already has that key**, so a service does not sprout
eight empty groups. Honest consequence: you can add a network to a service that already lists them,
but giving a service its first `networks:` is a Compose-view job until `PLAN_6.md` makes top-level
blocks writable.

`renderForm` (:1129) therefore builds buckets from the static `GROUPS` **union** the list keys
present — which also removes the `undefined.push` TypeError an unknown binder would cause today.
Column template is one box, identical to `--device`: add `--single` as a second selector on that
existing CSS rule rather than writing a new one.

## The dropdowns

A field whose key has `from` renders a `<select>` of that namespace instead of a text box, reusing
`optionsHtml()` (:542) — which already unshifts a value the list does not carry, so opening a
dropdown can never rewrite the file. `network_mode` additionally gains `service:<name>` options built
from `declared.services`: the same idea pointed at the namespace that key actually accepts.

Add-button words: Network, Secret, Config, Profile, Service (`depends_on`), DNS server, Permission,
Port (`expose`), File (`env_file`). Placeholders in `newEntry()` (:1258) — for a key with `from`, the
first declared name not already used, which `freeName()` already expresses; otherwise `default`,
`extras`, `1.1.1.1`, `NET_ADMIN`, `8080`, `./app.env`.

---

## Verification

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
python tests/validate_schema.py
```

New section in `tests/yaml_roundtrip.js`, with `10-advanced-compose-test` inlined as a fixture —
`scratch/` is gitignored, so it cannot be read from there.

**The strongest case: load that file, change nothing, save, and get a byte-for-byte identical file.**

Phase 1:

- `healthcheck:` yields a locked field titled "Health check" whose `raw` holds the whole block.
- `working_dir: /app` is editable; setting it rewrites only that span.
- A service with `x-unraid:` and one with `<<: *base` each yield no extra field.
- A service with `networks:` yields a `network_mode` field that is `blocked`, not `locked`, and
  `setPart` on it returns false.
- `declared.networks` is `['frontend_net','backend_net']`, `declared.volumes` is `['db_data']`,
  `declared.secrets` is `['db_password']`.
- Interpolation: `"${HTTP_PORT}:80"`, `${VAR:-8080}` and bare `$PORT` each carry the 1f advice;
  `"$$LITERAL"` and a plain `8080` carry none.

Phase 2:

- `networks:` yields two `list` fields with `listKey: 'networks'`; `addItem` appends a third;
  `removeItem` on the last deletes the key as well as the entry.
- `depends_on:` in long form yields **one locked field**, not two list entries.
- A reference to `frontend_nett` carries the 1e advice and does **not** appear in `requiredGaps()`.

Then `pscp` the plugin folder up, run `dev-install.sh`, `php -l` the `include/*.php`, hard-refresh:

1. `10-advanced-compose-test` — `web` accounts for **all nine** of its keys, plus the blocked
   `network_mode` slot.
2. Its `network_mode` is greyed with the note about joining named networks, and cannot be used.
3. `web`'s `networks:` rows are dropdowns offering `frontend_net` and `backend_net` — and **not** the
   server's own docker networks, which belong only to `network_mode`.
4. `db`'s `secrets:` row offers `db_password`; `depends_on` offers `web` and `db`.
5. `07-yaml-quirks` still round-trips untouched.
6. `06-fedora-advanced` — `profiles:` is an editable list on the optional service.
7. `05-busybox-http` — the host-port box shows `${HTTP_PORT}` with the 1f note under it. Save with no
   edit: the file must be identical, the port must still read `${HTTP_PORT}`, and the container must
   still answer on 15107.
