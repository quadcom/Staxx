# PLAN — packaging compliance fixes (Unraid plugin requirements audit)

## Context

An audit of the plugin against Unraid's plugin-installer rules and the Community
Applications policies found the structure sound. The deliberate `TODO-` placeholders
(author, repository, support thread, checksum) stay as they are — a premature publish
must still fail loudly. This plan covers the five real findings only:

1. Uninstalling leaves the image-update schedule file on the flash drive, so Unraid keeps
   rebuilding root's crontab with entries pointing at scripts that no longer exist.
2. The user config on the flash drive is written world-readable and holds a Docker Hub
   token in the clear.
3. Both the installer's inline script and `apply_settings` abort on the first error, and
   `apply_settings` deliberately exits non-zero when the Docker-tab takeover is on but its
   template is missing — turning a cosmetic problem into a failed install.
4. Only an MD5 is published for the package; SHA256 is the current recommendation.
5. No README ships inside the plugin folder, so the Plugin Manager's readme view is empty.

Outcome: a package that installs, upgrades and uninstalls cleanly with nothing left behind,
no credential readable by a non-root login, and the metadata Unraid expects.

---

## 1. Uninstall hygiene

**`staxx.plg`** — the `Method="remove"` inline block. After `removepkg`/`rm -rf` of the
emhttp tree, and before the closing message, add:

```sh
# Unraid's cron builder concatenates every *.cron file found under a plugin's own
# folder on the flash drive into root's crontab. Leaving ours behind means the
# server keeps scheduling passes whose scripts have just been deleted.
rm -f "/boot/config/plugins/staxx/staxx.cron"
[[ -x /usr/local/sbin/update_cron ]] && /usr/local/sbin/update_cron || true

# Only ever undo a sign-in this plugin performed — never an administrator's own.
if [[ -f "/boot/config/plugins/staxx/hub_login" ]]; then
  docker logout >/dev/null 2>&1 || true
  rm -f "/boot/config/plugins/staxx/hub_login"
fi

rm -rf "/tmp/staxx"   # job logs, stats snapshots, icon cache — all regenerate
```

Leave `header_menu` and `takeover_docker_tab` in place: they are projections of the config
that is deliberately kept, so a reinstall lands on the same layout. Say nothing new in the
removal message — the existing "your configuration and stack definitions were kept" line is
still accurate.

**`dev-install.sh`** — the `--remove|--purge` case gets the same three blocks, so the dev
loop matches the real one. `--purge` deletes the whole config folder anyway, but must still
re-run `update_cron` after doing so, or the crontab keeps the entries until the next boot.

## 2. Config file is owner-only

The file holds `HUB_TOKEN`. Anyone with a shell login on the server can currently read it.

- **`include/Settings.php`** (~line 371): `@chmod($tmp, 0644)` → `@chmod($tmp, 0600)`.
  Chmod the temp file before the rename, as it already does, so the mode is correct the
  instant the file lands — never a window where the real path is readable.
  Add a one-line comment: *holds a Docker Hub token, so owner-only.*
- **`staxx.plg`** install inline: after the first-install `cp` of `default.cfg`, add an
  unconditional `chmod 0600 "/boot/config/plugins/staxx/staxx.cfg" 2>/dev/null || true`
  outside the `if`, so an existing install that already has a token gets tightened too.
- **`dev-install.sh`**: the same one line wherever it seeds/normalises the config.
- The shipped `default.cfg` inside the package stays 0644 — it holds no secrets, and
  `pkg_build.sh` sets package permissions wholesale.

## 3. A failed settings step must not fail the install

- **`scripts/apply_settings`**: the takeover branch currently `exit 1`s when
  `shadow/Docker.page.tmpl` is missing. Replace with a warning to stderr, then fall through
  to `rm -f "${docker_tab_live}"` — refusing to take the tab over is the safe outcome, and a
  stale live copy is worse than none. Nothing else in the script changes; the existing
  `|| true` guards around Docker and `update_cron` are already right.
- **`staxx.plg`** install inline: the `apply_settings` call runs under `set -e`, so make it
  non-fatal —
  `/usr/local/emhttp/plugins/staxx/scripts/apply_settings || echo "warning: settings could not be applied yet; they will be applied on the next settings save or reboot."`
  Keep `set -euo pipefail` for the rest of the block: a failed `cp` or `mv` there genuinely
  should stop the install.
- No change to `Settings.php`'s "saved but not applied" reporting — with the above it only
  fires on a real failure, which is what it is for.

## 4. Publish a SHA256 as well as the MD5

- **`staxx.plg`**: add `<!ENTITY packageSHA256 "TODO-SHA256">` beside the MD5 entity, and a
  `<SHA256>&packageSHA256;</SHA256>` line inside the package `<FILE>` block. Element name
  case is exact — `<SHA256>`, not `<Sha256>`. Unraid prefers it and ignores the MD5 when
  both are present; the MD5 stays for older releases.
- **`pkg_build.sh`**: compute `sha256sum` alongside the existing `md5sum`, print it in the
  summary block, and add a third `sed -e` under `--update-plg` stamping the
  `packageSHA256` entity, matching the existing MD5 pattern exactly.

## 5. README inside the plugin folder

The Plugin Manager shows a README from the installed plugin directory. Rather than keep a
second copy in git that can drift, copy the repository's `README.md` into the staged tree at
build time:

- **`pkg_build.sh`**: after `cp -a "${SRC_DIR}/." "${STAGE}/"` and before the permission
  pass, copy `${REPO_ROOT}/README.md` to
  `${STAGE}/usr/local/emhttp/plugins/${NAME}/README.md` if it exists. The existing `find …
  -exec chmod 0644` then covers it.
- **`dev-install.sh`**: copy `${HERE}/README.md` into the destination if it is present
  beside the script, guarded — the deploy step does not upload it today, and a dev install
  must not fail because of a missing readme.

---

## Files touched

- `staxx.plg` — remove block, install block, SHA256 entity and element
- `pkg_build.sh` — sha256, README staging, `--update-plg` stamping
- `dev-install.sh` — remove-path hygiene, config permission, README copy
- `src/staxx/usr/local/emhttp/plugins/staxx/scripts/apply_settings` — non-fatal takeover branch
- `src/staxx/usr/local/emhttp/plugins/staxx/include/Settings.php` — one permission constant

No JavaScript and no page files are touched.

## Verification

Locally (all that is possible on Windows):

```sh
bash -n dev-install.sh
bash -n src/staxx/usr/local/emhttp/plugins/staxx/scripts/apply_settings
bash -n pkg_build.sh
python -c "import xml.dom.minidom; xml.dom.minidom.parse('staxx.plg')"
```

On the server, after deploying:

```sh
php -l /usr/local/emhttp/plugins/staxx/include/Settings.php
```

1. Save any setting from the UI, then `ls -l /boot/config/plugins/staxx/staxx.cfg` →
   expect `-rw-------`. Confirm the settings page still loads and the token still shows masked.
2. `mv` the shadow template aside, turn the Docker-tab takeover on, save → expect a warning,
   a successful save, no `Docker.page` in the plugin folder, and the stock Docker button
   still present. Put the template back and confirm the takeover works as before.
3. `bash /boot/staxx-dev/dev-install.sh --remove`, then check
   `/boot/config/plugins/staxx/staxx.cron` is gone and `crontab -l | grep staxx` is empty.
   Reinstall and confirm the schedule comes back.
4. `bash pkg_build.sh 2026.08.20 --update-plg` on the server → both checksums printed and
   stamped, `README.md` present in the built package
   (`tar -tJf build/staxx-*.txz | grep README`).
