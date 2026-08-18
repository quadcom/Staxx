# PLAN_16 — The file's own names, and the long ways of writing things

**Status: COMPLETE (2026-08-17).** Both parts are built, tested and deployed — see
`completed-plans/PLAN_30.md`, which carries the approved plan, what changed while building it, and
the one claim below that turned out to be wrong (a map-form `networks:` was locked, not dropped).

The two areas `PLAN_15.md` deliberately left out, written up so they were not lost. They were
independent of each other and could be built in either order, or separately.

Both come from `comp-autocomplete.md`, whose claims about them I have checked against the code —
all of them hold, and the specifics are quoted below.

---

## Part A — The editor offers names the file itself declares

### What you would notice

Type `depends_on:` and a `- ` beneath it, and nothing is offered. The form, in the same situation,
gives you a dropdown of the other services in your file. The same gap exists everywhere one part of
a compose file refers to another part by name: which networks a service joins, which secrets and
configs it can read, which profiles it belongs to, and the `service:<name>` forms of network mode,
IPC and process namespace.

These are the names you are most likely to get wrong, because they are yours — a typo in `always`
is caught by anyone reading it, a typo in `medi-server` is not.

### Why it is not simply the form's list

The form builds its list from `MODEL.declared`, which needs a **successful parse of the whole
file**. The editor must never depend on one: the section comment in `compose-model.js` explains that
the file is mid-edit exactly when a suggestion is wanted, which is when a parse has least to say. A
file with one unclosed quote three services below would silently stop offering names.

So this needs a scan that works from line classification alone.

**The scan to write is the complementary half of one already written.** `hostPaths()` walks a
mapping-key stack using nothing but `classify()`, and its own comment notes that it deliberately
never descends into the top-level `volumes:` block — which is precisely the block this needs. Read
that function before starting; it is the pattern, and it is already proven against a fuzz corpus.

### What it collects

One pass returning the file's own namespaces: service names, and the names declared under top-level
`networks:`, `volumes:`, `secrets:` and `configs:`. Profiles are different — they have no
declaration block, so every service's `profiles:` list has to be gathered instead, the same way the
form's `fileProfiles()` already does it.

### Where the names get offered

| Written as | Offer |
|---|---|
| `depends_on:` list items, and its long form's keys | other service names |
| service-level `networks:`, `secrets:`, `configs:` list items | that namespace's declared names |
| `profiles:` list items | every profile named anywhere in the file |
| `network_mode: service:…`, `ipc: service:…`, `pid: service:…` | other service names, after the prefix |
| a volume entry's host half | declared volume names |

### This is where "closed head, open tail" finally earns its keep

`PLAN_15` dropped the survey's `head`/`tail` idea because nothing needed it — `on-failure:3` works
by the list simply not matching. `service:<name>` is different: the head is closed and the tail is a
real list this scan can supply, so `network_mode: service:` should offer the file's services. That
is the first genuine case for it, and it should be built here rather than earlier.

### Two rules that currently live in the form and must not be forgotten

Both are in `stacks.js` today, small enough to be easy to miss and wrong enough to matter:

- **`default` is offered as a network even when the file never declares one**, because compose
  creates that network itself regardless.
- **A service is never offered in its own `depends_on`**, and likewise never in its own
  `service:<name>`. A service cannot depend on, or share a namespace with, itself.

Decide deliberately whether these move into the model, so both views read one rule, or are
duplicated. Moving them is better and is the reason to look.

### The risk

The scan runs on every keystroke, so it must be cheap and must never throw on a half-typed file.
`hostPaths()` already sets the standard for both, including its `try/catch` returning an empty
result rather than an error.

---

## Part B — Long forms are second-class citizens

### What you would notice

A compose file may write a port either shortly (`8080:80`) or at length, as a block with `target:`,
`published:`, `protocol:` and `mode:` on their own lines. Both are ordinary, correct compose. The
form handles the short way properly and the long way badly.

Four separate faults, all verified:

- **A long-form port loses its protocol and its mode.** The harvest reads `protocol` only to build
  the row's internal key, never as something you can edit, and never reads `mode` at all. Both
  vanish from the form.
- **A long-form port with no `published:` disappears entirely.** The harvest bails the moment
  `target:` is absent, so the entry is not shown as a row at all — it is simply missing, while
  staying in the file. **This is the worst of the four**, because a row that is not there cannot
  tell you it is not there.
- **A long-form volume keeps only its source, target and read-only flag.** `type`, the whole
  `bind:` block, `tmpfs:`, `consistency` and `subpath` are invisible.
- **`secrets:` and `configs:` are declared as plain lists**, so writing an entry as a block —
  which is how you set `uid`, `gid` or `mode` — locks the row with *"this entry is written as a
  block of its own"*.

And one more, different in kind: **a service's `networks:` written as a map is dropped silently.**
The map form is how you give a service a fixed IP address on a network, so this is not exotic.

### Why this is riskier than Part A, and how to hold it down

Part A only adds suggestions; nothing it does can change a file. Part B changes **what the form
harvests**, and therefore what it writes back. Every fault above is fixed by giving the form more
fields, and every new field is a new way to write to someone's file.

The guard already exists and is the strongest test in the suite: the **null edit** — set every box
to the value it already holds and demand the input back byte for byte. Every shape fixed here needs
one, written before the fix, so it is proved that the new fields do not disturb the file when left
alone.

`PLAN_15` phase 3 used a second check worth repeating: dump the form's whole field set before and
after and compare. Here the set is *supposed* to change, so the dump is a list to read and agree
with, not an equality to assert.

### The order to do it in

1. **The vanishing long-form port first.** It is the only one where the form is actively
   misleading rather than merely incomplete, and fixing it is a lock, not a new field: show the
   entry as a locked row saying why, instead of dropping it.
2. Then long-form port `protocol` and `mode` as real fields.
3. Then long-form volumes, which is the largest piece and mostly mechanical once the shape above is
   settled.
4. Then map-form `networks:`, then long-form `secrets:`/`configs:`.

Each step is separately deployable and separately verifiable. Do not do them in one pass.

### What falls out for free

`PLAN_15` left four vocabularies unwritten because nothing could reach them — long-form ports'
`protocol` and `mode`, long-form volumes' `type`, `bind.propagation` and `bind.selinux`. Once the
form harvests those fields they become reachable, and since both views read one registry, writing
them then serves the editor as well. The values are already listed in `comp-autocomplete.md`.

---

## Verifying

The usual suite before every deploy, and each part deployed to the test box as it finishes:

```sh
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
node --check src/stack.manager/.../stacks.js
node --check src/stack.manager/.../compose-model.js
python tests/validate_schema.py
```

For Part A, in the browser: a `- ` under `depends_on:` offers the other services and never the
service itself; `networks:` offers the declared ones plus `default`; `network_mode: service:` offers
services; a file with a deliberate syntax error further down still offers names.

For Part B: open a file holding one of each long form, check every part of it appears on the form,
change one thing, and confirm the rest of the file is untouched.
