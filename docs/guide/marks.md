# Row marks and icons

<!-- index: 20 | a quick key to every mark and colour on the stacks page: what each one means and what to do about it. -->

Every chip on [the stacks page](the-stack-list.md) is built from two things chosen independently: a
colour and a mark. Learn the two rules below and you can read any chip on the page, including ones
this list does not spell out.

- **Colour says how much it wants from you.**
- **The mark says what it is about.**
- **Movement says something is happening right now, rather than a standing state.**

## The five colours

| Colour | Means |
|---|---|
| <img src="../images/guide/marks-colour-green.png" alt="Green" width="130"> | Working. Nothing wanted. |
| <img src="../images/guide/marks-colour-grey.png" alt="Grey" width="130"> | At rest. Nothing wanted. |
| <img src="../images/guide/marks-colour-blue.png" alt="Blue" width="130"> | Worth knowing. Nothing to do right now. |
| <img src="../images/guide/marks-colour-amber.png" alt="Amber" width="130"> | Wants you, but nothing is broken. |
| <img src="../images/guide/marks-colour-red.png" alt="Red" width="130"> | Broken. |

## The marks

| Mark looks like | About |
|---|---|
| <img src="../images/guide/marks-mark-play.png" alt="A play triangle" width="130"> | The container is running. |
| <img src="../images/guide/marks-mark-stop.png" alt="A stop square" width="130"> | The container is not running. |
| <img src="../images/guide/marks-mark-cloud.png" alt="A cloud" width="130"> | There is an image to fetch. |
| <img src="../images/guide/marks-mark-diamond.png" alt="A diamond" width="130"> | The image has moved under a build. |
| <img src="../images/guide/marks-mark-clock.png" alt="A clock" width="130"> | A countdown, or something being held back. |
| <img src="../images/guide/marks-mark-refresh.png" alt="A circular arrow" width="130"> | Something needs restarting or rebuilding to take effect. |
| <img src="../images/guide/marks-mark-warn.png" alt="A warning triangle" width="130"> | Something has gone wrong, or will. |
| <img src="../images/guide/marks-mark-question.png" alt="A question mark" width="130"> | The image or its tag is gone from the registry. |
| <img src="../images/guide/marks-mark-page.png" alt="A page" width="130"> | The author's own published example. |

The warning triangle is the one mark used for more than a single subject: it always means
something has gone wrong, in three different places on the page. Where it appears more than once,
hover it: the text that pops up says which of the three you are looking at.

## Movement

Two things on the page actually move, and both mean something is live right now.

| Movement | Means |
|---|---|
| <img src="../images/guide/marks-state-checked.gif" alt="Green, play mark, a running count, pulsing" width="200"> | A slow pulse. A health check the app runs on itself is passing right now. |
| <img src="../images/guide/marks-state-inflight.gif" alt="Amber, dashed outline, circular arrow turning, a word such as Starting" width="200"> | A turning mark. A command you asked for is running right now. |
| <img src="../images/guide/marks-state-unchecked.png" alt="Green, play mark, a running count, still" width="200"> | Nothing moving. A standing state. Nothing is happening behind it. |

A dashed outline on its own is not movement. It is a shape more than one chip uses, some moving and
some not. Only the pulse and the turning mark mean something is live. If your system is set to
reduce motion, the movement stops, and only the words in this page and the chip's own hover text
tell a live chip from a still one.

## The state column

The first chip on a stack or container row.

![A folder row with four stack rows beneath it, each showing the app's logo, its name, a green "up" pill or a grey "stopped" one, the address it is reachable on, and columns of processor, memory and network figures](../images/guide/marks-row-states.png)

| Looks like | Means | What to do |
|---|---|---|
| <img src="../images/guide/marks-state-checked.gif" alt="Green, play mark, a running count, pulsing" width="200"> | Running, and every check that exists says it is working | Nothing |
| <img src="../images/guide/marks-state-unchecked.png" alt="Green, play mark, a running count, still" width="200"> | Running, but nothing is checking it | Press it to see whether StaXX can work out a check |
| <img src="../images/guide/marks-state-deciding.png" alt="Amber, play mark, a running count" width="200"> | Running, its own check has not finished deciding | Give it a moment |
| <img src="../images/guide/marks-state-unhealthy.png" alt="Red, play mark, a running count" width="200"> | Running, but the app inside says it is not working | Worth a look |
| <img src="../images/guide/marks-state-stopped.png" alt="Grey, stop mark" width="200"> | Stopped, or never started from the file yet | Start it when you want it |
| <img src="../images/guide/marks-state-inflight.gif" alt="Amber, dashed outline, circular arrow turning, a word such as Starting" width="200"> | A command you asked for is running right now | Wait, or press it to watch the log |
| <img src="../images/guide/marks-state-failed.png" alt="Red, warning triangle" width="200"> | That command failed | Press it to read what happened |

If a command's outcome is never seen by this page (most often because the browser tab was closed
or reloaded while it was running), the row shows no chip for it at all. It settles back to showing
whatever state the container is actually in.

## The update column

Sits beside the state chip, empty when there is nothing to report.

| Looks like | Means | What to do |
|---|---|---|
| <img src="../images/guide/marks-update-ready.png" alt="Amber, cloud mark, a version pair" width="200"> | A newer image has been published | Update when you are ready |
| <img src="../images/guide/marks-update-rebuild.png" alt="Amber, diamond mark" width="200"> | Built here, and its base image has moved on | Rebuild when you are ready |
| <img src="../images/guide/marks-update-restart.png" alt="Amber, circular arrow" width="200"> | The file has changed but the container has not been restarted to match | Restart to apply it |
| <img src="../images/guide/marks-update-countdown.png" alt="Blue, clock mark, a countdown" width="200"> | An update is waiting and will install on its own; the chip says when | Nothing yet; the figure is when it happens |
| <img src="../images/guide/marks-update-gone.png" alt="Blue, question mark" width="200"> | The image or its tag is gone from the registry | Check the repository |
| <img src="../images/guide/marks-update-findings.png" alt="Blue, page mark, a count" width="200"> | The author's own published example does things this file does not | Open the stack to see what differs |
| <img src="../images/guide/marks-update-failing.png" alt="Red, warning triangle" width="200"> | The update check itself keeps failing | Worth a look |

