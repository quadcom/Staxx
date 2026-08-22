# PLAN 64 — joining a container to one of this server's own networks

**Status: complete 2026-08-21. All four phases built, verified and deployed.**

- **Phase A — the dropdown.** A service's network row offers this server's own networks alongside the
  file's own, labelled "on this server, not in this file yet". `bridge`, `host` and `none` are left
  out of that list deliberately.
- **Phase B — declaring on pick.** Picking one declares it as external and attaches the container in
  one move, one undo entry, and writes neither half on a refusal. **A bug was found here after the
  fact and is now the subject of `PLAN_66`:** declaring re-reads the file, and writing through the
  form's stale memory of line positions corrupted an unrelated line. Fixed twice over — the form
  re-derives, and the model now refuses a write whose position has gone stale.
- **Phase C — the modes and the set-aside.** `host`, `none` and sharing another container write
  `network_mode` and take every network row off that service, keeping them in its `x-unraid` block
  with a one-line note and a way to put them back. The stash records **where the block sat** as well
  as what it held, so a set-aside and a restore leave the file byte-identical.
  *Deviation from section 6, accepted:* the reverse direction (mode back to a network) runs through
  the `network_mode` row's own existing dropdown rather than a resurrected empty network row. Both
  directions are one dropdown, one undo, and cannot leave a service holding both — just not literally
  the same row.
- **Phase D — the converter. Done.** A template saying `bridge`, or saying nothing, now writes
  `networks: [default]` on the service — and no top-level declaration, since compose creates that
  network itself. Unraid's Container mode writes `network_mode: container:<name>`, or
  `service:<name>` when the target is in the same stack, instead of being mistaken for a named
  network; a fixed IP or MAC is dropped with the same warning `host`/`none` already give, not a
  second mechanism.

  **The `container:<name>` spelling was established, not guessed** — Unraid's own Add Container form
  matches `/^container:(.*)/` against the template's `Network` field, and its Docker client tests the
  same prefix. Verified on the live server, not from a memory of GitHub.

  `tests/ca_convert.js` moved 236 → 245, the only expected movement in this plan, and it reconciles
  exactly: two existing assertions rewritten in place (both about `bridge` emitting nothing, which is
  precisely what changed) plus eleven new cases. Nothing was lost or quietly relaxed.

  **Inert for everything already on the box.** None of the 86 templates there says plain `bridge`;
  they are named networks, `host`, or nothing this touches. The explicit `default` only shows up on
  newly installed apps.

`PLAN_63` is complete and filed. Sections 4a, 4b and 6 were rewritten after Adrian read the first
draft; where they disagree with anything earlier in this file, they win.

Raised by Adrian 2026-08-21, from a real install: an app arrived on the default network, and putting
it on `br0` instead meant declaring that network at the top of the file by hand and only then
attaching the container to it — four steps, in two different parts of the form, with nothing saying
so. What he tried instead was the service's own Networks group, which offers only `default`.

---

## 1. What this builds, in one paragraph

The dropdown on a service's network row learns about the networks this server actually has, not just
the ones this file already declares. Picking one that the file does not declare yet declares it — as
an existing network the file did not create — and attaches the container, in a single move. `host`
and `none` are not networks to join and are routed to the setting that does mean that, instead of
being offered as something to declare.

## 2. What was confirmed in the code, 2026-08-21

Read, not assumed. Everything this needs is already present; almost none of it is new machinery.

- **The page already knows every network on the server.** The state refresh fills `ALL_NETS` (name
  and driver, verbatim) and `netPresent`/`netDriver` beside it. Nothing new has to be fetched, and
  no new endpoint is needed.
- **The stack-level Networks row already offers them.** A declared network's own name box is an
  ordinary dropdown built by `netChoices()` from `ALL_NETS`, plus a sentinel meaning "type a new
  name". So the good behaviour already exists — one level up from where it is wanted.
- **The service-level row is the narrow one, and its source is a single function.** A service's
  network entry carries `from: 'networks'`, so `choiceFor()` hands it to `fromChoice()`, which lists
  `MODEL.declared.networks` and nothing else. **This is the one change point.**
- **`default` is synthesised, not declared.** `refNames()` in the compose model appends `default` for
  networks whether or not the file says it. That is exactly why a freshly converted app shows one
  option and no others — the file declares nothing, so the synthetic entry is the whole list.
- **Declaring a network is already one call.** `declareNetwork()` in the compose model adds the entry
  under `networks:` and hangs `external: true` on it, refusing rather than guessing when the block is
  written in a way it cannot add to. It already returns the declaration's line.
