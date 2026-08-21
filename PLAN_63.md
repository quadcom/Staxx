# PLAN 63 — catching an install that came in through Unraid's own Apps page

**Status: open. Awaiting approval before any code is written.**

Concept, server findings and Adrian's decisions are in `feature_56.md`; this is the code plan for
them. Read that first — nothing here re-argues a decision taken there.

---

## 1. What this builds, in one paragraph

`AddContainer.page` is shadowed the same way `Docker.page` already is. The shadow decides, per
request, whether this is something StaXX should handle (a fresh install arriving from Community
Applications, or Add Container pressed by hand), something it should offer to handle (an edit of an
existing Unraid template), or something it must pass through untouched (everything else). Handled
requests are turned into a one-shot handoff file and a redirect to StaXX's own view, where the
existing app form opens with the converted compose file already in it. Saving writes the stack and,
once, an Unraid template alongside it so the Apps page still knows the app is installed.

## 2. Mechanism facts this plan depends on

Confirmed on the live server 2026-08-21. Restated here because every step below rests on them.

- Community Applications sends the browser to `/Apps/AddContainer?xmlTemplate=<type>:<path>`.
  `default:` is a fresh install (path = a temp XML CA just wrote); `edit:` is an existing template
  under `/boot/config/plugins/dockerMan/templates-user`.
- Unraid's page collector does `$site[$page['name']] = $page` over
  `glob('plugins/*', GLOB_ONLYDIR)`, which is sorted. `staxx` sorts last, so our `AddContainer.page`
  wins. Identical mechanism to `shadow/Docker.page.tmpl`.
- `AddContainer.page` has **no `Menu` key**, which is why one page answers under both `/Docker` and
  `/Apps`. Shadowing it adds nothing to any menu.
- The stock page is a six-line wrapper: it merges docker translations when the request did not arrive
  under `/Docker`, calls `session_write_close()`, then
  `eval('?>'.parse_file("$docroot/plugins/dynamix.docker.manager/include/CreateDocker.php"))`.
  Our pass-through is that same call, not an imitation of it.
- `Cond` cannot choose between shadow and stock: a page is registered in `$site` regardless, and a
  false `Cond` makes the page unavailable rather than falling back. The shadow must always be live
  and branch inside itself.
- CA learns what you installed by listing `templates-user` before and after an install and treating
  whatever is new as the app's template. Two places do this.

## 3. What already exists and is reused, not rebuilt

Checked in the tree. This is most of the feature.

| Need | What already does it |
|---|---|
| Turn a CA template into a compose file | `javascript/ca-convert.js`, `window.StaxxCA.convert(app, {appdataRoot})` |
| Decode one Unraid template XML into an array | `staxx_import_read_template(string $path): ?array` in `include/Import.php` |
| Open the app form on a converted file | `openEditor(name, yaml, true, '')` in `javascript/stacks.js`, exactly as `caImport()` calls it |
| Report conversion warnings and notes | `caImport()`'s three wordings — reuse verbatim, do not write a fourth |
| Write a stack with a provenance note | `staxx_import_write($rel, $yaml, $about, $error)` |
| Take over a container that already exists | `handover-check` / `handover-start` / `handover-finish`, and `staxx_handover_*()` in `Stacks.php` |
| Copy a dormant shadow page into place on a setting | `scripts/apply_settings`, the `Docker.page` block |

**The handover machinery is the adoption story.** It sets the old container *aside* (renamed) rather
than destroying it, brings the stack up in its place, and only deletes the old one once the new one
is confirmed working — restoring it if not. That is strictly better than the destroy-and-rebuild
Adrian was asked to approve in `feature_56.md` decision 6, and it is already written and shipping.
Adoption here is wiring, not new code.

## 4. New files

```
src/staxx/usr/local/emhttp/plugins/staxx/shadow/AddContainer.page.tmpl
```

One new file. Everything else is an edit to something existing.

## 5. Files touched

| File | Change |
|---|---|
| `shadow/AddContainer.page.tmpl` | new — the shadow and its branch |
| `scripts/apply_settings` | copy/remove the shadow on the new setting, beside the `Docker.page` block |
| `default.cfg` | new key `CATCH_INSTALLS="true"` — **comment lines start with `;`** |
| `include/Settings.php` | read and validate the new key |
| `staxx.settings.page` | the control, its help text |
| `include/Import.php` | the handoff store: write, read-once, expire; the one-time template write |
| `include/action.php` | two new cases: `handoff-read`, `handoff-template` |
| `javascript/stacks.js` | act on the handoff parameter on load; pass the handoff id through the save |
| `docs/` | one paragraph in the plain-English overview |

