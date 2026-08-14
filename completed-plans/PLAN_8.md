# Form: reorder the groups, fold the Stack section, and add three switchable groups

**Status: COMPLETE.** All five phases built, tested and deployed to the test box. `tests/yaml_roundtrip.js`
went from 440 to 490 cases over the five phases. Two things landed differently from the plan below, both
deliberately:

- **`deploy.replicas` and the rest of `deploy:` go to Advanced, not Resource limits.** The Resource
  limits tick is derived from `deploy.resources.*`, so a wider rule would have let an off tick hide a
  key the file plainly contains. The form must never hide something the file has.
- **Clearing the health check's command leaves its `test:` line alone**, where clearing a timing removes
  its line. Removing it would destroy `healthcheck:` and any comment above it between keystrokes during
  an ordinary clear-and-retype.

`PLAN_7.md`'s shared blocker — inserting a nested key — is resolved by `addNested()`, and its sections 1
and 2 are now built.

## Context

The compose form shows every group at the same weight, in an order that puts Devices — which most
stacks never use — above Variables, which nearly all of them do. The Stack section (the file's own
networks, volumes, secrets and configs) sits at the very top and is the first thing anyone sees,
though it is the last thing an ordinary user needs.

Three blocks compose supports are worth a form of their own — a health check, resource limits and
start-order dependencies — but none of them can be *created* today. `PLAN_5.md` made their leaves
editable when a file already has them; a file without them shows nothing at all, so the only way to
add one is to write YAML by hand in the Compose view. That is the gap this closes.

The outcome: everyday settings first, advanced settings below, and a tick box that turns each of
the three optional blocks on and off — writing them into the file, or taking them out of it, without
the user ever opening the Compose view.

**Note for the repo:** on approval this becomes `PLAN_8.md` at the repository root, per the
`CLAUDE.md` convention. It is five phases; deploy to the test box at the end of each.

---

## The new group order

```
Stack        — folded shut by default
Container    right-aligned:  [ ] Health check   [ ] Resource limits   [ ] Depends on
Ports
Volumes
Variables
Devices
  Health check      shown only when ticked
  Resource limits   shown only when ticked
  Depends on        shown only when ticked
Labels
Networks / Secrets / Profiles / DNS / …   (whatever lists the file has)
Advanced
```

## How the tick boxes behave

- The file decides the starting state. A service with a `healthcheck:` block opens with Health check
  ticked and the group shown, populated from the file.
- Ticking an empty one shows the group with every box blank. **A blank box writes nothing** — the
  key only appears in the file once something is typed into it.
- Unticking a group that holds values asks first: *"Remove the health check from web? Its 4 settings
  will be deleted from the compose file."* Yes removes the block; No leaves the box ticked. Unticking
  one that holds nothing just hides it, and touches no file.

---

## Phase 1 — order and the Stack fold

Files: `javascript/stacks.js`, `sheets/stack.manager.css`

1. **Reorder `GROUPS`** (stacks.js:110). Move `env` above `device`, move `label` below the three new
   entries, and add `depends`. `groupsForService()` (stacks.js:177) needs no change: it already
   splits the table into "everything except Advanced" plus a tail, and the dynamic list groups still
   land between Labels and Advanced.
2. **`CAPTIONS`** (stacks.js:128) gains a `depends` row: `['service', 'wait until', 'note, kept in the file']`.
3. **Fold the Stack section.** In `stackSectionHtml()` (stacks.js:1399), wrap the four groups in a
   `<details class="stackman-stackfold">` whose `<summary class="stackman-svchead">` is the "Stack"
   heading. The `<section class="stackman-svc stackman-svc--stack">` wrapper stays, so no existing
   rule changes.
   - `renderForm()` rebuilds the whole form on every structural edit, so the open/shut state cannot
     live in the DOM. Add a module-level `stackOpen = false` beside `listGroups`, write it as the
     `open` attribute at render, and update it from one `toggle` listener. Shut on every fresh open of
     the editor — no server-side memory, unlike the stacks table's folders.
