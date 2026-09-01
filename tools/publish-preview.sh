#!/bin/sh
# StaXX — render the docs straight into the folder the server is showing.
# Copyright 2026, StaXX contributors. GPL-2.0.
#
#   bash tools/publish-preview.sh
#
# Development tooling. The stack it feeds is one Adrian keeps on his own box so
# drafts can be read on a phone; nobody installing StaXX has one, and nothing
# about this belongs in anything a user reads.
#
# The rendering happens here because it asks GitHub to do the formatting and
# needs the signed-in GitHub CLI. The serving happens on the box because a
# container outlives this terminal. What joins them is a mapped drive onto the
# folder nginx serves, so there is no copy step at all — writing the page IS
# publishing it.
#
# Nothing here needs credentials. An earlier version copied the files up over
# ssh and had to read the password out of local/; the mapped drive removed the
# transfer, and with it the only reason this script ever knew a secret.
set -eu

DRIVE="${STAXX_PREVIEW_DRIVE:-P:}"

# Two separate questions, because the answers need different advice. Not mapped
# at all is a setup step nobody has done; mapped somewhere unexpected is worse,
# since the next thing this does is delete everything in it.
if [ ! -d "${DRIVE}/" ]; then
  cat >&2 <<MSG
${DRIVE} is not mapped.

It should point at the folder the preview stack serves:

  net use ${DRIVE} \\\\knoxx.local\\appdata\\staxx-docs-preview\\html /persistent:yes

Use the host's .local name — the bare name does not resolve here. Or render
locally instead, with: node tools/preview-docs.js
MSG
  exit 1
fi

# The guard on the delete below. An empty folder is fine — that is a first run.
# Anything present that this tool did not put there means the drive is pointing
# somewhere else, and clearing it would destroy whatever that is.
if [ -n "$(ls -A "${DRIVE}/" 2>/dev/null)" ] && [ ! -f "${DRIVE}/index.html" ]; then
  echo "${DRIVE} holds files but no index.html, so it does not look like the preview folder." >&2
  echo "Refusing to empty it. Check what ${DRIVE} is actually mapped to." >&2
  exit 1
fi

# Emptied rather than written over. A page deleted from the guide has to vanish
# from the preview too, or believing a stale page is current — the one thing
# this exists to prevent — becomes the thing it causes.
echo "Clearing ${DRIVE}"
rm -rf "${DRIVE:?}"/* "${DRIVE:?}"/.[!.]* 2>/dev/null || true

node tools/preview-docs.js --no-serve --out "${DRIVE}/" "$@"

echo
echo "  http://${STAXX_PREVIEW_HOST:-192.168.200.88}:8099/"
