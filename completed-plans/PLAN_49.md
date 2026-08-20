# PLAN_49 — the WebUI port on networks that are not a bridge

**Status: BUILT and proved on the server.** The four macvlan containers now link to their own
addresses and inside ports; every bridge container is unchanged; the form writes a single
container-side number and says something true. Only the wording polish in Part B step 5 is left,
and it was always meant to come after this.

## Context

The web-page button on a container row opens an address built from the `webui` line in the compose
file's metadata, with `[IP]` and `[PORT:…]` filled in. Which side of a port mapping fills `[PORT:…]`
is decided by exactly one test today: **does the service have a fixed address written into it?**
If yes, the container's own port; if not, the port on the server. Nothing about the link ever looks
at what network the container is actually on.

That single test is wrong three different ways as soon as the container is not on a bridge:

| Situation | What happens now |
|---|---|
| macvlan, fixed address written in | Container-side port. Right answer, but only if a mapping was written at all — and it ignores whatever you typed. |
| macvlan, address from DHCP | Link points at the **server's** address with the **server-side** port. Both wrong. |
| `network_mode: host` | Server's address is right, but a host-mode container normally has no mapping at all, so there is **no link**. |

Adrian hit this by adding a mapping to a macvlan container to "make the link work" and getting the
inside number rather than the outside one.

The intended outcome: on a network where nothing is mapped, there is one obvious place in the form
to state the port the application listens on, the plugin remembers it, and the link opens the right
address and port whether or not the container has a fixed address written down.

## Adrian's decisions, recorded

| Question | Answer |
|---|---|
| Where the number is typed | **The usual port row**, with one side disabled and only the enabled side read. Keeps the WebUI-marked-row affordance and gives one clear place. |
| Where it is stored | **A real port entry with only the container side filled in.** Not a new metadata key — it lands where the reader already looks. |
| Also in scope | Read the **running container's real address**, so macvlan-on-DHCP works. Let the **network** decide which side is read. Correct the **stale written specification** that still claims the number inside the port placeholder is honoured. |

## What was found, and what it changes

**The form already does most of what Adrian described.** A port written as a single number has no
place in the file for a server-side value, and the form's boxes are greyed out purely by "is there
somewhere in the file for this to go". So a one-number port already renders as a live container box
beside a greyed server box. Nothing needs building to make that happen.

Three things are actually missing:

1. The **Add** button always writes a two-sided mapping, so you cannot get the one-sided shape
   without hand-editing.
2. The sentence under such a row says *"compose picks the host port for this one"* — which is false
   on host or macvlan, where nothing is picked because nothing is mapped.
3. The **server never reads the container side**, so even a correctly written one-sided port produces
   no link.

**A hard boundary, and the one line to guard in review: the network must never decide whether a box
is greyed out.** Greying stays driven by the file's own shape. The network decides only the wording
of the sentence and what the Add button writes. Otherwise a wrong guess about the network greys out a
box on a working bridge stack and the user cannot type in it.

**Existing files are never rewritten.** Someone who already wrote `8080:80` on a macvlan container
keeps both boxes live and their value byte for byte, and gets a sentence saying the outer number is
ignored. Their link also starts working with no edit at all, because the inside number is the side
that will now be read.

---

## Part A — the server half (the link)

`staxx_webui_url()` in `include/Stacks.php` is the only place a URL is produced. It gains a third
argument, the address the container is actually reachable at, and two better rules.

**The address**, in order: the running container's own address, when that is not simply this server's
address → the `ipv4_address` written in the file → this server's address. Empty at the end still means
no link, as now.

The live address costs nothing new: `staxx_container_net()` already inspects every container for the
ports column, is statically cached, and returns each one's mode, driver and resolved addresses —
sorted with the container's own address first for exactly this kind of network. It is already in hand
at both places `staxx_webui_url()` is called from, in `staxx_stack_children()` (`StacksTable.php`),
because the container record with its id is the loop variable there.

**The port** now depends on the kind of network:

- **Bridge, or unknown** — the server-side port, exactly as today. If there is none, no link. This is
  the no-regression case: an existing bridge stack produces a byte-identical link.
- **Host networking** — the container side. In host mode the two are the same number, and the mapping
  that would have carried it is ignored by Docker anyway.
- **Macvlan or ipvlan** — the container side. Nothing is mapped, so it is the only port that can
  answer.

How the kind is known, in order: the driver and mode `staxx_container_net()` already reports for a
running container; failing that, `network_mode` read from the compose file — one more branch in the
harvest loop in `staxx_compose_meta()` that already picks up `image`, `container_name` and
`ipv4_address`; failing that, bridge.

