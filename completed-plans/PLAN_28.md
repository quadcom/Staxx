# PLAN_28 — Page the Apps homepage instead of growing it

## Context

"Show more" on the Apps homepage adds eight cards to a section and re-renders the whole homepage to
do it. Two faults compound:

- `caRenderHome()` rewrites `caList.innerHTML` and then sets **`caList.scrollTop = 0`**
  (`stacks.js:4842`), unconditionally. Every click throws you to the top of the window.
- The new eight are appended *below* the two rows you were looking at, so after that jump they are
  off the bottom of the fold. Nothing you asked for is on screen.

It reads as a flash to the top with no reward. `caRenderAll()` — the search view's equivalent —
already takes a `resetScroll` argument for exactly this reason (`stacks.js:4774, 4802`), with a
comment that an incremental update "must not yank the list back to the top on the user." The
homepage never got the same treatment.

**What replaces it.** Each section stays **two rows tall at every screen size** and *pages*. Clicking
down slides the current two rows up and out while the next two slide up into their place. Two glyph
buttons, up and down, sit under the cards where the "Show more" bar is. Both directions **wrap**, so
neither button is ever dead.

Decisions taken (asked and answered): wrap at the end; just the two arrows, no dots and no wording;
under the cards rather than beside the heading; arrows point up and down because the motion does;
two rows at every breakpoint, with tablets on two or three columns and phones on one.

---

## 1. The key idea: let CSS own the columns, and clip to two rows

"Two rows always" means the number of cards on a page changes with the column count — 8 at four
columns, 6 at three, 4 at two, 2 at one. Rather than teach JavaScript what a breakpoint is (which
would duplicate every media query in a second place and rot the first time one moved), **render the
whole list into one grid and let the deck clip it to two rows.**

The column count then stays entirely CSS's business, and "a page" is defined by measurement rather
than arithmetic: read where each grid row actually starts, and slide by two rows at a time. This is
both simpler than a two-grid swap and the only version that satisfies the requirement without
duplicating the breakpoints.

## 2. Markup — `caRenderHome()` in `javascript/stacks.js`

Heading, a **deck** that clips, a **track** that slides, one grid holding **all** of that section's
cards, and a pager:

```html
<h4 class="stackman-ca-group">Spotlight Apps</h4>
<div class="stackman-ca-deck" data-deck="spot">
  <div class="stackman-ca-track">
    <div class="stackman-ca-cards stackman-ca-cards--home">…all 32 cards…</div>
  </div>
</div>
<div class="stackman-ca-pager">
  <button type="button" class="stackman-ca-step" data-page="spot" data-dir="-1"
          aria-label="Previous two rows" title="Previous">▴</button>
  <button type="button" class="stackman-ca-step" data-page="spot" data-dir="1"
          aria-label="Next two rows" title="Next">▾</button>
</div>
```

Cards are `caCardHtml()` unchanged. **Omit the pager when the section fits in two rows** — there is
nothing to step through, which is also the state a short list or a v3 cache produces.

**Do not wrap each section in a container.** `.stackman-ca-group:first-child` (`css:4752`) kills the
top margin on the first heading and depends on the heading being a direct child of the list. Keep
heading, deck and pager as three flat siblings.

**Cost of rendering all 32:** three sections of 32 is 96 cards rather than today's 24. That is the
same order as the search view's 60 and no more than paging to the end reaches today; card icons carry
`loading="lazy"` already. Worth knowing, not worth avoiding.

## 3. Breakpoints — `sheets/stack.manager.css`

Replace the current 4 / 3 / 2 / 1 steps on `.stackman-ca-cards--home` (`css:4627-4647`) with:

| Viewport | Columns | Cards per page | Typical device |
|---|---:|---:|---|
| above 1200px | 4 | 8 | desktop |
| 897–1200px (`≤75rem`) | 3 | 6 | tablet landscape |
| 577–896px (`≤56rem`) | 2 | 4 | tablet portrait |
| 576px and below (`≤36rem`) | 1 | 2 | phone |

