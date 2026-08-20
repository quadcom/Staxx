# PLAN_51 — one field says which port the web button opens

**Status: APPROVED, building.**

## Context

Three plans have now tried to work out, from the compose file, which port a container's web page
answers on. PLAN_39 said "the first port in the list, and you choose by dragging". PLAN_49 said "the
network decides which side of the mapping". PLAN_50 added a suggestion drawn from the file's own web
address, a note when the evidence disagreed, and a button to test the result.

The button immediately found a link of Adrian's pointing at a port nothing listens on — **StirlingPDF**,
whose file carries a `80:8080` mapping left over from a bridge, on a network where Docker ignores
mappings entirely. Neither half of that mapping is authoritative, and its image declares no ports at
all, so no amount of reading the file would have found it.

Adrian's call, and it is the right one: **stop inferring.** A field holds the port. Filled in, the web
button works. Empty, it does not. No evidence-weighing, no ordering rule, no guessing.

## Adrian's decisions, recorded

| Question | Answer |
|---|---|
| The control | **A port field in the Container group**, with the WebUI chip beside it. |
| The rule | **Filled in, the link is live. Empty, the link is off.** |
| Where it is stored | **The existing web address line** — the field edits the port inside it. Nothing new invented, and Unraid templates still read. |
| Existing files | **Kept.** The field shows the port out of the address and writes only that back; the scheme and any path survive untouched. |
| PLAN_50's guessing | **Removed.** A field you fill in makes a suggestion and a disagreement note pointless. |
| PLAN_50's test button | **Kept.** It is the one thing that found a real fault, and it costs nothing. |

## What you would notice

The Container group gains a fourth row, **Web page port**, with the WebUI chip beside it. Type 80 and
the container's web button opens port 80. Clear it and the button greys out. That is the whole
feature.

The port rows lose their WebUI chip, because they no longer decide anything about the web button.

---

## Part A — the field

The form has never made anything inside `x-unraid` editable; this is the first, and the piece the
project has been missing. Four things stand in the way, all small:

1. **`harvest()` skips every `x-` key outright**, so `x-unraid` never becomes a field at all. One
   named exception for the web address, emitted the way the fixed Container rows already are.
2. **The field must bind the port inside the address, not the whole string.** This is exactly what a
   port row already does — `splitPortShort()` splits one value into three separately-editable pieces,
   each rebuilding the whole string around its own piece. The write path is generic and never looks
   at what kind of field it is, so the same shape works here: the port is the editable piece, and
   everything before and after it is carried along untouched.
3. **Container membership is worked out from a list of plain compose keys**, which the web address is
   not. One explicit rule beside the existing one that puts health-check rows in their own group.
4. **The chip is written only onto port rows**, is revealed by a class only the port group ever gets,
   and is pinned to a column the Container group does not have. It needs a plain version of itself
   and one more column on that group.

**Two shapes must be read**, because both exist in Adrian's files:

- `http://[IP]:[PORT:8181]/` — the token form, in 40 of 42 files. The field shows 8181.
- `http://[IP]:80/` and `http://192.168.200.88:5000` — a literal port. The field shows it.

**The field always writes a literal port.** So the first time anyone touches a file, its address stops
asking the plugin to work anything out and simply says what it is. Every one of Adrian's 42 files has
a port in one form or the other, so none loses its link.

**Clearing the field** empties the port, leaving an address with no port — which is what turns the
button off. It must not delete the whole address line, which is what the existing "cleared a value"
handling would otherwise do.

## Part B — the link

`staxx_webui_url()` gets simpler, but not as simple as "the field is the truth", and the reason
matters:

- **A literal port in the address is the truth.** Used as written. This is what the field produces,
  and it is what fixes StirlingPDF.
- **A `[PORT:…]` token still means "work it out"**, exactly as it does today. This has to stay for
  files nobody has edited yet — **mazanoke's address says 80 while its link needs 8686**, so reading
  the token literally would break working bridge links.
- **No port at all** means no link, which is the rule Adrian asked for.

So the token path is a migration, not a design: it keeps every existing link working until the file is
edited once, at which point the number becomes explicit and the guessing never runs again for it.

The address half is untouched — a macvlan container's own address, else a fixed address in the file,
else this server's. That part of PLAN_49 was right and stays.

## Part C — what comes out

- **PLAN_50's port suggestion.** The **+ port** button goes back to plain 8080 counting up, for every
  network. Nothing is guessing at a web port any more.
- **PLAN_50's disagreement note.** Nothing to disagree about once a field states it.
- **The WebUI chip on port rows**, and the sentence in its tooltip about putting a different port
  first. Dragging ports still reorders them in the file; it just no longer means anything to the web
  button.
- **PLAN_49's port-row sentences stay.** They explain why one side of a mapping is greyed out on a
  non-bridge network, which is still true and still useful.
- **The exposed-port list** carried on the state reply loses its only reader. Left in place, since the
  Address column already needs the same underlying read, and a comment saying so.

## Files

| File | Change |
|---|---|
| `javascript/compose-model.js` | The named exception in `harvest()`; a splitter for the port inside the web address; the write and clear paths for it; remove PLAN_50's suggestion and note. |
| `javascript/stacks.js` | The Container-group rule; render the field and a plain chip beside it; remove the chip from port rows. |
| `sheets/staxx.css` | One more column on the Container group; a chip that does not depend on being the first port row. |
| `include/Stacks.php` | `staxx_webui_url()` — a literal port wins, a token still resolves, no port means no link. |
| `schema/x-unraid.schema.json`, `docs/x-unraid-schema.md` | Describe the field, and drop the "cannot be edited in the form" gap. |
| `tests/yaml_roundtrip.js` | The pinned field count and id list move by one. |
| `tests/server/webui.php` | Cases for a literal port, a token, and no port. |

## Verification

**Locally:** `node --check` on both browser files; `tests/js_undeclared.js`; `tests/ca_convert.js`
unchanged; `tests/yaml_roundtrip.js` passing with its counts deliberately updated by exactly one
field per service, and its round-trip cases — the byte-for-byte guarantee — untouched.

**On the server:** `php -l`, then all twelve suites.

**In the browser, on the real server:**
- **StirlingPDF** — the broken one. Type 80 in the field, save, and confirm the button opens. Confirm
  the file's address changed from a token to a literal 80 and nothing else moved.
- **mazanoke** — a bridge container nobody has edited. Confirm its link is still 8686, proving the
  token path still works.
- **it-tools** — type 80, confirm the button appears and opens.
- **Clear a field** and confirm the button greys out and the address keeps its scheme and path.
- A container with a **path** in its address, such as one ending `/admin`, edited to confirm the path
  survives.
- **Test web page** on each, which is now the quickest way to prove all of the above.

## Risks

- **First editable metadata.** Everything in `x-unraid` has been read-only until now, and the harvest
  layer skips it deliberately. A named exception is the small version; the temptation is to build a
  general renderer for the whole block, which is a much larger piece of work and not this plan.
- **Round-trip safety.** The field edits a substring of an existing line, which is the same mechanism
  the port rows already use and the safest one available — but the whole project rests on files
  surviving a write untouched, so the round-trip tests are the gate, not a formality.
- **The token path looks like dead weight** and is not: deleting it breaks every link in a file nobody
  has edited. It should carry a comment saying exactly that, or someone will tidy it away.
- **Dragging ports quietly loses its meaning** for the web button. That was a real feature. Nothing
  breaks, but the reason for the drag is now only ordering.
