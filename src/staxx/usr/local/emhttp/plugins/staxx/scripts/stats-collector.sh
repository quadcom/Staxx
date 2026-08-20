#!/bin/sh
# StaXX — background statistics collector.
# Copyright 2026, StaXX contributors. GPL-2.0.
#
# WHY THIS EXISTS AS A BACKGROUND JOB
#
# `docker stats --no-stream` takes about two seconds on a server with 60-odd
# containers, because it queries every one of them. Running that inside a page
# request would make the page take two seconds to answer, every few seconds,
# for as long as anyone had it open. So the sampling happens out here, and the
# page reads whatever the most recent snapshot says.
#
# WHY IT STOPS BY ITSELF
#
# This is not a daemon and installs no boot hook. The page writes the current
# time into the heartbeat file each time it asks for statistics; this loop
# stops as soon as that timestamp goes stale. Close the tab and sampling stops
# on its own within a minute, leaving nothing running and nothing to clean up.
#
# Everything is written to a temporary file and moved into place, so a reader
# either sees the previous complete snapshot or the new one, never half of one.
#
# Usage: stats-collector.sh <state-dir>

DIR="$1"
[ -n "$DIR" ] || exit 1

STALE=45          # give up if nobody has asked for stats in this many seconds
INTERVAL=1        # pause between sampling rounds

# Measured on a 62-container server: docker stats 1.5s and radeontop 1.1s.
# The Intel reading is now a handful of file reads and costs next to nothing.
# With the pause that is a round every two or three seconds, which is what the
# graphs advance at.

WATCH="$DIR/watch"
LOCK="$DIR/collector.pid"
LOCKDIR="$DIR/collector.lock"

# One collector at a time, and the lock has to be ATOMIC.
#
# Testing "does a pid file exist" and then writing one is two steps, and the
# page polls every three seconds: two requests arriving together both saw no
# lock, both started a collector, and the machine ended up sampling twice over.
# That happened here, so this is not a theoretical concern.
#
# `mkdir` either creates the directory or fails, in one indivisible step, and
# is the portable way to do this in a shell. A lock left behind by a killed
# collector is detected by checking whether its pid is still alive.
if ! mkdir "$LOCKDIR" 2>/dev/null; then
  holder=$(cat "$LOCK" 2>/dev/null)
  if [ -n "$holder" ] && kill -0 "$holder" 2>/dev/null; then
    exit 0                      # a live collector already has it
  fi

  # No pid recorded yet does NOT mean the lock is abandoned. The winner writes
  # its pid a moment AFTER taking the directory, and in that gap a loser sees a
  # lock with no owner. Treating that as stale is how two collectors ended up
  # running at once: the loser deleted a perfectly good lock and took it.
  #
  # A lock younger than this is assumed to belong to a collector still starting
  # up. Only an old lock with no living owner is genuinely abandoned.
  created=$(stat -c %Y "$LOCKDIR" 2>/dev/null || echo 0)
  if [ $(( $(date +%s) - created )) -lt 30 ]; then
    exit 0
  fi

  rm -rf "$LOCKDIR"             # stale: the holder is gone for good
  mkdir "$LOCKDIR" 2>/dev/null || exit 0
fi
echo $$ > "$LOCK"

# Release the lock ONLY if we still hold it.
#
# An unconditional release is a bug that took a while to see: a collector
# shutting down would delete whatever lock was present, including one a newer
# collector had just taken. Each exit then handed the lock to the next arrival
# and the count crept upward — four were running at once before this was
# spotted. Checking the recorded pid against our own makes the release safe.
cleanup() {
  if [ "$(cat "$LOCK" 2>/dev/null)" = "$$" ]; then
    rm -f "$LOCK"
    rmdir "$LOCKDIR" 2>/dev/null
  fi
}
trap cleanup EXIT INT TERM

# Stand down when this script itself is updated.
#
# Without this, a collector started before an upgrade keeps running the old
# code indefinitely: the "one collector at a time" check above sees a live
# process and declines to start the new one, so a fixed collector never takes
# over until the machine reboots. Noticing our own mtime change and exiting
# lets the next page request start the new version.
VERSION=$(stat -c %Y "$0" 2>/dev/null)

