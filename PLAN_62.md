# PLAN 62 — watching what the author actually publishes

**Reserved and written 2026-08-21.** Deferred out of `PLAN_61` by decision, not by omission —
`completed-plans/PLAN_61-catalogue-registry-move.md` records the findings this starts from so they
are not re-derived. Plan only; no code has been written.

Scope answered by Adrian, 2026-08-21. Every decision below is his; the measurements are mine and
were taken on the live server rather than assumed.

**Stages 1 to 4 built, verified and deployed — the plan's original scope is complete. Stage 5 is
written up and deliberately held at Adrian's word, 2026-08-22.**

Stage 4 keeps a dismissal as **the author's value, not a flag**, so it expires itself: the dismissal
is honoured only while the author's value is still the one that was waved away. The report is built
from the state file alone and measured at **0.17ms over 100 findings**, against a self-test budget of
fifteen seconds — and it distinguishes "nothing to look at" from "the array is not started, so StaXX
cannot see the stacks", which `PLAN_68` Part C names as a rule.

**A third bug, found while checking Stage 3 and fixed at the root.** The stamp that says "this file has
not changed" lives on the **flash drive**; the copy of the file it refers to lives in **`/tmp`, which a
reboot wipes**. Quoting a stamp for a body no longer held earns a cheap "unchanged" — and then there
is nothing to compare against. Worse, the comparison returned **no findings and no reason**, which on
screen is indistinguishable from "your file matches the author's". After any reboot that would have
been every stack, silently, until an author happened to edit their file — possibly never.

Fixed in two places: the stamp is only offered while the body is genuinely still there, so a wiped
cache costs one honest refetch; and a missing body is now a stated reason rather than a clean zero.
Proven on the box by moving the cache aside — 0 findings with the reason, 4 findings with it back.

**A correction to this plan, found by measuring rather than reading.** Stage 2 says findings are
"stored per image in the state file". That is wrong wherever **two stacks use the same image** — the
comparison runs against one stack's file, and the findings would then be shown against the other
stack too, which is a wrong finding presented as the author's word. Counted on the box: **5 of 65
images are used by more than one stack**, including two real ones (`phpmyadmin` across two stacks,
`tdarr` across two).

Discovery and the fetch stay **per image** — that is where the network cost is. The **comparison is
local and cheap**, so it runs **per stack**, and findings are keyed by stack. Settled before Stage 3
paints anything, since Stage 3 would otherwise be built on the wrong shape.

**And a second trap that came with the new shape, closed at the same time:** a stack that is removed
or renamed leaves its findings behind. Stage 4's report is built **from the state file alone** — a
constraint this plan already imposes for a good reason — so without pruning it would list findings
against stacks that no longer exist, and the file would grow on the flash drive forever. Pruned on a
full pass only: a scoped pass has not looked at every stack, and would delete what it merely did not
visit.

**The reach, measured on the box rather than estimated:** 68 images — **7 pinned, so never looked at
at all**, 61 rolling, and of those **38 have a project home to ask**. The plan predicted 37 listings
would fit inside GitHub's hourly allowance of 60; the real number is 38. Twenty-three rolling images
have no home and get nothing, exactly as the "other half" note says: `debian`, `postgres:15-alpine`,
`nginx:alpine` and the like have nothing to watch and no honest way to invent it.

**The spawn-site trap was cleared and proved the right way** — by running the real out-of-band pass
on the server, not by calling the function. Two images carry a watch result in the live state file
after it, which is what a partial pass under the per-pass budget should leave.

**A bug the first test run caught, worth remembering:** PHP's `+` on two arrays keeps the *left*
side's value on a key collision, so building a result as `$empty + ['ok' => true]` silently kept
`ok: false`. Nineteen call sites. It is the kind of thing that reads correctly and does the opposite.

**Outbound, counted:** eight requests to GitHub from the server across all testing. Each sent only an
`Accept` header, a conditional stamp, and a generic agent string. Confirmed against the live API that
an unchanged file costs nothing at all against the hourly allowance, which is what makes asking on
every pass affordable.

---

## Context

`PLAN_61` answers one question — *has this catalogue app's publisher moved registries?* — using the
Community Applications index, which only knows about catalogue apps. Sixteen of this server's images
are nobody's template, and for those the catalogue has nothing to say.

But roughly half of all images **name their own project page**, because the publisher put it inside
the image as a label, and the plugin has been quietly capturing that for some time. So a second,
independent source of truth already exists and has never been read: **the example compose file the
author themselves publishes.**

