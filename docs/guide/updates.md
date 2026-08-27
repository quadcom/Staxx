# Checking for updates

**A blank space next to a stack is normally the good news — it means the check ran and there is
nothing new.** There is no separate "up to date" mark. If the space is blank, either everything is
current, or nothing has been asked yet — both look exactly the same until you check. Everything
else that can appear there is described below.

## What the mark says

| On the row | What it means |
|---|---|
| *(nothing there)* | Either checked and nothing has changed, or never checked at all — the two look identical. "Check this image again" in the row's menu settles which it is. |
| `update ready`, `oldversion → newversion`, or `version · new build` | Something newer is on offer. This one is a button — press it to fetch and install. |
| `built here` | This image was built on this server rather than downloaded, so there is no registry to compare it against. |
| `not installed` | The file names this image, but it has not been downloaded yet. |
| `tag withdrawn` | The version named in the file is no longer published anywhere. |
| `registry moved` | The image is now published somewhere other than the address the stack started with. |
| `rebuild ready` | A locally-built image whose starting point has changed since it was last built. |
| `N to look at` | Not about a newer build at all: the author's own published example does something this file does not. See the [key to every mark](marks.md). |
| `could not check` | Something stopped the check running — see [when it says it could not check](#when-it-says-it-could-not-check) below. This one is **not** good news, and it is never coloured the same as the quiet marks above. |

`built here`, `not installed`, `tag withdrawn`, `registry moved` and `rebuild ready` are all
coloured quietly on purpose — they are statements of fact, not warnings. Only the "something newer
is on offer" mark can be pressed; nothing else does anything if you click it, so nothing else is
built to look clickable.

## How StaXX knows

Every image that gets published carries a fingerprint that changes whenever the actual contents
change, even on occasions when the version name printed on the tin does not. StaXX remembers two
numbers for each image: the fingerprint of what is installed here, and the fingerprint the registry
last handed back. The mark is nothing more than a comparison of those two remembered numbers, which
is why showing it costs nothing and does not need to touch the internet — only running an actual
check does that.

## When it checks

Checking happens on a schedule you set — "Check for image updates", with a matching "Time of day to
check" — and whenever you ask for one yourself. **It never happens just because you opened the
page.** A stack list you are only looking at does not spend any of your allowance with a registry.

## Why some images are checked more often than others

This is the part of the design people ask about most, so it is worth the detail.

A registry only answers a limited number of questions in a given stretch of time, and some of that
allowance is needed for your own ordinary downloads. So StaXX does not treat every image the same —
it spends the allowance where an answer is most likely to be different from last time, and saves it
where it almost never is.

- **An image pinned to an exact build is never asked about at all.** Pulling it can only ever
  fetch the exact build already named, so asking would just confirm the obvious.
- **A moving tag** — things like `latest`, `main`, `nightly`, `stable`, `beta` and their relatives,
  or an image with no tag named at all — is asked about **the most often of anything**, because the
  same name can point at a different build from one day to the next.
- **A tag that is a plain version number** is asked about far less often, roughly **once a week**,
  because a numbered release does not quietly change under that same number.
- **Everything else** — a tag that is neither a version number nor a recognised moving name — sits
  in the middle, asked about **once a day**.
- **An image that has actually changed twice inside the last fortnight** is bumped straight up to
  the fastest rate, whatever shape its tag is — real recent movement earns closer attention.
- **An image that has sat completely still for over three months** has its gap between checks
  doubled. This only happens once there is real evidence the image is not changing; a brand new
  image is never assumed quiet just because nobody has watched it long enough yet to see it move.
- **An image that has never been checked, or whose last check failed,** is asked about again
  promptly, so a first answer or a fix shows up quickly. If a check has failed **five times running**
  the pace drops back to once a day — at that point the problem is very likely the image or the
  network, not bad timing, so asking sooner would not help.
- Nothing is ever asked about **more than four times a day**, and nothing waits **longer than a
  fortnight** between checks, whatever else applies.

One thing to be clear about: how often a check can happen at all is still governed by the "Check
for image updates" setting — turned off, it never runs; set to "Every day" or "Once a week", a check
pass happens that often, at the time you chose. What the rules above decide is which images get
asked during that pass and which get skipped as not worth asking yet — a moving tag is asked every
time a pass runs, while a version-numbered one sits out most passes and only gets asked roughly
once in seven.

## What pressing "something newer is on offer" does

Pressing it notes down exactly what is running now, so there is something to return to, then
downloads the new build and rebuilds the container on it. Once that is done, StaXX measures both
fingerprints again so the next comparison starts fresh. **The mark clearing afterwards is the proof
it actually worked** — if it is still there, the update did not go through.

## Why it sometimes says "new build" against the same version number

A moving tag like `main` or `latest` can point at a different build without its name ever
changing — the label on the tin stays the same, only what's inside changes. When StaXX can see that
the build date has actually moved on even though the name has not, it says so plainly with "new
build" rather than leaving you to guess why an update appeared with no new version number attached.

## The countdown

A countdown only appears when "What to do with what is found" is set to "Install it by itself once
the delay below has passed", and it starts ticking from the moment the new build was first noticed
— reloading the page does not restart it.

Be aware that **the clock can keep counting down even when nothing is actually about to be
installed.** When that happens the row itself says why, and it is always one of these:

- automatic updates are paused for every stack right now
- this particular update was cancelled
- this stack was imported and has not been reviewed yet
- this stack is being edited at the moment
- this stack is stopped
- the delay is up, but it is waiting for the quiet time of day you set

Each of those tells you the one thing to do about it — turn the pause off, press the mark again to
let it run, review the stack, finish editing, start the stack, or simply wait for the quiet window.

## Cancelling, rolling back and pinning

- **Cancelling** stops one particular update from installing itself. Press the mark again to change
  your mind and let the clock run.
- **Rolling back** puts a service back on the build it was running before, and remembers that
  declined version so it is never offered to you again as if it were new.
- **Pinning** fixes a service to one exact build for good. A pinned service is the one thing that is
  never asked about at all — see above.

## When it says it could not check

"Could not check" is never treated as good news, and it is worded differently depending on the
reason: too many questions asked of that registry recently, the repository no longer existing at
that address, a private or otherwise unreachable registry, or simply no answer at all. Hover over
the mark for the exact reason and what to try. If the same check has failed five times running,
the mark carries a fuller explanation of how long it has been failing — worth reading before trying
again.

## Going back

Rolling back can only offer a build that StaXX itself actually recorded running on this server —
never any digest that merely looks plausible. That is a safeguard, not a limitation: it means
rolling back can never be pointed at a build that was never actually running here, whatever a
request happens to claim.
