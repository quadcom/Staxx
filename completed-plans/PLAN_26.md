# PLAN_26 — Give the Apps dialog a front page

## Context

The Apps dialog opens straight into a flat A-Z list of the whole catalogue. That is a fine answer
to "show me everything" and a poor answer to "what should I install?" — there is no way in except
knowing what to type.

This adds a **homepage**: three curated rows — Spotlight, Recently Added, Top Trending — shown
when the dialog opens. The homepage and the search results are two views of the same panel; only
one is ever on screen. Two buttons above the search box switch between them, and typing a search
switches to results on its own.

The measurements below were taken live from the catalogue feed on 2026-08-17. All three rows are
built from data the feed already carries, so nothing here is invented ranking.

| Row | Feed field | Usable apps | Notes |
|---|---|---|---|
| Spotlight | `RecommendedDate` / `RecommendedWho` / `RecommendedReason` | **41** | CA's own curation — SpaceInvader One, SpencerJ, ICH777, AlienTech42. Carries a one-line reason. |
| Recently Added | `FirstSeen` | 3,651 | Newest is today; the 60th newest is ten days old. |
| Top Trending | `trending` (percent download growth) | 2,040 | Top ten all have 20k–600k pulls, so no minimum-downloads guard is needed. |

**Decisions taken** (asked and answered): Spotlight is CA's Recommended list alone, not topped up
with popular apps; the view switches to results as you type, keeping today's live search; **Show
more** reveals six more cards in that section per click.

---

## The shape of it

### Server — build the three lists once, at index time

The three lists are the same for every user and change only when the feed does, so they are
computed during the index build and stored in `index.json`. That keeps the per-search path
untouched: no new per-entry fields, no growth in the file read on every keystroke.

**`scripts/ca-index.php`**

- `stackman_ca_index_entry()` (line 212) currently deletes `trends`, `downloadtrend`, `trendsDate`
  at line 239 and keeps nothing about recency. Keep that deletion — the trend *history* arrays are
  the bulk being stripped and are still not wanted. Instead return three extra scalars alongside
  `line`/`n`/`r`/…, all optional and all dropped before the entry is written to `index.json`:
  `fs` (`FirstSeen`, ignored below 1000000000 — the same junk-date guard `stacks.js:5034` already
  applies), `tr` (`trending`, a float), and for a recommended app `rec` (`RecommendedDate`),
  `rw` (`RecommendedWho`) and `rw`'s reason from `RecommendedReason.en_US`, falling back to the
  first value in that object.
- After the entry loop, before the `index.json` write at line 425, build a `home` block from those
  scalars and the ordinals just assigned. Thirty per section, deprecated apps excluded from all
  three — a spotlight or a trend line is a recommendation, and recommending an abandoned app is
  not something to do by accident.

```php
'home' => [
  'spot'  => [ ['i'=>123, 'who'=>'SpaceInvader One', 'why'=>'Lightweight swiss-knife-like VPN client'], … ],
  'new'   => [412, 88, 1901, …],
  'trend' => [900, 12, 77, …],
],
```

  Spotlight carries its blurb inline because only ~41 entries have one; putting `who`/`why` on
  every index entry to serve 41 of them would be the `dep`/`st` mistake the file already avoids.

- Bump `STACKMAN_CA_INDEX_VERSION` (`include/CA.php:51`) from 3 to **4**. This forces one full
  24 MB re-download per server, which is the known cost of a shape change and is already handled
  gracefully: `stackman_ca_status()` (`CA.php:105-111`) marks a version mismatch stale **but still
  usable**, so the old cache keeps serving while the rebuild runs behind the user.

**`include/CA.php`**

- Extract the per-hit row builder from `stackman_ca_search()` (lines 279-298) into
  `stackman_ca_row(array $app, int $i): array`, returning the existing `i, n, r, a, ic, c, d, ov`
  plus conditional `dep`/`st`. `stackman_ca_search()` calls it; so does the new home function.
  This is the only change to the search path.
- Add `stackman_ca_home(): array` returning `['spot'=>[…rows…], 'new'=>[…], 'trend'=>[…]]`, each
  row from `stackman_ca_row()` and each spotlight row carrying the extra `who`/`why`.
- **A v3 cache has no `home` block.** That is a real state, not a theoretical one — it is exactly
  what a user sees in the seconds after upgrading, while the stale-but-usable cache is still being
  replaced. Return three empty lists in that case and let the client say so.

**`include/action.php`** — one new case beside `ca-search` (line 442), `ca-home`, no parameters.
It mirrors `ca-search`'s state machine exactly (`failed` / `building` / `ready`, kicking off a
refresh on a stale cache) and returns `categories` and `count` as well, so the category `<select>`
still gets filled on open now that opening no longer runs a search.

### Browser — a second view of the same panel

**`include/StacksPage.php`** — a new fourth child of the dialog, between `.stackman-ca-head`
(line 640) and `.stackman-ca-find` (line 647):

