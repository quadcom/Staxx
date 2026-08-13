# Form view: six fixes after the first look

**Status: COMPLETE** — built and deployed to the test box on 2026-08-13. Kept for reference.

Two things came out differently from the plan. The device row's `<details>` fold is emitted at the
very end of `fieldHtml()`, after the Remove button, not straight after the note box — a device is
a list entry, so it has a `×`, and the fold would have displaced it by exactly the trap the fold
was being moved to avoid. And `stackman_docker_networks()` leaves out the networks compose made
for a stack of its own, told apart by their `com.docker.compose.project` label; on the test box
that is the difference between six useful names and ten.

## Context

The grouped Form view is on the test box and mostly right. Six things are wrong or missing after
looking at it:

1. The `required` / `sensitive` words beside each tickbox are noise now that the group caption
   says `R` and `S` over the same two columns.
2. **The Devices group is broken** — every cell squashed into the 2.2rem tick columns down the
   left instead of lining up under its caption.
3. Group headings are grey against grey and read as decoration rather than as headings.
4. There are **two rules between adjacent groups**, not one.
5. A `command:` written as a YAML list shows a locked row with an **empty** code block — the
   content is simply lost from the form.
6. Network mode is a free text box; it should be a list to pick from, including the networks this
   server actually has.

## 2 is the real bug, and it is a grid auto-placement trap

A field row is a CSS grid and **its children are its cells**. The device row emits, in order:
tick, tick, `.stackman-fieldhead` (the hardware name, `grid-column: 1 / -1`), the device box, a
`<details>` fold (`1 / -1` again), then the note box.

A full-width child ends the row it is on, and auto-placement resumes **at column 1** below it. So
the ticks take columns 1–2, the heading takes a line of its own, and then the device box lands in
column 1 — 2.2rem wide — with the note in column 1 of another line under it. Nothing after the
first full-width child is ever in the column its caption names.

The fix is not more CSS. **A row must emit all of its column cells before any full-width child.**
The heading and the badges move *inside* the device's box, where the read-only badge already
lives, and the `<details>` fold moves to the end of the row.

## Changes

### `javascript/stacks.js`

- **Tick labels.** The two `<span>required</span>` / `<span>sensitive</span>` at :672 and :677
  become `<span class="stackman-sr">` — visually gone, still read aloud, and the `title` on each
  label still gives the tooltip. Deleting them outright would leave the checkbox nameless.
- **`boxHtml()`'s sixth parameter changes from `tag` to `head`** (:547): raw HTML rendered inside
  the box *above* the input rather than a text badge below it. The one existing caller passes the
  same `read-only mount` span it builds already, so a volume is unchanged bar the badge sitting
  above its path instead of under it.
- **Device row** (:696) — `headHtml(kit ? kit.label : f.title, [roTag, lostTag])` is passed to
  `boxHtml` as `head` instead of pushed as its own cell, and the `<details class="stackman-devmore">`
  block moves to after the note box. The row is then exactly tick · tick · box · note · ×, which is
  the `--device` template.
- **`.stackman-fieldnote`** for a partly-restricted row (:756) moves below the `showKill` block,
  for the same reason — it spans, so anything after it is displaced.
- **Network mode** joins `CHOICES` (:496):
  ```js
  'setting/network_mode': {
    hint: 'which network the container joins',
    options: [
      ['bridge', 'bridge — Docker’s own private network'],
      ['host',   'host — share the server’s network directly'],
      ['none',   'none — no network at all']
    ]
  }
  ```
  `optionsHtml()` already keeps a value the list does not carry (`service:db`, `container:x`), so
  opening the list can never change the file.
- **`netLoad()`**, modelled line for line on `devLoad()` (:1797): one `networks` call, append any
  name Docker reports that is not already in the list, labelled `<name> — <driver> network on this
  server`, then redraw once under the same guard `devLoad` uses
  (`first && modal.open && MODEL && !commitTimer && !devPanel`). Called beside `devLoad()` at
  :2141 and not waited for.

### `javascript/compose-model.js` — the empty code block

`settingTarget()` (:838) ends `lockedTarget(..., p.value ? (p.value.raw || '') : '')`. Only a
*sealed* node carries `raw`; a parsed `seq` or `map` node has no such property, so a `command:`
written as a list hands the renderer an empty string. A block scalar (`command: |`) is sealed and
does have it, which is why only the list form looks broken.

Fall back to the lines themselves, dedented by the key's own indent so the block reads as text
rather than as a fragment of a file:

```js
var raw = p.value && p.value.raw
        ? p.value.raw
        : lines.slice(p.start, p.end).map(function (l) { return l.slice(p.indent); }).join('\n');
```

Read-only, as asked — the row still lands in Advanced saying it is not editable here. Nothing
about the write path changes, so the file is untouched.

### `include/Defines.php` and `include/action.php`

New `stackman_docker_networks(): array` beside the other docker helpers — `stackman_docker_running()`
guard, then `stackman_sh()` over
`docker network ls --format '{{.Name}}|{{.Driver}}'`, split into `[['name' => …, 'driver' => …], …]`.
An empty list when Docker is down is the right answer, not an error: the three built-in choices
still work.

`case 'networks':` in the one `switch`, answering `['ok' => true, 'networks' => …]`. It takes no
parameters, same as `devices`.

### `sheets/stack.manager.css`

- `.stackman-fieldgroup` (:2061) — `color: var(--sm-fg)`, the theme's ordinary text colour, which
  is white on a dark theme and stays legible on a light one. `.stackman-groupnote` stays muted, so
  `(required)` is still grey beside a white **CONTAINER**.
- `.stackman-formgroup` (:2040) — drop `border-bottom`; add it back on `:last-child` only. Each
  group's own top rule is then the single line between it and the one above.
- `.stackman-fieldhead` (:2172) is now inside a box rather than a direct child of the row, so
  the `grid-column: 1 / -1` entry for it at :2157 applies only to a locked row's heading, which is
  where it is still needed.
- New: `.stackman-fieldrow--locked > .stackman-kill { grid-column: -2 / -1; }` — a locked row is
  all full-width children, so its Remove button would otherwise be displaced into the tick gutter
  by exactly the same trap. The narrow-screen rule already overrides this and stays as it is.

### `tests/yaml_roundtrip.js`

One case in section J: a service whose `command:` is a YAML list yields a locked field whose `raw`
holds every one of those lines, and the file round-trips byte-for-byte with no edit.

## Verification

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
python tests/validate_schema.py
```

Then `pscp` the plugin folder up, run `dev-install.sh`, `php -l` the `include/*.php`, and check in
the browser:

1. **Devices** — heading, tick, tick, path box, note and × all on one line, each under its caption;
   the "change the path inside the container" fold sits below the row, full width.
2. Ticks are bare boxes under `R` and `S`; hovering one still says what it does.
3. One rule between groups, not two; headings white.
4. A stack whose `command:` is a list — Advanced shows the whole thing in the code block.
5. Network mode is a dropdown carrying bridge/host/none plus this server's own networks; a stack
   already set to something else still shows that value when the list is opened.
6. Save a file that changed nothing and confirm it is byte-for-byte identical.
