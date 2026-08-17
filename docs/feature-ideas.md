# Features — ideas, not commitments

A running list of things that might one day be worth building. Nothing here is agreed or scheduled.
An entry gets promoted to its own numbered plan when it is picked up, and deleted from here when it
ships or is ruled out.

Sibling to `settings-ideas.md`, which holds the same kind of list for things that would be a
*setting*. An entry belongs there if it needs a switch on the settings page and here if it does not.

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
