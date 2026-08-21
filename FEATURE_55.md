# FEATURE — the app moved house, and nothing told you

**Concept only. No code plan here.** Extracted from `PLAN_55.md` (now closed) on 2026-08-21, so the
idea survives without a stale implementation plan attached to it.

**BUILT — 2026-08-21.** Both gates cleared, decision 10 answered, and the code plan is done and
filed at `completed-plans/PLAN_61-catalogue-registry-move.md`. That file records what the plan got
wrong and the two items it deliberately left open; this one is kept only for the concept and the
measurements behind it. Nothing here is outstanding.

Decision 10's answer, for the record: **an imported stack keeps the address written in the file it
came from.** Nothing is rewritten at import time; the drift is reported afterwards, as an orange
advisory rather than an error.

---

## The problem

A Community Applications template is a snapshot taken the day you added the app. Unraid never
revisits it, and neither does anything else. So when a publisher moves their images to a different
registry, every server that added the app before the move keeps pulling from the old address for
ever. The old address usually keeps working, which is exactly why nobody notices.

Two things make it worth caring about:

- Docker Hub now allows an unsigned-in server roughly ten questions an hour. An image still pointed
  at Docker Hub for no reason is spending an allowance the update checking needs.
- An abandoned repository eventually stops being updated — silently, because a stale tag still pulls.

## What the numbers actually say

Measured on the live server, 2026-08-20. This is what makes the idea worth keeping rather than a
theory, and it is also the honest case against rushing it.

| | |
|---|---|
| Distinct images across every container | 70 |
| On Docker Hub | 44 |
| On GitHub's registry (`ghcr.io`) | 16 |
| On linuxserver's registry (`lscr.io`) | 10 |
| Agreeing with their current template | 46 |
| **Pointing at a registry the template has left** | **3** |
| Not Community Applications apps at all | 16 |

The three: `binhex/arch-prowlarr`, `ich777/rustdesk-server-aio` and `sharevb/it-tools`, all of which
their templates now publish at `ghcr.io`.

**Three today.** The argument for building it is not the number — it is that both binhex and ich777
are among the largest publishers still on Docker Hub, and this is them leaving. The number only goes
one way.

## What you would notice

A quiet hint on the row — not a banner, because this is never urgent: *"The template for this app now
publishes at ghcr.io. Switch?"* Pressing it shows the exact one-line change and asks. Nothing moves
on its own, nothing restarts, and a hint you dismiss stays dismissed.

The same hint appears beside the image field in the editor, since that is where someone is already
looking at that line.

## Decisions

Carried over as settled unless marked. These are the answers a code plan must not re-litigate.

| # | Question | Decision |
|---|---|---|
| 1 | Where the truth comes from | The Community Applications index the plugin already builds and keeps locally. No network, no new dependency, refreshed on its own schedule already. |
| 2 | How an app is matched | By repository path with the registry removed — `binhex/arch-prowlarr` — never by app name, which is ambiguous across publishers. |
| 3 | What counts as a move | Same repository path, **different registry host**. Nothing else. |
| 4 | A changed namespace | **Not offered.** `bitr8/agregarr` versus `agregarr/agregarr` is a different account, and telling that apart from a genuinely different app is guesswork. |
| 5 | Proved before offering | The new address must actually answer for **the same tag** in use. An offer that 404s is worse than no offer. |
| 6 | Where it shows | A row hint and an editor hint. Never a page banner. |
| 7 | Applying it | Rewrite the one `image:` line through the compose model, so comments, ordering and formatting survive. **Never** restarts anything by itself. |
| 8 | Dismissal | Remembered per image, the same way a skipped update is, so it stays silent until the template moves again. |
| 9 | Non-CA images | Left entirely alone. Sixteen of this server's images are nobody's template and must never be second-guessed. |
| **10** | New imports — **ANSWERED 2026-08-21** | An imported stack **keeps the address written in the file it came from**. The importer rewrites nothing; the drift is reported afterwards as an orange advisory, not an error, so an imported stack always matches the file it came from. |
| 11 | Whether the old address is also checked | Yes, and worth saying on the hint: if the old repository has stopped being updated while the new one moves, that is the argument for switching, and we can see it. |

## Shape of the work

Four stages, in dependency order. Stage 1 is worth building even if nothing else is — it answers
"is any of this stale?" for any server, and it is the stage that proves the join is trustworthy.

1. **Detection** — join our images to the local index, find host-only differences, prove the tag
   exists at the new address. Testable on the server with no UI at all.
2. **The hints** — the row hint and the editor hint, and dismissal.
3. **Applying it** — the one-line rewrite, with the change shown before it happens.
4. **A one-off report** listing everything that has drifted. Cheap, and useful exactly once per
   server.

## Risks

- **The same name is not the same app.** The whole join rests on repository paths being unique
  enough, which is true across registries for a given publisher and not true in general. Decision 3
  keeps this narrow deliberately: only the host may differ, everything else must match exactly.
- **The new registry may be behind.** A publisher who has just moved may push to one and not yet the
  other. Hence decision 11 — compare both before saying anything, rather than assuming the newer
  address is the fresher one.
- **We are editing someone's file.** This writes to a compose file to change an address, which is
  the one thing this project promises to do carefully. It goes through the compose model or not at
  all, and the change is shown before it is made.
- **Small win today.** Three images on a seventy-image server. The honest case is that the cost is
  low because the index already exists, and that publishers leaving Docker Hub is a trend rather
  than an event.

## Verifying it, when it exists

A new `tests/server/moves.php`, run on the server, pointing its state at `/tmp` so the real file is
never touched. Weighted at the refusals, as ever:

- an image whose path is not in the index is never offered anything;
- a *different namespace* is never offered as a move;
- a new address that does not answer for our tag is never offered;
- a dismissed hint stays dismissed until the template moves somewhere new again;
- a hand-authored file comes back from a switch with its comments, ordering and anchors intact — the
  round-trip rule, tested on a file that has all three.

---

## Before this is planned — both gates cleared, and it is built

1. ~~**Decision 10 needs an answer.**~~ **Answered 2026-08-21** — see the decisions table above.
2. ~~`PLAN_60.md` must have landed first.~~ **Cleared 2026-08-21 — it has landed**
   (`completed-plans/PLAN_60-full-tree-review-fixes.md`). The write path decision 7 depends on was
   corrupting any file indented with four spaces at the time this document was written, so a code
   plan drafted then would have specified a write that no longer exists.

**The contract decision 7 must now be written against**, all four proven by test:

- indentation for a new nested key comes from the parent's existing children, not a fixed two-space
  step;
- a failed structural write rolls back, leaving the file byte-identical, rather than stranding a
  half-built line;
- a value containing a backslash is emitted single-quoted, and one containing a line break is
  refused outright;
- **a file the parser could only read part of refuses any write that adds or removes structure.**
  This one is new since the original plan and matters most here: a registry switch rewrites one
  `image:` line in place, which is *not* a structural write and so is still allowed — but the code
  plan must say that explicitly rather than leave it to be discovered.

Everything else here only *reads*, so it is unaffected and does not need revisiting.