# Per-process GPU time, straight from the kernel.
#
# THIS IS THE MEASUREMENT THAT MATTERS, and it took a while to find. Every
# process holding a /dev/dri file has counters in /proc/<pid>/fdinfo/<fd>:
#
#     drm-driver:      amdgpu
#     drm-pdev:        0000:10:00.0
#     drm-client-id:   39898
#     drm-engine-enc:  14406597734 ns
#
# The engine figures are nanoseconds of GPU time used, counting upward, so the
# share of a card a container is using is the change over the time between two
# samples. It works for Intel and AMD alike, needs no external tool, and is
# attributable to a process — and therefore to a container.
#
# It also sees what nothing else here does. During a real AMD encode, both
# radeontop and the kernel's own gpu_busy_percent read 0%, because they watch
# the graphics pipe and the work was on the video ENCODE engine. fdinfo showed
# it climbing the whole time. On a media server that is the only case anybody
# actually cares about, so the machine-wide numbers cannot be relied on.
#
# Only containers that have a GPU are examined, which is a handful, so this
# stays cheap however many containers are running.
sample_gpu_procs() {
  [ -f "$DIR/devices.raw" ] || return 0

  out="$DIR/gpuproc.raw.tmp"
  # Nanosecond timestamp: the rate depends on the exact gap between samples.
  date +%s%N > "$out"

  while IFS='	' read -r id devs reqs priv; do
    case "$devs" in
      *dev/dri*|*dev/nvidia*) ;;
      *) continue ;;
    esac

    procs="/sys/fs/cgroup/docker/$id/cgroup.procs"
    [ -r "$procs" ] || continue

    for p in $(cat "$procs" 2>/dev/null); do
      for fd in /proc/$p/fdinfo/*; do
        [ -r "$fd" ] || continue
        grep -qs '^drm-client-id' "$fd" 2>/dev/null || continue

        # One line per engine. drm-driver, drm-pdev and drm-client-id always
        # appear before the engine lines, so they are known by the time each
        # is printed. "capacity" lines are engine counts, not time, and are
        # skipped.
        awk -v cid="$id" '
          /^drm-driver:/    { drv = $2 }
          /^drm-pdev:/      { pdev = $2 }
          /^drm-client-id:/ { client = $2 }
          /^drm-engine-/ {
            name = $1
            sub(/^drm-engine-/, "", name)
            sub(/:$/, "", name)
            if (name !~ /^capacity/) print "e", cid, drv, pdev, client, name, $2
          }
        ' "$fd" >> "$out" 2>/dev/null
      done
    done
  done < "$DIR/devices.raw"

  # Keep the previous sample: a rate needs two.
  [ -f "$DIR/gpuproc.raw" ] && mv "$DIR/gpuproc.raw" "$DIR/gpuproc.prev"
  mv "$out" "$DIR/gpuproc.raw"
}

# How many separate pieces of work each card is running, machine-wide.
#
# The same counter for both cards, deliberately. The two are otherwise measured
# by different tools that count different things, and a header line where one
# card says "idle" while the other says "0%" is reporting the same state two
# ways — which reads as though they differ.
#
# A drm-client-id is one context on the card, not one process: a process opens
# the card several times and every one of those descriptors carries the SAME
# client id, so distinct ids are the honest unit. See sample_gpu_procs().
#
# The kernel already keeps this list, at /sys/kernel/debug/dri/<n>/clients, and
# reading the lot costs TWO MILLISECONDS. The obvious alternatives are all far
# worse and one of them is simply wrong:
#
#   fuser on /dev/dri/*        0.19s, and MISSES EVERY CONTAINER. Docker gives
#                              a container its own device node with the same
#                              major and minor but a different inode, and fuser
#                              matches on the inode. It reported nothing at all
#                              while a hardware encode was running.
#   grep every /proc/*/fdinfo  1.8s, correct but a third of a sampling round.
#   a shell loop over /proc    39 seconds. Not an option.
#
# The debugfs tree lists the same client under several directories, so the file
# format is: one line per client, columns command/tgid/dev/.../id, with a header
# line repeated in each file. Deduplicating on device-and-id collapses them.
sample_gpu_clients() {
  out="$DIR/gpuclients.raw.tmp"

  # `c <drm minor> <client id>`. The minor is turned into a vendor by the
  # reader, which already knows which card is which — see staxx_gpu_nodes().
  awk '$1 != "command" && $2 ~ /^[0-9]+$/ && $NF ~ /^[0-9]+$/ { print "c", $3, $NF }' \
    /sys/kernel/debug/dri/*/clients 2>/dev/null | sort -u > "$out"

  mv "$out" "$DIR/gpuclients.raw"
}

# Sample the Intel GPU from sysfs — read-only, nothing attached to the card
# and nothing to kill.
#
# WHY THIS REPLACED intel_gpu_top: that tool attaches to the i915 perf/PMU
# interface, and it was being started and then SIGTERMed/SIGKILLed under a
# timeout every few seconds, for as long as any StaXX page stayed open —
# indefinitely, on a server that never closes its Docker tab. On this
# hardware the card doing that is a discrete Intel Arc that also does the
# Plex hardware transcode, and the user has seen GPU crashes and freezes.
# Never proven as the cause, but repeatedly attaching a performance monitor
# to a card and killing it hard is a credible one, and there is no need to
# carry that risk for a number plain file reads already give.
#
# The idle-residency counter counts upward in milliseconds, so busy% is the
# inverse of how much of the elapsed wall time it grew by — see
# staxx_gpu_busy_percent() in Stats.php for the exact sum, and gpu.php for
# the proof it is right (verified on the box: 2002ms idle of 2004ms elapsed
# -> 0% busy, with the clock reading 0 while idle).
#
# Not every i915 generation exposes the same path. Newer ones publish
# gt/gt0/rc6_residency_ms; older ones publish it under power/ instead — both
# are tried, in that order, and the first that exists wins for that card.
#
# Follows the same "keep the previous reading so the caller can take a
# difference" idiom as sample_gpu_procs() above, so there is one shape for
# this kind of counter rather than two: the old reading moves to *.prev
# before the new one is written.
sample_intel() {
  out="$DIR/intel.raw.tmp"
  # First line is the timestamp, exactly like gpuproc.raw above: a millisecond
  # clock read at the same moment as the residency counters below it, so the
  # reader has a real elapsed time to divide by rather than assuming the
  # collector's cycle length.
  date +%s%3N > "$out"

  for card in /sys/class/drm/card*; do
    [ -d "$card/device" ] || continue
    vendor=$(cat "$card/device/vendor" 2>/dev/null)
    [ "$vendor" = "0x8086" ] || continue

    node=$(basename "$card")

    residency=""
    for base in "$card/gt/gt0" "$card/power"; do
      if [ -r "$base/rc6_residency_ms" ]; then
        residency=$(cat "$base/rc6_residency_ms" 2>/dev/null)
        break
      fi
    done
    [ -n "$residency" ] || continue

    freq=$(cat "$card/gt/gt0/rps_act_freq_mhz" 2>/dev/null)
    max=$(cat "$card/gt/gt0/rps_max_freq_mhz" 2>/dev/null)

    echo "i $node $residency ${freq:-0} ${max:-0}" >> "$out"
  done

  # Keep the previous sample: the busy figure is a rate, and a rate needs two
  # readings and the time between them.
  [ -f "$DIR/intel.raw" ] && mv "$DIR/intel.raw" "$DIR/intel.prev"
  mv "$out" "$DIR/intel.raw"
}

# The AMD card's own metrics table, sampled repeatedly.
#
# WHY THIS EXISTS: radeontop and the kernel's gpu_busy_percent both watch the
# graphics pipe, and a video transcode does not touch it. Measured here during
# a flat-out VAAPI encode, both read 0% while this table read 100%. On a media
# server that is the only figure anybody wants.
#
# SAMPLED REPEATEDLY, and that is the point of doing it here. The table holds
# an instant, not an average. A real-time transcode finishes each frame well
# inside the frame interval, so a single read genuinely catches the encoder
# idle about half the time — measured at 1080p60 the readings ran
# 10000,0,10000,0,9700,0,9900 and so on. One read is a coin toss between "busy"
# and "idle"; twenty of them across a second measure how much of that second
# the engine was working, which is the figure wanted.
#
# It also costs nothing, because this REPLACES the pause at the end of the
# round rather than adding to it. Reading the file is a few microseconds; the
# second was going to be spent sleeping anyway.
#
# The bytes are passed through as hex and decoded by the reader. The table is a
# versioned binary structure and where the fields sit depends on the version,
# so that knowledge lives in one place, in Stats.php, where it can be checked
# against the size the table declares for itself.
sample_gpu_metrics() {
  out="$DIR/gpumetrics.raw.tmp"
  : > "$out"

  n=0
  while [ $n -lt 20 ]; do
    for f in /sys/class/drm/card*/device/gpu_metrics; do
      [ -r "$f" ] || continue
      node=$(basename "$(dirname "$(dirname "$f")")")
      minor=$(cut -d: -f2 "/sys/class/drm/$node/dev" 2>/dev/null)
      hex=$(od -An -tx1 -v "$f" 2>/dev/null | tr -d ' \n')
      [ -n "$minor" ] && [ -n "$hex" ] && echo "g $minor $hex" >> "$out"
    done
    n=$((n + 1))
    # Unconditional, so a machine with no such card still pauses here rather
    # than spinning through the whole round with no wait at all.
    #
    # Not faster than this: the driver caches the table for about a
    # millisecond, so reads closer together than that return the same numbers
    # twice and would bias the result rather than refine it.
    sleep 0.05
  done

  mv "$out" "$DIR/gpumetrics.raw"
}

