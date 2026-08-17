# PLAN_20 — Ask compose while you type, and mark the two values it never checks

**Status: COMPLETE, 2026-08-16.** Built, deployed and checked in the browser on the test box.
978 round-trip assertions, 154 converter assertions, schema and lint checks all clean.

Three things turned up during the build that the plan above did not predict:

- **`%YAML` and multi-doc.** Measurement killed half of gap B — `%YAML 1.2` genuinely *is*
  rejected by compose, so the seal was right; only `%YAML 1.1` passes. Multi-doc went the
  other way and is worse than predicted: compose accepts it and reads services from **both**
  documents, so the old message was simply false. Both reworded.
- **A whole class of PHP 8.4 deprecation.** `array &$x = null` is now deprecated, and the
  notice lands *inside* action.php's JSON reply. One was introduced here and three were
  already present (`stackman_rename_stack`, `stackman_folders_save`, `stackman_folder_taken`).
  All four fixed.
- **Compose names a path, not a line, for schema errors.** `services.web.pull_policy 'alwyas'
  does not match …` has no line number, so those complaints could only reach the status bar.
  `lineOfPath()` in compose-model.js walks the path back to a line — longest-segment-first, so
  a service genuinely called `my.app` still resolves — and they are marked like any other.

Two false positives were caught and fixed before they shipped: an **empty** dropdown was being
marked as an odd value (so every unset setting in the form got a caution border), and the
network_mode check would have called a real docker network named `node` a typo of `none`.
`lint()` now takes the server's real network list, and treats "not known yet" as a reason to
stay silent rather than to guess.

**Supersedes PLAN_19**, which proposed checking four fields against our own vocabulary lists.
Measurement on the test box (Docker Compose v2.40.3) showed compose already catches two of
them properly, so this plan checks two fields instead of four, and gets a great deal more
than PLAN_19 offered for free.

Written after Adrian clarified what rule one protects: **structures we cannot confidently
rewrite** — not a ban on saying a value is plainly wrong.

---

## What compose actually judges

Every row below was run as `docker compose config -q` over a real file on the test box.
This table is the whole basis of the plan; nothing here is assumed.

### It catches these — so we never need a rule of our own

| Case | Compose's own verdict |
|---|---|
| `pull_policy: alwyas` | `services.web.pull_policy 'alwyas' does not match pattern 'always\|never\|build\|…'` |
| `condition: service_healty` | `value must be one of 'service_started', 'service_healthy', 'service_completed_successfully'` |
| `stop_grace_period: 30 seconds` | `time: unknown unit " seconds" in duration "30 seconds"` |
| `healthcheck.interval: 1 minute` | same, on the healthcheck |
| `ports: "99999:80"` | `invalid hostPort: 99999` |
| `ports: "notaport:80"` | `invalid hostPort: notaport` |
| `restrt: always` | `services.web additional properties 'restrt' not allowed` |
| `servces:` (top level) | `additional properties 'servces' not allowed` |
| `ports:` as a string | `services.web.ports must be a array` |
| a service with no image and no build | `has neither an image nor a build context specified` |
| `depends_on: [ghost]` | `depends on undefined service "ghost"` |
| an undeclared volume | `refers to undefined volume data` |
| tab indentation | `yaml: line 2: found character that cannot start any token` |

### It lets these through — this is the whole of Step 3

| Case | Result |
|---|---|
| `restart: alwyas` | **passes.** The container then never restarts and nothing says why. |
| `network_mode: hots` | **passes.** |
| `stop_signal: SIGTREM` | passes — left alone, the signal list is long and `SIGRTMIN+3` is real |
| `mem_limit: 512 MB` | passes — a deprecated v2 key, not worth a rule |

Compose's schema types `restart` and `network_mode` as plain strings. They are exactly the
two fields PLAN_19 was written about, and the only two it was right about.

### Three things we currently say that are not true

| | We say | Compose says |
|---|---|---|
| `include:`-only file | *"This does not look like a compose file — it has no services: section."* — **save refused** | **exit 0.** Valid, and resolves to the included services. |
| two YAML documents | *"compose does not support [this]. Keep only one."* | **exit 0**, and `config --services` lists services from **both** documents. |
| `%YAML` directive | *"not valid in a compose file"* | `%YAML 1.1` → **exit 0**. Only `%YAML 1.2` is rejected. |

