# PLAN_23 — Cards for the app search, a details window, and two more places to look

**Status: COMPLETE, 2026-08-17.** Built, deployed and checked in the browser on the test box.
1013 round-trip assertions, 154 converter, schema and lint clean, `php -l` clean over every
`include/*.php` and `scripts/ca-index.php`. Not committed.

Six things came out of the build that the plan did not predict:

1. **Unraid's own stylesheet outranked ours.** `.unapi img { height: auto }` is two selectors and
   beat the card icon's single class, so every icon stretched to its natural aspect ratio and
   pushed the description out of the card. Every image rule of ours is now scoped
   `.staxx-scaffold .thing`, which is exactly the "own the render" hazard `CLAUDE.md` warns
   about, met in the wild.
2. **A fixed card height was the wrong idea, twice.** First it squashed the text lines into each
   other — a flex column shrinks its items rather than overflowing. Then, sized to fit, it padded
   every short card out to the worst case. **Adrian's call: the Add button moved to the top-right,
   out of the flow entirely**, and the fixed height went with it — a grid row levels its own items,
   so no magic number is needed at all.
3. **Screenshot and icon URLs in the catalogue go dead.** One entry points all four of its images
   at a GitHub repository that no longer answers, which showed as a broken-image box and a tall
   empty gap. The details window now drops a dead thumbnail and takes the heading with the last
   one.
4. **`caCompact()` rounded 1,577 to "2k"**, which reads as a different number rather than a
   rounded one. One decimal place while the leading figure is a single digit.
5. **A capitalised query returned nothing from Docker Hub.** The shape check rejects an upper-case
   letter, so "Jellyfin" silently found nothing while the catalogue beside it matched perfectly.
   Lower-cased first now.
6. **A redraw on every keystroke** would have torn down and rebuilt all sixty cards, and their
   sixty images, while the user was still typing. All three groups now update together with the
   catalogue reply.

**Also added at Adrian's request, and not in the plan:** Docker Hub stars on the card, under the
Add button. Community Applications has had no likes, votes or ratings for years — `stars` is the
nearest thing the feed carries, and it is on 1,878 of 3,651 entries. Absent means "not counted"
rather than "none", so a card with no figure shows no line rather than a zero. This needed `st` in
the search index, hence index version **3**.

**The one thing not checked: the light Unraid theme.** Switching it is a change to Adrian's own
settings, so it was left alone.

---

## Context

Adrian asked for four things in the Apps dialog: split the results by source with CA first; render
CA apps as cards with their icon and a short block of repository detail; open a details window when
a card is clicked, like the panel Unraid's own Apps page slides in; and answer whether our
catalogue is stale, since Unraid announces an "Updating Content" step that we do not.

Three facts were measured before writing this, and two of them changed the shape of the work.

**There is only one search source today.** The dialog issues one action, `ca-search`
(`action.php:411`), which reads one local cache. There is nothing to separate. Adrian chose to add
the two sources that would make the separation real: **Docker Hub** (which Unraid's Apps page also
searches, shown below its own results) and **images already pulled on this server**.

**We are not stale, and the missing "Updating Content" is not a symptom.** We fetch our own copy of
`applicationFeed.json` (`scripts/ca-index.php:465-469`) and check the same 37-byte
`applicationFeed-lastUpdated.json` stamp CA does before pulling the 24 MB file
(`ca-index.php:88-92, 444-463`). Measured live on the test box, CA's `lastUpdated.json` and our
`index.json` hold the identical `feed_ts` of `1786968364`. CA has **no cron** — its refresh runs
only when someone opens the Apps page, which is what the modal announces. Ours runs on a search
once the cache is over 24 h old (`CA.php:42-43, 95-96`). Neither is scheduled; neither is fresher
by design. Worth keeping the independence: CA's own refresh opens with `rm -rf tempFiles`, so a
reader of its file can have it vanish mid-read.

**Adrian's call: move the trigger earlier.** The catalogue should refresh when the Stacks page
loads, not when someone searches — silently, with nothing on screen either way. That is strictly
better than what we have and closer to what CA does, without the modal: by the time anyone presses
Apps the work is normally already done, and on a freshly booted server (where `/tmp` is RAM and the
cache is simply gone) the first build starts while they are still reading the stack list. See A2.

