# How pages get mounted

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

Unraid's PageBuilder reads `.page` files: an ini header, a literal `\n---\n`, then the body. The
header's `Menu="X:N"` decides placement and rank, and `Cond` is a PHP expression evaluated on every
render of every page.

Three pages, two of them mutually exclusive:

- `Stacks.page` — `Menu="Docker:0"`, a tab ahead of the stock Docker Containers tab (`/Docker/Stacks`)
- `StaXX.page` — `Menu="Tasks:59"`, its own top-nav button just left of stock Docker (`Tasks:60`), at `/StaXX`
- `staxx.settings.page` — Settings → Utilities

Both view pages `include` the same `include/StacksPage.php`. Their `Cond` expressions test the
*same two* marker files — `header_menu` and `takeover_docker_tab` — in opposite directions of one
combined condition, so exactly one page is ever live, including the case where the takeover
setting is on but the header-menu setting is off. Both markers are **projections of the
`HEADER_MENU` and `TAKEOVER_DOCKER_TAB` config keys**, written by `scripts/apply_settings` (run by
`/update.php` after a settings save). The indirection exists because `Cond` runs constantly and
parsing an ini file there — or quoting a config lookup inside an ini header — would be wasteful and
fragile.
