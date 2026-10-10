#!/bin/bash
# PLAN_156 — put the four walkthrough stacks into the store and start them.
# Usage: bash install.sh            (from the folder holding the four t155-* fixture folders)
set -e
ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
[ -n "$ROOT" ] || { echo "STORE_ROOT is blank; StaXX has no store yet."; exit 1; }
DEST="$ROOT/stacks/DEV-TESTING"
[ -d "$DEST" ] || { echo "$DEST does not exist; DEV-TESTING is the only folder this may write into."; exit 1; }
for p in 18080 18443 18081 13306 16379; do
  ss -ltn | grep -q ":$p " && { echo "Port $p is already in use on this box. Refusing."; exit 1; }
done
for s in t155-web t155-db t155-cache t155-admin; do
  [ -e "$DEST/$s" ] && { echo "$DEST/$s already exists. Run teardown.sh first."; exit 1; }
done
HERE=$(cd "$(dirname "$0")" && pwd)
for s in t155-web t155-db t155-cache t155-admin; do cp -r "$HERE/$s" "$DEST/$s"; done
# The fixtures arrive via the flash drive, where every file reads as owner-only whatever its
# mode was; copied as-is nothing inside a container (nginx, mysql, redis) can read them.
chmod -R u=rwX,go=rX "$DEST"/t155-*
# The two stacks that dial the others through the server's own address get it here, not in the
# committed fixture: a real LAN address must never sit in the repository.
IP=$(hostname -I | awk '{print $1}')
sed -i "s/__BOX_IP__/$IP/g" "$DEST/t155-web/.env" "$DEST/t155-admin/.env"
mkdir -p /mnt/user/appdata/staxx-testing/t155-db/backups
# The file t155-web maps in from OUTSIDE its own folder lives in appdata at a real absolute path,
# never as a relative climb out of the store (Adrian, 2026-09-16: "../DEV-TESTING/t155-shared/..."
# in the merged file read as the shared file being moved into DEV-TESTING; a walk fixture should
# look like a real setup). The relative-path rewrite itself stays covered off the box, in
# tests/merge_examine.js's depth-path section.
mkdir -p /mnt/user/appdata/staxx-testing/shared
cp "$HERE/appdata-shared/dhparam.pem" /mnt/user/appdata/staxx-testing/shared/dhparam.pem
chmod 0644 /mnt/user/appdata/staxx-testing/shared/dhparam.pem

# Things git cannot carry: the certificate pair, the 12MB blob, the symlink, the icons, the modes.
W="$DEST/t155-web"
mkdir -p "$W/certs" "$W/data"
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=t155" \
  -keyout "$W/certs/server.key" -out "$W/certs/server.crt" 2>/dev/null
chmod 0600 "$W/certs/server.key"
dd if=/dev/zero of="$W/data/blob.bin" bs=1M count=12 status=none
ln -s default.conf "$W/conf/current.conf"
# Four different pictures under one filename (trap 14). The demo pair's icons where present,
# a generated one-colour PNG otherwise, so the grid never shows the same picture four times.
ICONS="$DEST/demo-web/.staxx/nginx.png $DEST/demo-db/.staxx/mariadb.png"
# Fallback colours, one 32x32 PNG per stack, so a missing demo icon still never repeats a picture.
PNG_RED="iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKklEQVR42mO4o6FBU8QwasGoBaMWjFowasGoBaMWjFowasGoBaMWDBULAIahsD0l4r5QAAAAAElFTkSuQmCC"
PNG_GREEN="iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKklEQVR42mPQ2BJAU8QwasGoBaMWjFowasGoBaMWjFowasGoBaMWDBULABZ0sD0prtT4AAAAAElFTkSuQmCC"
PNG_BLUE="iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKklEQVR42mPQiLpDU8QwasGoBaMWjFowasGoBaMWjFowasGoBaMWDBULAK1aeEwg+9jvAAAAAElFTkSuQmCC"
PNG_AMBER="iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKklEQVR42u3NQQkAAAgEsAthf0xnDlP4EAb7L9N1KgKBQCAQCAQCgeBLsBsEaFvq2mymAAAAAElFTkSuQmCC"
FALLBACKS="$PNG_RED $PNG_GREEN $PNG_BLUE $PNG_AMBER"
i=0
for s in t155-web t155-db t155-cache t155-admin; do
  mkdir -p "$DEST/$s/.staxx"
  src=$(echo $ICONS | cut -d' ' -f$((i+1)))
  if [ -n "$src" ] && [ -f "$src" ]; then cp "$src" "$DEST/$s/.staxx/icon.png"
  else echo "$(echo $FALLBACKS | cut -d' ' -f$((i+1)))" | base64 -d > "$DEST/$s/.staxx/icon.png"; fi
  i=$((i+1))
done
chown -R nobody:users "$DEST"/t155-*

# Start them in dependency order. This pulls nginx, php (and builds on it), mariadb, redis, adminer.
cd "$DEST/t155-db"    && docker compose up -d
cd "$DEST/t155-cache" && docker compose up -d
cd "$DEST/t155-web"   && docker compose up -d --build
cd "$DEST/t155-admin" && docker compose up -d
echo "Waiting for the database to come up..."
for n in $(seq 1 30); do
  docker inspect -f '{{.State.Health.Status}}' t155-mariadb 2>/dev/null | grep -q healthy && break
  sleep 2
done
bash "$HERE/check-page.sh"
