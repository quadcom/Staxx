# PLAN_12 — The compose pane becomes a real editor

**Status: complete.** All five phases built, deployed and checked in the browser, 2026-08-14, with
one deliberate change of shape: A5's folding and minimap were replaced by a structure outline — see
that section for why. A4 was split into A4a (autocomplete and hover help, pure client work) and A4b
(path checking, which needed new PHP and a server round trip). 792 tests passing (614 before), and
the highlighter's reconstruction invariant
separately fuzzed over 200,000 random lines with no failure. Alignment confirmed by making the
textarea's own text visible in red over the ink: the glyphs land on each other exactly, no offset
and no doubling. Marker dots confirmed centred by measuring a dot's centre against the ink line
element for the same index.

Colours were lifted from a 60/40 mix towards the foreground to 45/55 after the first look — the
first pass was legible but washed out. See the note in the sheet for why 45 is the floor.

**Known cost, accepted for now.** `paintInk()` matches lines by index, so typing inside a line
repaints one line, but pressing Enter shifts every line below it and repaints the lot. A common
prefix/suffix diff would cut that to one or two, and is worth doing only if a large file actually
feels slow — measure before building it.

The compose pane is a plain `<textarea>` with line numbers and a Tab key that types two spaces. It
has no colour, no auto-indent, no search, and nothing tells you a line is wrong until Save runs
`docker compose config` and it refuses. Fine for reading a file, poor for writing one.

This turns it into a code editor without adding a library — there are none in this project and there
will not be. Five phases, each deployable and visible on its own.

## The one idea the whole thing rests on

`#stackman-yamlmarks` (`StacksPage.php:273`) already proves an absolutely-positioned layer lines up
exactly with the textarea's text: the box never wraps (`wrap="off"`, `white-space: pre`) and the line
height is a fixed number, so line N sits at exactly `(N-1) × lineHeight`. That is how the orange
highlight band finds its row.

Syntax colour is the same trick with a second layer. Add a `<pre>` holding a coloured copy of the
text, make the textarea's own text transparent, and what you see is the `<pre>` while what you type
into is still a real textarea. Caret, selection, undo, paste, spellcheck and non-English input all
stay native, which is the whole point — those are what hand-rolled editors get wrong.

## Phase A1 — Colour and indentation

### The ink layer

A new `<pre class="stackman-yamlink" id="stackman-yamlink" aria-hidden="true">` inside
`.stackman-yamlwrap`, between `#stackman-yamlmarks` and the textarea, so the highlight bands stay
behind the letters. `aria-hidden` because a screen reader should read the textarea, not a decorative
copy of it.

Three things must match exactly or the letters drift apart from the caret:

- font family and size — the same `font` shorthand as `.stackman-yaml`
- `line-height` — the fixed `1.45` the band maths already depends on (`css:3074-3078`)
- padding, **including** the dynamic `padding-left` that `paintGutter()` measures back from the
  gutter's real width (`stacks.js:652-654`). The ink layer is set in the same place, from the same
  measurement.

The textarea gets `color: transparent` and keeps `caret-color` explicit, because a transparent
colour takes the caret with it in some browsers.

Selection is the one thing that cannot be transparent — a textarea's `::selection` paints over its
own text, so the highlight stays visible and the ink under it does not. That is acceptable and is
what every editor of this construction does.

### Scroll sync

`syncGutter()` translates the number column vertically against `scrollTop`. The band layer never
needed horizontal sync because a band spans the full width. Ink does, so it translates on **both**
axes, and the `scroll` listener (`stacks.js:663`) picks it up.

### The tokeniser lives in `compose-model.js`

Not in `stacks.js`, for two reasons. It needs what `classify()` (`:56`) already knows about a line,
and — the deciding one — there is no browser on the dev machine, so nothing in `stacks.js` can be
tested at all, while `compose-model.js` is already run headlessly by `tests/yaml_roundtrip.js`.

New export:

```js
API.highlight(line, carry) -> { html, carry }
```

One line in, escaped HTML with `<span class="stackman-tok stackman-tok--key">`-style wrappers out,
plus a carry value for the next line. The carry exists because two things in YAML are not line-local:

