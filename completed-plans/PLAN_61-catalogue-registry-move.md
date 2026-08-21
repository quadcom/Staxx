# PLAN 61 — noticing when a catalogue app has moved house

Approved 2026-08-21. The GitHub-watch half discussed at the end becomes **`PLAN_62.md`**, written
separately — see *Deliberately deferred*.

## The wire contract, fixed up front

Both halves of the work are written against this, so the server and browser sides can be built in
parallel. The `read` action's reply gains one optional key:

```
moved: {
  "<service name>": {
    repo:   "binhex/arch-prowlarr",          // registry-free path the fact was raised against
    host:   "ghcr.io",                       // the new registry
    tag:    "latest",                        // the tag in use, proven present at the new host
    tags:   ["latest", "1.37.0", "1.36.2"],  // up to three newest at the new address
    reason: "The template for this app now publishes at ghcr.io. …"
  }
}
```

Absent, or an empty object, means nothing to say. The browser builds each candidate value as
`host + '/' + repo + ':' + <tag>`, and uses `repo` to notice the line has since been hand-edited.
`reason` is composed server-side, because only the server knows both registries.

## Context

A Community Applications template is a snapshot taken the day you added the app. Unraid never
revisits it. So when a publisher moves their images to a different registry, every server that added
the app before the move keeps pulling the old address for ever — and the old address usually keeps
working, which is exactly why nobody notices.

Two reasons it matters: Docker Hub gives an unsigned-in server roughly ten questions an hour, so an
image still pointed at Hub for no reason spends an allowance the update checking needs; and an
abandoned repository eventually stops being updated, silently, because a stale tag still pulls.

Measured on the live server 2026-08-20: **3 images of 70** point at a registry their template has
left — `binhex/arch-prowlarr`, `ich777/rustdesk-server-aio` and `sharevb/it-tools`, all now at
`ghcr.io`. Three is small. The argument is that binhex and ich777 are among the largest publishers
still on Hub, and this is them leaving.

`PLAN_60` landed first on purpose, so this is written against the corrected write path rather than
one that was quietly corrupting four-space files. Concept and measurements: `FEATURE_55.md`.

## Why an account rename is refused — the real reason

The earlier note said telling a renamed account apart from a genuinely different app is *guesswork*.
That is true but soft, and it invites someone later to argue we could be cleverer.

**The hard reason, from Adrian 2026-08-21:** under Community Applications' own rules, once an
account name and app name are chosen, changing them afterwards is treated as hostile and the app is
pulled from the catalogue. So a legitimate account rename **cannot happen**. An apparent one is
either a different app, or an app that has been pulled. Either way there is nothing to offer.

That makes the refusal a rule being enforced, not a judgement being dodged. Record it in the code
comment, because the soft version reads like a limitation someone should fix.

Only the **registry home** legitimately changes, and only for catalogue apps, whose template is the
single source of truth. That is exactly the shape already designed — no behavioural change follows
from this, only a better-stated reason.

## What the user sees

Nothing urgent, ever. No banner.

**In the form**, the Image field gets an **orange** border — not red, nothing is broken — and
underneath it, always visible:

```
Image  [ binhex/arch-prowlarr:latest              ]   ← orange border
   The template for this app now publishes at ghcr.io. Docker Hub still
   answers, but it is no longer where updates are pushed.
   Switch to:  [ :latest ]  [ :1.37.0 ]  [ :1.36.2 ]   [ Leave it alone ]
```

**In the raw editor**, an orange dotted underline under the image value and an orange gutter dot,
with hover text carrying the reason and pointing at the Form view. **No buttons in the editor** — a
tooltip containing buttons closes as the pointer moves toward what it wants to click.

## Decisions — do not re-litigate

