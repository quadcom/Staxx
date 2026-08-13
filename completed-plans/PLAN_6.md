# The Stack section: the file's declarations, on the form

**Status: COMPLETE** — phases 4, 5 and 6 built and deployed to the test box on 2026-08-13. Kept for
reference. `tests/yaml_roundtrip.js` sections O, P and Q cover them, taking the suite from 382 to
**430** cases. Phase 6 is renderer-only and has no case there — it is checked in the browser.

Four things came out differently from the plan, beyond the six decisions recorded below.

- **Phase 4 is not read-only**, as decision 1 explains: only renaming and adding needed phase 5, so
  every existing setting shipped editable rather than through a read-only pass phase 5 would discard.
- **A `nameLocked` flag** carried "the name box is read-only until phase 5" from the model to the
  renderer, and was deleted again in phase 5 along with `boxHtml`'s `lockTitle` parameter, whose only
  caller it was.
- **The declaration name is a pencil, not a box.** A box commits through a debounce, so typing a new
  name would have renamed to `s`, `st`, `sto`… rewriting every reference on the way and possibly
  colliding. It reuses `inlineName()` and the service rename's explicit-commit interaction instead.
- **A long-form volume never carried `listKey`**, so `PLAN_4.md` phase 1e's dangling-reference note had
  never applied to one — `harvestLongForm` called `target()` without it. Found while checking whether
  phase 6's dropdown could key on `f.from`; it cannot, so that gates on `f.binder` instead, and the
  missing `listKey` was threaded through. `06-fedora-advanced` exists to catch exactly this class of
  thing, which is where it was confirmed.

## Context

`PLAN_4.md` reads the top-level `networks:`, `volumes:`, `secrets:` and `configs:` blocks into a
namespace, so a service's reference to one becomes a list to pick from. But the declarations
themselves are still invisible, and there is no way to create one — which leaves a hole: you can add
a network to a service that already lists networks, but not give a service its first one.

These three phases close that. Phase 4 shows the declarations, phase 5 makes them editable, phase 6
uses the result to fix the last misleading control on the form.

---

## Decisions settled before building

Six details worked out against the code, recorded here so the phases below read against them rather
than against the sketch they were written as.

**1. Phase 4 is not read-only after all.** Editing an existing `driver: bridge` needs nothing new —
it is a plain scalar and `writeScalar` handles it. Only **renaming** a declaration (which must rewrite
every reference) and **adding or removing** one (which needs a nested insert) require phase 5. So phase
4 ships with every existing setting editable and only the name box read-only, rather than a read-only
pass that phase 5 would immediately throw away.

**2. A declaration is one field**, `binder: 'declared'`, target `<kind>.<name>`, with
`parts: { name, value }` — the same two-box shape an environment variable already has:

- `name` — the declaration's own name, via `keySpot(pair)`, so a rename is a key write. **Read-only
  until phase 5.**
- `value` — the kind's primary setting, or `part('', null)` when the file does not set it.

**3. The primary setting per kind**: `networks` → `driver`, `volumes` → `driver`, `secrets` → `file`,
`configs` → `file`.

**4. The note rides on the primary setting's line, not the name's.** `parsePair` (:305) only fills
`pair.comment` when the value is a **scalar** (:315), so a comment beside `frontend_net:` with a map
under it is never captured, and `commentSpot()` (:1141) refuses anything that is not a scalar. Rather
than teach the parser about key-line comments, put the note where there is already a scalar to hang it
on. `harvestLongForm` (:766) sets exactly this precedent — a long-form port's note rides on its
`target:` line, because "the note belongs to the entry as a whole, so it rides on the first line".

**5. A declaration with no settings at all** — `frontend_net:` and nothing under it, which is common and
means "an ordinary bridge" — has no scalar anywhere, so it gets an empty value box and **no note box**.
`setComment` already refuses when `commentSpot` is null, so this needs no new guard. Filling that empty
box needs a nested insert, so it starts working in phase 5 rather than phase 4.

**6. A declaration's other settings reuse `harvestBlock`.** A declaration is a name with a map of
settings under it, which is the same shape as `healthcheck:` — so the `<details>` fold's contents are
`PLAN_5.md`'s mechanism pointed at a different table, not new machinery. Scalars come out editable,
maps and sequences come out locked, and nothing in the block is lost.

---

# Phase 4 — the Stack section

A `STACK` pseudo-service above the services, one group per declaration kind. Rows come from a new
`declaredFields(doc, lines)` producing fields with `service: ''`, binder `'declared'` and target
`'<kind>.<name>'`, appended to `form.fields`. `renderForm` buckets `service === ''` into the Stack
section and renders it first — `data-row` is an index into `form.fields`, so render order is free.

