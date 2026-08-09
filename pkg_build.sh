#!/bin/bash
# Stack Manager — build the installable Slackware package from src/.
#
# Produces build/<name>-<version>-noarch-<build>.txz, which is what the .plg
# fetches and hands to upgradepkg.
#
# Run on the Unraid server itself, or on any Linux with tar + xz. Not runnable
# on Windows — the package must carry Unix permissions and ownership.
#
# Usage:
#   ./pkg_build.sh                     build with today's date as the version
#   ./pkg_build.sh 2026.08.09          build a specific version
#   ./pkg_build.sh 2026.08.09 --update-plg   ...and stamp it into the .plg
#
# Copyright 2026, Stack Manager contributors. GPL-2.0.

set -euo pipefail

NAME="stack.manager"
ARCH="noarch"
BUILD="1"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="${REPO_ROOT}/src/${NAME}"
OUT_DIR="${REPO_ROOT}/build"
PLG="${REPO_ROOT}/${NAME}.plg"

VERSION="${1:-$(date +%Y.%m.%d)}"
UPDATE_PLG="${2:-}"

PKG_NAME="${NAME}-${VERSION}-${ARCH}-${BUILD}"
PKG_FILE="${OUT_DIR}/${PKG_NAME}.txz"

[[ -d "${SRC_DIR}" ]] || { echo "error: missing source tree at ${SRC_DIR}" >&2; exit 1; }

echo "==> Staging ${NAME} ${VERSION}"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

cp -a "${SRC_DIR}/." "${STAGE}/"

# Permissions are part of the package, and the shipped tree comes off a
# filesystem that may not preserve them (this repo is developed on Windows).
# Set them explicitly rather than trusting what was checked out.
find "${STAGE}" -type d -exec chmod 0755 {} +
find "${STAGE}" -type f -exec chmod 0644 {} +
if [[ -d "${STAGE}/usr/local/emhttp/plugins/${NAME}/scripts" ]]; then
  chmod 0755 "${STAGE}/usr/local/emhttp/plugins/${NAME}/scripts/"* 2>/dev/null || true
fi
if [[ -d "${STAGE}/usr/local/emhttp/plugins/${NAME}/event" ]]; then
  chmod 0755 "${STAGE}/usr/local/emhttp/plugins/${NAME}/event/"* 2>/dev/null || true
fi

# Drop the placeholders that only exist to keep empty directories in git.
find "${STAGE}" -name '.gitkeep' -delete

echo "==> Building ${PKG_FILE}"
mkdir -p "${OUT_DIR}"
rm -f "${PKG_FILE}"

# A Slackware .txz is a tar.xz rooted at /. upgradepkg is happy with one built
# this way; makepkg is not required and is not available off-Slackware.
tar -C "${STAGE}" -cJf "${PKG_FILE}" --owner=0 --group=0 .

MD5="$(md5sum "${PKG_FILE}" | cut -d' ' -f1)"

echo
echo "    package : ${PKG_FILE}"
echo "    size    : $(du -h "${PKG_FILE}" | cut -f1)"
echo "    md5     : ${MD5}"
echo

if [[ "${UPDATE_PLG}" == "--update-plg" ]]; then
  echo "==> Stamping version and md5 into $(basename "${PLG}")"
  sed -i \
    -e "s|<!ENTITY version              \"[^\"]*\">|<!ENTITY version              \"${VERSION}\">|" \
    -e "s|<!ENTITY packageMD5           \"[^\"]*\">|<!ENTITY packageMD5           \"${MD5}\">|" \
    "${PLG}"

  # XML entities do not expand inside CDATA, so the cleanup block carries a
  # literal filename that has to be rewritten in step with the version entity.
  sed -i -E "s|${NAME}-[0-9]{4}\.[0-9]{2}\.[0-9]{2}(\.[0-9]+)?-${ARCH}-${BUILD}\.txz|${PKG_NAME}.txz|g" "${PLG}"

  echo "    done. Review the diff before committing."
else
  echo "    (pass --update-plg as the second argument to stamp these into the .plg)"
fi
