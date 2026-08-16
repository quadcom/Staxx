# Importing from Unraid CA — what was built, what it found, what needs your eye

**2026-08-16. `PLAN_17.md` is complete**, deployed to the test box and verified end to end. The plan
now sits in `completed-plans/`.

This is the results write-up. The first half is what happened; the second half is **the seven things
that need a decision from you**, which is the part worth reading if you only read one.

---

## In one paragraph

The Stacks page has a new **Apps** button. It searches the Unraid CA catalogue — 3,730 containers —
and turns the one you pick into an ordinary compose file, opened in the editor as a new stack. CA's
description for each setting becomes the comment beside that setting, so the imported app renders as
a **proper form with real help text**, not a wall of YAML. That was the point of the exercise, and it
works.

---

## Verified on the test box

Not "should work" — actually run at 192.168.200.88:

| Check | Result |
|---|---|
| All 11 PHP files, `php -l` | clean |
| Catalogue build, cold | **1.3 s** — 3,730 apps, 81 categories |
| Catalogue refresh, nothing changed | **0.135 s, 37 bytes** |
| Search ranking | `jellyfin` → the real Jellyfin first |
| Bad app ordinal (`999999`, `-1`) | `null` — no warning, no file read |
| Byte offsets leaking to the browser | none |
| Import → editor → form | ports, volumes and variables all render with their descriptions |
| Save | file written, **`docker compose config -q` accepts it** |
| Untranslatable flag (`--gpus=all`) | reported above the form, not dropped |
| `node tests/ca_convert.js` | 139 passed — including **all 4,116 feed apps** round-tripping byte for byte |
| `node tests/yaml_roundtrip.js` | 966 passed (was 953 — the new example is picked up free) |
| `node tests/js_undeclared.js` | clean |
| `python tests/validate_schema.py` | clean |

The test stack I created to prove Save works has been deleted. Your ten test stacks are untouched.

---

## Six things the plan got wrong, found by measuring

The plan was written from reasoning about the feed. I downloaded it and checked. Six assumptions did
not survive, and the build follows the measurements.

**1. The plugin filter caught nothing.** The plan proposed dropping entries "carrying `PluginURL`
and no `Repository`". **Zero entries match that** — every plugin has a `Repository`, holding the URL
of its `.plg` file. So all 303 Unraid plugins sailed through and appeared as installable apps; I
caught "45D Drive Map" in the results list during the browser test. Importing one would have written
`image: https://….plg`. The real filter is "the repository is a URL", which catches exactly 303.
Count went 4,033 → 3,730.

**2. A build takes 1.3 seconds, not "about a minute".** The plan's user-facing copy promised a
minute. Removed — no duration is promised now, because it depends on the connection.

**3. There is no need to re-download.** CA publishes a 37-byte file saying when the catalogue last
changed. A daily refresh now costs 37 bytes instead of 24 MB. This came directly out of your
question about whether we need the download at all.

**4. Ranking a browse by downloads is wrong.** `downloads` is the **Docker Hub pull count of the
image**, not of the app. `nginx:alpine` carries hundreds of millions of pulls, so opening the dialog
showed a wall of nginx and postgres wrappers. Browsing is now A–Z; searching still tie-breaks on
downloads, which is correct there (of the several apps called "jellyfin", the one everyone runs comes
first).

**5. `ExtraParams` needed a much bigger table.** The plan listed ~18 flags. The feed actually uses
80-odd. I added the ones with clean equivalents (`--gpus` and the `--health-*` family deliberately
excluded — see decision 3 below) and everything else warns.

**6. Custom networks were unplanned.** 250-odd apps use `br0` or a named network. These now emit a
proper `external: true` declaration plus a warning, rather than silently losing the network.

---

## Defects I found reviewing the agents' code

Listed because they are the kind that stay hidden until they bite:

1. **A failed refresh beside a good cache reported failure** — would have restarted a doomed 24 MB
   download on every keystroke while a working catalogue sat on disk.
2. **`substr` on a UTF-8 overview** could split a character; `json_encode` then returns `false` for
   the **whole index**, giving an empty cache with nothing to explain it. Now `mb_substr`.
3. **The result count reported the whole catalogue** — a search matching three apps said "4033 apps
   found".
4. **No client handling for a failed download** — the page would have polled every 3 s forever.
5. **A failed build left the 24 MB download in `/tmp`**, which is RAM on Unraid.
6. **A stale cache showed a progress message** instead of the 4,000 good apps already on disk. Now
   served immediately and refreshed behind you.
7. An HTML comment I wrote landed **inside an `<input>` tag's attribute list**. Caught on re-read.