| | |
|---|---|
| **Imports** (was decision 10) | An imported stack keeps the address written in the file it came from. Nothing is rewritten at import time; the move is reported afterwards. |
| **The three choices** | Three newest **tags at the new registry** — one path, three versions. Only one alternative path can exist, so the choice is about versions. |
| **Where it shows** | Orange border plus an always-visible note under the field; hover-only text in the editor. Not a hover popover. |
| What counts as a move | Same repository path, **different registry host**. Nothing else. |
| An account rename | Never offered — see above. Needs **no code**: the catalogue map is keyed on the full path, so a different account is simply not found. A refusal to **test**, not a branch to write. |
| Proved before offering | The new address must answer for **the tag in use**. An offer that 404s is worse than no offer. |
| Applying it | In-place rewrite of the one `image:` line. Never restarts anything. |
| Dismissal | Per image, revived if the template moves somewhere new again. |
| Non-catalogue images | Left alone **by this plan**. See *Deliberately deferred*. |

## What already exists — reuse, do not rebuild

- **The join.** `staxx_links_repo_path()` (`include/Links.php`) already strips tag and registry host,
  so `ghcr.io/binhex/arch-prowlarr:latest` and `binhex/arch-prowlarr:latest` both reduce to
  `binhex/arch-prowlarr`. `staxx_links_ca_map()` maps that to a catalogue ordinal, memoised per
  request. The catalogue index already carries each template's **current** address in its `r` field —
  so detection reads only `index.json`, already decoded and request-cached, with **no** seek into
  `apps.jsonl` and **no** network call.
- **The three-tag shortlist.** `staxx_tag_suggestions()` (`include/Updates.php`) already drops the
  current tag and pre-release builds, prefers a rolling tag, otherwise picks the highest version by a
  deliberate integer-segment comparison, and trims to three. Its output is currently stored and
  **never displayed**.
- **The underline.** `.staxx-badpath` is an absolutely-positioned box carrying
  `border-bottom: 0.2rem dotted`, painted into the `.staxx-yamlmarks` overlay, and its `--file` /
  `--inuse` variants are **already orange**. Extend this; do not invent an overlay.
- **The gutter dot is free and already orange.** `.staxx-yamldot--warn` is `var(--sm-accent)`, and
  `redrawDots()` already concatenates four sources — a fifth costs almost nothing. **No new colour,
  no new dot class.**
- **An actionable button inside a field note.** `adviceText()` already emits a real
  `<button class="staxx-declfix">` for the declare-a-missing-network case.
- **The in-place write.** `image` is `{shape:'scalar', always:1}`, so `setValue` → `writeScalar`
  replaces exactly the value's column span. Verified by probe: this still succeeds on a file the
  parser could only partly read, where a structural insert is now refused.
- **The dismissal shape.** `staxx_update_skip()` stores the specific thing dismissed and is honoured
  only while it still matches, so it self-expires. Copy that, comparing the **host**.

## Four things verified rather than assumed

1. **`spot` carries what the underline needs** — probed: `{line, col, len, style}`. No conversion
   helper required.
2. **The row badge can only say one thing.** A service has exactly one advisory state, so "update
   available" and "template moved" collide. **Update wins the badge**; the move rides alongside as
   data so the menu and the editor still offer it, and the badge falls through to the move on its own
   once the update is applied.
3. **The shortlist has an ordering wart here, and a design pass got it wrong.** It claimed the
   function is order-independent and told future readers not to "fix" it. Its two *ranked* picks are
   order-independent — a rolling tag, and the highest version by explicit numeric comparison. Its
   **backfill** is not: it walks the candidates in the order given. Docker Hub answers newest-first;
   the generic route used for any *other* registry answers **lexically**. For a repository whose tags
   are dates or hashes, with no rolling tag and nothing version-shaped, lexical ascending offers the
   **three oldest**. Fix at the new call site only — reverse the list before passing when the new host
   is not Docker Hub — leaving withdrawn-tag behaviour untouched.