- **The advice route already exists.** A service referencing a network the file does not declare
  already draws advice with an "Add it to this file" button, wired to `declareNetwork()`. So the
  repair path is built; what is missing is any way to *reach* that state from the dropdown.
- **The `network_mode` conflict is already guarded, on one side only.** Adding a network to a service
  that already sets `network_mode` is refused with an explanation, because compose forbids both. The
  reverse guard was removed when `network_mode` left the form.

## 3. What is deliberately NOT changed

- **`bridge` still writes nothing.** The converter treats an Unraid template's `bridge` as compose's
  own default network, which is what it is, and that is correct — see the discussion under `PLAN_63`.
  This plan is about the step *after* that: changing your mind about which network to be on.
- **No new endpoint, no new fetch.** The list is already on the page.
- **The stack-level Networks group is untouched.** It already does the right thing.

## 4. Files touched

| File | Change |
|---|---|
| `javascript/stacks.js` | `fromChoice()` gains a networks branch; the change handler declares on pick; `host`/`none` routing; the advice wording |
| `javascript/compose-model.js` | Only if `declareNetwork()` proves not to cover attaching too — see work item 4 |
| `docs/` | One paragraph, once the behaviour is settled |

No new files.

## 4a. Decisions taken 2026-08-21, after the plan was first written

Adrian, on reading it and looking at what his own templates actually say.

1. **`default` is a first-class option in the dropdown**, named and selectable — not an absence you
   have to know about. Unraid's own list shows Bridge, Host, Container and None; nothing there is
   invisible, and an option that only exists by not being there is the hardest kind to learn.
2. **A template that defines no network is treated as `default`.** Same meaning as today; the change
   is that it is *said* rather than assumed.
3. **One dropdown covers both a network and a network mode.** See section 6, which is rewritten
   below — this replaces the "route them elsewhere" answer the first draft gave.
4. **Open: whether the converter writes the `default` line into the file it generates.** Adrian's
   argument for writing it: absence normally means "not there", so using absence to mean "the usual
   one" is a shorthand, and shorthand is hardest for someone starting from nothing. The counter is
   only noise. **Recommend writing it**, for one more reason found on the server: asked for its own
   canonical version of a file that says nothing about networks, `docker compose config` **writes the
   default network out explicitly**. The long form is compose's own preferred spelling; StaXX would be
   agreeing with it, not inventing a house style. Verified on the box that a service may name
   `default` with no declaration anywhere, and that the compose model already exempts that name from
   its own undeclared-reference check, so it produces no warning either.

**What his 86 templates actually say**, counted on the server and worth recording because it changes
where the value is: 47 `mybridge`, 26 `br0.2`, 6 `host`, 6 `eth0.2` — and **not one plain `bridge`**.
Every one of those is already written out in full by the converter. So the silence only affects
*newly installed* apps from the Apps page, which mostly ship as `bridge`. Smaller than it looked, and
it makes the case cleaner: `bridge` is the single case inconsistent with everything else the converter
already does.

## 4b. A converter bug found while checking this

The converter treats anything that is not `bridge`, `host` or `none` as a **named network**. Unraid's
fourth standard option is **Container** — share another container's network stack entirely: same
address, same ports, no separation between them, which is how a container is routed through a VPN.
That is a *mode*, not a network, and compose expresses it as `network_mode: container:<name>`
(or `service:<name>` for one in the same stack, which compose then orders the startup around by
itself — confirmed on the server).

Coming through as a named network, it would produce a file naming a network that does not exist.
**Zero of Adrian's templates use it**, so this is not urgent, but it is wrong. Fix it here.

## 5. The dropdown, precisely

One list, in three parts, in this order:

| Group | What is in it | Written as |
|---|---|---|
| This file's own | Every name under `networks:`, plus the synthetic `default` | The name, unchanged |
| On this server | Every entry in `ALL_NETS` **not** already declared and not `bridge`/`host`/`none` | The name, labelled so it is clear the file does not know about it yet |
| — | `host` and `none` | **Not offered here at all** — see below |

Three rules:

1. **`default` stays first.** It is what a stack gets when it says nothing, it is right for almost
   every app, and demoting it would push people off the correct answer.
2. **A server network already declared appears once, in the first group.** It is in the file now, so
   it is no longer "one of the server's" from this row's point of view. `netChoices()` already makes
   exactly this exclusion for the stack-level row; the same rule, not a second one.
