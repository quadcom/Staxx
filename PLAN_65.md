# PLAN 65 — warning about a stack that clashes with one already here

**Status: reserved 2026-08-21. Decisions taken; the plan itself is not written yet.**

Write this up once `PLAN_63` is finished. It builds on `PLAN_63` Phase E and does not replace it —
see the boundary below, which is the thing most likely to be got wrong later.

---

## Where this came from

Adrian, 2026-08-21, on being told that "Reinstall From Previous Apps" would have to be blocked for an
app StaXX already runs: *"what if we changed the reinstall function to a StaXX handled copy stack
with warnings on lines that contain duplicated values like paths, ports, volumes etc?"*

That turns a dead end into the thing the person actually pressed the button for.

## Decisions taken

Adrian's answers, 2026-08-21.

1. **A reinstall makes a second copy, as a new stack.** The same thing Unraid does today — it builds
   a second container and puts a suffix on the name. The running stack is never touched.
2. **Clash warnings apply to every caught install, not only a reinstall.** A brand new app whose
   default port is already taken is the common case, not the rare one, and Unraid's own Apps page
   already warns about it — so anything less is a step backwards from stock.
3. **A clash is shown on the line and listed once.** The offending line underlined in the file view,
   the field marked in the form, plus one summary saying what clashed.
4. **A clashing stack cannot be started without a deliberate double confirmation** — a checkbox and a
   confirm button, not a single OK. It must warn about **two containers writing the same appdata,
   which can corrupt it**, and about **the existing stack having to be stopped**.

Decision 4 is Adrian going further than was proposed, and it is the important one: it is the
difference between a warning someone clicks past and one that makes them stop. **Do not water it
down to a single confirm on the grounds that it is a lot of clicks. It is meant to be.**

## The boundary against `PLAN_63` Phase E

Phase E is **not** superseded and must still be built:

| Belongs to `PLAN_63` Phase E | Belongs here |
|---|---|
| Offering to convert when an existing Unraid container is opened for editing | — |
| Routing the reinstall type down StaXX's own conversion path at all | — |
| Naming the copy so it does not collide with the stack already there | — |
| — | Finding what clashes: ports, host paths, container names |
| — | Marking the lines and fields, and the one summary |
| — | The double confirmation before a clashing stack can start |

**Phase E is what removes the danger; this plan is the quality on top.** Routing a reinstall into
StaXX means a new stack gets written instead of Unraid rebuilding a container compose owns — the
hazard is gone at that point, with or without any of this. Nothing here is a safety fix, and nothing
here should be treated as blocking Phase D.

## What was already confirmed in the code, 2026-08-21

Enough to know this is small. Confirm again before writing the plan properly.

- **The compose model already finds every host path with its line number.** `hostPaths(text)` returns
  `{path, line, col, len}` for every volume's host side, which is exactly the shape a per-line
  warning needs. It also deliberately never `parse()`s, so it works on a file mid-edit.
- **The editor already underlines lines and hangs advice off them.** `PLAN_61` built that for images
  whose registry moved (`applyMovedAdvice()` and the surfaces around it). This is another fact to
  graft onto the same machinery, not a new one to build — the same relationship `PLAN_62` has to it.
- **The stack names in use are already one call** — `staxx_import_taken_names()`.
- **Ports have no equivalent yet.** Nothing collects the host ports every stack has bound. That is
  the one genuinely new piece of gathering, and it decides where this work actually sits.

## Open, for the plan to settle

1. **Where the "what is already taken" set is built and how it travels.** Server-side, sent with the
   handoff, seems right — the handoff already passes through the server and already carries a record.
   But the warnings must also work for a stack someone is editing by hand, which has no handoff.
2. **What counts as a clash for a path.** The same host path exactly, or anything underneath an
   appdata folder another stack owns? The second catches far more real corruption and produces more
   false alarms. Decision 4's warning is written for the second.
3. **Whether the start guard belongs to the stack or to the moment.** A stack that clashed when it
   was written still clashes a month later — does the double confirmation appear every time it is
   started, or once, or until the clash is resolved? Asking every time is the safe default and is
   probably right, but it needs saying out loud.
4. **What happens when the clash is resolved.** The marks must clear on their own; nothing should
   need dismissing by hand.
