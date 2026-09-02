# Update checking

<!-- index: 10 | answers what a check does, why images are asked about at different rates, what N to look at means, and how the countdown to an automatic install works. -->

## The update check


![The Check for updates button while a check is running, greyed out and reading "Checking…"](../images/guide/updates-checking.png)
![The update summary line reading "Checked 4 minutes ago, 19 updates waiting."](../images/guide/updates-summary-line.png)

A check asks each image's registry one question: is a newer build published under the same tag?
Nothing is downloaded. Nothing restarts. The answer sits until you press the pill or the countdown
finishes.

Checking happens on the schedule you set, and whenever you press **Check for updates**. It never
happens just because you opened [the stacks page](the-stack-list.md) — looking at the list costs
nothing.

## Check cadence

<!-- SHOT: updates-cadence-settings | full frame | the image updates section of the settings page, showing the check schedule control and the "what to do with what is found" choice -->

A check pass runs every hour, on its own, and only asks about the images that are due — most images
sit that pass out. The daily or weekly choice in [settings](settings.md#image-updates) is when StaXX
instead takes a full look at everything, whether it was due or not. That changes what the setting
means: choosing "Once a week" does not mean each image is only ever asked once a week — how often a
given image is asked is decided by the table below, running every hour. The weekly choice only sets
when the once-over of the whole lot happens.

A registry only answers so many questions an hour. StaXX spends that allowance where the answer is
most likely to have changed, and saves it where it almost never has. Checking costs your Docker Hub
allowance nothing at all — StaXX only asks for the build's headers, and Hub does not count that.
What spends the allowance is downloading an image, not asking about one.

| Image | Asked about |
|---|---|
| Pinned to one exact build | Never. Pulling it could only fetch that same build again. |
| A moving tag — `latest`, `main`, `master`, `develop`, `nightly`, `edge`, `stable`, `beta`, `dev`, or no tag at all | Roughly every six hours. |
| A plain version number | Roughly once a week. A numbered release does not quietly change under that same number. |
| Anything else | Roughly once a day. |
| Changed twice in the last fortnight | Every six hours, whatever its tag looks like. |
| Sat still for over three months | Its gap between checks doubled. |
| A check that keeps failing | Every six hours until it has failed five times running, then once a day. |

Nothing is ever asked about more than four times a day, or left longer than a fortnight between
checks.

An image you haven't actually downloaded to this server yet isn't asked about at all — there's
nothing on your machine to compare a registry's answer against, so StaXX waits until it's here.

Turning the schedule off in [settings](settings.md#image-updates) stops all of this. Left on, these
rules only decide which images get asked during a pass — not whether a pass happens at all.

## Author example findings

<!-- SHOT: updates-example-finding | full frame | a stack's edit form open with an "N to look at" finding note at the top, a Dismiss button visible -->

This is not about a newer build. It means the app's own publisher has put out an example compose
file that sets, or drops, something your file does not.

StaXX only looks for this on a moving tag — a pinned build is never checked. It reads the example
straight from the publisher's own GitHub project, never from Docker Hub, so looking does not spend
any of your registry allowance. A setting that merely holds a different *value* is not a finding —
only a setting the example adds or drops entirely is.

Open the stack to see each one. A finding sits next to the field it concerns when there is one, with
a **Dismiss** button that stops StaXX asking about it again until the author changes it once more. A
finding with no matching field — the example sets something your form has no place for — sits in a
note at the top of the form instead, with the same button.

## Update pill wordings

![A stack row with an orange "update ready" pill in the State column, beside a grey stopped pill](../images/guide/updates-pill-row.png)

The State column shows a pill for the container itself — see [the State column](the-stack-list.md#the-state-column). Beside it, when there is something to report, sits an update pill. Nothing is
shown there when nothing was found, or nothing has been checked yet.

| Wording | Meaning |
|---|---|
| `update ready`, an old and new version, or a version and "new build" | Something newer is on offer. Press it to fetch and install. |
| `N updates ready` | More than one service in this stack has an update waiting. |
| `rebuild ready` | Built here, and the image it builds from has moved on. |
| `built here` | Built on this server. There is no registry to compare it to. |
| `not installed` | Named in the file, but never pulled. |
| `tag withdrawn` | This tag no longer exists at the registry. |
| `registry moved` | The image is now published somewhere else. |
| `N to look at` | The author's own published example does something this file does not. |
| `could not check` | The last check failed. Hover the pill for why. |

## The countdown

<!-- SHOT: updates-countdown-chip | close-up | a stack row showing the countdown chip beside the update pill, with its reason text if the countdown is not actually running -->

A countdown only appears when [settings](settings.md#image-updates) has "What to do with what is
found" set to install it by itself. It starts the moment the new build was first seen — reloading
the page does not restart it.

The clock can keep ticking even when nothing is actually about to install. When that happens the row
says why:

| Reason shown | What to do |
|---|---|
| Automatic updates are paused for every stack | Turn the pause switch back on. |
| This update was cancelled here | Press the pill again to let it run. |
| This stack was imported and has not been reviewed yet | Review the stack. |
| This stack is being edited right now | Finish editing and save. |
| This stack is stopped | Start it. |
| Waiting for the quiet window | Wait — it opens at the time you chose. |

- **Cancelling** stops one waiting update from installing itself. Press the pill again to change your
  mind.
- **Skip this version** turns down one particular new build without cancelling future ones.
- **Rolling back** puts a service back on the build it ran before, and remembers the declined version
  so it is never offered again as new. See [going back](going-back.md).
- **Pinning** fixes a service to one exact build for good. A pinned build is never asked about — see
  the cadence table above.

All of this lives in the row's own menu — see [the row menu](the-stack-list.md#the-row-menu).

## Pause and update all

![The Check for updates button ringed, with Update all and Pause updates beside it](../images/guide/updates-bulk-buttons.png)
![The pause button after being pressed, now reading "Resume updates" and filled orange, with Update all beside it](../images/guide/updates-pause-resume.png)

| Button | What it does |
|---|---|
| Check for updates | Checks every image right now, ignoring the cadence table. |
| Update all | Installs every update currently waiting, across every stack. |
| Pause updates | Freezes every countdown on the page. Press again — it now reads Resume updates — to let them run. |

A folder has the same two actions for just what is inside it — see [folders](the-stack-list.md#folders).

## Docker Hub's limit

<!-- SHOT: updates-could-not-check | close-up | a stack row with a "could not check" pill, its tooltip open showing the failure reason -->

Docker Hub only lets one address download so many images an hour — about a hundred from a server
that has not signed in, and about two hundred signed in with an access token. Add the token under
[settings](settings.md#docker-hub-sign-in).

A check spends none of that. StaXX only asks Docker Hub for a build's headers — never the build
itself — and Hub does not count that against you. The allowance is spent by actually downloading an
image: installing an update, or anything else on your network that pulls through the same address.

When a registry refuses to answer, the pill says `could not check`. Hovering it says why: too many
questions asked recently, the repository no longer at that address, an unreachable registry, or
simply no answer at all. A check that keeps failing eventually says how long it has been failing, so
you can judge whether it is worth trying again.

Being refused by Docker Hub now means something else spent the download allowance on your address —
one of your own pulls, or another machine on the same network — since checking itself costs nothing.
StaXX tries again within the hour rather than waiting for the next scheduled pass. **Settings →
[Image updates](settings.md#image-updates)** shows what each registry has actually been asked and
what, if anything, it cost — worth a look if `could not check` keeps turning up. Hovering an update
pill also says when that image was last asked, when it is next due, and why.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