## 6. The branch, precisely

The shadow's whole job. Written as a table because every row is a decision that must not be guessed.

| Request | Route |
|---|---|
| Any POST | **pass through**, always, before anything else is read |
| `xmlTemplate` absent (Add Container pressed by hand) | StaXX route |
| `xmlTemplate=default:<path>`, path is a readable file | StaXX route |
| `xmlTemplate=edit:<path>`, path inside `templates-user` | interstitial: convert, or keep Unraid's form |
| `xmlTemplate` present with `&staxx=skip` | **pass through** — this is the decline |
| Anything else: unknown type, missing file, unreadable, path outside the two allowed folders | **pass through** |

Three rules that hold the branch together:

1. **POST is checked first and never intercepted.** The stock form submits to its own URL. A GET
   branch that also caught the POST would break creating a container the moment someone declines the
   offer — the worst possible failure, because it breaks Unraid rather than StaXX.
2. **Every doubt passes through.** Unknown type, vanished file, a path that is neither CA's temp
   folder nor `templates-user`: hand it to Unraid. The safe direction to fail is "Unraid behaves
   exactly as it always did".
3. **The path is validated as a real file inside one of two allowed folders,** by `realpath()` and a
   prefix test, before it is opened. It arrives from a query string; it is treated as hostile.

## 7. The handoff, and why it is a file rather than a URL parameter

StaXX's app form lives on the Stacks view, not on the Add Container page. So a handled request has
to travel from one page to the other. It does **not** travel as a path in a query string.

The shadow decodes the template with `staxx_import_read_template()`, writes the decoded record plus
the original XML text to `/tmp/staxx/handoff/<id>.json` where `<id>` is 32 hex characters from
`random_bytes()`, and redirects to StaXX's live view with `?staxx-install=<id>` and nothing else.

Four reasons this shape and not the obvious one:

- No filesystem path ever appears in a URL, so there is no traversal surface on the receiving page
  and nothing to validate twice.
- CA's temp XML has an unknown lifetime (open question below). Read once, held by us, the answer
  stops mattering.
- The original XML is what the one-time template write needs at save time, long after CA may have
  cleaned up.
- An id that names nothing is a clean failure: the form opens empty and says so.

`/tmp` and not the flash drive: this is a few seconds of state, and flash writes are the one thing
worth being stingy with. Mode 600. Expiry is one hour, swept when a new handoff is written — no
timer, same discipline as the stats collector's self-termination.

**Which URL to redirect to** depends on `HEADER_MENU` and `TAKEOVER_DOCKER_TAB` — `/StaXX` or
`/Docker/Stacks`. There is no existing helper that answers this; add one small function beside the
existing config readers and use it here. Do not re-derive the markers inline.

## 8. Work items, in order

Each phase is independently deployable and independently verifiable. Do not start a phase before the
one above it is checked on the server.

### Phase A — the setting

1. `default.cfg`: `CATCH_INSTALLS="true"`, with a `;` comment saying what it catches.
2. `Settings.php`: read it, default true, accept only `true`/`false`.
3. `staxx.settings.page`: the control. Help text names the two doors it catches and says an edit of
   an existing container is only ever offered, never taken.
4. `apply_settings`: copy `shadow/AddContainer.page.tmpl` to `AddContainer.page` when on, `rm -f`
   when off. **Model this on the `Docker.page` block exactly, including its
   never-exit-non-zero-here rule and the missing-template warning** — a failure here must not fail a
   settings save or an install.

*Check:* toggle the setting on the server, confirm `AddContainer.page` appears and disappears,
confirm `/Docker/AddContainer` still renders Unraid's form both ways (the shadow is a pass-through
until Phase B lands).

### Phase B — the shadow and the pass-through

5. Write the shadow. Structure: GPL-2.0 header explaining *why* it ships as `.tmpl`
   (every `.page` in the folder is collected, so it cannot carry that extension while dormant), then
   the branch of section 6, then either a redirect or the stock call.
6. The pass-through reproduces the stock wrapper's own two preliminaries — the docker translation
   merge when the request did not arrive under `/Docker`, and `session_write_close()` — and then
   `eval('?>'.parse_file(...))` on the stock `CreateDocker.php`. It must not include our own
   translations, assets or page furniture: a pass-through that renders differently is not one.
7. At this stage the StaXX route does nothing but redirect to the live view with no parameter, so
   the branch itself can be proven before the form work exists.

