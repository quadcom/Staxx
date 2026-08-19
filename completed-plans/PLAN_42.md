# PLAN_42 — phase 3 of the importer: the handover

**Status: BUILT 2026-08-19**, shipped in one commit with `PLAN_41.md`. Sub-plan of PLAN_35, whose phase 3 this is. Adrian chose on
2026-08-18 to build this together with PLAN_41 (phase 2, the writing), so that an import works from
end to end rather than producing stacks that cannot start. Read PLAN_41 first; this takes over where
it stops.

## What you would notice

A stack that arrived from an import carries a note saying it has not been checked. Its menu has one
thing on it: **Take over and start**.

Pressing that opens a window that names the container it is about to replace and says plainly what
will happen. Accept, and the old container is switched off, set aside under a different name, and the
stack starts up in its place — under the original name, so anything pointing at it by name carries on
working. A second window then asks whether it actually works. **Yes** clears the old one away for
good. **No** puts everything back exactly as it was, running, within seconds.

Until you answer that question the row says it is waiting to be confirmed, and the old container sits
there switched off. You can answer at any time.

## The two things that make this safe

**The old container is set aside, not deleted.** A container owns its name even while it is switched
off, so the old one has to move out of the way before the new one can have that name. Deleting it
would do that too — but then a start that fails for any reason leaves you with nothing at all, and
the only way back is rebuilding from the template. Setting it aside costs nothing, frees the name
just as well, and turns a failure into an undo.

**It only counts as done when you say so.** The window asking whether it works is not a
congratulations message — it is the undo button, and it stays available until you use it or dismiss
it. The cost is one switched-off container taking up a few megabytes until you answer.

## What decides which container gets replaced

The compose file already says what the container should be called — the importer writes that name in,
copied from the template. So the answer is simply: the names the file asks for, checked against the
names this server already has. Anything in both lists is a container to replace, and that is what the
first window names.

Nothing needs to be recorded at import time for this, and it stays right even if the file is edited
in between.

If the file asks for no particular name, nothing can clash and there is nothing to replace — the
stack just starts.

## The sequence, and what happens when each step fails

1. Remember whether the old container was running.
2. Switch it off.
3. Set it aside under a new name.
4. Remove the "not checked yet" note and write the "waiting to be confirmed" one in its place.
5. Start the stack.

If **anything** in there fails, it goes straight back: the set-aside container gets its own name
returned, is started again if it had been running, and the "not checked yet" note comes back. You end
up where you began, and the message says what went wrong.

If the going-back itself fails — which means the server is in a state nothing can assume anything
about — it **stops there**, changes nothing else, and says exactly what has been left where and under
what name. Guessing further at that point is how one problem becomes two.

## It runs on the server, not in your browser

Closing the tab or losing the connection halfway through must not strand a half-finished handover, so
the whole sequence is one job on the server that reports its progress back the way every other
long-running command here already does.

## Waiting to be confirmed

A second note file, written where the first one was. While it is there the row says so, and the two
answers are:

- **It works** — the set-aside container is deleted and the note goes.
- **It does not** — the stack is stopped, the old container gets its name and its state back, and the
  original "not checked yet" note returns, so the import is exactly as it arrived and can be tried
  again.

Same reasoning as the first note: a file in the folder survives a rename, survives the stack being
filed into a folder, goes when the stack goes, and can be read by a human without this plugin.

## The new kind of command, and why the existing one cannot do this

Every command this plugin runs today is built as "run compose, on this stack's own file, and do X".
Switching off, renaming and deleting a container that is *not* part of this stack cannot be said in
that form at all. The tempting shortcut — letting a command supply its own opening — would turn the
one place in this plugin with no route from typed input to an arbitrary command into a place that has
several.

So this is a second, separate kind of command, deliberately narrow:

- a fixed list of four things it can do: switch off, start, rename, delete;
- what it acts on is a **container name that must actually exist on this server** — checked by asking
  Docker for its list, not by checking the name looks reasonable;
- the set-aside name is **worked out by the server**, never sent from the browser, so there is no free
  text anywhere in it;
- the existing compose commands are not touched and the two never share a route.

## No exception to the lock is needed, which is better than one

The first draft of this said the handover would be an exception carved into the thing that refuses
every button on a locked stack. It does not need to be. That refusal guards the list of ordinary
commands, and the handover is not on that list — it is its own thing, with its own checks. So rather
than the lock gaining a hole, the handover simply **requires** the stack to be locked: it is the only
way in, and it refuses a stack that is not an unreviewed import.

## How the steps hold together, and how the undo can be trusted

Every stage of the handover is a rename, so every stage has an exact opposite. That is what makes the
undo real rather than best-effort:

- the "not checked yet" note is **moved aside, not deleted**, so putting it back is a move;
- the container is renamed, so putting it back is a rename;
- a small record of what was moved and whether it had been running is written **before** anything
  else happens and removed last, so a run cut off halfway still leaves something that says what
  state the server is in.

The undo is also written so it can be run when only some of the steps happened: it attempts every
reversal and ignores the ones that have nothing to reverse. That way it never needs to know how far
it got, which is the part of this kind of code that is usually wrong.

## Starting at boot: nothing to build, and why

Unraid's start-at-boot list is a plain list of container **names**. At boot it walks that list and
starts anything by that name that already exists — it never builds anything from a template. So a
container rebuilt under the same name is started by the existing entry with no changes from us. Do
not remove the entry, and do not build anything for this.

Between the old container being set aside and the new one existing, the entry finds nothing and is
skipped silently.

## What this costs you, honestly

- **The container is rebuilt, not moved.** Anything written *inside* it rather than into one of its
  mapped folders is gone. Everything in appdata is untouched, which is where a container's real data
  lives.
- **Unraid's own Docker page still has the template**, so it can still build that container again. If
  you do that while the stack is running you get two of them, and Docker will refuse the second one
  loudly over the port or the address rather than quietly corrupting anything. The row says which one
  is live.
- **The set-aside container is visible on Unraid's page** under its new name until you confirm. That
  is deliberate — it is your way back — but it will look odd until it goes.

## Proving it works

Everything except the Docker steps can be proven with no containers at all: that the narrow command
list refuses anything not on it and anything naming a container that does not exist, that the
set-aside name is derived safely and cannot collide, that the notes are written and removed in the
right order, and that a failed start puts everything back.

The Docker steps cannot be proven that way. Testing them needs **one container on the server you are
happy to have handed over and handed back** — the same arrangement as the test stack you nominated
for the restart work. The safest first run is one of the fifteen templates that has no container at
all: there is nothing to replace, so it tests everything except the setting-aside.

**This is the first thing in the importer that touches Docker on your server, and nothing in it runs
until you press the button on a stack you chose.**

## Files

| File | Change |
|---|---|
| `include/Stacks.php` | the second job kind; the lock's one exception; the second note file |
| `include/Import.php` | which names clash, and the handover sequence |
| `include/action.php` | the actions behind the two windows |
| `include/StacksTable.php` | the waiting-to-be-confirmed badge |
| `javascript/stacks.js` | the two windows, the menu entry, the progress |
| `sheets/staxx.css` | the badge |
| `tests/server/handover.php` | new — the refusals, the naming, the notes, the put-back |

## What this phase deliberately does not do

- **No Compose Manager projects.** Several containers at once, and four of the seven pin their own
  names — a later phase.
- **No touching Unraid's templates.** They stay exactly as they are, which is what makes going back
  possible at all.
- **No touching the start-at-boot list**, for the reason above.
