# PLAN_34 — fixed IP and MAC addresses, and the advanced settings behind them

**Status: DRAFT, awaiting Adrian. Do not start.** Written 2026-08-18 alongside PLAN_33 and PLAN_35.

## What you would notice

Today there is no way to say "give this container its own address on the network". On the test box
that is not a corner case: **26 of the 86 Unraid containers there sit on `br0.2` with a fixed IP
and a fixed hardware address**, and none of them could be described in a compose file this form can
write. That is the hole.

Filling it means exposing settings most people never touch, and if they all appear at once the form
stops being the friendly thing it is. So this plan is half about the settings and half about
keeping them out of the way until they are wanted.

## Part A — what the form covers now, and what it does not

The form knows **39** service settings. The compose specification has **90**. The 51 it does not
draw a control for are not lost — anything the file already holds still renders, as a read-only row
in Advanced — but they cannot be *added*, and several of them are the ones Unraid users need most.

The 51, triaged:

| Where they should land | Keys |
|---|---|
| **Network** (new section) | `mac_address` `domainname` `dns_search` `dns_opt` `extra_hosts` `links` `external_links` |
| **Resource limits** (existing section, grown) | `cpus` `cpu_shares` `cpuset` `cpu_count` `cpu_percent` `mem_reservation` `mem_swappiness` `memswap_limit` `pids_limit` `oom_kill_disable` `oom_score_adj` `storage_opt` `blkio_config` `ulimits` |
| **Hardware** (new section) | `gpus` `runtime` `security_opt` `group_add` `device_cgroup_rules` |
| **Lifecycle** (new section) | `stop_grace_period` `post_start` `pre_stop` `scale` `platform` `pull_refresh_after` |
| **Stays a read-only Advanced row** | `annotations` `attach` `cgroup` `cgroup_parent` `cpu_period` `cpu_quota` `cpu_rt_period` `cpu_rt_runtime` `credential_spec` `develop` `extends` `isolation` `label_file` `provider` `tmpfs` `userns_mode` `uts` `volumes_from` |

The last row is deliberate. Those are swarm-era, Windows-only, or restructure the file in ways a
form cannot safely undo (`extends`, `volumes_from`). Rendering them read-only keeps the promise
that nothing in the file is ever hidden, without pretending we can edit them.

`gpus` and `runtime` are not optional extras here — the test box has a container running with
`--runtime=nvidia`, and there is already a GPU fixture in the corpus.

## Part B — how a fixed address is actually written

This is the part worth getting right before any code is written, because there are three separate
places involved and they have to agree.

**One:** the address lives on the *service's* entry for that network, which means the service's
`networks:` has to be written as a **map**, not a list:

```yaml
services:
  plex:
    mac_address: "7e:c4:c9:f7:e4:af"
    networks:
      br0.2:
        ipv4_address: 192.168.202.66
```

**Two:** `mac_address` exists in two places — on the service (above) and on the individual network
entry. Compose treats the per-network one as the specific case and the service-level one as the
default. Offer the service-level one; read both.

**Three:** the network itself has to be *declared* at the top of the file. On Unraid `br0.2` already
exists — Unraid made it — so the declaration says so rather than trying to create it:

```yaml
networks:
  br0.2:
    external: true
```

Without that, compose invents a project-scoped network called `<project>_br0.2`, the container comes
up on the wrong network, and the fixed address silently does nothing. **This is the failure mode
that will burn people**, and it must be impossible to reach from the form: ticking a fixed address
on a network the file has not declared must add the declaration in the same edit.

### What already exists, and is nearly enough

The model already reads a long-form networks map and already has friendly labels for
`ipv4_address`, `ipv6_address`, `mac_address`, `priority` and `gw_priority`. The per-row "more
settings" toggle already renders them. **All of that works today — if the file already happens to
be written that way.** The missing piece is the promotion: turning a plain list entry into a map
entry from the form, and adding the declaration alongside it.

So Part B is much smaller than it looks:

1. A per-entry **"more settings"** control on a Networks row that, on a short-form entry, rewrites
   the list into a map with the same names before opening.
2. Boxes for the rest of the per-network keys the model does not yet label: `aliases`,
   `link_local_ips`, `interface_name`, `driver_opts`.
3. A service-level `mac_address` box in the new Network section.
4. Declaration support for `external: true` and for `ipam:` (`subnet`, `gateway`, `ip_range`), plus
   the rule above that a fixed address implies a declaration.
5. The reverse: emptying every extra on a map entry collapses it back to a plain list entry, so the
   file does not accumulate empty maps. **Never** collapse an entry a human wrote as a map with a
   comment in it — that is the round-trip promise.

## Part C — keeping the form neat

Four new sections and roughly thirty new rows land on a form that is already dense. Three rules,
and one of them is borrowed.

### The borrowed one: basic and advanced, the way Unraid already does it

Every Unraid container template marks its own settings `Display="always"` or `Display="advanced"`,
and the stock Docker page hides the advanced ones behind a switch. **Adrian's users already know
that switch.** Use the same idea and the same word.

So: each section gets a **"Show advanced settings"** toggle on its heading line, and each row in
that section is marked basic or advanced. Resource limits shows a CPU limit and a memory limit;
the other twelve sit behind the toggle. Network shows the network list, the fixed address and the
hardware address; DNS search domains, extra hosts and links sit behind it.

The toggle is per-section, per-service, and **lives in the browser only** — it is not written to
`x-unraid`. Which settings a *viewer* wants to look at is not a property of the file.

### The rule that overrides it: the file always wins

**A setting the file actually holds is never hidden, ever.** If a service has `sysctls`, the
Advanced group shows it whether or not the toggle is on, and the toggle shows as already-open. This
is the same rule the section ticks already follow, and it is what makes the disclosure safe: hiding
a control is fine, hiding *data* is a bug.

### The picker needs grouping

The Sections picker lists 19 sections today; this takes it to 23. A flat list of 23 ticks is a wall.
Split it under three headings — **Common** (ports, volumes, variables, devices, labels),
**Networking** (network, DNS servers, internal ports), **Advanced** (everything else) — in the
picker only. The sections themselves still render in their existing order.

## Phases

Each phase is independently useful and independently shippable. Stop after any of them.

1. **The fixed address.** Part B items 1–5, plus a service-level `mac_address` box. This alone
   unblocks PLAN_35's import of 26 containers, and is the only phase with a deadline attached to it.
2. **The Network section.** `hostname` and `network_mode` move into it from Container/Advanced;
   `domainname`, `dns_search`, `dns_opt`, `extra_hosts`, `links`, `external_links` gain controls.
3. **Basic/advanced disclosure**, and the picker grouping. Do this before phase 4, not after —
   phase 4 is what makes the form unusable without it.
4. **Resource limits grown**, plus the new Hardware and Lifecycle sections.
5. **The read-only 18** get proper labels and help text instead of bare key names in Advanced.

## Things that will bite

- **`network_mode` and `networks` are mutually exclusive** — the model already knows this
  (`excludes: 'networks'`). A Network section that shows both side by side has to enforce it
  visibly, not just refuse the save.
- **A fixed IP on a bridge network is not the same as one on a macvlan.** `br0.2` is macvlan and
  the address is on the LAN; `mybridge` is a bridge and it is not. The help text has to say which,
  because getting it wrong looks like a working setting that does nothing.
- **A hardware address must be locally administered** or it can collide with real equipment.
  Unraid generates one; offer to do the same rather than leaving an empty box.
- **`extra_hosts` has two forms** (a list of `host:ip` strings, or a map). Same problem `depends_on`
  already solved — reuse that shape, do not invent a second one.
- **The schema.** `x-unraid` gains nothing here except possibly section entries for the four new
  sections. Check `schema/x-unraid.schema.json` accepts them before the first save writes one.
