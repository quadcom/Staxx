# Deploying to the test server

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

Credentials for the test box live in `local/dev-server.md`, which is gitignored via `/local/`.

`sshpass` is not installed on Windows, so OpenSSH cannot take a password unattended. PuTTY's
`plink` and `pscp` are present and can — use `plink -ssh -batch` (the `-batch` flag is what stops
it hanging forever on a host-key prompt).

`dev-install.sh` runs **on the server**, from `/boot/staxx-dev/`, with a copy of the plugin
folder staged beside it as `/boot/staxx-dev/staxx/`. That folder holds the plugin's **contents** —
`include`, `javascript`, `sheets`, the `.page` files — so what gets uploaded is
`src/staxx/usr/local/emhttp/plugins/staxx`, not `src/staxx`. Staging `src/staxx` instead installs
the mirrored path *inside* the plugin folder, leaving the webGUI with a plugin directory holding
nothing but a `usr` tree, and no obvious error to say so. So a deploy is always two steps: `pscp` the plugin folder up, then `plink`
the script. Delete the staged copy first or stale files survive the upload.

```sh
bash /boot/staxx-dev/dev-install.sh            # install or update
bash /boot/staxx-dev/dev-install.sh --remove   # remove, keep settings
bash /boot/staxx-dev/dev-install.sh --purge    # remove settings too
```

Nothing under `/usr/local/emhttp` survives a reboot — that tree is rebuilt at boot, so a reboot is
the panic button if a change breaks the webGUI. Settings do survive; they live on the flash drive
at `/boot/config/plugins/staxx/`.
