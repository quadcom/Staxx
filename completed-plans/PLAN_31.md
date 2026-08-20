# PLAN_31 — what an image can say about itself (approved 2026-08-17, finishing PLAN_24)

**Status: COMPLETE 2026-08-18. Built, deployed, committed and confirmed in the browser.** Commits
`10c9a59` and `f729c7a` on `editor-and-field-help`, pushed.

**The browser pass was done with Claude for Chrome**, driving Adrian's own browser against the box —
which works, and is worth knowing for every future plan that ends "needs a look in the browser".
Confirmed on screen: no JavaScript error anywhere; all four scripts load in order with their
cache-busting; a file opening `---` and closing `...` renders as a full editable form; all three
formerly-invisible entries in `15-long-forms-broken` show as locked rows each naming what it is
missing; **the whole import flow** — `linuxserver/duplicati` from Docker Hub arriving with its ports,
paths and variables, 99/100 in place of 1000/1000, the timezone set, three placeholder paths under
appdata, `x-unraid` with its overview and readme, and the route-aware banner listing both what needs
attention and what was filled in; and the new setting on the Settings page with its full prose.

**The empty-secret warning fired on real documentation** — duplicati's own example leaves
`SETTINGS_ENCRYPTION_KEY` blank and unmarked, and without it the container will not start.

Still not checked, both because they change Adrian's own settings: **the two Unraid themes**, and the
lookup **switched off** (already proven server-side — it answers `{"off":true}` in 0.000 s and
fetches nothing).

Nothing was written to the server during the pass and nothing was started: the editor opens with
unsaved content, so the whole flow is visible before anything reaches disk.

Counts: **1129** round-trip assertions, **61** image-import, **162** converter, schema and lint
clean, `php -l` clean over all ten `include/*.php`.

Verified live, not simulated:

- All four routes built from facts fetched from the box: jellyfin and postgres take the README
  route, `alpine` (no example, and it genuinely declares no ports or volumes) falls to bare,
  `library/nginx` falls to the ports-and-volumes route, a local image takes the README route.
- **`docker compose config -q` accepts every produced file.**
- **The null edit passes on all three README-route files** — setting every box to the value it
  already holds leaves the file byte-identical (32, 10 and 7 parts written, 0 refused).
- The endpoint over HTTP with a logged-in session: `ok=true` with 20,138 bytes of README for
  jellyfin and no stray PHP output; `../../etc/passwd` gets back nothing but appdata and timezone;
  `config=1` returns nginx's port and labels and no `Env`; a GET is refused with our own message.
- The registry fallback is four requests and takes **1.3–1.5 s**, not the 32 s worst case.
- With `IMAGE_LOOKUP="false"` the answer is `{"off":true}` in 0.000 s and nothing is fetched.

## Five things the build found that this plan did not predict

1. **Rejecting any example the form cannot read perfectly was far too strict** — my own rule, and it
   would have cost most of the benefit. A flow sequence (`capabilities: [gpu]`,
   `command: ["sleep","infinity"]`), an anchor and a block scalar all seal a *value*, and all three
   are ordinary in a published example. A sealed value shows as one locked row and still round-trips;
   only a block whose whole root cannot be read is unusable. The guard parse had to change with it —
   it now compares against what the block already sealed, not against zero, or every such example
   would always report a discarded correction.
2. **`wantConfig` looped on an image that declares nothing.** `alpine` really has no ports and no
   volumes, and "asked and told nothing" was indistinguishable from "never asked", so the page would
   have paid for the four-request fallback to hear the same empty answer twice. The server sends a
   field absent when it did not look and present-but-empty when it did; the builder now reads that
   difference.
3. **Two dead links were being written into the file.** A private-registry image was handed a Docker
   Hub address that answers nothing, because the repository-path helper drops the registry host; and
   an official image written the long way got `/r/library/nginx`, which Hub does not serve — it is
   `/_/nginx`. Both now resolve or are omitted.
4. **The parser sealed a run of markers inconsistently.** The whole-file scan accepts any number of
   `---`/`...` before the first real line, but the root-kind check skipped only one, so a file
   opening with two markers was called fine in one place and locked in the other.
