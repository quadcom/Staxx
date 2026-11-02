# `x-unraid` metadata

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

`schema/x-unraid.schema.json` (JSON Schema Draft 2020-12) with prose in `docs/x-unraid-schema.md`.
Metadata lives *inside* the compose file — comment blocks and a companion file were both considered
and rejected. `staxx_compose_meta()` parses `x-unraid` blocks, and the form renderer built on top of
it — 23 field groups, covering everything from ports and volumes to update policy — is the largest
piece of engineering in the repository, and the reason the rest of this exists.

## `webui-network` (PLAN_252)

A service-level key beside `webui`: the name, as written in the file, of the network the web page
opens on. It exists for an app on two networks (say a macvlan one and a private one to its database),
where the automatic choice of address cannot know which is the front door. The editor draws a "Web
page opens here" tick on each of a service's own network rows, only when the service has a web page
port. At most one per service: ticking writes the key (replacing any other), unticking removes the
line entirely, never an empty value. Removal splices the key's own lines rather than calling
`removeKey()`, which would let an `x-unraid` block holding only this key collapse and take the
scaffold's comments with it. A key naming a network the service is no longer on is ignored by the
server, and the first network row says so. `docs/x-unraid-schema.md` has not been updated for it yet.
