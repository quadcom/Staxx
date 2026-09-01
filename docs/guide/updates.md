# Checking for updates

<!-- index: 10 | answers what a check does, why images are asked about at different rates, what N to look at means, and how the countdown to an automatic install works. -->

## What a check does

A check asks each image's registry one question: is a newer build published under the same tag?
Nothing is downloaded. Nothing restarts. The answer is just remembered until you press the pill or
the countdown finishes.

Checking happens on the schedule you set, and whenever you press **Check for updates**. It never
happens just because you opened the page — looking at the list costs nothing.

## Why some images are checked more often than others

A registry only answers so many questions an hour, so StaXX spends that allowance where the answer
is most likely to have changed, and saves it where it almost never has.

- **A build pinned to one exact image is never asked about.** Pulling it could only ever fetch that
  same build again.
- **A moving tag** — `latest`, `main`, `master`, `develop`, `nightly`, `edge`, `stable`, `beta`,
  `dev`, or no tag at all — is asked about most often, roughly every six hours.
- **A plain version number** is asked about roughly once a week. A numbered release does not
  quietly change under that same number.
- **Anything else** is asked about roughly once a day.
- **An image that has actually changed twice in the last fortnight** is bumped up to every six
  hours, whatever its tag looks like.
- **An image that has sat still for over three months** has its gap between checks doubled.
- **A check that keeps failing** is retried every six hours until it has failed five times running,
  then it drops back to once a day — trying sooner would not fix a dead registry.
- Nothing is ever asked about more than four times a day, or left longer than a fortnight between
  checks.

Turning the schedule off in [settings](settings.md#image-updates) stops all of this. On, these rules
only decide which images get asked during a pass — not whether a pass happens at all.

## N to look at

This is not about a newer build. It means the app's own publisher has put out an example compose
file that sets, or drops, something your file does not.

StaXX only looks for this on a moving tag — a pinned build is never checked. It reads the example
straight from the publisher's own GitHub project, never from Docker Hub, so looking does not spend
any of your registry allowance. A setting that merely holds a different *value* is not a finding —
only a setting the example adds or drops entirely is.

Open the stack to see each one. A finding sits next to the field it concerns when there is one, with
a **Dismiss** button that stops StaXX asking about it again until the author changes it once more.
A finding with no matching field — the example sets something your form has no place for — sits in a
note at the top of the form instead, with the same button.

## The pill

The State column shows a pill for the container itself. See [the state column](the-stack-list.md#the-state-column) for what those say. Beside it, when there is something to report, sits an update
pill. Nothing is shown there when nothing was found, or nothing has been checked yet.

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

A countdown only appears when [settings](settings.md#image-updates) has "What to do with what is
found" set to install it by itself, and it starts the moment the new build was first seen —
reloading the page does not restart it.

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
  above.

All of this lives in the row's own menu — see [the row menu](the-stack-list.md#the-row-menu).

## Doing it for everything at once

- **Pause updates**, top of the page, freezes every countdown at once. Press it again — it now reads
  Resume updates — to let them run.
- **Update all** installs every update currently waiting, across every stack.
- A folder has the same two actions for just what is inside it — see [folders](the-stack-list.md#folders).

## Docker Hub's limit

Docker Hub only answers a small number of these questions an hour from a server that has not signed
in — about ten. Signed in with an access token, that rises to about a hundred. Add the token under
[settings](settings.md#docker-hub-sign-in).

When a registry refuses to answer, the pill says `could not check`. Hovering it says why: too many
questions asked recently, the repository no longer at that address, an unreachable registry, or
simply no answer at all. A check that keeps failing eventually says how long it has been failing, so
you can judge whether it is worth trying again.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
