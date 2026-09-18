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
