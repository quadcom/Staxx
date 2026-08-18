# PLAN_34 — fixed IP and MAC addresses

**Status: DRAFT v2, awaiting Adrian. Do not start.** Written 2026-08-18, rewritten the same day
after a five-agent verification pass and three adversarial reviews. **Version 1 was wrong in four
material ways** and its cheapest item has been split out as `PLAN_36.md`, which should ship first.

## What the review changed

Read this before the plan. Version 1 measured the server carefully and then described our own code
from memory, and that is where it went wrong.

| v1 said | Actually |
|---|---|
| "26 containers have a fixed IP and the same ones have a fixed MAC" | **32** templates carry a fixed IP — 26 on `br0.2`, **6 on `eth0.2`, which is not a Docker network on that box at all**. Only **10** carry a MAC, a subset. The two halves are different-sized problems and can ship separately. |
| "The machinery works today; the missing piece is only promotion" | True of **reading**. False of **writing**. Promotion is the easiest of at least four missing write paths. |
| "This unblocks PLAN_35's import of 26 containers" | It does not. The import writes YAML as text through the converter, which **already** emits the external-network declaration and the hardware address. Writing the map form there is about four lines and needs none of this plan. |
| "The 51 uncovered settings render as read-only rows" | About two thirds are **editable text boxes today**. That correction moved the whole cheap half of this plan into `PLAN_36.md`. |
| "The test box has a container running with `--runtime=nvidia`" | **No container on the box uses it.** The string is in one template's extra parameters (for a container that does not exist) and in another's description prose. |

The one thing v1 got right, and the reason to build this at all, is the diagnosis of the failure
mode. It is in Part 1 below and it has not changed.

## Part 1 — how a fixed address is actually written

Three places have to agree, and only one of them is the obvious one.

**One.** The address hangs off the *service's* entry for that network, which means the service's
`networks:` must be written as a **map**, not a list:

```yaml
services:
  plex:
    mac_address: "7e:c4:c9:f7:e4:af"
    networks:
      br0.2:
        ipv4_address: 192.168.202.66
```

**Two.** `mac_address` exists in two places — on the service (above) and on the individual network
entry. Compose treats the per-network one as the specific case. Offer the service-level one; read
both.

**Three.** The network must be **declared** at the top of the file. On Unraid `br0.2` already
exists, so the declaration says so rather than trying to create it:

```yaml
networks:
  br0.2:
    external: true
```

Without that, compose invents a project-scoped `<project>_br0.2`, the container comes up on the
wrong network, and the fixed address silently does nothing. **This is the failure that will burn
people**, and it must be unreachable from the form: setting a fixed address on a network the file
has not declared must add the declaration in the same edit.

Two things the review found about this rule:

- It is the item in this plan with **zero write machinery under it today** (see Part 2).
- There is already a case where the form **shows a declaration as if it were absent**: written
  inline as `br0.2: {external: true}`, it renders as an empty unlocked box with no fold rows. So a
  user could be told to add a declaration that is already there. That is data being hidden, which
  breaks this project's own rule, and it should be fixed in the same change.

### The portability tension, stated

A file declaring `br0.2: external: true` only runs on a machine that has a Docker network called
`br0.2`. **This is the first thing StaXX will write that is not portable**, and it is unavoidable if
you want a fixed address on your LAN. That is an acceptable trade, but it should be said in the plan
rather than discovered by whoever first copies a stack to another server. Consider a note in the
file saying so.

## Part 2 — what actually has to be built

The reading side works: the model already parses a long-form networks map, already labels
`ipv4_address`, `ipv6_address`, `mac_address`, `priority` and `gw_priority`, and the per-row
"more settings" fold already renders them as editable boxes. **If the file already holds a fixed
address, you can already edit it.** Everything below is the writing side.

### Stage A — model work, no interface at all

This is the part v1 did not know existed, and everything else waits on it. All of it is testable in
the round-trip suite with nothing drawn on screen.

1. **Add an absent key to an existing long-form entry.** Today the write path refuses immediately
   when the named part does not exist — the extras harvester only creates a part for a key already
   present in the map. So even a successfully promoted entry has no way to receive its *first*
   fixed address.
