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

# Measured on a 62-container server: docker stats takes about 1.5s, which
# paces the round on its own — see PLAN_114 for why nothing here adds to it
# any more. The whole-machine Intel and AMD readings this collector used to
# spend a further second on fed only the strip above the table, which is
# gone; a stack's GPU vendor now comes from its own compose file instead
# (staxx_compose_gpu_vendors() in Devices.php), read once at row render.

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
  # A round killed mid-sample leaves its *.raw.tmp half-written; nothing else
  # ever cleans those up, so left alone they sit in the state directory
  # forever alongside the real snapshots.
  rm -f "$DIR"/*.raw.tmp
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
#
# A `stat` that fails here (a busy filesystem, say) must not be compared
# against a later, successful reading — that reads as "replaced" and exits
# after a single round — so an empty reading skips the check entirely rather
# than being compared as-is.
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

  # Keep the previous sample: a rate needs two. Copied, not moved — moving it
  # away first leaves a window with no .raw at all until the line below
  # replaces it, which is a real gap a reader can land in, not just a
  # theoretical one (a cosmetic flicker in the graph).
  [ -f "$DIR/gpuproc.raw" ] && cp "$DIR/gpuproc.raw" "$DIR/gpuproc.prev"
  mv "$out" "$DIR/gpuproc.raw"
}

while [ -f "$WATCH" ]; do
  now=$(date +%s)
  seen=$(cat "$WATCH" 2>/dev/null)
  case "$seen" in
    ''|*[!0-9]*) break ;;                       # unreadable heartbeat: stop
  esac
  [ $((now - seen)) -gt $STALE ] && break        # nobody watching: stop

  # This script has been replaced underneath us: let the new one take over.
  # Skipped when VERSION itself could not be read — see the comment by it.
  if [ -n "$VERSION" ] && [ "$(stat -c %Y "$0" 2>/dev/null)" != "$VERSION" ]; then
    break
  fi

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
done

cleanup
exit 0
