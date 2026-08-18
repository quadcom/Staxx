# PLAN_35 — bringing containers you already run into StaXX

**Status: DRAFT v2, awaiting Adrian. Do not start.** Written 2026-08-18, rewritten the same day
after a five-agent verification pass and three adversarial reviews. **Version 1's central safety
promise was false for one of the three sources**, and four of its five "work to do" items for
another source turned out to be already built.

## The thing to read first

Version 1 said: *"Import never touches Docker"* and *"an imported stack reads as stopped even while
its container runs."* For Compose Manager projects **both are false**, and not by a little.

Nothing in this plugin ever passes a project name to compose, so compose derives the project from
the stack's directory name. The state lookup tries three keys in turn — full path, then directory
tail, then project name — and an imported copy sitting in a folder named after the source project
**matches the live project on the last two**. The row goes green. And from a green row:

- **Stop** runs `compose down` against the live containers.
- **Remove container** runs `rm --stop --force`.
- **Delete stack** runs `compose down` *before* it asks you anything. The confirmation prompt only
  guards *extra files in the folder*, not the teardown.

So: import a project to look at it, change your mind, click Delete — and your Penpot or Unifi stack
is torn down. That is not a hole in the safety section, it is a hole underneath it.

**Nothing is broken in StaXX today** — running `down` when you delete a stack StaXX owns is correct
and deliberate. It becomes a hazard only the moment this plan creates a folder that shares an
identity with someone else's running containers. Which is why the fix is phase 0 below and not an
afterthought.

## What you would notice

A page listing every container you already run and every template you have, saying where each came
from. Tick the ones you want; StaXX writes each as a compose file in a stack folder and shows you
the form. **Until you say so, nothing changes** — the container keeps running exactly as it is.
Adopting it is a separate, deliberate second step with its own confirmation.

## The three sources, re-measured

Version 1's numbers were taken from one sample. These are from all of them.

| Source | Count | Notes |
|---|---|---|
| Unraid's Docker page | **85 templates** (not 86 — the 86th entry is a `.bak`), **70 containers** | `/boot/config/plugins/dockerMan/templates-user/my-<Name>.xml`; containers labelled `dockerman` |
| Compose Manager | **7 projects** (not 5 — 5 was the *container* count), 5 containers | `/boot/config/plugins/compose.manager/projects/<slug>/`; three projects have never been brought up |
| Neither | **1 container** (`music-assistant`, exited) | not 2 |

**Only 14 of the 76 containers are running.** The list page has to read well when almost nothing is
running — that is the normal case here, not the exception.

Other measurements that change the plan:

- **32 templates carry a fixed IP**, not 26. 26 on `br0.2`; **6 on `eth0.2`, which is not a Docker
  network on that box**. Those six would import into a stack compose refuses to start.
- **10 templates carry a hardware address**, a subset of the 32 — so the IP and MAC halves are
  different-sized problems.
- **628 settings across the templates: 374 variables, 151 paths, 100 ports, 3 devices, and no
  labels at all.** Sixty percent of every import is environment variables, so the Variables section
  is where import quality gets judged.
- **Five pairs of templates claim the same fixed LAN address as each other.**

## Source 1 — Unraid templates

The happy accident holds: a Community Applications catalogue entry and an Unraid user template are
the same `<Container>` document, and StaXX already converts one into a compose file. But version 1
listed five things the converter "has never had to handle", and **four of them are already built**:

- The named network **and** its `external: true` declaration — already written.
- `mac_address` from a template's extra parameters — already written. So `MyMAC` is one variable
  assignment, **not a PLAN_34 dependency**.
- The user's own edited value over the shipped default — already preferred.
- `Required=` and `Mask=` — already carried through as the two short trailing markers the model
  round-trips. Version 1's instruction *"never put it in a comment"* aimed at a problem that does
  not exist: the marker flags the value as secret, it never contains it. **Following that
  instruction would tear out a working mechanism.** Cut from scope.

### What is genuinely missing

1. **The map form for the fixed address** — four lines in the converter. This is the whole of the
   PLAN_34 dependency, and it is a *write* dependency only: PLAN_34 phase 1 is what lets a user
   **edit or add** a fixed address afterwards through the form. **Import can ship first**, with the
   caveat that a freshly imported address is read-only in the form until PLAN_34 lands.
