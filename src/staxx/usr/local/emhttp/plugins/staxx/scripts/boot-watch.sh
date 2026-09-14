#!/bin/sh
# StaXX — the "live" BOOT_COPY_MODE's own listener (PLAN_103 addendum).
# Copyright 2026, StaXX contributors. GPL-2.0.
#
# WHY THIS EXISTS
#
# The flash copy in BootCopy.php is only ever refreshed by StaXX's own code —
# a save, or (PLAN_103 addendum Phase 2) the moment a job StaXX started puts
# the compose file into use. A container recreated by hand at a command line,
# after the file itself was edited outside StaXX, is invisible to both. This
# watches Docker's own event stream for exactly that and refreshes the one
# stack's copy at once, so the flash drive is never more than a moment behind.
#
# HOW IT DIFFERS FROM scripts/events-watcher.sh
#
# Both watch `docker events` and both stand down when this script is replaced
# by an upgrade. Everything else is different on purpose: that one exists to
# nudge the open page over nchan and exits itself once nobody is looking,
# because a stray background process on somebody's server is worse than a
# page that has to poll. This one is started by apply_settings (which
# re-runs at every boot) precisely because it must NOT exit when idle — a
# flash copy has to stay current whether or not anyone has the page open.
# apply_settings is what starts and stops it, matching the BOOT_COPY_MODE
# setting; this script only ever stops itself when superseded or when Docker
# is unreachable to start with.
#
# Daily sweep as backstop: apply_settings still writes the cron line
# scripts/boot-sweep.sh needs even in "live" mode, for anything that happened
# while this was down — a reboot, a crash, Docker itself restarting.
#
# Usage: boot-watch.sh <state-dir>

DIR="$1"
[ -n "$DIR" ] || exit 1

LOCK="$DIR/watcher.pid"
LOCKDIR="$DIR/watcher.lock"
FIFO="$DIR/events.fifo"
LOG="$DIR/watch.log"

mkdir -p "$DIR" 2>/dev/null

# One watcher at a time — same atomic-mkdir lock as scripts/events-watcher.sh
# and scripts/stats-collector.sh; see either for why "does a pid file exist"
# followed by writing one is not safe on its own.
if ! mkdir "$LOCKDIR" 2>/dev/null; then
  holder=$(cat "$LOCK" 2>/dev/null)
  if [ -n "$holder" ] && kill -0 "$holder" 2>/dev/null; then
    exit 0                      # a live listener already has it
  fi
  created=$(stat -c %Y "$LOCKDIR" 2>/dev/null || echo 0)
  if [ $(( $(date +%s) - created )) -lt 30 ]; then
    exit 0                      # still starting up, not abandoned
  fi
  rm -rf "$LOCKDIR"
  mkdir "$LOCKDIR" 2>/dev/null || exit 0
fi
echo $$ > "$LOCK"

cleanup() {
  # The producer is this process's own child whoever owns the lock by now, so
  # it is killed unconditionally. It used to sit inside the ownership test
  # below, and apply_settings deletes the state directory immediately after
  # signalling us — so the test failed on the way out and the `docker events`
  # child was left running, reparented to init, with the pid file already
  # gone so nothing could find it again (measured on the server, 2026-09-14).
  [ -n "$PRODUCER" ] && kill "$PRODUCER" 2>/dev/null
  # The lock and its directory may belong to a newer watcher by now, so those
  # are only removed when they are still ours.
  if [ "$(cat "$LOCK" 2>/dev/null)" = "$$" ]; then
    rm -f "$LOCK" "$FIFO"
    rmdir "$LOCKDIR" 2>/dev/null
  fi
}
trap cleanup EXIT INT TERM

# Stand down when replaced by an upgrade — same reasoning as
# scripts/events-watcher.sh: a listener started before an upgrade must not
# run the old code forever. apply_settings starts the new one on the next
# settings save or boot.
VERSION=$(stat -c %Y "$0" 2>/dev/null)

DOCKER=docker
for candidate in /usr/bin/docker /usr/local/bin/docker; do
  if [ -x "$candidate" ]; then
    DOCKER="$candidate"
    break
  fi
done

php_bin=php
for candidate in /usr/bin/php /usr/local/bin/php; do
  if [ -x "$candidate" ]; then
    php_bin="$candidate"
    break
  fi
done

PLUGIN_DIR="/usr/local/emhttp/plugins/staxx"

# Resolve the project to a stack and refresh its copy. Not coalesced across
# several containers of the same project firing at once (a compose "up"
# recreating three services fires this three times for one project) — the
# write is idempotent and comparing first would cost the same php process
# startup anyway, so the simpler shape is the lighter one here.
refresh() {
  project="$1"
  [ -n "$project" ] || return 0
  "$php_bin" -r '
    require $argv[1]."/include/Stacks.php";
    $rel = staxx_boot_resolve_project($argv[2]);
    if ($rel === "") exit(0);
    $err = "";
    if (!staxx_boot_copy_stack($rel, $err) && $err !== "") {
      fwrite(STDERR, $err."\n");
    } else {
      echo "refreshed the flash copy of ".$rel."\n";
    }
  ' -- "$PLUGIN_DIR" "$project" >> "$LOG" 2>&1
}

start_producer() {
  rm -f "$FIFO"
  mkfifo -m 600 "$FIFO" 2>/dev/null || exit 1
  # Only container create/start carrying the compose project label — a
  # restart, stop, health check and everything else in the stream is of no
  # interest here, since none of them change what the compose file says.
  "$DOCKER" events --filter type=container --filter event=create --filter event=start \
    --filter label=com.docker.compose.project \
    --format '{{index .Actor.Attributes "com.docker.compose.project"}}' > "$FIFO" 2>/dev/null &
  PRODUCER=$!
}

PRODUCER=""
start_producer
exec 3<"$FIFO"

ticks=0
while :; do
  project=""
  IFS= read -r -t 5 project <&3
  read_ok=$?

  if [ -n "$project" ]; then
    refresh "$project"
  fi

  if [ "$read_ok" -gt 128 ]; then
    ticks=$((ticks + 1))
  elif [ "$read_ok" -ne 0 ]; then
    sleep 1
    ticks=$((ticks + 1))
  fi

  # Housekeeping roughly every 30 seconds of quiet, same cadence as
  # scripts/events-watcher.sh, but never exits merely for being idle — the
  # whole point of "live" is that this keeps running with nobody watching.
  [ "$ticks" -lt 6 ] && continue
  ticks=0

  if [ -n "$VERSION" ] && [ "$(stat -c %Y "$0" 2>/dev/null)" != "$VERSION" ]; then
    break
  fi
  [ -d "$LOCKDIR" ] || break
  [ "$(cat "$LOCK" 2>/dev/null)" = "$$" ] || break

  if ! kill -0 "$PRODUCER" 2>/dev/null; then
    exec 3<&-
    start_producer
    exec 3<"$FIFO"
  fi
done

cleanup
exit 0