3. **`bridge` is not offered.** Docker's shared `bridge` is not a network a compose service should be
   attached to by name, and it is not what Unraid's "bridge" means either — compose's `default` is.
   Offering it would hand people a worse version of what `default` already gives them.

## 6. One dropdown, two compose keys

**This replaces the first draft's answer, which was to leave `host` and `none` out and point at
another control. Adrian's answer is better and is what gets built:** one dropdown, and the form
writes whichever key is actually correct for what was picked.

| Picked | What is written | What is removed |
|---|---|---|
| `default`, or any network | `networks:` on the service, plus a declaration if it is one the file does not have yet | any `network_mode` on that service |
| `host`, `none` | `network_mode:` on the service | **every** network row on that service |
| Share another container | `network_mode: service:<name>` in-stack, `container:<name>` otherwise | every network row on that service |

Compose forbids a service having both, which is why each row of that table has a second half. The
form already knows this and already refuses one direction of it; the point here is to **do the right
thing instead of refusing**, because the person has just said plainly what they want.

**Why this does not contradict the decision recorded in `PLAN_34`.** That plan asked a different
question — *should a `network_mode` found in a pasted file be silently converted into a network
list?* — and answered no, correctly: four of its six value families have no network-list equivalent
at all, so converting would take a file saying "share the server's network" and turn it into one
joining a network called "host". **Nothing here is silent or automatic.** The person picks, in full
view, and the form writes the key that matches. The earlier decision stands untouched, and
`PLAN_34`'s own closing line — that the honest treatment is *an offer, never automatic* — is what
this is.

**The one thing to get right:** a network is a list and a mode is not. Picking `host` on a service
that has three networks must clear all three, not just the row that was clicked. That is a bigger
edit than it looks like from the row, so it is confirmed before it happens and covered by one undo
entry. **Never leave a service holding both** — a file compose refuses is worse than either choice.

### Where the control lives, and what happens to the networks it displaces

Decided 2026-08-21.

**The existing Networks list row carries the dropdown** — no separate single control. Smaller change,
and it keeps one place to look.

**Setting a row to a mode sets the other networks aside; it does not delete them.** Compose forbids a
service holding both, so they must come out of the service — but they are kept, in the service's own
`x-unraid` block, and put back in one click if the mode is changed back. This is Adrian's answer and
it is a good one: undo only helps inside one sitting, and the case that actually bites is switching
to host mode, saving, and coming back a week later to a fixed address nobody wrote down.

Three things that make it safe rather than clever:

- **It cannot break the file.** Compose ignores `x-` keys, so the stash rides along in a file that
  still runs anywhere under plain `docker compose up`. Rule 1 holds.
- **It is inert.** Nothing reads it except someone choosing to switch back. It is a clipboard, not a
  description of anything, so it cannot disagree with the file the way a sidecar would — which is
  what keeps it clear of the second-source-of-truth rule.
- **Restoring validates what it finds.** That block is as hand-editable as any other part of the
  file. A stash that no longer makes sense is reported and dropped, never written back blind.

**The set-aside networks are NOT shown as greyed-out rows.** This was considered and rejected. A
disabled row reads as file content that is switched off, when in truth it has been removed — and the
form's own standing rule is that showing something other than what the file says makes it a form
nobody can learn compose from. Instead: **one line beneath the mode row**, saying the networks were
set aside, naming them, with a way to put them back. Nothing on screen claims to be in the file when
it is not, and nothing is lost.

**This adds a key to `schema/x-unraid.schema.json`,** and it is the first time that block holds
displaced compose configuration rather than a description of something. Document it in
`docs/x-unraid-schema.md` as exactly that — a clipboard, written only by the form, read only on an
explicit restore — so the next person does not read it as a precedent for keeping a second copy of
things.

## 7. Declaring on pick

Picking a server network the file does not declare must do both halves. The alternative — write the
reference, let the existing advice appear, make the person press "Add it to this file" — is the
four-step dance this plan exists to remove, one step shorter.

- One undo entry covers both writes. Half of this on the undo stack is worse than neither.
- `declareNetwork()` refuses rather than guessing when `networks:` is written in a way it cannot add
  to. On a refusal, **write nothing at all** — not the reference either — and say so in the status
  line, in the words the form already uses when a list cannot be added to.
- The declaration carries `external: true`, because the network already exists and compose must not
  try to create it. That is `declareNetwork()`'s own behaviour and the reason it is the right call
  to make here.
