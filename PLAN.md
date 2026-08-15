# Phase 1 — Sections you can switch on and off without losing anything

Status: **built, verified and deployed**, 2026-08-14. Nothing is committed — the working tree is
left dirty for review. Local suite: **575 round-trip tests, 0 failed** (up from 529); schema, PHP
lint and `docker compose config` all clean on the test box.

## What the design gained while it was built

Three things the approved plan did not name, each forced by a defect found on the way:

- **`gap` and `blank` in the JSON.** `after` alone was not enough. `removeKey` collapses the blank
  line above a block when there is one below it too, so a block separated from its neighbour by a
  blank came back on the wrong side of it. `gap` records how far past the previous key the block
  sat; `blank` records whether a blank was removed with it. Both are optional and both default to
  "flush against the key above", so an entry written without them still reads correctly.
- **A bound on `ensurePath`'s retry.** It created a level, re-parsed, and looked again — forever, if
  the re-parse put the new line somewhere else, which is what a file with inconsistent indentation
  does. That was a stack overflow and a dead page. Now one insert per level and then a refusal.
  This bug pre-dates this work; `addNested` has had the same shape all along.
- **A rollback on a refused write.** `x-unraid.sections` is three levels deep, so giving up part way
  through left a bare `x-unraid:` and `sections:` behind in a file the form had just refused to
  edit. A refusal now restores the line array.

## Context

Last night's work gave roughly twenty compose fields a dropdown to pick from. But a dropdown you
cannot reach is no use, and most of those fields **only appear once the compose file already names
them** — which is the opposite of the point. You should be able to add the field from the form and
let the form write it into the file.

Three gaps cause that. This phase closes the biggest one.

| Gap | What it is | Phase |
|---|---|---|
| **Whole sections never render** | Nine list keys (capabilities, DNS servers, profiles…) and `logging` have no group at all until the file names the key — so no group, no Add button, no way in | **this one** |
| **Single settings** | Sixteen scalar keys (`pull_policy`, `read_only`, `command`…) land in Advanced only once the file has the line | 2 |
| **Round trip** | Confirm every new field survives form → compose → form unchanged | 3 |

There is already a working answer for exactly three sections: the tick boxes on the Container header
that switch **Health check**, **Resource limits** and **Depends on**. This phase turns those three
tick boxes into one button opening a list of every section — and changes what unticking *means*.

### The rule this phase is really about

Today, unticking a section that holds settings **deletes them**, after a confirm. That is the form
damaging a hand-authored file, which is the one thing this project treats as a bug rather than a
trade-off. So it stops.

**Unticking moves a section into `x-unraid`; re-ticking moves it back, byte for byte.** The block's
own lines — comments, indent, quoting, blank lines — are kept verbatim as one JSON line. Compose
ignores `x-unraid` entirely, so the file still runs anywhere under plain `docker compose up`.

This does not break the model's standing rule that `x-unraid` never restates a compose key
(`compose-model.js:1994` — *"a field lived in two places at once"*). A section is in the compose
proper **or** in `x-unraid`, never both. It is a move, not a copy.

### A bug this also fixes

`harvestLeaves()` (`compose-model.js:1127`) emits every `LEAVES` entry as an empty row **whether or
not the file has the block** — that is how an empty Health check group can show seven boxes to type
into. Adding `logging.driver` to `LEAVES` last night therefore put an empty **Log driver** box in
every service's Advanced group, because `groupFor()` has no route for `logging.*`. It has been there
since last night's deploy. This phase gives `logging` its own section, which is where it belongs.

---

## What changes on screen

**Now** — Container's header carries three tick boxes:

```
CONTAINER (required)      [ ] Health check  [ ] Resource limits  [ ] Depends on
```

**After** — one button, opening a vertical list:

```
CONTAINER (required)                                    [ Sections  ▾ ]
                                                 ┌──────────────────────────┐
                                                 │ [x] Ports                │
                                                 │ [x] Volumes              │
                                                 │ [x] Variables            │
                                                 │ [x] Devices              │
                                                 │ [x] Labels               │
                                                 │ [ ] Health check         │
                                                 │ [ ] Resource limits      │
                                                 │ [ ] Depends on           │
                                                 │ [ ] Networks             │
                                                 │ [ ] Secrets              │
                                                 │ [ ] Configs              │
                                                 │ [ ] Profiles             │
                                                 │ [ ] DNS servers          │
                                                 │ [ ] Extra permissions    │
                                                 │ [ ] Dropped permissions  │
                                                 │ [ ] Internal ports       │
                                                 │ [ ] Variable files       │
                                                 │ [ ] Logging              │
                                                 └──────────────────────────┘
```

---

## What it looks like in the file

Someone's hand-written health check, comment and all:

```yaml
services:
  web:
    image: nginx
    healthcheck:
      # raised from 10s, the DB is slow to wake up
      test: ["CMD", "curl", "-f", "http://localhost"]
      interval: 30s
    ports:
      - 8080:80
```

Untick **Health check**. The block leaves the service and lands in one block at the very end of the
file:

```yaml
services:
  web:
    image: nginx
    ports:
      - 8080:80

x-unraid:
  sections:
    web:
      healthcheck: '{"after":"image","lines":["healthcheck:","  # raised from 10s, the DB is slow to wake up","  test: [\"CMD\", \"curl\", \"-f\", \"http://localhost\"]","  interval: 30s"]}'
```

Re-tick it and the first file comes back **exactly** — same lines, same comment, same place. Nothing
was read, interpreted or rebuilt; the lines were carried across whole.

One YAML key per service, one line per section under it. Kept that way rather than as a single
JSON blob for the whole file so each entry stays short enough to read, and so deleting one by hand
is a sensible thing to do.

### The three things an entry can say

| Written | Means |
|---|---|
| `logging: '{"after":"image","lines":[]}'` | **Show this section, empty.** Ticked on but nothing typed yet |
| `healthcheck: '{"after":…,"lines":[…]}'` | **Hidden, holding this.** Restore it verbatim when re-ticked |
| `ports: false` | **Hidden deliberately.** Only needed for the five sections that show by default |

`after` names the key the block used to follow, so it goes back where it was. `null` means it was the
service's first key. Absent from the map entirely means "no opinion" — fall back to whether the file
has the block, then to the section's default.

Deleting one of these lines by hand is a supported thing to do: the section reverts to its default,
and ticking it on again gives you an empty one. **The compose file is the only source of truth** —
if the real block is present, it wins outright and any leftover entry is ignored. An entry naming a
service that no longer exists is ignored too, never quietly deleted.

### What a top-level block costs

A stash keyed by service name has to be kept in step with the service. `renameService()`
(`compose-model.js:2878`) already walks the file updating references — `depends_on`, `links`,
`volumes_from` (`collectServiceRefs()`, `:2792`) — so the stash key joins that list, edited via the
same `keySpot()` the long-form `depends_on` key already uses (`:2805`). Renaming a service therefore
carries its stash along, and the round-trip test covers it.

The block sits last because `insertChild()` already keeps `x-unraid` last at root level — that is
what `addDeclared()` relies on (`:2640`) — and when there is no `x-unraid` yet, the same call
appends it at the end.

`form.stack` is unaffected. `flatOf()` (`:1506`) reads top-level `x-unraid` one level deep and takes
scalars only, so a nested `sections:` map is invisible to it. Nothing renders it and nothing has to
learn to skip it.

---

## The table this is all driven from

One table in `stacks.js`, in the order the sections render. `on` is whether a service with none of
it, and nothing recorded, starts ticked.

