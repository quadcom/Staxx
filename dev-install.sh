#!/bin/bash
# Stack Manager — development install. RUN THIS ON THE UNRAID SERVER.
#
# This is not the real installer. It copies the plugin files straight into
# place so you can see them in the web interface within seconds, skipping the
# package-and-publish steps entirely.
#
# What that means in practice:
#
#   * Changes show up on a browser refresh. No rebuild, no version number.
#   * NOTHING SURVIVES A REBOOT. /usr/local/emhttp is rebuilt from scratch at
#     boot. If something goes badly wrong, reboot and it is gone.
#   * Your settings DO survive, because they live on the flash drive at
#     /boot/config/plugins/stack.manager/. Use --purge to clear those too.
#
# Expected layout on the flash drive:
#
#   /boot/stack.manager-dev/
#     dev-install.sh        <- this file
#     stack.manager/        <- copy of the plugin folder from the repo
#
# Usage:
#   bash /boot/stack.manager-dev/dev-install.sh            install or update
#   bash /boot/stack.manager-dev/dev-install.sh --remove   remove, keep settings
#   bash /boot/stack.manager-dev/dev-install.sh --purge    remove settings too

set -euo pipefail

PLUGIN="stack.manager"
DEST="/usr/local/emhttp/plugins/${PLUGIN}"
CFG_DIR="/boot/config/plugins/${PLUGIN}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${HERE}/${PLUGIN}"
MODE="${1:-install}"

case "${MODE}" in
  --remove|--purge)
    echo "==> Removing ${DEST}"
    rm -rf "${DEST}"
    if [[ "${MODE}" == "--purge" ]]; then
      echo "==> Removing settings at ${CFG_DIR}"
      rm -rf "${CFG_DIR}"
    else
      echo "    Settings kept at ${CFG_DIR} (use --purge to remove them)"
    fi
    echo
    echo "Done. Refresh the web interface — the Stacks pages should be gone."
    exit 0
    ;;
esac

[[ -d "${SRC}" ]] || {
  echo "error: no plugin folder found at ${SRC}" >&2
  echo "       Copy src/stack.manager/usr/local/emhttp/plugins/stack.manager/" >&2
  echo "       from the repo into $(dirname "${SRC}")/ and try again." >&2
  exit 1
}

echo "==> Installing ${PLUGIN} from ${SRC}"
rm -rf "${DEST}"
mkdir -p "${DEST}"
cp -a "${SRC}/." "${DEST}/"

# Unraid splits .page files on a literal newline-dash-dash-dash-newline. A file
# copied from Windows with carriage returns fails that split, and the page is
# discarded with nothing but a one-line complaint in the syslog. Strip them
# rather than trust the copy.
echo "==> Normalising line endings"
stripped=0
while IFS= read -r -d '' f; do
  if grep -qU $'\r' "$f" 2>/dev/null; then
    sed -i 's/\r$//' "$f"
    echo "    fixed: ${f#"${DEST}/"}"
    stripped=$((stripped + 1))
  fi
done < <(find "${DEST}" -type f -print0)
[[ ${stripped} -eq 0 ]] && echo "    all clean"

echo "==> Setting permissions"
find "${DEST}" -type d -exec chmod 0755 {} +
find "${DEST}" -type f -exec chmod 0644 {} +
chmod 0755 "${DEST}/scripts/"* 2>/dev/null || true
chmod 0755 "${DEST}/event/"*   2>/dev/null || true

echo "==> Seeding settings"
mkdir -p "${CFG_DIR}"
if [[ ! -f "${CFG_DIR}/${PLUGIN}.cfg" ]]; then
  cp "${DEST}/default.cfg" "${CFG_DIR}/${PLUGIN}.cfg"
  echo "    created ${CFG_DIR}/${PLUGIN}.cfg from defaults"
else
  echo "    kept existing ${CFG_DIR}/${PLUGIN}.cfg"
fi
bash "${DEST}/scripts/apply_settings"

echo
echo "==> Environment"
printf '    Docker running   : %s\n' \
  "$( [[ -f /var/run/dockerd.pid ]] && echo yes || echo 'NO — start the Docker service' )"
if docker compose version --short >/dev/null 2>&1; then
  printf '    Compose CLI      : yes (%s)\n' "$(docker compose version --short)"
else
  printf '    Compose CLI      : NO — expected; Unraid does not ship it\n'
fi
printf '    Array state      : %s\n' \
  "$(sed -n 's/^fsState=\"\?\([^"]*\)"\?/\1/p' /var/local/emhttp/var.ini 2>/dev/null | head -n1)"

echo
echo "Installed. Now:"
echo "  1. Hard-refresh the web interface (Ctrl-F5)."
echo "  2. Look under the Docker tab for a 'Stacks' sub-tab."
echo "  3. Look under Settings for 'Stack Manager'."
echo
echo "If a page is blank or missing, watch the log while you load it:"
echo "  tail -f /var/log/syslog"
