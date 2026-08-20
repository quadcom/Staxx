# PLAN_55 — the app moved house, and nothing told you

**Status: drafted 2026-08-20, awaiting approval.** Nothing here is built. Every decision below is a
*recommendation* rather than a settled answer — the ones that need Adrian's word are marked, and the
plan should not start until they have it.

## Context

A Community Applications template is a snapshot taken the day you added the app. Unraid never
revisits it, and neither does anything else — so when a publisher moves their images to a different
registry, every server that added the app before the move keeps pulling from the old address for
ever. The old address usually keeps working, which is exactly why nobody notices.

Two things make it worth caring about. Docker Hub now allows an unsigned-in server roughly ten
questions an hour, so an image still pointed at Docker Hub for no reason is spending an allowance
that PLAN_45's update checking needs. And an abandoned repository eventually stops being updated —
silently, since a stale tag still pulls.

**Measured on the live server, 2026-08-20**, which is what makes this worth doing at all rather than
a theory:

| | |
|---|---|
| Distinct images across every container | 70 |
| On Docker Hub | 44 |
| On GitHub's registry (`ghcr.io`) | 16 |
| On linuxserver's registry (`lscr.io`) | 10 |
| Agreeing with their current template | 46 |
| **Pointing at a registry the template has left** | **3** |
| Not Community Applications apps at all | 16 |

The three: `binhex/arch-prowlarr`, `ich777/rustdesk-server-aio` and `sharevb/it-tools`, all of which
their templates now publish at `ghcr.io`. Both binhex and ich777 have moved, which is the pattern
worth watching — those two are among the largest publishers still on Docker Hub, and this is them
leaving.

Three today. The number only goes one way.

## What you would notice

A quiet hint on the row — not a banner, because this is never urgent: *"The template for this app now
publishes at ghcr.io. Switch?"* Pressing it shows the exact one-line change to the compose file and
asks. Nothing moves on its own, nothing restarts, and a hint you dismiss stays dismissed.

The same hint appears beside the image field in the editor, since that is where someone is already
looking at that line.

## Decisions

