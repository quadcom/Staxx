# PLAN_34 — networking in the form

**Status: APPROVED 2026-08-18, building.** Third version. v1 was written from memory and corrected by
a verification pass; v2 was rewritten from that; **this one replaces both** after Adrian opened
`14-long-forms` and found three real gaps, and the investigation into them turned up a bug that can
make a compose file unreadable.

What changed from v2, so nobody rebuilds the dropped parts:

- **Part 3 (four new sections and a second show-advanced switch) is gone.** Three of the four could
  not have been sections at all, and the disclosure argument went with them. Deferred, possibly
  forever — see "Not building".
- **Scope is now three Add paths, not a coverage sweep**, chosen against measured use in Adrian's own
  files rather than a guess.
- **A new Phase 0 comes first**: the corruption fix, plus making the shape classification compulsory
  instead of conventional. That is the generalisable answer to "can we build a parser for this" — the
  parser exists; one path skipped it.

---

## Context

Looking at `14-long-forms`, Adrian raised four things: no way to add a MAC address; the `backend`
network's "setting" dropdown dead so its config can't be finished; Networks appearing in two places;
and a question — how much of compose networking can a form honestly cover, or is it too complex?

Investigating those turned up something more urgent than any of them. **StaXX can currently turn a
working compose file into one compose cannot read, with one click.** A network declaration written
as a flow map (`hft: {name: br0.2, external: true}`) or as an alias (`alias: *b`) renders a driver
dropdown that appears usable; using it appends an indented child under a node that cannot take one.
Verified against real compose on the server:

```
$ docker compose -f bad-flow.yaml config -q
yaml: line 4: did not find expected key
```

That shape is **already in Adrian's own `Homepage-For-Tesla` project**, so it is reachable today and
certain to be hit once PLAN_35's import lands. It breaks both of CLAUDE.md's overriding rules at
once. It goes first.

The answer to the design question is **no, it is not too complex**. Of 55 distinct ways compose can
express networking, 30 deserve a real field and 21 of those already work. The 86% case on Adrian's
own server is four controls. What is hard is not the taxonomy — it is four missing write primitives
and one YAML shape (a list of maps) this codebase cannot write at any depth.

### Decisions taken

| Question | Decision |
|---|---|
| Networks in two places | **Keep both, renamed so they differ** — "Networks this stack creates" and "Networks this container joins" — and let a service row create a missing declaration in one click. A declaration is genuinely stack-wide; hiding that would mislead. |
| Network mode | **Remove from the form entirely.** A file that has one shows it read-only in Advanced. |
| Coverage | **Everything except list-of-maps.** Address ranges stay read-only, saying what they are and why. |

### Why the split is right, in one line the UI should teach

The two-places instinct is correct, and the reason is worth stating in the form's own words:

> **The range belongs to the network. The address belongs to the container.**

A declaration *is* a network — one network, many containers. Put a fixed address on it and that
network can only ever serve one container, which defeats what a network is. So subnet and gateway
(the range) sit on the declaration, and the fixed IP and hardware address (this container's place on
it) sit on the service's own entry. That is also why address ranges are not worth building on Unraid:
the network is `external`, so Unraid owns the range already.

### What Adrian's own files actually use

Measured across the **24 real compose files** on the server (an earlier count of these was wrong —
it had swept in an application source file that happened to contain the word `aliases`, so treat
only this table as the measurement):

| Setting | His own compose files | Templates |
|---|---|---|
| `external:` | **8** | 26 need it |
| `ipv4_address` | 2 | 26 |
| `mac_address` | 0 | 10 |
| `network_mode` | **0** | 6 (`host`) |
| `aliases` | **0** | 0 |
| `link_local_ips` / `ipam` / `subnet` / `priority` / `gw_priority` / `interface_name` / `driver_opts` / `attachable` / `internal` / `enable_ipv6` | **0 each** | 0 each |

Two things fall out. **`external: true` is the most-used network setting he has, and it is the one the
form cannot create** — that is the highest-value fix in this whole plan. And **`network_mode` appears
in none of his own files**, which supports removing it.

### Considered and rejected: surfacing the address on the network row

Seen in the browser on `14-long-forms`: the fixed address is editable today, but it sits inside a
collapsed **more settings** toggle on a row that shows only a network name, so it reads as absent. The
obvious response is to promote the address (and the hardware address) onto the row itself, since it is
the most-wanted network setting there is.