The converter agent's own bulk test — converting all 4,116 apps — found two more: a value beginning
`&` was read by YAML as an anchor and sealed the file, and a template whose setting name literally
ended in `:` produced malformed YAML. Both fixed. That test is why they were found, and it is worth
keeping.

---

## Things that need your decision

### 1. Nothing is downloaded until you press Apps — is that enough?

There is no setting to switch the CA fetch off, on the reasoning that it is already opt-in by action.
The alternative is an `ICON_FETCH`-style toggle in settings. **My view: leave it.** A switch for
something that only happens when you press a button is configurability nobody asked for. Say the word
if you disagree.

### 2. Result icons load from the internet, in your browser

Each row shows the app's icon from wherever CA points — `raw.githubusercontent.com` and similar. The
*server* never fetches them; your *browser* does, and only while the dialog is open. That is what CA's
own Apps tab does too. It does mean opening the dialog makes ~60 requests to third-party hosts.
Options: leave it, drop icons from the list, or route them through the existing icon cache
(`Icons.php` already does this for the table). **My view: leave it for now**, but it is a real
privacy surface and worth knowing about.

### 3. `--gpus` and `--health-*` are deliberately not translated — RESOLVED 2026-08-16

**Your question: can the editor mark these invalid, and can we suggest the equivalent?**

The editor cannot mark them, and should not. `lint()` in `compose-model.js` is line-based — it flags
sealed nodes, duplicate keys and unknown compose keys **in the file**. An untranslated flag is not in
the file, so there is nothing to mark; and writing a deliberately invalid line to make it light up
would produce a file `docker compose config` rejects, which breaks the project's first rule.

**Suggesting the equivalent: yes, and now done.** Warnings name the compose key for 18 flags —
`--gpus` → `deploy.resources.reservations.devices, with capabilities: [gpu]`, the `--health-*` family
→ the `healthcheck:` block, `--memory` → `mem_limit`, `--ulimit` → `ulimits`, and so on. A flag with
no hint keeps the plain wording rather than getting an invented one.

**And they now persist.** The warnings were only ever a banner that vanished when the editor closed —
which is exactly when they become useful. They are now also written into the file as a comment block
under `# Could not be translated automatically:`, so they travel with the file and sit where the
editing happens. An app that converts cleanly gets no block at all.

Still not translated, and still deliberately: a subtly wrong healthcheck or resource limit produces a
file that looks right and behaves differently.

### 4. Odd category names — RESOLVED 2026-08-16, and you were right

**Your question: is `;` how CA packs multiple categories into one string?** Yes. Confirmed against
the feed — `"Gaming-;Productivity-;Network-Web"` is three categories, and the raw `Category` field
settles it (`"GameServers: MediaApp:Other"`). Sections also run together **without** a semicolon:
`AI-Tools-Utilities` is `AI` plus `Tools:Utilities`, not one category.

Chasing it found something worse than the cosmetic mess. `CategoryList` is an **array**, and **2,528
apps have more than one entry in it** — the indexer took `[0]` and discarded the rest. So filtering by
`MediaServer:Video` silently missed every app whose first listed category happened to be
`MediaApp:Video`. Category filtering was quietly wrong for two-thirds of the catalogue.

Both fixed. Every category an app claims is now indexed, and the normaliser understands `;`, trailing
`-` (meaning "no subcategory"), and run-together sections. The taxonomy went from 81 messy entries to
**71 clean ones**, and `binhex-emby` is now findable under all six of its categories rather than one.
Genuinely made-up categories (`MediaApplication-Video`, `System-Monitoring`) still pass through
untouched — reshaping those would be a guess.

### 5. Bad data passes through verbatim

`binhex-emby`'s project link in the feed is `https://https://emby.media/` — a double `https://`. It is
in the imported file as-is. This is correct behaviour under "never invent", but you will see the
occasional oddity and should know it came from CA, not from us.

### 6. `author` is set from CA's `Repo` string

That field holds things like `"Binhex's Repository"`, so imported files say
`author: Binhex's Repository`. It reads a little oddly for an "author" field. The alternative is to
leave `author` out entirely. Minor either way.

### 7. Committing — DONE 2026-08-16

Two commits on `editor-and-field-help`, pushed to `origin`. `main` is untouched.

The split is not perfectly clean, and here is why: `stacks.js` carries **both** the line-ending work
and the CA browser, so it cannot go in one commit without dragging the other along. Everything that
could be separated was — `yaml_roundtrip.js`, `compose-model.js`, `Stacks.php` and
`tests/server/files.php` turned out to be purely line-endings — so the first commit is clean and the
second carries `stacks.js` for both. Interactive staging (`git add -p`) is not available in this
environment, which is the only reason it is not split further.

---

---

## Is every one of them really a Docker container? — checked properly, 2026-08-16

