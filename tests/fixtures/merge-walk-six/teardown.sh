#!/bin/bash
# PLAN_169 — remove everything the second walkthrough made. Safe to run at any stage.
# Copyright 2026, StaXX contributors. GPL-2.0.
ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
DEST="$ROOT/stacks/DEV-TESTING"
# t169app is the name Adrian gives the merged stack on his own walks (top level); T169-MERGED/
# t169-all is the scripted walk's.
for d in "$DEST"/t169-edge "$DEST"/t169-site "$DEST"/t169-api "$DEST"/t169-store "$DEST"/t169-bus "$DEST"/t169-tools "$ROOT"/stacks/T169-MERGED/t169-all "$ROOT"/stacks/t169app; do
  [ -d "$d" ] || continue
  cd "$d" && docker compose --profile '*' down -v --remove-orphans 2>/dev/null
done
docker rm -f t169-edge t169-data 2>/dev/null
docker volume rm -f t169-store_data t169-bus_data 2>/dev/null
docker network rm t169-edge-net 2>/dev/null
rm -rf "$DEST"/t169-* "$ROOT"/stacks/T169-MERGED "$ROOT"/stacks/t169app /mnt/user/appdata/staxx-testing/t169-store
echo "Gone. If StaXX's start-order list still names T169-MERGED or t169app, open the grid once to let it tidy."
