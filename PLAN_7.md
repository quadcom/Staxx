# Future feature notes: editors for the Advanced blocks

**Status: PARTLY BUILT.** `PLAN_8.md` (complete) built sections 1 and 2 and removed the shared blocker
below — `addNested()` inserts a nested key at any depth, so a `healthcheck:` or long-form `depends_on:`
can now be created from nothing. What is left here is section 3 (which of `deploy:` to refuse to offer),
the boolean-quoting decision, and the CRLF note at the end.

## Where this comes from

`PLAN_4.md` phase 1 made every compose key visible: a key the form has no control for now appears as
a read-only block that shows itself and says why. That is honest, and for most keys it is enough.

Three blocks are not most keys. They are the ones an Unraid user actually wants to change, and
leaving them as grey code blocks means the Compose view is still mandatory for ordinary work:

| Block | Now | Still read-only |
|---|---|---|
| `healthcheck:` | timings and `test:` editable, creatable from nothing | a `test:` the reader cannot make sense of |
| `deploy:` | CPU and memory limits editable, creatable from nothing | the swarm-only keys — section 3 below |
| `depends_on:` | both forms editable, long form creatable from nothing | the inline flow map (`db: {condition: …}`) |

`PLAN_5.md` covered the *leaves that already exist* in a file; `PLAN_8.md` covered the rest — editing a
block's awkward parts, and creating a block that is not there at all. What is left is section 3.

## The shared blocker — resolved

Everything below needed one thing the model did not have: **inserting a nested key**. `addItem`
inserts a list or map entry one level inside a service and `addSetting` inserts one line at service
indent, but neither can create an intermediate map, so `healthcheck:` → `test:` could not be written
from scratch.

`PLAN_6.md` phase 5 built `addDeclared`, and `PLAN_8.md` generalised it into `addNested(doc, form,
service, path, value)`, which walks a key path of any depth and creates each missing level. It
restarts its walk from the top after each level it creates, because `splice()` re-parses the document
and every position held before it is stale the instant it returns.

That is the primitive. Anything else needing a nested insert calls it rather than writing a third way.

## 1. `depends_on:` long form

```yaml
depends_on:
  db:
    condition: service_healthy
    restart: true
    required: true
```

The natural form is one row per dependency: the service name from the `services` namespace as a
dropdown, `condition` as a dropdown, the two booleans in a `<details>` fold.

- `condition` is a closed set — `service_started`, `service_healthy`,
  `service_completed_successfully` — so it is a dropdown with real plain-English labels, not a text
  box. `service_healthy` only means anything if the other service *has* a `healthcheck:`, and the form
  knows whether it does, so it can say so rather than let someone pick a condition that never comes
  true. That is the piece a hand-edited file gets wrong most often.
- **The service name is a key, not a value.** `collectServiceRefs()` (:1483) already handles exactly
  this for renames and comments on it — reuse `keySpot()`, do not invent a second way.
- The inline flow form (`db: { condition: service_healthy }`) is sealed as `'flow'` and stays
  read-only. Worth leaving alone rather than teaching the parser to open flow maps.
- Short form (`- db`) and long form are alternatives, and `PLAN_4.md` phase 2 already makes the short
  form an editable list. **Edit whichever form the file uses; never convert one to the other.**
  Offering "add a condition" on a short-form entry would mean rewriting the block, which is the sort of
  helpfulness that loses someone's formatting.

## 2. `healthcheck.test:`

Three shapes, all common:

```yaml
test: ["CMD", "curl", "-f", "http://localhost/"]      # flow seq — sealed today
test: ["CMD-SHELL", "pg_isready -U appuser"]
test: curl -f http://localhost/ || exit 1              # a bare string means CMD-SHELL
```

The first element is a mode, not an argument: `CMD` runs a program directly, `CMD-SHELL` hands one
line to a shell, `NONE` switches the check off. So the control is a mode dropdown plus either an argv
list or a single text box — and the mode is the thing worth explaining, because picking `CMD` with a
shell line in it is the usual reason a health check never passes.

**`commandSay()` from `PLAN_3.md` already does the hard half.** It explains an argv structure in plain
English, handles all three of flow, seq and block-scalar, and its splitter is already written. A test
editor should show that sentence under the control rather than growing a second explainer.

The obstacle: a flow sequence is **sealed** (`scanValue`, reason `'flow'`), so it cannot be edited at
all today. Two ways out, and the choice matters:

- **Teach the parser to open a flow sequence.** Correct, and reusable — `command:` and `entrypoint:`
  are sealed for the same reason and would become editable too. Also the riskiest thing in these
  notes: flow scalars have their own quoting and escaping rules, and getting them wrong corrupts a
  line rather than refusing to touch it.
- **Replace the whole `test:` line** when the user edits it. Much smaller. It rewrites a line the user
  wrote, which the never-destroy rule normally forbids — but only the line being edited, which is what
  every other write already does. The honest cost is losing their spacing and quote style on that one
  line.

