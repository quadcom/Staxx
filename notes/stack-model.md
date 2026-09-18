# The stack model

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

A stack is **a directory containing a compose file, and nothing else**. No database, no index, no
metadata sidecar. Drop a compose file in a folder and it is a stack; delete the folder and it is
gone. The compose file is the source of truth, so anything kept alongside it is a second copy that
can disagree with it. Stacks live at `<store>/stacks`, derived from the one `STORE_ROOT` setting —
which ships **blank**, meaning nobody has chosen where StaXX keeps its data yet. Blank is not a
default to fall back from: `staxx_stack_root()` and `staxx_archive_root()` both return `''`, and
`staxx_store_ready()` is the gate every call site ahead of a derived folder checks.

Stacks self-group by `com.docker.compose.project`, the label compose stamps on every container it
creates. Containers without it (Unraid templates, hand-created) collect under `''`.

*Folders are directories.* A stack at `<root>/Media/jellyfin/` is in the folder "Media" because
that is where it is — there is no index and no membership file. A directory at the top of the root
holding a compose file is a stack; one that does not is a folder. One level only. A stack's identity
is its path under the root, `jellyfin` or `Media/jellyfin`, and `staxx_valid_path()` gates it by
splitting on `/` and handing every segment to `staxx_valid_name()` — never by a regex that
permits a slash, which is the obvious way to write it and also the way out of the stack root.

`include/Folders.php` now holds only which folders are shown collapsed, because an empty folder has
nowhere else to keep it.

Moving a stack is a directory move, which does not change its compose project name — but Docker
recorded the old config path, so `staxx_compose_state()` indexes what compose reports three ways:
by full path, by the tail (`jellyfin/compose.yaml`, which a move does not change), and by project
name. Without the tail index a moved stack reads as stopped until it is recreated.

A stack's name is its directory name — `jellyfin`, the leaf of `Media/jellyfin` — and there is no
display-name override.