*Check:* on the server, with the setting on — an Apps install lands on StaXX's view; Add Container by
hand lands on StaXX's view; opening an existing container for editing renders Unraid's form
unchanged; `php -l` clean; and, critically, **creating a container through Unraid's form still
works end to end**, because that is the POST path.

### Phase C — the handoff and the form

8. `Import.php`: the handoff store — write (with sweep), read, and read-and-keep-for-save. Path is
   built from the id after the id is checked against `^[0-9a-f]{32}$`; it is never concatenated raw.
9. `action.php`: `handoff-read` returns the decoded template for an id, or a refusal. POST only,
   like everything else there.
10. `stacks.js`: on load, if the live view was given `staxx-install=<id>`, fetch it, run
    `window.StaxxCA.convert()`, and call `openEditor()` — the same three lines `caImport()` already
    runs, and the same warning/note wordings after it. Factor the shared tail out of `caImport()`
    rather than copying it.
11. The line at the top of the form saying StaXX is handling this install (decision 3). One sentence,
    full stop, says what to do next.
12. Remove the query parameter from the address bar after it is consumed, so a refresh does not
    reopen the form on a handoff that has been used.

*Check:* an Apps install for a fat template (one with devices, several volumes, a WebUI) arrives in
StaXX's form fully converted, with the same warnings the built-in app store shows for the same app.

### Phase D — the template left behind, which is the exit route

**Why this phase exists.** StaXX replaces Unraid's Docker management rather than sitting beside it,
so the one thing it owes anyone who tries it and changes their mind is a way back. The template
written here is that way back: back out of StaXX and Unraid can still see the app. Apps'
installed-or-not check happening to keep working is a side benefit, not the reason, and if the two
ever pull in different directions the exit route wins.

13. `Import.php`: write the original XML once to `templates-user` as `my-<StackName>.xml`, at the
    moment the stack is saved — never when the form opens, or abandoning the form would leave a lie.
14. `stacks.js` passes the handoff id through the save so the server knows which XML to stamp.
15. Never overwrite an existing template of that name. A collision means somebody already has an
    Unraid app by that name, and theirs is somebody's real exit route — leave it alone, and say so
    rather than saying nothing, because an app whose template was skipped has no way back.
16. Written once and never revisited (decision 5), and **not** removed when the stack is removed.

*Check on the server, and this is the one to be suspicious of:* a left-behind template must not make
the app appear anywhere as installed-and-stopped, and must not offer itself in a way that would build
a second container beside the stack. It **will** appear in Unraid's Add Container template dropdown —
confirm what picking it there actually does, and if it would build a duplicate, say so in the help
text rather than pretending otherwise.

### Phase E — the edit offer

17. The interstitial for `edit:` — plain page, StaXX's own styling, two buttons. "Convert to a StaXX
    stack" goes down the Phase C route. "Keep editing here" is a link to the same URL with
    `&staxx=skip`, which section 6 passes straight through, so the URL and every parameter CA or
    Unraid built stays exactly as they built it. **A decline is never remembered.** The offer appears
    on every edit of every old-style container, so an app stays adoptable whenever its owner decides
    it is — which is the whole point of leaving them in Unraid's form rather than converting them.
    No state, nothing to keep in step with a template that may be deleted or replaced, and no way for
    a decline made once to hide the offer from somebody who wants it later. The cost is a click on
    every edit, which is accepted.
18. After a converted edit is saved, offer the handover — `handover-check` already answers whether
    there is a container to set aside, so this is a call and a confirm, not new machinery.

### Phase F — the safety net

19. No new watcher. When the view refreshes its rows, if there is a container belonging to no stack
    that was created since the last time the page looked, show one banner offering to adopt it, which
    opens the existing importer. A single stamp is all the state this needs.
20. This is what makes the feature degrade gracefully: if a future Unraid release stops matching the
    shadow, installs quietly go back to the old way and this notices them.

## 9. Tests

Local, on the dev machine:

- `node --check` on `stacks.js` **and** `node tests/js_undeclared.js`. Both, for the reason
  `CLAUDE.md` gives: the undeclared-name check catches what `--check` cannot see, and one such line
  in a function every render calls kills the page silently.
- `node tests/ca_convert.js` — unchanged behaviour expected; this plan adds an entry point to the
  converter, not a change to it. If a number moves, something was broken.
- A throwaway node probe over the fixture corpus converting a template the new way and the old way,
  asserting byte-identical output. That is the whole claim of Phase C.

