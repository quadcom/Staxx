# PLAN 65 — warning about a stack that clashes with one already here

**Status: complete 2026-08-21. All four phases built, verified and deployed.**

Phase A collects what is taken from Docker in one call — 41 published ports and 114 bind-mount paths
across the 72 containers on the test box, each naming the container that holds it. It rides the `rows`
refresh, not `state`. Measured cost: 80ms, and cached after the first call in a request.

**A bug the fixtures could never have caught, found by running it against the real machine:** Docker
reports the same published port once per bound address, so every port was counted twice — 82 facts
where there are 41. Deduped per container on port and protocol, and checked against what
`docker ps -a` itself reports.

---

## Where this came from

Adrian, on being told that "Reinstall From Previous Apps" would have to be blocked for an app StaXX
already runs: *"what if we changed the reinstall function to a StaXX handled copy stack with warnings
on lines that contain duplicated values like paths, ports, volumes etc?"*

That turned a dead end into the thing the person actually pressed the button for. **`PLAN_63` Phase E
built the routing** — a reinstall now writes a second stack rather than letting Unraid rebuild a
container compose already owns — so the hazard is already gone. **This plan is the quality on top of a
route that is already safe.** Nothing here is a safety fix for that, which matters when deciding what
to cut if it gets long.

## Decisions taken

Adrian's answers, 2026-08-21.

1. **A reinstall makes a second copy, as a new stack.** The same thing Unraid does — a second
   container with a suffixed name. The running stack is never touched. **Built already**, in
   `PLAN_63` Phase E.
2. **Clash warnings apply to every caught install, not only a reinstall.** A brand new app whose
   default port is already taken is the common case, not the rare one, and Unraid's own Apps page
   already warns about it — so anything less is a step backwards from stock.
3. **A clash is shown on the line and listed once.** The offending line marked in the file view, the
   field marked in the form, plus one summary saying what clashed.
4. **A clashing stack cannot be started without a deliberate double confirmation** — a checkbox and a
   confirm button, not a single OK. It must warn about **two containers writing the same appdata,
   which can corrupt it**, and about **the existing stack having to be stopped**.

Decision 4 is Adrian going further than was proposed, and it is the important one: the difference
between a warning someone clicks past and one that makes them stop. **Do not water it down to a
single confirm on the grounds that it is a lot of clicks. It is meant to be.**

## What was confirmed in the code

- **Host paths are already extractable with their line numbers.** `hostPaths(text)` in
  `compose-model.js` returns `{path, line, col, len}` for every volume's host side, and deliberately
  never `parse()`s, so it works on a file mid-edit. That is exactly the shape a per-line mark needs.
- **Ports have no equivalent.** Nothing extracts a service's published host ports with positions.
  **That is the one genuinely new piece of extraction** — a `hostPorts(text)` sibling, built the same
  way and for the same reasons.
- **The editor already marks lines and hangs advice off them.** `PLAN_61` built that for images whose
  registry moved (`applyMovedAdvice()`, `movedSpots`, the row border and the underline). This is
  another fact to graft onto the same machinery, the same relationship `PLAN_62` has to it — **not a
  new marking mechanism.**
- **Stack names in use are one call** — `staxx_import_taken_names()`.
- **Container facts are already read from Docker** — `staxx_import_all_containers()`, and
  `staxx_container_net()` already resolves each container's addresses and port list.

## Where "what is already taken" comes from

**From Docker, not by re-reading every stack's compose file.** The decision matters enough to record
the reasoning, because the obvious answer is the wrong one:

- Reading every stack file means running the extractor over ~40 files on every check. The extractor is
  JavaScript, and this answer is needed server-side, so it would mean **a second implementation in
  PHP** — a parser in two languages, disagreeing eventually. That is the trap.
- Docker already knows every container's published host ports and bind-mount host paths, for stopped
  containers as well as running ones, in one call. It is also authoritative about what is *actually*
  bound, which is what a clash means.
- **It catches containers StaXX does not manage.** A freshly installed app most often clashes with one
  of Unraid's own containers, and no amount of reading stack files would find those.

**The gap, stated honestly:** a stack that exists but has never been started has no container, so
Docker cannot see it. Its ports and paths are invisible to this check. That is a real hole and it is
accepted — the alternative is the two-parsers trap. Say so in the help text rather than implying the
check is exhaustive.

## What counts as a clash

| Kind | Rule |
|---|---|
| Host port | The same host port, same protocol. An exact fact; no judgement. |
| Host path | **Open — see below.** |
| Container name | Already handled: the save refuses a stack name that exists, and the converter suffixes a copy. |

**The path question, which decides how useful this is.** The same host path exactly, or anything
*underneath* a folder another container already owns? The second catches far more real corruption —
two apps sharing an appdata parent is the case that eats data — and produces more false alarms, since
plenty of containers legitimately share `/mnt/user/media`. Decision 4's warning is written for the
second reading.

**Recommendation:** treat an exact match as a clash always, and a shared *appdata* parent as a clash
too, because appdata is where a container keeps its own private state and two writers there is the
corruption case. Do not treat a shared media or downloads path as a clash — sharing those is the
normal, correct thing to do, and warning about it would train people to ignore the warning. That
distinction needs the appdata root, which `staxx_import_write()` and the converter already know.

## The double confirmation

Only in front of **starting** a stack that has a live clash — not the save, not the edit.

- A checkbox and then a confirm button. Two deliberate acts.
- It names the specific clash: which port, or which folder, and which container already has it.
- It says the two things decision 4 requires: **two containers writing the same folder can corrupt
  what is in it**, and **the container that has the port now will have to be stopped**.
