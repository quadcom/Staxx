# Settings page — ideas, not commitments

A running list of things that might one day belong on the Stack Manager settings page. Nothing here
is agreed or scheduled. An entry gets promoted to its own numbered plan when it is picked up, and
deleted from here when it ships or is ruled out.

Sibling to `feature-ideas.md`, which holds the same kind of list for everything that would *not* be
a setting. An entry belongs here only if it needs a switch in the settings panel.

Started 2026-08-17.

---

## How a setting works here

Every entry below depends on this.

- **There is a server-side allowlist now.** `stackman_settings_keys()` in `include/Settings.php`
  names every setting a control is allowed to touch — its type, its default and, for a choice, the
  values it may hold. A key not on that list cannot be read or written, whatever gets posted. Adding
  a matching field to a form is no longer enough by itself; the one exception is the stack-folder
  field still left on Unraid's own settings page, which posts straight to `/update.php` with no
  allowlist at all — deliberately, so it still works as a way back in if the panel is ever broken.
- **Settings do reach the browser, and live.** The panel calls the `settings` action on
  `include/action.php`, which reads the current values fresh each time it opens — not a value baked
  into the page when it was last loaded.
- **A setting is two entries, not one.** One in `stackman_settings_keys()` on the server; one in the
  ordered `SETTINGS_ROWS` table in `stacks.js`, which carries the label, the kind of control, its
  choices and the explanation shown underneath. Adding a setting means adding one row to each table.
- **Saving goes through `stackman_settings_save()`.** It checks every value before writing any of
  them, so one bad entry can't leave the file half-changed; writes the whole config to a temporary
  file and swaps it into place, so a crash mid-write can't corrupt it; and keeps any key it does not
  recognise, so a config from a newer version of the plugin survives being saved by an older one. It
  then runs `scripts/apply_settings` to make the change take effect.
- **`default.cfg`'s comments must start with `;`, not `#`.** Still true, still load-bearing — a `#`
  line is read as ordinary content, and one such mistake makes PHP throw away the *entire* file and
  quietly fall back to nothing. The damage only shows the day a new key is added, because an existing
  user's config already holds every older one. The file's own header explains this at length.
- **`stackman_cfg()` merges the shipped defaults under the user's own file**, so a config saved before
  a new key existed still gets that key's default. `stackman_cfg_bool()` sits beside it and still has
  no callers — don't reach for it for a new true/false setting: it treats a missing value as false,
  which is wrong for two of the five settings that default to true. Defaults now live in the
  allowlist instead.
- **A setting a `.page` file's `Cond` needs to read must be projected onto a marker file**, the way
  `HEADER_MENU` always has been — `Cond` runs on every render of every page, so parsing the config
  there would be wasteful. `TAKEOVER_DOCKER_TAB` now does the same job a different way: instead of a
  marker, `apply_settings` installs or removes a whole shadow page file when it changes. `ICON_FETCH`
  is the counter-example — it is read normally from PHP in one place and must never be projected.

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

## 2. Whether to offer a switch for the catalogue download at all

Recorded in `CA-IMPORT-RESULTS.md` as an open question and still open. The catalogue is fetched
without asking, now on page load rather than on first search (`PLAN_23`). The argument against a
switch is that it is configurability nobody asked for; the argument for is that it is the one thing
this plugin does that reaches the internet unprompted.

Related and unresolved: the Apps dialog loads app icons directly from third-party hosts in the
browser, roughly 60 requests when it opens, and **ignores `ICON_FETCH`** — which a reasonable person
would expect to cover it. That inconsistency is worth settling whichever way this goes.
