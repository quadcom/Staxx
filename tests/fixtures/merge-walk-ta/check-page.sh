#!/bin/bash
# PLAN_178 — walk every address the third walkthrough publishes and say whether each one still
# answers. Copyright 2026, StaXX contributors. GPL-2.0.
#
# Usage: bash check-page.sh [before|after]
#   before the merge (default): the app reaches Elasticsearch and Redis over the server's own
#                                address and their published ports.
#   after the merge:             the same five checks, plus proof the app now reaches the other
#                                two by service name instead.
#
# Every check is found by the image it runs or the address it answers on, never by a fixed
# container name — a merge is free to rename anything, and this script must still find it.
MODE=${1:-before}
IP=$(hostname -I | awk '{print $1}')
ok=0

port_open() { timeout 3 bash -c "cat < /dev/null > /dev/tcp/$1/$2" 2>/dev/null; }

APP_CID=$(docker ps -q --filter "ancestor=bbilly1/tubearchivist:latest" | head -n1)

# 1. Tube Archivist's own web page
if curl -s --max-time 10 -o /dev/null -w '%{http_code}' "http://$IP:17800/" | grep -q '^[23]'; then
  echo "pass: Tube Archivist's own web page (:17800/)"
else
  echo "FAIL: Tube Archivist's own web page (:17800/)"; ok=1
fi

# 2. the app's own health endpoint — proof its web server, not just Docker, is up
if curl -s --max-time 10 -o /dev/null -w '%{http_code}' "http://$IP:17800/api/health/" | grep -q '^2'; then
  echo "pass: Tube Archivist's own health endpoint (:17800/api/health/)"
else
  echo "FAIL: Tube Archivist's own health endpoint (:17800/api/health/)"; ok=1
fi

# 3. logging in via the app's own API — it cannot answer this without having already reached
# Elasticsearch, which is where its user store lives
# /api/user/login/ answers 204 and sets a session for the right password, 400 for a wrong one —
# both checked, so a pass means it really looked the user up rather than waving everything through.
LOGIN_OK=$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json'   -d '{"username":"tubearchivist","password":"t178-test-only"}' "http://$IP:17800/api/user/login/" || true)
LOGIN_BAD=$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json'   -d '{"username":"tubearchivist","password":"not-the-password"}' "http://$IP:17800/api/user/login/" || true)
if [ "$LOGIN_OK" = "204" ] && [ "$LOGIN_BAD" = "400" ]; then
  echo "pass: logged in via the app's own API, and a wrong password was refused (proves it reached Elasticsearch)"
else
  echo "FAIL: login via the app's own API — right password got $LOGIN_OK, wrong one got $LOGIN_BAD"; ok=1
fi

# 4. Elasticsearch itself
ES_BODY=$(curl -s --max-time 10 -u elastic:t178-test-only "http://$IP:17920/_cluster/health")
if echo "$ES_BODY" | grep -qE '"status":"(green|yellow)"'; then
  echo "pass: Elasticsearch answers, cluster status green or yellow (:17920)"
else
  echo "FAIL: Elasticsearch does not answer green or yellow (:17920) — got: $ES_BODY"; ok=1
fi

# 5. Redis itself
if timeout 3 bash -c "exec 3<>/dev/tcp/$IP/17637; echo -e 'PING\r' >&3; head -c 7 <&3" 2>/dev/null | grep -q '+PONG'; then
  echo "pass: Redis answers +PONG (:17637)"
else
  echo "FAIL: Redis does not answer +PONG (:17637)"; ok=1
fi

if [ "$MODE" = "after" ]; then
  # 6. the app's own recorded ES/Redis addresses — proof it now reaches both by service name
  # rather than the box's own address, once the merge has run
  if [ -n "$APP_CID" ] && docker inspect "$APP_CID" --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -q '^ES_URL=http://archivist-es:9200$'; then
    echo "pass: ES_URL now reads http://archivist-es:9200"
  else
    echo "FAIL: ES_URL does not read http://archivist-es:9200"; ok=1
  fi
  if [ -n "$APP_CID" ] && docker inspect "$APP_CID" --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -q '^REDIS_CON=redis://archivist-redis:6379$'; then
    echo "pass: REDIS_CON now reads redis://archivist-redis:6379"
  else
    echo "FAIL: REDIS_CON does not read redis://archivist-redis:6379"; ok=1
  fi
fi

exit $ok