- Neither sentence is hedged, and neither is a generic "are you sure".
- **Never remembered.** A stack that clashed last week still clashes today; the confirmation is about
  the state of the machine at the moment of starting, not a preference someone once expressed. This is
  the same reasoning `PLAN_63` decision 2 used for never remembering a decline.

## Files touched

| File | Change |
|---|---|
| `javascript/compose-model.js` | `hostPorts(text)`, the sibling of `hostPaths()` |
| `javascript/stacks.js` | the clash facts on the fields, the marks, the one summary, the start confirmation |
| `include/action.php` | one action returning what is taken, or the facts folded into an existing refresh |
| `include/Import.php` or `include/Defines.php` | collecting taken ports and paths from Docker |
| `sheets/staxx.css` | only if `PLAN_61`'s existing marking styles genuinely do not stretch |

## Work items

### Phase A — knowing what is taken
1. Collect every container's published host ports and bind-mount host paths from Docker, running or
   not, in one call. Reuse whatever `staxx_import_all_containers()` already asks for rather than a
   second enumeration.
2. Decide where it rides to the browser. Prefer folding it into a refresh that already happens over a
   new action — but **not** into the cheap `state` refresh, whose whole point is that it is one
   `compose ls`. `PLAN_63` Phase F made this mistake available and avoided it; do the same.

### Phase B — finding the clash
3. `hostPorts(text)` in the model, mirroring `hostPaths()`: positions, no `parse()`, never throws.
   **Done 2026-08-21.** Verified over the corpus and by hand for the long form, the short form, a
   protocol suffix, an interface-qualified port, an IPv6 host address, a quoted and an unquoted port,
   a container-only port, a long form with no `published:`, and a port from a variable. The last three
   correctly yield nothing.
4. Compare, and attach the facts to the fields the same way `movedAdvice` is attached.

   **A range is returned whole, as `"8000-8010"`, not expanded** — which is right for the extractor
   and a trap for the comparison. Comparing that against a taken port of `8005` as strings finds no
   clash, and there plainly is one. So the comparison has to understand a range on **either** side:
   a range in the file against a single taken port, a single port in the file against a taken range,
   and two ranges that overlap. If that turns out to be more than a few lines, **say so rather than
   comparing strings and reporting a clean result** — a check that silently cannot see a whole class
   of clash is worse than one that admits it.

### Phase C — saying it
5. The marks and the one summary, on `PLAN_61`'s existing machinery.
6. It must clear itself when the clash is resolved. Nothing should need dismissing by hand.

### Phase D — the start guard
7. The double confirmation, per the section above.

## Tests

- `node tests/js_undeclared.js` and `node --check`. Both, always.
- `node tests/yaml_roundtrip.js` — 1482 unchanged. **This plan should not write to a compose file at
  all**; if that number moves, something is writing that should only be reading.
- A node probe over the fixture corpus for `hostPorts()`: long form, short form, a range, a
  protocol suffix, an interface-qualified port, a quoted port, a port from a variable (which cannot be
  resolved and must be left out rather than guessed).
- On the server: the taken set against what `docker ps -a` actually reports, and the appdata-parent
  rule against his real containers — **read-only**, and expect false positives to show up here rather
  than in the fixtures.

## Risks

| Risk | Standing |
|---|---|
| The warning cries wolf | The one that kills the feature. A shared media folder is normal and must not warn. This is why the path rule is a decision and not an implementation detail. |
| A stack never started is invisible to the check | Real, accepted, and the price of not writing a second parser in PHP. Must be said in the help text, not implied away. |
| The double confirmation gets softened later | Explicitly forbidden by decision 4. It is meant to be two acts. |
| A port from a variable reads as no port | Correct behaviour, and worth a note beside the summary: this cannot see through a variable, so it may be quiet where it should not be. |
| It becomes a marking mechanism of its own | Use `PLAN_61`'s. A third way to underline a line in this file is a maintenance problem, not a feature. |

## Sequencing

`PLAN_66`'s write guard is in place, and this plan writes nothing, so nothing here needs it. It shares
`javascript/stacks.js` and `javascript/compose-model.js` with `PLAN_62` and `PLAN_67`, so it is
**sequential** with either rather than parallel.

## What was actually measured

**Ports: nothing false, out of 41 taken on the test box.** Ranges are handled on either side — a range
against a single port, a port against a range, two overlapping ranges — which turned out to be two
small functions rather than the "more than a few lines" the work item allowed for.

**Paths took three passes to get right, and only the real machine showed it.**

1. First reading of the rule was "the same top-level appdata folder", which produced **9 false
   positives** — every one of them a multi-container app whose own sidecar reads its sibling's folder.
2. Corrected to real containment — one path is the other, or a prefix of it on a `/` boundary — which
   brought it to **4**.
3. **Then the collection was narrowed to writable mounts only**, which brought it to **3**. A
   read-only mount cannot corrupt what it reads, so warning about one is pure noise; that one change
   also dropped 10 paths from the taken set. The false positive it removed was a log viewer mounted
   read-only beside the proxy whose logs it reads — exactly the shape the rule was crying wolf over.

**The three that remain are not really false at all**: `Tautulli`/`plex`, and TubeArchivist beside its
own Elasticsearch and Redis. Each is two containers with genuine write access to one folder, which is
the risk this check exists to name. And in StaXX terms they would be **one stack**, not two — a
multi-container app converts to a single stack — and the comparison already excludes a stack's own
project. So the practical rate on a real install is lower again than three.

**The lesson worth keeping:** none of those three passes could have been discovered from the fixture
corpus. The rule was wrong twice in ways that only 72 real containers revealed.

## Where the guard sits

Every per-stack and per-service start funnels through one function, so the confirmation sits in front
of that; "Start everything" on a folder posts its own action and needed its own. Restart is
deliberately **not** guarded — every caller only sends it to something already running, so it cannot
introduce a fresh clash.

