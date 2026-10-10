#!/bin/bash
# PLAN_156 — fetch the walkthrough page and say whether both halves answered.
# Usage: bash check-page.sh [expected-db-address] [expected-redis-address]
#   before the merge:  bash check-page.sh            (defaults to this box's own address)
#   after the merge:   bash check-page.sh db:3306 cache:6379
IP=$(hostname -I | awk '{print $1}')
DB=${1:-$IP:13306}; RD=${2:-$IP:16379}
PAGE=$(curl -s --max-time 10 http://127.0.0.1:18080/) || { echo "FAIL: no answer on 18080"; exit 1; }
echo "$PAGE"
echo "---"
ok=0
echo "$PAGE" | grep -q "Database: ok — 3 greetings from $DB" && echo "pass: database via $DB" || { echo "FAIL: database not read via $DB"; ok=1; }
echo "$PAGE" | grep -q "Redis: ok — this page has been read [0-9]* times ($RD)" && echo "pass: redis via $RD" || { echo "FAIL: redis not read via $RD"; ok=1; }
curl -sk --max-time 10 https://127.0.0.1:18443/ >/dev/null && echo "pass: TLS answers on 18443" || { echo "FAIL: TLS on 18443"; ok=1; }
curl -s --max-time 10 http://127.0.0.1:18081/ | grep -qi adminer && echo "pass: adminer on 18081" || { echo "FAIL: adminer on 18081"; ok=1; }

# PLAN_170's own pass rule: every address that answered before the merge
# must answer after it too. The site itself now reaches the database and
# Redis by service name (checked above, through the page's own text), but
# their PUBLISHED ports are left alone by default — port-unneeded only
# stops publishing one when a person approves it — so both must still
# answer directly on the box's own address, exactly as they did standalone.
(exec 3<>"/dev/tcp/$IP/13306") 2>/dev/null && echo "pass: MariaDB's own published port ($IP:13306) still answers" \
  || { echo "FAIL: MariaDB's own published port ($IP:13306) no longer answers"; ok=1; }
exec 3<&- 2>/dev/null; exec 3>&- 2>/dev/null
(exec 3<>"/dev/tcp/$IP/16379") 2>/dev/null && echo "pass: Redis's own published port ($IP:16379) still answers" \
  || { echo "FAIL: Redis's own published port ($IP:16379) no longer answers"; ok=1; }
exec 3<&- 2>/dev/null; exec 3>&- 2>/dev/null

exit $ok