5. **linuxserver publishes no description on Docker Hub at all** — their blurb exists only in the
   image's own labels, which the cheap route does not fetch. So a jellyfin import gets a barer
   metadata block than a postgres one. Left alone deliberately: four extra requests for one line of
   prose is the wrong trade, and the icon still comes from the image name as it always has.

6. **The placeholder-password check was matching whole values and missed the obvious case.**
   wordpress's own example says `examplepass`, not `example`, so it sailed through the one check that
   exists to stop exactly that. Now matched as a substring — a real password containing `changeme` is
   not a case worth protecting, and the cost of a false hit is one sentence telling somebody to check
   a password, never an edit. Found only because the test box is a **production server** and picking
   a safe app to try meant reading real documentation rather than fixtures. The same pass added a
   warning for a secret left empty and *not* marked `#optional` — duplicati's
   `SETTINGS_ENCRYPTION_KEY=`, without which it will not start, where an empty line looks finished.

## The test box is Adrian's production server

Recorded because it changes what may be done there. Roughly 65 containers are running on it,
including `jellyfin` under that exact container name — so **importing jellyfin is the one thing not
to try**, since its own example sets `container_name: jellyfin` and starting it would collide.

Nothing in this work ever pulled or started anything: every check was `docker compose config -q`,
which only reads text. Confirmed — no container exists for any fixture, and Docker's own project
list holds only Adrian's four.

Two fixtures written earlier today wanted ports that were a bad idea on a live box: `16-doc-marker`
asked for **8081, which Reubah is using**, and `14-long-forms` asked for 8080, which a third fixture
also wants. Both moved into the **151xx** range the older fixtures use, and re-validated. Every
fixture's host port is now checked free against both TCP and UDP listeners.

**Safe to import on that box** — absent from it entirely, no container name taken, every port in
their own examples free on TCP and UDP:

| Image | Why it is the right test | Ports it asks for |
|---|---|---|
| `linuxserver/duplicati` | one service, and it exercises every correction at once: PUID/PGID, timezone, three `/path/to/` paths, `#optional` comments kept, and an empty required secret | 8200 |
| `wordpress` | the multi-service case, with named volumes and a placeholder password in each of its two services | 8080 |

## Still to check in the browser

The eight checks in "Step 5 — on the box" below, of which only these need a person: pressing Add and
seeing the footer say it is reading, the warning banner's wording on both routes, the new setting in
Settings → Utilities, a second click while a lookup is running doing nothing, Escape during a
lookup, and both Unraid themes. There is also a fixture stack **16-doc-marker** on the box for step
1 — a file opening with three dashes and closing with three dots, which Docker accepts and which
used to render as one uneditable block.

---

## Context

`PLAN_23` added two new places to look for something to add: Docker Hub, and images already pulled
on this server. Both produce the same four lines — a name, the image, `restart: unless-stopped` and
a comment apologising for the rest (`stacks.js:5572-5586`, `caSkeleton()`). A Community Applications
app, beside them, arrives with its ports, paths, variables and a description against every setting.

That gap is not a rendering problem: a CA template *is* a description of how to run something, and
an image reference is not. This closes it by reading what the image and its own documentation
already say. `PLAN_24.md` in the repo is the survey behind this; its measurements were re-taken live
today and **two of them were wrong in a way that would have sunk the flagship case** — see below.

Decisions taken with Adrian, 2026-08-17:

- **Always look, with a switch.** Pressing Add fetches and builds; a new `IMAGE_LOOKUP` setting
  turns the looking off for anyone who wants imports offline and instant.
- **Fix what is certainly wrong, flag the rest.** Unraid's own user and group numbers, the server's
  timezone, placeholder paths pointed at appdata — and every change, plus every remaining
  placeholder, named in a warning and in the file's own comments. Never a password.
- **A local image is looked up too.** The source clicked should not change what you get.
- **The three-dashes fault is fixed properly**, not worked around — see step 1.

### What re-measuring changed

1. **A compose file whose first line is `---` is sealed whole.** `parse()` steps over a leading
   `---` in its multi-document scan (`compose-model.js:479-482`) and then trips over it three lines
   later, where `significant()` hands back that line and `ctx.cls[j].kind !== 'key'` seals from there
   (`:491-497`). Every linuxserver example file begins that way, so the README route cannot work at
   all until this is fixed — and any file a person hand-writes that way is unusable in the form
   today. A trailing `...` is likewise called `multi-doc` when it closes a single document.
