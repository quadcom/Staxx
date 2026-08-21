#!/bin/sh
# StaXX — background Docker event watcher.
# Copyright 2026, StaXX contributors. GPL-2.0.
#
# WHY THIS EXISTS
#
# The page has no timer and refreshes only when something asks it to. That is
# fine for anything done from the page itself, but leaves a row wrong when a
# container is changed from outside it — a scheduled update, a `docker`
# command run by hand, a container that just falls over. This watches Docker's
# own event stream and nudges the page over the same nchan channel the stats
# graphs do not use, so it learns of the change instead of polling for one.
#
# WHY MOST EVENTS ARE DROPPED
#
# Measured over an hour on this server: 256 container events, every single one
# `exec_create` / `exec_start` / `exec_die` from health checks, and not one
# real lifecycle event. Publishing all of that would turn a silent channel
# into three messages a second and tell the page nothing it can use. Only the
# events below survive; everything else, `exec_*` above all, is dropped before
# it ever reaches curl.
#
# WHY THE MESSAGE CARRIES NOTHING
#
# The published word is "rows" or "state" and nothing else — no container
# name, no stack, no path. The channel sits behind Unraid's own login today,
# but a nudge with no detail in it cannot leak one regardless of what happens
# to that protection later.
#
# WHY IT STOPS BY ITSELF
#
# Same discipline as scripts/stats-collector.sh, but there is no heartbeat
# file to go stale here — the publish endpoint already answers who is
# listening. Every 30 seconds of wall clock, a plain GET (no POST, nothing
# published) is asked how many subscribers the channel has; two readings of
# zero in a row and this exits. Nobody has to remember to stop it.
#
# Usage: events-watcher.sh <state-dir>

DIR="$1"
[ -n "$DIR" ] || exit 1

SOCK=/var/run/nginx.socket
PUBURL='http://localhost/pub/staxx?buffer_length=1'
CHECK=30          # seconds between subscriber checks when nothing is happening

LOCK="$DIR/watcher.pid"
LOCKDIR="$DIR/watcher.lock"
FIFO="$DIR/events.fifo"

mkdir -p "$DIR" 2>/dev/null

# One watcher at a time, and the lock has to be ATOMIC — see
# scripts/stats-collector.sh for the full explanation of why a "does a pid
# file exist" check followed by writing one is not safe. Same shape here.
if ! mkdir "$LOCKDIR" 2>/dev/null; then
  holder=$(cat "$LOCK" 2>/dev/null)
  if [ -n "$holder" ] && kill -0 "$holder" 2>/dev/null; then
    exit 0                      # a live watcher already has it
  fi

  # A lock younger than this belongs to a watcher still starting up, not an
  # abandoned one — see stats-collector.sh, which hit this race first.
  created=$(stat -c %Y "$LOCKDIR" 2>/dev/null || echo 0)
  if [ $(( $(date +%s) - created )) -lt 30 ]; then
    exit 0
  fi

  rm -rf "$LOCKDIR"             # stale: the holder is gone for good
  mkdir "$LOCKDIR" 2>/dev/null || exit 0
fi
echo $$ > "$LOCK"

# Release the lock, and stop the event reader, only if we still hold the
# lock. An unconditional release here would hand a newer watcher's lock back
# to a dying old one — see stats-collector.sh's cleanup() for the incident
# that taught this.
cleanup() {
  if [ "$(cat "$LOCK" 2>/dev/null)" = "$$" ]; then
    [ -n "$PRODUCER" ] && kill "$PRODUCER" 2>/dev/null
    rm -f "$LOCK" "$FIFO"
    rmdir "$LOCKDIR" 2>/dev/null
  fi
}
trap cleanup EXIT INT TERM

# Stand down when this script itself is updated, exactly as the stats
# collector does — otherwise a watcher started before an upgrade runs the old
# code forever, because the lock above sees it as still alive and refuses to
# start the new one.
VERSION=$(stat -c %Y "$0" 2>/dev/null)

# PHP's environment has no PATH worth trusting either, so the docker binary is
# found the same way staxx_docker_bin() does it in Defines.php: try the two
# known install paths and fall back to the bare name.
DOCKER=docker
for candidate in /usr/bin/docker /usr/local/bin/docker; do
  if [ -x "$candidate" ]; then
    DOCKER="$candidate"
    break
  fi
done

# One word in, and it says why in the header above: no detail travels with it.
publish() {
  curl -s --max-time 5 --unix-socket "$SOCK" -X POST -d "$1" "$PUBURL" >/dev/null 2>&1
}

