# PLAN_21 — Stop requiring what compose does not, and show what the file actually says

**Status: COMPLETE, 2026-08-16.** Built, deployed and checked in the browser on the test box.
1002 round-trip assertions (up from 978), 154 converter, schema and lint clean, `php -l` clean.

Two things worth recording from the build:

- **A redraw was missing.** `includesHtml()` decides whether an include gets an Open button by
  looking it up in the folder listing, which arrives *after* the form first renders — so every
  include read *"cannot be opened here"* even with the file sitting right beside the compose
  file. `filesLoad()` now redraws, and only for the include view, since nothing else reads that
  list. Found in the browser, not by a test; the node suite cannot see it.
- **`fieldHelp()`'s regex only matched `healthcheck|deploy|logging`**, so the new Build rows
  would have had no hover help even though `DESCRIPTIONS.build` already carried full sentences.
  One line, and it would have been easy to miss.

Everything else landed as described. `harvestBlock`'s uncovered-children pass did what the plan
predicted: a block `build:` carrying `args`, `cache_from` and `ssh` shows three editable rows
and three honest read-only ones, and nothing goes missing.

**Not fixed, and not this plan's business:** the editor dialog collapses to 714px when the form
holds little content, truncating its own header to "E…". Measured both ways — the old empty
message produced the identical 714px, so this is pre-existing. Includes only make it reachable.

## Context

Adrian asked whether `container_name:` is required because the form uses it organisationally.
**It is not.** A whole-repo search found no code that reads its value — only writes and a
tooltip. The proof is `renameService()` (`compose-model.js:3337`): renaming a service rewrites
`depends_on`, `links`, `volumes_from`, `network_mode: service:x` and `extends`, and never
touches `container_name`. If it were an identifier, rename would have to follow it.

The requirement is one hardcoded line — `compose-model.js:1822`:

```js
fixedRequired: fixed && (t.target === 'image' || t.target === 'container_name'),
```

Both halves are wrong, and the `image` half is the worse one: a service with `build:` and no
`image:` is valid compose, and the form refuses to save it. Meanwhile `build:` written as a
block renders as a locked grey `<pre>` in Advanced, so the user is told a field is required
*and* cannot see the thing that makes it unnecessary.

The same conversation turned up `include:`. Compose accepts an `include:`-only file (measured;
`PLAN_20`), and `stackman_compose_meta()` runs `docker compose config`, which **expands
includes — so the stacks table already lists the included services correctly.** Only the form
is blank, because it parses the raw file text. A stack using includes is already half-working.

Decisions taken: say it but never block Save; let compose judge `image` while giving `build`
real form rows; point at included files rather than resolving them, but leave the door open to
editing them later.

---

## 1. Nothing blocks Save that compose would accept

**`compose-model.js:1822`** — delete the whole `fixedRequired` condition. Both names go.

Nothing is lost, because compose's own live check (`PLAN_20`) already reports
`service "web" has neither an image nor a build context specified` on the genuinely broken
case, and `stackman_save_stack()` refuses it server-side regardless. This is the layering the
project already committed to: **never be stricter than Docker.**

In its place, two notes through the existing `f.advice` mechanism (`adviceBlock()`,
`stacks.js:1866`; producers at `compose-model.js:1529`, `1779`, `2036`, `2056`) — they render
under the boxes and block nothing:

- on an empty `container_name`: *"Leave this empty and Docker names the container itself, from
  the stack and service names."*
- on an empty `image` **when the service has a `build:` key**: *"This service builds its own
  image, so this is optional."*

`requiredGaps()` and `updateRequired()` (`stacks.js:2522`, `2535`) need no change — with the
flag gone they simply find no gap. The `-!R` marker keeps working untouched; that is a
different flag and an author's explicit choice.

## 2. `build:` gets real rows

Reuse the block mechanism `healthcheck`, `deploy.resources` and `logging` already use. No new
machinery — `BUILD_LEAVES` (`compose-model.js:4220`) already lists the 20 keys and was
deliberately left unwired so it would not mint fields on every service. `PLAN_8`'s tick box is
the answer to that, and this becomes the fourth gated block.

- **`KEYS`** (`compose-model.js:558`) — add `build: { shape: 'block' }`. It has no entry today,
  which is why it falls to the catch-all `settingTarget()` and locks.
- **`LEAVES`** (`:642`) — add a `build` entry. Start with the everyday scalars only:
  `context`, `dockerfile`, `target`. Do **not** paste all 20 in; `harvestLeaves()` emits every
  listed path present-or-absent, so a long list is 20 empty boxes on every service.
- **`groupFor()`** (`stacks.js:276`) — add a `build.` case beside the existing
  `healthcheck.`/`deploy.resources.`/`logging` checks at `:297`, so the leaves land in a Build
  group rather than Advanced.
