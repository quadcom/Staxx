# PLAN 66 — an edit that cannot land on the wrong line

**Status: complete 2026-08-21.** The guard is in place in every scalar writer and in the two
line-splicing writers, and a stale write is reported to the person rather than swallowed.

Written 2026-08-21 from a bug found the same day in `PLAN_64` Phase B. **Smaller than the reservation
note estimated** — see section 3, which corrects it: this is not a tax on twenty writers.

---

## 1. The bug this exists to make impossible

Declaring a network and then attaching a container to it is two writes. The order was right and the
refusal handling was right, but declaring **re-reads the document**, and the form was still holding
its memory of where each line used to be. In a file whose `networks:` block sits *above* `services:`,
the declaration inserts a line before every service line, so every remembered position was one line
too high — and the attach landed in the middle of the image line, turning `image: nginx` into
`imagmybridge`.

Not a half-finished edit. **An unrelated line destroyed, in a file that still parsed cleanly.**

Its probe passed sixteen cases and never saw it, because every fixture in the corpus with a
`networks:` block has it at the *bottom*, where an insert shifts nothing below it. The bug was found
by asking "what if it were at the top" — a question a corpus can only answer if somebody thought of
it first.

## 2. What this is, under the rewritten rule 2

Rule 2 now protects the author's meaning and their annotations rather than the byte order, and says
plainly that a file which is wrong should be fixed rather than refused, with the change visible and
undoable. **That makes this plan clearer, not weaker.** It is not "write less". It is
**know exactly what a write did, and be unable to write somewhere you did not mean to.**

Adrian's original framing was to build the file from a recipe, write it aside, diff it, and swap it in.
Two halves of that, recorded because the reasoning is worth keeping:

- **Rebuilding from a recipe cannot work.** The recipe would have to carry every comment, blank line,
  key order, anchor and quoting choice the file holds. Whatever it does not carry is lost. A recipe
  that carries all of it *is* the file — at which point it is the line-splicing writer that already
  exists, with a diff bolted on.
- **The staging half already exists and did not help.** Every save writes a temporary file beside the
  real one and `rename()`s it into place; the editor does not touch the file at all until Save. The
  corruption above happened *inside* that staging copy, which then looked like a perfectly good file.
  Atomic replacement protects against a crash mid-write. It does nothing against writing the wrong
  thing correctly.

## 3. Why this is small — the reservation note was wrong

Checked in the code 2026-08-21. **Every mutation of a compose document goes through one of exactly
two choke points**, not twenty:

| | |
|---|---|
| `splice(doc, at, remove, insert)` | Every structural write. Adds or removes whole lines, then re-parses. |
| Four direct `doc.lines[n] = …` assignments | Every in-place write. `writeScalar()` is the one that matters; the other three are a comment strip, a key rewrite and one line replacement. |

So the guard has two homes. The earlier estimate — that every writer would have to declare which lines
it may touch — was wrong, and the reason it was wrong is worth stating: a per-writer *declaration* is
also **circular**. The writer's claim comes from the same stale data as its target, so it would have
cheerfully claimed the image line and passed its own check. **A claim is no good; the check has to be
against the file itself.**

## 4. The design

### The precondition: a spot remembers the text it was taken from

Every in-place write is handed a `spot` — a line, a column and a length. Today it trusts all three.

**Have the spot also record the exact text it was read from, at the moment it was read.** One
substring per spot, captured where spots are built. Then `writeScalar()` checks, before touching
anything, that what is currently at that line and column *is still that text*. A stale position fails
instantly, because the bytes at the old offset are something else.

This is a **precondition, not a postcondition** — it fails before the write, so there is nothing to
revert and no corrupted intermediate state to explain. It catches the exact bug above, at the exact
moment, with a message naming what it expected to find.

It also catches the general case, which is worth more than the specific one: **any** position that has
gone stale for **any** reason — a second write in the same action, a hand edit landing between two
form writes, a future writer that forgets to re-derive.

### The backstop: what a structural write claims to have changed

`splice()` knows precisely what it did: an index, a count removed, and the lines inserted. That is not
a claim, it is the operation. Recording it costs nothing and gives, for any action, an exact account
of which lines moved and why — which is what makes a failure diagnosable rather than mysterious.

Whether that account is then *asserted* against a before-and-after comparison is a second phase and
may not be needed once the precondition exists. **Build the precondition first and measure whether
anything gets past it.**

### When it trips

Say what happened, in a full sentence, and write nothing. Reuse the wording the form already has for
"that cannot be written as it stands — edit this one in the Compose view". A guard that trips is a bug
in StaXX, not a mistake by the person, so it must not read like an accusation — and it must not be
silent, because a silently skipped edit is how someone loses confidence in the whole form.

## 5. Files touched

| File | Change |
|---|---|
| `javascript/compose-model.js` | spots carry their source text; `writeScalar()` and the three other in-place writers check it; `splice()` records what it did |
| `javascript/stacks.js` | only if a tripped guard needs saying differently from the existing wording |

No new files. No schema change. Nothing in PHP — the server writes what it is handed and computes no
edits, so it needs none of this.

## 6. Work items

1. Capture the source text on every spot, where spots are built. Check the cost: spots are built on
   every parse, and a parse happens on every keystroke's debounce.
2. `writeScalar()` verifies before writing. Refuse, with a sentence, on a mismatch.
3. The other three in-place assignments get the same check. They are the ones nobody is thinking
   about, which is reason enough.
4. `splice()` records the operation into a per-document journal, capped so it cannot grow unbounded
   over a long editing session.
5. Re-point `PLAN_64` Phase B's own fix at this: the rebuild-and-look-up-again it does by hand becomes
   belt and braces rather than the only thing standing between a stale position and a mangled file.

## 7. Tests

- `node tests/yaml_roundtrip.js` — 1482, unchanged. The guard must not refuse a single legitimate
  edit. **A drop here means the check is too strict**, which is a worse failure than not having it:
  a form that refuses valid edits is one nobody can use.
- `node tests/js_undeclared.js` and `node --check` on both files, as always.
- A throwaway probe that reproduces the original bug directly — networks above services, declare then
  attach without re-deriving — and asserts it is now **refused** rather than written. That probe is
  the whole point of this plan and should be kept somewhere it will be run again.
- The awkward layouts generally: a service whose keys are in an unusual order, a file with two
  services where the first is longer, a file whose top-level keys are reversed.

## 8. Risks

| Risk | Standing |
|---|---|
| The check is too strict and refuses valid edits | The real risk, and worse than the bug. The round-trip suite is the measure: 1482 must still pass, and a drop is a fault in the guard. |
| The captured text costs too much on every parse | Measure at item 1 before building on it. One substring per spot should be nothing against a full parse, but "should be" is not a measurement. |
| It gives false confidence | It catches a stale *position*. It cannot catch a write that is aimed correctly and wrong in what it writes. Say so where it is documented, so nobody stops writing tests because a guard exists. |
| A guard that trips silently | Then an edit vanishes with no explanation, which is worse than the corruption it prevented. Item 2's sentence is not decoration. |

## 9. Sequencing

Independent of `PLAN_62`, `PLAN_64` Phase D and `PLAN_65`. It touches the writer, so it is
**sequential** with anything else that does. **`PLAN_67` — one house layout for every file that
arrives — should not start before this**, since a pass that rewrites a whole file is precisely what
wants this underneath it.