- The existing advice and its button stay. They are the repair path for a file that arrived with a
  dangling reference — a hand-written one, or one this refused to finish — and that case does not go
  away.

## 8. Work items, in order

### Phase A — the dropdown

1. `fromChoice()`: when `f.from === 'networks'`, append the undeclared server networks after the
   declared ones. Reuse the exclusion rule `netChoices()` already applies rather than writing a
   second one; if that means lifting a small shared helper out of `netChoices()`, lift it — do not
   copy it.
2. Label the second group so the difference is visible in a plain `<select>`. Optgroups if the box
   already supports them, a suffix on the label if not — check what `boxHtml()` actually renders
   before choosing, and do not add a rendering mode for this alone.
3. Exclude `bridge`, `host` and `none` per section 5.

*Check:* on the server, a freshly converted app's service network dropdown lists `default` first and
then the box's own real networks; a stack that already declares one shows it once, not twice.

### Phase B — declaring on pick

4. The change handler: when the picked name is a server network the file does not declare, push one
   undo entry, call `declareNetwork()`, and write the reference — in that order, so a refusal from
   the first leaves the file untouched. Confirm whether `declareNetwork()` plus the ordinary list
   write is genuinely enough, or whether the model needs one call that does both; **prefer two calls
   from the form over a new model function** unless the re-parse between them makes that unsafe.
5. On refusal: pop the undo entry, write nothing, and reuse the existing "that list is written in a
   way the form cannot add to" wording.

*Check:* picking a real network on a converted app writes both halves, one undo puts both back, and
the file still round-trips — comments, ordering and anchors intact.

### Phase C — the modes, and the set-aside

6. The dropdown writes `network_mode` for `host`, `none` and sharing another container, removing
   every network row on that service as it does — one confirmation, one undo entry. Picking a network
   from a service that has a mode does the reverse. **A service must never be left holding both.**
7. The set-aside block in the service's `x-unraid`, its one-line note and its restore, per section 6.
   Restoring validates what it finds and drops a stash that no longer makes sense.
8. `schema/x-unraid.schema.json` and `docs/x-unraid-schema.md`: the new key, documented as a
   clipboard rather than as a description.

### Phase D — the converter

9. Templates with no network stated are `default`, and the generated file **says so** (section 4a
   item 4).
10. Unraid's Container mode becomes `network_mode: container:<name>` — or `service:<name>` when the
    target is in the same stack — instead of being mistaken for a named network (section 4b).
11. `node tests/ca_convert.js` numbers will move here, and that is expected for the first time in
    this plan. Every moved number must be accounted for one by one.

## 9. Tests

Local:

- `node --check` on `stacks.js` **and** `node tests/js_undeclared.js`. Both — the second catches what
  the first cannot see, and one such line in a function every render calls kills the page silently.
- `node tests/yaml_roundtrip.js` over the fixture corpus. This plan writes into `networks:`, which
  is a hand-authored block in several fixtures, so the never-destroy-a-file promise is genuinely in
  play here in a way `PLAN_63` never was.
- A throwaway node probe: declare-then-attach against a file whose `networks:` block is written in
  each awkward shape the corpus has, asserting either both writes or neither.

On the server: the dropdown's contents against the box's real network list, and the two-write
behaviour through the actual form.

## 10. Risks

| Risk | Standing |
|---|---|
| Half a write lands — a reference to a network never declared | The thing this must not do. One undo entry, declare first, write nothing on refusal. Covered by the Phase B check. |
| A `networks:` block the writer cannot add to | Already handled by `declareNetwork()` refusing. The new part is making sure the form refuses *with* it rather than continuing. |
| Someone picks a network that later disappears from the server | Same as today for any external network: compose refuses to start and says which one. Not made worse here. |
| `external: true` on something that was not external | `declareNetwork()` always writes it, and every name this offers came from the server, so it is true by construction. Worth re-checking if this dropdown ever grows a "create a new one" entry. |
| The corpus loses a comment or an anchor | The real risk of this plan, and the reason the round-trip suite is not optional here. |

## 11. Against `PLAN_63`

No overlap in subject. They share `javascript/stacks.js` and nothing else, and in different parts of
it — `PLAN_63` at page load, in the app-form entry and in the save; this one in the form's own choice
and change handling. **Sequential, not parallel**, for the same merge reason `PLAN_63` gives about
`PLAN_62`.

`PLAN_63` goes first: it is already part-built and deployed, and this plan is what someone reaches
for *after* an app has been brought in — which is the order they happen in.
