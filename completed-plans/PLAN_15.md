# PLAN_15 — Value autocomplete in the editor, and field help on the form

**Status: complete, deployed 2026-08-15, awaiting a look in the browser.** Turns
`comp-autocomplete.md` into a build order. Phases 4 and 5 of the survey are **not** in this plan —
see "Left out" at the end.

**884 round-trip tests, 0 failed**, up from 792 when the plan started. Schema self-test, both
JavaScript checks and PHP lint on the test box all clean. Nothing is committed — the working tree is
left dirty for review.

Build order, Adrian's call: **Part B first**, since it is the visible half and touches nothing the
editor phases use, then phases 1, 2, 3 in order. Each deploys to the test box as it finishes.

| | | |
|---|---|---|
| Part B | the ⓘ on the form | **built and deployed 2026-08-15**, awaiting a look in the browser |
| Phase 1 | one list of values, read by both views | **built and deployed 2026-08-15**, awaiting a look in the browser |
| Phase 2 | the editor offers values | **built and deployed 2026-08-15**, awaiting a look in the browser |
| Phase 3 | fill the gaps | **built and deployed 2026-08-15**, awaiting a look in the browser |

All four parts complete. **884 round-trip tests, 0 failed** (792 when this plan started). The form's
field set was dumped before and after and is identical — 29 setting and declaration shapes either
side — which is the check that matters, since phase 3 splits two tables the form also reads.

## What you get

Today, typing `restart: ` in the Compose pane offers nothing after the colon — only the key before
it. The form knows thirteen lists of allowed values; the editor cannot see any of them, and about
twenty more closed lists are known to neither. Separately, the 134 plain-English descriptions
written for the editor are reachable only by hovering a key in the Compose pane — the Form pane, the
friendlier of the two, shows none of them.

After this: the editor offers values as well as keys, both views read the same list of values, and
every form field has an ⓘ that reveals its sentence.

---

## Four corrections to the survey

I checked every line reference in `comp-autocomplete.md` against the code. These four are wrong or
incomplete, and each changes what gets built.

**1. Four of the thirteen lists must NOT move.** The survey reads as though all thirteen become
shared vocabulary. Four of them are not compose values at all:

| Entry | Why it stays in `stacks.js` |
|---|---|
| `setting/healthcheck.test.mode` | `shell` / `cmd` / `none` are words `readTest()` invented for the dropdown. The file says `CMD-SHELL`. |
| `port/proto` | Values are `''` and `/udp` — they carry their own separator, so the box can write it away. Meaningless as editor text. |
| `volume/mode` | Same: `''`, `:ro`, `:rw`. |
| `BOOL_CHOICES` wording | The *values* `true`/`false` move; the eight sets of per-setting labels ("true — full access to the host") are form prose and stay. |

Nine move, plus the capability list. That is the honest count.

**2. The Phase 1 snapshot test cannot be written as described.** `stacks.js` needs a browser and a
DOM at load, so no test on this machine can call it — the survey's "assert what `CHOICES` produced
before equals what it produces after" has nothing to run against. What is actually possible: capture
today's option lists into a fixture file by hand, and assert in the round-trip harness that the new
`VOCAB` table in `compose-model.js` reproduces them byte for byte. `stacks.js` is then reduced to a
lookup holding no data of its own, so if the model half matches the fixture, nothing a user sees can
have changed. Weaker than the survey claims, and still worth having.

**3. `KEYS[...].choices` is the wrong hook.** It exists on six keys, but three of the lists that
need to move have no `KEYS` row at all — a dependency's `condition`, and the two declaration
drivers. Instead the registry gets a lookup table shaped exactly like `DESCRIPTIONS` (`service:`,
`healthcheck:`, `logging:`, `deploy:`, `declared:` …), because the editor already resolves a caret
to one of those buckets via `suggestionContext()`. The six dead `choices:` entries on `KEYS` are
deleted rather than left pointing somewhere nothing reads.

**4. `classify()` reports `valueCol: -1` when a key has no value yet** — which is precisely the
`restart: ` case this whole thing is for. The new function works out the column after the colon
itself rather than trusting `valueCol`.

Everything else in the survey checks out: `keyPathAbove()` (`:4353`), `keyPositionOnLine()`
(`:4393`), `runSuggest()` (`stacks.js:4827`), the never-call-`parse()` rule (`:4081-4090`), and the
`netLoad()` mutation warning (`stacks.js:4140`) are all as described.