# radeontop reports the card as a whole and has no per-process breakdown, so
# this figure is the machine's, not any one container's. The page labels it
# that way rather than pretending otherwise.
sample_amd() {
  command -v radeontop >/dev/null 2>&1 || return 1
  timeout -k 1 5 radeontop -d - -l 1 > "$DIR/amd.raw.tmp" 2>/dev/null
  if [ -s "$DIR/amd.raw.tmp" ]; then
    mv "$DIR/amd.raw.tmp" "$DIR/amd.raw"
  else
    rm -f "$DIR/amd.raw.tmp"
  fi
}

while [ -f "$WATCH" ]; do
  now=$(date +%s)
  seen=$(cat "$WATCH" 2>/dev/null)
  case "$seen" in
    ''|*[!0-9]*) break ;;                       # unreadable heartbeat: stop
  esac
  [ $((now - seen)) -gt $STALE ] && break        # nobody watching: stop

  # This script has been replaced underneath us: let the new one take over.
  [ "$(stat -c %Y "$0" 2>/dev/null)" = "$VERSION" ] || break

  # Our lock is gone, or now belongs to somebody else. That happens if the
  # state directory is cleared while we are running, which lets a second
  # collector start alongside us. Whoever holds the lock keeps going; we stop.
  [ -d "$LOCKDIR" ] || break
  [ "$(cat "$LOCK" 2>/dev/null)" = "$$" ] || break

  # One call covers every container. Filtering to the stacks on screen would
  # not be cheaper — docker walks them all regardless.
  # EVERY docker call here is wrapped in `timeout`, and that is not belt and
  # braces — it is load bearing.
  #
  # `docker stats` can hang indefinitely. When it did, this loop sat blocked on
  # the pipe forever: the heartbeat went stale but the loop never reached the
  # check, so it never stopped. Worse, it could not be killed with SIGTERM
  # either, because a shell defers a trapped signal until the running command
  # finishes and that command never finished. One collector survived every
  # attempt to stop it for fourteen minutes.
  #
  # A timeout turns that from a permanently stuck process into one slow round.
  if timeout -k 5 30 docker stats --no-stream --format '{{json .}}' > "$DIR/docker.raw.tmp" 2>/dev/null; then
    mv "$DIR/docker.raw.tmp" "$DIR/docker.raw"
    date +%s > "$DIR/docker.at"
  else
    rm -f "$DIR/docker.raw.tmp"
  fi

  # `docker stats` gives no compose label, so the mapping from container to
  # stack is captured here too. Doing it in the collector keeps the page
  # request free of any docker call at all.
  if timeout -k 5 15 docker ps --no-trunc \
       --format '{{.ID}}	{{.Names}}	{{.Label "com.docker.compose.project"}}	{{.Label "com.docker.compose.service"}}' \
       > "$DIR/ps.raw.tmp" 2>/dev/null; then
    mv "$DIR/ps.raw.tmp" "$DIR/ps.raw"
  else
    rm -f "$DIR/ps.raw.tmp"
  fi

  # Which containers actually have a GPU handed to them. Inspecting all of
  # them costs under a tenth of a second, so it is refreshed every round
  # rather than cached and risked going out of date when one is recreated.
  #
  # Privileged is recorded but is NOT by itself a GPU mapping: a container is
  # usually privileged for disk or network reasons and flagging every one of
  # them as a GPU user would fill the column with containers that never touch
  # it. The reader decides — see Stats.php.
  ids=$(timeout -k 5 15 docker ps -q 2>/dev/null)
  if [ -n "$ids" ]; then
    # shellcheck disable=SC2086
    # `range` over both lists, never `len`.
    #
    # DeviceRequests is null on any container that never asked for a GPU —
    # which is nearly all of them — and Go's template `len` raises "len of nil
    # pointer" on null, failing the whole command for every container at once.
    # `range` over null simply produces nothing, which is the answer wanted.
    if timeout -k 5 15 docker inspect $ids --format \
         '{{.Id}}	{{range .HostConfig.Devices}}{{.PathOnHost}},{{end}}	{{range .HostConfig.DeviceRequests}}request,{{end}}	{{.HostConfig.Privileged}}' \
         > "$DIR/devices.raw.tmp" 2>/dev/null; then
      mv "$DIR/devices.raw.tmp" "$DIR/devices.raw"
    else
      rm -f "$DIR/devices.raw.tmp"
    fi
  fi

  # Must run after devices.raw is refreshed above — it drives which containers
  # are worth looking at.
  sample_gpu_procs
  sample_gpu_clients

  sample_intel
  sample_amd

  # This is the pause at the end of the round, spent reading the AMD metrics
  # table over and over instead of doing nothing. It takes the same second the
  # bare `sleep $INTERVAL` used to.
  sample_gpu_metrics
done

cleanup
exit 0