2. **The acceptance test in the survey rejects `linuxserver/jellyfin`.** Its README block says
   `image: lscr.io/linuxserver/jellyfin:latest`. Comparing that string to the reference being
   imported fails; comparing canonical repository paths succeeds. `repositoryPath()`
   (`ca-convert.js:235-241`) already does most of it, and `postgres`'s block says a bare
   `image: postgres`, so the implicit `library/` has to be filled in too.

Both READMEs still hold exactly one fenced `yaml` block, verified live today. The registry chain
still works and still needs `curl -L` for the config blob. `Env` is still 13 build-time values
(`PATH`, `S6_VERBOSITY`, …) and is still never written.

---

## Step 1 — a leading `---` stops sealing the file

`compose-model.js`, two narrow changes in `parse()`:

- Before the root-kind check at `:491-499`, advance past a lone `---` line the same way a leading
  comment is already skipped. `doc.lines` keeps it untouched and every offset is line-based, so
  `serialise()` returns it verbatim — the round-trip is safe by construction.
- In the multi-document scan at `:474-485`, a `---` or `...` only means a second document when real
  content **follows** it. A closing `...` at the end of a file is one document.

A genuine two-document file still seals as `multi-doc`, and `lint()`'s treatment of that
(`:4356-4365`) is unchanged.

New cases in `tests/yaml_roundtrip.js`: a file starting `---` renders as a form and writes back byte
for byte; the same with a comment between the marker and `services:`; a trailing `...` likewise; two
real documents still seal. Continue the file's lettering.

**Deploy and check in the browser before starting step 2** — this is the only step that touches
files people already have.

## Step 2 — the server side of looking

All in `include/Defines.php`, modelled on `staxx_hub_search()` (`:340-379`) and holding its
contract: **an empty result for every failure**, no exception, no logging.

- `staxx_hub_json(string $url, array $headers = []): array` — the three lines currently duplicated
  at `:307-310` and `:358-361` (build URL → `staxx_sh('curl -fsSL --max-time 8 …', 12)` →
  `json_decode` and shape-check), factored out and used by both existing callers as well. The
  registry chain needs a bearer header, hence the second argument.
- `staxx_hub_repo(string $repo): array` — one request to
  `https://hub.docker.com/v2/repositories/<ns>/<name>/`, returning `{readme, description}` from
  `full_description` and `description`. Reuse the registry-host normalisation already at `:288-297`
  (`lscr.io`/`ghcr.io` → `linuxserver/…`, bare name → `library/…`). Cap the README at 256 KB.
- `staxx_registry_config(string $repo): array` — the three-request chain, and **only ever the
  fallback**, so the common path never pays for it: token from `auth.docker.io`, the `amd64`/`linux`
  entry out of the manifest index, then the config blob **with `-L`**, which is what the survey found
  is easy to miss. Returns `{ports, volumes, labels}`. `--max-time 6` per request so three of them
  fit inside the browser's wait.
- `staxx_local_image_config(string $ref): array` — the same three fields from
  `docker image inspect --format '{{json .Config}}'`, no network at all. First use of `--format json`
  outside `staxx_compose_state()`; the only other `docker inspect` here is template-formatted
  (`Stacks.php:1213`).
- `staxx_server_timezone(): string` — `readlink -f /etc/localtime` with the zoneinfo prefix
  stripped, `''` when it cannot be read.

One new case in `include/action.php`, immediately after `hub-search` (`:462-463`), in the
`networks`/`images`/`tags`/`hub-search` cluster:

```php
case 'image-facts':
  staxx_reply(['ok' => true, 'facts' => staxx_image_facts(
    (string)($_POST['image'] ?? ''), (string)($_POST['source'] ?? ''))]);
```

`staxx_image_facts()` is the small orchestrator: **off** when `IMAGE_LOOKUP` is `false` (returned
as `{off:true}`, nothing fetched); otherwise the local config when the source is `local`, the Hub
README always, and the registry chain only when asked for. Validation stays in the callee, as every
other action here does.

