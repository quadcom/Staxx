#!/bin/bash
# StaXX — development install. RUN THIS ON THE UNRAID SERVER.
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
#     /boot/config/plugins/staxx/. Use --purge to clear those too.
#
# Expected layout on the flash drive:
#
#   /boot/staxx-dev/
#     dev-install.sh        <- this file
#     staxx/        <- copy of the plugin folder from the repo
#
# Usage:
#   bash /boot/staxx-dev/dev-install.sh            install or update
#   bash /boot/staxx-dev/dev-install.sh --remove   remove, keep settings
#   bash /boot/staxx-dev/dev-install.sh --purge    remove settings too

set -euo pipefail

PLUGIN="staxx"
DEST="/usr/local/emhttp/plugins/${PLUGIN}"
CFG_DIR="/boot/config/plugins/${PLUGIN}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${HERE}/${PLUGIN}"
MODE="${1:-install}"

# The app was called stack.manager until 2026-08-18. Carry a pre-rename install
# across before anything else touches either path. mv rather than cp: two config
# folders that both look valid is worse than one. The "new does not exist" guard
# makes a re-run a no-op rather than a clobber. STACK_ROOT holds an absolute
# path — rewrite it or it points at the folder the stacks just moved out of.
OLD_CFG_DIR="/boot/config/plugins/stack.manager"
if [[ -d "${OLD_CFG_DIR}" && ! -d "${CFG_DIR}" ]]; then
  echo "==> Migrating settings from ${OLD_CFG_DIR} to ${CFG_DIR}"
  mv "${OLD_CFG_DIR}" "${CFG_DIR}"
  if [[ -f "${CFG_DIR}/stack.manager.cfg" ]]; then
    mv "${CFG_DIR}/stack.manager.cfg" "${CFG_DIR}/${PLUGIN}.cfg"
  fi
  sed -i 's#/boot/config/plugins/stack\.manager#/boot/config/plugins/staxx#g' \
    "${CFG_DIR}/${PLUGIN}.cfg" 2>/dev/null || true
  echo "    stacks now at $(grep -o '"[^"]*"' <<<"$(grep '^STACK_ROOT' "${CFG_DIR}/${PLUGIN}.cfg")" | tr -d '"')"
fi
rm -rf "/usr/local/emhttp/plugins/stack.manager"   # stale tree; rebuilt at boot anyway
rm -rf "/tmp/stack.manager"                        # a cache, regenerates

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

    # Unraid's cron builder concatenates every *.cron file under a plugin's own
    # folder on the flash drive into root's crontab, so leaving ours behind keeps
    # the server scheduling passes whose scripts have just been deleted.
    rm -f "${CFG_DIR}/${PLUGIN}.cron"
    if [[ -x /usr/local/sbin/update_cron ]]; then /usr/local/sbin/update_cron || true; fi

    # Only ever undo a sign-in StaXX performed — never an administrator's own.
    if [[ -f "${CFG_DIR}/hub_login" ]]; then
      docker logout >/dev/null 2>&1 || true
      rm -f "${CFG_DIR}/hub_login"
    fi

    rm -rf "/tmp/${PLUGIN}"   # job logs, stats snapshots, icon cache — all regenerate

    # Undo the registration marker below, so nothing of a dev install lingers.
    rm -f "/var/log/plugins/${PLUGIN}.plg"
    echo
    echo "Done. Refresh the web interface — the StaXX pages should be gone."
    exit 0
    ;;
esac

[[ -d "${SRC}" ]] || {
  echo "error: no plugin folder found at ${SRC}" >&2
  echo "       Copy src/staxx/usr/local/emhttp/plugins/staxx/" >&2
  echo "       from the repo into $(dirname "${SRC}")/ and try again." >&2
  exit 1
}

echo "==> Installing ${PLUGIN} from ${SRC}"
rm -rf "${DEST}"
mkdir -p "${DEST}"
cp -a "${SRC}/." "${DEST}/"

# The deploy step does not upload the README today; carry it in if it is
# sitting beside the script, but a dev install must not fail without one.
[[ -f "${HERE}/README.md" ]] && cp "${HERE}/README.md" "${DEST}/README.md" || true

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

# Unraid's cron builder only gathers *.cron files from plugins it can see
# registered here — a real .plg install creates this marker, but a dev
# install skips packaging entirely, so without it the schedule would look
# broken for a reason that has nothing to do with the cron file itself.
mkdir -p /var/log/plugins
[[ -f "/var/log/plugins/${PLUGIN}.plg" ]] || touch "/var/log/plugins/${PLUGIN}.plg"

echo "==> Seeding settings"
mkdir -p "${CFG_DIR}"
if [[ ! -f "${CFG_DIR}/${PLUGIN}.cfg" ]]; then
  cp "${DEST}/default.cfg" "${CFG_DIR}/${PLUGIN}.cfg"
  echo "    created ${CFG_DIR}/${PLUGIN}.cfg from defaults"
else
  echo "    kept existing ${CFG_DIR}/${PLUGIN}.cfg"
fi
# Holds a Docker Hub access token, so no other login on the box may read it.
chmod 0600 "${CFG_DIR}/${PLUGIN}.cfg" 2>/dev/null || true
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
echo "  2. Look under the Docker tab for a 'StaXX' sub-tab."
echo "  3. Look under Settings for 'StaXX'."
echo
echo "If a page is blank or missing, watch the log while you load it:"
echo "  tail -f /var/log/syslog"