The first answer to this was an **exclusion** filter — not a plugin, not a URL — which is not the same
as confirming what is left. Checking properly found a positive marker and three real problems.

### There is a positive marker, and it agrees exactly

**`Registry`.** Exactly 3,730 feed entries carry it — precisely the set the exclusion filter kept,
with **zero** plugins, **zero** language packs, and **zero** kept entries missing it. Two completely
independent tests landing on the same 3,730 is as close to proof as this data offers.

Worth knowing where the little Docker logo in CA actually comes from, since it is not what it looks
like: `ca_fa-docker` in CA's own skin (`skins/Narrow/skin.php:856`) is the **Registry link** in the
support menu — "open this image's registry page" — shown `if ($template['Registry'])`. It is not a
type badge. It only *appears* to mark containers because only containers have a `Registry`.

CA's own type test is a negative three-way, not a positive one: `Language` → language pack, else
`Plugin` → plugin, else container. That yields 3,792 — our 3,730 plus **62 parse-error stubs**,
entries CA itself failed to read. They carry nothing but a path and an error
(`"errors":["char '&' is not expected."]`) — no name, no repository, no registry. Correctly excluded.

### Three problems it turned up, now fixed

| Problem | Count | What was done |
|---|---|---|
| **Blacklisted** — CA flags these broken or withdrawn and drops them from its own listing | 83 | excluded |
| **Hidden** (`hideFromCA` / `hideFromWeb`) | 10 | excluded |
| **Deprecated** — CA still shows these, with a notice | 153 | **kept and marked** with a `deprecated` badge on the row |
| **Uppercase repository path** — `docker pull` refuses outright | 6 | warned, not rewritten |

Deprecated apps are kept deliberately: CA shows them, and people legitimately still run some. Hiding
them would be making a decision that is not ours; showing them unmarked would be worse.

The uppercase ones are **not** auto-lowercased. `ghcr.io/Suvir0/ripuz` → `ghcr.io/suvir0/ripuz` is a
different reference that may not exist, and silently rewriting an image name to a registry path we
have not checked is the kind of guess this project refuses to make. The warning names the image and
says Docker will refuse it as written.

**Count now: 3,639** (it drifts by a couple as CA republishes; it was 3,637 when measured).

---

## The editor does not flag an invalid value — `PLAN_19.md`

Asked whether the editor's gutter would mark a bad value in an imported file, and whether it could
suggest the right one. It will not, and the reason is worth recording.

`lint()` validates **keys**, not values (`compose-model.js:4005`). And the form's dropdown
deliberately keeps an unrecognised value rather than correcting it — a `<select>` can only hold its
own options, so dropping it would silently rewrite the file — but it renders identically to a valid
one. So `restart: alwyas` is invisible today, whatever wrote it.

**This is not a CA problem.** All 4,116 conversions were checked: every `restart` produced is valid
(`unless-stopped` ×4112, `no` ×3, `on-failure:5` ×1) and every `network_mode` is `host` or `none`.
Nothing the catalogue can produce trips it. The gap belongs to the editor, and the file that most
needs it is one somebody typed by hand.

Written up as `PLAN_19.md` rather than bolted on here, because the hard part is not the check — it is
not becoming stricter than Docker. `on-failure:5`, `network_mode: service:db`, `container:x` and a
real network like `br0` are all valid and all absent from the offered lists, and `br0` only becomes
known *after* the server's networks load. One false warning on a working file costs more trust than
ten missed typos.

---

## Where the code lives

| File | What it is |
|---|---|
| `include/CA.php` | reads the cache — search, one app, categories, status. Never touches the network |
| `scripts/ca-index.php` | the only thing that downloads. Brace-depth splitter, so peak memory is one app, not 400 MB (the box's PHP limit is 256 MB) |
| `javascript/ca-convert.js` | the converter. Pure, no DOM, testable under node |
| `tests/ca_convert.js` | 139 cases, including the whole-feed bulk run |
| `examples/emby/compose.yaml` | a real converted app, checked in — the round-trip and schema tests pick it up free |
| `include/action.php` | two new cases, `ca-search` and `ca-app` |
| `include/StacksPage.php` | the Apps button and the dialog |
| `javascript/stacks.js` | the Apps browser section |
| `sheets/stack.manager.css` | `stackman-ca-*` rules |

## One loose end

Twice during browser testing the first click on **Apps** did nothing and the second worked. I could
not reproduce it once the page had settled: a programmatic click always works, a real click on a
settled page always works, and there are no console errors. Both failures happened within about two
seconds of a page load, before the 420 KB `stacks.js` had finished running — so I believe it is an
artefact of automation clicking faster than a person could. **Flagging it rather than declaring it
fixed**, because I did not prove the cause. If you ever see the button ignore a first click, that is
this, and it needs a proper look.