**Everything the details window needs is already on disk**, screenshots included.
`staxx_ca_app()` returns the whole unstripped feed record, and `caChoose()` already fetches it.
`Screenshot`/`Screenshots` are deliberately kept at build time (`ca-index.php:232-241`) — a handful
of URLs per app costs nothing next to the trend arrays and moderator comments the strip list exists
to remove, and the details window needs them.

**Decisions taken, Adrian's call:** all three sources; the card opens the details window *and*
carries its own Add button; details match Unraid's panel including screenshots.

---

## Part A — Cards and the details window

Build and verify this in the browser **before** starting Part B. Part B changes what fills the
list; Part A changes what a filled list looks like, and debugging both at once is a waste.

### A1. The catalogue keeps its screenshots, and learns its own version

`scripts/ca-index.php:232-241` strips six keys — moderator notes and the download-trend history CA
keeps for its own graphs — while deliberately keeping `Screenshot` and `Screenshots`: a handful of
URLs per app costs nothing next to what the strip list removes, and the details window needs them.
`/tmp` is RAM on Unraid, which is why the comment there explains the distinction.

**The trap this creates, and the fix.** `ca-index.php:444-463` skips the download entirely when the
upstream stamp equals the one stored in our index. So an existing cache would keep its old shape —
no screenshots — until CA republishes, with nothing to explain why. Add a schema version:

- a constant in `include/CA.php` beside the TTL, `STAXX_CA_INDEX_VERSION = 2`
- written into `index.json` at `ca-index.php:412-421` as `'v'`
- read back at `ca-index.php:444-463`: the stamp shortcut applies **only** when the stored `v`
  matches. A mismatch always downloads.
- `staxx_ca_status()` (`CA.php:83-141`) treats a version mismatch as **stale but usable** —
  `$stale = $stale || (int)(index['v'] ?? 0) !== STAXX_CA_INDEX_VERSION`. `action.php:420` then
  refreshes behind the user while the old cards still show, which is the existing and correct
  behaviour for a stale cache. Do not make a mismatch unusable; that would replace 3,600 working
  apps with a progress message.

Bump the constant for any future change to what the indexer keeps.

### A2. The catalogue refreshes itself when the page loads

Today the only two things that can start a refresh are the two `ca-search` call sites
(`action.php:411-446`) — so a catalogue only ever updates because somebody opened the dialog, and
the first person to do so after a reboot waits for a 24 MB download while looking at a progress
message.

A new action, `ca-refresh`, in `action.php` beside `ca-search`. It calls `staxx_ca_status()` and,
if the cache is missing or stale, `staxx_ca_refresh_start()`. It returns `{ok:true}` and nothing
else — **no results, no polling, no message, no UI of any kind.** Failure is silent here: a server
with no route to the internet must not gain a warning on the Stacks page for a dialog nobody opened.
The existing failure reporting inside the Apps dialog is the right and only place for that.

`stacks.js` calls it **once**, about two seconds after the page settles, alongside the other
deferred start-up work. Two seconds rather than immediately so it never competes with the table,
the state refresh and the icon sweep, none of which anybody should wait behind for a catalogue they
may not use.

Three reasons this belongs in the browser rather than in `StacksPage.php`'s render, all of them
already project rules: the page talks to the server through one endpoint and nothing else
(`action.php`); a render must not have side effects, even a detached one; and a render happens on
every navigation, where a deferred client call is easy to fire exactly once.

Nothing is removed. The stale check inside `ca-search` stays as the backstop for a tab left open
for days, and it costs nothing when the cache is already fresh. The existing atomic-mkdir lock
(`CA.php:150-171`) is what stops two tabs starting two downloads, and it already works.

Because a version mismatch counts as stale (A1), this is also what makes the one-off rebuild for
screenshots happen quietly in the background, usually well before the dialog is ever opened.

### A3. Cards instead of rows

Replace `caRowHtml()` (`stacks.js:4563-4592`). The search reply already carries everything a card
needs — `i, n, r, a, ic, c, d, ov, dep` (`CA.php:246-260`). **No new index fields are required.**

One card, in order:

| Element | Source | Notes |
|---|---|---|
| Corner badge | `dep`, or derived | One only. `deprecated` wins; otherwise `official` when `r` has no `/` or starts with `library/` — the same test CA derives it by (`skin.php:390-391`), so it costs no index field |
| Icon, 6.4rem | `ic` | `loading="lazy" referrerpolicy="no-referrer"`, existing `--empty` fallback |
| Name | `n` | bold, one line, ellipsis |
| Maintainer | `a` | CA shows this same string on its tile; leave `"linuxserver's Repository"` as it comes — never invent |
| **Repo block** | `r`, `c[0]`, `d` | the "short block of repo details": image address in monospace on its own line, then category and a compact pull count (`151M pulls`, not CA's "More than 100,000,000" — a card has no room for a sentence). Omit the count when `d` is 0 |
| Description | `ov` | clamped to 3 lines with `-webkit-line-clamp` |
| Add button | — | small, bottom-right, `data-add="<i>"` |

The card is a `<div class="staxx-ca-card" data-i="…">`, **not** a `<button>` — it now contains a
button, and a button inside a button is invalid and behaves unpredictably. Keyboard access comes
from `tabindex="0"` plus `role="button"` and an Enter/Space handler, or from making the *name* the
focusable element; either is acceptable, but it must be reachable by keyboard and it must show a
focus ring, because the current row gets both free from being a `<button>`.

Grid: `display: grid; grid-template-columns: repeat(auto-fill, minmax(23rem, 1fr)); gap: 1rem` on
`.staxx-ca-list`, which is 84rem wide, so three across. Fixed card height so the grid stays
tidy; CA's own tile is 24rem × 20rem.

The click handler at `stacks.js:4708-4711` splits: a hit inside `[data-add]` calls the existing
import path, anything else on the card opens the details window.

### A4. The details window

A fifth `<dialog id="staxx-ca-app">` in `StacksPage.php`, **sibling** of `staxx-ca` inside
`.staxx-scaffold` — the reason is written out at `StacksPage.php:455-462` and applies unchanged:
nested inside, closing the parent with Escape takes the child with it while the child holds focus.
Dialogs stack in the top layer in open order, so no z-index (`CSS:1677-1679`).

Sizing, "centred and slightly smaller than the searcher": `.staxx-ca` is
`min(84rem, 94vw) × min(80vh, 76rem)` (`CSS:4443-4460`). Use `min(68rem, 90vw) × min(74vh, 68rem)`,
same border, radius, backdrop and `@starting-style` fade — copy the block, do not invent a new
treatment. Three grid rows: head / scrolling body / foot.

Contents, matching Unraid's panel (`skin.php:1466-1735`) minus the parts that are theirs (install,
pin, trend charts, changelog, template errors):

1. **Head** — icon 10rem, `Name`, `Author` (falling back to `Repo`), a **Add this app** primary
   button, and Close.
2. **Links row** — Project, Support, Read Me, Registry, Donate. Emit each only when the field is
   present **and** matches `^https?://`; see A5.
3. **Description** — `OriginalOverview ?: Overview`, whitespace preserved, collapsed past about
   25rem behind a Show more / Show less toggle (CA collapses at 250px, `Apps.page:2952`).
4. **Additional requirements** — `Requires`, same treatment, only when present.
5. **Attention** — only the deprecated notice. `CAComment` and `ModeratorComment` are stripped at
   build time and blacklisted apps never reach the index, so there is nothing else to say.
6. **Screenshots** — `Screenshot` (string or array) plus `Photo`. A row of thumbnails; clicking one
   opens it in a new tab. **No lightbox** — that would be a third stacked dialog for a feature
   almost no app uses. If a card has none, the whole section is absent.
7. **Details table** — Categories (all of them, not just the first), Added (`FirstSeen`, as
   `M j, Y`), Downloads, Repository, Docker Hub stars (`stars`), Last update (`LastUpdate`),
   Minimum Unraid version (`MinVer`), Licence (`Licence`/`License`). Every row conditional on its
   field being present — `hotio/jellyfin` has no downloads, no stars and no `LastUpdate`, and is
   the shape to test against. **Ignore `Date`**: it is junk in the feed (`binhex-plex` carries
   `"1970-01-01"`) and CA ignores it too.
8. **Maintainer** — `RepoName`/`Repo` as text. We do not hold CA's `repositoryList.json`, so there
   is no profile, bio or icon to show, and fetching that file is out of scope.

`caChoose()` (`stacks.js:4673-4706`) splits into `caAdd(ordinal)` — today's behaviour, verbatim —
and `caDetails(ordinal)`. Both need the `ca-app` record; `caDetails` keeps the one it fetched so
pressing Add inside the window does not ask again.

