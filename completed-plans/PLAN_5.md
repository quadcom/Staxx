# Health check and resource limits, editable

**Status: COMPLETE** — built and deployed to the test box on 2026-08-13. Kept for reference. Phase 3
of six; phases 4–6 are `PLAN_6.md`.

`tests/yaml_roundtrip.js` section N covers it, taking the suite from 362 to **382** cases.

Three things came out differently from the plan, all noted in place below.

- The uncovered-child pass emits a row whose **shape** decides whether it locks, not a blanket lock —
  see the correction under "Covering the rest of the block".
- `test` earned a **leaf-table entry of its own** (`'The check itself'`). It is never editable as a flow
  sequence, but `humanise()`'s fallback titled it "Test", which reads as a verb rather than the name of
  a thing. The entry only supplies the title; the flow form still falls through to the locked pass.
- **A renderer bug surfaced while wiring the groups up.** `fieldHtml()`'s box branch was gated on
  `isContainer || grp === 'advanced'`, so a field in the new `health` or `resources` group — still
  `binder === 'setting'` — would have rendered as a row with no box at all. The condition is now
  `f.binder === 'setting'`, which is exactly equivalent for every pre-existing case because the
  `if (f.locked)` branch runs first, and additionally covers the dotted targets.

## Context

`PLAN_4.md`'s catch-all makes `healthcheck:` and `deploy:` visible as read-only blocks. They are the
two nested blocks an Unraid user actually tunes — how often a container is checked, and how much CPU
and memory it may take — so read-only is the wrong resting place for them.

**No new write primitive is needed.** The parser is recursive with no depth limit
(`parsePair:330` recurses into `parseMap`) and `writeScalar` (:512) needs only a spot, so
`healthcheck.interval` and `deploy.resources.limits.cpus` are **already writable today**. The only
missing piece has always been a field pointing at one. `settingTarget()` (:821) is reused verbatim on
the nested pair, which brings the editable box, the note box and both markers with it.

## The leaves

```js
// Nested values the form can edit. The parser reaches these already and
// writeScalar needs only a spot — the only thing missing was a field pointing
// at one. An ABSENT leaf is never offered: creating a healthcheck block means
// inserting nested keys, which arrives in PLAN_6.md.
var LEAVES = {
  healthcheck: [['interval'], ['timeout'], ['retries'],
                ['start_period'], ['start_interval'], ['disable']],
  deploy:      [['resources', 'limits', 'cpus'],       ['resources', 'limits', 'memory'],
                ['resources', 'reservations', 'cpus'], ['resources', 'reservations', 'memory']]
};
```

`harvest()` walks each path through `.pairs[seg].value`. Every segment present and the leaf a plain
scalar → `settingTarget(leafPair, key + '.' + path.join('.'), lines)`. Field id
`web/setting/healthcheck.interval` is unique.

## Covering the rest of the block

After the leaves, for each `block` key emit a row for any **direct child that produced no field**,
through the same `settingTarget()` the catch-all uses. So `healthcheck.test` — a flow list, sealed —
appears read-only saying *"this is written as a list on one line"*, and a `deploy.replicas` nobody
asked about appears too. The parent key itself is then skipped, so nothing shows twice and no two
field ranges overlap.

This is what stops the feature quietly losing information: a block is either broken into editable
leaves or shown whole, never partly both.

**Corrected after building it.** An earlier draft of this said an uncovered child is emitted *locked*.
It is not, and should not be: `settingTarget()` locks a value whose shape it cannot write and leaves a
plain scalar editable, so `deploy.replicas: 3` comes out as an ordinary number box. That is right.
Locking a scalar the form can safely write would be an arbitrary exception to the catch-all, and the
lock reason would have to be untrue. Only the shape decides.

## The two groups

New `health` and `resources` groups on the `--advanced` template (label · value · note, with ticks),
neither carrying an Add button — nothing here can create a key.

```
HEALTH CHECK
       Check every            30s              note…
       Give up after          10s              note…
       Failures allowed       3                note…
       Grace period at start  10s              note…
       The check itself       ┌ test: ["CMD", "curl", "-f", "…"] ┐
                              └ not editable here — a list on one line ┘

RESOURCE LIMITS
       CPU limit              0.50             note…
       Memory limit           512M             note…
       CPU reserved           0.25             note…
       Memory reserved        256M             note…
```

`groupFor()` reads the target's prefix: `healthcheck.` → `health`, `deploy.` → `resources`. Titles
come from a dotted-path lookup in the same `KEYS` mechanism; anything else falls back to
`humanise(last segment)`.

## The one hazard

**Quoting.** `cpus: '0.50'` is `style: 'single'`, and `emitScalar` re-emits single quotes (:180).
Writing `0.75` must not drop them, or compose reads a number where the file said a string. Already
handled by the existing write path — but it is the thing to check first, because getting it wrong
changes what the file *means* rather than only how it looks.

## Verification

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
```

- `healthcheck` yields four editable leaves plus a locked `test` row and **no** `healthcheck` row.
- `deploy` yields the four resource leaves and no `deploy` row; a `deploy.replicas` yields a locked
  row.
- `cpus: '0.50'` set to `0.75` stays single-quoted.
- No two fields have overlapping ranges — a `buildIndex` sanity check over the whole fixture.
- `10-advanced-compose-test` still round-trips byte-for-byte with no edit.

Then deploy and check: health check timings and CPU/memory limits are typeable; change one, save, and
`diff` against a copy taken first shows exactly one line changed.