The setting: `IMAGE_LOOKUP="true"` in `default.cfg` — **semicolon comments, never `#`**, for the
reason written at the top of that file — and a `<select>` in `staxx.settings.page` copying
the `ICON_FETCH` block at `:47-59`, prose included: what is sent out, when, and what turning it off
costs.

## Step 3 — the builder, as its own testable module

New file `javascript/image-import.js`, dual-target export exactly like `ca-convert.js` (`:675-682`),
because the logic has to be runnable in `node` — `stacks.js` is browser-only and gets nothing but
`node --check`. Add `repositoryPath` to `ca-convert.js`'s exported API and use it here rather than
copying it; `normaliseName` is already exported and gives the stack and service names.

`build(image, source, facts, opts)` → `{name, yaml, warnings, notes, route}`.

**The README route, tried first:**

1. Take the first fenced block tagged `yaml` from the README.
2. Strip a leading `---` and a trailing `...` — cosmetics of somebody else's file, and this is a new
   file. (Step 1 means a file that keeps them still works; this just does not carry them in.)
3. `parse()` it. Accept only if it parses clean and **at least one service's image has the same
   canonical repository path** as the reference being imported: digest and tag off, a first segment
   containing `.` or `:` dropped, `library/` filled in when there is no slash, lowercased. A block
   that never mentions the image is somebody's unrelated example — reject and fall through.
4. **Keep every service it declares.** `postgres` brings `adminer` with it, and a database plus its
   admin tool *is* a stack: the form iterates services already, the job runner has whole-stack and
   single-service verbs, and this is the first import route that can ever produce one. Deleting a
   service you did not want is what the editor is for.
5. Apply the corrections below, then **parse again as a guard**: if the second parse seals anything
   the first did not, throw the corrections away, keep the original lines, and say so in a note. Two
   parses of a twenty-line block cost nothing and this is what stops a narrow regex quietly
   producing a file the form cannot read.

**The corrections, and what each one says for itself:**

| Found | Written | Said |
|---|---|---|
| `PUID=1000` / `PUID: 1000`, same for `PGID` | `99` / `100` | named, with why: a container running as 1000 cannot write to your shares |
| `TZ=Etc/UTC` | this server's zone | named; left alone if the zone cannot be read |
| a host path beginning `/path/to/` | that prefix swapped for the appdata root | named, **and** told plainly that a media library belongs on a share, not appdata — the swap makes the path real, not right |
| `PASS`/`SECRET`/`TOKEN`/`KEY` set to `example`, `changeme`, `password`, `secret` | **nothing** | named as a warning: this must be changed before starting |

Nothing else is touched. `#optional` comments, commented-out alternatives, `container_name:`,
`shm_size:` — all carried through as written, which is what the model does anyway.

**The fallback, when no block matches:** `ports:` from `ExposedPorts` and `volumes:` from `Volumes`,
pointed at appdata, and **nothing else**. Never `Env` — those are the image's build-time internals
(`PATH`, `S6_VERBOSITY`), writing them pins a snapshot that breaks the day the image changes them,
and the variables that genuinely are required are not in there at all (`POSTGRES_PASSWORD` is not).

**And when there is neither:** today's four lines, unchanged.

**`x-unraid`, either way** — top-level, mirroring `ca-convert.js:616-631`: `version: 1`, plus
`overview` from Hub's short `description` or the OCI `…image.description`, `readme` from
`…image.documentation` or the image's Hub page, `project` from `…image.source`/`.url`, `author` from
`…image.authors`. **No `icon`** — the schema says an absent icon is matched from the image name,
which is already what happens and is better than a guess.

**The header comment**, extending what `PLAN_23` writes: which of the three routes produced the file,
that it is an ordinary compose file that runs with `x-unraid` deleted, then the existing two
headings — `# Could not be translated automatically:` for warnings and
`# Filled in for you — check these before starting:` for notes, through
`warningCommentLines()`'s wrapping (`ca-convert.js:179-190`). A clean import gets no block, exactly
as a clean CA conversion gets none.

