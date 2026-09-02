# Compose value autocomplete, and field help in the form

## Context

You typed a `restart:` **value** in the compose editor and nothing was offered. Chasing it produced
three findings:

1. **The test box was up to date** — all four files matched local checksums exactly.
2. **Nothing was broken.** Hover help and key autocomplete both work; I triggered them on `restart`
   in the browser and got the right answers back.
3. **Value autocomplete was never built.** A4 built *key* autocomplete only — it offers `restart`
   while you type the setting name and deliberately stops at the colon.

A survey of what the plugin knows about compose values then turned up something larger: **the form
already holds thirteen value vocabularies that the text editor cannot see at all**, and beyond those
there are around twenty more closed value sets that neither view knows. `deploy` is the worst — the
whole block has one described key against six distinct closed vocabularies.

Chasing it also exposed a second gap. The 134 descriptions written in A4a are reachable **only** by
hovering a key in the Compose pane. The Form pane — the friendlier view, and the reason this project
exists — shows none of them. If you were looking at the form when you went hunting for a hint, there
was nothing there to find.

Five phases. **Phases 1–3 are the core**; 4 and 5 are separable and can be dropped or deferred.

---

## The three rules this design turns on

Everything below follows from these, so they come first.

**1. Never a closed list.** Any compose value may be written `${VAR}` or `${VAR:-default}`, and the
model already has `interpolates()` (`compose-model.js:1733`) and `splitOutsideVars()` (`:751`) for
exactly that. Every suggestion the editor offers is datalist-style — a hint you can ignore and type
straight past. The form's `<select>` model is right for the form and wrong here.

**2. "Closed head, open tail" is one concept, not four special cases.** `on-failure:3`,
`service:<name>`, `container:<name>`, `type=registry,ref=…` are all the same shape: a known prefix
with free text after it. Model it once. Today `restart`'s numeric tail is unsupported and
`container:` is omitted from three settings because there was nowhere to put the idea.

**3. Some "closed" sets are lies.** `logging.driver` accepts any Docker logging plugin; `runtime`
depends entirely on that server's `daemon.json`; `platform` is `os/arch[/variant]` and open-ended.
For these, suggest and never constrain — and where the truth is host-specific, either ask the server
(the `netLoad()` / `imgLoad()` pattern) or offer nothing.

---

## Phase 1 — One vocabulary registry

### The problem

The thirteen value lists live in `CHOICES` (`stacks.js:929`), plus `BOOL_CHOICES` (`:1076`) and
`CAP_OPTIONS` (`:1133`). `stacks.js` is browser-only and has no tests — there is no browser on this
machine. `compose-model.js` is the only file the harness can reach.

### The hook already exists

`KEYS[...].choices` in `compose-model.js` (`:547`, `:548`, `:579-582`) already carries vocabulary
ids — `'restart'`, `'netmode'`, `'pullpolicy'`, `'stopsignal'`, `'ipc'`, `'pid'`. **Nothing reads
them.** `stacks.js` keys `CHOICES` on `'setting/<key>'` instead. It is dead metadata pointing exactly
where a shared registry should go.

### What to build

A `VOCAB` table in `compose-model.js`, next to `DESCRIPTIONS`, keyed by those ids. Each entry:

```js
{ open: true,                    // always — see rule 1
  head: [['on-failure', 'on-failure — only when it crashes'], …],
  tail: 'count'                  // optional: this head takes ':<something>' after it
}
```

Then:

- `stacks.js`'s `CHOICES` is rebuilt from `VOCAB`, keeping the parts that are the form's own
  business: the `hint` string shown as a column tooltip, the `open` flag for datalist boxes, and the
  dynamic joins (`serviceModeOptions()` `:1250`, `fromChoice()` `:1214`, `profileOptions()` `:1326`,
  `imageOptions()` `:1292`).
- `KEYS[...].choices` stops being dead and becomes the lookup.

**A snapshot test is the point of this phase.** Assert every option value and label produced after
the move is byte-identical to what `CHOICES` produced before it. The refactor must provably change
nothing a user sees. Without that test this phase is not worth doing.

**Do not repeat `netLoad()`'s trick.** It mutates `CHOICES['setting/network_mode'].options` in place
(`stacks.js:4140`) so server networks appear. `serviceModeOptions()`'s comment (`:1246`) explains why
per-call joining was chosen instead. The registry is static; anything host-derived is joined on at
call time.

---

## Phase 2 — Value autocomplete in the editor

### Finding the value under the caret

New model function, mirroring `keySuggestions()`:

```js
API.valueContextAt = valueContextAt;
// valueContextAt(text, offset) -> null | {path, key, which, start, end, prefix}
```

`null` unless the caret is **after the colon** on a key line, or inside a `- ` sequence item under a
key with a vocabulary. `start`/`end` bracket the partial value so accepting replaces exactly it.

Everything needed already exists and must be reused, not re-derived:

- `classify()` (`:56`) already reports `valueCol` (`:101`), `contentCol` (`:73`) and `sub` (`:77`)
  for `- ` lines.