`staxx_first_ports()` needs **no change**. It already saves an entry whenever a container-side value
is present, leaving the server side empty — the shape a one-number port normalises to.

## Part B — the form half

Five small steps, each shippable on its own, none of them altering a single byte written for a bridge
stack.

1. **Keep the driver the server already sends.** The network list the page loads already carries each
   network's driver and the browser discards it. Keep it as a name-to-driver map, modelled on the
   existing "not answered yet is different from none" handling for network names.
2. **Work out the kind per service, and render nothing.** From the file's own declared networks
   first, then that map. Not yet answered, or a network nobody can see, means *unknown*, which
   behaves as bridge — never as macvlan, or every stack would show the wrong thing for the first
   fraction of a second after loading.
3. **Say something true.** Replace the misleading sentence on host and macvlan rows, and clear the
   old one in the same pass so a row never shows two contradictory lines. This goes in the field's
   live advice, not its lock reason, because advice is refreshed in place on every edit and so tracks
   a change to `network_mode` immediately.
   - host: *"This container shares the server's network, so the port inside the container is the port
     on the server."*
   - macvlan: *"This container has its own address, so only the port inside the container matters."*
   - both sides already filled on such a service: *"…so the outer number here is ignored — the port
     inside the container is the one the web button opens."*
4. **The Add button writes the one-sided shape** on a confirmed host or macvlan service — a single
   quoted number rather than a mapping. Quoted, for the same reason ports are always quoted here: an
   unquoted `22:22` is a base-sixty number in YAML. The caret already lands in the container box,
   because the form skips a greyed input when it focuses a new row.
5. **Wording polish, last** — the WebUI chip's tooltip says "opens this port", which is now ambiguous
   about which side. Only worth doing once the link is proved working.

Short form over long form deliberately: the long form gives no way to add `/udp` from the form when
the file has no protocol line, which would be a regression. Both mean the same thing to Docker.

## Part C — the stale specification

`schema/x-unraid.schema.json` still describes the number inside `[PORT:nnnn]` as resolving the server
port mapped to container port `nnnn`. It has been ignored outright since an earlier change, and the
plain-English document already says so correctly. Documentation only; the schema does not validate
the contents of that string.

---

## Files

| File | Change |
|---|---|
| `include/Stacks.php` | `staxx_webui_url()` — third argument, new address and port rules. `staxx_compose_meta()` — harvest `network_mode`. A small helper for "what kind of network is this service on". |
| `include/StacksTable.php` | `staxx_stack_children()` — pass the live address through at both call sites. |
| `javascript/stacks.js` | Keep the driver map in the network loader; expose it the way network names are exposed. |
| `javascript/compose-model.js` | Per-service network kind; the honest sentences; the Add button's one-sided port. |
| `schema/x-unraid.schema.json` | Corrected description. |
| `docs/x-unraid-schema.md` | A short note on stating the port for non-bridge containers. |

## Verification

Nothing here runs on the development machine — no PHP, no Docker, no browser.

**Locally, before deploying:** `node --check` on both browser files; `tests/js_undeclared.js` — both
files are strict mode, and a new map assigned without declaring it kills the whole page silently;
`tests/yaml_roundtrip.js` must still pass unchanged, which is the byte-for-byte guarantee.

**On the server:** `php -l` over both changed includes. Extend `tests/server/webui.php`, which already
covers fourteen pure cases, with the new ones: bridge unchanged; host mode with only a container-side
port; macvlan with a fixed address and no mapping; macvlan with a live address and no fixed address;
macvlan with both sides written, taking the inside one; a bridge service with only a container-side
port still producing no link. Add a case proving the Add button's placeholder is not treated as
already taken by the existing duplicate check.

**In the browser, on the real server:** the macvlan container that started this — confirm the link now
opens its own address and its own port. Add a one-sided port to a host-mode container through the
form and confirm the file gets a single quoted number and the link appears. Confirm a bridge stack's
port rows and links are visually identical to before.

## Risks

- **Greying driven by the network.** The one thing that must not happen; called out above because it
  is the obvious way to write this and it breaks working stacks.
- **"Not answered yet" read as macvlan.** Must fall back to bridge, or every stack flickers through
  the wrong state on load.
- **`ipvlan` forgotten.** Unraid can create those too and they behave identically here. Handle both or
  the fix half-works on a real server.
- **A bridge service written with only a container side** genuinely has a random server-side port, so
  no link is the honest answer. Reading the inside number there would produce a link that does not
  work — which is why the network, not just the shape, has to decide.
- **The WebUI chip already appears on services that have no web link at all**, promising a button that
  does not exist. Pre-existing and out of scope, but it gets more visible once the form invites people
  to type a WebUI port. Worth its own small plan.
