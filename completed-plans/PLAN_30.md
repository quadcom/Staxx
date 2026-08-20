# PLAN_30 — finish PLAN_16: the file's own names in the editor, and the long forms on the form

**Status: COMPLETE (2026-08-17).** All five steps built, tested and deployed to the test box. The
suite went from 1023 to 1112 passing. `PLAN_16.md` is closed and points here.

What the build changed against what was planned:

- **A map-form `networks:` was never "dropped silently"** — measured, it reached a locked row
  showing the whole block. The fault was that it could not be edited, not that it vanished. The
  claim is corrected in the B4 section below; the fix is the one that was approved either way.
- **The "more settings" toggle already existed**, used by declarations, long-form dependencies and
  device rows. Reused rather than reinvented, so B3 needed no new stylesheet rule — only a modifier
  class hiding the label's second, visible copy under each box.
- **Two live bugs were found and fixed while testing B4**, neither of them in the plan: pressing Add
  on a map-shaped `networks:` wrote a bare word with no colon, which compose refuses; and clearing a
  map entry's name wrote a bare `:` or `''`. Both are covered by tests now.
- **Long-form extras are never created, only edited.** A key the file does not have gets no box, and
  a blank edit to any part of a long-form row writes nothing — nothing here can remove one line out
  of a numbered list item, so deleting is the Compose view's job.

Fixtures on the test box: `14-long-forms` (valid compose — 42 parts written back unchanged) and
`15-long-forms-broken` (compose refuses it: "missing a target port", which is what the locked rows
say in plain English).

Known gap, deliberately not built: the editor still offers nothing inside a long-form port or mount
item, because the suggestion machinery has no branch for that position. The new `protocol`,
`volumetype`, `propagation`, `selinux` and `portmode` vocabularies serve the form today and are
ready for the editor the day that branch exists.

## Context

`PLAN_16.md` is the last of the two outstanding plans (`PLAN_24.md` is the other, untouched here).
It holds two independent pieces, both still entirely unbuilt:

- **Part A** — the editor offers no suggestions for names the file itself declares. Type `- ` under
  `depends_on:`, `networks:`, `secrets:`, `configs:` or `profiles:` and nothing appears, even though
  the form beside it offers exactly those names from a dropdown. These are the names most likely to
  be typed wrong, because they are the user's own.
- **Part B** — a port or a mount written the long way (spelled out over several lines) is handled
  badly: a long-form port with no `target:` **vanishes from the form while staying in the file**,
  long-form `protocol`/`mode` are never shown, long-form volumes keep only source/target/read-only,
  and a service's `networks:` written as a map is **dropped silently** — which is how anyone pins a
  container to a fixed IP.

Decisions taken with Adrian (2026-08-17):

- Long-form extras appear **behind a "more settings" toggle on the row itself**, not as extra rows
  and not as extra columns.
