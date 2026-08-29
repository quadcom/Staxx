# What every mark means

<!-- index: 20 | a quick key to every mark on the stack list: running state, restart pending, pinned, and the rest. -->

A quick key to the small marks on the stack list — no essay, just what each one means and what to
do about it. If you want the full story behind the update marks, that lives on the
[update checking page](updates.md).

## Running state

The first pill on a row, showing whether the containers are actually going.

| Mark | Meaning | What to do |
|---|---|---|
| Green pill, e.g. "Up 5 minutes" | Running. The words are whatever Docker itself reports, so they can say "Up 2 hours" or similar rather than just "running". | Nothing needed. |
| Grey pill, "stopped" | Not running, and nothing else is wrong. | Start it when you want it running. |
| Grey pill, "not created" | The container has never been built from the file yet. | Start it to create and run it. |
| Amber pill (e.g. "Restarting", "paused") | Mid-way through something, or paused. | Usually settles on its own; check back. |
| Red pill, e.g. "Dead" | The container is in a broken state Docker itself calls dead. | Worth a look — try restarting it. |
| "unknown" (plain grey text, no pill) | StaXX cannot currently ask the running-container service what is going on. | Not something to fix on the row — it clears up once that service answers again. |

## The update column

Sits right beside the running-state pill. **Most of the time it is empty, and empty is normally the
good news** — nothing newer than what you are running has been found. There is no separate
"up to date" badge; absence is the answer. One line each below; see the
[update page](updates.md) for the reasoning behind each one.

| On screen | What it means |
|---|---|
| *(nothing)* | Either what is running is what the registry currently serves, or nothing has been asked about this image yet. The row does not tell the two apart — "Check this image again" in its menu settles it. |
| `update ready`, or a version — `1.2.3 · new build`, or `1.2.2 → 1.2.3` | Something newer exists; the wording depends on how much is known about the two builds. Press it to fetch and apply it. |
| `rebuild ready` | This was built on the server, and the base it builds from has moved on. Rebuild to pick it up. |
| `built here` | Built on this server, so there is no registry to compare it against. |
| `not installed` | Named in the file but not pulled yet. |
| `tag withdrawn` | The tag the file names no longer exists at the registry. |
| `registry moved` | The image now lives at a different registry. |
| `N to look at` | The author's own published example does something differently here that the file does not. Open the stack to see what. |
| `could not check` | The check failed. Not good news, and it is not shown as if it were. |

`built here`, `not installed`, `tag withdrawn`, `registry moved` and `N to look at` are deliberately quiet —
they are facts, not alarms. A waiting update and `rebuild ready` are the two shown in a stronger colour,
because they are the two worth acting on. Only a waiting update is a button; nothing else can be pressed
because there is nowhere for a press to go.

## Restart pending

A button reading "Restart to apply", shown when the containers actually running no longer match
what the file on screen now says — usually because it was edited and saved but not yet restarted.

**Nothing is broken until a restart happens.** That is exactly what the mark's own tooltip says:
the file describes what *should* be running, the containers are what *is* running, and this mark
just flags that the two have drifted apart. Press it to see why, in plain terms, before you decide
whether to restart.

On an individual service row inside an expanded stack, the same idea appears as a small icon with
one of these reasons:

| Reason shown | Meaning |
|---|---|
| Settings changed since this started | The file has been edited since this container was last started. |
| Not started yet | This service is in the file but has never been started. |
| No longer in the file | This service was removed from the file after its container was started, so it is still running but orphaned. |

## Image changed but not applied

A small warning triangle beside a single-service stack's image name, shown when the file now names
a different image than the one actually running. It is the same underlying idea as restart
pending — a save that has not yet been followed by a restart — but drawn beside the image itself
rather than as its own chip. Restarting brings the two back into line.

## Pinned

A drawing-pin icon next to the stack's name, shown when one or more of its services are fixed to
one exact build rather than a tag that can move. Hover it to see which service and which build.
This is why a pinned service never shows an update mark — there is nothing to compare it against,
by design.

## Drift since import

A warning triangle next to the stack's name, shown only on a stack brought across from the Compose
Manager plugin, when the compose file still sitting in Compose Manager's own folder has changed
since the day it was copied. It is a fact about where the stack came from — the two copies no
longer say the same thing — not about anything currently wrong with what is running. Hover it to
see what differs.

## Awaiting review

A "needs review" tag next to the name of a stack that was imported and has not yet been looked
over. It will not start or update itself until you have checked it and confirmed it from the row's
own menu — either by taking it over properly, or by clearing the lock if nothing else depends on
its container name.

## Waiting to confirm (handover)

A "waiting to confirm" tag shown right after a handover — the moment a stack takes over from an
older container that has been switched off and set aside. Unlike the review tag, this stack really
is running under its own control; the tag is only asking you to check the app still works and then
answer the confirmation question in the stack's own menu.

## Starts at boot

A small lightning-bolt icon next to the name, present on any row that is set to start when the
server boots. Hovering it says whether that applies to the whole thing, only part of it, or
nothing at all, and whether there is a pause built in before the next one starts.

## The row's own link buttons

Every row carries the same four small buttons, each greyed out with a reason when there is
nothing for it to open:

| Button | What it opens |
|---|---|
| WebUI | The app's own web page, when it has one and is running. |
| Logs | This container's log output. Always available, even when the container is stopped — that is often the moment logs matter most. |
| Repo | The project's own page, when one is known. |
| CA | The support or discussion thread for the app, when one is known. |