- a **block scalar** (`|` or `>`) — every more-indented line below it is literal text, not YAML, and
  must not be coloured as keys and values
- a **flow collection** (`[` or `{`) left open at the end of a line

Token classes: `key`, `str`, `num`, `bool` (also null), `comment`, `anchor` (also aliases, tags and
merge keys), `punct`, `var` for `${...}` inside a value, and `text` for block-scalar body lines.

It must never throw. A line it cannot make sense of comes back as one `text` span — an editor that
stops colouring is a nuisance, one that stops working is a bug.

### Repaint cost

Rebuilding a 400-line file's HTML per keystroke is waste. The layer keeps one `<span>` child per line
plus an array of the source text it last drew. On input it walks the two and rewrites only the lines
whose text changed — one line, for ordinary typing. A line whose *carry out* changed forces the lines
below it to be rechecked until the carry agrees again, which is what makes opening a `|` block
recolour everything under it and closing one put it back.

Same guard as `paintGutter()` (`:638`): if nothing changed, return without touching the DOM.

### Indentation keys

All on the textarea's existing `keydown` handler (`stacks.js:4065`), all through
`document.execCommand('insertText')` with the `setRangeText` fallback that is already there — that is
what keeps the browser's own undo stack working, and the reason it is written that way is worth
keeping.

| Key | Behaviour |
|---|---|
| Tab, no selection | two spaces (unchanged) |
| Tab, selection spanning lines | indent every touched line by two |
| Shift-Tab, no selection | remove up to two spaces before the caret |
| Shift-Tab, selection | outdent every touched line by up to two |
| Enter | copy the previous line's indent; add two more if that line ends in `:` or is a bare `-` |
| Backspace, caret in leading whitespace | delete back to the previous multiple of two |

**Never a real tab character.** YAML forbids tabs for indentation and a file containing one is sealed
whole by `compose-model.js:459` — the right guard, and this is the code that stops you tripping it.

### Indent guides

Faint vertical lines every two columns, as a `repeating-linear-gradient` on the leading-whitespace
span of each line — which is why that whitespace is its own token kind (`indent`) rather than plain
`text`. Put on the pane behind everything instead, the gradient draws a line down every other column
of the *whole* file and strikes through the middle of comment prose; on the span it spans exactly the
indentation, which is the only place a line's depth is in question. Still no per-line JavaScript.

The line is a whole pixel wide, not a fraction of a character: `0.1ch` is about three quarters of a
pixel, which the browser cannot draw, so it antialiases across a full pixel at partial strength and
the guide comes out fainter than its colour asked for and blurred with it.

### Colours

CSS custom properties on `.stackman-yamlink`, one set per theme, following how the sheet already
handles Unraid's light and dark. They must clear WCAG AA against the pane background in both, since
this is body text and not decoration.

### Verifying A1

Locally: `node tests/yaml_roundtrip.js` (new cases for `highlight()` — a block scalar swallowing its
body, a flow list left open across lines, a `#` inside a quoted string that is not a comment, a
`${VAR}` inside a value, an anchor, a line ending in a colon), `node tests/js_undeclared.js`,
`node --check` on both scripts.

In the browser, and only you can answer these: do the letters and the caret sit on the same pixels
in both themes; does the alignment survive the dialog's width animation between Form and Split; does
horizontal scrolling keep the ink under the gutter instead of sliding out from under it; does typing
in a 500-line file still feel instant; does Ctrl-Z still undo typing.

## Phase A2 — Error markers, and the form gate

A marker column in the gutter. A red dot means the parser could not read that line, an amber dot
means it read it but something is off. Hovering gives the sentence.

Duplicate keys come free — `parse()` already records them in `doc.warnings`. Whole-file failures
(tab indentation, a `%YAML` directive, a multi-document file, an unreadable region) come from
`doc.sealed`; every *other* seal reason is deliberately silent, because an anchor or a flow list is
legitimate YAML the author chose, not a problem to nag about.