Three columns at 897px gives roughly 270px cards; two at 768px gives about 355px, which is the more
comfortable of the two on a portrait tablet — hence the split at 896 rather than pushing three
columns further down.

**A rem inside a media query is not this page's rem.** The existing comment at `css:4619-4626` records
this the hard way: media queries are evaluated before any element carries the 10px root, so their rem
is the browser's initial 16px. `75rem` is 1200px, `56rem` is 896px, `36rem` is 576px. Keep that
comment and update its worked example to the new numbers.

Also fix the stale claim at `css:4610-4611` that the homepage "always shows a fixed eight cards per
section" — eight was only ever two rows at four columns, and the count is now derived from the
layout rather than fixed.

## 4. Measuring a page

Two small helpers, both driven off the live grid so neither knows anything about breakpoints:

- **`caDeckRows(grid)`** — walk the grid's children and collect each distinct `offsetTop`. Cards in
  the same grid row share one, so the result is one entry per row, in order. Row count is its length;
  page count is `Math.ceil(rows / 2)`.
- **`caDeckFit(key, animate)`** — for the section's current page `p`, set the deck's height and the
  track's transform:
  - offset = `tops[2p]`, applied as `translateY(-offset px)`
  - height = `(tops[2p + 2] !== undefined ? tops[2p + 2] - rowGap : grid.offsetHeight) - tops[2p]`

  `rowGap` comes from the grid's own computed style, never a hard-coded 10px. The final page is
  usually short — one row, or a partial one — and the `grid.offsetHeight` branch handles it without a
  special case.

Card heights vary (names clamp at two lines, descriptions at three), so grid rows are not all the
same height. Measuring each row's real top is what makes two rows land exactly on the boundary
instead of half-clipping the third.

## 5. Stepping and resizing

**State** replaces the reveal count with a page index: `caShown = { spot: 8, … }` becomes
`caPage = { spot: 0, new: 0, trend: 0 }`, reset in `caOpen()` (`stacks.js:5034`) where `caShown` is
reset today.

**`caPageStep(key, dir)`** replaces the `[data-more]` branch of the delegated click handler
(`stacks.js:5458-5459`) with a `[data-page]` branch reading `dataset.page` and `dataset.dir`. It
recomputes the page count, wraps with `(caPage[key] + dir + pages) % pages`, and calls
`caDeckFit(key, true)`. **No DOM is inserted or removed** — the whole motion is two animated property
changes on elements that already exist, so there is no transition-end cleanup, no orphaned node to
tidy, and no lock needed: a click mid-slide simply retargets the transition, which is what a
carousel should do anyway.

Delegation stays on `caList`, so the existing card-click, keydown and capture-phase icon-error
handlers keep working untouched. The pager buttons are real `<button>`s, so Enter and Space reach the
click handler for free, exactly as `.stackman-ca-more` does now.

**Resize.** Changing the window changes the column count, which changes the rows, which changes both
measurements. Add a debounced `resize` listener, active only while the dialog is open and the home
view is up, that clamps each `caPage[key]` to the new page count and calls `caDeckFit(key, false)`.
The codebase already mirrors a CSS breakpoint in JS with `NARROW` (`stacks.js:986, 1032`), but this
needs no breakpoint at all — it just re-measures.

**First paint.** Set each deck's height in the same tick as the `innerHTML` write, before the browser
paints, or all 32 cards flash on screen before the clip applies.

## 6. CSS for the deck

```css
.stackman-ca-deck  { overflow: hidden; transition: height    var(--sm-motion-height) var(--sm-ease-height); }
.stackman-ca-track { transition:       transform var(--sm-motion-height) var(--sm-ease-height); }
.stackman-ca-track--still, .stackman-ca-deck--still { transition: none; }
.stackman-ca-pager { display: flex; justify-content: center; gap: 0.6rem; margin-top: 0.6rem; }
```

The `--still` classes are what `caDeckFit(key, false)` uses for the initial fit and for a resize, so
neither animates. **Suppress with a class, never an inline `style.transition`** — an inline value
would also override the reduced-motion rule, which must always win.

