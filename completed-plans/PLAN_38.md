# PLAN_38 — phase 1 of the importer: the list

**Status: COMPLETE 2026-08-18.** Built, deployed, and proved by running the converter over all 85 real templates in the browser. 24 server-side checks pass, no console error, nothing written.

### What it found, which is why it was built first

**1,154 settings were being silently thrown away** — across 73 of the 85 templates, one of them
losing all 74 of its own. Ports, paths and variables all gone, with the import still looking like it
worked.

The cause is one line. A setting is written `<Config Name="Web UI" Target="5006" Type="Port">5006</Config>`
and everything that matters is in the attributes. Encode that element on its own and they survive;
encode the whole template, which is what the reader did, and each setting collapses to its text alone.
The converter then sees an untyped setting and skips it, in a comment nobody reads. Every row is now
rebuilt from the XML by hand, and all **628** settings come through — exactly the figure PLAN_35
measured independently.

The empty-element bug the plan predicted was real too, and is fixed both server-side and in the
converter itself, which had the same truthiness trap and its own tests to keep it honest.

Sub-plan of PLAN_35. Phase 0 (PLAN_37) has shipped.

## What it is

A panel listing everything on the server that could be brought into StaXX — every Unraid template,
every Compose Manager project, and any container that belongs to neither — saying what each one is,
whether it is running, whether StaXX already has it, and anything odd about it. **It writes nothing
and touches no container.**

It also **previews** what StaXX would write for any entry, read-only. That is the real point of
building it first: it runs the converter against all 85 real templates without saving a byte, which
is the only honest way to find out what the importer would actually produce.

## What the server already told us

Measured 2026-08-18, and the plan is shaped around it rather than around guesses.

| | |
|---|---|
| Templates | **85**, every one parses, all sharing the same 22 fields |
| Templates with a container | **70** — so 15 are configured but not installed |
| Compose Manager projects | **7** |
| Containers belonging to neither | **1** (`music-assistant`, exited) |

Four awkward shapes that the list has to name rather than trip over:

- **`Wazuh`'s compose file is ten bytes long** — the single word `services:` and nothing else. It is
  a project that was created and never filled in. It must not import as an empty stack silently.
- **`PenPot_Complete` has no compose file on the flash drive at all**, only a 33-byte `indirect` file
  pointing at `/mnt/user/appdata/Penpot_Complete` — note the different capitalisation from its own
  folder, so the path must be used as written and not rebuilt from the folder name.
- **Two projects are named with spaces** — `Tesla Tools` and `Unifi Voucher Server` — which the stack
  naming rules reject. The list shows both the real name and the folder name StaXX would use.
- **Six of the seven have an override file**, several of them substantial. StaXX passes compose a
  single file and never merges an override, so importing one and starting it would silently run
  without whatever the override sets. The list must say so on those six.

## What gets built

### 1. `include/Import.php` — reading the three sources

One function per source plus one that returns them together. Mirrors what the catalogue reader
already does. No external commands of its own: "is it running" comes from the container index the
page already builds.

Each entry carries: which source it came from, its identity there, the name to show, whether a
container exists and whether it is up, the folder name StaXX would use, whether a stack of that name
already exists, and a list of **plain-English notes** about anything awkward.

For a Compose Manager project it also carries **how its compose file was found** — `indirect` file
first, then the container's own label, then the flash folder — because that ordering is the thing
most likely to be wrong and it should be visible rather than inferred.

### 2. Empty elements normalised **in PHP, not the browser**

PLAN_35 proposed a new browser file for this. Do it in PHP instead, in the one place the decoded
template is produced.

An optional element written `<PostArgs/>` decodes to an empty *list*. PHP reads that as nothing, but
JavaScript reads an empty list as something, and the converter runs in the browser — so it writes
`command: ""` into 83 of the 85 templates, which overrides the image's own start-up command. Proven
against real templates, not inferred.

Coercing them to empty strings as the file is read fixes it in one place that cannot be bypassed, and
avoids adding a browser file — which PLAN_35 itself warns silently never loads if it is left out of
the page's asset list. Also here: split `Category`, which a template supplies as one space-packed
string where the catalogue supplies a list.

### 3. `import-list` — one new endpoint action

POST only, on the existing switch, like every other action. Returns the list. Nothing else.

### 4. The panel

An **Import** button in the Stacks page toolbar, beside Apps, opening a panel in the same style.
Three groups, each with a count. Every row says what it is, where it came from, whether it is
running, and its notes. A row whose stack already exists says so and is not offered.

**No tick boxes and no import button in this phase.** A control that does nothing is worse than no
control; selecting and writing is phase 2.

Each row can be expanded to show **the compose file StaXX would write**, generated in the browser by
the existing converter, read-only. For a Compose Manager project it shows the file that would be
copied instead, since that is a copy rather than a conversion.

Icons are **not** fetched for this list — 85 rows meets a budget built for a dozen. Names only.

### 5. Self-test

Three counts, and a named reason for anything that could not be read. Pure PHP, no external commands,
so the list stays debuggable from the server with no browser — which matters, because there is none
on this machine.

## Tests

- **Server-side** (`tests/server/import.php`): the three readers against the real data on the box —
  85 templates found and parsed, the `.bak` excluded, `PenPot_Complete` resolving through `indirect`
  to the path as written, `Wazuh` flagged as effectively empty, the two spaced names reported with
  both forms, the six overrides flagged, and every entry's would-be folder name passing the stack
  naming rules. Read-only throughout; it creates nothing and must never call Docker.
- **Browser-side** (`tests/ca_convert.js`): extend it with the PHP-shaped template — every optional
  element as an empty list — asserting no `command:`, no `webui:`, no empty `icon:` or `category:`
  appears. This is the assertion that would have caught the bug, so it belongs in the suite that owns
  the converter.

## What this phase deliberately does not do

- **Writes nothing.** No stack folders, no lock files, no changes to any source.
- **No selection, no import button.** Phase 2.
- **No collision *resolution*** — it reports that a name is taken, and stops there.
- **No `docker inspect` diffing.** Cut from the plan entirely; see PLAN_35.

## Files

| File | Change |
|---|---|
| `include/Import.php` | new — the three readers, normalisation, the notes |
| `include/action.php` | the `import-list` action |
| `include/Stacks.php` | the self-test counts |
| `include/StacksPage.php` | the Import button in the toolbar |
| `javascript/stacks.js` | the panel, and the preview through the existing converter |
| `sheets/staxx.css` | the panel |
| `tests/server/import.php` | new |
| `tests/ca_convert.js` | the empty-element case |
