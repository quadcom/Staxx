# PLAN_14 — The health check's command field stops writing to the file

**Status: fixed, deployed and confirmed in the browser, 2026-08-14.** Reported by Adrian while
checking the editor colours. 792 tests passing, including a new section N covering this.

**The hypothesis below was wrong**, and is left in place because being wrong about it is the useful
part of the record. It guessed the dropdown needed to go through `structuralEdit()` because
switching to *No check* changes which fields exist. It does not — `harvestHealthTest()` emits the
mode field and the command field whatever the mode is, so the field count never changes and no
redraw was ever needed.

## What it actually was

`healthcheck.test` is a single line in the file shown as **two** boxes, so `setPart()` has to
combine them back into one write. To do that it read the *other* box's value off the freshly rebuilt
model — the real state of the file — rather than off the on-screen box, which by design is not
redrawn mid-edit.

1. Switching to **No check** wrote `test: ["NONE"]`, which has no room for a command, so the command
   left the file. The box on screen still showed it. Correct so far, if surprising.
2. Switching **back** re-read the command from the model, which now truthfully said *blank*. A
   pre-existing guard — "blank command in shell or cmd mode, write nothing" — then fired. That guard
   exists for someone *clearing the box*, not for a mode switch, so the switch silently did nothing
   and the file stayed on `NONE`.
3. From then on the model's mode read as `none`, so every keystroke in the command box was funnelled
   through a write that discards the command for `NONE`. Every edit was dropped, silently, for ever.

## The fix

- A **mode** change now always writes. A deliberate choice by the user must never be a silent no-op.
- The blank-writes-nothing leniency now applies only to a **command** edit, which is what it was
  written for.
- The command displaced by `NONE` is remembered in memory on the parsed document and handed straight
  back when the mode returns. The `x-unraid.sections` stash was considered and rejected: it lifts
  whole YAML blocks by path, and this is one argv element inside a line, not a key.
- `commit()` no longer returns quietly when a row has lost its place in the file — it says so. That
  is the general fix, and it is what turns this class of bug from invisible into obvious.

**Known boundary.** The remembered command lives in memory, not in the file. Switch to *No check*,
then type in the Compose text pane (which reparses from scratch), then switch back, and it will not
return — though by then it is genuinely gone from the file, so there is nothing to give back.

## What happens

In a service's **Health check** group:

1. Change **How the check runs** to **No check**. The **The check itself** value changes in the
   compose file, but the form box still shows the old text — the two are now saying different things.
2. Change **How the check runs** back to **Run a shell line**. The compose file is **not** restored
   to the value the form box is still showing.
3. Type into **The check itself**. Nothing reaches the compose file. The box is now permanently
   disconnected — every later edit to it is silently dropped.

Step 3 is the serious part. A form control that looks like it works and quietly writes nothing is
worse than one that refuses, because the file that gets saved is not the file on screen.

## Where to look

- `compose-model.js` — `readTest()` `:1318`, `writeTest()` `:2614`, `harvestHealthTest()` `:1369`
- `stacks.js` — `commit()` `:2281`, `refreshRanges()` `:2236`, `structuralEdit()` `:2572`,
  `choiceFor()` `:1170`

## Hypothesis

The form is deliberately **not** redrawn by an edit made in the form — the rule is written out at
`stacks.js:2230-2234`, and it is the right rule, because redrawing takes the caret with it. Instead
only the attributes carrying line numbers (`data-field-row`, `data-from`, `data-to`) are refreshed.

That works for a narrow value edit. Switching **How the check runs** is not one: choosing *No check*
rewrites `test:` to the `NONE` form, which changes what fields exist in the rebuilt model. If the
command field is no longer among them, its row keeps line numbers pointing at text that has moved or
gone, and `commit()` then has nothing to write to — silently, which is how step 3 goes unnoticed.

So the likely fix is that changing this particular dropdown is a **structural** edit and must go
through `structuralEdit()` (which redraws and pushes undo), not `commit()`. Worth checking whether
any other dropdown changes the shape of the file the same way — `network_mode`, and the declaration
drivers, are the obvious candidates.

## Two things to settle while fixing it

- **What should *No check* actually do with the command you had typed?** Discarding it is hostile if
  the switch was a mis-click; keeping it in the file under a disabled `test:` is not valid compose.
  The `x-unraid.sections` stash built in `PLAN.md` already solves exactly this shape of problem —
  the block moves out of the way verbatim and comes back untouched — so it probably applies here.
- **`commit()` should never fail silently.** Whatever the cause, a write that cannot find its target
  ought to say so rather than return quietly. That is a separate, smaller fix and worth doing on its
  own merits, because it turns every future instance of this class of bug from invisible into
  obvious.

## Verifying

`tests/yaml_roundtrip.js` can drive the model directly: read a compose file with a `healthcheck`,
call the same model functions the dropdown calls to switch to `NONE` and back, and assert the file
comes back byte for byte. That reproduces the round trip without a browser and is where the
regression test belongs.
