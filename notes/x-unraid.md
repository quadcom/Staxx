# `x-unraid` metadata

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

`schema/x-unraid.schema.json` (JSON Schema Draft 2020-12) with prose in `docs/x-unraid-schema.md`.
Metadata lives *inside* the compose file — comment blocks and a companion file were both considered
and rejected. `staxx_compose_meta()` parses `x-unraid` blocks, and the form renderer built on top of
it — 22 field groups, covering everything from ports and volumes to update policy — is the largest
piece of engineering in the repository, and the reason the rest of this exists.
