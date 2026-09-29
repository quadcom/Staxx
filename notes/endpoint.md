# The endpoint

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

`include/action.php` is the only thing the page talks to. It answers JSON always, buffers output so
a stray PHP notice lands *inside* the reply rather than corrupting it, and registers a shutdown
handler so a fatal error still produces a readable response.

**POST only.** CSRF is already enforced by Unraid — `/etc/php.ini` sets `auto_prepend_file` to
`webGui/include/local_prepend.php`, which validates `csrf_token` on every POST and then `unset()`s
it. Re-checking it here cannot succeed, because the field is gone by then. But that gate covers
POST only, so accepting query-string parameters would hand anyone a way around it.

Every action is named in one `switch`; there is no path from user input to a command that is not on
that list. Two refresh sizes exist deliberately: `state` is one `compose ls` for the whole machine
and is what start/stop/restart use; `rows` re-renders the whole table body and re-reads every
compose file, so it is only for changes to the *set* of rows.

Dashboard and icon actions (all POST, all in `include/action.php`):

- `dash_state` — no input; replies the layout, resolved icon addresses, every stack's live facts,
  `canRun` and the picker's icon-set summary. Polled while the Dashboard is open.
- `dash_save` — takes `layout` (JSON text); replies the normalised layout. Nothing is written as
  sent, and icon files nothing references any more are pruned.
- `dash_icons` — takes `set` and optionally `collection`; replies that set's cached listing.
- `dash_icon_pick` — takes `set` and `file`; downloads it into `config/icons/dash/` and replies the
  local file name and address. It only ever downloads from the four raw.githubusercontent.com
  prefixes `staxx_dash_allowed_prefix()` builds (hernandito, ground7, homarr-labs, tabler).
- `dash_icon_upload` — takes `name` and `data` (base64 text in the urlencoded body, never
  multipart); replies the stored file name and address.
- `folder_icon` — takes a folder `name` and `icon` (a file already in `config/icons/dash/`, or ''
  to remove); replies `icon` and `url`.
- `about` — no input; replies StaXX's version, the Docker Compose answering (`missing`, `found` or
  `staxx`), and the credits and services tables. Read-only.
