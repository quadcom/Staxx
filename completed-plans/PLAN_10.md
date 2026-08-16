# A mapping with an empty half stays visible, and stops the save

Status: **built and deployed**, 2026-08-14, awaiting a look in the browser. Follows the
blank-list-entry work in `PLAN.md`, which fixed the same fault for one-box entries and deliberately
left this one out. Local suite: **613 round-trip tests, 0 failed**.

Both halves are flagged, host as well as container — Adrian's call, 2026-08-14. The row is marked
and named the moment the file opens, so a save that is going to be blocked says so before you reach
for the button.

## What is wrong

Clear the container side of a port and the row **disappears from the form**. The line stays in the
file, invisible, reachable only in the Compose view. Volumes and devices do the same.

The cause is not the port splitter, as it first looks. It is one gap in `needsQuoting()`
(`compose-model.js:155`): it quotes a value with `": "` inside it, but not one that *ends* in a
colon. So clearing the container box writes

```yaml
      - 8080:
```

which is no longer a string — it is a **mapping** with the key `8080` and no value. The item stops
being a port entry at all, `harvestLongForm` cannot read it, and the row is dropped. The same
one-character hole turns `- /mnt/a:` and `- /dev/dri:` into mappings too.

What compose makes of each shape, tested on the box:

| entry | `compose config` |
|---|---|
| `ports: - "8080:"` | rejected — `invalid proto:` |
| `ports: - ":80"` | accepted |
| `volumes: - "/mnt/a:"` | rejected — `empty section between colons` |
| `volumes: - ":/data"` | rejected |
| `devices: - "/dev/dri:"` | accepted |

So compose catches some and waves others through. Its refusals also arrive from the server, after a
round trip, in its own words. The form should say it first, and say which row.

## The change

### 1. A trailing colon is quoted — `compose-model.js:155`

```js
if (/:(\s|$)/.test(v) || /\s#/.test(v)) return true;
```

One character (`\s` → `(\s|$)`). A colon at the end of a scalar is a key indicator just as much as
one followed by a space, so this is a plain correctness fix: it stops the writer producing something
that means a different thing from what it wrote. Clearing a half now writes `- "8080:"`, the entry
stays a string, and the row stays on screen with one box empty. Verified: both halves bind, and a
legitimate one-sided entry (`- 8080`, `- /data`) is untouched — its missing half has no spot, which
is what tells the two apart.

### 2. An empty half blocks Save — `stacks.js`, beside `requiredGaps()` (`:2106`)

The mechanism already exists: `updateRequired()` marks each gap row, shows a sentence naming the
first one, and disables **Save** and **Save and start**. It runs on every render and after every
edit, so the block lifts the moment the row is filled in. All this needs is a second kind of gap:

```js
// A mapping written with a separator but with one side left empty — "8080:",
// ":/data". Both halves have a spot only when both were written, which is what
// separates this from a legitimate one-sided entry ("- 8080", "- /data") whose
// missing half has no spot at all.
function halfMapping(f) { … }
```

The note wording moves to a per-gap sentence, because "Port 80" is a poor name for a row whose port
number is the missing half — a half mapping says *"A port entry has no container side."* while a
required-and-empty field keeps today's *"X" is required and empty.*

### 3. Both halves cleared removes the entry — `stacks.js`, in `commit()` (`:2232`)

An entry with nothing on either side is nothing, so it goes rather than being written as a bare
separator. This has to happen on the redrawing path, not `commit()`'s quiet one: a removed row must
leave the screen, or the next edit writes into a field that is no longer there.

The × button's body (`:2743`–`:2766`) does exactly this already — undo, refuse safely, keep the
section on screen when its last entry goes — so it becomes a small `removeRow(f, say)` that both
callers use, rather than a second copy of twenty lines.

## Files

| File | What |
|---|---|
| `…/javascript/compose-model.js` | `needsQuoting()` — one character |
| `…/javascript/stacks.js` | `halfMapping()`, the gap note, `removeRow()` shared by × and `commit()` |
| `tests/yaml_roundtrip.js` | the cases below |

## Verification

New tests:

1. Clearing a port's container box writes `- "8080:"` and the row is still there, with the host half
   intact — today it vanishes.
2. The same for a volume and a device.
3. A legitimate `- 8080` / `- /data` is unaffected, and still has no host spot.
4. A value ending in a colon is quoted wherever it is written; one with a colon inside it is not
   quoted differently from today.

Then `node tests/yaml_roundtrip.js`, `node tests/js_undeclared.js`, `node --check` on both scripts;
deploy and match checksums.

By eye on the server:

1. Clear a port's container box. The row stays, the box is empty, the row is marked, the note names
   it, and Save and Save-and-start are both greyed out. Type a port back in and both come back.
2. Clear the host box as well: the whole entry disappears.
3. Open a stack with `- "8080:"` already in its file: the same block is in force from the moment it
   opens, so nothing is a surprise at save time.
4. A normal file still saves. This must not flag anything a working file contains.