| Section | Group key | Compose key | Starts |
|---|---|---|---|
| Ports | `port` | `ports` | **on** |
| Volumes | `volume` | `volumes` | **on** |
| Variables | `env` | `environment` | **on** |
| Devices | `device` | `devices` | **on** |
| Labels | `label` | `labels` | **on** |
| Health check | `health` | `healthcheck` | off |
| Resource limits | `resources` | `deploy.resources` | off |
| Depends on | `depends` | `depends_on` | off |
| Networks | `list:networks` | `networks` | off |
| Secrets | `list:secrets` | `secrets` | off |
| Configs | `list:configs` | `configs` | off |
| Profiles | `list:profiles` | `profiles` | off |
| DNS servers | `list:dns` | `dns` | off |
| Extra permissions | `list:cap_add` | `cap_add` | off |
| Dropped permissions | `list:cap_drop` | `cap_drop` | off |
| Internal ports | `list:expose` | `expose` | off |
| Variable files | `list:env_file` | `env_file` | off |
| Logging | `logging` | `logging` | off |

The first five start on because they show on every service today and must carry on doing so. What
they gain is the ability to be switched *off*, which is what `ports: false` records.

**Container and Advanced are not in the list.** Container is required. Advanced is the catch-all for
anything the form has no better home for, so hiding it could hide something the file genuinely has.

---

## The code

### Model — `compose-model.js`

Everything here works on **lines**, not values, which is what makes it lossless and short.

- **`readSections(doc)`** — parse `x-unraid.sections` into `{service: {key: false | {after, lines}}}`.
  A malformed entry is ignored, never thrown on: this runs on every render of every file, including
  ones edited by hand.
- **`stashSection(doc, form, service, path)`** — read the block's exact lines out of `doc.lines`,
  note the key above it, remove them with `removeKey()`'s existing walk (`:2744`, which already takes
  an emptied parent with it), then write the JSON line into `x-unraid.sections.<service>`.
- **`restoreSection(doc, form, service, key)`** — read the entry, splice the lines back after
  `after`, then remove the entry and any level it leaves empty.
- **`setSectionState(doc, form, service, key, state)`** — writes the empty-lines form, or `false`, or
  removes the entry. One place that touches the map.
- **`collectServiceRefs()`** gains the stash key, so a rename carries it.

**One refactor, not a second copy.** `addNested()` (`:2452`) walks a path creating missing levels,
but starts from `serviceMapOf()`. The stash needs the same walk from the document root. Its walk
takes a starting pair instead, and both callers share it.

**Indent.** Lines are stashed with the block's base indent stripped, and re-indented to the target on
restore. Relative indent inside the block — which is what actually matters — is untouched.

