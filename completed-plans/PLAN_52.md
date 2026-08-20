# PLAN_52 — a breadcrumb path, and a handle between the shell and the files

**Status: BUILT 2026-08-20.** Two small additions to the Manage tab's right-hand column, both asked for
after living with PLAN_44 for a day.

## Context

**The path is not navigation.** The file pane's toolbar shows the current folder as plain text next
to an `Up` button, so climbing out of `/etc/periodic/daily` is three clicks with nothing on screen
saying where any of them land. The path already holds every parent — it just is not clickable.

**The split is fixed.** The shell and the file listing share the right-hand column half and half.
Sometimes you want the console and sometimes the listing; PLAN_44 deliberately shipped "a click, not
a drag" (collapse a pane by clicking its heading), which gives you all-or-nothing but nothing in
between. Adrian's request is the in-between.

Decisions taken: `Up` stays, because it does not move as the path grows; the handle's position is
remembered while the page lives and forgotten on a reload, so nothing is written to browser storage.

## What you would notice

The folder path becomes a row of clickable parts — `/ etc / periodic / daily` — where every part but
the last takes you there. The last is where you are, so it is not a link. `Up` keeps its place to
the left of it.

Between the Shell pane and the Files pane there is a thin handle. Drag it up or down to give one
pane the other's room. Double-click it to go back to even. It also takes the arrow keys once
focused, so it works without a mouse.

Move to another container, or close and reopen the editor, and the split is where you left it. Reload
the page and it is even again.

## How it works

### The breadcrumb

The path element stops being a span of text and becomes a container the listing refills each time it
lands somewhere new: a `/` for the root, then one button per segment separated by a thin `/`, with
the final segment written as plain text rather than a button. An empty path — the moment before the
first listing arrives — still reads as the single `…` it does today.

Each button navigates through the *existing* `navigateTo()`, built from the segments to its left, so
there is no second idea of what a path is. `parentPath()` and `joinPath()` are untouched.

### The handle

A `div` with `role="separator"` between the two panes in the right-hand column. The split itself is
**one custom property on the column**, not inline styles on the panes:

```
.staxx-manage-right      { --sm-split: 1; }
.staxx-manage-pane--shell { flex: var(--sm-split) 1 0; }
.staxx-manage-pane--files { flex: calc(2 - var(--sm-split)) 1 0; }
```

That matters for one reason: `.staxx-manage-pane--collapsed` sets `flex: 0 0 auto` and is declared
later at equal specificity, so collapsing a pane still wins. Written as inline styles on the panes
instead, a dragged split would outrank the collapse and a collapsed pane would keep its height.

Dragging is pointer events, with the moving and the letting go listened for on the window for the
length of the drag, so a drag that leaves the handle still tracks. Position converts to a ratio of the column's own height, so the split survives a window resize
rather than pinning one pane to a pixel count. Each pane is held to a floor of 6rem so neither can
be dragged away to nothing. A drag is ignored outright while either pane is collapsed — the handle
would appear to do nothing, since the collapse rule wins by design.

The ratio lives in a variable at module scope, not on the instance, which is exactly what
"remembered until the page reloads" means: a new editor, a new stack, even a new instance reads the
same number, and a reload starts even.

`Home` resets, the arrow keys nudge, and `aria-valuenow` carries the shell's share as a percentage.

**Narrow screens.** The right-hand column becomes `display: contents` under 45rem and the panes show
one at a time, so there is no split to adjust — the handle is hidden there.

## Where the code changes

- `javascript/manage.js` — the toolbar's path element gains a `renderCrumbs()`; `build()` puts a
  handle between the two panes and wires it. Nothing else moves.
- `sheets/manage.css` — crumb styling (a button rule, so `.staxx-scaffold`-prefixed per the button
  trap at the head of the file); the handle; the two pane flex rules rewritten in terms of
  `--sm-split`; `display: none` for the handle in the narrow media query. The comment on
  `.staxx-manage-pane--collapsed` still says PLAN_44's "a click, not a drag" — it is now a click
  *and* a drag, and says so.

No PHP, no endpoint, no test-suite change: this is presentation over data the page already holds.

## Verification

Locally: `node --check` on both browser files, `tests/js_undeclared.js` — the one that matters here,
since `manage.js` is strict mode and a mistyped name in a drag handler would only throw at run time.

On the server, in the file pane of a running container:

- Walk into `/etc/periodic/daily`, then jump straight back to `/etc` from the breadcrumb.
- The last part is not clickable and does not look it.
- A path long enough to wrap still reads, and the toolbar's other buttons stay reachable.
- Drag the handle both ways; the pane that gives up room keeps its heading and its scroll position.
- Double-click resets. Arrow keys move it. Neither pane can be dragged to nothing.
- Collapse the shell by its heading, try to drag, and confirm the collapse holds.
- Switch container and reopen the editor: the split is where it was. Reload: even again.
- Narrow the window past the collapse point and confirm no stray handle appears.

## What it took, in the end

Two things only the real browser could have shown, both in the drag:

- **Pointer capture was the wrong tool.** It looked like the tidy answer — one element, capture the
  pointer, release it at the end — but the release arrived as a retargeted event the handle itself
  never saw, so the "do not select text" flag it sets on the page stayed on after the mouse came up.
  Moving and releasing are now listened for on the window for the length of the drag and removed at
  the end of it, which cannot get stuck that way.
- **Preventing the default on `pointerdown` does not stop a text selection.** The mouse event that
  follows it starts one regardless, so the first sweep down the page came up highlighted. Prevented
  on the mouse event as well, a drag now selects nothing: measured at zero characters.

Proved on the server against a running container: a real mouse drag moved the shell from 346px to
504px and the files pane from 346px to 188px, with nothing selected and nothing left stuck; the
floor holds both panes at 61px; a collapsed pane refuses the drag; double-click and `Home` reset;
the arrow keys nudge; and the split came back at 1.46 in a *different* stack's editor, which is what
"remembered until the page reloads" was meant to mean.

The grip is a short centred bar, not the full-width hairline it started as — drawn edge to edge in a
pane's own border colour it read as one more pane edge, which is exactly the thing nobody thinks to
drag.

## What this deliberately does not do

- **No handle between the log and the right-hand column.** Not asked for; the same mechanism would
  extend to it later if the vertical one proves itself.
- **Nothing written to browser storage.** Chosen deliberately over surviving a reload.
- **No editable path box.** Typing a path to jump to it is a different feature with its own
  validation; the breadcrumb only offers folders you have already been through.
