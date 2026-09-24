#!/bin/bash
# PLAN_169 — put every off-box "round two" probe pair (tests/fixtures/merge-pairs/r2-*) into
# DEV-TESTING as real stacks, so the torture run can be walked through the actual merge wizard,
# not just buildMergedText() off the box (see tests/merge_trip.js).
# Copyright 2026, StaXX contributors. GPL-2.0.
#
# Usage: bash install.sh          (run from inside a copy of this folder on the box; it looks
#                                   for ../merge-pairs next to itself, same as tests/merge_trip.js does)
#
# Never runs docker. Nothing here is started or pulled — the walk itself (README.md) does that,
# a pair at a time, through the wizard.
#
# Special handling, and why:
#
#   Pair                       Stack path(s)                                  Why
#   -------------------------- ----------------------------------------------  -------------------------------
#   r2-project-name-casefold   R2-PROJECT-NAME-CASEFOLD-A, r2-project-name-     R2-15's trap needs two stack
#                               casefold-a  (both "-A"/"-a", not "-A"/"-B")     folders whose names are only
#                                                                               different in case, so Compose
#                                                                               folds them to one identical
#                                                                               project name. The box's store
#                                                                               is a case-sensitive filesystem,
#                                                                               so both fit inside DEV-TESTING
#                                                                               with no need for a second
#                                                                               folder (R2-TORTURE is not
#                                                                               created for this reason).
#   r2-include-extends         DEV-TESTING/shared/{common,base}.yaml           Both sources' include:/extends:
#                                                                               point at ../shared/*.yaml,
#                                                                               which nothing in the repo ships
#                                                                               (only referenced by path in the
#                                                                               off-box probe). Written once so
#                                                                               the sources — and the merged
#                                                                               stack, at the same depth — can
#                                                                               actually be read by Compose.
#   r2-secret-clash             secrets/a-creds.txt, secrets/b-creds.txt        Each side's secrets: file: is a
#                               (inside each of -a and -b)                     placeholder that ships with
#                                                                               neither fixture folder; written
#                                                                               into both sides so `docker
#                                                                               compose config -q` can resolve
#                                                                               them before and after the merge.
#   r2-self-merge                r2-self-merge-a only (no "-b")                The fixture itself has only one
#                                                                               side — R2-17 is about merging a
#                                                                               single stack, or a stack with
#                                                                               itself.
#
# Every other pair is copied plainly as r2-<name>-a and r2-<name>-b.
set -e

ROOT=$(grep '^STORE_ROOT=' /boot/config/plugins/staxx/staxx.cfg | cut -d'"' -f2)
[ -n "$ROOT" ] || { echo "STORE_ROOT is blank; StaXX has no store yet."; exit 1; }
DEST="$ROOT/stacks/DEV-TESTING"
[ -d "$DEST" ] || { echo "$DEST does not exist; DEV-TESTING is the only folder this may write into."; exit 1; }

HERE=$(cd "$(dirname "$0")" && pwd)
SRC=$(cd "$HERE/../merge-pairs" 2>/dev/null && pwd) || { echo "../merge-pairs was not found next to this script."; exit 1; }

# Ordinary pairs: both sides copied as r2-<name>-a and r2-<name>-b. r2-self-merge and
# r2-project-name-casefold are handled separately, below.
PAIRS="alias-cross-source crlf-bom env-form-mix env-tag-disagree healthcheck-disable
       hostname-links include-extends label-router-clash name-equals-source
       network-mode-sidecar port-range-proto-addr profile-shared retired-roundtrip
       secret-clash unicode-emoji volume-identity xunraid-disagree yaml-error"

TARGETS=()
for p in $PAIRS; do TARGETS+=("r2-$p-a" "r2-$p-b"); done
TARGETS+=("r2-self-merge-a")
TARGETS+=("R2-PROJECT-NAME-CASEFOLD-A" "r2-project-name-casefold-a")
TARGETS+=("shared")

for t in "${TARGETS[@]}"; do
  [ -e "$DEST/$t" ] && { echo "$DEST/$t already exists. Run teardown.sh first."; exit 1; }
done

for p in $PAIRS; do
  cp -a "$SRC/r2-$p/a" "$DEST/r2-$p-a"
  cp -a "$SRC/r2-$p/b" "$DEST/r2-$p-b"
done
cp -a "$SRC/r2-self-merge/a" "$DEST/r2-self-merge-a"
cp -a "$SRC/r2-project-name-casefold/a" "$DEST/R2-PROJECT-NAME-CASEFOLD-A"
cp -a "$SRC/r2-project-name-casefold/b" "$DEST/r2-project-name-casefold-a"

# r2-include-extends' sources read ../shared/common.yaml (a top-level include:) and
# ../shared/base.yaml (a service's extends:) — neither ships in the repository, since the
# off-box probe only ever checks the path string, never reads the file. Written once, here,
# so the sources — and the merged stack, sitting at the same depth in DEV-TESTING — resolve.
mkdir -p "$DEST/shared"
cat > "$DEST/shared/common.yaml" <<'EOF'
services:
  common-svc:
    image: alpine:3.20
EOF
cat > "$DEST/shared/base.yaml" <<'EOF'
services:
  base:
    image: alpine:3.20
EOF

# r2-secret-clash's two sources each declare a secret file the fixture never ships
# (secrets/a-creds.txt, secrets/b-creds.txt) — written into both sides so config -q can read them.
mkdir -p "$DEST/r2-secret-clash-a/secrets" "$DEST/r2-secret-clash-b/secrets"
echo "placeholder-a" > "$DEST/r2-secret-clash-a/secrets/a-creds.txt"
echo "placeholder-b" > "$DEST/r2-secret-clash-b/secrets/b-creds.txt"

# The fixtures arrive via the flash drive, where every file reads as owner-only whatever its
# mode was; copied as-is nothing inside a container can read them (same trap as merge-walk-six).
chmod -R u=rwX,go=rX "$DEST"/r2-* "$DEST"/R2-* "$DEST/shared"
chown -R nobody:users "$DEST"/r2-* "$DEST"/R2-* "$DEST/shared"

echo "Installed stacks:"
for t in "${TARGETS[@]}"; do
  [ "$t" = "shared" ] && continue
  echo "  $DEST/$t"
done
echo "Also written (not a stack): $DEST/shared — r2-include-extends' relative include:/extends: paths"