On the server (a new file under `tests/server/`, headered with its exact commands like the others):

- The branch table of section 6, every row, including the POST row.
- Path validation: a `default:` path pointing outside CA's temp folder and `templates-user` is
  refused; `..` in the path is refused; a symlink out is refused.
- Handoff ids: a malformed id, an unknown id, an expired id, and an id used twice.
- The template write: normal, name collision, and a save where the handoff has already expired.
- `php -l` over every changed `include/*.php` after deploy, as always.

## 10. Risks

| Risk | Standing |
|---|---|
| Unraid renames or reworks `AddContainer.page` | Shadow stops matching, stock form returns, Phase F notices the installs. Fails in the safe direction by design. |
| CA changes its handoff URL | Same: unmatched requests pass through. |
| Intercepting the POST by accident | Would break creating containers in Unraid. Mitigated by making POST the first check and testing it explicitly in Phase B. |
| The left-behind template builds a duplicate | Real, open, and the reason Phase D has its own server check. |
| The stale template disagrees with the stack | Accepted by decision 5. Nothing in StaXX reads it. The real cost is that backing out lands on day-one settings, which is still worth more than no way back. |
| `PLAN_61`'s registry rewrite ages the stamped template | Real and specific. When a publisher moves registries, `PLAN_61` rewrites the address in the stack. The template stamped here keeps the address as installed, so backing out a year later lands on an address the publisher has left. Acceptable for now — the reverse translator in `feature_56.md` is the proper fix — but the help text must not promise the template is current. |
| A change quietly makes backing out of StaXX harder | A regression by itself, even with nothing broken. StaXX replaces Unraid Docker; the door out is part of the product. |
| A `Cond` on the shadow | Would silently remove Add Container entirely. Explicitly forbidden — see section 2. |

Rollback at every phase is `rm AddContainer.page`, which is what turning the setting off already
does, and a reboot rebuilds the whole tree regardless.

## 11. Still to decide

1. **What does CA do when its post-install folder diff comes back empty?** Decision 5 means it
   usually will not, but the hand-pressed route has no CA involvement at all. Worth ten minutes on
   the server before Phase D, not a blocker.
2. **`UpdateContainer.page` is a separate page** and is not shadowed by this plan. Whether an adopted
   app can still be sent down Unraid's update path — and whether that matters once the Docker tab is
   gone — is a question for a later plan, not this one.

## 12. Against `PLAN_62`

Crosschecked 2026-08-21. **No logical overlap. They can run sequentially in either order, and this
one should go first.**

Nothing either plan builds is needed by the other, and they share no subject matter: `PLAN_62` reads
what an author publishes upstream and never writes anything; this one catches an install and writes a
stack. Neither touches the compose model or the writer.

**What they do share is four files, and that is a merge problem rather than a design one:**
`default.cfg`, `Settings.php` and the settings page (both add one config key), the single request
handler (both add cases, differently named), and `stacks.js` (`PLAN_62` in the row pill and the
form-advice grafting; this one at page load, in the app-form entry and in the save). Nothing
contradicts, but two agents in those files at once buys conflicts for no reason. **Sequential, not
parallel.**

**Why this one first:**

- **`PLAN_62` has a hard dependency; this does not.** `PLAN_62` grafts a second fact onto machinery
  `PLAN_61` built — the moved-row treatment, the advice branch, the dismissal shape. Those exist in
  the code and `PLAN_61` is now filed as complete, so that machinery is settled rather than still
  moving. Nothing here touches any of it either way.
- **This closes a hole that exists today.** With the Docker tab takeover on, an app installed through
  Unraid's Apps page succeeds and then has no page to appear on. That is live behaviour, not a
  missing feature.
- **It is much the smaller job.** One new file and wiring, against `PLAN_62`'s four stages, new
  include, new node script, network conditionals and captured fixtures.
- **It cannot destabilise the update pass.** Nothing here goes near the six-hourly pass, the state
  file or the spawn sites `PLAN_62` has to widen — the trap that cost `PLAN_61` a day.

**Housekeeping noticed while checking, and since done:** `PLAN_61` is filed at
`completed-plans/PLAN_61-catalogue-registry-move.md`, with its completion note and the two items it
deliberately left open.

## 13. Sequencing

Phases A and B are one deploy and prove the risky half — the shadow, the pass-through, and that
Unraid's own form still works. C and D are the feature. E and F are additive and can land later
without holding anything up. Nothing here touches the compose model or the writer, so the
never-destroy-a-file promise is not in play.
