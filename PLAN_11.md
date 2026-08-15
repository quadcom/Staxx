# A section switched on writes nothing until there is something to write

Status: **built and deployed**, 2026-08-14, awaiting a look in the browser. Local suite:
**614 round-trip tests, 0 failed**; schema self-test clean.

## Where this comes from

Adrian, testing `PLAN.md`'s sections work: ticking a section on put a line into his file straight
away —

```yaml
x-unraid:
  sections:
    web:
      cap_drop: '{"after":null,"lines":[]}'
```

— with nothing configured. Switching it back off left the scaffolding standing over one dead word
(`cap_drop: false`, written by the blank-entry rule in `PLAN.md`), so a file that never had an
`x-unraid:` block ended up with one for nothing.

Both come from the same thing: the on-state was being kept **in the file**, because a default-off
section with nothing in it would otherwise vanish on the next redraw and take its Add button with
it.

## What changed

**The on-state lives in the editor, not in the file.** `sectionOn[service][key]` — the page's own
map, exactly like `sectionsOpen` for the Sections panel — is set when a section is ticked on and
consulted by `serviceFlags()`, the one place that decides whether a group shows. Nothing is written.
As soon as a real entry is added the file holds the section itself, which carries the state from
then on.

**Switching off leaves nothing behind unless there is something to leave.** Content is stashed as
before. Nothing means the entry is removed outright, which collapses `web:`, `sections:` and
`x-unraid:` with it. The exception is a section that is **on** by default (Ports, Volumes,
Variables, Devices, Labels): there, absence means shown, so `false` is the only thing keeping it
hidden and it stays.

**Ticking on also clears a `false`** — `serviceFlags()` reads a false ahead of everything else, so
leaving one would hide the section just switched on.

The × button's "keep this section on screen when its last entry goes" now records the tick in the
same map rather than in the file.

`'{"after":null,"lines":[]}'` is still **read** as "shown, empty", so files written by earlier
builds behave exactly as they did. Nothing writes one. `docs/x-unraid-schema.md` says so.

## The trade

Tick a section on, add nothing, save, reopen — it is hidden again, having lost nothing, because
there was nothing in it. Adrian's call, 2026-08-14.

## Files

| File | What |
|---|---|
| `…/javascript/stacks.js` | `sectionOn`, `serviceFlags()`, both tick branches, `removeRow()`, the open-a-stack reset |
| `docs/x-unraid-schema.md` | what an entry with no lines means, and that nothing writes one now |
| `tests/yaml_roundtrip.js` | stash-then-clear leaves no block behind |

## Verification

By eye on the server, in the Compose view beside the form:

1. Tick a section on with nothing in it — the file does not change at all.
2. Add a real entry — only the compose key appears; still no `x-unraid:`.
3. Switch it off — the stash appears, holding the entry. On again — the entry is back and
   `x-unraid:` is gone entirely.
4. Tick on, add an entry, clear it, switch the section off — the file is exactly as it started.
5. Switch **Ports** off on a service with no ports — `ports: false` is written, and the section
   stays hidden through a redraw. On again, and it goes.
6. Open a file that already carries `'{"after":null,"lines":[]}'` — the section still shows.
