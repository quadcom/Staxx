# PLAN_54 — the control buttons move up onto the status row

**Status: BUILT 2026-08-20.**

## Context

The five control buttons (Stop, Start, Restart, Recreate, Update) sit in a 9rem column of their own
between the log pane and the right-hand pair, stacked vertically, with the This container / Whole
stack switch above them. Two problems with that:

- **The status row collapses.** The strip above the panes holds only the container's status chip, so
  selecting All empties it and the whole row disappears — everything below jumps up. Since PLAN_53
  moved the three job buttons out of that strip into the pane headings, there is nothing left to hold
  it open.
- **The buttons are a column in the middle of the layout**, taking width from the panes for five
  short words, and the switch beside them is a two-button segmented control that spells out both
  modes at once.

Adrian's instruction: put the buttons on the status row as a centred horizontal row, replace the
segmented switch with a small dot switch like the Autostart one, move the wording to the right of the
buttons in small text, and mark whole-stack mode with a thin orange border round the control.

Clarified with him: **the switch goes to the left of the buttons, the wording to the right of them,
and the buttons stay centred.** The label names the mode the switch is in now — one label that
changes, not both at once — the same reasoning the Autostart menu item already uses: a switch showing
its own position beats a label that has to be read twice.

## What you would notice

The strip above the panes becomes a single row that is always there:

```
Up 8 hours (healthy) · 0 restarts        ( ⬤— Stop Start Restart Recreate Update )  Whole stack
```

The switch and the five buttons are centred as one group. The wording to their right says which mode
you are in. Flip the switch and the group gains a thin orange rounded border, so whole-stack mode is
visible at a glance rather than something to check. On All the switch is locked to Whole stack,
because that is the only honest reading there, and the border is on.

The panes gain the width the buttons column used to take. The row no longer collapses when you switch
to All — the buttons hold it open, so nothing below moves.

## How it works

### The row

`.staxx-manage-above` becomes a three-track grid, `1fr auto 1fr`:

- track 1 — the status chip, left-aligned, hidden as it is today for All
- track 2 — the control group: the switch, then the five buttons
- track 3 — the wording, left-aligned so it sits immediately right of the group

An `auto` middle track between two equal `1fr`s is what centres the group exactly, regardless of how
long the status text runs — which is the point, since "Up 8 hours (healthy) · 2 restarts · health:
healthy" is a good deal wider than "stopped". The wording sits in its own track rather than inside
the group, so the orange border can wrap the group without enclosing it.

`.staxx-manage-buttons` — the 9rem middle column — goes, and with it its own narrow-screen rules.
`build()` stops making that column, so `.staxx-manage-body` becomes log | right-hand pair and both
grow into the space.

### The switch

The Autostart menu item uses Font Awesome's own `fa-toggle-on` / `fa-toggle-off`, which Unraid
already ships — so this reuses the identical glyph rather than building a switch out of CSS. One
button carrying that icon, with `aria-pressed` and a title, and the label in track 3 changing with
it. `scopeBtn()` and the pair of `.staxx-manage-scope-btn` rules are replaced.

`state.scope` and `scope()` keep their present meaning exactly — the switch writes the same two
values the segmented control wrote, and `effectiveScopeService()` is untouched. This is a change of
control, not of behaviour.

### The border

`border: 1px solid transparent` on the group at all times, turning `var(--sm-accent)` when the
effective scope is the whole stack. Declaring the border in both states rather than adding one is
what stops the buttons shifting a pixel as it appears.

The condition is the *effective* scope — `isAll || state.scope === 'stack'` — the same expression
`renderButtons()` already uses to decide what the buttons would act on, so the border cannot disagree
with what pressing one would do.

### Sizes

The buttons take `font-size: 1.1rem`, which is what `.staxx-pill` in `staxx.css` sets — the status
chip on the same row — so the row reads as one row. The wording is smaller and muted.

### Narrow screens