2. **An XML-to-object normalisation step, and it is the most likely way this ships broken.**
   Optional template elements are self-closing when empty — `<PostArgs/>`, `<WebUI/>`, `<Icon/>`.
   PHP's XML reader turns those into an empty *object*, which is truthy, and the converter tests
   them with plain truthiness. That writes `command: "[object Object]"` into 83 files, and a web
   address and an icon to match. **This behaviour is inferred, not proven — test it on the server
   before relying on either outcome.** Coerce every empty element to an empty string.
3. **`Category` has a different shape.** A catalogue entry supplies a list; a template supplies one
   space-packed string like `"Network:Management Productivity: Tools:Utilities"`. Unsplit, that gets
   written as a single category. Pre-split it before calling the converter.
4. **Filter the folder by `.xml`** — the `.bak` parses as valid XML and would be offered as a
   template.
5. **The generated file header says "Converted from the Community Applications template for X"**,
   which is a false statement on every import from this source. Give the converter a provenance
   option.
6. **Three malformed paths.** One template has Path settings whose target is a bare word rather than
   an absolute path, and the converter's concatenation produces a bind mount that is not valid and
   not obviously wrong to read. Detect and flag rather than emit.

### Cut from source 1

**`Display=`.** Version 1 wanted to carry Unraid's basic/advanced marking into the metadata. There
is nowhere to put it: the metadata format has no per-field home *by deliberate design* — its own
documentation says so — and it is a published, additive-only format, so this is a version bump, not
an edit. Worse, the real data has **four** values, not two, and **42 settings on your box are marked
"do not show this at all"** — which collides head-on with the rule that nothing in the file is ever
hidden. If it is wanted later it needs its own plan that answers that question first.

`TailscaleStateDir`, `Shell` and `DateInstalled` can be preserved as inert text, but nothing renders
top-level metadata keys, so say "preserved" rather than implying they do something. `CPUset` is not
in that category — it has a real compose equivalent.

## Source 2 — Compose Manager

The file is already what we want, so import is a copy with the bytes left alone. Four complications,
one of which version 1 missed entirely:

- **Finding the file needs three tiers, not two.** Version 1 said label, then folder. There is a
  third and it comes first: one project has **no compose file on flash at all**, only a 33-byte
  `indirect` file naming a folder under appdata. That project is the largest and most complete on
  your box — five services — and version 1's rule would have skipped it as unreadable. Rule:
  `indirect` file, then the container's own label, then the flash folder.
- **The label does not always point at appdata.** Three of four point there; one points back at the
  flash folder. "Trust the label" is right; "the label points at appdata" is not.
- **The override file is never actually used.** The job runner, the metadata reader and the
  validator each pass exactly **one** `-f`, and compose does not auto-merge an override when files
  are named explicitly. So copying both files and starting the stack **runs the base file alone**
  and whatever the override sets silently vanishes — the same class of failure as the network one:
  it looks like it worked.
- **The write order is a deadlock.** Saving a stack validates the compose text *before* the folder
  exists, and a companion file cannot be written until it does. So a project interpolating `${VAR}`
  from its `.env` gets validated with no `.env` present — refused outright if it uses `${VAR:?}`, or
  accepted with empty values if not. The import needs its own write sequence: create the folder,
  write `.env` and the override **first**, then the compose file, validating with the project
  directory pointed at the real folder — plus a rollback that removes a half-written folder.

## Source 3 — cut

Version 1's phase 5 was "containers with no template, diffed against `docker inspect`". **Remove it,
do not defer it.** It was stated, not designed. The only existing helper for reading an image's
config carries an explicit comment that it never returns environment variables — and the
container-versus-image environment diff was the whole proposal, so the phase begins by reversing a
deliberate decision. A real diff also needs entrypoint, command, healthcheck, labels, resolved
mounts and network settings, each with its own "default or choice?" rule. **The payoff on your box
is one container, which is not running and has one user-set variable.**

If something here is wanted, the honest small version is: *show me what `docker inspect` says about
this container*, as read-only text you can copy from.

## Safety

Every rule below exists because breaking it loses somebody's running service.