4. **Docker Hub sign-in does exist.** A report claimed the "sign in to raise the limit" message
   points at a feature that was never built. It is in Settings as `HUB_USER`/`HUB_TOKEN`, the token is
   masked, and `apply_settings` performs the login. Not a bug — do not "fix" it.

## The work

Four stages. **Stage 1 is worth shipping alone** — it answers "is any of this stale?" and it is the
stage that proves the join is trustworthy.

### Stage 1 — detection (`Links.php`, `Updates.php`)

Two pure functions in `include/Links.php`, beside `staxx_links_repo_path()` so the host/path split
can never disagree with it:

- `staxx_links_image_host()` — the host half, lower-cased, `''` when none is written. **Must fold
  Docker Hub's own aliases** (`docker.io`, `index.docker.io`, `registry-1.docker.io`) to `''`, or an
  image written `docker.io/x/y` misreads as moved against a catalogue entry with no host written.
- `staxx_links_move_candidate()` — the join. Returns the new host and the full new image reference,
  or `[]` for not-catalogued / same host / anything else.

One network step in `staxx_update_check()`'s per-image loop, the **only** place allowed to touch the
network: for a flagged image, list tags at the new address and keep the fact only if **the tag in use
is there**. Store on the existing per-image entry as `move => {host, tag, tags}`, and `unset` it
first each pass so a fact that stops being true disappears.

Cost: one extra registry round trip per affected image per pass — three of seventy — riding the
existing six-hour cadence. It never spends Hub allowance, because a move is only interesting when the
*new* host is not Hub.

**The trap, load-bearing.** `Links.php` requires `Updates.php`, so the reverse would be a cycle; the
call is therefore guarded by `function_exists()`, matching the existing `staxx_rebuild_due()`
precedent. But the real out-of-band passes spawn a process that requires **only `Updates.php`**, so
the guard would be permanently false and the feature would appear to work only when driven from a
foreground request. **Both spawn sites must be widened to require `Links.php`** —
`staxx_update_check_start()` in `include/Updates.php`, and `run_check()` in `scripts/update-check`.
Without this the whole stage is dead code in production.

### Stage 2 — the badge and the endpoint (`Updates.php`, `StacksTable.php`, `action.php`)

- A new pill state `moved`, promoted **only** from `current` — mirroring how `built` becomes
  `rebuild`. Carry the move on every pill regardless of headline state, so nothing is lost to the
  collision.
- Rank it below `built`/`missing`, above `unknown`, with a count and a plural sentence, following the
  `tagmissing` precedent.
- An inert `<span>`, not a button — only `update` is pressable.
- `staxx_update_skip_move()` beside `staxx_update_skip()`, same allowlist guard (the image must
  already be a key in the state file, since a request must not invent entries), storing the host.
- One `action.php` case, `update-skip-move`, beside `update-skip`.
- **Deliver the facts to the editor on the `read` reply, not via row attributes.** `read` already
  returns the body and the fingerprint; a per-service `moved` map works however the editor was opened
  and does not depend on the row being in the DOM. This removes the need for new `data-moved-*`
  plumbing entirely.

### Stage 3 — the two surfaces (`stacks.js`, `staxx.css`)

Small, separately checkable edits, in order:

1. **CSS**, three additions: `.staxx-fieldrow--moved` (modelled on `.staxx-fieldrow--gap` but
   `--sm-accent`), `.staxx-badpath--moved`, and button styling beside `.staxx-declfix`.
2. **`movedFacts` and `applyMovedAdvice()`** — one module var and one function that grafts the fact
   onto the Image field **after** `buildForm` returns, and toggles the row class in the same walk.
   `buildForm` stays pure and registry-ignorant: that is the central design point. Re-run after every
   `reparse()` and `refreshRanges()`, since both replace the field array wholesale. Must compare the
   field's current repository against the fact and **stop applying when they differ** — the user may
   already have edited that line.