Under 45rem the row wraps instead of holding three tracks: the grid becomes a single column so the
status, the group and the wording stack, centred. The old `.staxx-manage-buttons` and
`.staxx-manage-verbs` overrides in that media query are removed with the column they described.

## Where the code changes

- `javascript/manage.js` — `buildAbove()` builds the row's three tracks and calls `buildButtons()`
  into it; `buildButtons()` makes the switch and the verb row instead of a column; `scopeBtn()` is
  replaced by the single toggle; `renderButtons()` sets the toggle's glyph, label, `aria-pressed` and
  disabled state, and toggles the border class. `build()` stops creating the middle column.
- `sheets/manage.css` — the above-row grid; the control group and its border; the verbs as a
  horizontal row at the chip's own size; the toggle and the label; the two scope-button rules and the
  `.staxx-manage-buttons` block removed, in both the main sheet and the narrow media query.

No PHP, no endpoint, no test change: nothing about what a button does is altered, only where it sits
and what tells you its scope.

## Verification

Locally: `node --check` on both browser files, and `tests/js_undeclared.js` — the one that matters,
since a mistyped name in `renderButtons()` would only throw when the tab is opened.

On the server, in the Manage tab of a running multi-container stack and a single-container one:

- The row is present and the same height for All and for a selected container — measured, not
  eyeballed, since "it stays put" is the whole point. Nothing below it moves as you switch.
- The buttons are centred: the group's own centre matches the row's centre, with a short status
  ("stopped") and a long one ("Up 8 hours (healthy) · 2 restarts · health: healthy").
- The switch flips, the wording follows it, and the orange border appears only in whole-stack mode.
- On All the switch is locked on Whole stack and the border is on.
- The buttons and the status chip measure the same font size.
- A container that was never created still greys out Stop, Restart and Recreate for "this container"
  and offers them for the whole stack — the disabling rule PLAN_44 got wrong once already.
- Pressing one still runs against the right target: a stack-scope Restart and a container-scope
  Restart, each confirmed by what the row reports. **Run these against a stopped scratch stack in
  DEV-TESTING, never a live service.**
- Narrow the window under the collapse point: the row stacks rather than overflowing.

## What it took, and what was proved

**One fault, found by measuring rather than looking.** With the controls placed in the middle track of
a three-track grid, they sat dead-centre — until All was selected. Then they jumped to 399px in a row
centred on 794px. A `display: none` grid item is not *placed*, so hiding the status for the
whole-stack view left auto-placement to shuffle everything up a track. Each item now names its own
column, and the group measures exactly on centre in every state.

Measured on the server, on `Bambuddy` (running, two mounts) and `01-alpine-minimal` (a stopped
scratch stack whose container was never created):

- **The row holds its height.** 30px and the panes starting at 212px, identical for All and for a
  selected container — so nothing below moves. That was the point of the change.
- **Dead centre**, 0px off, with no status at all, with a short one, and with "Up 9 hours (healthy) ·
  0 restarts · health: healthy".
- **The switch** flips both ways, the caption follows it, and the orange border appears only in
  whole-stack mode. On All it is locked on and the border is on.
- **Same size as the chip:** buttons and status pill both measure 11px.
- **The disabling rule PLAN_44 got wrong once is still right.** A container that was never created
  greys Stop, Restart and Recreate for "this container", and offers all five the moment the switch
  says whole stack — because a stack-wide stop is perfectly possible.
- **A press still reaches the right target.** Stop at stack scope on the stopped scratch stack wrote
  `compose -f compose.yaml down` with no service name, exit 0. Chosen deliberately as the one verb
  that proves the plumbing without starting or pulling anything on a production box.
- **Narrow screens** stack the three items, centred, with no sideways overflow. Tested by applying
  the media query's own rules directly, because the browser-automation window would not resize.

## What this deliberately does not do

- **No change to what any button does**, or to how scope is decided. `state.scope`, `scope()` and
  `effectiveScopeService()` keep their current meaning.
- **No CSS-built switch.** The Autostart glyph already exists and is the thing being matched.
- **No new orange for other states.** The border marks whole-stack mode and nothing else.