- A map-form `networks:` entry becomes **an editable row** (name from the file's declared networks)
  with its address/priority behind the same toggle — not a locked row.

Everything is in the two browser files:
`src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js` and
`.../stacks.js`. Copy this file to `PLAN_30.md` in the repo at the start of the build, and mark
`PLAN_16.md` complete pointing at it when finished.

**Five separately deployable steps: A, B1, B2, B3, B4. Run the suite and deploy after each.**

---

## Part A — names the file declares, offered in the editor

### A1. The scan (compose-model.js, next to `hostPaths()` at :5434)

`fileNames(text)` -> `{ services: [], networks: [], volumes: [], secrets: [], configs: [], profiles: [] }`

Built from `classify()` alone, never `parse()` — the section comment above `hostPaths()` (:5345)
gives the reason: a suggestion is wanted exactly when the file is mid-edit and a parse has least to
say. **Copy `hostPaths()`'s ancestor-stack walk verbatim** (:5441-5459), including its `try/catch`
returning an empty result. It is the proven pattern and it is already fuzzed.

Collect: mapping keys at stack depth 2 under `services`, and under each of the four top-level
declaration blocks; sequence-item values under `services -> <name> -> profiles`. Deduplicate, keep
file order. A top-level `networks:` and a service-level `networks:` are told apart by stack depth,
the same way `hostPaths()` avoids the top-level `volumes:` block.

### A2. One rule table, read by both views

`fromChoice()` (stacks.js:1304) and `serviceModeOptions()` (stacks.js:1340) each hold a rule the
editor must not contradict:

- `default` is always offered as a network, declared or not — compose creates it regardless.
- A service is never offered in its own `depends_on`, nor in its own `service:<name>`.

Move both into compose-model.js as one exported helper — `refNames(names, kind, serviceName)`
returning a plain string array — and have `fromChoice()`/`serviceModeOptions()` call it, keeping
`MODEL.declared` as the form's own source. The form and the editor then read one rule from two
sources, which is the point.

### A3. Where the names are offered

A new table beside `VOCAB_AT` (:4606), keyed the same way (`where` bucket -> key):

| Written as | Offer |
|---|---|
| `depends_on:` list items | other services |
| service `networks:`/`secrets:`/`configs:` list items | that namespace, plus `default` for networks |
| `profiles:` list items | every profile named anywhere in the file |
| `network_mode:`/`ipc:`/`pid:` | the static vocabulary **plus** `service:<name>` per other service |
| a volume entry's host half | declared volume names |
| `build:`'s own `secrets:` list items | declared secrets |
| long-form `depends_on:` child keys (`db:`) | other services |

In `valueSuggestions()` (:5234) the dynamic list is resolved **alongside** `vocabIdFor()`, not after
it — today a key with no VOCAB entry returns null at :5293 before anything else can run. Static
first, then names, matching `choiceFor()`'s own order in the form.

Two things fall out with no extra work and should not be over-thought:

- **`service:<name>` needs no "closed head, open tail" machinery.** The caret's word is
  whitespace-delimited, so offering the whole string `service:db` prefix-matches a typed `service:`
  and replaces the whole word on accept. `PLAN_16.md`'s head/tail section is answered by not
  building it.
- **A volume's host half only suggests before a colon is typed.** `- appd|` offers `appdata`; once
  `appdata:/config` is on the line the typed word no longer prefix-matches any name and the list
  goes quiet. That is correct behaviour, not a gap — say so in the comment.

The long-form `depends_on` key case is a **key** position, so it goes in `keySuggestions()` via
`suggestionContext()` (:5013), which returns null for that path today. Give `suggestionContext()`
an optional second argument for the scan result and leave `describeKeyAt()`'s call (:5335) passing
nothing — it must keep working unchanged. `siblingKeysOf()` then excludes already-listed
dependencies for free.

While in there, add the missing `depends` bucket so `condition:`'s value offers the
`dependscondition` vocabulary that already exists (:4421) and is currently unreachable from the
editor. One line in each of two tables.

### A4. Cost

`runSuggest()` (stacks.js:8010) is debounced at 80ms and already classifies the whole file twice per
call. Scan **lazily** — only once the resolved position is one that wants names.

---

## Part B — the long forms

### B1. The vanishing entry (smallest, do it first)

`harvestLongForm()` (:1019) does `if (!tgt) return;` at :1027 and the entry disappears from the form
while staying in the file. Replace with a locked row carrying the item's own lines as `raw`, exactly
as the `binder === 'list'` map branch above it already does (:989-995). Pass the item index down
from `harvestList()` so the target can be `'@' + listKey + '#' + index`, the existing convention.

Reasons, in house style: `'this entry does not say which port inside the container to use'` /
`'this mount does not say where it goes in the container'`.

### B2. Long-form port protocol

Add a `proto` part reading the `protocol:` line's own scalar. The row's fifth column already exists
and currently renders a placeholder for exactly this case — read the comment at stacks.js:2153-2173
before touching it.

The value differs from the short form: `udp`, not `/udp`. So mark the harvested target
`longForm: true`, carry it onto the field in `fieldsFor()` (:1813), and branch in `choiceFor()`
(stacks.js:1511) onto a new `protocol` vocabulary (`tcp`, `udp`) instead of `CHOICES['port/proto']`.

**A protocol line that is not in the file is not created.** Same rule `harvestLeaves()` states at
:1226 — an absent leaf is never offered.

### B3. The "more settings" toggle, and everything it holds

The mechanism, and the reason it is cheap: **extras are extra `parts` of the same field, not new
fields.** `boxHtml()` (stacks.js:1550) reads `f.parts[which]` by name and writes `data-part`, and
the change handler passes `el.dataset.part` straight to `setPart()` (stacks.js:2884), which looks it
up generically. So a new part name needs no wiring at all — no new ids, no new binders, no grouping
changes, and `setPart()`'s "writing a value back unchanged touches nothing" rule (:2351) keeps the
null-edit guarantee intact for free.

Harvest, in `harvestLongForm()`: every scalar leaf of the item that the main boxes do not already
own, one level of nesting allowed so `bind.propagation` and `tmpfs.size` are reachable. Present-only
— nothing absent is offered, nothing is ever created.

Render, in stacks.js: a `<details>` under the row's boxes, built by looping an ordered table of
extra part names. `staxx-devmore` (stacks.js:2132) is the existing precedent for a disclosure
inside a row; give this one its own `staxx-` class rather than borrowing that one.

Covered by this one mechanism: a port's `mode`; a volume's `type`, `read_only`, `consistency`,
`subpath`, `bind.propagation`, `bind.selinux`, `bind.create_host_path`, `volume.nocopy`,
`tmpfs.size`, `tmpfs.mode`.

New vocabularies (values are already listed in `comp-autocomplete.md:160-164`): `volumetype`,
`propagation`, `selinux`, `portmode`. Adding them to `VOCAB` serves the editor at the same time,
since both views read one registry.

Leave the read-only badge alone: `f.mode` still drives it, and `read_only` is now editable beside
it in the toggle.

### B4. A service's `networks:` written as a map

Correction (verified 2026-08-17): a service's `networks:` written as a map does not vanish — `harvest()`
hands it to `settingTarget()`, which renders one locked row for the whole block, reason "this is
written as a block of its own". Add a map branch to `harvestList()` (it returns at :954 on anything
that is not a `seq`) emitting one `list`-binder field per network name instead:

- the name is the mapping key, bound through `keySpot()` — the same binding `harvestPairs()`
  already uses for an environment map (:1084)
- `from: 'networks'` gives it the declared-names dropdown, `default` included, via A2's shared rule
- `ipv4_address`, `ipv6_address`, `mac_address`, `priority`, `gw_priority` become B3-style extras
- anything else in the block (`aliases`, `link_local_ips`, `driver_opts`) adds advice:
  `'this network has extra settings only the Compose view shows'`

`removeItem()` already counts and removes map entries (its `v.kind === 'map'` branch), so the × and
the "last entry takes its key with it" rule need no change — **verify this rather than assume it.**

Long-form `secrets:`/`configs:` entries (`- source: x` / `uid` / `gid` / `mode`) are the same shape
and get the same treatment in the same step.

---

## Verification

After every step, all five checks, and the deploy loop from `local/dev-server.md`:

```sh
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
python tests/validate_schema.py
```

**The null edit is the guard that matters for Part B** — set every box, including every new one, to
the value it already holds and demand the input back byte for byte. Write it for each new shape
*before* the fix. Part A cannot change a file at all, so its risk is only that a scan throws on a
half-typed file: extend section L/Q's fuzz cases to cover `fileNames()`.

New test sections, continuing the file's letters: **W** for Part A (beside the existing key/value
suggestion sections L and Q), **X** for Part B.

Also dump the whole field set for a long-form fixture before and after B2/B3/B4 and **read** it —
here the set is supposed to change, so it is a list to agree with, not an equality to assert
(`PLAN_15` phase 3's treatment).

On the test box, a new fixture stack `14-long-forms` holding, in one file: a long-form port with
`protocol` and `mode`; a long-form port with no `target:`; a long-form volume with `type`, `bind`
and `read_only`; a `networks:` map with a fixed IP and an `aliases` list; a long-form `secrets:`
entry with `uid`/`gid`/`mode`. Then in the browser:

- **Part A** — a `- ` under `depends_on:` offers the other services and never the service itself;
  `networks:` offers the declared ones plus `default`; `network_mode: service:` offers services; a
  deliberate syntax error further down the file does not stop any of it.
- **Part B** — every part of the fixture appears on the form, the toggle opens and its boxes hold
  the file's values, changing one leaves the rest of the file untouched.