- **The tick box** — follow `PLAN_8`'s pattern on the Container heading exactly: the file's own
  content decides the starting state, ticking an empty one shows blank boxes that write nothing
  until typed into, unticking one holding values asks first.

**What happens to `args`, `cache_from` and the rest is already decided and needs no code.**
`harvestBlock()`'s second loop (`compose-model.js:1181-1189`) emits a field for **every direct
child the leaves table does not name** — a scalar child becomes an editable row, a map or list
child becomes a locked row showing the file's own text. So nothing in a `build:` block can go
missing, whatever someone wrote in it.

`build: ./app` written as a scalar keeps working exactly as it does now — `harvestBlock`'s
first line (`:1150`) hands a non-map value straight to `settingTarget()`.

**Judgement call for whoever implements it:** `args:` is a name/value map, the same shape as
`environment:`. Left alone it renders as one locked block, which is honest but not editable.
Building it as a proper list group is a second piece of work — **do the scalars first, look at
it on screen, then decide.** Do not do both in one pass.

## 3. Included files are pointed at, not resolved

Two halves, and the first is nearly free.

**`fileRefs()`** (`compose-model.js:5413`) scans `env_file`, `build.context`,
`build.dockerfile`, `secrets`/`configs` `.file`, and bind-mount volume sources. Add top-level
`include:`, scalar and list forms, with `where: 'include'`. That one addition buys two fixes
already wired to it:

- an included file sitting in the stack folder stops being drawn as an **orphan** tab
  (`stacks.js:5629`, *"Nothing in the compose file uses this file."*) — it is used, and the tab
  should say so
- `missingRefs()` (`stacks.js:5404`) starts warning when an `include:` names a file that is not
  there, which it cannot do today because nothing feeds it

**The form's empty branch** (`stacks.js:2396`) currently shows one grey line, because
`buildForm()` returns early with `'This file lists no services.'` (`compose-model.js:1952`) and
`out.ok` stays true. Have `buildForm()` also carry `out.includes` — the list read off
`doc.root.pairs['include']` — and when it is non-empty render a block per included file instead
of the bare sentence:

- the file's name, a grey `include` tag, and one sentence saying its services are defined there
- an **Open** button when the file is in this stack's folder, switching to its existing
  companion tab (`renderTabs()`, `stacks.js:5602` — the tab is already there, since tabs come
  from the folder listing, not from references)
- a plain note instead when the path points outside the stack, since that file cannot be opened
  here

**Keeping the door open**, as asked: render this from `form.includes` in its own function, so an
editable version later replaces one renderer rather than unpicking the empty-form branch. The
rule that holds for now is the one the companion tabs already follow — **the form edits the
file it opened and nothing else.**

Do **not** resolve the services. `docker compose config` would expand them, but they cannot be
edited in place, and the table already shows them.

## 4. Tests

`tests/yaml_roundtrip.js`, same `ok()` harness. Negatives first, as always:

- a service with `build:` and no `image:` produces **no required-gap** — the case that started this
- a service with neither `image` nor `build` also produces no gap (compose's job now, not ours)
- `build: ./app` scalar still yields one editable field, unchanged
- `build:` block yields rows for `context`/`dockerfile`/`target`, and `args:` still appears as
  its own row rather than vanishing — the guarantee that matters
- ticking Build on a service without one writes nothing until a box is typed into
- `fileRefs()` returns the include with `where: 'include'`, for both scalar and list forms
- `buildForm()` on an `include:`-only file returns `ok: true` and a populated `includes`
- **the null edit still holds** — nothing here may change a byte of any file

## Verification

Local:

```sh
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
node --check .../javascript/stacks.js
node --check .../javascript/compose-model.js
python tests/validate_schema.py
```

On the test box, after `pscp` of the leaf plugin folder + `plink` of `dev-install.sh`, `php -l`
over `include/*.php`, then in the browser:

- a `build:`-only service — Save is **enabled**, Image shows the "builds its own image" note,
  and the Build block has real boxes
- a service with an exotic `build:` key (`cache_from`, `ssh`) — that key still appears, as its
  own row, and the file is byte-identical after opening and closing
- a stack whose compose file is only `include: [other.yaml]`, with `other.yaml` beside it —
  the form lists the include, Open switches to its tab, and the tab is **not** marked orphan
- point an `include:` at a file that is not there — a missing-file warning appears
- an ordinary stack with neither — nothing changed anywhere

## Left out

- Resolving included services into the form.
- Editing a service that lives in an included file (deliberately left possible, not built).
- `args:` as an editable name/value list — decide after seeing the scalars on screen.
- `renameService()` does not rewrite a stale `container_name` when a service is renamed. Now
  known, and arguably fine since the value is inert — but it is a real inconsistency and should
  be recorded rather than quietly fixed inside this work.