4. **CSS.** Copy the disclosure treatment from `.stackman-details` (stack.manager.css:3301) — it
   already gives a real triangle via `list-style: revert` — onto `.stackman-stackfold > summary`,
   keeping `.stackman-svchead`'s own type scale.

## Phase 2 — a blank box for a setting the file does not have yet

Files: `javascript/compose-model.js`

Today a `healthcheck` or `deploy` leaf is only turned into a field when the file already has that
line (`harvestBlock`, compose-model.js:998). Every leaf must become a field either way, so the group
can be shown with empty boxes.

1. **Split the block harvest in two.** Add `harvestLeaves(out, serviceMap, lines)`, called from
   `harvest()` (compose-model.js:1029) immediately after the `ALWAYS_KEYS` loop — the same fixed-pass
   shape, for the same reason. For every path in `LEAVES` (compose-model.js:570) it emits either
   `settingTarget(leafPair, …)` when the line exists, or an absent field:

   ```js
   target('setting', 'healthcheck.interval', {
     parts: { value: part('', null) }, range: null, absent: true,
     path: ['healthcheck', 'interval']
   })
   ```

   `harvestBlock` then keeps only its second loop — covering children `LEAVES` does not name, such as
   `deploy.replicas` — so nothing is emitted twice. Field ids are unchanged (`web/setting/healthcheck.interval`),
   so a row keeps its identity when its value materialises.

2. **`addNested(doc, form, service, path, value)`.** The primitive `PLAN_7.md:23` names as the shared
   blocker. `insertChild` (compose-model.js:1838) already writes one child under any pair and copes
   with a parent that has nothing under it; `addDeclared` (:1943) already shows the two-step pattern
   for creating a block and its first child. This walks a path of any depth, creating each missing
   level:

   ```js
   function addNested(doc, form, service, path, value) {
     var pair = serviceMapOf(doc, service), i;
     if (!pair) return -1;
     for (i = 0; i < path.length - 1; i++) {
       var map = pair.value && pair.value.kind === 'map' ? pair.value : null;
       if (pair.value && !map) return -1;              // sealed or scalar — never guess inside one
       var next = map ? map.pairs[path[i]] : null;
       if (!next) {
         // splice() re-parses, so every position above is now stale: create the
         // one missing level and walk again from the top.
         if (insertChild(doc, pair, path[i], null, i === 0 ? 'x-unraid' : null) < 0) return -1;
         return addNested(doc, form, service, path, value);
       }
       pair = next;
     }
     return insertChild(doc, pair, path[path.length - 1], value,
                        path.length === 1 ? 'x-unraid' : null);
   }
   ```

3. **`setPart`** (compose-model.js:1568) gains one branch beside the existing `f.absent` one:
   a field carrying `path` and no spot writes via `addNested`, and a blank value writes nothing —
   the rule already spelled out at :1588.

4. **Blanking a leaf removes its line.** Elsewhere in the form, clearing a box writes `key: ''`. For
   these leaves that is not a formatting question but a broken file — compose rejects
   `interval: ''` — so a `path` field cleared to blank deletes its line, and any parent left empty
   with it. Share that walk with phase 3's `removeKey`.

## Phase 3 — the tick boxes

Files: `javascript/stacks.js`, `javascript/compose-model.js`, `sheets/stack.manager.css`

1. **`removeKey(doc, form, service, path)`** in compose-model.js, exported. Removes the pair at a
   path and then every parent the removal left empty — a bare `deploy:` is null and compose refuses
   the file, the same rule `removeItem` (:1908) and `removeDeclared` (:1979) already follow, and it
   reuses their blank-line collapsing so a file that separates its blocks with blanks does not gain
   a double one. Resource limits removes `deploy.resources`, which takes `deploy` with it only when
   `resources` was all it held — `deploy.replicas` is honoured outside swarm (`PLAN_7.md:113`) and
   must survive.

