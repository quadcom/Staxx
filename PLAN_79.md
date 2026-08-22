# PLAN 79 — marking a volume as real data

**Status: reserved 2026-08-22. Small, and squarely inside the project's central promise. Not to be
built until approved.**

## The problem

Some folders a container uses hold **nothing that matters** — a cache, a temporary working area,
something rebuilt on next start. Others hold **years of somebody's life**: a photo library, a media
database, accounts, documents.

The compose file cannot tell the difference, and neither can the tool. Both look like a path. So every
destructive action — removing a stack, recreating a container, taking a volume down — is offered with
the same weight regardless of whether the worst case is a rebuilt cache or an unrecoverable loss.

This is the classic way people lose data with compose: a command that removes volumes, run against a
stack where one of those volumes was the only copy of something.

## What it is

**A marking, in the file's metadata, that says this path holds real data** — and destructive actions
treating a marked path differently from an unmarked one.

The marking belongs in our metadata rather than anywhere else: it describes the stack, it travels with
the file, and it cannot be orphaned. That is the same test that put descriptions and update policy
there, and it passes.

The difference it makes is at the moment of danger: an action that could destroy a marked path says
which path, and what is in it, and asks properly — the double-confirmation shape already used before
starting a clashing stack. An unmarked path gets the ordinary confirmation.

## Where the marking comes from

1. **The person**, in the form — the only fully reliable source.
2. **A converted template**, where the original often distinguishes application data from a cache well
   enough to carry across.
3. **A sensible default for a few well-known cases** — but see below, because this is where it gets
   dangerous.

## What must never be guessed

- **Never mark something as safe to destroy.** The whole value of this is asymmetric: wrongly treating
  precious data as disposable is unrecoverable, while wrongly treating a cache as precious costs one
  extra click. So the absence of a marking must mean *unknown*, and unknown must behave like
  precious wherever the cost of being wrong is loss.
- **Never remove a marked path without saying exactly what is being removed**, in full, before it
  happens.
- **Never let this become a checkbox nobody reads.** If everything is marked, nothing is — which
  argues for asking about the paths that matter at the moment a stack is created, not presenting a
  wall of them later.

## Open questions

1. Does this interact with the archive-on-remove that already exists? A removed stack's file is kept;
   its data is not. Perhaps the honest answer at removal is to say plainly what is being kept and what
   is not, which may be most of this plan's value on its own.
2. Should a marked path be shown differently in the ordinary view, or only at the moment of danger?
3. Is one marking enough, or is there a real distinction between "irreplaceable" and "would rather
   not lose"? Start with one.

## Size

Small. One metadata field, one change to how destructive actions confirm.
