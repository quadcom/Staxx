# PHP layer

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

Everything is prefixed `staxx_`. Files are guarded against double-inclusion by a `defined()`
early return, and each `require_once`s by absolute path.

| File | Role |
|---|---|
| `Defines.php` | Config, `staxx_sh()`, docker/compose discovery, project grouping |
| `Stacks.php` | The stack model — list, read, save, delete, validate, self-test, the job runner |
| `Folders.php` | The presentational folder layer |
| `StacksTable.php` | Renders table rows; `staxx_state_snapshot()` for cheap refreshes |
| `StacksPage.php` | Page shell, asset tags, CSRF handoff to the client |
| `Icons.php` | Icon resolution — selfh.st index, caching, initials fallback |
| `Images.php` | The "Scan stored images" window (Storage tab): grouping unused images, the removal job — sits beside `UpdateRun.php`'s weekly cleanup and calls its keep-set, never edits it |
| `Stats.php` | Reads what the background collector wrote; GPU/CPU/mem/net |
| `action.php` | The single JSON endpoint |

`staxx_sh()` wraps every external command in `timeout -k 2 N sh -c '<cmd>' </dev/null`. Nothing
may hang: a page that waits forever on `docker` is worse than one that fails visibly. The command
goes to `sh -c` as one argument rather than trailing `timeout` directly — written the other way,
`timeout 120 cd /x && foo` time-limits the `cd`, which fails, short-circuits the `&&`, and reports
success while `foo` never runs.
