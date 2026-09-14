#!/bin/bash
# StaXX — the "scheduled" BOOT_COPY_MODE's own pass (PLAN_103 addendum).
#
# Run from the cron entry apply_settings writes to
# /boot/config/plugins/staxx/staxx.cron, hourly under "scheduled" or once a
# day as the "live" listener's own backstop. Walks the store and rewrites a
# stack's flash copy only when its bytes differ from what is already there —
# staxx_boot_sweep() in include/BootCopy.php does the actual comparison and
# writing; this script only finds php and calls it, the same shape
# scripts/update-check already uses.
#
# Copyright 2026, StaXX contributors. GPL-2.0.

set -euo pipefail

PLUGIN_DIR="/usr/local/emhttp/plugins/staxx"
LOG="/tmp/staxx/boot-sweep.log"

mkdir -p "$(dirname "${LOG}")"
: > "${LOG}"

# cron's PATH is barer than a login shell's — same reasoning as
# scripts/update-check, which this mirrors.
php_bin=""
for candidate in /usr/bin/php /usr/local/bin/php; do
  if [[ -x "${candidate}" ]]; then
    php_bin="${candidate}"
    break
  fi
done
php_bin="${php_bin:-php}"

"${php_bin}" -r "
require '${PLUGIN_DIR}/include/Stacks.php';
\$result = staxx_boot_sweep();
foreach (\$result['written'] as \$rel) {
  echo date('Y-m-d H:i:s').\" rewrote the flash copy of \$rel\n\";
}
foreach (\$result['errors'] as \$rel => \$err) {
  echo date('Y-m-d H:i:s').\" could not rewrite the flash copy of \$rel: \$err\n\";
}
" >> "${LOG}" 2>&1 || true
