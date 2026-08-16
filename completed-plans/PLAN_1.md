# Form layout: group the fields

**Status: COMPLETE** — built and deployed to the test box on 2026-08-13. Kept for reference.

Three things came out differently from the plan, all noted in place below: the form's group class
is `stackman-formgroup` rather than `stackman-group`, which the stacks table already owns; the
`read-only mount` badge moved inside the container-path box rather than disappearing with the
titles; and `.stackman-fieldhead` survives as the wrapper for a device or locked row's heading.

## Why

The Form view renders a service as one flat list. Every field gets its own bold title and its own
pair of right-floated `required` / `sensitive` checkboxes. On a service with twenty variables that
is twenty headings mostly repeating the variable name in the box below, and twenty pairs of ticks
at twenty different horizontal positions.

Fields become **groups**: one heading per group, ticks in a fixed left gutter so they line up, and
the standard compose keys lifted out of file order into a block at the top — where `container_name`,
which the form does not render at all today, finally appears.

## Shape

```
────────────────────────────────────────────────────────────────
CONTAINER (required)
       setting         value                        note
       Image           lscr.io/…/jellyfin:latest    note…
       Container name  jellyfin                     note…
       Restart         unless-stopped ▾             note…
       Network mode                                 note…
────────────────────────────────────────────────────────────────

────────────────────────────────────────────────────────────────
VOLUMES                                                 + Volume
  R  S  path on the server  path in the container   note
  ☐  ☐  /mnt/user/media     /media                  note…     ×
────────────────────────────────────────────────────────────────
```

| | |
|---|---|
| Group order | Container, Ports, Volumes, Devices, Variables, Labels, Advanced |
| Container | `image`, `container_name`, `restart`, `network_mode` — **always shown**, even when the file has no such line. No ticks, no Remove; the header carries `(required)` instead |
| Advanced | `command`, `entrypoint`, `user`, `hostname`, `privileged`, `shm_size`, plus any non-setting row the parser had to lock |
| Titles | dropped, except on a device (which names the hardware, not the path) and a locked row |
| Container / Advanced rows | a **label column** ahead of the value box — fixed keys with one box have nothing else to name them |
| Box hints | one **caption row** per group instead of a hint under every box |
| Save-blocking | an empty `image` or `container_name` only |
| Add buttons | one per group, right-justified on its header line; the strip under the service name goes |
| Empty groups | header and Add button still shown, no caption row |
| Remove | small `×` at the right end of the row, on hover and `:focus-within` |

## The one real hazard

`data-row` is an **index into `MODEL.fields`**, and `refreshRanges()` re-maps those indices against
a freshly built model without redrawing. If typing into an empty `restart` slot made a field appear
mid-array, every later row would silently point at the wrong field.

So the always-shown slots live **in the model, not the renderer**: `harvest()` emits all four
Container settings for every service whether the file has them or not. The field count is then
constant, and a key materialising changes a field's contents but never the array's length.

## Markup contract

All three files below must agree on this. Class names are fixed here, not invented per file.

`stackman-formgroup`, not `stackman-group` — the latter is the stacks table's subgrid wrapper, and
its rule sets `display` and `grid-template-columns` on the bare class.

```html
<div class="stackman-formgroup stackman-formgroup--pair" data-group="volume">
  <div class="stackman-grouphead">
    <h5 class="stackman-fieldgroup">Volumes</h5>
    <button type="button" class="stackman-add" data-add="volume" data-service="web">
      <i class="fa fa-plus" aria-hidden="true"></i> Volume</button>
  </div>

  <div class="stackman-caption" aria-hidden="true">
    <span class="stackman-capflag" title="required">R</span>
    <span class="stackman-capflag" title="sensitive">S</span>
    <span>path on the server</span>
    <span>path in the container</span>
    <span>note, kept in the file</span>
    <span></span>
  </div>

  <div class="stackman-fieldrow" data-row="7" data-field-row="web/volume/…"
       data-from="12" data-to="12" tabindex="0">
    <label class="stackman-flag">…required checkbox…</label>
    <label class="stackman-flag">…sensitive checkbox…</label>
    <div class="stackman-box">…host…</div>
    <div class="stackman-box">…container…</div>
    <label class="stackman-box stackman-box--note">…</label>
    <button type="button" class="stackman-kill" data-row="7" data-remove="1">…×…</button>
  </div>
</div>
```

Rules:

- The two `.stackman-flag` labels are **direct grid children**, one column each, so they sit under
  the `R` and `S` captions. There is no wrapper element.
- `.stackman-boxes` and `.stackman-boxes--mapped` are gone — the row owns the columns.
- A **Container** row emits no flags, no `×`, and a `<span class="stackman-fieldlabel">` first.
  An **Advanced** row emits flags, then the label, then the rest.
- A device row's heading, a locked row's `<pre class="stackman-fieldraw">`, `.stackman-fieldnote`
  and `<details class="stackman-devmore">` span every column (`grid-column: 1 / -1`). The heading
  keeps its `.stackman-fieldhead` wrapper so the title and its tags span as one thing — loose, each
  tag would claim a column and shove the boxes out of line with the captions.
