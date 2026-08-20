# PLAN_55 — the app moved house, and nothing told you

**Status: drafted 2026-08-20, awaiting approval.** Nothing here is built. Every decision below is a
*recommendation* rather than a settled answer — the ones that need Adrian's word are marked, and the
plan should not start until they have it.

## Context

A Community Applications template is a snapshot taken the day you added the app. Unraid never
revisits it, and neither does anything else — so when a publisher moves their images to a different
registry, every server that added the app before the move keeps pulling from the old address for
ever. The old address usually keeps working, which is exactly why nobody notices.

Two things make it worth caring about. Docker Hub now allows an unsigned-in server roughly ten
questions an hour, so an image still pointed at Docker Hub for no reason is spending an allowance
that PLAN_45's update checking needs. And an abandoned repository eventually stops being updated —
silently, since a stale tag still pulls.

**Measured on the live server, 2026-08-20**, which is what makes this worth doing at all rather than
a theory:

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
their templates now publish at `ghcr.io`. Both binhex and ich777 have moved, which is the pattern
worth watching — those two are among the largest publishers still on Docker Hub, and this is them
leaving.

Three today. The number only goes one way.

## What you would notice

A quiet hint on the row — not a banner, because this is never urgent: *"The template for this app now
publishes at ghcr.io. Switch?"* Pressing it shows the exact one-line change to the compose file and
asks. Nothing moves on its own, nothing restarts, and a hint you dismiss stays dismissed.

The same hint appears beside the image field in the editor, since that is where someone is already
looking at that line.

## Decisions

| # | Question | Recommendation |
|---|---|---|
| 1 | Where the truth comes from | The Community Applications index the plugin already builds and keeps locally. No network, no new dependency, and it is refreshed on its own schedule already. |
| 2 | How an app is matched | By repository path with the registry removed — `binhex/arch-prowlarr` — never by app name, which is ambiguous across publishers. |
| 3 | What counts as a move | Same repository path, **different registry host**. Nothing else. |
| 4 | A changed namespace | **Not offered.** `bitr8/agregarr` versus `agregarr/agregarr` is a different account, and telling those apart from a genuinely different app is guesswork. Informational at most; see the risks. |
| 5 | Proved before offering | The new address must actually answer for **the same tag** we use, checked with PLAN_45's existing registry lookup. An offer that 404s is worse than no offer. |
| 6 | Where it shows | A row hint and an editor hint. Never a page banner. |
| 7 | Applying it | Rewrite the one `image:` line through the compose model, so comments, ordering and formatting survive — then it is an ordinary update. **Never** restarts anything by itself. |
| 8 | Dismissal | Remembered per image, the same way a skipped update is, so it is silent until the template moves again. |
| 9 | Non-CA images | Left entirely alone. Sixteen of this server's images are nobody's template and must never be second-guessed. |
| 10 | New imports (needs Adrian's word) | The importer could take the template's *current* address instead of the one written into the file being imported — fixing this at the door rather than reporting it later. Sensible, but it means an imported stack does not match the file it came from. |
| 11 | Whether the old address is also checked | Yes, and worth saying on the hint: if the old repository has stopped being updated while the new one moves, that is the argument for switching, and we can see it. |

## Phases

| # | Phase | Lands |
|---|---|---|
| 1 | Detection — join our images to the local index, host-only differences, prove the tag exists at the new address | Testable on the server with no UI, exactly as PLAN_45's phase 1 was |
| 2 | The row hint and the editor hint, and dismissal | |
| 3 | Applying it — the one-line rewrite through the compose model, with the change shown before it happens | |
| 4 | A one-off report listing everything that has drifted | Cheap, and useful exactly once per server |

Phase 1 is worth building even if nothing else is: it answers "is any of this stale?" for any server,
and it is the phase that proves the join is trustworthy.

## Verifying it

No PHP, Docker or browser on the dev machine, so a new `tests/server/moves.php` run on the server,
pointing its state at `/tmp` so the real file is never touched. Weighted at the refusals, as ever:

- an image whose path is not in the index is never offered anything;
- a *different namespace* is never offered as a move;
- a new address that does not answer for our tag is never offered;
- a dismissed hint stays dismissed until the template moves somewhere new again;
- a stack whose compose file was hand-authored comes back from a switch with its comments, ordering
  and anchors intact — the round-trip rule, tested on a file with all three.

## Risks

- **The same name is not the same app.** The whole join rests on repository paths being unique enough,
  which is true across registries for a given publisher and not true in general. Decision 3 keeps this
  narrow deliberately: only the host may differ, everything else must match exactly.
- **The new registry may be behind.** A publisher who has just moved may push to one and not yet the
  other. Hence decision 11 — compare both before saying anything, rather than assuming the newer
  address is the fresher one.
- **We are editing someone's file.** This writes to a compose file to change an address, which is the
  one thing this project promises to do carefully. It goes through the compose model or not at all,
  and the change is shown before it is made.
- **Small win today.** Three images on a 70-image server. The honest case for building it is that the
  cost is low because the index already exists, and that publishers leaving Docker Hub is a trend
  rather than an event.