**Decided against, 2026-08-18.** Adrian's call, and the reasoning is the project's core goal: too many
fields visible at once is overwhelming, and this form exists so someone who does not know compose can
work without being intimidated. A fixed address is the most-wanted setting *among people who want one
at all* — which is a minority of containers. Promoting it puts a box in front of everyone to serve
some. It stays in **more settings**.

Do not revisit this as a "quick win". The discoverability concern is real but the answer, if one is
ever needed, is better wording on the toggle — not more boxes on the row.

### The answer to "are we too far into the weeds?"

No, because the choice is not what a user *can* do — the Compose view is in the same dialog, one
click away, and anyone who wants `gw_priority` is already comfortable there. A **labelled read-only
row is not a failure**: it names the setting, shows its value, keeps the promise that nothing in the
file is hidden, and costs nothing to build.

So the real axis is narrower than it looks. Everything that renders today keeps rendering and stays
editable — nothing is taken away by scoping down. The only expensive mechanism is **adding** a
setting the file does not have yet. So the scope question is just: *how many Add paths do we build?*

The benchmark: be at least as capable as Unraid's own Docker page, which offers a network, a fixed
IP and a MAC — and no more adventurous than the measurements above justify.

---

## The architecture question — the parser already exists, and that is the point

The idea of a layer that recognises *how* a block is written and translates it into form controls is
**already the architecture**, and it already spans several blocks:

- **`LOCK_WORDS` + `lockReason()`** (`compose-model.js` ~768-785) is the classifier. Twelve shape
  codes — `flow`, `anchor`, `alias`, `merge`, `tag`, `block-scalar`, `multiline-scalar`, `escape`,
  `tab-indent`, `directive`, `multi-doc`, `unparsable` — each with a plain-English sentence.
- **`harvestLongForm` / `harvestLongExtras`** (~1080, ~1154) are already shared: ports (~1125),
  volumes (~1142), secrets and configs (~1040) and networks (~1704) all go through the same
  long-form machinery.

So the recognition problem is solved. **The bug we found is a path that skipped the shared classifier**
— `lockReason` is called from nine places and `declaredFields` is not one of them. That is the whole
root cause, stated architecturally: shape handling is *conventional* rather than *mandatory*, and the
one path that forgot corrupts files.

### The generalisable improvement

Not a new parser — make the existing classification **compulsory**. One function every harvest path
must go through, returning one of three answers: *editable*, *editable in place*, or *read-only, and
here is why in plain English*. That would have made the corruption impossible by construction rather
than by remembering, and it removes a judgement currently made five different ways in five paths
(`fieldsFor`, `declaredFields`, `harvestBlock`, `harvestLongExtras`, `settingTarget`).

### What NOT to do, and why

**Do not normalise into a canonical in-memory shape and render that.** It is the obvious reading of
"translate the config into a form presentation", and it would break the project's second rule. Fields
deliberately bind to *positions* — a line and column in the original text — not to an abstract model,
which is exactly what makes comments, ordering, anchors and formatting survive a write-back. A
canonical model has to re-serialise to save, and re-serialising is how a round-trip becomes a
normalised rewrite of someone's file.

### Where the same approach pays off elsewhere

The same shape families recur across everything the form already supports, so the primitives built
here are reusable rather than networking-specific:

| Family | Keys it affects |
|---|---|
| Short form vs spelled-out form | `ports`, `volumes`, `secrets`, `configs`, `depends_on`, `networks` |
| A list or a map, same meaning | `depends_on`, `networks`, `extra_hosts`, `sysctls`, `labels`, `environment` |
| A single value or a list | `dns`, `dns_search`, `env_file`, `command`, `entrypoint` |
| A list of maps — unwritable | long-form `ports`, `volumes`, `secrets`, `configs`, `ipam.config`, `ulimits`, `blkio_config` |

The promote-between-forms operation this plan needs for networks is **already on the wish list for
ports and mounts** — `docs/feature-ideas.md:131`, "switch a port or mount between the short and the
spelled-out form… the model has understood both forms since PLAN_30, and this is fiddly enough by
hand that nobody does it". Build it once here, shaped so it takes the key rather than assuming
networks, and it serves all six.

## Phase 0 — stop corrupting files

Non-negotiable, ships alone, no feature depends on it.

