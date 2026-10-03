# Updates tab

<!-- index: 84 | the Updates tab: the check schedule, the default action, install timing, notifications and the update-check activity table. -->

The **Updates** tab, in [Settings](settings.md), sets the server-wide defaults for checking and
installing image updates. It is explained fully in [checking for updates](updates.md). A single
service can instead be set to Manual, Automatic or Pinned; see
[choosing how a container updates](update-policy.md).

![The Updates tab in full: Check for image updates with How often and Time of day, the Updates default with Manual and Automatic, When to install, Notifications with a row for each kind of message, the summary, release notes, Use icons, Quiet hours and Send a test message, Previous image releases to keep set to 2, and the Update-check activity table](../images/guide/settings-updates-tab.png)

## Image updates

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Check for image updates | Never / Every day / Once a week | Every day | Sets how often StaXX asks each image's publisher whether a newer version exists. The check only ever tells you; nothing is downloaded or restarted by it. Never, it never asks. |
| Time of day to check | A 24-hour time | 04:00 | Sets the time the daily or weekly check runs. |

## Updates

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Updates | Manual / Automatic | Manual | Sets what happens when a newer version is found. Manual marks the row and offers an Update button, leaving installing it to you. Automatic installs the update once the delay below has passed. A service can be given its own answer instead, including **Pinned** to keep it on one exact build; see [choosing how a container updates](update-policy.md). A service's own answer wins over this one. |

## When to install

These three only appear while Updates, above, is Automatic.

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Delay before installing | 0 to 720 hours | 24 | Sets how long an update waits, counting down on its row, before it installs itself. A delay of 0 installs the moment an update is found. |
| Only install during a quiet time | Yes / No | Yes | On, an update whose countdown ends outside the quiet hours waits until they begin. Off, it installs the moment the countdown ends. |
| Quiet time starts / ends | A time, in half-hour steps | 03:00 / 05:00 | Sets the hours during which updates may install themselves. The window can run past midnight into the next day. |

## Notifications

![The Notifications field: StaXX advanced email notifications ticked with its line beneath, rows for New image found, Image installed and Installation failed each offering Straight away, In the summary and Off, Still pinned offering In the summary and Off, the Summary set to Daily at 08:00, Release notes set to First lines and a link, Use icons and Quiet hours ticked with from 10:00 PM to 07:00 AM, and the Send a test message button](../images/guide/settings-updates-notifications.png)

Messages go through Unraid's own notifications: the bell, email, and any other service set up
there. What each message holds is in [update checking](updates.md#notifications).

| Setting | Choices | Default | What it does |
|---|---|---|---|
| StaXX advanced email notifications | On / Off | Off | With this on, StaXX sends its own email, with app icons and colours, to the address set in Unraid's notification settings. The bell and any other services still get every message. With it off, every message is plain text. |
| New image found | Straight away / In the summary / Off | Off | Sets when you hear that a check found a newer version. |
| Image installed | Straight away / In the summary / Off | In the summary | Sets when you hear that an update went in. |
| Installation failed | Straight away / In the summary / Off | Straight away | Sets when you hear that an update did not go in. |
| Still pinned | In the summary / Off | In the summary | With this on, the summary lists every container still pinned to an exact build, once a week. |
| App keeps restarting | Straight away / In the summary / Off | Straight away | Sets when you hear that an app restarted by itself 3 or more times within an hour. |
| Health check failed | Straight away / In the summary / Off | Straight away | Sets when you hear that an app's health check turned unhealthy. |
| App stopped by itself | Straight away / In the summary / Off | Straight away | Sets when you hear that a running app stopped with an error or ran out of memory. |
| Summary | Daily / Weekly, a day of the week, a time | Daily at 08:00 | Sets when the summary is sent. Nothing is sent when there is nothing to report. |
| Release notes | First lines and a link / Link only / Leave out | First lines and a link | Sets how much of each app's release notes a message shows. The summary only ever shows the link. |
| Use icons | On / Off | On | With this on, small pictures such as ✅ and ❌ start each line. With it off, messages are words only. |
| Quiet hours | On / Off, from and to | Off, 22:00 to 07:00 | With this on, a message that falls inside these hours waits for the next message or the summary. Failed updates and app problems set to Straight away are always sent straight away. |
| Send a test message | A button | | Sends one message now, so you can see what yours look like. |

A service can take itself out of these messages in its own settings; see
[choosing how a container updates](update-policy.md).

## Previous image releases to keep

| Setting | Choices | Default | What it does |
|---|---|---|---|
| Previous image releases to keep | 0 to 5 | 2 | Sets how many older versions of each image stay on disk after an update, so you can put one back. See [version history](recovery-and-redundancy.md). |

## Update-check activity

Shows, for each place StaXX asks about updates, how many times it has asked this hour and today,
and how many of those counted against that place's limit. Where there is something to say about a
place (that it sets no limit of its own, or that it has stopped answering), the row carries an
asterisk and the note appears once underneath the table. Look here when a row keeps saying
`could not check`. See [checking for updates](updates.md#docker-hubs-limit).

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
