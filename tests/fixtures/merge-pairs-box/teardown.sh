#!/bin/bash
# PLAN_169 — remove everything install.sh (or a walk built from it) made. Safe to run at any stage.
# Copyright 2026, StaXX contributors. GPL-2.0.
#
# Only ever touches things named r2-* or r2m-* inside DEV-TESTING (or R2-TORTURE, if some walk
# created one by hand) plus the shared/ folder install.sh itself writes — nothing else in
# DEV-TESTING is read or removed.
ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
[ -n "$ROOT" ] || { echo "STORE_ROOT is blank; StaXX has no store yet."; exit 0; }
DEST="$ROOT/stacks/DEV-TESTING"
[ -d "$DEST" ] || { echo "Nothing to do; $DEST does not exist."; exit 0; }

remove_matching() {
  local dir="$1"
  [ -d "$dir" ] || return 0
  shopt -s nullglob nocaseglob
  for d in "$dir"/r2-* "$dir"/R2-* "$dir"/r2m-*; do
    [ -d "$d" ] || continue
    ( cd "$d" && docker compose --profile '*' down -v --remove-orphans >/dev/null 2>&1 )
    rm -rf "$d"
  done
  shopt -u nullglob nocaseglob
}

remove_matching "$DEST"
remove_matching "$ROOT/stacks/R2-TORTURE"
rmdir "$ROOT/stacks/R2-TORTURE" 2>/dev/null || true

# install.sh's own shared/ support files (r2-include-extends' ../shared/*.yaml) — named
# explicitly, so nothing else anyone put in DEV-TESTING/shared/ is ever touched. rmdir only
# succeeds once the folder holds nothing else, same rule as R2-TORTURE above.
rm -f "$DEST/shared/common.yaml" "$DEST/shared/base.yaml"
rmdir "$DEST/shared" 2>/dev/null || true

echo "Gone. If StaXX's start-order list still names any r2-* or r2m-* stack, open the grid once to let it tidy."
