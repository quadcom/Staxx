# Settings page — ideas, not commitments

A running list of things that might one day belong on the Stack Manager settings page. Nothing here
is agreed or scheduled. An entry gets promoted to its own numbered plan when it is picked up, and
deleted from here when it ships or is ruled out.

Sibling to `feature-ideas.md`, which holds the same kind of list for everything that would *not* be
a setting. An entry belongs here only if it needs a switch on the settings page.

Started 2026-08-17.

---

## How a setting works here

Every entry below depends on this, and it is currently only discoverable by reading four files.

- **Adding a control named `FOO` to the settings form is all it takes to persist `FOO`.**
  `/update.php` writes every non-`#` field into `/boot/config/plugins/stack.manager/stack.manager.cfg`.
  There is no server-side allowlist. The form lives in `stack.manager.settings.page`.
- **The key also goes in `default.cfg`, whose comments must start with `;`, never `#`.** A `#` line
  is parsed as content, and one syntax error makes PHP reject the entire file and return `false` —
  silently. The damage only shows the day a new key is added, because existing user configs already
  hold every older key. The file's own header explains this at length; read it before editing.
- **`stackman_cfg()` (`include/Defines.php:34`) merges the shipped defaults under the user's file**,
  so a config that predates a new key still gets its default. `stackman_cfg_bool()` sits beside it
  and currently has no callers — a new true/false setting should be its first.
- **A setting read by a `.page` `Cond` must be projected onto a marker file** by
  `scripts/apply_settings`, the way `HEADER_MENU` is. `Cond` runs on every render of every page, so
  parsing an ini file there would be wasteful. An ordinary PHP-read setting must not be projected —
  `ICON_FETCH` is the model for that one, read in a single place in `Icons.php`.
- **No setting reaches the browser today.** If one ever needs to, the route is a `data-*` attribute
  on `.stackman-scaffold` in `include/StacksPage.php`, read once near the top of `stacks.js` — the
  same handoff the CSRF token and the folder list already use. Note that this only changes on a page
  reload, since the settings form posts to an iframe rather than to `action.php`.

---

## 1. Hide catalogue apps that are deprecated or abandoned

**What it does.** Stops the Apps dialog offering apps their maintainer has given up on. Adrian's
request, 2026-08-17.

**Two signals, and they are not equally solid.** Measured against the live catalogue, 3,651 apps:

| Signal | Count | Notes |
|---|---|---|
| `Deprecated` flag | 153 | The catalogue's own. Exact. Already carried into search results as `dep` and already drawn as a badge |
| Image not updated in 2+ years | 523 (14%) | Inferred from `LastUpdate` |
| Image not updated in 3+ years | 403 (11%) | |
| **No update date at all** | **900 (25%)** | See below |

**The 900 is the number that decides the design.** A quarter of the catalogue records no update
date, and no date means nobody counted — not that the app is dead. Those must never be hidden, or
the setting quietly removes a quarter of the catalogue. Same trap as the star counts in `PLAN_23`:
absent is not zero.

The other honest caveat: a stable app that needs no changes looks identical to an abandoned one.
That is why this is a switch somebody turns on, not a default.

**Where the filter goes: at search time**, in the loop in `stackman_ca_search()`
(`include/CA.php:207`), beside the existing category test. It reads the config per request, so
toggling takes effect immediately. The alternative — dropping entries at index build time, which is
what already happens for the 83 blacklisted and 10 hidden apps (`ca-index.php:226-230`) — bakes the
choice into the cache and would force a 24 MB re-download every time the switch moved.

**One cost to flag.** The catalogue index carries `dep` but **not** the update date, so the stale
half needs a new field in the index, and therefore an index version bump and one rebuild. That is
the machinery `PLAN_23` added and proved, so it is a known quantity rather than new work.

Note that `dep` is *absent* rather than `0` on a healthy app, so any test must use `isset()`.

---

## 2. Remove or wire up the dead setting

`TAKEOVER_DOCKER_TAB` is declared in `default.cfg:32` and read by nothing anywhere in the tree. It
has no control on the settings page. Either wire it up or delete it — a key that looks like a switch
and does nothing is worse than either.

Not really an idea so much as a tidy-up, but it belongs on this list because it is the settings file
it lives in.

---

## 3. Whether to offer a switch for the catalogue download at all

Recorded in `CA-IMPORT-RESULTS.md` as an open question and still open. The catalogue is fetched
without asking, now on page load rather than on first search (`PLAN_23`). The argument against a
switch is that it is configurability nobody asked for; the argument for is that it is the one thing
this plugin does that reaches the internet unprompted.

Related and unresolved: the Apps dialog loads app icons directly from third-party hosts in the
browser, roughly 60 requests when it opens, and **ignores `ICON_FETCH`** — which a reasonable person
would expect to cover it. That inconsistency is worth settling whichever way this goes.
