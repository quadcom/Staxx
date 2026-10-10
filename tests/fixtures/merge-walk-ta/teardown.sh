#!/bin/bash
# PLAN_178 — remove everything the third walkthrough made. Safe to run at any stage.
# Copyright 2026, StaXX contributors. GPL-2.0.
ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
DEST="$ROOT/stacks/TubeArchivist"
removed=""

if [ -d "$DEST" ]; then
  for d in "$DEST"/Demo-TubeArchivist "$DEST"/Demo-TubeArchivist-ES "$DEST"/Demo-TubeArchivist-Redis "$DEST"/Demo-TubeArchivist-Stack; do
    [ -d "$d" ] || continue
    cd "$d" && docker compose --profile '*' down -v --remove-orphans 2>/dev/null
    removed="$removed$d\n"
  done
  cd /
  rm -rf "$DEST"
  removed="$removed$DEST (the whole folder)\n"
fi

docker rm -f Demo-TubeArchivist Demo-TubeArchivist-ES Demo-TubeArchivist-Redis 2>/dev/null

if [ -d /mnt/user/appdata/Demo-TubeArchivist ]; then
  rm -rf /mnt/user/appdata/Demo-TubeArchivist
  removed="$removed/mnt/user/appdata/Demo-TubeArchivist\n"
fi

echo "Removed:"
echo -e "$removed"
echo "Adrian's own real Tube Archivist (/mnt/user/appdata/TubeArchivist, containers TubeArchivist / TubeArchivist-ES / TubeArchivist-RedisJSON) was never touched by this fixture."
echo "Images are left in place. If StaXX's start-order list still names TubeArchivist/Demo-TubeArchivist-Stack, open the grid once to let it tidy."