Any of these shown with a stronger outline carries a full sentence behind it: hover, or press it on
a touch screen, to read it.

## Along the top of the list

Words, in their own colours, sometimes with the row's own mark for the same subject.

![The right end of the title bar: a grey chip saying when updates were last checked, an orange chip counting updates waiting, and an outlined chip counting author-example findings](../images/guide/the-stack-list-title-chips.png)

| Looks like | Means |
|---|---|
| <img src="../images/guide/marks-topbar-checked.png" alt="Grey words, no mark" width="260"> | When updates were last checked. This stays grey whether a check has just run, has never run, or did not finish. It is only ever a notice, never something you need to act on. |
| <img src="../images/guide/marks-topbar-updates.png" alt="Amber, cloud mark, words" width="260"> | How many updates are waiting to install, across every stack |
| <img src="../images/guide/marks-topbar-findings.png" alt="Dashed outline, page mark, words" width="260"> | How many author-example findings there are to look at |
| <img src="../images/guide/marks-topbar-templates.png" alt="Red, warning triangle, words" width="260"> | Unraid still has old templates on flash that could rebuild a container StaXX has taken over, pushing its own container out |

When there is nothing to say about the author's example, or nothing waiting, that chip is left off
the bar entirely rather than shown empty or in red.

## Marks under the name

| Mark | Meaning |
|---|---|
| <img src="../images/guide/marks-name-pin.png" alt="Drawing pin" width="230"> | One or more services is fixed to one exact build. Hover it to see which. |
| <img src="../images/guide/marks-name-autoupdate.png" alt="Green circular arrows" width="230"> | This container installs updates on its own, whether from its own setting or the server's. See [choosing how a container updates](update-policy.md). |
| <img src="../images/guide/marks-name-triangle.png" alt="Orange triangle" width="230"> | Either the stack's file has drifted since it was imported, or a service is on a network that gives it its own address, so its ports do nothing. Hover it to see which. |

A coloured badge for a graphics card (blue for Intel, red for AMD, green for NVIDIA) means the
file asks for one. It stays even while the stack is stopped.

## Restart to apply

Shown when the running containers no longer match what the file on screen says. Nothing is broken
until you restart.

| Where it sits | Meaning |
|---|---|
| <img src="../images/guide/marks-restart-stackrow.png" alt="A circular arrow chip on a stack's own row, beside its state chip" width="300"> | Press it to see why, in plain terms, before deciding whether to restart |
| <img src="../images/guide/marks-restart-imagename.png" alt="A circular arrow chip beside an image name in the Services column" width="300"> | The file now names a different image than the one running |
| <img src="../images/guide/marks-restart-servicerow.png" alt="A circular arrow chip on a service row inside an expanded stack" width="300"> | See the reasons below |

All three reasons below show the same chip. Hover it to see which one you have.

| Reason shown on a service | Meaning |
|---|---|
| <img src="../images/guide/marks-restart-reason-changed.png" alt="The circular arrow chip with its hover text reading Settings changed since this started" width="330"> | The file has been edited since this container last started |
| <img src="../images/guide/marks-restart-reason-absent.png" alt="The circular arrow chip with its hover text reading Not started yet" width="330"> | In the file, never started |
| <img src="../images/guide/marks-restart-reason-leftover.png" alt="The circular arrow chip with its hover text reading No longer in the file" width="330"> | Removed from the file after its container started; still running, but orphaned |

## Tags beside the name

![Three stack rows, each with an orange "needs review" tag sitting next to the stack's name](../images/guide/marks-needs-review.png)

| Tag | Meaning |
|---|---|
| <img src="../images/guide/marks-tag-review.png" alt="An orange needs review tag" width="270"> | [Imported](bringing-in-a-container.md) and not checked over yet. Confirm it from the row's own menu before it will start or update itself. |
| <img src="../images/guide/marks-tag-handover.png" alt="A waiting to confirm tag" width="270"> | Shown right after a handover, when an older container has switched off and this one is running in its place. Check the app still works, then answer in the row's own menu. |
| <img src="../images/guide/marks-tag-boot.png" alt="A small lightning bolt, no text" width="270"> | Starts at boot. Hover it to see whether that is the whole stack, only part of it, or nothing, and whether a delay is set before the next one starts. |

## Broken stack marks

The strongest mark on the list: it replaces the app's own logo rather than sitting beside it. It
means the file could not be read, or none was found. The row states which, in red, with the
underlying complaint underneath.

![A stack row whose app logo is replaced by a red warning triangle, its name beside it, and in red "Compose cannot read this file" with "yaml: line 31: did not find expected key" underneath](../images/guide/marks-broken-stack.png)

Open the editor from this mark and fix the file. The row returns to normal on the next save. The
same mark appears on a [folder row](folders.md) when a stack inside it is broken.

## Health check offer

![A stack row for a stack called zz-screenshot-demo running one container called demo-database on the postgres image, a red box drawn around its green "running(1)" pill to show it is a button you can press](../images/guide/marks-health-offer-pill.png)

Press a green running chip that has nothing checking it and StaXX works out a health check for that
container. See [the health check offer](editing-a-stack.md#health-check-offer) for what happens next.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