**Escaping.** The JSON contains double quotes, so `emitScalar()` will quote the whole thing. A
stashed line holding an apostrophe or a backslash is the case to get wrong, so the round-trip test
gets a block containing `#`, `'`, `"`, `\` and a trailing-comment line, stashed and restored, checked
for byte identity.

**`deploy.resources` is the awkward one** — it is nested, so stashing it can leave `deploy:` empty and
`removeKey()` will take that with it. Restoring has to recreate `deploy:` first, then place the lines
under it. It gets its own test.

**The exclusion:** top-level `x-unraid` is never harvested as a field — `harvest()` runs per service
and `flatOf()` skips maps — so nothing can put a 400-character JSON line in a text box. Confirmed
first, not last.

### Form — `stacks.js`

- **`SECTIONS`**, the table above, beside `GROUPS` (`:115`). It absorbs `FLAG_LABELS` (`:281`), the
  `flag:` markers on three `GROUPS` entries, and `flagFor()` (`:288`) — whose healthcheck /
  deploy.resources / depends_on special cases stay (they are broader than group routing on purpose),
  gain a `logging` case, and otherwise fall through to `groupFor(f)`, which already returns `port`,
  `volume`, `list:dns` and the rest.
- **`serviceFlags()`** (`:319`) resolves a tick from the file alone: the block is present → **on**;
  else the `sections` entry says so; else the table's default.
- **All session state deletes.** `flagOn` (`:713`), `emptiedLists` and `listGroups` (`:698`) exist
  only because a ticked-but-empty section had nowhere to be remembered. It has somewhere now. That
  also removes `serviceFlags`' special case for a `depends_on` emptied by ×, and
  `groupsForService()`'s entire dynamic half (`:243-275`) — with all nine list keys named in the
  table there is nothing left for it to discover, since an unknown compose key is never harvested as
  a list. **This is the largest deletion in the phase and the one to read carefully.**
- **× on the last row of a list** leaves `{"after":…,"lines":[]}` behind, so the section stays on
  screen with its Add button. The line itself is still deleted outright, as now — this records the
  tick, it does not stash the row.
- **The confirm prompt goes** (`:2340-2345`). Unticking is no longer destructive, so there is nothing
  to warn about. Undo still covers a mis-click.
- **The button and panel** replace `.stackman-groupflags` in `groupHeadHtml()` (`:1877`). The
  checkbox markup is unchanged, so the existing change listener (`:2311`) keeps working behind the
  generalised resolver. **The panel is rendered by `renderForm()`, not built on click** — every tick
  reparses and rebuilds the form, so a hand-built panel (the way `devOpen()` builds the device
  picker, `:3538`) would be destroyed on the first tick and you could only ever tick one thing. Its
  open state is the one thing left in the session, keyed by service. Focus returns to the box just
  clicked. Closes on the button again, `Escape`, or a click outside.
- **CSS:** `.stackman-sections` gains `position: relative`; the panel is absolutely positioned below
  the button and floats over the rows, so ticking a box does not shift the form under the pointer.
  It is opaque, carries a shadow, and scrolls past a maximum height. `.stackman-form` is
  `overflow: auto` rather than `hidden`, so a panel taller than the remaining pane gains scroll
  instead of being clipped. Its `max-width` is measured against the window, not the parent — the
  parent is only as wide as the button. Narrow screens drop the button below the heading under the
  existing `flex-wrap: wrap` rule (`stack.manager.css:4456`).

### Schema — `schema/x-unraid.schema.json`, `docs/x-unraid-schema.md`

`sections` joins the **top-level** schema: a map of service name → map of compose key → `false` or a
JSON string. `validate_schema.py` gets positive cases and — the ones that matter — negatives: a bare
array instead of the object form, a number where the string belongs, an unknown key.

### PHP

`stackman_compose_meta()` reads `x-unraid` blocks. It must tolerate a nested `sections` map with long
string values without complaint. Checked on the server, not assumed.

---

## Verification

Local, before deploy:

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
python tests/validate_schema.py
```

`js_undeclared.js` matters most. `stacks.js` is one strict-mode IIFE, and this deletes three
variables read in several places — one missed reader is a silently dead page, which is exactly what
this catches and `node --check` cannot.

The round-trip suite gains a **stash section**, and its central case is the one that makes the whole
design honest:

> **Untick then re-tick leaves the file byte-identical.** Over the whole corpus, for every section a
> service has. Not "the values match" — the same bytes, in the same order.

Plus: a block holding `#`, `'`, `"`, `\` and a trailing comment; a block that is the service's first
key (`after: null`); `deploy.resources`, where the parent is removed and recreated; a **rename while
a stash exists** (the entry follows); a hand-mangled entry (ignored, not thrown on); an entry naming
a service that does not exist (ignored); and a section present in the file *and* in `sections` at
once (the file wins).

On the server, by eye, on a stack with two services:

1. One button on the Container header, not three ticks.
2. Ports, Volumes, Variables, Devices, Labels ticked and showing, as before.
3. Untick a Health check that has settings — **no confirm**, block moves to the `x-unraid` block at
   the end of the file, compose pane shows the move. Re-tick — back with its comment, in its place.
4. Save, reopen the stack, re-tick — still restores.
5. Rename that service while its stash is hidden, then re-tick — still restores.
6. Tick **DNS servers**, add one, save. `dns:` written with the file's own indent.
7. Tick three sections in a row without the panel closing.
8. Advanced no longer shows an empty **Log driver** row.
9. Delete a `sections` line by hand in the compose pane — the section reverts to its default.

---

## Then

- **Phase 2** — which section each of the sixteen absent scalar settings belongs to, and how one gets
  added.
- **Phase 3** — confirm every field added this way survives form → compose → form unchanged.

Previous plan, complete and deployed (add container, and the dropdowns themselves): `PLAN.md`.