The second is the smaller version and probably the right first move. Say so in the plan when it is
written, rather than reaching for the parser change because it sounds more complete.

## 3. `deploy:` — and what to refuse to offer

`PLAN_5.md` covers `resources.limits` and `resources.reservations`. The rest of `deploy:` is
`replicas`, `placement`, `update_config`, `rollback_config`, `restart_policy`, `endpoint_mode`, `mode`.

**Some of it does nothing on an Unraid box, and some of it does — the split is not the obvious one.**
Checked against the test box's compose 2.40.3 rather than assumed, with
`docker compose up -d --dry-run` over a file carrying `replicas: 3` and a
`node.role == manager` placement constraint:

| Key | Outside swarm |
|---|---|
| `resources.limits`, `resources.reservations` | **honoured** |
| `replicas` | **honoured** — the dry run planned `a-1`, `a-2`, `a-3` |
| `restart_policy` | honoured; overlaps `restart:` |
| `placement` | **ignored** — proven by the same run scheduling all three containers despite a constraint no non-swarm node satisfies |
| `update_config`, `rollback_config`, `endpoint_mode`, `mode` | swarm only |

So this is **not** a blanket "leave `deploy:` alone" note, as an earlier draft of it wrongly said.

- `replicas` is genuinely useful and already editable via `PLAN_5.md`'s uncovered-child pass, since it
  is a plain scalar. Nothing to do.
- The **swarm-only** keys are the ones not to grow controls for. A box that looks like it works and
  does nothing is the exact failure this project is meant to avoid. Leave them read-only, and consider
  an `advice` note — the mechanism `PLAN_4.md` phase 1 already built — saying compose ignores them
  outside swarm. That sentence is more use than a control would be.
- `restart_policy` overlaps `restart:` in the Container group, which the form already offers. Two
  controls for one idea is worse than one, so if it is ever wanted, decide which wins first.

**A conflict worth catching, in the same shape as `network_mode` versus `networks`.** Compose refuses a
service with `container_name` set **and** `replicas` above 1 — one fixed name cannot be given to three
containers. `container_name` is one of the four the Container group always shows, so the form can walk
someone straight into it, exactly as it used to with `network_mode`. `PLAN_4.md`'s `excludes` mechanism
and its `blocked` flag already express this; it wants a second condition rather than new machinery.

## A decision waiting on Adrian: should a plainly-written `true` stay plain?

Found while building `PLAN_6.md` phase 4, and **partly fixed already**.

`needsQuoting()` (compose-model.js:159) forces quotes on any value matching
`/^(true|false|yes|no|on|off|null|~)$/i`. So editing `privileged: true` to `false` writes
`privileged: 'false'` — the file gains quotes it never had.

**What is already done.** `setPart()` now writes nothing when the value is unchanged, so merely opening
a form, or setting a box to what it already says, can never rewrite a line. That closed the
round-trip hole and is right regardless of the decision below. A *genuine* edit of a boolean still
gains quotes.

**How much this matters, measured rather than assumed.** Compose 2.40.3 on the test box **coerces** a
quoted boolean: `privileged: 'true'` and `privileged: true` both resolve to `privileged: true`. So
nothing breaks and no container behaves differently. It is a formatting change on one line — but rule 2
says a normalised round-trip over someone's file is a bug, not a trade-off, so it is still a bug.

**The fix, if wanted:** drop `true|false` from that list and keep `yes|no|on|off|null|~`. The comment
two lines above already argues for it — *"a number written plainly is read as a number, which is what
someone typing a number into a number field means"* — and a plainly-written `true` is read as a boolean
by every YAML version, which is what the file's author meant. `yes`/`no`/`on`/`off` genuinely deserve
their quotes: YAML 1.1 reads them as booleans and YAML 1.2 as strings, so they really do "read as
something other than themselves" depending on who is parsing.

**The cost:** someone typing the word `true` into a box that previously held ordinary text would get an
unquoted `true`, which compose then reads as a boolean. For an `environment:` value compose warns about
a non-string value; elsewhere it is usually what they meant anyway. Weigh that against rewriting a line
in a hand-authored file, which is the thing this project promises not to do.

## Also outstanding, unrelated to these blocks

**A CRLF compose file is rejected whole.** The parser seals the document and the form says *"This file
is written in a way the form cannot read"*, which does not name the cause, so there is nothing for the
user to act on. YAML treats CRLF as a valid line break and `docker compose up` accepts such a file, so
the plugin is currently stricter than Docker and refuses a file that works. Anyone editing their
compose file in Notepad on Windows lands here.

Preserving CRLF on write is the correct answer rather than normalising to LF, because normalising
rewrites every line of a file the user did not ask us to reformat. Its own small plan when wanted.