A declaration whose value is a flow map, an anchor or an alias must **lock**, show its raw text and
give a reason — the treatment every other unreadable node in the form already gets. Today it renders
as an ordinary unlocked row with an *empty* box, so it is indistinguishable from a bare `backend:`
while the file says `external: true`. That hides data, which is a bug on its own.

Two independent causes; fix both, so neither alone can corrupt a file:

- `declaredFields` (`compose-model.js` ~2110-2180) never runs the locked/usable computation that
  `fieldsFor` does (~1982-1991). Route an opaque or non-map value node through `lockedTarget` with
  the raw span, the way `settingTarget` (~1262-1288) already does.
- `setPart`'s declaration branch (~2505-2519) calls `insertChild` without checking the value node's
  kind. Refuse unless it is a map or null.

**Then make the classification compulsory rather than conventional** — the generalisable fix from the
architecture section above. Extract the "can this node be edited, and if not what do I say?" judgement
into one function and route all five paths through it (`fieldsFor`, `declaredFields`, `harvestBlock`,
`harvestLongExtras`, `settingTarget`). This is what stops the next path from forgetting; without it,
the two fixes above are patches on one instance of a class of bug. Keep it a pure refactor with no
behaviour change beyond the two fixes, so the existing suite is the proof.

Add fixtures for all three shapes plus a bare declaration as control, and a test asserting the file
is byte-identical after an attempted edit on each.

## Phase 1 — the dead dropdown Adrian hit

