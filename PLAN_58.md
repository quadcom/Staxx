# The page learns of a change instead of asking for one

## Context

The grid refreshes only when you do something. There is no timer, by design — but it means anything
that changes containers from outside the page (a scheduled update overnight, a `docker` command, a
container falling over) leaves the row wrong until you act or reload. Fixing the image column
exposed that: it now follows a rebuild, but only a rebuild *this page asked for*.

A poll would close the gap and waste effort doing it. Unraid already ships the alternative, and
every part of it was proved on the server before this was written:

- **nginx carries nchan 1.3.8.** `/sub/<channel>` is a subscriber endpoint; `/pub/<channel>` is a
  publisher endpoint bound to a **unix socket** (`/var/run/nginx.socket`), so only processes on the
  box can publish.
- **A plain `EventSource` works.** Opened from the logged-in Stacks page, it connected and received
  three messages published from the server, in order, with no library — which matters, because this
  project ships no client-side libraries.
- **`/sub/` is behind Unraid's own session auth.** An unauthenticated request is answered
  `302 → /login`. (An earlier note in conversation said the channel was unauthenticated because
  `nchan_authorize_request` is commented out; that was wrong — the site-wide gate still applies.)
- **The publisher endpoint reports its audience.** A plain `GET` (no POST, nothing published)
  answers `{"messages": …, "subscribers": N, …}`, so the watcher can tell when the last tab closed
  without nudging anyone and without a heartbeat file.
- **Docker's event stream is almost entirely noise.** Measured over the last hour on this server:
  256 container events, *all* of them `exec_create` / `exec_start` / `exec_die` from health checks,
  and not one real lifecycle event. Filtering is what makes the channel silent when nothing is
  happening.

Outcome: the page is told what changed, refreshes only the cells affected, and never reloads.

---

## The change

### 1. `scripts/events-watcher.sh` — new

Runs `docker events` filtered to container lifecycle, translates each event into one of two words,
and publishes it.

- **Read `--filter type=container`** and keep only real actions. `create`, `destroy`, `rename` change
  which rows should exist; `start`, `stop`, `die`, `kill`, `restart`, `pause`, `unpause` and
  `health_status` change only a row's cells. **Everything else is dropped, `exec_*` above all** —
  see the measurement above; without this the channel would carry three messages a second and say
  nothing.
- **Publish one word,** `rows` or `state`, to the `staxx` channel:
  `curl --unix-socket /var/run/nginx.socket -X POST -d "$verb" 'http://localhost/pub/staxx?buffer_length=1'`.
  `buffer_length` is required — omitting it is refused with `missing nchan_message_buffer_length
  value`. Nothing else goes in the message: no container name, no path, no stack. The channel is
  behind login, but a nudge that carries no detail cannot leak one regardless.
- **Coalesce.** Starting a stack emits several events in a second. Publish at most one message per
  second, and let `rows` win over `state` within that window since it is the larger refresh.
- **Exit when nobody is listening.** Every 30 seconds with no event, `GET` the publish endpoint and
  read `subscribers`. Two consecutive zeroes and it stops. Same discipline as
  `scripts/stats-collector.sh` — close the tab and sampling stops — but driven by the real audience
  rather than a heartbeat file, so there is nothing for the page to keep touching.
- **One instance.** Atomic `mkdir` lock under `/tmp/staxx/events`, exactly as the stats collector
  does it.
- Written to survive `sh` rather than bash, and with CRs stripped in mind — see the LF note in
  `CLAUDE.md`.

### 2. `include/action.php` — one new action

`events-watch`: start the watcher if it is not already running, and answer plainly whether push is
available at all. It must **not** report success when nchan is absent, because the browser uses that
answer to decide whether to bother subscribing.

Availability is a real check, not an assumption: the socket exists and a `GET` to the publish
endpoint answers. A future Unraid without nchan then reports unavailable and the page carries on
exactly as it does today.

### 3. `javascript/stacks.js` — subscribe

- On page start, ask `events-watch`. If it says push is available, open
  `new EventSource('/sub/staxx')`.
- `state` → `refreshState()`. `rows` → `refreshRows()`. Both already exist and already guard every
  write with a comparison, so an unchanged value touches no DOM: no flicker, open menus survive, a
  text selection survives.
- **Coalesce here too.** Two messages arriving together must not fire two requests; and a `rows`
  refresh that is already in flight must not be stacked. `refreshState()` already serialises itself
  (there is a "two of these in flight at once would race" comment) — follow that shape for `rows`.
- **Give up gracefully.** `EventSource` reconnects by itself, which is a feature until the endpoint
  is simply not there. Count failures and stop after a handful, leaving the page on its
  refresh-on-action behaviour rather than reconnecting forever.
- Nothing existing is removed. `afterRun()` keeps refreshing after a command: push is an addition,
  not a replacement, so the page still works when it is unavailable.

### 4. `scripts/stats-collector.sh` — read, do not change

It is the precedent for a self-terminating background sampler and the watcher should read like its
sibling. Reuse its shape for the lock and the exit; do not touch the file.

---

## Files

| File | Change |
|---|---|
| `scripts/events-watcher.sh` | new — the Docker event listener and publisher |
| `include/action.php` | the `events-watch` action, and the availability check |
| `javascript/stacks.js` | subscribe, dispatch to the two existing refreshes, coalesce, give up gracefully |
| `staxx.plg` / packaging | confirm a new file under `scripts/` needs no manifest entry (the tree is copied verbatim) — check, do not assume |

Order: **1** first and testable on its own from a shell, then **2**, then **3**.

## Verifying it

Locally: `node --check` on `stacks.js` and `node tests/js_undeclared.js`, both every time.

On the server, the watcher can be proved with no browser at all:

```sh
bash /usr/local/emhttp/plugins/staxx/scripts/events-watcher.sh &
docker events --since 1m --until 0s --filter type=container --format '{{.Action}}'
curl -s --unix-socket /var/run/nginx.socket -H 'Accept: text/json' \
     'http://localhost/pub/staxx?buffer_length=1'      # subscribers, messages
```

- With no tab open, the watcher exits within about a minute on its own.
- Health-check churn publishes **nothing** — confirm the message count does not move while
  `exec_*` events stream past.

Then in a browser, and this is the part only a screen shows:

- Open the Stacks page. `subscribers` reads 1. Open a second tab: 2. Close both: the watcher exits.
- **Stop a container from the command line.** Its row's state chip changes within a second, with no
  page reload and without touching the page. That is the whole point of this.
- Start it again from the command line: the chip follows.
- **Create a container outside the page** in a stack that has one: a new row appears, which is the
  case the cheap refresh cannot do and the reason two words exist rather than one.
- Open a right-click menu and leave it open while stopping a container elsewhere: the menu must stay
  open and the cells behind it must still update.
- Select some text in a row, then trigger an event: the selection survives.
- Watch the network panel: no repeated requests while nothing happens. One long-lived stream, silent.