3. **The note and buttons** in `adviceText()`, beside the `declareMissing` branch. Each button
   carries the exact resulting value, and its label and tooltip show it.
4. **Two click branches** on the existing form-host delegate: apply, and dismiss. Apply calls
   `flushPending()`, then `pushUndo()`, then the in-place write. **No confirmation dialog** — the
   button's label is the preview, Undo is right there, and nothing reaches disk until Save. A second
   dialog repeating the same string is the duplicated step the house rules warn against.
5. **The editor underline** — a paint function beside the existing path-underline painter, into the
   same overlay, keyed off the field's `spot`. Wiring into the existing repaint gets scroll, view
   switch and caret moves for free.
6. **The hover text** — a branch in the existing hover handler, reusing the manually-positioned panel
   the path hover already feeds. Prose only.
7. **The gutter dot** — a fifth list into `redrawDots()`, filled inside `applyMovedAdvice()`'s
   existing walk. Level `warn`, already orange.

Dismissal needs no clearing code: the surfaces are recomputed fresh, never diffed, so removing the
fact makes all three disappear on the next `refreshRanges()`.

### Stage 4 — a one-off drift report

Everything that has moved, in one list. Cheap once the join exists, useful exactly once per server.

## Deliberately deferred to PLAN_62 — watching the author's own repository

Adrian's question was whether we can watch a non-catalogue author's published sample compose.
**Answer: yes, and better than expected — but it is a bigger feature than this one, so it is its own
plan.** Nothing in this plan needs to change to enable it. Recording the findings so PLAN_62 starts
from fact rather than re-deriving them.

**We already know where the source lives, for free.** An image can carry
`org.opencontainers.image.source`, and `staxx_update_labels_meta()` **already captures it** into the
per-image state whenever it is an `https://` URL. Measured on the live server 2026-08-21:

| | |
|---|---|
| Distinct images on the box | 83 |
| Carrying an https source label | **44 (53%)** |
| Of those, pointing at GitHub | 43 |
| Pointing elsewhere (GitLab) | 1 |
| Hosted at ghcr.io / lscr.io | 28 |
| Inspection route in use | `imagetools` — the one that carries labels |
| Source URLs **already stored** in `updates.json` | **32 of 68 tracked images** |

So the groundwork exists and always has. This is not a guess derived from the image name — the
publisher put it there. `staxx_links_derive_ghcr()` also exists but is explicitly a *derived* guess
from a `ghcr.io/owner/name` address; prefer the label.

**What is missing is only the fetching.** There is no GitHub call anywhere in the plugin, and nothing
anywhere stores a previous copy or hash of any remote text to compare against — no `etag`, no
`If-None-Match`, no stored README. The only cross-pass comparison that exists is digest-shaped.

**Two corrections to my own earlier reasoning, recorded so PLAN_62 does not inherit them:**

- I said the plugin "can't read GitHub". Wrong. The Hub-only refusal in `staxx_hub_repo_path()` is
  narrower than that: it declines to pretend *another registry's API* is Docker Hub's, which is
  correct, and says nothing about GitHub. There is no barrier, only absent code.
- I suggested "has this repository gone quiet?" might be answerable from data already collected. It
  is not. The only recency field is `created`, an **image-build** timestamp from labels the image may
  not carry, and it is compared against nothing over time.

**Decided shape for PLAN_62 (Adrian, 2026-08-21): a cheap sentinel, then the real read.**

1. Ask the repository's own last-changed date — one small request per image that has a source label.
   If it has not moved since the last pass, stop there.
2. Only when it has changed, look for the project's compose file at the conventional paths and
   compare its image address with ours.

This mirrors the catalogue cache's existing pattern, which asks for a 37-byte stamp before deciding
whether to download 24 MB — the model to copy. It also sidesteps the objection that killed the
README idea: GitHub's rate limit is separate from Docker Hub's, so watching it does not spend the
allowance the update checking needs.

