# PLAN_27 — Give the Apps window room, and read what the catalogue actually wrote

**Status: COMPLETE, 2026-08-17.** Built and deployed to the test box; every part of it is in the
tree. This line was added retrospectively — the plan was filed as complete without one, which is
why it read as unfinished.

## Context

Three things came out of using the Apps dialog for real.

1. **It is cramped.** The Apps window is `min(84rem, 94vw)` — *exactly half* the editor's
   `min(168rem, 96vw)`. Adrian asked for them to match.
2. **App detail pages look like they have broken thumbnails.** They do not. Measured against the
   live catalogue on the test box (3,651 apps): **296 apps — 8.1% — carry a `Screenshot` field**,
   none of them malformed, and four sampled URLs all load in Chrome with the page's
   `referrerpolicy="no-referrer"` intact. The screenshots section is fully built on both sides
   already. The reason it never appears is the list Adrian has been clicking: **Spotlight has
   screenshots on 1 of its 30 apps**, Recently Added 7 of 30, Top Trending 5 of 30. EmbyServer, the
   one he named, has none. Nothing to fix; two loose ends worth tidying (§3).
3. **Bracket markup leaks into the text.** CA's own templates use a BBCode-ish markup that Unraid's
   Apps page renders and we do not. EmbyServer's overview is literally
   `…Mono.[br][br]\n    [b][span style='color: #E80000;']Directories:[/span][/b][br]…`.
   **340 overviews (9.3%) carry it**, and **zero overviews contain raw HTML tags** — so there is no
   sanitising problem, only a rendering one.

Decisions taken (asked and answered): match the editor's size exactly; fix the homepage at **four
cards per row, eight per section**; **render** the markup properly rather than stripping it.

---

## 1. Size — `sheets/staxx.css`

**`.staxx-ca` (line 4443)** — two values, copied from `.staxx-modal` (1687-1688):

```css
width: min(168rem, 96vw);    /* was min(84rem, 94vw)  */
height: min(94vh, 108rem);   /* was min(80vh, 76rem)  */
```

Leave `grid-template-rows: auto auto auto 1fr auto` alone — the five children are unchanged.

**Phone breakpoint.** `.staxx-ca` appears **nowhere** inside the `@media (max-width: 45rem)`
block (opens at 5678), so today it stays a centred 94vw box on a phone while the editor goes
edge-to-edge. Now that they are meant to match, add `.staxx-ca` (and `.staxx-ca-app`, if it is
likewise absent — check) to that block using `.staxx-modal`'s own override at 6252-6261 verbatim:
`inset: 0; margin: 0; width: 100%; height: 100dvh; max-width: none; max-height: none; border: 0;
border-radius: 0;`.

**`.staxx-ca-app` (the details window, 4964)** — a judgement call, not something asked for:
at `min(68rem, 90vw)` it will look lost opening on top of a 1680px parent. Grow it to
`width: min(104rem, 92vw); height: min(88vh, 96rem);` — deliberately still smaller than its parent,
so it reads as a panel over the list rather than a replacement for it. Its screenshot strip stays a
sideways-scrolling row; it just fits more thumbnails now.

## 2. Four across, eight per section

`.staxx-ca-cards` (4563) is `repeat(auto-fill, minmax(23rem, 1fr))` and is shared by both views.
**Search results keep it** — filling the width is right there. The homepage needs a fixed count, so
add a modifier used only by `caRenderHome()`:

```css
.staxx-ca-cards--home { grid-template-columns: repeat(4, minmax(0, 1fr)); }
@media (max-width: 100rem) { .staxx-ca-cards--home { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width:  72rem) { .staxx-ca-cards--home { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width:  45rem) { .staxx-ca-cards--home { grid-template-columns: minmax(0, 1fr); } }
```

Keying on the viewport rather than the dialog is correct here **because the dialog width tracks the
viewport** (`96vw`) at every width below 175rem. `minmax(0, 1fr)` not `1fr` — a bare `1fr` refuses
to shrink below its content and a long repo name would push the grid sideways.

**`javascript/stacks.js`** — three small edits in the `ca*` block:

- `caShown` initialiser and the `caOpen()` reset: `{spot: 6, new: 6, trend: 6}` → **8s**.
- The `[data-more]` click branch: `+= 6` → **`+= 8`**.
- `caRenderHome()` emits `class="staxx-ca-cards staxx-ca-cards--home"` on each section grid.

**`scripts/ca-index.php`** — each home list is sliced to 30, which is not a multiple of four, so the
final Show more would leave a two-card row. Change the three slices to **32**. This does *not* need
an index version bump: the shape is unchanged and a cache still holding 30 just renders a short last
row until the next natural rebuild.

## 3. Render the bracket markup — `javascript/stacks.js`

`caTextHtml()` (5191) is `esc(s)` plus newline→`<br>`, and its comment declares markdown deliberately
unrendered. That reasoning stands for *markdown*; this is a different thing — a fixed, twenty-tag
markup that the catalogue's own publisher renders. Rewrite the comment to say so.