| # | Question | Recommendation |
|---|---|---|
| 1 | Where the truth comes from | The Community Applications index the plugin already builds and keeps locally. No network, no new dependency, and it is refreshed on its own schedule already. |
| 2 | How an app is matched | By repository path with the registry removed — `binhex/arch-prowlarr` — never by app name, which is ambiguous across publishers. |
| 3 | What counts as a move | Same repository path, **different registry host**. Nothing else. |
| 4 | A changed namespace | **Not offered.** `bitr8/agregarr` versus `agregarr/agregarr` is a different account, and telling those apart from a genuinely different app is guesswork. Informational at most; see the risks. |
| 5 | Proved before offering | The new address must actually answer for **the same tag** we use, checked with PLAN_45's existing registry lookup. An offer that 404s is worse than no offer. |
| 6 | Where it shows | A row hint and an editor hint. Never a page banner. |
| 7 | Applying it | Rewrite the one `image:` line through the compose model, so comments, ordering and formatting survive — then it is an ordinary update. **Never** restarts anything by itself. |
| 8 | Dismissal | Remembered per image, the same way a skipped update is, so it is silent until the template moves again. |
| 9 | Non-CA images | Left entirely alone. Sixteen of this server's images are nobody's template and must never be second-guessed. |
| 10 | New imports (needs Adrian's word) | The importer could take the template's *current* address instead of the one written into the file being imported — fixing this at the door rather than reporting it later. Sensible, but it means an imported stack does not match the file it came from. |
| 11 | Whether the old address is also checked | Yes, and worth saying on the hint: if the old repository has stopped being updated while the new one moves, that is the argument for switching, and we can see it. |

## Phases

| # | Phase | Lands |
|---|---|---|
| 1 | Detection — join our images to the local index, host-only differences, prove the tag exists at the new address | Testable on the server with no UI, exactly as PLAN_45's phase 1 was |
| 2 | The row hint and the editor hint, and dismissal | |
| 3 | Applying it — the one-line rewrite through the compose model, with the change shown before it happens | |
| 4 | A one-off report listing everything that has drifted | Cheap, and useful exactly once per server |

Phase 1 is worth building even if nothing else is: it answers "is any of this stale?" for any server,
and it is the phase that proves the join is trustworthy.

## Verifying it

No PHP, Docker or browser on the dev machine, so a new `tests/server/moves.php` run on the server,
pointing its state at `/tmp` so the real file is never touched. Weighted at the refusals, as ever:

- an image whose path is not in the index is never offered anything;
- a *different namespace* is never offered as a move;
- a new address that does not answer for our tag is never offered;
- a dismissed hint stays dismissed until the template moves somewhere new again;
- a stack whose compose file was hand-authored comes back from a switch with its comments, ordering
  and anchors intact — the round-trip rule, tested on a file with all three.

## Risks

- **The same name is not the same app.** The whole join rests on repository paths being unique enough,
  which is true across registries for a given publisher and not true in general. Decision 3 keeps this
  narrow deliberately: only the host may differ, everything else must match exactly.
- **The new registry may be behind.** A publisher who has just moved may push to one and not yet the
  other. Hence decision 11 — compare both before saying anything, rather than assuming the newer
  address is the fresher one.
- **We are editing someone's file.** This writes to a compose file to change an address, which is the
  one thing this project promises to do carefully. It goes through the compose model or not at all,
  and the change is shown before it is made.
- **Small win today.** Three images on a 70-image server. The honest case for building it is that the
  cost is low because the index already exists, and that publishers leaving Docker Hub is a trend
  rather than an event.

---

# Part two — the tag went away, and the links nobody reads

**Approved 2026-08-20.** Three related pieces, in dependency order. The registry-move detector above
and these share one subject and one remedy: the address in your file is stale, and nothing told you.

## Context

Three things came out of looking at why `neosmemo/memos:latest` reports *not found in the registry*.

**It is not missing.** The repository is alive with 50 tags — `stable`, `canary`, `0.30`, `0.29.1` —
and `latest` is simply no longer among them. Update checking asks about the same tag by design, and
every route it uses collapses "no such repository" and "no such tag" into one answer. The pill then
says *could not check*, the same words as a network failure, so a permanently broken reference looks
like a passing glitch for ever.

**The link that would have explained it is in the file already, unread.** `x-unraid: project` is
written at import and read by nothing. The *What changed* link comes only from labels baked into the
image, which is why memos — a GitHub project with its URL sitting in its own compose file — offers
none.

**And most images can be traced even without it.** Measured on the live server, of 67 images: 32
declare a source in their own labels, another 19 can be found in the Community Applications feed, and
of the 16 left, six are official base images whose registry page *is* their home and three or four are
GitHub-hosted where the address itself names the owner. Only a handful have genuinely nothing.

The outcome wanted: a row that says a tag has been withdrawn and what the image offers instead; the
project and forum links stored in the compose file; and **Repo** and **CA** chips beside the existing
WebUI and Logs chips.

## Decisions taken

| # | Question | Decision |
|---|---|---|
| 1 | How a withdrawn tag is fixed | The row menu **opens the editor with that service's image field focused**, where the existing tag picker already offers the real tags. Never switched automatically — a tag is which version of a program runs. |
| 2 | Registry coverage for tag lookups | **All registries**, not just Docker Hub. |
| 3 | Where project and support links live | **Written into the compose file**, so they travel with the stack and cost nothing to read when drawing the grid. |
| 4 | Which rows get the new chips | **Service rows only.** A repo link belongs to an image, and images are per service. |
| 5 | Whether we write without asking | No. Every write to an existing file is previewed and approved. |

**One concern to state plainly** (decision 3's consequence): nothing is in the files today, so chips
would show on nothing until each stack has been through the action. The plan therefore resolves links
on demand *to produce the values being written*, and phase 3 offers to do the whole grid in one
reviewed pass. If chips-on-day-one matters more than links-in-the-file, say so and the resolver can
feed the chips directly instead.

---

## Part A — tell a withdrawn tag from a missing image

### A1. Ask any registry what tags a repository has

New `staxx_registry_tags(string $image): array` in `include/Updates.php` — bare tag names, `[]` on any
failure, like its neighbours.

- When `staxx_hub_repo_path()` accepts the reference, delegate to the existing **`staxx_image_tags()`**
  (`include/Defines.php:360`): already built, ordered by most recently pushed, capped at 50. Covers
  Docker Hub and the two linuxserver mirrors.
- Otherwise the standard registry conversation, identical for every host: `GET https://<host>/v2/`,
  read the `WWW-Authenticate` challenge for the token realm and service, fetch a pull-scoped token,
  then `GET /v2/<repo>/tags/list`. Anonymous works for public images on `ghcr.io`, `quay.io` and a
  plain registry. All through **`staxx_sh()`** with curl so nothing can hang.
- Registry v2 answers in lexical order with no dates, so nothing downstream may assume recency.

### A2. The new reason

`staxx_remote_failure_reason()` (`include/Updates.php:167`) classifies into `limited` / `notfound` /
`failed`, with `unsupported` set directly. On `notfound` **only**, ask for the tag list:

- tags returned and ours absent → `'tagmissing'`;
- tags returned and ours **present** → stays `notfound`, because something stranger is happening and a
  guess is worse than silence;
- no tags, or the lookup failed → stays `notfound`.

One extra request, only after a failure. Two of 67 images fail here, so the cost is noise — and it
must stay that way: never fetch a tag list for an image that answered.

### A3. Suggest a replacement, safe against truncation

`staxx_tag_suggestions(string $missing, array $tags): array` — at most three, best first:

1. a conventional rolling tag that exists and is not the one that vanished: `stable`, `release`,
   `main`, `latest`, in that order;
2. otherwise the highest version-looking tag, compared **numerically** so `0.30` beats `0.9`;
3. never anything reading as a test build — `rc`, `alpha`, `beta`, `canary`, `nightly`, `dev`, `edge`.

Stored on that image as `tags` and `suggest`, so the pill needs no further requests.

### A4. On the row

- New pill state `tagmissing` in `staxx_updates_pill_for_image()` (`include/Updates.php:685`), label
  **`tag withdrawn`**, tooltip naming what went and what is offered instead. It must not fall into the
  catch-all that renders every error as *could not check* — that flattening is half the problem.
- Matching modifier in `staxx_update_pill_html()` (`include/StacksTable.php:251`) and one quiet
  variant in `sheets/staxx.css`; identical markup from `paintUpdatePill()`
  (`javascript/stacks.js:11417`), which is already required to be indistinguishable.
- **Fix the tag…** in `buildStackMenu()` / `buildContainerMenu()` (`javascript/stacks.js:13339`,
  `:13462`), only for a `tagmissing` row: opens that stack in the editor with the service's image
  field focused, where `tagLoad()` / `mergeTags()` (`:8027`, `:8043`) already offer the real tags and
  `YAML.setPart()` (`javascript/compose-model.js:2897`) makes the one-line change safely.

### A5. Fix while here

A permanent failure should not be re-asked every pass. The failure branch of `staxx_update_check()`
never stamps `asked`, so `notfound` and `tagmissing` images are re-asked every run and never honour
the six-hour memory. A withdrawn tag will not come back — stamp it. Transient failures (`failed`,
`limited`) keep today's prompt retry.

---

## Part B — find the project and forum links, and store them

### B1. Resolve

`staxx_project_links(string $image, array $stackX, array $serviceX): array` in a new
`include/Links.php`, returning `['project' => …, 'support' => …, 'from' => …]` where `from` names the
source so wording can match confidence. In order, first hit wins:

1. the service's own `x-unraid` block, then the stack's — a stored answer always wins;
2. the image's `org.opencontainers.image.source` label, which `staxx_update_labels_meta()` already
   reads and the update state already holds (32 of 67 images);
3. the **Community Applications feed** — `Project` and `Support` from the full record read by
   `staxx_ca_app()` (`include/CA.php:364`), joined on repository path with the registry stripped
   (+19). This needs a repository→ordinal map, which does not exist today: `staxx_ca_search()` ranks
   text and there is no exact join. Build it once per request from `staxx_ca_index_data()`, keyed on
   the `r` field normalised the way `repositoryPath()` (`javascript/ca-convert.js:329`) does;
4. **derived from the address** for `ghcr.io/<owner>/<name>` → `https://github.com/<owner>/<name>`.
   Marked `from: 'derived'`, because a package can live in a differently-named repository;
5. the registry's own page for an official base image (`alpine`, `debian`, `node`…), whose home that
   genuinely is.

Never invent: a hit only counts when it is `https://`. `paperless-redis` — a local name with no
registry — must come back empty and stay that way.

### B2. Store, per service

The format allows `project`, `support` and `readme` only at stack level
(`schema/x-unraid.schema.json`), but decision 4 puts the chips on service rows, and a three-service
stack has three projects. So: **add `project` and `support` to the service block** in the schema, with
prose in `docs/x-unraid-schema.md`. Stack level keeps working and acts as the fallback.

### B3. Write

- **New imports** get it for free: `javascript/ca-convert.js:795` already writes stack-level
  `project`/`support`/`readme` from the template. Extend it to write them per service when the
  resolver knows one, for non-CA imports too.
- **Existing files** need an insert, not a span replacement, and the only comment-preserving editor
  lives in the browser. **Verify first**, before anything else in Part B is built: can
  `javascript/compose-model.js` add a key to an existing mapping and create an `x-unraid` block that
  is absent? The form editor adds ports and volumes, so the primitive probably exists — find it. If it
  does not, this part stops and gets its own plan rather than growing a second write path.
- A row-menu item **Find the project link…** per service: resolves, shows the exact lines to be
  added, writes on approval through the model and the existing whole-file `save` action.

---

## Part C — the Repo and CA chips

`staxx_row_actions_html()` (`include/StacksTable.php:295`) is the single renderer for the WebUI and
Logs chips, called once for a stack row and once per service row. Two more chips go in there, on the
**service call site only** (`:1162`):

- **Repo** → the resolved `project` URL, `target="_blank" rel="noopener"`, gated by the same
  `^https?://` test the WebUI chip already applies at `:304`.
- **CA** → the `support` URL, which for a Community Applications app is its Unraid forum thread. The
  label is `CA` rather than "CA Support" because the chip column is 4.2rem wide.
- Both are **omitted** when there is no URL, unlike WebUI which shows disabled. That chip stays for
  alignment of the name column; these carry no such duty, and an empty chip teaches nothing.

**Layout.** `.staxx-rowactions` is a single-column grid (`sheets/staxx.css:449`), so four chips would
stack vertically and roughly double the height of every service row — and two rules are written
against that column's height already (`.staxx-icon { align-self: flex-start }` at `:471`, and
`.staxx-dot`'s absolute position at `:556`). Make it two columns when there are more than two chips,
so four chips sit two-by-two and the row grows by nothing. Revisit `min-width: 4.2rem` for the pair.

**Getting the data to the row.** A service row does not have its `x-unraid` block in scope: only
`icon` and `webui` are lifted out of `$declared` inside `staxx_stack_children()`
(`include/StacksTable.php:353`). Add `project` and `support` to the row arrays it builds there —
`$declared` is already in hand, so this costs nothing. **Do not** call `staxx_compose_meta()` in the
row loop: that is the 316ms-across-64-stacks cost the comment at `:1274` warns about. And nothing goes
into `staxx_state_snapshot()`, which does not emit these chips and must stay fast.

---

## Files

| File | Change |
|---|---|
| `include/Updates.php` | `staxx_registry_tags()`, `staxx_tag_suggestions()`, the `tagmissing` reason and pill, the `asked` stamp |
| `include/Links.php` *(new)* | `staxx_project_links()` and the CA repository→ordinal map |
| `include/StacksTable.php` | pill modifier; two chips in `staxx_row_actions_html()`; `project`/`support` in `staxx_stack_children()` |
| `include/action.php` | one case to resolve links for a service, for the row-menu action |
| `sheets/staxx.css` | pill variant; two-column chip grid |
| `javascript/stacks.js` | matching pill markup; *Fix the tag…*; *Find the project link…* |
| `javascript/ca-convert.js` | per-service `project`/`support` on import |
| `schema/x-unraid.schema.json`, `docs/x-unraid-schema.md` | service-level `project` and `support` |
| `tests/server/updates.php`, `tests/server/links.php` *(new)*, `tests/validate_schema.py` | below |

## Verifying it

No PHP, Docker or browser on the dev machine. `node --check` and `node tests/js_undeclared.js` after
every JavaScript change; `php -l` on the server after every deploy; `python tests/validate_schema.py`
with negative cases for the new service keys (a non-URL, a number). Server tests point their state at
`/tmp`, as they already do. Weighted at the refusals:

- a repository answering with tags, ours absent → `tagmissing`, never *could not check*;
- ours **present** despite a not-found answer → stays `notfound`, no suggestion invented;
- a tag list that cannot be fetched → stays `notfound`;
- `canary`, `0.30.0-rc.1`, `nightly` never suggested; `0.30` outranks `0.9`; `stable` outranks a
  higher version number;
- an image that answered normally triggers no tag lookup at all;
- a `tagmissing` image is not re-asked within six hours; a transient failure still is;
- `staxx_project_links()` returns nothing for a local-only name, never a fabricated URL, and prefers a
  stored answer over every lookup;
- a chip is omitted, not disabled, when no URL is known, and a `javascript:` URL is refused;
- a hand-authored file with comments, ordering and anchors comes back from a link write with all three
  intact — the round-trip rule, on a file that has all of them and no `x-unraid` block at all.

**End-to-end on the box:** `neosmemo/memos:latest` moves from *could not check* to *tag withdrawn*
suggesting `stable`, with *Fix the tag…* opening its editor on the image field; that stack's service
row gains a Repo chip pointing at `github.com/usememos/memos`; and `paperless-redis` gains neither a
suggestion nor a chip.

## Risks

- **A registry conversation per host is new surface.** A private registry will simply return nothing,
  which is correct, and must never be read as a withdrawn tag.
- **A suggestion is advice.** `stable` may not be the same content stream as the old `latest`. The
  wording offers; decision 1 means the choice is made in the editor with the real list in view.
- **Derived links can be wrong.** `ghcr.io/owner/name` usually maps to that repository and sometimes
  does not. Hence `from`, so the wording can say "project page" rather than "release notes".
- **We are writing to existing files.** The one thing this project promises to do carefully. It goes
  through the compose model or not at all, previewed every time, and the insert capability is verified
  before any of Part B is built.
- **Row height.** Four chips in one column would grow every service row; two columns is the whole
  mitigation, and it wants an eye on the real grid before it is called done.