- **Import never touches Docker.** No start, stop, create, remove or pull. It reads files and writes
  a stack folder.
- **Import never touches the source.** The template and the Compose Manager project both stay where
  they are, and both plugins keep working.
- **An imported stack is "managed elsewhere" until adopted** — see phase 0. This is what makes the
  first rule true rather than aspirational.
- **Adoption is a separate act with its own confirmation and its own job log**, one container at a
  time, stopping at the first failure.
- **Never both.** Two things managing one container is how a container gets deleted twice.
- **Names collide — refuse, do not overwrite.** Version 1 said this was already true. It is true of
  *one* code path: the refusal lives in the endpoint, not in the save function, which has no
  collision check and will overwrite a hand-authored compose file. An importer calling the save
  function directly — the obvious thing to do — would violate the project's first rule. The check is
  also a plain directory test while compose lowercases project names, so importing `Plex` beside an
  existing `plex` passes it and produces two folders that are one Docker project.
- **Secrets get copied**, into a folder that defaults to the flash drive. Say so *with the full
  path* before the import runs, not after.

### The collision check, precisely

Test case-insensitively **and** against the normalised project name of every existing stack, and
across the import set itself — five template pairs share a fixed address, and importing both then
starting both puts two containers on one address. Note the source names need normalising first: the
Compose Manager folder names are fine, but the `name` files inside hold `"Tesla Tools"` and
`"Unifi Voucher Server"` with spaces, which the name rule rejects.

## Adoption, redesigned

Version 1's sequence — stop the old container, bring the stack up, remove the old container if that
worked — **cannot execute**. The converter always writes `container_name`, and a *stopped* container
still owns its name, so `compose up` fails with a name conflict against the very container adoption
just stopped. Every time, for every template import. Version 1 had no rollback because it did not
know the failure existed.

**The real sequence:**

1. Record whether the container was running.
2. `docker rename <name> <name>.staxx-preadopt`
3. `compose up -d`
4. On success: `docker rm <renamed>`.
5. On **any** failure: rename back, and start it again if it was running.
6. If the rollback itself fails: stop, leave the renamed container in place, name it in the log, and
   touch nothing else.

There is a second path for a compose file with no `container_name` — no conflict, no rename.

### It cannot be one more entry on the verb allowlist

Version 1 said adoption goes through the existing job runner "with its own verb — the allowlist is
the safety property". It cannot. **Every step of every verb is built unconditionally as
`<compose> -f <this stack's file> <step>`**, so `docker rename`, `docker stop` and `docker rm`
against a container that is not a service of this file are unreachable without changing the
invocation builder — which is the exact mechanism being cited as the safety property. Discovering
that mid-build invites the quick fix (let a verb supply its own prefix), which would turn the one
place with no path from user input to an arbitrary shell command into one that has several.

**Design the second job kind now:** a raw-docker job whose argument is a *container name*,
allowlisted by real membership in `docker ps -a`, escaped on top, with its own fixed verb list
(`rename`, `start`, `rm`) and no free text anywhere. The compose-verb allowlist stays untouched and
the two never share a code path.

### Autostart — the silent one

**StaXX has no autostart at all** — no event hook, no config key, and the metadata format
deliberately excludes it as a fact about one machine. But Compose Manager keeps an autostart file
per project and Unraid keeps its own state per template. So adoption as designed produces a
container that used to start at boot and now never will, discovered at the next reboot with the
connection to the import long gone.

Decide before building: either StaXX gains autostart and adoption carries the source's state across,
or it does not — and adoption must **say so in plain words** on the confirmation, in the log, and on
the row afterwards. The second is acceptable. Finding out at reboot is not.

## Phases

**0. The "managed elsewhere" row state, and the isolation decision.** An imported stack that has not
been adopted must be visibly distinct from one StaXX owns, with every run verb *and* delete's
implicit teardown disabled. Everything in the safety section assumes this exists. It cannot be
bolted on later, because it is what makes phases 2 and 3 safe to ship at all.

**1. The list.** All three sources, what each is, where it came from, whether it is running, whether
it is already imported, and what StaXX can and cannot represent about it. Reads only. Build this
first: it needs nothing from anyone, it is how everything else gets tested, and **it is the cheapest
test material PLAN_34 will ever have.** Two things it should prove before a byte is written —
what the converter would produce for all 85 templates (which surfaces the empty-element problem
immediately), and which Compose Manager projects resolve through `indirect`, through a label, or not
at all.

