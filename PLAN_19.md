# PLAN_19 — Say when a value is not one of the ones that field accepts

**Status: outstanding.** Nothing here is in the tree. Found while reviewing `PLAN_17.md`'s Community
Applications import, but it is **not a CA problem** — it applies to every compose file, including one
typed by hand, and it has been there since the form was built.

---

## What you would notice

Type `restart: alwyas` into the compose pane today and nothing happens. No mark in the gutter, no
underline, no note in the form. Save it and the file saves; `docker compose config -q` accepts it,
because that only checks the file's *shape*. The container then never restarts, and there is nothing
anywhere that says why.

The same is true of `network_mode: hots`, a `depends_on` condition that is not one of the four, a
`pull_policy` that is not one of the five, and every other field whose value comes from a fixed list.

## Why it happens

Two mechanisms both stop short of values, each for its own reason, and neither is wrong on its own.

**`lint()` checks keys, not values.** `compose-model.js:4005`, `checkSpecKeys()`, walks the top level
and each service and warns about a key the compose spec does not define. There is no equivalent pass
over values. So the gutter — which renders whatever `lint()` returns, `stacks.js:740` — has nothing to
show.

**The form deliberately shows an unrecognised value rather than correcting it.** `optionsHtml()`
(`stacks.js:1318`) prepends the current value as a selected `<option>` when it is not one of the
known ones. That is exactly right and must not change: a `<select>` can only hold one of its own
options, so without this the box would silently show something the file does not say, and saving
would write a value nobody chose. **The bug is not that it keeps the value; it is that it keeps it
looking identical to a valid one.**

## The rule this must not break

**Never be stricter than Docker.** The lists in `VOCAB` (`compose-model.js:4194`) are the values worth
*offering*, not the complete set of values that *work*. Several perfectly valid values are not in
them, and a naive enum check would flag every one of them as wrong:

| Value | Why it is valid | In `VOCAB`? |
|---|---|---|
| `restart: on-failure:5` | retry count is part of the syntax | no — only `on-failure` |
| `network_mode: service:db` | share another service's network | no |
| `network_mode: container:name` | share an existing container's | no |
| `network_mode: br0` | a real network on this server | added at runtime by `netLoad()` |

That last one matters most: `netmode`'s list is extended after the page loads with the server's own
docker networks, so anything checking it has to run **after** that, or every custom-network user gets
a false warning on a file that is perfectly correct. One false positive on a working file costs more
trust than ten missed typos.

This is also why it is a **warning**, never an error, and never anything that blocks a save.

---

## The shape of it

### 1. A value pass in `compose-model.js`

A `checkSpecValues()` beside `checkSpecKeys()`, called from `lint()` in the same walk, with a table of
`key => { vocab, allowPrefixes, allowFree }`:

- `vocab` — the `VOCAB` list to match against.
- `allowPrefixes` — forms like `on-failure:`, `service:`, `container:` that are valid with anything
  after them. Present so the table, not the checker, carries the exceptions.
- `allowFree` — set for `network_mode`, where a bare name may be a real network and cannot be judged
  from the file alone. With this set, only a value that is *nearly* one of the known ones is worth
  mentioning (see below).

Start with `restart`, `network_mode`, `pull_policy` and `depends_on.<svc>.condition`. Do not try to
cover everything at once.

### 2. Only complain when it is worth complaining

For a closed list (`restart`), anything off the list gets a warning naming the accepted values.

For an open one (`network_mode`), a warning only when the value is a near-miss of a known one — one
edit away, so `hots` is flagged and `br0` is not. This wants a tiny edit-distance-of-one test, not a
library, and its own unit tests: the cost of getting it wrong is a false warning on a working file.

The message says what the field accepts and, where there is an obvious intended value, names it —
*"`alwyas` is not one of the values `restart` accepts. Did you mean `always`?"*

### 3. The gutter gets it free

`lint()`'s callers already render `{line, level, message}`. Nothing in `stacks.js` needs changing for
the compose pane to start marking these, which is most of the argument for putting the check there.

### 4. The form marks the odd option

`optionsHtml()` already knows when it is injecting an unrecognised value — that is its `known` flag.
Have it add a `stackman-choose--odd` class to the `<select>` in that case, and a `stackman-`-prefixed
rule styling it as a caution, matching the host-path warning treatment already in the sheet. The value
is still shown and still saved unchanged; it just no longer looks the same as a valid one.

### 5. Tests

`tests/yaml_roundtrip.js`, same `ok()` harness. The negative cases matter more than the positive ones:

- `restart: always`, `on-failure:5`, `network_mode: service:db`, `container:x`, `br0` — **no warning**
- `restart: alwyas`, `network_mode: hots` — warned, and the message names the likely value
- a value the form wrote itself never warns — the round-trip corpus should stay silent throughout
- and the null-edit promise still holds: nothing here may change a byte of any file

---

## Why not just fix it in the CA import

Because the CA import does not have this problem. All 4,116 catalogue entries were converted and
checked: every `restart` produced is valid (`unless-stopped` ×4112, `no` ×3, `on-failure:5` ×1) and
every `network_mode` is `host` or `none`. Nothing CA can produce trips this.

The gap belongs to the editor, and the file that most needs it is the one somebody typed themselves.

## Left out

- **Validating values compose would reject for reasons other than a fixed list** — a malformed
  duration, a port outside 1–65535, a bad cron expression. Worth having, much larger, and each needs
  its own rule; this plan is only the fixed-list ones.
- **Making any of it an error.** Nothing here should stop a save. The file is the user's.