**The unknown-key check needed its own vocabulary, and this is the trap in it.** `KEYS` is not the
compose specification — it is the list of service keys the *form* renders, about 40 of roughly 90
compose accepts. Linting against it would flag `sysctls`, `build`, `ulimits`, `extra_hosts` and
dozens more perfectly valid keys, and a gutter that cries wolf is one nobody reads. So `lint()` uses
two lists of its own, `TOP_SPEC_KEYS` and `SERVICE_SPEC_KEYS`, kept deliberately separate from
`KEYS` — merging the two would be a bug, and the comment above them says so. Keys beginning `x-` are
skipped at every level, and so is anything inside a sealed region. An unknown key within an edit
distance of two of a real one says which: *Did you mean "environment"?*

Real validation stays where it is — `docker compose config` on save (`Stacks.php:1104`), which takes
seconds and cannot run while you type. What is new is that when it refuses, the line its error names
gets a red dot and is scrolled to.

**The form gate.** Switching views already re-syncs. The missing case was failure: with `form.ok`
false there are no services, so the form drew itself almost empty — which reads as "this file has no
containers" when the truth is "this file could not be read". It now shows a panel with the reason
instead, and the Form and Split buttons are disabled with that reason as their tooltip.

It does **not** force the view back to Compose, which is a deliberate departure from the first draft
of this plan. Typing in the Compose pane makes a file transiently unreadable constantly — mid-word,
mid-line — so a forced view change would throw the layout about on a 400 ms debounce, far worse than
the problem it solves. Disabling the buttons is what stops you *entering* a broken form; the panel
is what explains it if you are already looking at one.

## Phase A3 — Find and replace

A bar at the top of the compose pane. Ctrl-F find, Ctrl-H replace, Escape closes the bar — and must
not close the dialog, so the `cancel` handler (`stacks.js:4019`) has to learn about it. Enter and
Shift-Enter step, with a "3 of 17" count, wrap-around, a case tick and a regex tick.

Matches draw into `#stackman-yamlmarks` — `repaintMark()` was extended to draw them after the band,
so every place that already refreshed the overlay (scroll, view switch, resize, caret move) keeps the
hits right for free rather than needing a second layer and a second set of call sites. Only the hits
in the visible line range are drawn, plus always the current one; the counter still reflects all of
them, because drawing five thousand boxes nobody can see would freeze the page.

Positioning a hit needs a character width, which nothing measured before — a band spans the full
width so it never needed one. `measure()` now works out `CHAR_W` from a probe span in the pane's own
font, once, beside `LINE_H` and `PAD_T`. Measured in the browser, a hit box lands within 0.02px of
the real glyph rectangle.

Replace-all pushes one entry onto the existing undo stack (`pushUndo` `:2566`) so it comes back in
one step.

**The `$` trap, which is where this could have silently corrupted a file.** With regex on, `$1` in
the replacement is a group reference. With regex off it must reach the file as a literal `$` — and
`String.replace()` honours `$&`, `$1` and `$$` *even when the search was a plain string*, so the
obvious implementation quietly rewrites anyone's `$` into something else. Literal mode therefore
splices the replacement in directly and never goes through `.replace()` at all. Both directions are
covered by tests and were confirmed in the browser.

**Highlight contrast.** The hit fills are deliberately weak — 15%, and 28% for the current match.
The coloured text sits in the ink layer *above* the hit, so the fill becomes that text's background,
and a string is drawn in a green mixed from the very token the highlight uses. At the first attempt's
55% that measured 3.2:1, under the 4.5:1 threshold for body text. The current match gets its
prominence from an outline instead, which owes nothing to the colour of the text inside it.

## Phase A4 — Compose smarts

**Key autocomplete.** The valid keys at the caret's position, straight from `KEYS`/`LEAVES` — the
same table the form renderer uses, so the two cannot disagree — filtered by indent depth and
enclosing block, which `serviceAtLine` (`:2068`) and `fieldAtLine` (`:2056`) already answer. Arrows
move, Enter or Tab accepts, Escape dismisses. A key that always takes a list or a mapping brings its
colon and the next line's indent with it.

**Hover help.** One plain-English sentence per compose key, in a small panel. These live in a
`DESCRIPTIONS` table in `compose-model.js` — 134 of them, covering every key in `TOP_SPEC_KEYS`,
`SERVICE_SPEC_KEYS`, the `LEAVES` blocks and `build`. Where `KEYS` already names a key, that exact
title is reused so the editor and the form can never word the same thing differently. A test asserts
every spec key has an entry with a non-empty title and a description ending in a full stop, which is
what stops the table rotting as keys are added.

