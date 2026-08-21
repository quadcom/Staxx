# PLAN 60a — the parser stops partway through and says nothing

Sub-plan of `PLAN_60.md` Phase 2, called for by that phase itself. Written 2026-08-21, after
Phase 1.1 landed and turned this from a latent fault into a blocking one.

## Why this moved to the front of the queue

PLAN_60 sequenced Phase 2 after Phase 1. Running Phase 1.1 proved that ordering wrong.

Three round-trip assertions went red the moment `insertChild()` started taking its column from
the parent's existing children. All three are the same case: a service whose own indentation is
inconsistent (`GOOD:` at six, `BAD_INDENT:` at five). Reproduced directly:

```yaml
services:
  broken:
    image: alpine
    environment:
      GOOD: 1
     BAD_INDENT: 2
```

`buildForm` reports one service, **zero** warnings and **zero** seals — the ragged line simply
falls out of the parse and nothing says so. Switching the `environment` section off then produced:

```yaml
services:
  broken:
    image: alpine
x-unraid:
     BAD_INDENT: 2
     sections:
       broken:
         environment: '{"after":"image", …}'
```

The stray line has been adopted as a child of a brand-new root-level `x-unraid:` key, and its
column has become the column for the whole metadata block. The operation **reported success**.

Before 1.1 this refused and rolled back — but only by accident: the hardcoded two-space step
disagreed with the stray line's five, the consistency check tripped, and the refusal fell out of
a bug. 1.1 removes the disagreement, so the accident stops happening. The correct refusal has to
be written deliberately, which is this plan.

**So Phase 2 lands before Phase 1 is called done.** Nothing else in Phase 1 is affected; 1.2, 1.3
and 1.4 stand.

## What is actually wrong

`compose-model.js` ~542:

```js
doc.root = parseMap(ctx, j, ctx.cls[j].indent, false).node;
```

`.next` — how far the parse got — is discarded. `parseMap` (~364) and `parseSeq` (~427) both
`break` on the first line they cannot key, with no `ctx.warnings.push` and no `seal()`. So a file
can be read to line 5 of 40 and report itself as fully understood.

The trigger is not exotic. One sibling indented three where its neighbours use four is enough. So
is a multi-line plain scalar, which is legal YAML that compose reads happily:

```yaml
services:
  web:
    image: nginx
    command: echo hello
      world
  db:                  # invisible: no field, no warning, no seal
    image: postgres
```

Knock-ons already confirmed: `addService(doc, form, 'db')` succeeds and writes a **second** `db:`
key, and cross-references to `db` are told no such service is defined.

## The change

### 1. Notice the shortfall

Keep `parseMap`'s return value at the root parse. Compare `.next` against `lines.length`,
**ignoring trailing blank and comment lines** — a file ending in a comment is not a truncated
file. `significant()` already does that walk; reuse it rather than writing a second one.

### 2. Seal the remainder and say so

When they differ, `seal(ctx, next, lines.length, 'unparsable')` and push a warning naming the
**first** line number that could not be read. `seal()` already records into `ctx.sealed` and the
`'unparsable'` reason is already in use for a root that is not a mapping (~539), so neither is new
vocabulary.

The warning is user-facing, so it is a full sentence saying what to do next — name the line, say
the rest of the file below it is being left alone, and that the usual cause is a line indented
differently from the ones around it.

Serialise must stay byte-identical: the document *is* its line array, and a seal records a range,
it does not rewrite one. Every existing round-trip assertion on these files must still pass
unchanged. That is the test that this step is right.

### 3. Refuse structural writes into a document with an unread region

This is the part that replaces the accidental refusal, and the reason this plan exists.

A document carrying an `unparsable` seal cannot be reasoned about below that point, so any write
that **inserts or removes structure** must refuse outright rather than guess: `ensurePath`,
`insertChild`'s callers, `addService`, `addNested`, `addDeclNested`, `stashSection`,
`restoreSection`, `declareNetwork`, `writeSectionEntry`. One guard at the top of each, or — better
and lighter — one guard inside `ensurePath` and `insertChild` that every caller inherits. Prefer
the latter, and check whether a single predicate (`hasUnreadTail(doc)`) placed at the two
choke points covers the whole list before adding it in nine places.

Editing a value **in place** is not affected. Changing a scalar on a line the parser did read is
safe whatever follows it, and refusing that would make a ragged file unusable rather than
partially usable.

Refusals return the existing convention (`-1`, or `false`) and set the existing error string. The
message must be a sentence: the file has a line the form cannot read, so it will not add or remove
anything until that line is fixed by hand.

**`addService` in particular:** refuse a name that may exist inside the unread region, which is
every name once a region is unread. That closes the duplicate-`db:` route above.

### 4. `lint()` reports it

A file the form cannot fully read is exactly what the margin is for. One entry, pointing at the
first unreadable line, with the same wording as the parse warning.

## Explicitly out of scope

**Do not attempt to parse multi-line plain scalars properly.** The goal is "never silently lose
content", not full YAML coverage. A file the parser cannot read is allowed to stay unreadable — it
is not allowed to be silently truncated, and it is not allowed to be written to.

Do not change `parseMap`/`parseSeq`'s `break` behaviour itself. They stop correctly; the fault is
that nobody looks at where they stopped.

## Verifying it

The three currently-red assertions are the acceptance test — they must go green **by refusing
deliberately**, with the file byte-identical, not by the indent check tripping:

- `stashSection refused: a partly-created x-unraid is rolled back`
- `stashSection refused: no bare x-unraid was left behind`
- `08-deliberately-broken/compose.yaml (1 section stash/restore round trips)`

New cases, from PLAN_60 §11.2:

| Case | Asserts |
|---|---|
| A **multi-line plain scalar** | the services *after* it still appear, or the file is sealed and warned about — never silently short |
| A mapping with **inconsistent sibling indents** (4 and 3) | a warning or seal rather than silent truncation |
| The ragged file above | `addService` refuses; no second key is written |
| Every existing fixture | serialises byte-identically, and the assertion count does not drop |

The last row is the one that matters most: this change must add warnings without moving a byte.

## Definition of done

- The root parse no longer discards how far it got.
- A truncated parse produces a seal, a warning naming the line, and a lint entry.
- A structural write into a document with an unread region refuses, and leaves the file
  byte-identical.
- An in-place value edit on a line that *was* read still works.
- The three red assertions pass, and the full suite is back above its 1,420 baseline.