- `keyPositionOnLine()` (`:4393`) is the exact mirror of the function to write.
- `keyPathAbove()` (`:4353`) already walks upwards to establish context and is shared with
  `keySuggestions()`. Factor it out rather than copying it.

Like `keySuggestions()`, this must **never call `parse()`** — the section comment at `:4081-4090`
gives the reason: the file is mid-edit exactly when a suggestion is wanted, which is when `parse()`
has the least to say.

### Getting the options

Two sources, in order:

1. **The registry** (Phase 1), via `KEYS[key].choices` or the path for nested keys.
2. **Where a field already exists, reuse the form's own cascade.** `fieldAtLine()`
   (`compose-model.js:2056`) maps a line to a field, and `choiceFor(f, which)` (`stacks.js:1340`)
   then yields the fully-joined options — server networks, pulled images, declared names, profiles,
   capabilities — with no second code path. This is the cheap win and should be tried first.

The second only works where the form harvests a field, which Phase 5 shows is far from everywhere.
The registry is what covers the rest.

### Wiring

The suggestion panel from A4a is reused whole — same markup, same keyboard handling, same
positioning and clipping logic. `runSuggest()` (`stacks.js:~4830`) asks `keySuggestions()` first and
falls back to the value path, so there is one panel and one code path. A row shows the value on the
left and its plain-English label on the right, exactly as key rows show key and title.

---

## Phase 3 — Fill the empty vocabularies

These are closed, small, and known to nobody in the plugin today. Each benefits **both** views, since
the form reads the same registry.

| Where | Key | Values |
|---|---|---|
| service | `uts` | `host` |
| service | `cgroup` | `host`, `private` |
| service | `userns_mode` | `host` |
| service | `isolation` | `default`, `process`, `hyperv` |
| service | `attach`, `oom_kill_disable` | booleans — and they are **not typed as boolean** today |
| `deploy` | `mode` | `replicated`, `global`, `replicated-job`, `global-job` |
| `deploy` | `endpoint_mode` | `vip`, `dnsrr` |
| `deploy` | `restart_policy.condition` | `none`, `on-failure`, `any` |
| `deploy` | `update_config.order`, `rollback_config.order` | `start-first`, `stop-first` |
| `deploy` | `update_config.failure_action` | `continue`, `rollback`, `pause` |
| `logging` | `options.mode` | `blocking`, `non-blocking` |
| volumes long form | `type` | `bind`, `volume`, `tmpfs`, `npipe`, `cluster`, `image` |
| volumes long form | `bind.propagation` | `rprivate`, `private`, `rshared`, `shared`, `rslave`, `slave` |
| volumes long form | `bind.selinux` | `z`, `Z` |
| ports long form | `mode` | `host`, `ingress` |
| ports long form | `protocol` | `tcp`, `udp` |
| `build` | `network`, `isolation`, `entitlements` | `none`/`host`/`default`; the isolation trio; `network.host`/`security.insecure` |
| `develop` | `watch[].action` | `sync`, `rebuild`, `restart`, `sync+restart`, `sync+exec` |
| `env_file` long form | `format` | `raw` |

**Also correct these while in the table:** `pull_policy` is missing `if_not_present`, `refresh`,
`daily`, `weekly`; `networks.driver` is missing `overlay`; `cap_add`/`cap_drop` are missing `ALL`;
`restart` cannot express `on-failure:3` (rule 2 fixes this).

**`deploy` needs its keys before its values.** `DESCRIPTIONS.deploy` has one entry (`resources`), and
`suggestionContext()` (`:4330`) feeds key autocomplete from `leafTopKeys(LEAVES.deploy)` — which also
yields only `resources`. So `deploy.mode` is not suggested as a *key* either. Same for several
declaration keys: `DECL_LEAVES` drives both form fields and editor key suggestions, which is why
`configs.environment`, `secrets.name` and `networks.enable_ipv6` are invisible in both. Splitting
those two jobs is the same trap `BUILD_LEAVES` was created to avoid (`:4092`) — follow that
precedent.

---

## Phase 4 — Names declared elsewhere in the file

Offer the file's own service names for `depends_on`, its declared networks, volumes, secrets and
configs where they are referenced, and its profiles.

The form does this from `MODEL.declared` (`compose-model.js:1922`), which needs a successful
`parse()`. The editor must not depend on one. **The scan to write is the complementary half of one
already written**: `hostPaths()` (`:4505`) walks a mapping-key stack from `classify()` alone and
notes at `:4510-4513` that it deliberately never reaches the top-level `volumes:` block — which is
precisely the block this needs.

Two rules currently live in `stacks.js` and must move into the model or be duplicated deliberately:
`default` is appended to the networks list when absent (`:1216`), and a service is filtered out of
its own `depends_on` options (`:1219`).

---

## Phase 5 — Long forms are second-class (separable; may deserve its own plan)

Not autocomplete work as such — these are places the form harvests nothing, so there is no field, no
vocabulary and no help:

