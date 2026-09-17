#!/bin/bash
# PLAN_156 — remove everything the walkthrough made. Safe to run at any stage.
ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
DEST="$ROOT/stacks/DEV-TESTING"
for d in "$DEST"/t155-web "$DEST"/t155-db "$DEST"/t155-cache "$DEST"/t155-admin "$ROOT"/stacks/T155-MERGED/t155-site; do
  [ -d "$d" ] || continue
  cd "$d" && docker compose --profile '*' down --remove-orphans 2>/dev/null
done
docker volume rm -f t155-web_data t155-db_data t155-cache_data 2>/dev/null
docker rmi -f t155-web-php t155-site-php 2>/dev/null
rm -rf "$DEST"/t155-* "$ROOT"/stacks/T155-MERGED /mnt/user/appdata/staxx-testing/t155-db /mnt/user/appdata/staxx-testing/shared
echo "Gone. If StaXX's start-order list still names T155-MERGED, open the grid once to let it tidy."
