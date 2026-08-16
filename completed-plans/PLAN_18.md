# PLAN_18 — Keep a Windows file's line endings

**Status: COMPLETE, 2026-08-15.** Built, deployed and verified on the test box. What was checked and
what it showed is at the bottom.

Closes the last two open items in `PLAN_7.md`. **Item 2 needs no code at all** — it was fixed
along the way by a better mechanism than the one that plan proposed, and this plan records that
rather than building it twice. Item 3 is real, and is not quite the bug `PLAN_7.md` describes.

---

## Item 2 — the boolean quoting decision is already made

`PLAN_7.md` asked whether editing `privileged: true` to `false` should write `privileged: 'false'`,
and proposed loosening `needsQuoting()` so a plainly-written `true` stays plain.

**That is not how it was fixed, and the way it was fixed is better.** `setPart()`
(`compose-model.js:2249`) writes `style: 'bare'` when the *form* knows the field is a boolean and
the line was unquoted to begin with. So the decision is taken from what the field **is**, not
guessed from what its text **looks like** — which is exactly what the comment above `needsQuoting()`
says that function is right to refuse to do.

Measured, not assumed. Every one of these writes an unquoted value today:

| Edit | Result |
|---|---|
| `privileged: true` → false | `privileged: false` |
| `DEBUG: true` → false (an environment value) | `DEBUG: false` |
| `x.flag: true` → false (a label) | `x.flag: false` |
| `disable: false` → true (inside `healthcheck:`) | `disable: true` |
| `healthcheck.disable` created from nothing | `disable: true` |

And the two guards hold: a file that wrote `privileged: "true"` keeps its quotes, because quoting is
never removed; and typing the word `true` into a box that held ordinary text still writes `R: 'true'`
— which is the right answer, since compose warns about a bare boolean as an environment value.

**Nothing to build. `needsQuoting()` keeps `true|false` in its list.** `PLAN_7.md`'s section is
marked resolved with this reasoning, so the question is not reopened in six months.

---

## Item 3 — what actually happens to a Windows-authored file

`PLAN_7.md` says a CRLF file is rejected whole, with the form saying *"This file is written in a way
the form cannot read"*. **That is not what happens**, and I checked on the box rather than reasoning
from the code.

Half of it is true: the parser really does seal a CRLF document as `unparsable`. `KEY_RE` allows only
spaces and tabs after a key's colon, so `services:\r` is not a key, and the file seals. Proven in
node.

But the browser never hands it one. **A `<textarea>` normalises its value to LF** — a rule of the
platform, not of this code — so by the time anything here reads the box, the carriage returns are
already gone. Opening `zz-crlf-test` on the box just now: the file is 90 bytes on disk with six CRs,
the box holds 84 characters with none, and the form renders perfectly.

So the seal never fires in the UI. What fires instead is three things nobody would connect to line
endings:

### 1. The editor thinks you changed a file you only looked at

`isDirty()` compares the box against `textAtOpen`, which is the server's text with its CRs still in
it. Those two can never be equal for a Windows file. Opening `zz-crlf-test` and clicking Close, with
nothing typed, asks:

> Close without saving?
>
> Your changes to "zz-crlf-test" will be lost.

Reproduced on the box. This is the one you would actually hit.

### 2. Saving silently converts the whole file to LF

`stackman_save_stack()` (`Stacks.php:1482`) forces `\r\n` → `\n` on the way to disk. So the first
save of a Windows-authored compose file rewrites **every line in it** — the exact thing rule 2 says
is a bug rather than a trade-off.

The comment there defends it as protection against "a Windows browser sends CRLF". **That premise is
false** — see above, a textarea cannot send CRLF. It is guarding against something that cannot
happen, at the price of reformatting files that can.

### 3. A companion file rewrites itself with nothing typed

The same shape, one level worse. `loadCompanion()` sets `fileAtLoad = res.text` (with CRs) while the
box holds the LF version, and `runFileSave()` writes whenever `body !== fileAtLoad`. It is called on
every tab switch and on closing the editor. So **opening a Windows-format `.conf`, clicking another
tab, and closing rewrites that file to LF without a keystroke** — and `stackman_write_file()`
(`Stacks.php:1813`) would strip the CRs even if we sent them.

---

## The fix

**One rule: a file keeps the line endings it arrived with.** Since the textarea cannot hold a
carriage return, the ending is remembered beside the text and put back on the way out. That is the
same bargain the byte-order mark already gets, three lines above where this lands.

Four files.

### A. `compose-model.js` — the model learns about line endings

Two small changes, mirroring the BOM handling exactly.

- **`parse()`** takes CRLF off at the door and records it as `doc.eol`, defaulting to `'\n'`. **Any
  CRLF at all makes it a CRLF file**, so a file with mixed endings comes out consistent rather than
  half-converted — a deliberate simplification, and worth a comment saying so.
