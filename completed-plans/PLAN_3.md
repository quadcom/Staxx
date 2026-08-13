# Form view: alignment, density, hierarchy, and a plain-English command summary

**Status: COMPLETE** — built and deployed to the test box on 2026-08-13. Kept for reference.

Two things came out differently from the plan. The `<p data-say>` paragraph is emitted for every
command and entrypoint, `hidden` while it has nothing to say, rather than only when there is a
sentence — the row is not redrawn while its box is typed in, so an element that was never emitted
could never be filled. And the flow-list test reads the first line only: `- echo [ok]` is a list
item that merely contains a bracket, and matching anywhere in the block reported the wrong command
entirely.

## Context

Four things from the latest look at the Form view on the test box:

1. **The `R` / `S` captions do not sit over their tickboxes** — in every group, by the same amount.
2. Rows are too far apart; a service with twenty variables scrolls further than it needs to.
3. A `command:` shown as a read-only block says nothing about what it actually is.
4. The service name is 1.5rem against a group heading's 1.2rem uppercase — barely a step, so the
   page reads as one flat level rather than "service, then its groups".

1, 2 and 4 are stylesheet-only. 3 is the real piece of work.

## 1 — why the captions are off, in one line

`.stackman-fieldrow` has `padding: 0.8rem 1rem`; `.stackman-caption` has none. Both grids read the
same `--sm-fieldcols`, so both have identical tracks — but the row's start **1rem further right**
than the caption's. Hence a constant offset in every group, which is exactly what it looks like.

Give the caption the same horizontal padding and they line up. Nothing else changes.

## 3 — what the summary will and will not say

It describes the command's **structure**, never its meaning. The plugin cannot know what
`/app/run` does, and a summary that guesses is worse than none. What it can say for certain: what
program is invoked, whether a shell is in the way, how many steps there are, and which arguments
go together.

```
ADVANCED
  Command   ┌─ command:                            ─┐
            │   - /bin/sh                           │
            │   - -c                                │
            │   - echo starting && exec /app/run    │
            └─                                    ─┘

  Runs a shell, which is handed one line to run: “echo starting && exec /app/run”.
  Two steps, run in order. The last replaces the shell, so it becomes the
  container's main process.

  Not editable here because this is written as a list of separate items.
  Use the Compose view.
```

**It covers the one-line form too**, not just the multi-line one — `command: sh -c "…"` on a
single line is the commonest way to write one, and a feature silent on that case would read as
broken. That row is editable, so the summary is kept in step by `refreshRanges()` rather than by a
redraw (see below).

When it cannot parse something confidently it returns nothing and no paragraph is emitted.

## Changes

### `javascript/stacks.js` — the explainer

New `commandSay(f)` near `fieldHtml()`, returning an HTML string or `''`. It runs only for
`f.target === 'command'` or `'entrypoint'`; every other field gets nothing.

**Getting the arguments.** One small quoted-token splitter serves all three shapes — it walks the
string, treats `'` and `"` as opening and closing a run, and splits on a separator only outside
one:

| Written as | Where it comes from | What we get |
|---|---|---|
| `command: sh -c "…"` | `f.parts.value.value` (editable row) | argv, split on whitespace |
| `command: ["sh", "-c", "…"]` | `f.raw` (locked, sealed `flow`) | argv, split on commas inside the brackets |
| a `- item` list | `f.raw` (locked, seq) | argv, one per `- ` line, quotes stripped |
| `command: \|` … | `f.raw` (locked, sealed `block-scalar`) | a script — the body lines |
| `command: >` … | `f.raw` (locked, sealed `block-scalar`) | one folded line |

`f.raw` is the dedented file text the `<pre>` above already shows (see `settingTarget()` in
compose-model.js), so the summary can never disagree with the block it sits under.

**What it says.**

- *Shell form* — `argv[0]`'s basename is one of `sh`, `bash`, `ash`, `dash`, `zsh` and `-c` is
  present: name the shell line, count the steps (split on `&&` and `;`), and add the `exec`
  sentence **only** when the final step actually begins `exec `.
- *Ordinary argv*: `Runs <prog>.` then the arguments, pairing a `-flag` with the token after it
  when that token is not itself a flag and the flag carries no `=`. Cap the list at six and finish
  `…and N more` — a wall of text is not a summary.
- *`|` script*: how many lines, that each runs in turn, and what the first one begins with.
- *`>` folded*: that it is one line wrapped across N in the file.
- Anything else: `''`.

Code-ish fragments are wrapped in `<code>`; every value goes through `esc()` first.

### `javascript/stacks.js` — where it appears, and keeping it fresh

- **Locked row** (the `if (f.locked)` branch of `fieldHtml()`): emit
  `<p class="stackman-fieldhint" data-say="1">` between the `<pre class="stackman-fieldraw">` and
  the "Not editable here…" note, so the explanation sits directly under what it explains.
- **Editable row**: emit the same paragraph in the row's tail, alongside the other full-width
  children — after the `showKill` block, per the rule established last round.
- `refreshRanges()` gains three lines beside the existing `[data-note]` / `[data-required]` sync:
  find `[data-say]` in the row and rewrite its `innerHTML` from the fresh field. Editing the box
  therefore keeps the sentence in step without a redraw, which is what preserves the caret.

### `sheets/stack.manager.css`

- `.stackman-caption` — add `padding: 0 1rem`, matching `.stackman-fieldrow`'s horizontal padding.
  This is fix 1 in its entirety; comment it, because the reason is not visible from either rule on
  its own.
- `.stackman-fieldrow` — `margin: 0 0 1.4rem` → `0 0 0.2rem`, `padding: 0.8rem 1rem` →
  `0.45rem 1rem`. About 1.9rem off every row.
- `.stackman-svchead` — `font-size: 1.5rem` → `2.2rem`, bottom margin `0.8rem` → `1.2rem`. Against
  a 1.2rem uppercase group heading that is a clear step. `.stackman-svcrename` stays 1.1rem, so
  the pencil does not grow with it.
- Add `.stackman-fieldhint` to the `grid-column: 1 / -1` list of full-width row children — it is
  now emitted inside a field row, not only above one.
- Extend the existing `.stackman-fieldmap code` chip rule to cover `.stackman-fieldhint code`
  rather than writing a second one.

## Verification

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
```

`commandSay()` lives inside a browser IIFE, so there is no way to unit-test it in place. Instead
copy the finished function into a throwaway script under `$CLAUDE_JOB_DIR/tmp`, run it over these
and read every sentence it produces before shipping:

- `sh -c "echo starting && exec /app/run"` — one line, and the same as a three-item list
- `["/bin/sh", "-c", "sleep infinity"]` — flow, single step, no `exec`
- `--api.insecure=true --providers.docker` — no program at all, just flags
- `/usr/bin/myapp --config /etc/app.conf --verbose extra.txt` — pairing, and a positional
- a `|` block of four lines, and a `>` block of three
- `command:` with nothing after it, and a value with an unclosed quote — both must return `''`

Then deploy (`pscp` the plugin folder, run `dev-install.sh`) and check in the browser: captions
sitting square over their tickboxes in all seven groups, rows visibly tighter, the service name
clearly the largest thing in the pane, and a `command:` row carrying a sentence that is true.
