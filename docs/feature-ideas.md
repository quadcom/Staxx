# Features — ideas, not commitments

A running list of things that might one day be worth building. Nothing here is agreed or scheduled.
An entry gets promoted to its own numbered plan when it is picked up, and deleted from here when it
ships or is ruled out.

Sibling to `settings-ideas.md`, which holds the same kind of list for things that would be a
*setting*. An entry belongs there if it needs a switch in the settings panel and here if it does not.

Started 2026-08-17.

---

## 1. Markdown in catalogue app descriptions

**What you would notice.** A stray `#` at the start of Gluetun's blurb, `**` around a word that
should be bold, a bulleted list running together as one paragraph. Adrian spotted it on the Apps
homepage, 2026-08-17, straight after `PLAN_27` fixed the *other* markup the catalogue uses.

**Two different markups, and only one is handled.** `PLAN_27` renders CA's bracket tags — `[b]`,
`[br]`, `[span style='…']` — which 340 overviews use. A separate set of apps writes ordinary
markdown instead, and nothing renders that. The bracket work deliberately did not touch it, and the
reasoning for *not carrying a markdown library* still stands (see below).

**Measured against the live catalogue, 2026-08-17, 3,651 overviews. 391 (10.7%) carry some
markdown:**

| Construct | Apps | Worth doing? |
|---|---:|---|
| Bullet list (`- item`) | 179 | Yes — highest value, lowest risk: a line beginning `- ` is unambiguous |
| Bold (`**text**`) | 127 | Yes |
| Inline code (`` `text` ``) | 78 | Yes |
| Numbered list (`1. item`) | 74 | Yes |
| Heading (`# Text`) | 31 | Yes — this is the one Adrian saw |
| Italic (`*text*`) | 28 | **No.** A lone asterisk is a footnote, a wildcard and a separator far more often than it is emphasis |
| Link (`[text](url)`) | 12 | Marginal — see the caveat below |
| Fenced block, rule, image, blockquote | 4 / 6 / 5 / 2 | No — not worth a rule apiece |

### Three sizes, and they are genuinely different jobs

1. **Strip it on the cards — about five lines, half an hour.** A card blurb is flattened to one
   line already, so markdown there needs *deleting*, not interpreting: drop `**`, backticks, and a
   leading `#` or `- `. This alone fixes exactly what Adrian saw, across every card in the
   catalogue. The place for it is the plain-text half of the pair of text helpers in `stacks.js`
   that `PLAN_27` added.
2. **Render it in the details window — roughly sixty lines, half a day with testing.** Same
   technique as the bracket converter beside it: escape first, then convert a closed list of
   patterns, so the output can only ever contain tags we built ourselves. Covers about 350 of the
   391. Line-anchored patterns (`^#`, `^- `) are safe; inline ones need the same exact-match
   discipline the bracket work needed.
3. **A markdown library — recommended against.** It is a chunk of third-party code in a page that
   deliberately carries none, for 10% of one field. It also gives up the current guarantee that
   nothing from the catalogue can inject markup, which holds today only because every string is
   escaped before a fixed set of tags is put back.

### The caveat that should be read before anyone starts

**It will not fix the ugliest case.** Duplicati's overview reads, verbatim from the feed:

```
Duplicati(https://www.duplicati.com/) is a backup client that securely stores…
```

That was `[Duplicati](https://www.duplicati.com/)` before CA published it — the catalogue's own
pipeline ate the bracketed half and left the URL welded to the word. **1,049 overviews (28.7%)
contain a bare web address**, and an unknown share of those are this same damage. Nothing on our
side recovers it, so rendering markdown links properly buys much less than the 12-app count above
suggests.

### Related, and cheap if this is picked up anyway

`PLAN_27` unwraps CA's `[a href='…']` tags rather than turning them into real links — about twenty
occurrences, judged not worth the URL-safety plumbing on its own. If markdown links are being built
anyway, both routes can share the same scheme guard that already sits beside the icon handling in
`stacks.js`, and doing them together costs almost nothing extra.

---

## 2. A right-click menu in the editor

**What you would notice.** Right-clicking anywhere in the editor gives you the browser's own menu,
which knows nothing about compose files. Adrian asked for a custom one, 2026-08-17, and named a
generator for things like encryption keys as the item he wanted on it.

**Four things were measured before writing this, and each one changed what should be on the menu.**

1. **The menu already exists.** The stylesheet's own section is headed "Context menu", and
   `#staxx-menu` is a finished component: each item carries a glyph, a label, **and an explanation
   line underneath**, plus separators, a disabled state and a red variant for destructive things. It
   serves stacks, containers and folders today — Start, Stop, Update images, Logs, Edit, Delete, move
   to a folder. Editor items would be a second caller, not a new component. Two small gaps: it
   positions itself against a *button's* rectangle, so it needs to learn to position against a mouse
   point; and **nothing in the whole plugin listens for a right-click**, so despite the name that
   menu opens from a click.
2. **The page is served over plain HTTP.** Measured on the test box: `USE_SSL="no"`, port 80, no
   redirect to HTTPS. TLS is opt-in per server and access by LAN address is the norm, so a
   **non-secure context has to be the assumption**. Two hard consequences: a custom **Paste is
   impossible**, because reading the clipboard needs both a secure context and a permission grant;
   and **the browser cannot hash**, because `crypto.subtle` is secure-context-only. Random
   generation is unaffected — `crypto.getRandomValues` is not gated.
3. **The server can do the rest with nothing new installed.** Checked live: PHP gives bcrypt
   (`$2y$12$…`), `hash('sha256', …)` and `random_bytes()`; `openssl` and `uuidgen` are present.
   Argon2id is not in this PHP build and `htpasswd` is absent, but bcrypt is the format basic auth
   wants anyway.