**Reuse `--sm-motion-height` / `--sm-ease-height` (`css:132-133`) rather than inventing a token.** The
comment above them was written for the folder accordion and argues this exact case: at a distance of
hundreds of pixels 180ms reads as a blink rather than a movement, and `ease-out` spends its time
decelerating so the eye misses the part that says "this is sliding". 260ms on a curve that starts
fast and settles is precisely "noticeable but not painful". (These tokens live on
`.stackman-scaffold`, not `:root`; the dialog inherits them as a DOM descendant, which the existing
`.stackman-ca` transition already relies on.)

`.stackman-ca-step` styles on `.stackman-ca-more` — quiet, borderless, `--sm-muted`, coming forward to
`--sm-accent` on hover and focus — scoped under `.stackman-scaffold`, because Unraid's own sheet
carries `.unapi button { color: inherit }` at a specificity that beats a bare class. **Delete the
`.stackman-ca-more` rules** (`css:5175-5198`) once nothing emits that class; not
`.stackman-ca-app-more`, which is the separate control in the details window.

**Why a CSS transition and not a JS-driven scroll.** The reduced-motion block already wildcards
`.stackman-scaffold *` to 1ms, so a CSS transition is covered with no new rule — whereas
`scroll-behavior: smooth` and a JS `behavior: 'smooth'` are covered by nothing in this sheet. The
codebase never branches on `prefers-reduced-motion` from JavaScript and this should not be the first
thing to.

## 7. What this fixes for free

- **The scroll never moves.** Paging no longer calls `caRenderHome()` — it changes two properties on
  one deck. `caList.scrollTop = 0` stays where it is, still correct for a genuine first render
  (opening the dialog, switching back from Search); say so in a comment rather than deleting it.
- **Keyboard focus survives a step.** The pager sits outside the deck and is never re-rendered, where
  today a "Show more" press destroys the very button that was focused.

---

## One consequence to be aware of

Holding two rows at every width means more pages on smaller screens: four pages on a desktop, six on
a landscape tablet, eight on a portrait one, and **sixteen on a phone**, where one column of two
cards is a page. That is what "always two rows" implies. It is easy to revisit later — a minimum of,
say, four cards per page on the narrowest breakpoint would cap it — but it is a change to the rule
just agreed, so it is not being built in now.

## Left out

- Page dots, page numbers, or a count — asked and declined.
- Auto-advance, swipe gestures, and arrow-key paging beyond the buttons' native Enter and Space.
- Paging the search results. Those are a flat list of matches you scroll, which is right for them.

## Verification

1. `node --check` on `stacks.js`, then `node tests/js_undeclared.js` — several new names go into the
   strict-mode IIFE, which is what that second check exists to catch. `node tests/yaml_roundtrip.js`
   as the standing regression.
2. **The reported bug**: click down on Spotlight and confirm the window's scroll position does not
   move, the old two rows travel up out of the deck, the next two arrive in their place, and the other
   two sections stay put.
3. **Two rows at every width**: at each of the four breakpoints confirm exactly two rows are visible,
   with no third row half-showing at the bottom edge and no gap beneath the second.
4. **Uneven rows**: Spotlight's cards are not all the same height. Confirm the deck grows and shrinks
   with the incoming pair rather than clipping or leaving a gap, and that the boundary lands between
   rows rather than through one.
5. **Wrapping**: step down past the last page and confirm you land back on the first two rows. Step up
   from the first and confirm you land on the last.
6. **The short last page**: at three columns, 32 cards is five full rows plus two — confirm the final
   page shows the remainder cleanly rather than clipping or over-scrolling.
7. **Resize mid-page**: page to the last set, then narrow the window across a breakpoint. Confirm the
   deck re-measures, still shows two rows, and does not leave the page index pointing past the end.
8. **Reduced motion**: turn on the emulated `prefers-reduced-motion` setting in Chrome's rendering
   panel and confirm the swap is instant and complete.
9. **Keyboard**: tab to a down arrow, press Enter twice, confirm focus is still on that arrow.
10. **Untouched behaviour**: a card inside a deck still opens the details window, its Add button still
    works, and a broken icon still falls back to initials.
