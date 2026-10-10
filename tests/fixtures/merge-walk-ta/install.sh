#!/bin/bash
# PLAN_178 — put the third walkthrough's three Tube Archivist stacks into the store and start
# them the way Community Applications would have, one container per template.
# Copyright 2026, StaXX contributors. GPL-2.0.
# Usage: bash install.sh   (from the folder holding Demo-TubeArchivist, -ES and -Redis)
set -e
ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
[ -n "$ROOT" ] || { echo "STORE_ROOT is blank; StaXX has no store yet."; exit 1; }
DEST="$ROOT/stacks/TubeArchivist"
[ -e "$DEST" ] && { echo "$DEST already exists. Run teardown.sh first — this fixture refuses to write into a folder that is already there."; exit 1; }

for p in 17800 17920 17637; do
  ss -ltn | grep -q ":$p " && { echo "Port $p is already in use on this box. Refusing."; exit 1; }
done

HERE=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$DEST"
for s in Demo-TubeArchivist Demo-TubeArchivist-ES Demo-TubeArchivist-Redis; do cp -r "$HERE/$s" "$DEST/$s"; done
# The fixtures arrive via the flash drive, where every file reads as owner-only whatever its
# mode was; copied as-is nothing inside a container can read them.
chmod -R u=rwX,go=rX "$DEST"

# The box's own address is filled in here, not in the committed fixture — a real LAN address
# must never sit in the repository. Only Demo-TubeArchivist's own file names it (TA_HOST,
# REDIS_CON, ES_URL); Demo-TubeArchivist-ES and -Redis need no address of their own.
IP=$(hostname -I | awk '{print $1}')
sed -i "s/__BOX_IP__/$IP/g" "$DEST/Demo-TubeArchivist/compose.yaml"

# Adrian's own real Tube Archivist install lives at /mnt/user/appdata/TubeArchivist — this
# fixture must never touch it, so its own appdata sits under a name that cannot collide with it.
mkdir -p /mnt/user/appdata/Demo-TubeArchivist/{cache,es,redis,youtube}
chown -R 99:100 "$DEST" /mnt/user/appdata/Demo-TubeArchivist
# Elasticsearch runs as its own user 1000, not Unraid's nobody, and cannot write its data folder
# otherwise; Tube Archivist's own install notes give this same step.
chown -R 1000:0 /mnt/user/appdata/Demo-TubeArchivist/es

# Elasticsearch and Redis first — Tube Archivist's own start-up waits on Elasticsearch, and
# nothing here waits on the app.
cd "$DEST/Demo-TubeArchivist-Redis" && docker compose up -d
cd "$DEST/Demo-TubeArchivist-ES"    && docker compose up -d

echo "Waiting for Elasticsearch to answer..."
ES_UP=0
for n in $(seq 1 60); do
  CODE=$(curl -s --max-time 2 -o /dev/null -w '%{http_code}' -u elastic:t178-test-only "http://$IP:17920/_cluster/health" || true)
  if echo "$CODE" | grep -q '^2'; then ES_UP=1; break; fi
  sleep 2
done
if [ "$ES_UP" != "1" ]; then
  echo "Elasticsearch did not answer within the time allowed. Its last log lines:"
  docker logs --tail 40 Demo-TubeArchivist-ES
  echo "This box's vm.max_map_count is left exactly as it is — raising it is Adrian's call, never this script's. Stopping."
  exit 1
fi

cd "$DEST/Demo-TubeArchivist" && docker compose up -d

echo "Waiting for Tube Archivist's own health check..."
APP_UP=0
for n in $(seq 1 90); do
  STATUS=$(docker inspect -f '{{.State.Health.Status}}' Demo-TubeArchivist 2>/dev/null || echo "")
  if [ "$STATUS" = "healthy" ]; then APP_UP=1; break; fi
  sleep 2
done
if [ "$APP_UP" != "1" ]; then
  echo "Tube Archivist did not report healthy within the time allowed. Its last log lines:"
  docker logs --tail 40 Demo-TubeArchivist
  exit 1
fi

bash "$HERE/check-page.sh"
