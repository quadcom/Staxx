# Source tree mirrors install paths

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

`src/staxx/` is a Slackware-style tree: `src/staxx/usr/local/emhttp/plugins/staxx/`
lands at `/usr/local/emhttp/plugins/staxx/` on the server. The nesting is not decorative —
both the packager and the dev installer copy it verbatim.

The directory name `staxx` is load-bearing: it must sort alphabetically after
`dynamix.docker.manager`, which is what makes shadowing stock pages possible.
