# PLAN_22 — Say what Image means when a service builds its own

**Status: COMPLETE, 2026-08-16.** Built, deployed and checked in the browser on the test box.
1013 round-trip assertions (up from 1002), 154 converter, schema and lint clean, `php -l` clean.

Verified on a four-service fixture, all of which compose accepts (`exit 0`):

| service | file says | Image row |
|---|---|---|
| `named` | `image:` + block `build:` | *"this names the image built here, rather than one to pull"*, **editable** |
| `unnamed` | block `build:` only | *"this service builds its own image, so this is optional"*, editable |
| `short` | `image:` + `build: ./app` | the new note, **and now in the Build group** — the routing fix |
| `plain` | `image:` only | no note, no Build group |

One worry that turned out to be unfounded: a short-form `build: ./app` does **not** also draw the
three blank `context`/`dockerfile`/`target` rows beside it. `harvestBlock`'s first line hands a
non-map value straight to `settingTarget()` and returns, so the short form is one row reading
`Build = ./quick`. No duplication, nothing to special-case.

The fixture's compose file was byte-identical after the whole session.

## Context

Adrian asked whether ticking Build should disable the Image field. **It should not**, and the
form's own precedent says why.

`blocked` — the mechanism that greys out `network_mode` when a service has `networks:` — exists
for one stated reason (`completed-plans/PLAN_4.md:172`): *"Compose refuses a service with both of
these, so an empty slot here is a trap: filling it in would make a working file invalid."*
Measured against compose v2.40.3 on the test box, via stdin so nothing was written:

| | |
|---|---|
| `network_mode:` + `networks:` | **exit 1** — *"service web declares mutually exclusive `network_mode` and `networks`"* |
| `image:` + `build:` (block form) | **exit 0**, both kept in the resolved output |
| `image:` + `build: ./app` (short form) | **exit 0** |
| `image:` + `build:` with `target:` | **exit 0** |

So there is no trap. `build:` says *how* to make the image; `image:` names the result. Without it
Docker invents `<project>-<service>`, so `image:` is the only way to get a predictable name — and
the only way to push it anywhere. Disabling it would refuse a valid and useful pattern.

**Three further reasons it would be the wrong move**, beyond breaking that pattern:

- `blocked` is driven by **what the file contains**, never by a tick box — `harvest()`
  (`compose-model.js:1532`) receives only `(serviceMap, lines)` and has no access to tick state
  at all. Wiring a field's editability to a UI toggle would be a new kind of coupling.
- A file already carrying both would render Image **disabled and uneditable**, and the only route
  back would be to untick Build — which stashes the whole build block into `x-unraid.sections`.
  A destructive path to recover from a cosmetic decision.
- `boxHtml()`'s `dead` path renders `disabled`, not `readOnly` (`stacks.js:1546`), so the value
  could not even be selected and copied. The sheet's own note on that trade-off
  (`stacks.js:5837`) says copying a value out is most of the reason for showing it.

**What happens today:** ticking Build writes nothing to the file — it shows three blank rows, and
typing into one is what creates `build:`. Image is untouched by the tick in every respect. The
"builds its own image" note keys off `serviceMap.pairs['build']`, the file's own key, so it does
not appear until something is actually typed. All of that is correct and stays.

What is missing is only this: when a service has `build:` **and** Image has a value, nothing says
that Image's role has changed.

---

## The work

### 1. Make the Image note role-aware

`compose-model.js:1556` currently notes the empty case only:

```js
if (akey === 'image' && !akeyVal && serviceMap.pairs['build']) {
  akeyTarget.advice.push('this service builds its own image, so this is optional');
}
```

Add the other branch — same `advice` channel, same lower-case fragment style as its neighbours:

- `build:` present, Image **empty** → unchanged
- `build:` present, Image **set** → `'this names the image built here, rather than one to pull'`

No new mechanism; `adviceBlock()` already renders it under the boxes and blocks nothing.

### 2. Fix the one bit of prose that says otherwise

`DESCRIPTIONS.service.build` (`compose-model.js:4614`) reads *"Builds the image from a Dockerfile
**instead** of pulling a ready-made one…"*. That "instead" is the only text in the repo implying
the two are alternatives, and it is what the editor shows in its key help — quite possibly what
prompted the question. Reword so it says that `image:` alongside names what gets built.

Keep the trailing full stop: `helpGaps()` asserts `/\.\s*$/` on every description and will fail
the suite otherwise.

### 3. A real bug in what just shipped

`groupFor()` (`stacks.js:312`) routes build leaves with `/^build\./`. A **short-form**
`build: ./app` produces the target `build`, with no dot — so it does not match. Consequences:

- the short form lands in **Advanced** while the block form gets the **Build** group — two
  spellings of one key in two different places
- `flagFor()` returns null for it, so `fileFlagCounts()` counts zero and **the Build tick does not
  turn itself on** for a service that plainly has a build

`logging` already has this right one line above: `/^logging(\.|$)/`. Match it — `/^build(\.|$)/`.
Existing tests (`tests/yaml_roundtrip.js:5915`) only assert the field exists and is editable, not
which group it lands in, which is why this got through.

### 4. Tests

`tests/yaml_roundtrip.js`, same `ok()` harness:

- `build:` + Image **set** → advice contains the "names the image built here" note; the empty-case
  note is **absent**
- `build:` + Image **empty** → unchanged note, and the new one absent
- **no** `build:` → neither note, whether Image is set or empty
- neither note ever sets `f.locked`, `f.blocked` or `f.fixedRequired` — Image stays editable, which
  is the whole point of this change
- a short-form `build: ./app` lands in the Build group, not Advanced (the bug above; assert the
  group, since asserting only the field is what let it through)

## Verification

```sh
node tests/yaml_roundtrip.js
node tests/js_undeclared.js
node --check .../javascript/stacks.js
node --check .../javascript/compose-model.js
```

On the test box, after `pscp` + `dev-install.sh`: a service with `image: ghcr.io/me/app:dev` and a
`build:` block — Image is **editable**, carries the "names the image built here" note, and Save is
enabled. Then clear Image and confirm the note switches to the "so this is optional" wording. Then
a short-form `build: ./app` and confirm it appears under **Build**, with the tick already on.

## Left out

- Disabling or blocking Image under any circumstance.
- The editor dialog collapsing to 714px on a content-light form — measured as pre-existing in
  `PLAN_21.md`, still unaddressed, still not this plan's business.