**Escape first, then convert.** `esc()` neutralises `<` and `>`; brackets pass through untouched, so
converting afterwards can only ever emit tags we construct ourselves. Attribute values are dropped
entirely, never re-emitted — which matters because `esc()` does not escape `'` and the `span` tags
carry `style='…'`.

**The allowlist must match exact tag text, not a prefix.** This is the trap: the catalogue is full of
literal bracketed placeholders — `[IP]` (25), `[PORT]`, `[port:5001]`, `[unraid-ip]`,
`[public ip section]`, and one app that lists forty DDNS providers as `[aliyun]`, `[godaddy]`,
`[namecheap]`… A pattern like `\[p[^\]]*\]` would swallow `[port]`. Anything not on the list below
must survive **exactly as typed**.

| Tag (counts from the live feed) | Becomes |
|---|---|
| `[br]` 2363, `[br/]` 26, `[/br]` 9 | `<br>` |
| `[b]`/`[/b]` 732, `[strong]`/`[/strong]` 2 | `<strong>` |
| `[u]`/`[/u]` 65 | `<u>` |
| `[i]`/`[/i]` 9 | `<em>` |
| `[code]`/`[/code]` 102 | `<code>` |
| `[h1]`…`[h6]` 5 | `<br><strong>` … `</strong>` |
| `[li]` 106 / `[/li]` 14 | `<br>• ` and nothing — **not** `<li>`. Six in seven are unclosed and have no `[ul]` around them; treating them as real list items produces broken markup |
| `[p]` 12, `[/p]` 9 | `<br>` and nothing |
| `[span …]` 142, `[ul]`/`[ol]`/`[center]`/`[color=…]`/`[url]`/`[a href=…]` and their closers | unwrapped — the text is kept, the tag and any styling dropped |

Colour is dropped on purpose: the dialog has its own palette and a hard-coded `#E80000` on a dark
panel is not ours to honour. Links are unwrapped rather than turned into anchors — ~20 occurrences,
and Project / Support / Read Me already sit at the top of every detail page as real links.

**Two output modes, one tag list.** Keep a single regex constant and two thin functions:

- `caTextHtml(s)` — the detail view (5256 Description, 5266 Additional requirements). Full
  conversion, keeping today's newline→`<br>`.
- `caPlainText(s)` — the **card blurb**, which has the same problem: the index stores `ov` raw, so
  cards currently read `FTB Skies 2 Modded Minecraft Server[br][br][b]FTB Skies 2[/b][br]…`. Cards
  want one flat string, so here every tag is removed and `[br]`/`[li]` become a space, with runs of
  whitespace collapsed. Call site is the card description at **stacks.js:4721** (`esc(app.ov)`).

**One edge case that will bite if missed:** `ov` is cut at 160 characters server-side
(`ca-index.php:265`), so a blurb can end mid-tag — `…updated mod[b`. Both functions must drop a
trailing `[` run that has no closing `]`.

## 4. Screenshots — the two loose ends

No feature work. `caFillDetails()` (5279-5283) reads `app.Screenshot` and `app.Photo`:

- **`Photo` appears on zero apps** in the feed. Dead branch — remove it.
- **`Screenshots` (plural) exists on one app** and is not read. Add it.

Also: `PLAN_23.md` (lines 75, 90) still describes screenshots as stripped at index time. That has not
been true since `ca-index.php:232-241` was written; correct the prose so it does not mislead later.

---

## Left out

- Any change to how screenshots are laid out. A sideways strip is what Unraid does and the taller
  window already improves it.
- A markdown or entity library. The feed's overviews contain **no HTML tags at all** (measured), so
  the only markup to handle is the bracket list above. Note in passing: a literal `&amp;` in the feed
  is double-escaped by `esc()` today — pre-existing, cosmetic, and not in scope here.
- Cleaning the markup at index build time. Doing it in the browser needs no version bump and no
  24 MB re-download, and the detail view reads the raw record regardless so a browser-side helper is
  needed either way.
- Turning `[a href=…]` into real links.

---

## Verification

1. `node --check` on `stacks.js`; `node tests/js_undeclared.js` — two new function names go into the
   strict-mode IIFE.
2. `php -l scripts/ca-index.php` on the server after deploy.
3. **Size**: open Apps and the editor side by side — same width, same height. Drag the browser
   narrow and confirm the card grid steps 4 → 3 → 2 → 1 and that the dialog goes edge-to-edge below
   45rem, exactly as the editor does. Confirm the list still scrolls (a wrong `grid-template-rows` is
   how that breaks).
4. **Rows**: homepage shows two full rows of four per section; Show more takes it to 16, then 24,
   then 32. Search results still fill the width rather than locking to four.
5. **Markup**: open **EmbyServer** from Spotlight — the description must show a blank line, a bold
   "Directories:", then bold `/config` and `/mnt` on their own lines, with no square brackets
   anywhere. Then open an app whose text contains `[IP]` or `[PORT]` (the DDNS updater is the
   clearest case, with its forty bracketed provider names) and confirm **every bracket is still
   there**. Check a card blurb too — no `[br]` on the homepage cards.
6. **Screenshots**: open **TeslaMate** (the only Spotlight app of the thirty that has any) and
   confirm the strip renders. This is a regression check, not a fix.