This plan reads it, and tells you what the author changed that you do not have.

## What the numbers say

Measured on the live server, 2026-08-21. The second table is the one that matters, and it is the
honest case for keeping this feature small.

**The watch list**

| | |
|---|---|
| Images the update pass tracks | 68 |
| On a **rolling** tag (`latest`, `stable`, `main`, …) | 56 |
| Declaring a GitHub project inside the image | 32 |
| Living at GitHub's own registry, so the project address is derivable | 15 more |
| Having a findable project home either way | 39 |
| **Both rolling-tagged and findable — the actual watch list** | **37** |

**What those authors actually publish** — a 12-repository sample of that list, checked directly:

| What the repository holds | Count | Examples |
|---|---|---|
| One clear compose example in a file | 2 | `dozzle`, `seerr` |
| A compose example inside the README only | 2 | `linuxserver/jellyfin`, `tubesync` |
| Several compose files, no obvious "the" one | 3 | `Stirling-PDF` (four, one a CI test), `glances` (one under test data), `it-tools` (four variants) |
| Nothing to compare against at all | 5 | `tdarr`, `Tautulli`, `teslamate`, `binhex/arch-prowlarr`, `homarr` |

Scaled to 37: roughly **12 comparable**, **9 ambiguous**, **16 with nothing published**.

**This is the feature's real size and it must be stated in its own copy.** A third works. Nearly half
of these authors — including binhex, and every `linuxserver` repository as a *file* — publish no
compose file, and document with `docker run` or an external documentation site instead. Reading
READMEs is what recovers `linuxserver`, which is seven of this server's images. Saying "this author
publishes no example" is a legitimate answer, not a failure, and the feature must say it plainly
rather than looking broken.

## Decisions — settled, do not re-litigate

