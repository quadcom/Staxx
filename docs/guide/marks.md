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
| Red pill, e.g. "Up 2 hours (unhealthy)" | The container is going, but the app inside says it is not working. See below. | Worth a look — the app's own log usually says why. |
| Amber pill, e.g. "Up 5 seconds (health: starting)" | The container is going, and its own check has not finished deciding yet. | Give it a moment. |
| Grey pill, "stopped" | Not running, and nothing else is wrong. | Start it when you want it running. |
| Grey pill, "not created" | The container has never been built from the file yet. | Start it to create and run it. |
| Amber pill (e.g. "Restarting", "paused") | Mid-way through something, or paused. | Usually settles on its own; check back. |
| Red pill, e.g. "Dead" | The container is in a broken state Docker itself calls dead. | Worth a look — try restarting it. |
| "unknown" (plain grey text, no pill) | StaXX cannot currently ask the running-container service what is going on. | Not something to fix on the row — it clears up once that service answers again. |

![A folder row with four stack rows beneath it, each showing the app's logo, its name, a green "up" pill or a grey "stopped" one, the address it is reachable on, and columns of processor, memory and network figures](../images/guide/marks-row-states.png)

## Running, and actually working, are two different things

Hover any running pill and it tells you which of the two it is claiming.

Most images say only "the container is going". That is genuinely all Docker knows: some apps fail
at startup and shut themselves down while the container carries on doing something harmless, and
from the outside it looks perfectly fine. A green pill on such an app means nobody has checked.

Some images ship their own definition of working and check themselves every few seconds. Where one
does, StaXX shows the answer: the pill turns red and the dot on the app's picture turns red with
it, so a stack whose app has quietly died no longer reads as healthy. The stack's own row turns red
too, and its tooltip names which part of it is the unhappy one.

Nothing is being probed to work this out — it is the app's own verdict on itself, which Docker was
already collecting. StaXX simply asks for it.

## Letting StaXX work out a health check

A health check is not something an app's author has to build in. It is just a question Docker asks
the container every so often — "are you all right?" — and the container answers yes or no. Some
apps ship their own question and answer it themselves; most do not, which is why so many green pills
only ever mean "the container is going", never "the app inside is working". You can add the question
afterwards, without any help from whoever wrote the app, and it lives in your own file from then on.

You will meet the offer in two places:

- **Click a green running pill that says nothing has checked it.** On a stack holding several
  services, that is the pill on each service's own row. On a stack holding one, it is the pill on the
  stack's own row, since there is no doubt about which container it means.
- **A button in the editor's health check section**, for anyone who goes looking before anything has
  quietly gone wrong.

When you click through, StaXX looks for a real question to ask, best answer first:

| What StaXX finds | What happens |
|---|---|
| The image already checks itself | Nothing is offered — Docker is already watching it. |
| StaXX recognises the image | It offers a check written against that database's own documentation, using the database's own tool to log in and ask it something real. |
| The project published its own example, and it matches a shape StaXX recognises | It offers that check instead. |
| The app has a web page, and something inside the container can fetch a page | It offers a plain check of that page. |
| None of the above | It offers nothing, and says so. |

Be clear about what each kind actually proves, because none of them proves more than the question it
asked. A check written against a known database logs in and runs a real command, so it stands for
something — the database accepted a connection and did real work. A plain web-page check only tells
you the web page answered; it says nothing about whatever sits behind that page. StaXX always tells
you which claim you are getting before you accept it.

**Nothing is ever offered on a guess.** Before it offers anything, StaXX tries the candidate check
once against the running container and looks at what comes back — this runs one short command
inside the container, and only when you have asked for a check to be worked out. If the command
cannot run at all, StaXX says so and offers nothing, rather than handing you a check that would sit
there reporting "unhealthy" forever regardless of whether the app is actually fine. That trial is
also why you can trust an accepted offer: a check that could not run would never have been offered.

Sometimes StaXX offers nothing at all, and that is the correct answer, not a shortcoming. It means
StaXX does not know a real question to ask that particular image. A check that can only ever say yes
is worse than no check, because it turns an honest "nobody knows" into a false "looks fine" — so
silence here is deliberate.

One thing worth knowing before you accept: if another service in the same file is already written to
wait for this one to become healthy, that wait does nothing until a check exists to answer it. Adding
one turns it into a real gate — the other service will now actually wait its turn. StaXX tells you
when this applies to the check you are about to add.

**What this never does.** Nothing is ever added without you accepting it on screen first. Nothing is
written into a service that already has a check, whether that check came from the image itself or is
already sitting in your file. No check is ever invented for an image StaXX does not recognise. And a
container going unhealthy never makes StaXX restart it — Docker does not restart a container just
because it fails its own check, and nothing here changes that.

## A red triangle where the app's picture should be

The strongest mark on the list, because it replaces the app's own logo rather than sitting beside it.
It means StaXX could not make sense of that stack's compose file, or could not find one in the
folder at all, and the row says which of the two it is in red, with the reader's own complaint
underneath — the line number is usually the fastest way to the problem:

![A stack row whose app logo is replaced by a red warning triangle, its name beside it, and in red "Compose cannot read this file" with "yaml: line 31: did not find expected key" underneath](../images/guide/marks-broken-stack.png)

Nothing about such a stack can run, so there is nothing to start and no state pill worth reading.
Open the editor from the triangle and fix the file; the row goes back to normal on the next save. The
same triangle appears in the group of pictures on the stack's [folder row](folders.md), in full
colour with a red outline, so a problem inside a collapsed folder is still visible from the outside.

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

![Three stack rows, each with an orange "needs review" tag sitting next to the stack's name](../images/guide/marks-needs-review.png)

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