Sealing all three is still right — we cannot safely edit them. Only the claim of invalidity
is wrong.

### And a live bug

Validation runs in a scratch directory, so relative paths do not resolve:

```
services:
  web:
    image: nginx
    env_file:
      - extra.env          # sits beside the real compose file
```

Today: `env file /tmp/…/extra.env not found` — **exit 1, the save is refused.** A perfectly
ordinary file cannot be saved through the editor. Adding `--project-directory <the stack's
real folder>` returns exit 0, resolves `.env`, and silences the spurious
`"NOPE" variable is not set` warnings that a scratch dir invents.

---

## The build

### 1. `Stacks.php` — `stackman_validate_compose()`

Signature gains the stack's real directory. Three changes:

- `--project-directory` when that directory exists (an existing stack). A new stack has no
  folder yet, so the flag is omitted and behaviour is what it is today.
- **Drop the `/^services:/m` gate** (`:1278`). It predates having compose to ask and now
  refuses a valid `include:`-only file. Keep the empty-text check.
- **Return compose's stderr on a passing file too.** Today `$lines` is discarded when
  `$code === 0`, throwing away exactly the advisory notes worth showing. Parse
  `msg="…"` out of compose's logfmt warnings; ignore anything that does not match.

Callers: `stackman_save_stack()` (`:1467`) passes `stackman_stack_dir($name)`.

### 2. `action.php` — a `check` action

Calls the same function and returns `{ok, error, warnings}`. No new validation logic —
the save path's own check, run earlier. POST only, like everything else.

### 3. `stacks.js` — ask while typing

Debounce ~800ms after typing stops; supersede any in-flight check by sequence number.
Render through the existing `saveErrorDot` path so line mapping and the gutter need no new
code. Warnings become `warn` dots.

**Every failure is silence.** Slow reply, timeout, no compose, no model — no information,
never a mark. Same rule `devLoad`/`netLoad`/`checkHostPaths` already follow. Advisory only:
never blocks a save, never disables a button, never writes.

### 4. `compose-model.js` — `checkSpecValues()`

Beside `checkSpecKeys()` (`:4005`), called from the same `lint()` walk. **Two fields only.**

- `restart` — closed list, plus the `on-failure:N` form. Anything else warns and names the
  nearest value.
- `network_mode` — open, because a bare word may be a real network on this server. Warn
  **only on a near-miss** of a known value (`hots` → `host`); never on an unknown word
  (`br0`). Reuse `nearestKey()`'s edit-distance helper (`:3977`); do not write a second one.
  The list gains the server's real networks after `netLoad()` returns, so the check must run
  after that.

Wording: *"`alwyas` is not one of the values `restart` accepts. Did you mean `always`?"*
Always `warn`, never `error`, never a block.

**One false positive on a working file costs more trust than ten missed typos.**

Also reword two messages measurement proved wrong (`sealErrorMessage()`, `:4020`): multi-doc
and directive files are ones the **form** cannot read, not ones compose rejects.

### 5. `stacks.js` + sheet — mark the odd option

`optionsHtml()` (`:1318`) already computes `known` and deliberately keeps an unrecognised
value as a self-labelled option. Keep that exactly — a dropdown that could not show the
current value would change the file just by being opened. Add `stackman-choose--odd` when
`known` is false, and a caution rule matching the host-path treatment.

### 6. Tests

- `FIXTURE_10_ADVANCED` (`tests/yaml_roundtrip.js:1337`) is named "advanced" but holds no
  anchor, alias, merge key, block scalar, tag or flow map — so the null-edit proof, our
  strongest test, runs on a file with none of the constructs sealing exists for. Give it
  all of them, plus `extends` and `profiles`.
- `checkSpecValues` cases, negatives first: `on-failure:5`, `service:db`, `container:x`,
  `br0` and every value the form itself writes must stay **silent**.

## Left out

- Any automatic rewriting of a value, on any path, including import and paste.
- Anything that blocks a save. The file is Adrian's.
- `stop_signal` and `mem_limit`, per the measurement above.
- Mixed line endings: opening a file with both CRLF and LF and pressing Save rewrites every
  line to CRLF, including untouched ones (`stacks.js:713`, `5060`, `7212`). Deliberate, and
  the least-bad option available through a textarea — but the comment there does not admit
  it, and should.