2. **Which boxes are ticked.** A `serviceFlags(form, name)` helper in stacks.js returns
   `{health, resources, depends}`, true when the service has a field for that block that is really in
   the file (not `absent`), **or** when a module-level `flagOn[name]` says the user turned it on. That
   override is what lets a ticked-but-empty group survive `reparse()`, exactly as `listGroups` and
   `emptiedLists` do for an emptied list.

3. **Markup.** `groupHeadHtml()` (stacks.js:1345) takes the flags for the container group and appends,
   after the `<h5>`:

   ```html
   <div class="stackman-groupflags">
     <label class="stackman-flag">
       <input type="checkbox" data-flag="health" data-service="web" checked> Health check</label>
     …resources, depends…
   </div>
   ```

   `.stackman-grouphead` is already `display:flex; justify-content:space-between` (CSS:2071), so this
   right-aligns with no new layout — only type and spacing rules for `.stackman-groupflags`/`.stackman-flag`.

4. **Showing and hiding.** The three `GROUPS` entries gain `flag: 'health' | 'resources' | 'depends'`.
   The render loop (stacks.js:1465) skips a flagged group whose flag is off, and keeps one whose flag
   is on even at zero rows — replacing the `!rows.length && !grp.add` test for these three.

5. **The click.** One delegated `change` listener on `formHost`:
   - **On:** `flagOn[svc][flag] = true`, then redraw. No file write — there is nothing to write yet.
     Add a small `redraw()` that re-renders from the stored form rather than re-parsing the YAML;
     `reparse()` (stacks.js:1558) currently keeps its form object local, so store it alongside `MODEL`.
     Call `flushPending()` first, so a half-typed box is not lost.
   - **Off, and the file has nothing:** drop the override and redraw.
   - **Off, and the file has values:** `window.confirm` naming the service and the count — the same
     plain `window.confirm` `confirmDiscard()` (stacks.js:2985) already uses, which works over the
     open `<dialog>`. Yes → `removeKey`, re-serialise, `structuralEdit()` (stacks.js:1762) for the
     redraw and the status line; No → put the tick back.

## Phase 4 — the health check itself

Files: `javascript/compose-model.js`, `javascript/stacks.js`

Ticking Health check on a service that has none currently gives timings with nothing to time, because
`test:` is written as a one-line list and the model seals those (`reason: 'flow'`). `PLAN_7.md:66`
weighs the two ways out and lands on the smaller one: **rewrite that one line**, rather than teaching
the parser to open flow sequences.

**Two rows, not one new row shape.** `harvestLeaves` emits `healthcheck.test` as a pair of fields —
"How the check runs" and "The check itself" — so both are ordinary label · value · note rows and
`fieldHtml` needs no new branch. `CHOICES` (stacks.js:599) gains the mode list, which turns the first
into a dropdown through the machinery `boxHtml` already has.

| Shown as | Written as |
|---|---|
| run a shell line | `test: ["CMD-SHELL", "<command>"]` |
| run a program | `test: ["CMD", "<arg>", "<arg>", …]` |
| no check | `test: ["NONE"]` |

- **Reading** — `readTest(pair)` handles all four shapes found in real files: a plain scalar (means
  CMD-SHELL), a block sequence, a flow sequence, and `NONE`. The flow case reads the sealed node's
  own `raw` text with the argv splitter `commandSay()` already uses, rather than unsealing it.
- **Writing** — `writeTest(doc, form, service, mode, command)` replaces the whole `test:` value with
  the canonical flow form above, each element JSON-encoded so quoting and escaping are right by
  construction. Editing either row writes both, since the mode and the command are one line.
- The honest cost, and it goes in a comment: this loses the author's own spacing and quote style **on
  that one line**. A `test:` written across several lines is replaced by the single-line form. Every
  other line of the file is untouched.

## Phase 5 — Depends on

Files: `javascript/compose-model.js`, `javascript/stacks.js`

Compose has two ways to write this and `PLAN_7.md:62` is firm that the form edits whichever one the
file already uses and **never converts between them**.

```yaml
depends_on: [db, redis]          # short — a plain list of names
depends_on:                      # long — carries a condition
  db:
    condition: service_healthy
```

