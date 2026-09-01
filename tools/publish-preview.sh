#!/bin/sh
# StaXX — render the docs and put them on the server's preview stack.
# Copyright 2026, StaXX contributors. GPL-2.0.
#
#   bash tools/publish-preview.sh
#
# Development tooling. The stack it feeds is one Adrian keeps on his own box to
# read drafts on a phone or tablet; nobody installing StaXX has one, and nothing
# about this belongs in anything a user reads.
#
# Two steps, deliberately kept apart. The rendering has to happen here, because
# it asks GitHub to do the formatting and needs the signed-in GitHub CLI to do
# it. The serving happens there, because a container on the server outlives this
# terminal and is reachable from anything on the network.
#
# The whole folder is replaced rather than merged. A page deleted from the guide
# has to disappear from the preview too, or the one thing this exists to prevent
# — believing a stale page is current — is exactly what it starts causing.
set -eu

HOST="${STAXX_PREVIEW_HOST:-192.168.200.88}"
DEST=/mnt/user/appdata/staxx-docs-preview/html

# Credentials live in local/, which is gitignored — never inline them here.
PW="$(sed -n 's/^| Password | `\(.*\)` |$/\1/p' local/dev-server.md | head -n1)"
if [ -z "${PW}" ]; then
  echo "No password found in local/dev-server.md — this is developer tooling and needs that file." >&2
  exit 1
fi

echo "Rendering..."
node tools/preview-docs.js --no-serve "$@"

echo "Replacing what is on the server..."
plink -ssh -batch -pw "${PW}" "root@${HOST}" "rm -rf ${DEST} && mkdir -p ${DEST}"
pscp -q -pw "${PW}" -r .preview/* "root@${HOST}:${DEST}/"

# Reported rather than assumed: nginx serves whatever is in that folder, so a
# copy that half-failed would look exactly like a successful one until a page
# turned up missing.
plink -ssh -batch -pw "${PW}" "root@${HOST}" \
  "echo \"\$(ls ${DEST}/*.html | wc -l) page(s) now on the server\"; \
   docker ps --filter name=staxx-docs-preview --format '{{.Names}} | {{.Status}}'"

echo
echo "  http://${HOST}:8099/"