- A volume's `read-only mount` badge rides inside its container-path box, for the same reason.
- A caption row is omitted when the group has no rows.
- `.stackman-groupnote` is the grey `(required)` inside the Container heading.

Group classes and templates — set on `.stackman-group`, inherited by both the caption row and the
field rows so they line up without subgrid, which needs the outer columns fixed-width:

| Group class | Columns |
|---|---|
| `--container` | `label · value · note` |
| `--advanced` | `R · S · label · value · note · ×` |
| `--pair` (ports, volumes, variables, labels) | `R · S · box · box · note · ×` |
| `--device` | `R · S · box · note · ×` |

## Changes

### `javascript/compose-model.js`

- `container_name` joins `SETTINGS` (:526); new `ALWAYS = ['image', 'container_name', 'restart',
  'network_mode']`.
- `harvest()` (:812) emits the four `ALWAYS` settings first, in that order, before the file-order
  loop, which then skips those keys. A key that is present behaves exactly as today. An absent one
  becomes a target with `parts: { value: part('', null) }`, `range: null`, `absent: true`.
- `fieldsFor()` (:972) — an `absent` target counts as usable, so it does **not** fall into the
  `locked` branch. Carry `absent`, plus `fixed` (the four) and `fixedRequired` (`image`,
  `container_name`) onto the field. `fixed` is what the renderer groups on, so the list of four
  lives in one place.
- New `addSetting(doc, form, service, key, value)`, modelled on the key-absent tail of `addItem`
  (:1290–1319): `serviceMapOf()` guard, `svc.value.indent`, insert after the last non-`x-unraid`
  key, `splice()`. It writes one line — `pad(indent) + key + ': ' + emitScalar(value, 'plain',
  false)` — and returns `-1` when `emitScalar` refuses.
- `setPart()` (:1140) — where `!p.spot && f.absent && which === 'value'`: a blank writes nothing and
  reports success (an empty slot must not put `restart:` into the file just from being focused);
  anything else goes through `addSetting`, which writes key and value together, so there is no
  second write.
- `setComment()` (:1155) keeps refusing an absent row — there is no comment line to write to.
- **Nothing strips a marker.** A file with `restart: always  # -!R` shows no tick to edit it with,
  but `readComment()` still reads it and the comment is written back untouched.

### `javascript/stacks.js`

- New `GROUPS` table and a function placing a field by `fixed` / `binder` / `locked`. A `fixed`
  field goes to Container even when locked, so that group always has exactly four rows.
- `renderForm()` (:680) buckets a service's fields into groups **preserving each field's original
  index into `form.fields`**, then emits one `.stackman-group` each. The `.stackman-adds` strip
  (:707–717) goes; its buttons move onto the header lines with their `data-add` / `data-service`
  attributes intact, so the click handler at :959 is untouched.
- `fieldHtml()` (:542) emits flat grid cells instead of head-over-boxes.
- `boxHtml()` (:498) keeps emitting `.stackman-boxhint` — CSS hides it where a caption names the
  column, and shows it again on a narrow screen. Its `dead` test (:501) becomes
  `(!p.spot && !f.absent) || f.locked`, or an empty slot renders disabled and can never be typed in.
  The hint also goes into the input's `title`, so a Container row's explanation survives as a
  tooltip.
- `requiredGaps()` (:752) counts `f.required || f.fixedRequired`.
- `refreshRanges()` (:833) also syncs the `disabled` state of each row's note and tick inputs, so a
  slot that has just gained its line stops being greyed out without a full redraw.

### `sheets/stack.manager.css`

- `.stackman-fieldgroup` (:2030) is written but never emitted — reuse it as the group heading, its
  bottom hairline replaced by the rule-above-and-below band on `.stackman-group`.
- New `.stackman-grouphead`, `.stackman-caption`, `.stackman-capflag`, `.stackman-fieldlabel`,
  `.stackman-groupnote`.
- `.stackman-fieldrow` (:2040) becomes `display: grid`; `.stackman-boxes` (:2067) and
  `--mapped` (:2074) lose theirs.
- Drop `.stackman-flag:first-of-type { margin-left: auto }` (:2136), which is what pushes the ticks
  right today, and `.stackman-adds` (:2152).
- `.stackman-kill` becomes a quiet `×` shown on row hover and `:focus-within`.
- **Narrow screens.** Below the existing breakpoint the group drops to one column per row: caption
  hidden, boxes stacked, `.stackman-boxhint` visible again so each box still says what it is.

### `tests/yaml_roundtrip.js`

- typing into an absent `restart` slot inserts `restart: <value>` at the right indent under the
  right service, and leaves every comment intact;
- a blank into an absent slot writes nothing;
- a service with no settings still yields exactly four Container fields, and the field count does
  not change once one is filled in;
- a `restart:` line carrying `-!R` survives a save byte-for-byte.

## Verification

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js
```

Then deploy and check, in order: `examples/jellyfin/compose.yaml` arrives with `container_name`
filled; a stack with no `restart:` shows the empty slot, and typing into it adds the line at the
right indent without losing the caret; an empty `image` raises the gap note and disables Save;
devices still show the hardware name and the "not found on this server" tag; a `command` written as
a list lands in Advanced, read-only; Sanitise still blanks sensitive rows; captions sit over the
boxes they name; narrowing the window brings the hints back.