- **Long-form ports** lose `protocol` and `mode` entirely — `harvestLongForm()` (`:981`) reads
  `protocol` only to compute a key, and never reads `mode`. It also bails outright when `target:` is
  absent (`:989`), so the entry vanishes from the form.
- **Long-form volumes** keep only `source`, `target` and `read_only`; `type`, `bind.*`, `tmpfs.*`,
  `consistency` and `subpath` are invisible.
- **Long-form `secrets:` / `configs:`** are declared as plain lists (`KEYS` `:557-558`), so a map
  entry is locked with *"this entry is written as a block of its own"*.
- **Map-form service `networks:`** is dropped silently — `harvestList()` returns at `:916` when the
  value is not a sequence.

Worth doing, but it is form-harvesting work with its own risk profile. Recommend deciding on it
separately once 1–3 are in.

---

## Part B — Field help in the Form pane

Independent of the phases above and can land at any point.

### The affordance

A small ⓘ beside a field's name. Click or tap it and the sentence appears under the row; click again
to hide.

**Not a plain tooltip**, and that is a house rule. `StacksPage.php:203-209` argues it already: *"The
hint is a visible line rather than a title attribute, because a tooltip cannot be reached on a phone
and this sentence is the one that tells the two apart."* The stylesheet makes the same argument where
the caption row is deleted on a phone and a visible hint takes over. Throughout this codebase `title=`
carries short control names and is always duplicated somewhere reachable.

### Where it attaches — the part needing care

Only `binder === 'setting'` rows have a visible label (`stacks.js:1933`, `.staxx-fieldlabel`).
Ports, volumes, variables and the other list groups have **no label at all** — their meaning comes
from the group's caption row.

| Row shape | Where the ⓘ goes |
|---|---|
| `setting` (Container, Health check, Resource limits, Advanced) | beside the field's own label |
| list / mapped / declared groups | beside the **group heading** |

That leaves nothing unexplained and puts the `ports` sentence where someone looking at the Ports
group would want it.

### Two constraints already documented in the code

- **Full-width children come last.** `stacks.js:2062-2065`: *"A full-width child ends the grid row it
  lands on and resets auto-placement to column 1 below it, so anything emitted after one is stranded
  in the label column."* The help paragraph is pushed in the tail block beside `adviceBlock` and
  `commandSay`, after the `×`.
- **Reuse `.staxx-fieldhint`, not `.staxx-fieldnote`.** Both are already in the full-width
  selector list (`css:2535-2547`), but `.staxx-fieldnote` is accent-coloured and means *something
  is wrong here*. Help is ordinary grey prose.

The phone layout needs no new rule — every template already collapses to `1fr auto` and forces
full-width children across (`css:5061-5080`).

Wording comes from `DESCRIPTIONS` (`compose-model.js:4126`) through `keyInfo(key, where)` (`:4298`),
whose titles were written to match the form's own. **Add a test that fails when a rendered field has
no help**, so the two cannot drift as fields are added.

---

## Explicitly not changing

- **Autocomplete still hides a key the service already has.** You chose this. Typing `rest` in a
  service that already has `restart:` keeps offering nothing — correct, since a mapping cannot hold
  the same key twice, and the likeliest reason the feature looked dead when you first tried it.
- No suggestions for genuinely free values: `command`, `entrypoint`, `user`, `hostname`,
  `container_name`, `environment`, `labels`, paths, and every numeric or duration setting.
- `mem_swappiness` is bounded (0–100), not closed. A hundred-and-one-item list helps nobody.

---

## Verification

Locally, before every deploy:

```sh
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
python tests/validate_schema.py
```

Test coverage worth naming:

- **The Phase 1 snapshot** — every option value and label identical before and after the move. This
  is the one that makes the refactor safe.
- `valueContextAt` returns `null` before the colon and a context after it; `null` in a comment, in a
  key, and on a blank line.
- `restart: unl` narrows to one; `restart: ` offers all; `restart: on-failure:` accepts a free tail.
- Booleans offer exactly `true` and `false` — and nothing rejects `yes`/`no`, which YAML 1.1 permits
  and the codebase is currently inconsistent about (`:1014` accepts `yes`, `:1725` does not).
- A `${VAR}` value is never blocked or rewritten.
- `depends_on` offers the file's own service names and never the service itself.
- Free-text keys return `null`.
- Every field the form renders resolves to a description.

In the browser, in this order:

1. Type `restart: ` in the Compose pane — options appear; accepting inserts the bare value.
2. Type `restart: unl` — narrows. Then `restart: on-failure:3` — accepted, not fought.
3. Type `image: ` — nothing appears.
4. Type `${` anywhere a suggestion was showing — the list gets out of the way.
5. Click the ⓘ on the form's Restart row, and on the PORTS group heading.
6. Confirm the form is no taller than before when nothing is expanded.
7. On a narrow window, confirm the ⓘ is tappable and the revealed sentence readable.

Deploy each phase to the test box as it finishes, as with the editor phases.
