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
exit $ok