---

## Phase 1 — One list of values, read by both views

**In `compose-model.js`, beside `DESCRIPTIONS`:**

`VOCAB` — one entry per vocabulary, each holding value/label pairs:

```js
restart: {
  values: [['no', 'no — leave it stopped'], …],
  tails:  { 'on-failure': 'count' }     // this value also accepts ":<something>"
}
```

`tails` is rule 2 of the survey — "closed head, open tail" — modelled once. It covers
`restart: on-failure:3`, `network_mode: service:<name>`, `ipc: container:<name>` and the rest,
instead of three special cases and one missing feature.

`VOCAB_AT` — which key uses which vocabulary, keyed the same way `DESCRIPTIONS` is:

```js
service: { restart: 'restart', network_mode: 'netmode', … },
logging: { driver: 'logdriver' },
declared: { driver: 'decldriver' }, …
```

New export `API.vocabFor(key, where) -> null | {values, tails}`, the exact mirror of the existing
`keyInfo(key, where)`.

**In `stacks.js`:** `CHOICES` keeps its `hint` strings (the form's column tooltips) and the four
entries above, and pulls its option lists from `VOCAB`. The four dynamic joins —
`serviceModeOptions()`, `fromChoice()`, `profileOptions()`, `imageOptions()` — are untouched and
still join at call time, for the reason `serviceModeOptions()`'s own comment gives.

**Test:** `tests/vocab-snapshot.js` — extracted mechanically from `stacks.js` the moment before the
move, so the comparison is against a photograph rather than something retyped. A new section in
`tests/yaml_roundtrip.js` asserts every value and label in `VOCAB` matches it, in order.

**Nothing a user sees changes in this phase.** That is the point of it.

### Two decisions taken while building

**No `tails` field yet**, though the survey's rule 2 asks for one. Nothing reads it until phase 2,
and the house rule is not to build for a case that does not exist yet. Phase 2 adds it with its
first reader.

**No key-to-vocabulary map yet either.** The survey wanted `KEYS[...].choices` or a table shaped
like `DESCRIPTIONS`, so a key could be looked up and its vocabulary found. The form does not need
one — it already knows which list each field wants, because that *is* the `CHOICES` key. The editor
is what needs the mapping, so it arrives in phase 2, where the awkward case can be dealt with
properly: `driver` under a declaration means the network list or the volume list depending on the
declaration's kind, and one shared `declared` bucket cannot say which.

**The trap in this phase is `netLoad()`.** It appends the server's own docker networks by pushing
straight onto `CHOICES['setting/network_mode'].options`. Once that array is a fresh copy from the
registry, the push lands on a throwaway and the server's networks quietly stop appearing — a
regression that no test on this machine could catch, since it needs a real server reply. The names
now live in a variable of their own and are joined at call time, which is what the comment above
`serviceModeOptions()` already said to do.

---

## Phase 2 — The editor offers values

**New model function**, `API.valueContextAt(text, offset)`, returning `null` or
`{key, where, start, end, prefix, vocab}`.

It returns something only when the caret is after the colon on a key line, or inside a `- ` item
under a key that has a vocabulary. `start`/`end` bracket exactly the partial value, so accepting
replaces that and nothing else. It reuses `keyPathAbove()` and `suggestionContext()` — the same two
functions key autocomplete already uses — and, like them, **never calls `parse()`**: the file is
mid-edit exactly when a suggestion is wanted.

Three things it must refuse: a caret inside `${…}`, a caret in a comment, and a key with no
vocabulary. `interpolates()` (`:1733`) already answers the first.

**Wiring in `stacks.js`:** `runSuggest()` asks `keySuggestions()` first, then falls back to
`valueContextAt()`. Same panel, same keyboard handling, same positioning — nothing new is drawn.
One change is needed: `acceptSuggest()` appends `': '` unconditionally today, which is right for a
key and wrong for a value. It becomes mode-aware, inserting the bare value.

Where a form field already exists for the line, `fieldAtLine()` + `choiceFor()` give the
fully-joined list for free — server networks, pulled images, declared names. Tried first; `VOCAB`
covers everywhere the form harvests nothing.

**Every suggestion is a hint, never a rule.** Any compose value may be written `${VAR}`, so nothing
is ever rejected or rewritten — you can type straight past the list.

**Test:** new section — `null` before the colon, in a comment, on a blank line and inside
`${`; `restart: unl` narrows to one; `restart: ` offers all.

### How it came out

**The function returns the same shape `keySuggestions()` does**, plus a `value: true` flag. That
sameness is the design: the panel, its keyboard handling, its positioning and clipping, and the row
markup all work unchanged, and the flag exists only so accepting a value does not append `': '` the
way accepting a key does. The `stacks.js` half of this phase is about a dozen lines as a result.

**A space after the colon is required.** With the caret at `restart:` and no space, nothing is
offered — accepting there would write `restart:always`, which YAML reads as a plain string, not a
key and a value. It looks like an off-by-one until you try it.

**Values keep their vocabulary's order, not alphabetical.** Keys are sorted alphabetically because
there are ninety of them and you are hunting for one. A value list is four entries in a deliberate
order, commonest and safest first, and the form already shows them that way — the editor sorting the
same list differently is a small wrongness that is hard to spot and irritating to live with.

**`suggestionContext()` reports one `declared` bucket for all four declaration kinds**, but `driver`
means the network list under `networks:` and the volume list under `volumes:`. The path says which,
so the kind is resolved from it before the table is consulted. This is why `VOCAB_AT` splits the
four kinds where `DESCRIPTIONS` deliberately does not: a *description* of "driver" is true for both,
a *list of drivers* is not.

**Long-form `depends_on` values are not offered.** A dependency sits at a path
`suggestionContext()` answers `null` for, and teaching it that shape is a change to key autocomplete
as much as to values. Not needed for anything in this plan, so it is left alone.

---

## Phase 3 — Fill the gaps

The survey's table of ~20 unknown value sets is added to `VOCAB`, benefiting both views at once.
Also corrected while in there: `pull_policy` is missing four values, `networks.driver` is missing
`overlay`, `cap_add`/`cap_drop` are missing `ALL`, and `attach`/`oom_kill_disable` are not typed as
boolean today.

**`deploy` needs its keys before its values.** `DESCRIPTIONS.deploy` has one entry, so `deploy.mode`
is not offered as a *key*, never mind a value. Fixing that means a `DEPLOY_LEAVES` table separate
from `LEAVES.deploy`, exactly as `BUILD_LEAVES` was split off for the same reason (`:4092`) — adding
keys to `LEAVES` would silently give every service form fields nobody asked for. Same for three
declaration keys (`configs.environment`, `secrets.name`, `networks.enable_ipv6`).

### Half the survey's table was unreachable, and that changed the phase

Checked before building rather than after: key autocomplete stops at depth three. `deploy`'s own
children come back empty, and `deploy.restart_policy.*`, `deploy.update_config.*`,
`logging.options.*`, `develop.watch` and long-form ports all come back `null`. A vocabulary for any
of those would have been data nothing could reach — worse than not writing it, because it reads as
a feature.

So the phase does the unlocking first: `suggestionContext()` learns the three `deploy` sub-blocks
and `logging.options`, and declarations get an editor-only key table the way `deploy` does. `update_config`
and `rollback_config` share one vocabulary and one description bucket, being identical in shape.

**Four of the survey's rows are deliberately left out**: long-form ports, long-form volumes,
`develop.watch` and long-form `env_file`. Every one lives inside a `- ` item carrying its own
mapping keys — the shape phase 2 explicitly excluded and `suggestionContext()` does not resolve.
They belong with the long-form work in survey phase 5, not here.

### "Closed head, open tail" needed no machinery

The survey's rule 2 asked for a `head`/`tail` model so `restart: on-failure:3` would be understood.
It turns out to need nothing: the prefix `on-failure:` matches no value in the vocabulary, so the
panel simply does not appear and the typing is not fought — which is exactly the wanted behaviour.
A test records it; there is no code. The same is true of `network_mode: service:web` and
`ipc: container:x`.

---

## Part B — The ⓘ on the form

Independent of the phases above; can land first if you prefer.

A small ⓘ beside a field's name. Click it and the sentence appears under the row; click again to
hide. **Not a hover tooltip** — that is a house rule already argued in `StacksPage.php:203-209`,
because a tooltip cannot be reached on a phone.

Where it attaches needs care, because only `setting` rows have a label of their own:

| Row | ⓘ goes |
|---|---|
| `setting` (Container, Health check, Resource limits, Advanced) | beside the field's label |
| Ports, Volumes, Variables, and the other groups | beside the **group heading** — the group's caption is where their meaning lives |

Two constraints already documented in the code and obeyed here: the help paragraph is emitted in
the tail block beside `adviceBlock`, **after** the `×`, because a full-width child strands anything
emitted after it (`stacks.js:2062-2065`); and it reuses `.staxx-fieldhint`, not
`.staxx-fieldnote`, which is accent-coloured and means *something is wrong here*.

Wording comes from `keyInfo()`, already exported. **Test:** new section `Q` — every field the form
renders resolves to a description, so the two cannot drift apart as fields are added.

---

## Part B, as built

**806 tests passing, 0 failed** (792 before). Schema self-test clean, no undeclared names, PHP lint
clean on the test box. Four things came out differently from the plan above.

- **Two titles in the plan were wrong.** I specified `How the check is run` and `The command to run`
  for the two health-check boxes. `inferTitle()` already renders those labels as `How the check
  runs` and `The check itself`. The render is the authority — a description headed differently from
  the label above it reads as being about something else — so the descriptions were pulled back to
  match rather than changing a label people already see.
- **Folded settings got an ⓘ too**, which the plan did not ask for. `internal`, `attachable`,
  `external` and a dependency's `required` are the settings least likely to be understood on sight,
  they already have descriptions, and `declaredFoldHtml()` turned out to have a real label to hang
  the icon from. Leaving them the only described fields with no way to read their description would
  have been the odd one out.
- **A long-form dependency's row has no ⓘ.** Both its boxes are named by placeholder text only, so
  there is no label to attach one to, and adding one would have meant changing the function every
  list, mapped and device row shares. The Depends on group heading carries the sentence instead.
- **A locked row has none either.** It branches out before the label is ever emitted, and it already
  shows its own "not editable here because…" note.

The drift guard is `helpGaps()`: it walks `KEYS`, `LEAVES`, `BUILD_LEAVES`, `DECL_LEAVES` and
`DEPENDS_LEAVES` and returns every key none of the description tables can describe. The test demands
it comes back empty, so the day a key is added to a table without its sentence, the suite says which
one.

## Left out

- **Survey Phase 4** (offering the file's own service, network and volume names in the editor). It
  needs a new scan written from `classify()` alone, complementary to `hostPaths()`. Worth doing,
  but it is its own piece of work and phases 1–3 do not depend on it.
- **Survey Phase 5** (long-form ports and volumes are second-class in the form). This is
  form-harvesting work, not autocomplete, with a different risk profile. Its own plan once 1–3 land.
- **Autocomplete still hides a key the service already has.** Your call, unchanged.
- No suggestions for genuinely free values: `command`, `user`, `hostname`, paths, durations.
  `mem_swappiness` is bounded 0–100, not closed — a 101-item list helps nobody.

---

## Verifying

Locally, before every deploy:

```sh
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
node --check src/staxx/.../stacks.js
node --check src/staxx/.../compose-model.js
python tests/validate_schema.py
```

Each phase deploys to the test box as it finishes. In the browser, in this order: `restart: ` offers
options; `restart: unl` narrows; `restart: on-failure:3` is not fought; `image: ` offers what is on
the server; typing `${` gets the list out of the way; the ⓘ on the Restart row and on the PORTS
heading both open; the form is no taller than before with nothing expanded.

---

## What the field dump settled

Before writing any of this I dumped every field the form builds across the whole fixture corpus,
so the shapes below are facts rather than guesses. Three things came out of it:

- **Only three binders need a per-row ⓘ** — `setting`, `declared` and `depends`. Ports, volumes,
  variables, labels, devices and every list entry have no label of their own, so their help belongs
  on the group heading, exactly as the survey said.
- **Twelve descriptions were missing**, all of them for fields the form already renders: the two
  boxes `healthcheck.test` is split into, the four resource limits under `deploy`, and a
  dependency's own three settings. Written as part of Part B rather than deferred to phase 3.
- **A field's help cannot always resolve, and should not.** The Advanced group is a catch-all that
  renders whatever key the file actually holds — the corpus contains a service with `portz:`, a
  plain typo. No sentence is the honest answer there, so the ⓘ simply does not appear, and the
  drift test checks the model's own tables against `DESCRIPTIONS` instead of demanding every
  rendered row resolve.