The panel is positioned arithmetically from `CHAR_W` and `LINE_H` rather than by asking the browser
where the pointer is in the text — a textarea will not say. The same measurement drives the
suggestion list and the find bar's match boxes.

**Two collisions worth remembering**, both caught during the build rather than after:

- `compose-model.js` already had an internal `keyAt(ctx, i, col, allowSub)` used by `parseMap` and
  `parsePair`. Declaring the public one under that name would have silently replaced it and broken
  the parser. The public function is `describeKeyAt`, exported *as* `keyAt`.
- `build`'s sub-keys went into their own `BUILD_LEAVES` table, **not** into `LEAVES`. `LEAVES` is
  read by `harvestLeaves()` to decide which fields every service gets, so adding to it would have
  grown a set of empty `build.*` boxes on every service in the form.

**Path awareness (A4b, built).** Volume host paths go up together in one request to a new `paths`
action, which answers `ok` / `file` / `missing` / `skipped` per path. `missing` and `file` get a
dotted underline; hovering one explains it and says what to do. Cached per editor session, hung off
the existing 400 ms re-parse, and every failure — timeout, bad reply, anything — is treated as *no
information*: no marks, no banner, nothing in the status line. It is advice about the file, never
part of it, and it can never block a save.

**Images are deliberately not marked.** An image absent from the server is the normal state of any
stack that has not been started yet, and telling that apart from a typo needs a registry lookup —
slow, and offline half the time. Any marker would therefore cry wolf on every new stack. The
`stackman_docker_images()` data is there if a use is ever found for it as neutral information.

**The endpoint is the security-sensitive part**, because the paths are arbitrary text out of a file
someone is typing. `stackman_check_paths()` does `realpath`/`is_dir` only — no shell, no listing, no
recursion — caps the batch at 200, and answers `skipped`, never `missing`, for anything outside its
allowed roots. That distinction is the whole point: `missing` is itself an answer, so returning it
for `/etc/shadow` would turn this into a way to probe the filesystem.

Containment is checked **twice, by two different means**, because each covers the other's blind spot:
`realpath()` cannot resolve a path whose middle does not exist yet, and a lexical `..` collapse
cannot see a symlink. Both must agree. The first version had only the realpath check and let
`../../../../etc` through as `missing` whenever the stack folder itself did not exist — no leak,
since it never stat'd the target, but the wrong verdict. `local/test_check_paths.php` (gitignored)
runs a hostile list on the server and asserts nothing outside the root ever comes back as anything
but `skipped`.

## Phase A5 — A structure outline, in place of folding

**Folding was dropped, and this is the reasoning — it is the one place this plan changed shape.**

Folding means hiding lines. A `<textarea>` cannot hide lines: the only way is to cut the folded text
out of the buffer and keep it somewhere else. That buffer is `yamlPane.value`, which is exactly what
`currentText()` hands to Save. Every fold would therefore put the editor in a state where the thing
on screen and the thing that gets written to disk are different documents, held together only by
bookkeeping — against a rule that says a hand-authored file is never destroyed. For a compose file,
usually well under 150 lines, that is a poor trade.

The minimap went with it. It was already named as the drop candidate, and it answers the same
question the outline answers better.

What was actually built is an **Outline** button in the dialog header opening a panel that lists the
file's top-level blocks and, indented under `services`, each service — in file order, with line
numbers. Clicking a row scrolls there and puts the caret on that line. It only ever reads the
document.

One subtlety worth keeping: a row uses the service's **own** key line from `doc.root.pairs.services
.value.pairs[name].start`, not `svc.range.start`. A service's *range* deliberately opens at the
comment introducing it — right for the form, since that comment belongs to the service — but an
entry reading "quirks-alpha, line 56" must not point at a comment on line 55.

## Not in scope

- Multiple cursors, column selection, a command palette.
- Colouring anything other than YAML and (in PLAN_13) `.env`.
- Reformatting or prettifying. The file is written exactly as typed — that rule does not bend for a
  convenience feature.