Two things that must be carried over from the existing dialogs, because they are easy to miss:

- Focus explicitly after `showModal()` (precedent `stacks.js:5298-5301` and `8150-8156`); the
  UA's own choice lands somewhere wrong.
- The search dialog's `close` handler must force-close this one, the way the editor's does for the
  picker and timezone dialogs (`stacks.js:5339-5379`).

**Markdown is not rendered.** CA runs the overview through a markdown renderer; we will not add one.
Render as escaped text with line breaks preserved. Some overviews will show a bare `(https://…)`
where CA shows a link — a known, acceptable difference. Note it in a comment so nobody "fixes" it by
importing a library.

### A5. Every URL from the feed is checked before it is used

The feed is third-party data and its links land in `href`. `esc()` (`stacks.js:1059-1063`) escapes
four characters and is not a URL check. Add one small helper used by every link and image:

```js
function caUrl(u) { return (typeof u === 'string' && /^https?:\/\//i.test(u)) ? u : ''; }
```

An empty return means the element is not emitted at all. All outbound links get
`target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"`. This is not theoretical
tidiness — `binhex-emby`'s project link in the live feed is `https://https://emby.media/`, so bad
values are already in there.

---

## Part B — Docker Hub and the images already here

### B1. Docker Hub search

`staxx_hub_search(string $q): array` in `include/Defines.php`, directly modelled on
`staxx_image_tags()` (`Defines.php:262-300`) — same `curl` through `staxx_sh()`, same
`--max-time 8` inside a 12 s outer timeout, same "return an empty array for every failure" contract,
and the same reasoning: this runs while someone is typing.

Endpoint, verified live from the test box today (HTTP 200):

```
https://hub.docker.com/v2/search/repositories/?query=<q>&page_size=25
```

Real reply, trimmed:

```json
{"count":946,"results":[
 {"repo_name":"jellyfin/jellyfin","short_description":"The Free Software Media Browser ",
  "star_count":1577,"pull_count":404787600,"is_official":false},
 {"repo_name":"linuxserver/jellyfin","short_description":"","star_count":812,
  "pull_count":151362973,"is_official":false}]}
```

Return `[{name, desc, stars, pulls, official}]`. Validate the query the same way the tags helper
validates a repo — reject anything that is not lowercase alphanumerics, `. _ -` and at most one
slash — and `rawurlencode` it. There is **no icon** in this reply, which is one of the reasons the
Docker Hub group is not rendered as cards.

New case `hub-search` in `action.php`, beside `tags`. POST only, like everything else.

### B2. Images already on this server

**No new endpoint.** The `images` action already returns every `repo:tag` on the box
(`action.php:388`). Fetch it **once** when the dialog opens, keep it in a variable, and filter it
client-side on each keystroke. A `docker images` call per keystroke would be absurd.

### B3. Three groups in one list

The list becomes three sections, each rendered only when it has results, each with a heading
carrying its own count:

| Group | Rendering | Limit | When |
|---|---|---|---|
| **Community Applications** | cards, Part A | 60, as now | always, including the empty-query A–Z browse |
| **Docker Hub** | compact rows | 25 | query of 3+ characters only |
| **Images on this server** | compact rows | 15 | any query; whole list when the query is empty |

CA is first and is the only group that gets cards. That is not just visual ranking — those two
sources carry no icon, no description worth a card, and no ports, paths or variables. A compact row
is what their data supports, and the difference in weight *is* the separation Adrian asked for.

A Docker Hub row shows the image name, its short description, stars and a compact pull count, plus a
link out to its Docker Hub page. A local-image row shows `repo:tag` and nothing else. **Neither
opens a details window** — there is nothing to put in one. Clicking either adds it.

Timing, so the CA results are never held up by a network call: `caSearch()` keeps its 250 ms
debounce and fires `ca-search` as now; Docker Hub goes on its own **600 ms** debounce and a 3
character minimum, filling its section when it lands. A reply that arrives after the query has moved
on is discarded — stamp each request and ignore any whose stamp is not the current one.

### B4. Adding an image rather than an app

Both new groups produce the same thing: the six-line skeleton `openEditor` already uses for a new
stack (`stacks.js:689-690`, `my-app` / `alpine:3.20`) with the image substituted and the service and
stack named from the last path segment of the image (`linuxserver/jellyfin` → `jellyfin`), lowercased
and stripped to what `staxx_valid_name()` accepts. No converter is involved — there is no
template to convert. Say so in the dialog: the footer message for these groups should make clear
that a Docker Hub image arrives bare, with no ports, paths or variables, unlike a CA app.

