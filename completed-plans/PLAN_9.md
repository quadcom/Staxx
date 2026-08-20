# PLAN — Add container, and a list to pick from wherever compose has one

Status: **complete**, 2026-08-13. Built, verified and deployed to the test box.
Nothing is committed — the working tree is left dirty for review.

## Why

Two gaps in the form editor.

1. A stack can be created and a service renamed, but a service could not be **added** to a stack
   that already exists. The only routes were hand-typing YAML or starting a whole new stack.
2. The dropdown machinery worked but was wired to four fields. Roughly fifteen more compose fields
   have a closed set of legal values and rendered as free text — a spelling test where compose only
   accepts a list.

## What was built

- [x] **Model** — `javascript/compose-model.js`
  - `addService()`, mirroring `addDeclared()`, reusing `renameService()`'s name validation. Nests
    to the file's own indent. Refuses (writing nothing) on a taken name, an invalid name, an empty
    name, a sealed `services:`, or no `services:` at all.
  - Ten new `KEYS` rows so `pull_policy`, `stop_signal`, `ipc`, `pid`, `read_only`, `init`, `tty`,
    `stdin_open`, `cap_drop` and `logging` gain a title and a group instead of falling to the
    Advanced catch-all. `logging.driver` joins `LEAVES`.
  - A `proto` part on a short-form port and a `mode` part on a short-form mount. Each carries its
    own separator (`/udp`, `:ro`), so choosing the empty option writes the separator away with the
    value and `writeScalar()` needs no special case.
  - **Boolean quoting fix.** `needsQuoting()` quotes `true`/`false` to stop a string being misread
    as a boolean — right for a text box, wrong for a boolean field, where it wrote
    `privileged: 'true'`, a string. New `'bare'` style in `emitScalar()`, chosen only when the
    field is boolean *and* the line is already plain, so a deliberate `"true"` keeps its quotes.
  - `booleanTail()` so an **absent** `healthcheck.disable`, dependency `required` and declaration
    `external` report `type: 'boolean'`. Without it they render as text boxes and write quoted.
- [x] **Server** — `include/Defines.php`, `include/action.php`
  - `staxx_docker_images()`, `staxx_image_tags()`; `case 'images'`, `case 'tags'`.
  - Repo validated by shape **and** `escapeshellarg`'d before it reaches a URL. Docker Hub only;
    `lscr.io`/`ghcr.io` + `linuxserver` map to Hub; every other registry returns empty rather than
    guessing. Every failure returns `[]` — this runs mid-keystroke, so it degrades in silence.
- [x] **UI** — `javascript/stacks.js`
  - `choiceFor()` replacing the five inline special cases in `boxHtml()`, precedence preserved.
  - Boolean dropdowns keyed on `f.type`, not on a second list of key names. New fixed lists for
    `pull_policy`, `stop_signal`, `ipc`, `pid`, `logging.driver`, port protocol, mount mode,
    declared network/volume driver.
  - Datalists (suggest, never refuse) for `image`, `cap_add`/`cap_drop` and `profiles`. The
    `<option>` carries an explicit `value=`, so a description can never be written into the file.
  - Add-container button and handler; `imgLoad()`/`tagLoad()` beside `netLoad()`.
  - A `--mapped` five-track group for ports and mounts, so the protocol sits beside the container
    half with its own caption rather than wrapping below.
- [x] **Tests** — 490 → **529** passing. Three new sections: `addService`, the new parts, boolean
  writing. Section B's null-edit byte-identity guard iterates `Object.keys(f.parts)`, so the new
  parts joined the strongest guard in the suite automatically.
- [x] **Verified and deployed.**

## Not done, deliberately

- **Removing a container.** Out of scope this round; `addService` has no `removeService` twin.
- **`ghcr.io` tag suggestions.** 17 of the 78 images on the test box are ghcr, which needs a bearer
  token even for public reads. They fall back to a plain text box, which is the designed behaviour.
- **Long-form port/mount protocol.** Not harvested as an editable field by the model, so there was
  nothing to attach a list to. The short form has it.
- **`deploy.restart_policy.condition`.** Not in `LEAVES`, so it is not a field.

## The rule this had to not break

A port with no `/udp` must not grow one just by being rendered, and a boolean written plainly must
stay plain. Both are covered by the null-edit test, which demands byte identity after setting every
part to the value it already holds. Both fixes were mutation-tested: disabling `booleanTail` gives
4 failures, hardcoding `addService`'s indent gives 1.

Full plan, with the reasoning behind each decision:
`C:\Users\adrian\.claude\plans\2-things-first-there-tender-patterson.md`
