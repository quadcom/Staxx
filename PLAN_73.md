# PLAN 73 — clash warnings that can see the machine itself

**Status: reserved 2026-08-22. Extends `PLAN_65`, which is complete. Not to be built until approved.**

## The gap, verified

The clash warning compares a stack's ports and paths against **other containers**, and against nothing
else. Confirmed by reading it: there is no code anywhere in the plugin that looks at what is actually
bound on the machine — no listening-socket check of any kind.

So the most likely real collision on an Unraid box is precisely the one it cannot warn about: **a
reverse proxy that wants ports 80 and 443, which the webGUI is already holding.** Setting up remote
access is one of the commonest things a person does, it fails on exactly this, and the failure arrives
as an opaque binding error from Docker rather than as an explanation.

Anything the machine itself is listening on has the same problem — the web interface, an SSH port
moved off the default, a service from another plugin, something the person started by hand.

## What it is

One more source of facts for the warning that already exists. Where the existing check names the
container holding a port, this names **the machine** — and where it can, what on the machine.

The user-facing difference is one sentence: instead of *"nothing else is using this port"* followed by
a failed start, *"the server itself is already listening on 443 — this will not start."*

## What must never be guessed

- **A container's own published port shows up as a machine listener too.** The existing check already
  has to exclude a stack's own containers; this needs the same care in reverse, or every published
  port in the system reads as a clash with itself. **This is the main thing to get right, and the
  obvious way to write it is wrong.**
- **Listening on one address is not listening on all of them.** A service bound to the local interface
  only does not conflict with a container publishing on every address — and the existing code already
  carries a note about this distinction for container ports, which is the precedent to follow.
- **Do not name a process without being sure.** "The server is using this" is always true and useful.
  Naming the wrong culprit is worse than naming none.
- **Warn, never refuse.** Same stance as the existing warning.

## Open questions

1. Which source of truth for what is listening, and is it available without a shell call that could
   hang? Anything external goes through the existing time-limited wrapper.
2. Is this cheap enough to run while somebody types in the form, or does it belong to the same moment
   the existing clash check runs?
3. Does the same idea apply to paths — the machine's own use of a folder, as opposed to another
   container's? Probably not worth it, but worth asking once rather than discovering later.

## Size

Small. One new source of facts feeding a warning that is already written, presented the same way.