| | |
|---|---|
| **Which images are watched** | **Only images on a rolling tag.** A pinned version means "this exact thing" — the file is locked as written and nothing upstream may nag about it. Not merely silent: **not even fetched.** Comparison begins only if that tag is later changed to a rolling one. *(Adrian's own rule, stronger than the option offered, and it removes the entire version-drift problem rather than papering over it.)* |
| **How the project home is found** | The address the publisher declared inside the image. Failing that, and **only** for an image kept at GitHub's own registry, the matching project address, which is a near-certainty there. Nothing else is guessed. A plain image like `alpine` gets nothing, and says so. |
| **Never** read the Docker Hub page for a project link | It works and it spends the Docker Hub allowance the update checking exists to protect. |
| **The sentinel** | Ask the file itself "have you changed since I last looked?", quoting what was seen last time. Unchanged costs a few bytes and no content. This **replaces** the two-step "ask the date, then the file" shape sketched in PLAN_61: one request does both jobs, and file serving is counted separately from the stricter API allowance. |
| **Finding the file** | Ask for the repository's file listing **once**, so the example is found rather than guessed at — including one kept under `examples/` or `docs/`. Remembered until the repository changes. |
| **Several candidates** | **Compare nothing.** Say "this author publishes several examples". Picking one silently would compare you against a CI test fixture and present the result as the author's word. |
| **No candidate file** | Fall back to the README, and accept it **only** when it holds exactly one fenced YAML block containing a services list. Two or more such blocks — the common "here is compose, here is plain docker" README — is a refusal with a reason, not a guess. |
| **What is reported** | **Only what is missing or new**: a setting the author added that you do not have, or one they dropped that you still carry. **Never a value you have merely set differently.** Your paths, ports, user ids, timezone, container names and networks are your configuration; mentioning them is guaranteed noise on every stack, and is the difference between roughly two findings per stack and roughly fifteen. |
| **Where findings appear** | On the form field each finding concerns, in the section it belongs to, using the orange treatment PLAN_61 already built. An addition with no field to paint collects in one short note at the top of the form. |
| **Applying** | **Never.** Read-only, no write path at all. PLAN_61's one-line address rewrite stays the only automatic edit, because there the new value is *proven* correct; here it is an example, and possibly one for a different major version. |
| **Dismissal** | Per finding, revived when the author changes that same thing again. Third use of the pattern `staxx_update_skip()` and `staxx_update_skip_move()` already establish. |
| **On by default** | Yes, riding the existing six-hourly pass, with an off switch in Settings. Nothing about the server is sent. |
| **The row** | A quiet, inert count — "3 to look at" — ranked below every real problem so it can never outrank an update, a stopped container or a registry move. Without it the feature is invisible unless someone happens to open an editor. |

## Where the comparison happens — the load-bearing decision

This needs settling before any code, because the obvious two answers are both wrong.

**Not in PHP directly.** Verified on the server: PHP is 8.4.23 with **no YAML extension**
(`yaml_parse` absent). Writing a second, cruder YAML reader in PHP to compare against the careful
one this project already owns would be a parser that disagrees with the real one — and the
disagreement would surface as a wrong finding presented as the author's word.

**Not via `docker compose config` on the fetched file.** It resolves `env_file` and `extends`, so
pointing it at a third party's file invites it to read local paths named by that file, and it can
take fifteen seconds per call. Refused on both counts.

**Through node, using the parser that already exists.** Verified present: `node v22.18.0` at
`/usr/local/bin/node`, shipped by Unraid's own `dynamix.unraid.net` package on 7.3.2. And
`compose-model.js` is already requireable from node — the whole local test suite depends on that.
So the comparison runs through **the exact parser the browser uses**, giving one parser, one set of
round-trip guarantees, and no second implementation to fall out of step.

**The catch, and it is not optional:** node arrives via a *package*, not the base system. A server
without it must get "the comparison cannot run on this server", never a partial answer. Probe once
and remember the answer in the state file, exactly as `staxx_docker_inspector()` already probes for
an inspection route — same TTL, same short-circuit, same reason (probing per image would be sixty
pointless processes).

## What already exists — reuse, do not rebuild

- **The project address.** `staxx_update_labels_meta()` already captures
  `org.opencontainers.image.source` into per-image state whenever it is an `https://` URL — 32 are
  stored right now. `staxx_links_derive_ghcr()` already derives the GitHub address for an image at
  GitHub's registry. Neither needs writing.
- **The conditional-fetch pattern.** `staxx_ca_index_feed_stamp()` is the model to copy verbatim in
  spirit: it asks for a 37-byte stamp before deciding whether to download 23.9 MB, with
  `CURLOPT_MAXFILESIZE`, a connect timeout, a redirect cap and a named user agent. Every one of
  those guards applies here.
- **The transport bound.** `staxx_hub_json()`'s `--proto '=https,http'` exists because a URL read out
  of a remote reply reaches curl. A project address read out of an image label is exactly that kind
  of URL. **Reuse the bound; a fetch here must never accept a scheme curl merely supports.**
- **Least-recently-asked ordering.** `staxx_update_check()` already sorts its pass so the images past
  a rate ceiling are not the same ones every time. The discovery work inherits this for free.
- **Every surface.** PLAN_61 left `.staxx-fieldrow--moved`, `.staxx-badpath--moved`,
  `applyMovedAdvice()`, the `adviceText()` advisory branch, the fifth `redrawDots()` source, the
  per-service map on the `read` reply, and the promote-only-from-`current` pill rule. This plan adds
  a second fact to the same machinery rather than a second machinery.
- **The dismissal shape.** `staxx_update_skip_move()` stores the specific thing dismissed and honours
  it only while it still matches, so it self-expires. Copy it a third time.
- **The field a finding attaches to.** A form field already carries `binder`, `target`, `listKey`,
  `index` and `spot`. PLAN_61 proved the lesson: read those properties, **never** rebuild a field id
  by string concatenation — that was a real bug caught in review.

## Four things verified rather than assumed

1. **The API listing works unauthenticated and the parsing is fiddly.** The reply is pretty-printed,
   so a naive `"path":"` match finds nothing and reads as "no author publishes a compose file". I
   made exactly that mistake and it produced a confident, wrong, all-zeroes table. Whatever reads
   this reply must be tested against a real captured body.
2. **Rate limits are two separate counters.** The file listing is counted against the strict
   allowance — 60 an hour for an unauthenticated address, shared with anything else on the box.
   Fetching a file's content is not. With 37 images the listings fit, **but only just**, and a forced
   recheck plus retries would not. Discovery must therefore be capped per pass and spread across
   passes, and a refusal must be handled the way `'limited'` already is: stop the pass, say so, try
   later. Never retry in a tight loop.
3. **A rolling-tag test does not exist yet.** `staxx_tag_suggestions()` knows the words
   `stable`/`release`/`main`/`latest` inline, and `staxx_image_tag_part()` extracts the tag. Neither
   is a reusable "is this tag rolling?" answer, and this plan's central gate needs one. Write it
   once, in `Links.php` beside the other pure image helpers, and have the suggestion code use it too
   so the two can never disagree.
4. **node is package-supplied, not base-system.** See above. This is the single assumption most
   likely to be false on someone else's server.

## The work

Four stages. **Stage 1 alone is worth shipping** — it answers "which of my images even have an
author's example, and what does it say?", which is the finding that decides whether stages 3 and 4
earn their keep.

### Stage 1 — the watch list and discovery (PHP only, no comparison)

New `include/Watch.php`, requiring `Defines.php` and `Links.php`, guarded against double-inclusion
the same way every other file is.

- `staxx_watch_rolling_tag()` — the gate. Rolling or not. Anything version-shaped, or a digest, is
  pinned and the image is dropped here, before any network thought.
- `staxx_watch_home()` — declared label first, derived GitHub address second, `''` otherwise. Pure.
- `staxx_watch_discover()` — one file listing per repository. Applies the candidate rule (exactly one
  conventionally-named compose file wins; several is a refusal *with* the count, so the copy can say
  how many; none falls through to the README). Result cached with the repository's own change stamp,
  so it is not asked again until the repository moves.
- `staxx_watch_fetch()` — the conditional fetch, quoting last time's stamp. Returns the body, or
  "unchanged", or a reason.
- `staxx_watch_readme_block()` — lift a single fenced YAML block containing a services list out of a
  README. **Refuses on two or more.** Pure and testable, and the first thing to write a test for,
  because it is the one place in this plan that reads prose.

Bodies cached under `/tmp/staxx/watch/` — reducible downloads, like the catalogue cache, and for the
same reason: flash writes are the one thing on Unraid worth being stingy with. Only the stamps, the
resolved path, the refusal reason and the dismissals go in the state file on flash.

Called from `staxx_update_check()`'s existing per-image loop, capped per pass. **The PLAN_61 trap
applies again and is the single most likely way this stage becomes dead code:** the out-of-band
passes spawn a process that requires a narrow set of files, so both spawn sites —
`staxx_update_check_start()` and `scripts/update-check`'s `run_check()` — must be widened to require
`Watch.php`. PLAN_61 lost a day to exactly this, working perfectly by hand and silently doing nothing
where it mattered.

Off switch: one config key, default on, read with the existing `staxx_cfg_bool()`.

### Stage 2 — the comparison, through the real parser

A small node script under `scripts/`, invoked through `staxx_sh()` so it inherits the "nothing may
hang" rule. It takes two file paths, requires `compose-model.js`, parses both, and emits JSON:
per service, the settings and list entries **present in one and absent in the other**, and nothing
else. Values that merely differ are dropped inside the script, so no caller can accidentally start
reporting them.

Matching services across the two files needs one rule, and the obvious one is right: match by the
**image's repository path** with tag and registry removed — the same normalisation
`staxx_links_repo_path()` already performs, so a `lscr.io` image matches the author's `ghcr.io`
example. Never by service name, which people rename freely.

Output stored per image in the state file, small: a list of findings, each identifying the service,
the setting, whether it was added or dropped, and the author's value for it — the last purely so
dismissal can notice when it changes.

### Stage 3 — the surfaces

All three reuse PLAN_61's, in this order:

1. **The count on the row** — a new inert pill state, ranked below `built`/`missing`/`moved` and
   above `unknown`, with a count and a plural sentence, following the `tagmissing` precedent.
2. **The form fields** — one function grafting findings onto the field each concerns, after
   `buildForm` returns, exactly as `applyMovedAdvice()` does. `buildForm` stays pure and knows
   nothing about any of this: that is the design point PLAN_61 established and it holds here.
   Re-run after every reparse, since those replace the field array wholesale.
3. **The note at the top of the form** — for additions with no field to paint, which is most of them.

Nothing in the editor's raw view: an addition has no line to underline, so there is nothing honest
to draw there. Say so in the note instead.

### Stage 4 — dismissal and the report

Dismissal per finding, keyed on the setting and the author's value, so a later change to that same
setting speaks up once more. One endpoint case beside `update-skip-move`.

Then one list of everything, across every stack. **Built from the state file alone** — PLAN_61's
Stage 4 first tried walking every stack and parsing every compose file, which would have blown the
self-test's fifteen-second budget on a cold cache and turned the button people press when the page
misbehaves into a blank screen. Same constraint, same answer, and the test should assert it.

## Stage 5 — a third source for the project home

**Added 2026-08-22, from Adrian asking whether the images with no project home were base images that
people build on.** They mostly are not, and the measuring is what showed it.

**What the 23 unplaceable rolling images on the box actually are:**

- **Three or four are genuinely base images** — `debian:bookworm-slim`, `node:22-alpine`, arguably
  `nginx:alpine`. Adrian is right about what they are, and **watching them is the wrong response**:
  there is no author's opinion to compare against, because the person running it wrote the behaviour.
  A *label* saying "this is a base image; what it does is your own configuration" would help someone
  reading such a stack, but that is a different feature and not this plan's.
- **The other twenty are ordinary published apps** — Excalidraw, Memos, CloudBeaver, Posterr, MQTT
  Explorer. They all have a project. This plan cannot find it only because the publisher did not put
  the address inside the image and they are not at GitHub's registry.

**The information is already on the flash drive.** Of those 23, **15 are named by a `<Project>` field
in one of the 86 Unraid templates already on the box.** No network at all.

**It cannot be taken at face value, and one case proves why.** The Actual Budget template names
`Kippenhof/docker-templates` — **the template author's own repository, not the app's**. Following that
would fetch a stranger's template collection and present whatever compose file it holds as "the
author's example", which is exactly the failure the "several candidates" and "never guess" decisions
exist to prevent. Four others name a product website (`postgresql.org`, `influxdata.com`, `redis.io`,
`iventoy.com`) with no repository to fetch from.

**The rule to build:** accept a template's address as a third source **only** when it is a GitHub
repository **and** its owner or repository name resembles the image's own namespace or name. On the
measured sample that accepts Memos (`neosmemo/memos` → `usememos/memos`, name matches), Excalidraw and
CloudBeaver (both exact), and **correctly rejects Actual Budget**. Expect roughly ten more images
watched.

**This overturns a settled decision, deliberately and with Adrian's agreement.** The original rule was
"the address the publisher declared inside the image... nothing else is guessed" — a template's
`<Project>` is a *third party's* claim about the app, not the publisher's own. The resemblance test is
what keeps it from being a guess. Note also that a URL may need normalising to the repository root: one
template names an `/issues` sub-path.

## Verification

Local, after every edit to a browser file:

```sh
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js      # 1,476 passing — must not drop
```

A new `tests/watch_compare.js`, run locally, against **real captured fixtures** from the sample
above — the one clear file, the README-only case, the four-candidate pile, and one that publishes
nothing. Weighted at the refusals:

- a pinned tag is never fetched, never compared, never mentioned;
- a differing **value** never becomes a finding, at any level of nesting;
- two YAML blocks in a README produce a refusal with a reason, not the first block;
- four candidate files produce a refusal carrying the count, not the shallowest;
- services are matched by image path, so a `lscr.io` image matches a `ghcr.io` example;
- a captured pretty-printed listing reply is parsed correctly — the mistake from item 1 above,
  pinned by a test so it cannot recur.

On the server: `php -l` over `include/*.php`, all `tests/server/` suites (18 passing today), and a
new `tests/server/watch.php` pointing its state and cache at `/tmp`. Weighted at the refusals:

- an image with no declared project and no derivable one is offered nothing, and says so;
- an image on a pinned tag never appears in the watch list at all;
- a refused fetch never clears a previously stored finding — a network failure must not read as
  "the author changed nothing";
- a dismissed finding stays dismissed, and revives when the author's value for it changes;
- a finding cannot be dismissed for an image that was never checked;
- with node absent, the feature reports that it cannot compare, and every other feature is
  unaffected.

The fetching itself cannot be made deterministic without a stub this repository does not have. Test
the discovery rules, the comparison and the state machine locally against captured fixtures; verify
the fetching by hand against the real repositories named above.

## Open, and deliberately left open

1. **An optional GitHub sign-in.** It would lift the strict allowance from 60 an hour to several
   thousand, and Settings already has the shape for it (`HUB_USER`/`HUB_TOKEN`, masked). Recommend
   **not** building it: 37 listings fit inside 60, discovery is cached until a repository changes, and
   the per-pass cap already covers the first-run burst. Revisit only if the cap proves too slow to
   fill the list.
2. **GitLab.** One image of 44 declares a GitLab project. The fetch and the listing are shaped
   differently there. Recommend GitHub only in this plan, and one clearly-labelled addition later if
   a second host ever matters.
3. **The other half.** Roughly half of all images carry no project address and are not at GitHub's
   registry — the plain ones, `alpine`, `adminer`, `apache/tika`. There is nothing to watch and no
   honest way to invent it. The copy must say so rather than looking broken.
4. ~~**A typo in the metadata key is silently ignored.**~~ **Fixed 2026-08-21.** The editor now
   warns on an `x-` key that reads as a near miss of `x-unraid`, including one differing only in
   capitals, while leaving an unrelated `x-` block alone.