- **`serialise()`** joins with `doc.eol` instead of a literal `'\n'`.
- **`splice()`** needs no change: it re-parses `doc.lines.join('\n')` and copies back only lines,
  offsets, root, sealed and warnings, so `doc.eol` survives untouched. That is currently true by
  accident, so it gets a comment making it deliberate.

This is what stops the model sealing a CRLF file, which matters for the tests and for any future
path that does not go through a textarea. It is not what fixes the three symptoms above.

### B. `stacks.js` — the page remembers, and puts back

- A `composeEol` beside `textAtOpen`, set in `openEditor()` from the body the server sent.
- `textAtOpen` is stored **as the box will hold it** (CRs stripped), so the dirty check compares like
  with like. That is symptom 1.
- `save()` converts the body back to the file's own endings for the POST only; `textAtOpen` stays in
  its LF form, since that is what it is compared against. That is symptom 2.
- The same pair for companions: a `fileEol` set in `loadCompanion()`, `fileAtLoad` stored as the box
  holds it, and `runFileSave()` converting on the way out. That is symptom 3 — and note that fixing
  `fileAtLoad` alone stops the unasked write, which is the part that matters most.

### C. `Stacks.php` — stop overriding the client

Remove the forced normalisation in **`stackman_save_stack()`** and in **`stackman_write_file()`**,
each replaced by a comment saying why the file is now written exactly as sent, and why the old
reasoning was wrong. `stackman_compose_validate()`'s normalisation at `:1292` **stays** — it writes a
throwaway temp file for `docker compose config -q` and never touches the user's.

Both functions already refuse anything outside the stack folder, over the size cap, or onto a link or
a directory, so nothing about the safety of a write changes here.

### D. The tests

- **`tests/yaml_roundtrip.js`** — a CRLF file parses, `buildForm()` reads it, an edit changes one
  line and no other, and `serialise()` gives back CRLF throughout; the same file with a BOM as well,
  since the two now stack; a mixed-endings file comes out consistently CRLF; an LF file is untouched.
- **`tests/server/files.php`** — its "normalises CRLF" case (`:46`) becomes **"keeps CRLF"**, and a
  companion written as LF still comes back as LF.

---

## Not in scope

- **Offering to convert.** A button saying "save this with Unix line endings" is a reasonable thing
  to want and is not this. Preserve first; offer a conversion later if it is ever missed.
- **A lone `\r`** as a line ending — classic Mac OS, which no compose file has been written in for
  twenty years. It stays unhandled and unparsable, as it is today.
- **`PLAN_7.md` item 1** — which of `deploy:` to refuse to offer controls for. Left alone at Adrian's
  word, pending a proper explanation of what the swarm-only keys are.

---

## Verifying

On Windows, before any deploy:

```sh
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js
node --check src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/stacks.js
node tests/js_undeclared.js
node tests/yaml_roundtrip.js        # 939 passing now, plus the new cases
python tests/validate_schema.py
```

On the box, after `pscp` and `dev-install.sh`:

```sh
php -l .../include/Stacks.php
pscp tests/server/files.php root@<box>:/tmp/ && php /tmp/files.php
```

Then, against `zz-crlf-test` — a stack already on the box whose compose file is CRLF throughout:

1. **Open it and click Close.** No "close without saving" prompt. *(Asks today.)*
2. **Change the image, save, and check the file on the box with `od -c`.** Still `\r\n` on every
   line, including the line that changed. *(Every line becomes `\n` today.)*
3. **Add a CRLF companion file with `plink`, open its tab, switch back to the compose tab, close the
   editor.** The file is byte-identical on disk. *(Rewritten to LF today.)*
4. **An ordinary LF stack** — open, edit, save, and confirm nothing gained a carriage return.
5. `zz-crlf-test` is a fixture I created for this and is removed at the end.

---

## What it showed

All local checks: `node --check` clean on both files, `js_undeclared` clean, `yaml_roundtrip`
**953 passed, 0 failed** (939 before, plus fourteen new assertions), `validate_schema.py` clean. On
the box: `php -l` clean across `include/*.php`, and `tests/server/files.php` **all passed** with its
inverted expectations.

Against a CRLF fixture, `zz-crlf-test`, with a CRLF `site.conf` beside it:

| Check | Before | After |
|---|---|---|
| Open, touch nothing, Close | *"Close without saving? Your changes will be lost."* | closes silently |
| Open a companion's tab, switch back, close | `site.conf` rewritten to LF | byte-identical, md5 unchanged |
| Edit the image and Save | every line converted to LF | 141 bytes, all seven lines CRLF, the comment intact, `nginx:1.28` written |
| An LF stack, edited and saved, `.env` tab visited | — | 98 bytes, no carriage return anywhere, `.env` byte-identical |

No console errors. Both fixtures removed afterwards.

**One thing found on the way, unrelated and not acted on.** My first fixture had no
`container_name:`, and the form refused to enable Save: *"Container Name is required and empty."*
Compose does not require one — it names the container itself from the project and service. Whether
the form should insist on it is Adrian's call and is nothing to do with line endings, so it is
recorded here rather than changed.