2. **Insert a child under a top-level declaration.** There is no exported function that reaches
   one: the declaration creator writes a bare name with nothing under it, the nested-insert is
   hardcoded to walk into a *service*, and the low-level insert is not exported. This is what
   Part 1's third rule needs.
3. **Promote a short-form list entry to a map entry** — the shape change itself.
4. **Read and write a comment on a mapping key.** Needed because of the regression in the next
   paragraph.

**The promotion regression, which the obvious implementation will cause:** a short-form list entry
binds a trailing comment; a long-form map entry does not, because the model never reads a comment
on a mapping key. So clicking "more settings" would make the note you typed *disappear*. Either
build item 4 first, or make promotion carry each list item's comment onto its new map key line —
and say in the plan that it must, because nothing about the natural implementation does it.

### Stage B — the controls

5. **The promotion control, in three states.** The "more settings" fold only renders when a row
   *already has* extras — so it is absent on a short-form entry and on a bare map entry, which are
   two of the three states this control must cover. That makes it a change to the list-rendering
   branch, not a tweak to the fold builder.
6. **Keep the fold open across the redraw.** Every structural edit rebuilds the whole form and the
   fold's open state is remembered nowhere, so the promotion click would open a fold that instantly
   closes — the headline feature visibly doing nothing on its first click. The codebase has already
   solved this twice for other open states; reuse that.
7. **A service-level `mac_address` box.**
8. **The declaration rule** from Part 1, on top of stage A item 2.
9. **The reverse `network_mode` guard.** `network_mode` and `networks` are mutually exclusive, and
   the enforcement runs in **one direction only**: a service with `networks:` gets the network-mode
   box drawn empty and disabled, but a service with `network_mode: host` will happily let you press
   Add on the Networks group and write a `networks:` key beside it. No lint rule, no save-time
   refusal. This belongs in the same change, because that is where a network gets added.
10. **Labels for `interface_name` and `driver_opts`.** These already harvest and already render as
    editable boxes — they need a name and nothing else.

### Explicitly cut

- **`ipam:`** (subnets, gateways, address ranges). It is not in the declaration's known-settings
  table, reaches the form as a locked read-only block, and its real payload is a *list of maps* — a
  shape nothing in this codebase can write at any depth. It is a deeper build than everything else
  here combined, and it is **not needed**: an Unraid network is made by Unraid, so the file declares
  it external and the address range is Unraid's, not the file's.
- **Auto-collapse** (v1 item 5: emptying every extra turns a map entry back into a list entry). Its
  own safety guard — "never collapse an entry a human wrote as a map with a comment in it" — cannot
  be implemented, because the model reads no comment there. An empty map entry is valid compose and
  harms nothing. Tidiness is not worth risking the round-trip promise.
- **`aliases` and `link_local_ips`** from stage B. v1 grouped these with `interface_name` and
  `driver_opts` as four keys needing a label. They are not the same: these two are **lists**, the
  extras harvester cannot bind a list at all, and there is no box to label — the row currently grows
  the sentence "this entry has settings only the Compose view can show". `aliases` is the thing
  users will want next after the fixed address, so it deserves its own item: a list-shaped extra
  with its own add and remove controls inside the fold. Not in phase 1.

## Part 3 — where the extra settings go, and the disclosure question

**Recommendation: build no new hiding mechanism. Use the one that already exists.**

Version 1 proposed a per-section "Show advanced settings" toggle, borrowed from Unraid's own
templates. Three findings killed it:

1. **The four new sections cannot be sections.** A section carries exactly one `path`, that path is
   walked one map level per segment to find the block to move, and the entry written into the file
   is literally that path joined with dots. Network (seven sibling keys), Hardware (five) and
   Lifecycle (six) have **no representable path**. "Resource limits, grown" is the same defect in
   disguise — the section points at the nested `deploy.resources` block while every key v1 adds to
   it is a top-level service key. Route `cpus` into it and you get a section that either cannot be
   unticked, or unticks and leaves `cpus` in the file with its row hidden. That second one is the
   exact bug the rules exist to prevent.
