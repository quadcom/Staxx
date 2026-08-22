# PLAN 76 — give somebody your stack

**Status: reserved 2026-08-22. Relates to Adrian's longer-range idea of compose templates on their own
repository; this is the minimal, no-repository first step. Not to be built until approved.**

## The idea

A stack is already a folder with a portable file in it, so sharing one *should* be trivial — and
almost is, except that the file is full of things personal to the machine it came from: the paths, the
ports, and above all the passwords.

**So: export a stack as a recipe with the personal parts taken out, and import one somebody else
exported.** No hosting, no repository, no trust decision about a stranger. Just a file that leaves and
a file that arrives.

## What export does

Everything it needs is already known:

- **Blank every setting marked secret.** The form already knows which those are, from the file rather
  than from guesswork.
- **Generalise the personal paths.** A folder on this machine becomes a placeholder to be filled in.
- **Flag ports that are likely to be taken elsewhere.**
- **Keep the annotations.** The comments, the descriptions, the notes on what each setting does — this
  is what makes a recipe better than the file it came from, and it is exactly what the writer already
  preserves.

Then **show what was taken out** before handing the file over. A screen listing every blanked value,
so nobody discovers afterwards that they published a password — and nobody discovers on the other side
that a value is missing without being told which.

## What import does

Exactly what an import already does: **it opens in the editor for review, and nothing runs.** The
person fills in the blanks the recipe declares, sees the warnings, and saves when ready. This is the
same stance already taken for converted templates, and it is the whole safety story.

## The rule that survives from everywhere else this has been tried

Every ecosystem that hosts other people's configurations has learned the same lesson, and this plan
should start from it rather than rediscover it: **a stranger's configuration always opens for review
and never simply runs.**

A compose file can ask for the whole disk, the machine's own network, and the control socket that
governs every container on the box. Nothing about the format limits what a recipe may request, so
there is no version of "one-click install from the internet" that is safe. If a hosted collection is
ever built, the thing that makes it survivable is a plain list of what a recipe is asking for —
privileged access, the host network, the control socket, a mount covering the whole disk — shown
before anything is saved, and derived from the same full parse the form already does.

## What must never be guessed

- **Never decide a value is not secret.** If in doubt, blank it and say so. A false blank is an
  inconvenience; a leaked password is not.
- **Never auto-update a recipe someone imported.** Pulling a newer *image* is one thing; changing the
  structure of somebody's stack from a remote source is a different thing entirely, and it is how
  every supply-chain problem in this space has happened.
- **Never strip an annotation.** They are the point.

## Open questions

1. Is the export the existing archive format, or a single file? A single file is far easier to hand to
   somebody.
2. How does a recipe declare "you must fill this in", so the importing form can ask rather than start
   with a broken stack?
3. Does a recipe record where it came from, and is that useful or merely a way of looking trustworthy?

## Size

Medium for export and import. **A hosted collection is a separate, much larger plan and should not be
started as part of this one.**