`tests/image_import.js`, new, same shape as `ca_convert.js` — no framework, no network, one line per
case. Fixtures are the two README blocks quoted verbatim from today's live measurement plus a
no-block README and a config-only case. The cases that matter: `lscr.io/…` matches `linuxserver/…`;
bare `postgres` matches `library/postgres`; an unrelated block is rejected; both of postgres's
services survive; `POSTGRES_PASSWORD` keeps its value **and** raises a warning; `Env` never appears;
the guard parse restores the original when a correction breaks the block; every produced file parses
clean and round-trips byte for byte.

## Step 4 — wiring it to the button

`stacks.js`, around `caAddImage()` (`:5590-5593`). It becomes asynchronous, which is the whole of the
visible change:

- `call('image-facts', {image, source}, 30000)` — 30 s because the fallback route is three chained
  requests at up to 6 s each inside their own wrappers; the common route is one request and returns
  in about a second.
- While it runs, a short line in the footer (`#staxx-ca-msg`) saying the documentation is being
  read, and the list not accepting another click. Stamp the request and ignore a reply whose stamp
  has moved on — `caHubStamp` (`:4834`) is the existing precedent.
- Then `openEditor()` as now, and warnings and notes through `showError()` in exactly the three
  wordings `caImport()` already uses (`:5544-5557`). No new banner mechanism.
- Any failure at all — no reply, lookup switched off, nothing usable — falls silently back to
  today's `caSkeleton()`. **Never a dead end**: the bare file was always an acceptable answer and
  still is.

`include/StacksPage.php` gains the `<script>` tag for the new module, **with `filemtime()`** like
every other asset there, ordered after `ca-convert.js` since it uses two of its helpers.

## Step 5 — on the box

A fixture is not needed; the real sources are the test. Deploy, Ctrl-F5, then:

1. `linuxserver/jellyfin` from Docker Hub → the file matches its README block, with 99/100 in place
   of 1000/1000, the timezone set, the four `/path/to/…` paths under appdata, and a comment block
   naming every one of those changes and saying the media paths belong on a share.
2. `postgres` → **both** `db` and `adminer` arrive, `POSTGRES_PASSWORD: example` is still `example`,
   and it is called out as needing changing before start.
3. Something with no usable block → ports and volumes only, no `Env` anywhere.
4. A local image of one of the above → the same file as from Hub.
5. `IMAGE_LOOKUP` off → the four lines, instantly, and nothing leaves the server (check with
   `tcpdump` or simply that it returns immediately).
6. Save all of the above: `docker compose config -q` accepts each, which is the real proof.
7. A hand-written file starting `---` renders as a form and saves back unchanged (step 1).
8. Both Unraid themes; Escape and Cancel while a lookup is in flight.

Server-side, before the browser: `php -l` over `include/*.php`, then a throwaway script calling
`staxx_hub_repo('linuxserver/jellyfin')`, `staxx_registry_config('library/nginx')`,
`staxx_local_image_config()` on something present, and each of them on `'../../etc'` and `''` —
the last two must return empty and touch nothing.

Locally, all five, plus the two new suites:

```sh
node tests/yaml_roundtrip.js
node tests/image_import.js
node tests/ca_convert.js
node tests/js_undeclared.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/image-import.js
python tests/validate_schema.py
```

## Files

| File | Change |
|---|---|
| `javascript/compose-model.js` | step 1: a leading `---`, a closing `...` |
| `include/Defines.php` | the factored JSON fetch, Hub README, registry chain, local inspect, server timezone |
| `include/action.php` | `image-facts` |
| `default.cfg`, `staxx.settings.page` | `IMAGE_LOOKUP` and its prose |
| `javascript/image-import.js` | new — the whole builder |
| `javascript/ca-convert.js` | export `repositoryPath` |
| `javascript/stacks.js` | `caAddImage` waits, reports, falls back |
| `include/StacksPage.php` | the new script tag |
| `tests/yaml_roundtrip.js`, `tests/image_import.js` | step 1's cases; the builder's suite |

## Left out

- **Pulling an image to inspect it.** The registry route needs no pull.
- **More than the first fenced block**, or merging several.
- **Caching a lookup.** Adding the same image twice fetches twice; it is one request.
- **Importing from a git repository.** Hub and local images have real APIs; a repository does not.
- **Anything written from `Env`.**
- **Multi-document compose files.** Step 1 makes a marker harmless; two real documents still seal,
  and that verdict is correct.