### B5. Wording

The dialog is titled "Community Applications" (`StacksPage.php:641`) and its hint mentions the
catalogue. With three sources both need rewording — "Add an app", and a hint that says CA apps
arrive fully configured while the other two arrive as a bare image. The footer count logic
(`stacks.js:4629-4650`) is written for one list and needs to speak for three.

---

## Files

| File | Change |
|---|---|
| `scripts/ca-index.php` | keep screenshots; write the index version; version-aware download shortcut |
| `include/CA.php` | version constant; version mismatch counts as stale |
| `include/Defines.php` | `staxx_hub_search()` |
| `include/action.php` | `ca-refresh` and `hub-search` cases |
| `include/StacksPage.php` | details dialog markup; retitle the search dialog |
| `javascript/stacks.js` | the start-up `ca-refresh` call, card renderer, group rendering, details window, hub + local search, split `caChoose` |
| `sheets/staxx.css` | `staxx-ca-card*`, group headings, compact rows, the details dialog |

## Verification

Local, all four green — none of them can see any of this, which is worth stating plainly; the
browser pass is the test:

```sh
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
```

On the server, `php -l` over `include/*.php` and `scripts/ca-index.php`, then a throwaway script
calling `staxx_hub_search('jellyfin')`, `staxx_hub_search('')` and
`staxx_hub_search('../../etc')` — the first returns rows, the other two return an empty array and
touch the network for neither.

Then in the browser, after `pscp` + `dev-install.sh` + Ctrl-F5:

1. **The rebuild happens by itself, without opening the dialog.** With an old-shape cache in place,
   load the Stacks page and touch nothing. Within a few seconds `/tmp/staxx/ca/index.json`
   carries `"v":2` and an app with screenshots has them. The page shows nothing about it at any
   point, and one page load makes exactly one `ca-refresh` request.
2. **Nothing waits on it.** Delete `/tmp/staxx/ca` entirely, load the page, and confirm the
   table, states and icons all appear at their normal speed while the catalogue builds behind them.
   Then open Apps: cards, not a progress message.
3. **A stale-but-usable cache is never replaced by a message.** With `/tmp/staxx/ca` present
   but old, load the page and open Apps immediately — the old catalogue must stay on screen
   throughout the refresh.
4. `jellyfin` — CA cards on top, a Docker Hub section under them, and any local jellyfin image under
   that. Each heading carries a count.
5. `linuxserver/jellyfin` card → details window: description, all three categories, added date,
   downloads, stars, last update, minimum version, and every link opening in a new tab.
6. `hotio/jellyfin` — the sparse case. No downloads, no stars, no last update: those rows must be
   absent, not blank or `undefined`.
7. `rabbitmq` — screenshots present, official badge, three thumbnails that open in a new tab.
8. A deprecated app still shows its badge, and the notice appears in the details window.
9. Add from a card, and Add from inside the details window, both open the editor on the same file.
10. Add a Docker Hub result and a local image — the editor opens on a skeleton naming that image, and
    the file saves.
11. Escape closes the details window and leaves the search dialog open; Escape again closes that.
    Closing the search dialog while the details window is open closes both.
12. Empty query — CA browses A–Z, no Docker Hub request is made at all (check the network panel),
    local images list in full.
13. Type fast: no card from a stale query ever appears, and the Docker Hub section never shows
    results for a query that has moved on.
14. Both Unraid themes.

## Left out

- Routing CA icons through `Icons.php`. Opening the dialog still makes ~60 requests to third-party
  hosts from the browser, exactly as it does today and exactly as Unraid's Apps page does. Recorded
  as an open question in `CA-IMPORT-RESULTS.md:111-118`; unchanged by this work, and worth its own
  decision rather than being smuggled in here.
- A markdown renderer for overviews (A4).
- A screenshot lightbox (A4).
- CA's maintainer profile panel — it needs a second catalogue file we do not download.
- Trend charts, changelogs, pinning, install state. All of those describe apps CA manages; we do not
  install anything.
- The unreproduced "first click on Apps does nothing" report (`CA-IMPORT-RESULTS.md:266-274`). Still
  unexplained, still not this plan's business.
