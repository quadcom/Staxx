# PLAN 78 — acting on several stacks at once

**Status: reserved 2026-08-22. Straightforward; no new understanding required. Not to be built until
approved.**

## The gap

Everything is one stack at a time. With a dozen or more stacks — which is the normal case, and
certainly the case on the development machine — ordinary jobs become repetitive: check everything for
updates, stop everything before maintenance, start everything again afterwards.

Nothing new has to be understood to fix this. **The jobs already exist, they already run detached,
they already report progress and completion.** This is about driving several of them.

## What it is

Select stacks, choose one verb, watch them run.

- Selection on the rows that already exist.
- The verb list is the **existing** allowlist, restricted to those that make sense in bulk. Update,
  start, stop, check for updates: yes. Anything that removes or destroys: no, or not without the same
  double confirmation the clash guard already uses.
- Progress per stack, not one merged log. A person needs to know *which* of eight stacks failed.
- **One failure does not stop the rest**, and the summary at the end says plainly what succeeded and
  what did not.

## What must never be guessed

- **Never bulk anything destructive by default.** Removing several stacks at once, or recreating them,
  is a very efficient way to cause a lot of damage from one mis-click. If it is offered at all it
  wants the strongest confirmation in the plugin.
- **Order matters more than it looks.** Stacks can depend on each other — a database in one, an
  application in another; a reverse proxy everything else sits behind. Starting or stopping in an
  arbitrary order is not the same as doing it in a sensible one. The connections work in the
  relationships plan is what would eventually make a sensible order possible; **until then, do not
  pretend to know one** — say the order is as listed.
- **Do not run everything at once.** Ten stacks pulling images simultaneously will saturate the
  network and may exhaust the disk. A small number in flight at a time.

## Open questions

1. How many at a time, and is that worth making adjustable?
2. Does a bulk run produce one job or several? Several is simpler and reports better; one is easier to
   watch.
3. Should folders be selectable as a unit, given stacks already group into folders?

## Size

Medium, and almost entirely presentation over machinery that already works.
