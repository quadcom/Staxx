# PLAN_40 — dragging a port into a different place in the list

**Status: COMPLETE 2026-08-18.** Built, deployed and proved in the browser on a fixture whose ports
are written the long way — two four-line entries, each with a trailing comment, one of them
deliberately on the "wrong" line. Both moved as whole blocks with every comment attached, by keyboard
and by drag, and the file on disk was never written.

One bug found by dragging that no test would have caught: the drop read its position back off the
line the last `dragover` had drawn, and a drag that crosses a row in a single jump fires no `dragover`
over that row at all. The fallback then read as "insert before", which for a downward move puts the
row back where it started — a drag that silently did nothing. The drop now measures the pointer
against the row it landed on rather than trusting a leftover mark.

Follows PLAN_39, which established the rule this makes usable:
**the first port in a service's list is the one the WebUI button opens.**

## What it is

A grip on each port row. Drag it up or down and the ports reorder — in the compose file, not just on
screen — so the WebUI chip follows to whichever port is now first.

Kept out of PLAN_39 on purpose: everything else there is readable and importable without it, and this
is the only part that **rewrites lines in someone's file**.

## The risk, stated plainly

Moving an entry means lifting lines out of the file and putting them back somewhere else. Two ways
that goes wrong, both seen before in this codebase:

- **A comment gets orphaned or deleted.** When PLAN_34 phase 5 rewrote a networks block, the obvious
  implementation silently deleted the note the user had typed, and the nearest precedent in the
  codebase kept only the *last* comment of a block. This has both kinds: a note on the entry's own
  line, and a standalone comment line above it.
- **A block gets half-moved.** A port written the long way spans four or five lines. Move the first
  and leave the rest and the file is ruined.

The parser already answers both. Every sequence item carries `leadStart` (walked back over the
comment lines directly above it) and `end` — so `leadStart..end` **is** the entry, comments and all,
however many lines it occupies. Move that span and nothing else and both problems disappear. Blank
lines sit outside any item's span and so stay where they are, which keeps the file's own spacing
pattern rather than dragging gaps around.

## What gets built

### 1. `moveItem(doc, form, service, listKey, from, to)` — `compose-model.js`

Beside `addItem` and `removeItem`, which it resembles.

- Resolve the service's `listKey` pair; it must be a real sequence.
- **Refuse, changing nothing, when the list is not safe to rewrite**: a flow sequence (`ports: [a, b]`),
  anything the parser sealed, an anchor or an alias anywhere in it. Same posture as
  `promoteNetworksList` — refuse the whole operation rather than half-do it — returning a plain-English
  reason for the caller to show.
- Otherwise lift `lines[from.leadStart .. from.end]` and re-insert before the target entry's
  `leadStart`, adjusting for the removal when moving downwards.
- Written generically because it is no harder than writing it for ports, but **only ports get the
  handle** — no other list has an order that means anything.

### 2. The grip — `stacks.js` and `staxx.css`

A new narrow track at the **start** of the ports row, holding a grip. The captions row gains an empty
cell to match, and the WebUI chip keeps the last track it was already pinned to.

**The row is not draggable by default.** Making a row containing text inputs draggable breaks
selecting text inside them. The grip sets `draggable` on the row on pointer-down and clears it on
drag end — the standard way round that, and worth a comment saying so, because it looks redundant.

**The grip is also a button.** Focus it and press up or down and the row moves. That is a few lines
on top of the drag, and it is the difference between a feature everyone can use and one that needs a
mouse.

A drop indicator between rows while dragging, and nothing else moving — no live reordering under the
pointer, which fights with the fact that a real edit only happens on drop.

### 3. Committing the move

On drop: `flushPending()`, `pushUndo('moving that port')`, call `moveItem`, and on refusal pop the undo
and say why — exactly the shape the promote control and the declare-network button already use. Then
`structuralEdit`, which re-serialises, reparses and rebuilds the form, so the chip lands on the new
first port with no extra work.

A move to where it already is does nothing and pushes no undo.

## Tests

`tests/yaml_roundtrip.js`, following the newest sections' conventions.

- Two short-form ports swap; the file is byte-identical to the same file written in the other order.
- **Each entry's own trailing comment goes with it.** This is the one that would have been silently
  wrong.
- **A standalone comment line above an entry goes with it**, and one above the *list key* does not.
- A long-form entry (four lines) moves as a block, and its inner lines keep their order.
- A blank line between entries stays where it was rather than travelling.
- Moving the last entry to the front, and the first to the back, both work — the two off-by-one cases.
- Refusals leave the file **byte-identical**: a flow sequence, an anchor, an alias, a sealed item.
- After a move, `buildForm` reports the ports in the new order and the WebUI chip's field is the one
  now first.
- The whole file still round-trips unsealed.

## Not in this plan

- **No dragging between services or groups.** A port belongs to its service; moving it elsewhere is a
  different operation with different rules.
- **No reordering of volumes, variables or anything else.** Their order carries no meaning, so a
  handle on them would be motion without purpose.
- **No multi-select drag.**

## Files

| File | Change |
|---|---|
| `javascript/compose-model.js` | `moveItem`, exported |
| `javascript/stacks.js` | the grip, the drag and keyboard handlers, the commit |
| `sheets/staxx.css` | the grip, the drop indicator, the extra track |
| `tests/yaml_roundtrip.js` | the section above |