```
STACK

NETWORKS                                                    + Network
       name              driver                    note
       frontend_net      bridge                    note…            ×
       backend_net       bridge                    note…            ×
                         ▸ more settings

VOLUMES                                                     + Volume
       db_data           local                     note…            ×

SECRETS                                                     + Secret
       db_password       ./db_password.txt   📂     note…            ×

CONFIGS                                                     + Config

version: is obsolete. Docker Compose ignores it, and it can be deleted.
```

New template `--declared`: `name · setting · note · ×`. **No R/S gutter** — `required` and
`sensitive` are markers about a form control, and a declaration has none. The note box stays, because
a declaration can carry a comment.

The second box is the one setting that matters per kind: networks → `driver` (dropdown: bridge,
macvlan, ipvlan, host, none); volumes → `driver`; secrets and configs → `file`, a path box with the
existing `browse` tool.

**Anything else on a declaration goes in a `<details>` fold** — `backend_net` also has
`internal: true`. That is the pattern device rows already established (`.stackman-devmore`), and its
contents are `PLAN_5.md`'s mechanism reused: one field per nested scalar leaf. So `internal`,
`external`, `name` and `driver_opts` children each get a box in the fold without new machinery.

Empty groups still show their header and Add button, exactly as the service groups already do
(stacks.js:1136–1143) — the existing code path, not a new one. Top-level `x-unraid` is skipped: it is
metadata, rendered elsewhere already.

---

# Phase 5 — the Stack section, editable

The one phase needing new write primitives. All three are modelled on functions that already exist.

| New | Modelled on | Does |
|---|---|---|
| `addDeclared(doc, kind, name)` | `addItem`'s key-absent tail (:1340–1363) | Inserts `  <name>:` under top-level `<kind>:`, creating `<kind>:` at the end of the document when absent — after `services:`, the conventional order |
| `removeDeclared(doc, kind, name)` | `removeItem` (:1410–1452) | Splices the name's pair range, and the whole `<kind>:` block when it was the last, because a bare `networks:` is null and compose rejects it |
| rename | `renameService` + `collectServiceRefs` + `applyRenameWrites` (:1473–1605) | `writeScalar` on the name's `keySpot`, **plus every service reference in one batch** |

The rename is the piece that earns its keep. Renaming `db_data` without also rewriting `db`'s volume
line gives a file that no longer starts, and the existing service-rename code already solves exactly
that shape of problem: collect every spot first, then apply them in one pass, so no edit invalidates
another's line numbers.

Removing a declaration a service still references does **not** refuse — it leaves `PLAN_4.md`'s
dangling-reference advice on the referencing row, and compose reports it too. Refusing would block a
legitimate two-step edit.

Add buttons carry `data-add="declared:networks"`; the handler (:1398) branches on the `declared:`
prefix to `addDeclared` instead of `addItem`.

---

# Phase 6 — the volume source dropdown

Today `db_data:/var/lib/postgresql/data` renders as a host path **with a folder-browse button**, so
browsing silently converts a named volume into a bind mount. With the namespace read, the host half
becomes a `<select>`: every declared volume, plus **"a folder on the server…"**. Choosing a declared
name writes it; choosing the sentinel writes nothing and swaps the row's control to the path box with
the picker, so the file changes only once a path is chosen.

**No write-path work.** A volume's host part is already
`part(host, spot, '', ':' + container + mode)` (:645), so writing either a name or a path rewrites the
whole scalar correctly. Two care points:

- `optionsHtml()` already unshifts a value the list does not carry, so an existing bind-mount path
  shows as its own selected option.
- The chosen mode is **DOM state** (`data-source` on the row), not model state, because
  `refreshRanges()` deliberately does not re-render controls — that is what preserves the caret.

Wanting phase 5 first is the whole reason this is last: a dropdown of declared volumes is only useful
once you can declare one.

---

## Verification

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
```

- **4** — a file with no top-level blocks still yields all four Stack groups, each with its Add button
  and no rows. `backend_net`'s fold holds an `internal` field.
- **5** — `addDeclared` on a file with no `networks:` creates the block after `services:`; renaming
  `db_data` rewrites the declaration **and** `db`'s volume line in one pass; removing the last
  network deletes the `networks:` key too; removing a referenced volume leaves the dangling advice
  rather than refusing.
- **6** — choosing a declared volume writes the name; choosing "a folder on the server…" writes
  nothing until a path is picked.
- `10-advanced-compose-test` and `07-yaml-quirks` both still round-trip byte-for-byte with no edit.

Then deploy and check the Stack section lists both networks with `bridge`, `backend_net`'s fold
showing `internal`, the `db_data` volume and the `db_password` secret with its file path — and that
adding a network from the form produces a block compose accepts.