**The model already does this and has a green test** (`tests/yaml_roundtrip.js` §11, "filling an empty
declaration's primary setting"). The form disables the only control that would call it.

One line — `stacks.js:1667`:

```js
var dead = (!p.spot && !f.absent && !f.path) || f.locked || f.blocked;
```

For a bare `backend:` the row has no spot, is not `absent`, and carries no `path`, so it renders
`disabled` — with no lock reason and no explanation, which is why it reads as arbitrary. Exempt the
declaration case here (narrower than setting `absent`, which is read elsewhere).

This reaches **`driver` only**. The other four leaves (`external`, `name`, `internal`, `attachable`)
need Phase 3.

## Phase 2 — cheap and visible

- **Networks above Ports.** Render order is just the `GROUPS` array order — move the `list:networks`
  entry above `port`, and the matching `SECTIONS` entry, so the picker agrees. Two moved lines,
  nothing else reads position.
- **`br0.2` reads as "Br0 2".** `humanise` splits on `.`, so every Unraid VLAN name mangles — in the
  service row, the declaration row and the remove tooltip. These plans are entirely about
  `br0.2`-shaped names. A network name is a proper noun: show it verbatim.
- **Label the unlabelled.** `interface_name` renders with its raw key; `ipam` titles as "Ipam".
- **Tier-2 lock reasons** should name the thing and say why it is not editable here, rather than
  "this is written as a block of its own".

## Phase 3 — three Add paths, three mechanisms

Scope decision: **three settings become addable** — a network's "made outside this file"
(`external: true`), a fixed IPv4 address, and a hardware address. Everything else stays editable
when the file has it. Each needs a different mechanism, and two of them hand us siblings free.

1. **An always-offered pass for a declaration's own settings**, mirroring what `harvestLeaves`
   already does for `healthcheck` and `deploy`: emit a blank fold row for a `DECL_LEAVES` key the
   file lacks, instead of emitting nothing (`harvestBlock` ~1332 skips an absent leaf). This is the
   `external: true` path — **the highest-value fix in the plan**, since it is the most-used network
   setting in Adrian's own files and the form cannot write it at all today.
   *Free siblings:* `name`, `internal` and `attachable` become addable by the same mechanism at no
   extra cost. That is not scope creep — there is no cheaper version that gives only one of the four.
2. **A declaration-scoped child insert**, which (1) needs in order to write. Cheaper than it looks:
   `ensurePath` already takes a root-resolver *closure* and `insertChild` already takes a *pair*;
   `addNested` merely hardcodes `serviceMapOf`. A sibling passing a declaration resolver is a thin
   wrapper, not new machinery.
3. **A fourth creation case in `setPart`** for an absent key on an existing long-form list entry.
   This is the fixed-address path: `setPart(..., 'ipv4_address', ...)` returns `false` today on an
   entry that lacks the line. *Free siblings:* `ipv6_address`, per-network `mac_address`, `priority`,
   `gw_priority` and `interface_name` all become addable by the same code.
4. ~~**A service-level slot for `mac_address`.**~~ **DROPPED 2026-08-18**, using this plan's own
   escape hatch. Three reasons, and they compound:
   - It is the only item needing brand-new machinery. The existing always-offered mechanisms are
     Container-bound (`f.fixed` routes to Container at `stacks.js:345`) or nested-path-only (`LEAVES`
     walks one map level per segment, so a top-level sibling key has no legal path).
   - **It has nowhere to go that respects the no-clutter rule.** An addable setting needs a visible
     affordance, and there is no Add control in Advanced. Putting it in Container is exactly the
     clutter Adrian ruled out.
   - **It is redundant.** The per-network hardware address comes free from (3) and lands in the same
     fold as the fixed address — which is where Adrian asked for both. Unraid's own `MyMAC` is set
     against a container on a specific network, so per-network is the more faithful shape anyway.

   A service-level `mac_address` the file already has stays editable in Advanced, correctly labelled
   since PLAN_36. Only *adding* one from scratch is off the table, and the per-network box does that
   job.

### How many blank boxes the fold offers

Item (3) could offer a blank box for every extra a network entry can take — eight or so. **It must
not.** That is the clutter rule again, inside the fold this time. Offer blanks for exactly the two
worth adding — **fixed IPv4 address** and **hardware address** — and leave the rest
editable-when-present. So a network entry with nothing set opens onto two empty boxes, not eight.

**Do not implement any of this by adding `always: 1` to more keys** — `f.fixed` routes straight to
Container, so they would all land back in the Container group.

## Phase 4 — remove Network mode from the form

Adrian's decision. Three consequences the change must carry:

- **The exclusion enforcement lives inside the always pass** and disappears with it. Today a service
  with `networks:` gets the mode box blocked with "this service joins the networks listed below
  instead". Removing the row removes that.
- **The reverse was never guarded and now matters more.** A service with `network_mode: host` will
  happily let you press Add on Networks and write an invalid file — verified. With the mode row gone
  this becomes the only guard, so it is required: refuse in the Add handler, and show it on the
  Networks heading rather than only refusing the save.
- **Six test blocks in `tests/yaml_roundtrip.js` change** — the Container four-row count, web's exact
  field list, the blocked-not-locked assertions, the absent-slot addability, and the revert-on-last-
  network-removed case. The real invariant those protect is **field index stability**, which
  `refreshRanges` depends on; keep that intact and update the counts deliberately.

### Should a pasted `network_mode` be converted into a network list?

Asked 2026-08-18. **Answer: no, and mostly it is not possible.** Recorded because the question will
come back.

First, a correction to the premise: `network_mode` is **not** marked uneditable today. A file that has
one gets an ordinary editable row in Advanced, with a dropdown. So the choice is not between
converting and locking.

Second, only one of its six value families has a network-list equivalent at all:

| Value | Means | Convertible? |
|---|---|---|
| `host` | share the server's own network stack | **No.** No network you can join grants the host's namespace. |
| `none` | no networking | **No.** A list cannot express "no network". |
| `service: x` / `container: x` | share another container's stack | **No.** Same reason. |
| `bridge` | Docker's default `docker0` bridge | **No** — and this is the trap. Compose's default network is a *project-scoped* bridge where services resolve each other by name; `bridge` is not, and containers on it cannot. Converting silently changes both addressing and name resolution. |
| a real network name | joins that network | **Yes** — equals `networks: [name]` plus a declaration. |

So auto-conversion would take a file saying "share the server's network" and turn it into "join a
network called host", which breaks the container. That is exactly the silent wrongness Phase 0 existed
to remove.

Third, the one convertible case **is never produced by StaXX**. The importer already maps Unraid's
network setting correctly: `bridge`/empty writes nothing, `host`/`none` write `network_mode`, and any
named network already writes a `networks:` list plus its declaration. So the convertible value only
arises in a hand-pasted file. On this server: **zero occurrences** — the six templates using
`network_mode` are all `host`.

**Decision:** build nothing. If a bare network name ever shows up in a pasted file, the honest
treatment is an *offer* to convert with the equivalence explained — never automatic, never for the
other five. One line of future work, not worth doing before it is seen.

## Phase 5 — the promote control

The fixed-address path only reaches a network written as a **map** entry. A plain `- backend` has
nowhere to hang an address, so it has to be promotable. Three traps, all verified:

- **The control must render on all three row states** — plain list entry, bare map entry, and map
  with settings. Today the "more settings" toggle appears *only* when extras already exist, so it is
  absent on two of the three. That is an edit to the list-rendering branch, not to the toggle builder.
- **The fold's open state is remembered nowhere**, and every structural edit rebuilds the whole form —
  so the first click of the headline feature would visibly do nothing. Reuse the existing
  `stackOpen` / `sectionsOpen` / `sectionOn` pattern: a fourth module-scope map, one clause in the
  capture-phase `toggle` listener, and a reset where the other three are cleared.
- **The note must survive.** A list entry binds an editable comment; a map key binds none, because
  the model never reads a comment on a mapping key. The obvious implementation silently deletes a
  note the user typed. Promotion must carry each item's trailing comment onto its new map key line.

## Phase 6 — a consistency fix, not a feature

**Neither `aliases` nor `link_local_ips` is used anywhere** — zero across the 24 compose files and
zero across the 85 templates. Neither is necessary either:

- **`aliases`** is largely redundant. Compose already makes a service resolvable by its **service
  name** on every network it joins, automatically, with no setting at all — which is how containers
  normally find each other. `aliases` only matters when something expects a *different* name than the
  service name (a migrated stack whose app config hardcodes `db.internal`, say). Not a gap for an
  Unraid user.
- **`link_local_ips`** adds extra `169.254.x.x` addresses. Nothing else covers it, and nothing needs
  it.

So this is **not** a feature to build. What remains is a one-off consistency fix, and it is about
trust rather than capability: everywhere else in the form, a shape it cannot edit is shown as a
**locked row displaying the raw text**. These two are the only place the form says "there is
something here" — via a generic "this entry has settings only the Compose view can show" — **without
showing what**. Make them consistent with everything else: locked, named, raw text visible.

The existing assertion (§X12) pins today's silence and should be **updated, not deleted** — it is the
only guard on that path.

Also here: **create the missing declaration from a service row.** A service naming an undeclared
network already shows advice ("no network called br0.2 is defined in this file"); make it actionable,
writing the whole declaration in one edit with `external: true` for a network Unraid made. This is
what keeps the two-places split honest — the service row can fix the stack-wide thing without
pretending to own it.

## Not building

**Lists of maps.** `ipam.config` (subnet, gateway, ip_range) and long-form `ports`. Nothing in this
codebase can create a list of maps, add a key inside one, or collapse one, at any depth. On Unraid
the address range belongs to Unraid anyway, because the network is external. Read-only, with a reason
that names it.

**Single-setting sections for the locked list settings** — DNS search domains, security options,
extra groups, device cgroup rules, extra hosts, links. Deferred deliberately: they already read with
proper names and each says where to edit it, none appears in Adrian's own files, and it is six
sections of machinery for no measured need. Revisit only if it proves annoying in use. This drops
PLAN_34's whole Part 3 and the disclosure argument with it.

**The long tail of add paths** — `priority`, `gw_priority`, `interface_name`, `driver_opts`,
`internal`, `attachable`, `enable_ipv6`, `link_local_ips`. Zero use across Adrian's own files. Several
become addable free as siblings of Phase 3's mechanisms; none gets bespoke work.

Also note **`13-`–`16-long-forms` exist only on the test server, not in the repo**, so no test can
reach them — including the file this whole review came from. Copy them into the corpus while working
here.

## Verification

1. `node tests/yaml_roundtrip.js`, `js_undeclared.js`, `ca_convert.js`, `image_import.js`,
   `node --check` on both browser files, `python tests/validate_schema.py`.
2. **The corruption cases specifically:** for each of the three shapes, attempt the edit and assert
   the file is byte-identical, then run `docker compose config -q` on the server over every fixture
   to prove none of them regress into an unreadable file.
3. Deploy and drive it in the browser on `14-long-forms` and `03-multi-tier`: the `backend` dropdown
   works; `br0.2` reads as `br0.2`; Networks sits above Ports; a fixed address and a MAC can be
   *added* where none existed; the note on a promoted entry survives; no console error.
4. Confirm `md5sum` of each fixture is unchanged after opening and closing the editor.