# A plain GET publishes nothing — it only reports who is still subscribed.
# Prints the subscriber count, or nothing at all when the answer is genuinely
# unknown, which the caller must not mistake for zero.
#
# The HTTP code has to be read as well as the body, and this is not
# defensive tidiness: nchan answers 404 with an EMPTY BODY for a channel that
# does not exist, which is the state on every boot until something publishes
# or subscribes. Reading the body alone gives nothing, "nothing" reads as
# unknown, and a watcher that treats unknown as "carry on" then runs for ever
# with nobody listening — the exact stray background process this whole
# self-terminating design exists to avoid. A 404 is not an error here: no
# channel means no subscribers, which is a real, countable zero.
subscribers() {
  out=$(curl -s -w '\n%{http_code}' --max-time 5 --unix-socket "$SOCK" \
        -H 'Accept: text/json' "$PUBURL" 2>/dev/null) || return 0
  case $(printf '%s\n' "$out" | tail -n 1) in
    200) printf '%s\n' "$out" \
           | sed -n 's/.*"subscribers":[[:space:]]*\([0-9][0-9]*\).*/\1/p' ;;
    404) echo 0 ;;
    *)   ;;                       # unreadable: say nothing, so it counts as unknown
  esac
}

# Translate one Docker action into the one word the page understands, or
# nothing at all. `create`/`destroy`/`rename` change which rows exist, so they
# get the larger "rows" refresh; the rest only change a row's own cells.
# `health_status` arrives as "health_status: healthy" (or unhealthy), hence
# the prefix match rather than an exact one.
classify() {
  case "$1" in
    create|destroy|rename)
      echo rows ;;
    start|stop|die|kill|restart|pause|unpause|health_status*)
      echo state ;;
    *)
      ;;                        # exec_create/exec_start/exec_die and the rest: dropped
  esac
}

start_producer() {
  rm -f "$FIFO"
  mkfifo -m 600 "$FIFO" 2>/dev/null || exit 1
  # Backgrounded so the fifo's write end is held open by `docker events`
  # itself rather than by this shell; opening our own read end below is what
  # lets the two rendezvous.
  "$DOCKER" events --filter type=container --format '{{.Action}}' > "$FIFO" 2>/dev/null &
  PRODUCER=$!
}

PRODUCER=""
start_producer

# Opened once for the life of the script. Read with `read -r -t 1` below
# instead of a plain blocking read: a read with no timeout would sleep
# forever on a quiet stream and this loop would never reach the subscriber
# check, so nothing would ever notice the last tab had closed. `-t` is not in
# strict POSIX but is honoured by dash, busybox ash and bash alike, and it is
# the only portable way to get a periodic wake-up out of a shell `read`.
exec 3<"$FIFO"

pending=""              # the strongest verb seen since the last publish
zeroes=0                # consecutive subscriber checks that read zero
ticks=0                 # read timeouts since the last housekeeping pass

# Everything below is counted in `read -t 1` timeouts rather than read off the
# clock, and the housekeeping only runs once every CHECK of them. Written the
# obvious way — a `date`, a `stat` and two `cat`s every time round — this
# forked four processes a second for as long as a tab stayed open, on a server
# whose whole point is running other people's containers. A tick is not a
# precise second (a burst of events makes several passes without waiting), but
# nothing here needs one: the gap between subscriber checks is allowed to
# drift, and drifting SHORTER only happens while events are flowing, which is
# exactly when somebody is listening.
while :; do
  action=""
  IFS= read -r -t 1 action <&3
  read_ok=$?

  verb=""
  [ -n "$action" ] && verb=$(classify "$action")

  if [ -n "$verb" ]; then
    # "rows" always wins within a coalescing window: it is the larger refresh
    # and covers whatever "state" would have asked for anyway.
    if [ "$verb" = rows ] || [ -z "$pending" ]; then
      pending="$verb"
    fi
  fi

  # A timeout, not a line: one tick of the housekeeping clock. A read that
  # returned something does not tick, so a burst of events is drained as fast
  # as it arrives instead of one per second.
  if [ "$read_ok" -ne 0 ]; then
    ticks=$((ticks + 1))

    # Anything held back is sent on the first quiet moment, so a coalesced
    # burst lands within a second of the last event in it rather than waiting
    # for the housekeeping pass below.
    if [ -n "$pending" ]; then
      publish "$pending"
      pending=""
    fi
  fi

  [ "$ticks" -lt "$CHECK" ] && continue
  ticks=0

  # This script has been replaced underneath us: let the new one take over.
  [ "$(stat -c %Y "$0" 2>/dev/null)" = "$VERSION" ] || break

  # Our lock is gone, or now belongs to somebody else.
  [ -d "$LOCKDIR" ] || break
  [ "$(cat "$LOCK" 2>/dev/null)" = "$$" ] || break

  # The event reader died — the Docker daemon restarted under us, most
  # likely. Closing our end of the old fifo before making a new one matters:
  # without it, the stale fifo hangs around holding a read descriptor nothing
  # will ever write to again.
  if ! kill -0 "$PRODUCER" 2>/dev/null; then
    exec 3<&-
    start_producer
    exec 3<"$FIFO"
    continue
  fi

  subs=$(subscribers)
  case "$subs" in
    0)  zeroes=$((zeroes + 1)) ;;
    '') ;;                      # endpoint unreadable: treat as unknown, not as zero
    *)  zeroes=0 ;;
  esac
  [ "$zeroes" -ge 2 ] && break
done

cleanup
exit 0
