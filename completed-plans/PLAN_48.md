# PLAN_48 — making the page refresh cheap, and updates feel immediate

**Status: BUILT 2026-08-19, deployed and measured.** Four seconds became 461ms; see the phase table.
Measured on the server before anything was designed, and again afterwards.
Not a sub-plan of anything; it fixes a cost the whole page pays.

## Context

Adrian noticed that some settings take seconds to show what he had just set. Chasing it found the
switch's own position was waiting on a full table redraw, which is now fixed — but the redraw itself
turned out to take **3.3 seconds** on his server, and that is the real problem. Anything that waits
on one feels broken, and every page load pays it in full.

Measuring it found a single cause, not a general slowness.

## Measurements this plan rests on

Taken on the server, 2026-08-19, read-only. 64 stacks.

| What | Cost |
|---|---|
| One `docker compose config` on a real stack | **53ms** |
| **All 64 of them** | **3502ms** |
| One `compose ls` for the whole machine | 98ms |
| One `docker ps -a` for the whole machine | 19ms |
| Everything else the row list does, parses already in hand | 10ms |
| Reading which folders are stacks | included in the 10ms |
| The drift check added by PLAN_46 | 7ms |
| Largest compose file on the box | 5,147 bytes |
| Stacks using `include:` or `extends:` | **0** |
| Stacks with a settings file / an override | 3 / 3 |

So: **the page asks Docker to re-parse every compose file on every render, and that is 97% of the
bill.** Nothing else is worth touching.

Why it asks at all: the row needs what compose *means*, not what the file says — services, images,
container names, the `x-unraid` block, a fixed address, the first port. Variables, anchors and an
override all have to be resolved first, and compose is the only thing entitled to do that. Reading
the file by hand instead would be faster and wrong.

## What you would notice

The page appears in about half a second instead of four — nearer two-tenths on a server with no
deliberately-broken stacks in it. Anything that waits on a
refresh — a folder move, an autostart toggle, a rename, a delete — stops feeling like it did not
work. Nothing looks different; it just arrives.

## Your decisions, recorded

| Question | Answer |
|---|---|
| Where remembered answers live | **Temporary space only, cleared at reboot.** No flash writes, no wear, and the first load after a boot pays the old price once. |
| Single-row updates as well | **No — fix the cause first, then judge.** If a whole-table refresh comes back in 150ms, a third refresh path is machinery with nothing to buy. |

---

## Part A — remember the answer, not the question

`staxx_compose_meta()` already caches its answer for the length of one request, which is why the
second call inside a render is free. The change is to let that answer survive **between** requests.

- One file per stack, at `/tmp/staxx/meta/<md5 of the compose file's path>.json`, beside the job logs
  and the stats snapshots that already live there for the same reasons.
- It holds two things: a **key** describing the inputs the answer was computed from, and the answer.
- On a call: read the file, rebuild the key, and use the answer only if the keys match. Otherwise ask
  compose, and overwrite.
- Written temp-then-renamed, like the stats snapshots, so a reader never sees half of one.

**One file per stack, named after the stack, is the point** — not one file per distinct answer. A
cache keyed by content grows every time somebody edits a file; this one cannot grow past the number
of stacks, so it needs no pruning and no expiry.

## Part B — what makes a remembered answer stale

The key is a hash of everything that can change what compose would say:

1. The compose file's **contents**.
2. The override's contents, if it has one.
3. The settings file's (`.env`) contents, if it has one — compose fills values in from it.
4. A **version number stamped in the code**, bumped whenever the parsing changes. Without it, a
   plugin update would happily read answers computed by the old parser.

**Contents, not timestamps.** The obvious cheap key is the modified time and size, and it is wrong
here: stacks live on the flash drive, which is vfat, and vfat records times only to the nearest two
seconds. An edit inside that window that happened to keep the same length would be invisible. Every
file involved is a few kilobytes — the largest on the box is 5KB — so reading them costs less than a
millisecond against the 53 it saves.

## Part C — what is deliberately not remembered

- **A file that pulls in another file.** `include:` and `extends: file:` make the answer depend on
  something the key does not cover, and compose does not report what it read. Any stack whose text
  mentions either is parsed fresh, every time. There are none on this server today, so this costs
  nothing and removes a whole class of wrong answer.