**What PLAN_62 must still solve, flagged not answered:** where the fetch is gated and paced (the
existing README fetch is outside the rate machinery entirely — not time-gated, not paced, not stopped
on a refusal); which conventional compose paths to try and when to give up; and that a compose file
found in a repository is an *example*, not necessarily what that publisher recommends for this tag.

**Also still true:** roughly half the images carry no source label at all — the plain ones like
`alpine`, `adminer`, `apache/tika`. For those there is nothing to watch and no honest way to invent
it. Say so rather than guessing.

## Verification

Local, after **every** edit to a browser file — the second catches a name assigned but declared
nowhere, which the first cannot see and which kills the whole page at run time:

```sh
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js      # 1,476 passing — must not drop
```

A throwaway node probe proving the switch really is the in-place path: apply it to a fixture from
`tests/fixtures/test-stacks/` that has an **unreadable tail**, and assert the rewrite succeeds where
a structural insert is refused. That is the contract this feature rests on.

On the server: `php -l` over `include/*.php`, then all `tests/server/` suites (17 passing today),
plus a new `tests/server/moves.php` pointing `STAXX_UPDATE_STATE` at `/tmp` and backing up the
catalogue index it writes a fixture over. **Weighted at the refusals:**

- a path not in the index is offered nothing;
- a different account name is never offered, even when the trailing name matches;
- the same host — including each of Hub's three aliases — is never offered as a move;
- a new address that does not answer for our tag is never offered;
- a dismissed hint stays dismissed, and **revives when the template moves somewhere new**;
- an image never checked cannot be dismissed.

The network-proving step cannot be made deterministic without a stub this repo does not have. Test
the join and the state machine here; verify the proving step by hand against the three real images.

## Open, and deliberately left open

1. **Decision 11's freshness argument** — the feature document says it is worth telling the user if
   the old repository has gone stale while the new one moves. Existence at the new host is proved;
   *freshness* would need a second network call per affected image. Recommend shipping without it and
   deciding when the hint copy is written; the qualitative argument ("this is where updates are
   pushed now") may be enough. PLAN_62 may answer it properly for free.
2. **A typo in the metadata key is still silently ignored** — anything starting `x-` is waved
   through, so `x-unriad` is accepted and then never read. Unrelated to this feature, found during
   `PLAN_60`, still worth its own small fix.

---

## Status: COMPLETE — 2026-08-21

All four stages built, deployed and verified on the server. `1476` round-trip, `236` catalogue,
`73` undeclared-name, schema self-test clean, `php -l` clean, all 18 server suites passing
(including the new `tests/server/moves.php`).

**Where the plan was wrong, recorded so the next one is not:**

1. **Stage 4 as written would have broken the self-test.** It specified attributing each drift to a
   stack and service, which means parsing every compose file — and that shells out for up to 15
   seconds per stack on a cache miss, while the browser gives the whole self-test 15 seconds total.
   On a cold cache the button people press when the page misbehaves would have shown nothing.
   Rewritten to read the state file alone, deliberately dropping attribution, with the test now
   asserting the absence so nobody restores the slow version without solving that first.
2. **The ordering wart was worse than item 3 of *Four things verified* allowed for.** Correct that
   the two ranked picks are order-independent; the fix at the new call site was necessary and is in.
3. **A test fixture contradicted itself** — printing one host as the new address beside a reason
   naming a different one. Fixed; host and reason now stay in step.

**Still open** — the two items under *Open, and deliberately left open* above, neither blocking:

- Decision 11's freshness argument, deliberately shipped without. `PLAN_62.md` may answer it for
  free, since an author's own published example is a better freshness signal than a second registry
  call.
- ~~The `x-unriad` typo trap.~~ **Fixed 2026-08-21**, in the editor's existing unknown-key check —
  a near miss of `x-unraid` now warns, an unrelated `x-` block still does not.

The GitHub-watch half deferred here is now written up as `PLAN_62.md`.
