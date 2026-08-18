# PLAN_39 — the web page button, and StaXX owning which port it opens

**Status: DRAFT v3, awaiting Adrian.** v1 asked Docker for the port map; Adrian pointed out the compose
file already has it. v2 argued against his draggable-port idea on the grounds that the web address
already names its own port. **Measurement has now shown that argument was wrong**, so v3 adopts his
rule.

## Why the inherited convention cannot be trusted

`x-unraid.webui` uses Unraid's own token, which the schema adopted and our documentation describes as:

> `[PORT:8096]` — the **host** port currently mapped to container port 8096

So the number in the token is supposed to be the *container* port. Checked against the 64 real
templates that use it:

| What the number actually matches | Templates |
|---|---|
| Host and container port are the same, so it cannot be told apart | **36** |
| The **container** port only — the documented reading | **15** |
| The **host** port only — the opposite reading | **10** |
| Neither port the template declares | **3** |

**Template authors do not agree with each other.** Ten say one thing, fifteen say the other, and three
say something that matches nothing at all. There is no reading of that token that is right more than
about 80% of the time, and the failures are silent: a link to the wrong port on a page that looks fine.

Three real examples, each unambiguous on its own and each contradicting the next:

- `Dozzle` publishes container 8080 on host 8087, and its address says `[PORT:8087]` — the host.
- `StirlingPDF` publishes container 8080 on host 80, and says `[PORT:80]` — the host.
- Fifteen others name the container port instead.

So Adrian's instinct is right and v2's objection does not survive: **doing it our way is not
reinventing something that works, it is replacing something that does not.**

## The rule

**The first port in a service's `ports:` list is the one the button opens.** One rule, always true,
impossible to be ambiguous about, and visible in the file itself.

The `webui` value keeps its job, minus the port: it supplies the **scheme and the path** — `https://`
rather than `http://`, and a trailing `/admin` where an app needs one. StaXX fills in the address and
the port. A `webui` with no tokens at all is still honoured verbatim, because someone who typed a full
address meant it.

### What the address resolves to

- **A service with a fixed address** (`ipv4_address` on its network entry): that address, and the
  **container** port — a container on its own network publishes nothing to the host, so the host half
  of the mapping is ignored by Docker entirely. 32 of the 85 templates on this box are like this.
- **Anything else**: the server's address and the **host** port.

## What gets built now

### 1. The two buttons, under the icon on both row types

- **Web page** — an `<a>` opening in a new tab. Drawn always; greyed when the container is not
  running or there is no port to open, with a title saying which.
- **Logs** — calls the existing logs verb at the right scope. **Never greyed**: logs are worth reading
  precisely when something is not running.

Both hidden for a stack awaiting review, along with the run verbs.

### 2. A badge on the first port in the form

The rule is only fair if it is visible. The first port row gets a marker saying it is the one the web
page button opens. Without it, "first is special" is a secret.

### 3. Import puts the right port first

The importer knows which port the template meant, as well as anyone can — so it orders the ports with
that one first, using a best-effort read of the token: match the host port, then the container port.
Where it matches neither (3 templates), leave the order alone and say so in the notes, because a guess
there would be worse than the honest default of "the first one".

This is where the unreliable token gets used **once**, at import, under human review — rather than
every time somebody presses a button.

## What comes next, separately — PLAN_40

**Dragging to reorder.** The rule above is readable and importable without it, and the compose view can
already reorder ports by hand today, so the button works from day one either way.

It is separate because it is the only part that **rewrites lines in someone's file**. Moving an entry
means rebuilding the block and carrying each entry's own comment with it — the same operation PLAN_34
phase 5 did for networks, where the obvious implementation silently deleted the user's notes and the
nearest precedent in the codebase kept only the last comment. It deserves its own pass and its own
tests, not a corner of this one.

Also for that plan: a long-form port entry spans several lines, so reordering moves blocks rather than
lines, and a list holding anything the parser sealed must refuse the drag rather than half-move it.

## Not in this plan, but next door

**Nothing can edit the web address.** It is parsed by both the server and the browser, reaches the form
layer, and is rendered by nothing. So a stack with no address can never gain one — 19 of the 85
templates, plus every hand-written file and every Compose Manager import. Small, and separate.

## Tests

- **Server-side** (`tests/server/webui.php`): the resolution against every shape — a fixed address
  (container port wins), a published mapping (host port wins), no ports at all, a `webui` with a path,
  a `webui` with no tokens, no `webui` at all, and a `javascript:` URL which must come back empty.
  Read-only, no Docker.
- **Import ordering** (`tests/ca_convert.js`): a template whose web address names the host port, one
  naming the container port, and one naming neither — the first two put that port first, the third
  leaves the order alone.

## Files

| File | Change |
|---|---|
| `include/Stacks.php` | resolving a service's web address |
| `include/StacksTable.php` | the two buttons on both row types |
| `javascript/ca-convert.js` | ordering the web port first on import |
| `javascript/stacks.js` | the logs button, and the badge on the first port |
| `sheets/staxx.css` | the buttons and the badge |
| `tests/server/webui.php` | new |
| `tests/ca_convert.js` | the ordering cases |
| `docs/x-unraid-schema.md` | correct what the port token means, and say we no longer rely on it |
