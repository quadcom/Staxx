#!/bin/bash
# StaXX — run one tests/server suite with STORE_ROOT pointed at a scratch store
# under /tmp, and put the real value back on every exit path. Developer tooling,
# never packaged. Copyright 2026, StaXX contributors. GPL-2.0.
#
#   bash /tmp/run-with-store.sh /tmp/<scratch-store> /tmp/<suite>.php
#   STAXX_FOO=1 bash /tmp/run-with-store.sh …    the environment reaches php as it is
#
# One backup per run, under its own name, so a run after a failed one can never
# copy a redirected file over the real one's backup. It refuses to start if the
# store is already pointed at /tmp, and proves the restore by the VALUE of
# STORE_ROOT and a byte comparison with that run's own backup. Prints only
# STORE_ROOT, never the file: the settings on this box hold a live token.
#
# Exit: the suite's own code; 2 for a refusal before anything changed; 3 when
# the restore could not be proved (the backup is kept and its path printed);
# 130 when interrupted.

set -u
CFG="${STAXX_POINTER_CFG:-/boot/config/plugins/staxx/staxx.cfg}"
SCRATCH="${1:-}"
SUITE="${2:-}"

# Quoted or not: an unquoted value read as blank would slip past the /tmp refusal.
store_root() { sed -n 's/^STORE_ROOT="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CFG" | head -n1; }

case "$SCRATCH" in
  /tmp/*[!/]*) ;;
  *) echo "refusing: the scratch store must be a folder under /tmp, not '${SCRATCH}'"; exit 2 ;;
esac
case "$SCRATCH" in
  *[\"\#\ ]*|*..*) echo "refusing: the scratch path may not hold a quote, a #, a space or .."; exit 2 ;;
esac
[ -f "$SUITE" ] || { echo "refusing: no suite at '${SUITE}'"; exit 2; }
[ -f "$CFG" ]   || { echo "refusing: no config at ${CFG}"; exit 2; }

REAL="$(store_root)"
case "$REAL" in
  /tmp/*) echo "refusing: STORE_ROOT already points at ${REAL}, so an earlier run did not put it back. Restore the real value by hand first."; exit 2 ;;
esac

BAK="$(mktemp /tmp/staxx-cfg.XXXXXX)" || exit 2
cp -p "$CFG" "$BAK" || { rm -f "$BAK"; exit 2; }

RC=0
restore() {
  cp "$BAK" "$CFG"
  NOW="$(store_root)"
  if [ "$NOW" = "$REAL" ] && cmp -s "$BAK" "$CFG"; then
    echo "STORE_ROOT restored: ${REAL:-<blank>}"
    rm -f "$BAK"
  else
    echo "RESTORE NOT PROVED: STORE_ROOT reads '${NOW}', expected '${REAL}'. Backup kept at ${BAK}."
    RC=3
  fi
  exit "$RC"
}
trap restore EXIT
trap 'RC=130; exit' INT TERM HUP

if grep -q '^STORE_ROOT=' "$CFG"; then
  sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"${SCRATCH}\"#" "$CFG"
else
  echo "STORE_ROOT=\"${SCRATCH}\"" >> "$CFG"
fi
[ "$(store_root)" = "$SCRATCH" ] || { echo "the redirect did not take; the suite was not run"; RC=2; exit; }

php "$SUITE"
RC=$?