- **Short form** already works: `harvestList` makes it the dynamic `list:depends_on` group. Change
  only where it lands — `groupFor()` (stacks.js:146) sends `f.listKey === 'depends_on'` to the
  `depends` group, so both forms share one group and no "Starts after" group appears separately.
- **Long form** gets a new `binder: 'depends'` harvest branch in `harvest()` (compose-model.js:1065),
  one field per dependency:
  - `parts.name` — the service name, which is a **key, not a value**: write it through
    `keySpot(pair)`, which `collectServiceRefs()` (:2031) already uses for exactly this during a
    rename. Rendered as a dropdown of the file's services, via the `from: 'services'` path
    `fromChoice()` (stacks.js:650) already knows.
  - `parts.value` — `condition`, a dropdown over the three real values with plain-English labels
    ("wait until it has started" / "…reports healthy" / "…has finished OK").
  - `restart` and `required` in a `<details>` fold, reusing `declaredFoldHtml` (stacks.js:1125) and
    the `f.fold` convention.
  - When the condition is "reports healthy" and the named service has no `healthcheck:`, the row
    carries an `advice` line saying so — the mistake `PLAN_7.md:53` calls the one hand-edited files
    get wrong most often. `adviceBlock()` (stacks.js:1097) renders it; it is currently only called on
    a locked row, so call it here too.
- **Adding.** `ADDABLE` gains `{ binder: 'depends', word: 'service' }`. The button writes long form
  when the file is long form or has no `depends_on` at all, short form when the file is short form.
  A new long-form entry must carry `condition: service_started` — compose's own default — because a
  bare `db:` under `depends_on` is null and the file would be rejected. This is the one place the
  blank-writes-nothing rule cannot apply, and the comment should say why.
- **Removing.** A branch in `removeItem` (compose-model.js:1890) for the long form: drop the name's
  sub-map, and the whole `depends_on:` key when it was the last one.
- The inline flow form (`db: { condition: service_healthy }`) stays sealed and read-only, as it is
  today.

---

## Verification

Local, after every phase — all four are cheap and catch different things:

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
```

`yaml_roundtrip.js` needs new cases, and existing ones will move:

- **Will need updating** — `tests/yaml_roundtrip.js:1400` asserts the health check and resource limit
  leaf titles, and every service now gains 11 more fields from `harvestLeaves`, so any assertion
  counting fields shifts.
- **New cases, phase 2** — `addNested` creating `healthcheck:` → `interval: 30s` in a file that had
  neither; creating `deploy:` → `resources:` → `limits:` → `cpus:` three levels down; refusing when
  an intermediate key is sealed; a blank box writing nothing at all.
- **New cases, phase 3** — `removeKey` dropping `deploy.resources` while `deploy.replicas` survives;
  dropping `deploy` entirely when `resources` was all it held; the blank line either side collapsing
  to one.
- **New cases, phase 4** — `readTest` over all four shapes; `writeTest` round-tripping each mode; a
  command containing a double quote surviving intact.
- **New cases, phase 5** — long form read and written; a rename following a long-form key; short form
  untouched by anything the long-form code does.

On the test server, per `local/dev-server.md` — `pscp` the plugin folder up, then
`plink -ssh -batch` `bash /boot/stack.manager-dev/dev-install.sh`, and `php -l` over `include/*.php`.
Nothing here is server-side, so the real check is in the browser:

1. A stack with no `healthcheck:` — tick Health check, fill only "Check every", confirm the Compose
   view shows `healthcheck:` with exactly one line under it and nothing else.
2. Untick it — confirm the prompt names the service, and Yes leaves no trace of `healthcheck:`.
3. Paste a compose file that already has `healthcheck:`, `deploy.resources` and a long-form
   `depends_on:` into the Compose view — all three boxes tick themselves and the groups fill.
4. A hand-authored file with comments, anchors and odd spacing: tick and untick each box, change one
   value, and diff the file. Only the lines actually edited may differ.