4. **Nothing anywhere generates a value today** — no random string, no UUID, no free port, no
   password. The only near miss is the collision-avoiding *namer* that produces `NEW_VARIABLE` and
   `/data`, which is deterministic.

So the shape is: **generate in the browser, hash on the server, and never offer Paste.**

### A key is not a hash, and both are wanted

An encryption key or a token wants **random gibberish**. A hash is a one-way scramble *of* something
that already exists. They are two different menu items and both earn their place: *generate a secret*
for the value an app stores as-is, and *hash this for a login* for the handful that store a hash
instead — nginx-proxy-manager and Traefik basic auth being the ones people actually hit.

### The items worth having

**These are the valuable ones, because nothing else could offer them.** Each leans on data or
machinery already present:

| Item | Rests on |
|---|---|
| Generate a secret — a few shapes, the key under the caret choosing a sensible default | `crypto.getRandomValues`, which works over plain HTTP |
| Suggest a host port nothing is using | every container's published ports is already read for the network column; would want the server's own listeners too, since Unraid's 80 and 443 are not Docker's |
| Hash this for a login | bcrypt on the server, one more case on the existing endpoint |
| Insert Unraid's own user and group, 99 and 100 | `PLAN_31` made plain how often this is wanted — every linuxserver example ships the wrong ones |
| Insert this server's timezone | already read, for the same reason |
| Pick a folder that exists | the folder browser already exists behind a chip |
| Switch a port or mount between the short and the spelled-out form | the model has understood both forms since `PLAN_30`, and this is fiddly enough by hand that nobody does it |
| Hide this section | the show/hide machinery is complete — it moves a live block into `x-unraid` byte for byte and back again |
| Quote this value properly | the model already holds the quoting rules, including the ones that once sealed a whole file |
| Open this image's Docker Hub page, or look it up in the catalogue | `PLAN_31` already resolves an image's Hub address and refuses to invent one when there is none |
| Jump to the next problem | genuinely absent: a lint dot is a tooltip and nothing more — not focusable, not clickable. The findings are already sorted by line and the scroll-to-line helper exists, so a next/previous stepper needs no new data at all |
| Download this file, sanitised | the redact-for-screenshots mode already exists, and downloading works over plain HTTP where copying does not |

**Two ordinary editor commands are missing and would be used constantly.** Neither exists in any
form, and the whole Ctrl/Alt/Cmd space is free apart from Find and Replace:

- **Comment out / uncomment the selection**, indentation-aware. The most-used command there is in a
  YAML file — commenting a service or a volume out to try something is constant, and there is no way
  to do it but by hand, line by line.
- **Duplicate this block.** A second port, a second volume, a second service modelled on the first.
  Removing one is already mostly there (the model prunes emptied parents on its own); duplicating is
  not.

**Do not put these on it:**

- **Paste** — impossible over plain HTTP, as above. This on its own means the browser's menu must not
  be fully replaced.
- **Cut and Copy** — the browser's own already work, and a custom Copy would need a deprecated call
  that a secure context is quietly killing off.
- **Indent and outdent** — Tab and Shift+Tab already do this, over a multi-line selection too, and
  Enter already carries the indent down. Easy to propose by mistake.
- **Fold this section** — text folding does not exist in the Compose pane at all; the folding you see
  is form-pane markup. This is new work, not a second door onto something already built.
- **Explain this setting** — already there twice, as hover help and the ⓘ button. A third route is
  clutter unless the other two turn out not to be found.
- Spell-check suggestions, Inspect, Search-with — all lost the moment the native menu is replaced.

### Three sizes, genuinely different jobs

1. **Right-click opens the existing menu with three or four items** — generate a secret, comment out,
   jump to the next problem. Teach the opener to take a point, listen for `contextmenu` on the text
   pane, build the items. Half a day, and it would already be worth having.
2. **The full menu, aware of what the caret is on** — an `image:` line offers the Hub page, a port
   line offers the form swap, a password-looking key offers the generator first. Medium, and the
   interesting part is deciding what the caret is on, which the model can already answer.
3. **The same menu on form fields too.** Larger, and it overlaps the picker each field already
   carries — probably the wrong shape rather than more work.

### Caveats to read before anyone starts

- **Every edit must go through the one sanctioned write.** Text is written with a browser command
  chosen specifically so that native undo keeps working, and structural edits are bracketed by an
  undo snapshot and a standard "re-read and repaint" epilogue. A menu item that writes text any other
  way silently breaks Ctrl+Z, which is the one thing nobody would think to test.
- **A second right-click, or holding Shift, should give the browser's menu back.** That is the
  established convention and it is the whole answer to losing spell-check and Inspect.
- **There is no right-click on a tablet.** The stylesheet supports screens down to 36rem, but the
  page has no touch handling of any kind. Either a long-press and a visible ⋮ are part of the work,
  or the feature is simply absent there — which should be a decision rather than a discovery.
- **Keyboard access is part of it, not an extra.** Shift+F10 and the Menu key are how a context menu
  opens without a mouse; arrows move, Escape closes, focus returns where it was. The autocomplete
  list already has keyboard-navigable rows to copy, and Escape already has a queue of handlers in the
  editor that a new one has to join rather than fight.
- **A generated secret must be shown before it is written**, never quietly pasted over a value that
  is already there. And a generated value the reader does not keep is one they may already have used
  somewhere else, so generating again is not free.
- Honour `prefers-reduced-motion`, which the stylesheet already does elsewhere.