- **Validation on save.** `staxx_validate_compose()` must always run for real; it is the gate that
  stops a broken file landing.
- **State.** Whether containers are running still comes from `compose ls` on every refresh. It is
  98ms for the whole machine and it is the thing most likely to have changed.

## Part D — updates that do not wait for a refresh

Two rules, one already proved in practice, to be written down rather than rediscovered:

1. **A control shows its own new position the moment the save is acknowledged**, without waiting for
   a redraw. The boot delay and the autostart switch both do this now; the switch's absence of it was
   the bug that started this. A redraw still follows and still has the last word.
2. **The two refresh sizes stay two.** The cheap one is one `compose ls`; the full one re-renders the
   table. Anything that changes the *set* of rows needs the full one; anything that changes a state
   pill needs the cheap one. A third, single-row path is not built until a measurement asks for it.

---

## Phases

**1. The remembered answer. — BUILT 2026-08-19, deployed.** Part A and B, behind the existing
function; no caller changed. Fourteen server-side cases pass, refusals first, and every other server
suite still passes.

**2. Measure again. — DONE, and it passed.**

| | Before | After |
|---|---|---|
| Full render, cold (nothing remembered) | 4026ms | 4026ms — unchanged by design |
| Full render, warm | 4026ms | **461ms** |
| Second warm render | 3991ms | 565ms |

The 400ms bar was very nearly met, and the miss is explained rather than mysterious: **270ms of that
461 is five deliberately-broken fixtures in `DEV-TESTING` being re-checked every time**, at ~55ms
each, because a rejection is never remembered. A server without broken stacks lands near **190ms** —
one `compose ls` at 92ms, one `docker ps` at 20ms, and the rest.

Whether to remember a rejection too was considered and **declined**. It would buy that 270ms, but a
transient failure — Docker hiccups once — would then be frozen as "this stack is broken" until
somebody edited the file or rebooted. Re-checking a stack that failed is the behaviour worth having,
and nobody can perceive the difference between 190ms and 460ms.

**3. Write the two rules down. — DONE.** Beside the two refresh helpers in the browser file, where
somebody about to add a third will actually meet them, with the autostart loop named as the reason
the first rule exists.

**4. Judge single-row updates. — DECLINED, as expected.** A whole-table refresh now returns in about
half a second, and on a server without broken fixtures nearer two-tenths. A third refresh path would
be machinery to keep correct with nothing left to buy.

---

## Verification

- **Local:** `node --check` on both browser files, `tests/js_undeclared.js`, `tests/yaml_roundtrip.js`.
- **On the server:** `php -l` over every changed file, then a new `tests/server/meta-cache.php`
  covering the refusals rather than the happy path — an edited file is not served from the cache, an
  edited override is not, an edited settings file is not, a file mentioning `include:` is never
  cached, and a bumped version number invalidates everything.
- **The number that decides it:** time a full render cold, then warm, then after touching one stack's
  compose file. Cold should look like today, warm should be a fraction of it, and touching one stack
  should cost one parse and no more.
- **The correctness check that matters most — DONE 2026-08-19.** A compose file on the flash drive
  edited by hand, with no help from StaXX, and read back in a fresh process: the new service appeared
  immediately, and reverting the file brought the old answer back. That is the case a
  timestamp-based key would have failed, and it is the reason the key reads contents.

  Worth knowing for the next person who tests this: within ONE request the answer is deliberately
  frozen, by the in-process cache that has always been there. Proving anything about the on-disk
  memory needs separate processes, which is why the test suite shells out for each read.

## Risks

- **A stale answer is worse than a slow one.** A row that lies about what a stack contains is the one
  outcome this must never produce. Hence contents rather than timestamps, and hence not caching at
  all for the cross-file cases.
- **The parser's shape and the stored answer can drift apart** across a plugin update. The version
  number in the key is the whole defence, and it only works if it is actually bumped — so it lives
  next to the parsing it describes, with a comment saying why.
- **/tmp filling up.** One small file per stack, overwritten in place. Sixty-four stacks is a few
  hundred kilobytes.
- **First load after a reboot is unchanged.** Accepted deliberately: the alternative is writing to
  the flash drive.