```php
<div class="stackman-ca-tabs" role="tablist">
  <button type="button" class="stackman-ca-tab" id="stackman-ca-tab-home" aria-selected="true">Home</button>
  <button type="button" class="stackman-ca-tab" id="stackman-ca-tab-search" aria-selected="false">Search</button>
</div>
```

The search box and the category select stay visible on both views — you need the box on the
homepage to leave it, and changing the category is itself a search.

**`sheets/stack.manager.css`**

- `.stackman-ca` (line 4443) is `grid-template-rows: auto auto 1fr auto`. It gains a fifth row:
  `auto auto auto 1fr auto`. Missing this is how the list stops scrolling.
- New `.stackman-ca-tabs` / `.stackman-ca-tab` with a selected state, and `.stackman-ca-more` for
  the full-width bar under each section — style it on `.stackman-ca-app-more` (line 5018), which
  is already a text-button-in-a-bar.
- The section grids reuse `.stackman-ca-cards` (line 4563) unchanged. It is
  `repeat(auto-fill, minmax(23rem, 1fr))`, which gives three across at the dialog's normal width
  and degrades to two then one on a narrow screen — so "two rows of three" is what it renders at
  full size without a fixed three-column rule that would break when the dialog is narrow.

**`javascript/stacks.js`**

- New state beside the block at 4553: `caView` (`'home'` or `'search'`), `caHome` (the reply), and
  `caShown` (`{spot: 6, new: 6, trend: 6}`).
- `caHomeFetch()` — POSTs `ca-home`, handles `building` with the same 3 s self-poll `caSearch()`
  uses at 4846, then renders.
- `caRenderHome()` — three sections, each an `<h4 class="stackman-ca-group">` heading like the
  search view's, a `.stackman-ca-cards` grid of the first `caShown[key]` cards, and a Show-more
  bar when more remain. **Cards are `caCardHtml()` verbatim** — home rows carry the same global
  ordinal `i` in `data-i` and `data-add`, so the existing click, keyboard and broken-icon handlers
  (5202, 5221, 5242) work with no change at all.
- `caCardHtml()` gains one `if`: when `app.why` is present, a `.stackman-ca-cardrec` line reading
  the reason and who gave it. That is the whole point of a spotlight — it says why.
- `caShowView(view)` — sets `caView`, flips `aria-selected` on the two tabs, and calls
  `caRenderHome()` or `caRenderAll(true)`.
- `caOpen()` (4870) calls `caHomeFetch()` in place of `caSearch()` at 4886, and resets `caView` to
  `'home'` and `caShown` to sixes. The one-shot `images` call at 4891 stays, but its
  `caRenderAll(false)` must become a no-op while the home view is up.
- The input handler (5299-5325) keeps both debounces; the 250 ms catalogue branch switches to the
  search view when the box is non-empty, and **back to the homepage when it is emptied**. The
  category `change` handler (5327) switches to search too.
- Show-more clicks are delegated on `caList` alongside the existing handlers: `+= 6` on that
  section, re-render. Cap is the 30 the server sent.
- `caFooterMsg()` (4788) gains a home branch — it currently reads `caBox.value` and `caApps`
  directly and would otherwise describe a search nobody ran.

---

## Left out

- **Any new sort control on the search results.** The three lists are a front page, not a sort
  order; wiring "newest first" into `stackman_ca_search()` is a separate question.
- **Per-section categories** ("trending in Media"). The home lists are whole-catalogue.
- **Remembering which view you were on** between opens. It opens on the homepage, every time.
- Docker Hub and local-image groups on the homepage. Both are searches by definition — they have
  nothing to show until you type.

---

## Verification

No local runtime, so the real check is the test box.

1. `node --check` on `stacks.js`, then `node tests/js_undeclared.js` — several new names go into
   the strict-mode IIFE, which is exactly what that second check exists to catch.
2. `php -l` over `include/*.php` and `scripts/ca-index.php` after the deploy.
3. **Force the rebuild and read the block**, on the server: delete `/tmp/stack.manager/ca`, trigger
   a refresh, then a throwaway PHP script printing `stackman_ca_home()`. Expect 30 spotlight rows
   led by the most recent recommendation, 30 recently-added led by today's date, and 30 trending
   led by roughly 60% growth. Confirm none carries `dep`.
4. Confirm the **stale-cache path** by hand: with a v3 `index.json` in place, `ca-home` must return
   three empty lists and a readable message rather than a PHP notice.
5. In the browser: the dialog opens on the homepage; Show more turns 6 into 12 into 18 without
   moving the other two sections; typing flips to results and clearing the box flips back; Search
   with an empty box still gives the A-Z browse; the category select still fills; clicking a
   spotlight card opens the same details window a search result does, and its Add button works.
6. Check it at a narrow window width — the card grid must fall to two across and then one without
   the dialog scrolling sideways.
