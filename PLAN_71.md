# PLAN 71 — what is running versus what the file says

**Status: reserved 2026-08-22. Cheapest of the valuable ones. Not to be built until approved.**

## The gap, verified

**Nothing anywhere compares what is running against what the file now says.** Confirmed by reading
the code: there is no comparison of a container's recorded configuration against the file, and no
surface anywhere that says a stack is out of date with itself.

The everyday consequence: edit a stack, forget to press restart, and the row shows a healthy green
running stack whose settings are **not the ones in force.** The page is telling the truth about the
container and a lie about the configuration, and there is no way to tell the two apart.

The same missing comparison is why there is no answer to *"what will this actually change?"* before
pressing a button that restarts a running application.

## What Docker already knows

Compose stamps each container it creates with the configuration it was created from, and records the
files it was built out of. So this is a **read**, not a guess: the machine already holds enough to
answer "were these containers made from this file as it stands now".

Worth proving on the server before designing further, because the exact shape of what is recorded
decides how good the answer can be — whether it is only "yes or no", or whether it can name which
settings differ.

## The two things this gives, in order

**1. A stack is marked out of date.** A row whose containers were created from an older version of the
file says so. Quiet, factual, no action forced. This alone fixes the lying-green-row problem and is
most of the value.

**2. Show me what will change.** Before a restart or recreate, list the differences between what is
running and what the file says. Which service, which setting, old and new.

The second is worth having but is the harder half, and it should not hold up the first.

## What must never be guessed

- **Out of date is not broken.** A running stack whose file has moved on is a completely normal state
  — somebody is mid-edit. The wording must not imply fault or nag.
- **Never restart on the strength of this.** The whole point is to tell somebody what a restart would
  do so they can decide. Acting automatically inverts it.
- **Unknown is not "up to date".** A container StaXX did not create, or one whose stamp cannot be
  read, must read as unknown. Reporting a false match here is worse than reporting nothing.

## Open questions

1. Is the comparison cheap enough for the ordinary state refresh, which runs every few seconds, or
   does it belong on the slower row rebuild?
2. Does it survive a stack being moved? A moved stack keeps its project name while Docker remembers
   the old path — the same problem the existing three-way state index was built to solve.
3. For the difference list: is it a comparison of resolved settings, or of the file text? Resolved is
   more truthful and slower; text is cheap and can report a difference that changes nothing.

## Size

Small for the out-of-date mark. Medium for the difference list.
