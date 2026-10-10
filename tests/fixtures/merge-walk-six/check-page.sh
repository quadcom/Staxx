#!/bin/bash
# PLAN_169 — walk every address the six-stack walkthrough publishes and say whether each one
# still answers. Copyright 2026, StaXX contributors. GPL-2.0.
#
# Usage: bash check-page.sh [api-db-address] [idle-port]
#   before the merge:  bash check-page.sh                  ("idle" was never started — see
#                                                            install.sh — so 19090 is the
#                                                            broker's alone here)
#   after the merge:   bash check-page.sh db:5432 20000     (whichever port the merge moved
#                                                            "idle" to; the broker keeps 19090)
#
# Every check is found by the image it runs or the address it answers on, never by a fixed
# container name — a merge is free to rename anything, and this script must still find it.
IP=$(hostname -I | awk '{print $1}')
DB_ADDR=${1:-$IP:19432}
IDLE_PORT=${2:-}
ok=0

port_open() { timeout 3 bash -c "cat < /dev/null > /dev/tcp/$1/$2" 2>/dev/null; }

# 1. the static site, through the edge
if curl -s --max-time 10 "http://$IP:19080/" | grep -q "T169-SITE-MARKER-OK"; then
  echo "pass: static site via the edge (:19080/)"
else
  echo "FAIL: static site via the edge (:19080/)"; ok=1
fi

# 2. the API, through the edge -> Postgres
API_BODY=$(curl -s --max-time 10 "http://$IP:19080/api/greetings")
if [ "$(echo "$API_BODY" | grep -o '"id"' | wc -l)" = "3" ]; then
  echo "pass: three greetings via the edge (:19080/api/greetings)"
else
  echo "FAIL: three greetings via the edge (:19080/api/greetings) — got: $API_BODY"; ok=1
fi

# 2b. the API's own recorded database address — proof it now reaches the database by service
# name rather than the box's own address, once the merge has run
REST_CID=$(docker ps -q --filter "ancestor=postgrest/postgrest:v12.2.3" | head -n1)
if [ -n "$REST_CID" ] && docker inspect "$REST_CID" --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -q "PGRST_DB_URI=postgres://[^@]*@$DB_ADDR/"; then
  echo "pass: the API's own database address reads $DB_ADDR"
else
  echo "FAIL: the API's own database address does not read $DB_ADDR"; ok=1
fi

# 3. the edge's own dashboard
CODE=$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' "http://$IP:19081/")
if echo "$CODE" | grep -q '^[23]'; then
  echo "pass: the edge's own dashboard (:19081/)"
else
  echo "FAIL: the edge's own dashboard (:19081/) — got HTTP $CODE"; ok=1
fi

# 4. Postgres itself
PG_CID=$(docker ps -q --filter "ancestor=postgres:16-alpine" | head -n1)
if [ -n "$PG_CID" ] && docker exec "$PG_CID" psql -U t169 -d t169 -tAc "select count(*) from greetings;" 2>/dev/null | grep -q '^3$'; then
  echo "pass: Postgres has the three seeded rows"
else
  echo "FAIL: Postgres does not answer with three seeded rows"; ok=1
fi

# 5. the broker's retained message
MQ_CID=$(docker ps -q --filter "ancestor=eclipse-mosquitto:2" | head -n1)
if [ -n "$MQ_CID" ] && docker exec "$MQ_CID" mosquitto_sub -h localhost -p 1883 -t t169/status -C 1 -W 3 2>/dev/null | grep -q '^ready$'; then
  echo "pass: the broker's retained message still reads ready"
else
  echo "FAIL: the broker's retained message does not read ready"; ok=1
fi

# 6. the site's own debug page, on its own address
if curl -s --max-time 10 "http://127.0.0.2:19800/" | grep -q "T169-SITE-MARKER-OK"; then
  echo "pass: the site's own debug page (127.0.0.2:19800)"
else
  echo "FAIL: the site's own debug page (127.0.0.2:19800)"; ok=1
fi

# 7. Dozzle's own debug page, on its own address — the same port as the line above, which is
# not a clash since each is on an address of its own (Linux answers on 127.0.0.2/.3 with no
# setup of its own)
CODE=$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' "http://127.0.0.3:19800/")
if echo "$CODE" | grep -q '^2'; then
  echo "pass: Dozzle's own debug page (127.0.0.3:19800)"
else
  echo "FAIL: Dozzle's own debug page (127.0.0.3:19800) — got HTTP $CODE"; ok=1
fi

# 8. the broker's websocket listener — t169-bus keeps 19090 unchanged by the merge, so this is
# checked every time, before and after
if port_open "$IP" 19090; then
  echo "pass: the broker's websocket listener answers on :19090"
else
  echo "FAIL: the broker's websocket listener does not answer on :19090"; ok=1
fi

# 9. t169-tools's own "idle" — install.sh deliberately never starts it before the merge (it
# clashes with the broker's own 19090), so this is only checked once its port is known
if [ -n "$IDLE_PORT" ]; then
  if curl -s --max-time 10 "http://$IP:$IDLE_PORT/" | grep -q "T169-IDLE-MARKER-OK"; then
    echo "pass: idle's own page answers on :$IDLE_PORT"
  else
    echo "FAIL: idle's own page does not answer on :$IDLE_PORT"; ok=1
  fi
else
  echo "skip: idle's own page — not started before the merge (pass its port as the second argument once merged)"
fi

exit $ok
