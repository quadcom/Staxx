# PLAN 67 — one house layout for every compose file that arrives

**Status: parked 2026-08-21. Adrian's concept, recorded so it is not lost. Not to be designed or
built until he asks.**

---

## The idea

Raised by Adrian 2026-08-21, immediately after rewriting rule 2. **Every compose file that arrives —
imported, converted, or pasted in — is laid out to one convention, so that files across the whole
install read the same way.**

This is the first piece of work the clarified rule makes possible. Under the old wording it was
forbidden outright: applying a house order to somebody's pasted file *is* reordering it. Under the
new one it is allowed, on the same terms as any other correction — **nothing may be lost, and the
person has to be told what changed and be able to put it back.**

## Why it is wanted

Consistency for the reader. A form is only half of what someone learns compose from; the other half
is the file itself, and twenty files in twenty layouts teach nothing. It also makes every other
feature cheaper to reason about — including `PLAN_66`'s write guard, which has an easier job when the
shape of a file is predictable.

## What has to be decided before any of it is built

Recorded as questions, deliberately unanswered.

1. **When does it apply?** On arrival only — import, convert, paste — or on every save? Arrival only
   is the safer default and matches how the idea was described. Reformatting on every save turns each
   ordinary edit into a whole-file rewrite, which is a much larger promise to keep.
2. **What is the convention?** Top-level order, service-key order, quoting, indent, where `x-unraid`
   sits, whether a blank line separates services. This is the bulk of the thinking and none of it is
   done. The converter's own output is already consistent and is the obvious starting point — it is
   what most files will already look like.
3. **What happens to a file that is already in some other consistent style?** Somebody's own careful
   layout being replaced by ours is exactly the kind of thing the rule is there to make visible.
   An offer rather than an assumption may be the right answer.
4. **Comments.** The hard part. A comment sits on a *line*, not on a key, so any reordering has to
   carry each one with whatever it annotates — and decide what to do with a comment that annotates
   nothing in particular, or a whole block, or the file itself.
5. **Anchors and aliases.** An alias must appear after its anchor, so a house order can turn a valid
   file into one that will not load. Any layout pass has to either respect that dependency or decline
   to reorder a file that uses them.
6. **Is it reversible?** Rule 2 as rewritten says a change has to be undoable. For a whole-file
   relayout that means keeping the original somewhere, or being confident enough to offer a preview
   and a refusal before writing.

## Relationship to the other plans

- **`PLAN_66` should land first.** A pass that rewrites a whole file is precisely the thing worth
  having a write guard underneath, and its "which lines was this edit allowed to touch" question has
  an interesting answer here — *all of them* — which is worth designing for rather than discovering.
- Independent of `PLAN_62`, `PLAN_64` and `PLAN_65`, but it touches the writer, so it is sequential
  with anything else that does.
- The corpus in `tests/fixtures/test-stacks/` is what proves this one. Every fixture exists because it
  holds a quirk; a layout pass has to survive all of them and, unusually, is *expected* to change
  their output — so the round-trip suite will need a second mode that asserts "same meaning" rather
  than "same bytes". That is a real piece of work in its own right and should not be discovered late.
