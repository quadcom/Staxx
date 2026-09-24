#!/bin/bash
# PLAN_169 — put the six second-walkthrough stacks into the store and start them.
# Copyright 2026, StaXX contributors. GPL-2.0.
# Usage: bash install.sh            (from the folder holding the six t169-* fixture folders)
set -e
ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
[ -n "$ROOT" ] || { echo "STORE_ROOT is blank; StaXX has no store yet."; exit 1; }
DEST="$ROOT/stacks/DEV-TESTING"
[ -d "$DEST" ] || { echo "$DEST does not exist; DEV-TESTING is the only folder this may write into."; exit 1; }

for p in 19080 19081 19432 19883 19090; do
  ss -ltn | grep -q ":$p " && { echo "Port $p is already in use on this box. Refusing."; exit 1; }
done
for a in 127.0.0.2:19800 127.0.0.3:19800; do
  ss -ltn | grep -q "$a " && { echo "Address $a is already in use on this box. Refusing."; exit 1; }
done
for s in t169-edge t169-site t169-api t169-store t169-bus t169-tools; do
  [ -e "$DEST/$s" ] && { echo "$DEST/$s already exists. Run teardown.sh first."; exit 1; }
done

HERE=$(cd "$(dirname "$0")" && pwd)
for s in t169-edge t169-site t169-api t169-store t169-bus t169-tools; do cp -r "$HERE/$s" "$DEST/$s"; done
# The fixtures arrive via the flash drive, where every file reads as owner-only whatever its
# mode was; copied as-is nothing inside a container can read them.
chmod -R u=rwX,go=rX "$DEST"/t169-*

# t169-api's own database address is filled in here, not in the committed fixture — a real LAN
# address must never sit in the repository. It is written straight into the compose file
# because, unlike t169-store, t169-api carries no root .env of its own (trap 8's asymmetry).
IP=$(hostname -I | awk '{print $1}')
sed -i "s/__BOX_IP__/$IP/g" "$DEST/t169-api/compose.yaml"

mkdir -p /mnt/user/appdata/staxx-testing/t169-store/backups
chown -R nobody:users "$DEST"/t169-* /mnt/user/appdata/staxx-testing/t169-store

# The edge, t169-site and t169-api all join this network, declared external in every one of
# their files — it must exist before any of the three are started, and none of them may create
# it themselves.
docker network create t169-edge-net >/dev/null 2>&1 || true

# Start in dependency order. This pulls Traefik, nginx, PostgREST, Postgres, Mosquitto, Dozzle
# and Alpine — nothing here is built. t169-tools's own "idle" also publishes 19090, the same
# port t169-bus's broker uses — a real clash a single Docker host cannot host at once — so it
# is left out here, exactly the shape a person has when they stopped a service because it
# clashed. Its own file still declares that port, which is what the merge has to notice once
# both land in one stack.
cd "$DEST/t169-store" && docker compose up -d
cd "$DEST/t169-bus"   && docker compose up -d
cd "$DEST/t169-tools" && docker compose up -d dozzle
cd "$DEST/t169-site"  && docker compose up -d
cd "$DEST/t169-api"   && docker compose up -d
cd "$DEST/t169-edge"  && docker compose up -d

echo "Waiting for the database to come up..."
for n in $(seq 1 30); do
  docker inspect -f '{{.State.Health.Status}}' t169-data 2>/dev/null | grep -q healthy && break
  sleep 2
done

bash "$HERE/check-page.sh"