**2. Unraid templates**, one at a time. Includes the normalisation step, the provenance header, and
`MyMAC` — none of which wait on PLAN_34.

**3a. Compose Manager, projects with no override.**

**3b. Compose Manager, projects with an override** — blocked on multi-file support in the job
runner, the metadata reader and the validator. Splitting it this way makes the missing capability
visible rather than discovered.

**4. Adoption**, as its own guarded job with the second job kind. Note that for source 1 this is
closer to phase 2 than the numbering suggests: because the converter always writes
`container_name`, an imported template stack collides with the still-running Unraid container and
**cannot be started until adoption exists**. That is a lucky safety net, but it means shipping
phase 2 alone delivers stacks whose Start button always errors. Say so on the row, or ship the two
together.

## Things version 1 never mentioned

- **Page cost.** A full table refresh runs one compose command per stack, each with a 15-second
  ceiling; the code's own comment already calls a dozen stacks expensive. Importing a third of 85
  templates makes the plugin's main page take minutes. Either cap what one import may write **and
  say the number**, or make the refresh path cheap first. Related: there is no import destination
  folder in the plan, so 85 stacks land flat at the top level of a one-level folder system.
- **Web addresses and icons.** The converter already carries a template's web address across
  verbatim — including Unraid's own placeholder markers for server address and port, so every
  imported stack shows a link full of literal placeholder text on the first row you look at. And a
  list page showing 85 rows meets an icon-fetch budget designed for a dozen. Most likely answer: no
  icons on the list, resolve on import.
- **The self-test panel.** It already counts stacks and folders and runs no external commands, so it
  cannot hang. One line: give it counts for the three import sources and a named reason for any it
  could not read. That makes the list page debuggable with no browser — which matters, because
  there is no browser on the development machine.
- **Testing.** No file in the corpus carries a template-shaped import. Needed: a test whose
  acceptance criterion is that a user template and a catalogue entry for the same app produce an
  identical object, and a **server-side** test of the XML-to-object step, because the empty-tag
  behaviour cannot be checked on Windows.
- **Documentation.** The plain-English overview never mentions bringing existing containers in — a
  headline feature. The glossary has no entry for adoption. The architecture tables enumerate every
  server-side file and endpoint action and would need the new ones. And a new browser-side file must
  be added to the page's asset list explicitly or **it silently never loads**.
- **What happens when the source changes** after you import it. This is the second-copy-that-can-
  disagree problem the whole stack model was built to avoid. At minimum, say so on the row.

## Where the code goes

`include/Import.php` for reading the three sources, mirroring what the catalogue reader does.
`javascript/template-import.js` for the XML-to-object step, beside the converter and calling into
it — **and registered in the page's asset list**. New endpoint actions: `import-list`, `import-read`,
`import-write`, and later `adopt`.

## Decisions only you can make

1. **The Compose Manager identity clash.** Write a project name into the copied file — which
   abandons the byte-for-byte promise for that source — or keep the file untouched and lock the row
   out of every button until adoption? **I recommend the lock** (phase 0): it keeps the promise, and
   an imported-not-adopted stack genuinely is not ours to run.
2. **Autostart.** Does StaXX take it on, or does adoption just say loudly that it is gone?
3. **Where do imports live?** The default stack root is the flash drive, and imports carry plaintext
   API keys and copied `.env` files. Should bulk import refuse while the root is the flash default
   and send you to Settings? And do imports go into a folder you choose, or one called `Imported`?
4. **Is "tick all" ever offered?** On your box that is 85 templates against a page costing one
   compose call per stack per render. One at a time, or fix the page cost first?
5. **`eth0.2`.** Translate to `br0.2` on import — a guess about your network — or refuse those six
   and say why? **I lean refuse-and-explain.**
6. **After a successful adoption, does StaXX delete the Unraid template automatically** (having
   offered you a copy), or only tell you to? Version 1 said automatically, and that is a write into
   another plugin's data directory.
7. **Five template pairs share a fixed address.** Refuse the second, warn, or say nothing and let
   compose fail later?