2. **The toggle hides at field granularity, and nothing in the form does that today.** The
   file-always-wins safeguard exists in one place and works at *section* granularity by counting
   fields the file holds. A per-field advanced flag that does not itself test absence will hide a
   value the file holds, silently. Advanced is also deliberately the only group with no switch,
   precisely so it can never be hidden — a uniform toggle would give it one.
3. **It is a second question for the user to ask.** Today: "is the section ticked?" With this:
   "is the section ticked, *and* is its advanced fold open?" For a non-technical user that is
   worse, and it is far more code.

**The alternative, which needs no new machinery.** A section that starts unticked *is already* a
hidden advanced setting. Six of the nineteen existing sections are exactly that — single list-shaped
keys, off by default. `dns_search`, `dns_opt`, `security_opt`, `group_add`, `device_cgroup_rules`,
`volumes_from`, `links` and `external_links` are the same shape and slot straight in with zero new
machinery, and **every one-key section has a legal path**, which dissolves problem 1 entirely.

What is left over — plain scalars like `mac_address`, `domainname`, `runtime`, `cpus` — needs no
section at all. It needs a name, which is `PLAN_36.md`. If Advanced then feels long, **sort it**;
do not hide it.

So Part 3 is: **add one-key sections for the list-shaped keys, and stop.** Reassess afterwards
whether any further disclosure is wanted. My expectation is that it will not be.

### If a multi-key section is built anyway

It needs a decision first about what happens when three of seven keys stash and the fourth refuses,
and it changes the entry name written into people's files. **A block stashed under one naming scheme
and read under another is a lost block of someone's file.** So: existing section names are never
renamed or repurposed, new sections take new names, and the restore path must leave an entry it does
not recognise untouched rather than dropping it. Write the round-trip tests before the first save
writes an entry under a new scheme.

The schema needs no change — section names have no enum, no pattern and no property-name
constraint. v1's checklist item about this was a no-op.

## Build order

Close to the reverse of version 1's.

0. **`PLAN_36.md`** — the label fix and the simple table entries. A day, improves 51 rows, blocks
   nothing.
1. **A test fixture** carrying the uncovered keys and both network forms. Nothing after this should
   ship blind.
2. **The four-line converter change** so the template import writes the map form with a fixed
   address. This is what actually unblocks PLAN_35, and it needs none of the form work.
3. **Stage A** — the model write primitives, with no interface, tested in the round-trip suite.
4. **Stage B** — the promotion control, comments carried across, fold state preserved, the
   service-level hardware address, the reverse network-mode guard, the declaration rule.
5. **One-key sections** for the list-shaped keys.
6. Stop and reassess.

### Two existing tests will go red on purpose

The round-trip suite already asserts, deliberately, that a network alias list produces the
"only the Compose view can show this" sentence, and that a bare network name is not a long-form row.
Stage A and the `aliases` item change both. **Invert those assertions; do not delete them** — they
are the only guard on the shapes being built, and deleting them is the tempting fix for what looks
like your own bug.

## Documentation

Not named in v1 and it should be: the plain-English overview, the glossary (no entry for a fixed
address or an externally-made network), and the architecture tables that enumerate every file and
endpoint action.

## Decisions only you can make

1. **Auto-collapse: cut it?** I have cut it above. The guard it needs cannot be built and an empty
   map entry is harmless. Agree, or is the tidiness worth building comment-reading on mapping keys
   first?
2. **One switch or two?** I am recommending the longer picker over a second toggle. Your users, your
   call.
3. **How should the declaration be written** — `br0.2: external: true`, or the alias form your own
   box already uses in one project (`hft: {name: br0.2, external: true}`)? The alias form reads
   better and v1 did not know it existed.
4. **Should StaXX generate a hardware address when the box is empty?** If yes: what happens when
   someone duplicates that stack, and should the form refuse two stacks carrying the same address?
   Five templates on your box already share a *fixed IP* with another template.
5. **`eth0.2`.** Six templates name it, it is not a Docker network on the box any more, and all six
   carry a fixed IP in the `br0.2` subnet — almost certainly the old name for the same VLAN.
   Translate it on import (a guess about your network, made on your behalf), or refuse those six and
   say why? **I lean refuse-and-explain**, because guessing at someone's network is exactly the kind
   of silent wrongness this plan exists to prevent.
