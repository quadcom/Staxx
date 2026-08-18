# PLAN_35 — bringing containers you already run into StaXX

**Status: DRAFT v3, awaiting Adrian.** Written 2026-08-18. v1 was corrected by a verification pass;
v2 by three adversarial reviews; **this one by measurement on the server**, plus Adrian's four
decisions. Two of v2's headline claims did not survive contact with the real box.

## What you would notice

A page listing every container you already run and every template you have, saying where each came
from. Tick the ones you want. StaXX writes each as a compose file in a stack folder **in the
background**, and each one arrives **needing review** — not started, and not startable until you have
looked at it. Reviewing shows you what would clash with something already running, and what StaXX
could not represent. Until you say so, nothing changes: the container keeps running exactly as it is.

And when you do start one, **the original stays where it is, stopped**. Switching back is stopping
the StaXX stack and starting the old container again. Nothing is deleted at any point, and Unraid's
own Docker page keeps working the whole time.

---

## Your four decisions, recorded

1. **Autostart is not this plan's problem.** You are writing a separate plan covering autostart and
   startup ordering. So nothing here builds it — but adoption must **say in plain words** that a
   container it takes over will no longer start at boot, on the confirmation, in the log and on the
   row, with a pointer at that coming plan. Finding out at the next reboot is the one unacceptable
   outcome.
2. **Imports land in the configured stack root, using the existing folder model.** No special
   destination rules, no `Imported` folder invented, no refusal while the root is the flash drive.
   The import screen offers the same folder choice a stack move already offers. (You noted the
   default root should eventually be an appdata folder, settled during onboarding — that is a
   different plan, and this one must not pre-empt it.) Import still **states the full path** the
   secrets will land in before it runs, because copying API keys somewhere is worth saying out loud.
3. **Import runs in the background and everything it writes needs review before it can start** —
   including a collision check against everything else running on the box. This is your idea and it
   is better than what v2 had; it is now the spine of the plan. See below.
4. **`eth0.2` imports as-is.** The six templates carrying a fixed address on a network Docker does
   not have are written faithfully and compose is allowed to complain. The review step names the
   problem, which costs nothing and is not a guess about your network.

Three more I took myself, since v2 already argued them:

- An imported stack is **locked out of every button** rather than having a project name written into
  a file we copied byte-for-byte. That keeps the never-rewrite promise.
- Two templates claiming the same fixed address gets a **warning at review, not a refusal** — you may
  well be replacing one with the other.
- Whether adoption deletes the old Unraid template waits until adoption is actually being built.

---

## What measurement changed

### The page-cost worry was wrong, and it was wrong by two orders of magnitude

v2 said importing a third of your 85 templates "makes the plugin's main page take minutes", and built
a batch cap on top of that. **Measured on your server: 48ms per stack.** All 18 current fixtures
render in 881ms; seven real Compose Manager projects, including a five-service one, come in at
45–53ms each. One `compose ls` for the whole machine is 44ms.

So 85 more stacks costs about **four seconds** on a full page render, not minutes. v2's figure came
from reading the 15-second *timeout ceiling* as if it were the cost. **The batch cap is cut** — there
is nothing to protect against. Reviewing 85 stacks one at a time would be miserable, but that is a
human problem, not a machine one, and the review screen should be built for it.

The background-import decision stands anyway, because what it is really for is safety: nothing
arrives startable.

### The empty-element bug is real, bigger than v2 thought, and breaks differently

v2 flagged this as "inferred, not proven — test it on the server". Tested, on all 85 of your real
templates. It is real, it affects **18 different settings**, and v2 predicted the wrong symptom.

An optional element written as `<PostArgs/>` comes through as an **empty list**, not an empty string.
PHP correctly treats that as nothing; JavaScript treats an empty list as *something*. The converter
runs in the browser. So every truthiness check fires.

What it actually produces, run against a real template shape:

```yaml
    command: ""
    x-unraid:
      webui: ""
```

Not v2's predicted `[object Object]` — an **empty command**, which is worse, because it looks
harmless and is not. An empty `command` in compose *overrides the image's own start-up command*, so
those containers start with nothing to run. That would land in **83 of your 85 templates**.

Measured frequency of empty elements across your templates:

| Element | Templates | Element | Templates |
|---|---|---|---|
| `CPUset` | 85 | `DonateText` | 46 |
| `PostArgs` | 83 | `ReadMe` | 34 |
| `TailscaleStateDir` | 81 | `WebUI` | 19 |
| `Requires` | 75 | `Support`, `TemplateURL` | 10 each |
| `ExtraParams` | 70 | `MyMAC`, `Project` | 8 each |
| `MyIP` | 53 | `Registry`, `Category` | 5 each |
| `DonateLink` | 47 | `Icon`, `Overview` | 3, 2 |

The fix is unchanged and small — coerce every empty element to an empty string before the converter
sees it — but it is now **proven necessary and first in its phase**, not a precaution.

### The delete hazard is still there, exactly as v2 described

Re-read in the code rather than taken on trust. Deleting a stack runs `compose down` whenever the
folder holds a compose file and Docker is running. The confirmation prompt fires **before** that, and
only guards *extra files in the folder* — so a folder holding nothing but a compose file, which is
precisely what an import creates, gets torn down with no confirmation at all.

Nothing is broken in StaXX today: running `down` when you delete a stack StaXX owns is correct. It
becomes a hazard the moment this plan creates a folder that shares an identity with somebody else's
running containers. Which is why it is phase 0.

**How it shares that identity:** nothing here ever passes a project name to compose, so compose
derives the project from the folder name. The state lookup tries full path, then folder tail, then
project name — and an imported copy in a folder named after the source project matches on the last
two. The row goes green. From a green row, Stop runs `down` on the live containers and Remove runs
`rm --stop --force`.

---

## The spine: "needs review"

A stack StaXX imported but you have not yet checked. It is **visibly distinct**, every run verb is
disabled, and **delete removes the folder without running `down`**. That last part is the whole
safety property — it is what makes "import never touches Docker" true rather than aspirational.

**Where the state lives: in the compose file's own `x-unraid` block.** It travels with the file,
survives a move, is ignored by plain `docker compose up`, and is honest — the file itself says it was
imported and nobody has checked it. Reviewing removes the key and the stack becomes ordinary. This
needs a version bump of the metadata format, which is what that format's extension point is for. The
fallback, if the bump proves heavy, is a list kept beside the collapsed-folders state — cheaper, but
a second copy that can disagree with the file, which is the thing the whole stack model exists to
avoid.

**What review shows you**, per stack:

- **Collisions.** Checked against existing StaXX stacks (case-insensitively *and* against normalised
  project names, since compose lowercases them), every container name in `docker ps -a`, every
  Compose Manager project, and the rest of the import set. Names need normalising first: the Compose
  Manager `name` files hold `"Tesla Tools"` and `"Unifi Voucher Server"`, with spaces the name rule
  rejects.
- **What StaXX could not represent**, named rather than counted.
- **Whatever the import flagged** — the six `eth0.2` addresses, the three malformed paths, the five
  template pairs sharing a fixed address.
- **Whether something else is currently managing it**, which is what decides whether adoption is
  needed at all.

**A collision refuses the write, it never overwrites.** v2 said this was already true; it is true of
*one* code path. The refusal lives in the endpoint, not in the save function, and an importer calling
the save function directly — the obvious thing to do — would overwrite a hand-authored compose file
and break the project's first rule.

---

## Source 1 — Unraid templates

85 templates (not 86; the 86th is a `.bak`), 70 containers. The happy accident holds: a Community
Applications catalogue entry and an Unraid user template are the same document, and StaXX already
converts one into a compose file.

v2 listed five things the converter "has never had to handle" and **four were already built** — the
named network with its `external: true` declaration, the hardware address from a template's extra
parameters, the user's edited value preferred over the shipped default, and the two short trailing
markers that flag a value as secret. v2's instruction to never put those in a comment aimed at a
problem that does not exist and would have torn out a working mechanism. Cut.

### What is genuinely missing

1. **Empty-element normalisation.** Proven above. First, because everything else builds on it.
2. **`Category` has a different shape.** A catalogue entry supplies a list; a template supplies one
   space-packed string like `"Network:Management Productivity: Tools:Utilities"`. Unsplit, that
   becomes a single nonsense category. Pre-split before calling the converter.
3. **The map form for the fixed address** — four lines. This was PLAN_34's only dependency and
   PLAN_34 has shipped, so a freshly imported address is now editable in the form rather than
   read-only.
3a. **An option to leave out the container name**, defaulting to leaving it out for imports. One
   line in the converter, and it is what lets an import run beside the Unraid container it came from
   rather than instead of it. See "Can the old container just stay where it is?" below.
4. **Filter the folder by `.xml`** — the `.bak` parses fine and would otherwise be offered.
5. **A provenance option.** The generated header says "Converted from the Community Applications
   template for X", which is false on every import from this source.
6. **Three malformed paths.** One template's Path settings target a bare word rather than an absolute
   path; the converter's concatenation produces a mount that is invalid and not obviously wrong to
   read. Detect and flag rather than emit.

### Cut from source 1

**`Display=`** (Unraid's basic/advanced marking). There is nowhere to put it — the metadata format
has no per-field home by deliberate design — the real data has four values rather than two, and 42
settings on your box are marked "do not show this at all", which collides head-on with the rule that
nothing in the file is ever hidden. Its own plan, if ever.

`TailscaleStateDir`, `Shell` and `DateInstalled` can be preserved as inert text, but say "preserved"
rather than implying they do anything. `CPUset` is different — it has a real compose equivalent.

---

## Source 2 — Compose Manager

7 projects (not 5; 5 was the container count), 5 containers, three never brought up. The file is
already what we want, so import is a copy with the bytes left alone. Four complications:

- **Finding the file needs three tiers, not two.** `indirect` file first, then the container's own
  label, then the flash folder. One project — `PenPot_Complete`, your largest at five services — has
  **no compose file on flash at all**, only a 33-byte `indirect` file naming a folder under appdata.
  Re-confirmed on the box while measuring. v2's two-tier rule would have skipped it as unreadable.
- **The label does not always point at appdata.** Three of four do; one points back at the flash
  folder. Trust the label; do not assume where it goes.
- **The override file is never actually used.** The job runner, the metadata reader and the validator
  each pass exactly one `-f`, and compose does not auto-merge an override when files are named
  explicitly. Copying both and starting the stack **runs the base file alone** and whatever the
  override sets silently vanishes. Same class of failure as the network one: it looks like it worked.
- **The write order is a deadlock.** Saving a stack validates the compose text *before* the folder
  exists, and a companion file cannot be written until it does — so a project interpolating from its
  `.env` gets validated with no `.env` present. Import needs its own sequence: create the folder,
  write `.env` and the override **first**, then the compose file, validating with the project
  directory pointed at the real folder, plus a rollback that removes a half-written folder.

---

## Source 3 — cut

v2's "containers with no template, diffed against `docker inspect`". Removed, not deferred. The only
existing helper for reading an image's configuration carries an explicit comment that it never
returns environment variables — and that diff was the whole proposal, so the phase begins by
reversing a deliberate decision. The payoff on your box is one container, not running, with one
user-set setting.

The honest small version, if it is ever wanted: *show me what Docker says about this container*, as
read-only text you can copy.

---

## Can the old container just stay where it is? — mostly yes

Adrian's question, and it reshapes the plan: stop the Unraid container, leave it alone, start the
StaXX import, and switch back whenever you like. No all-or-nothing moment, no transfer-back
procedure.

**The obstacle is one line.** A stopped container still owns its name — 14 on your box are stopped
right now and every one of them still holds its name — and the converter writes
`container_name: <app>` unconditionally, so the import wants a name the old container has not let go
of. Docker refuses, and no amount of stopping helps.

**Give the converter an option to leave that line out, defaulting to out for imports, and the
conflict disappears.** Compose then names the container after the project and service instead, which
cannot collide with anything Unraid made. Both containers exist side by side, one running, one
stopped, and switching between them is stop-one-start-the-other. Nothing is deleted, nothing is
renamed, and the Unraid template is never touched, so Unraid's own page keeps working throughout.

So, by source:

| Source | Side by side? | How you go back |
|---|---|---|
| **Unraid templates — all 85** | Yes, once the name line is optional | Stop the StaXX stack, start the Unraid container |
| **Compose Manager — 3 of 7** | Yes, those files pin no container name | Same |
| **Compose Manager — 4 of 7** | Not directly: the file pins a name, and we copy it byte for byte | Rename the original out of the way first; rename it back to return |

Those four are `Homepage-For-Tesla`, `LoanDash`, `Unifi_Voucher_Server` and `supstack-dev`. Their
files name their containers outright, and we cannot edit that without breaking the promise that a
copied file is copied exactly. Putting the copy in a differently-named folder does not help either —
a pinned container name ignores which project it belongs to. So those four need the original renamed
before the copy can start. **That is still reversible and still deletes nothing** — the original sits
there, stopped, with all its data — it is just one extra step.

### There is no organisational name to invent — the service name already is one

Worth saying plainly, because it is the obvious next thought: `container_name` cannot be made
"organisational only". In compose that setting has exactly one job, which is to fix the name Docker
uses. But the label it would be wanted for **already exists** — the **service name**. That is what
the row shows, what the form groups by, what containers inside the stack use to find each other, and
what Docker builds the real container name out of. Leaving `container_name` out does not lose a name;
it just stops pinning one.

### How much of the app this changes: nothing

Checked rather than assumed:

- **The plugin never matches containers to stacks by container name.** It matches on the compose
  file's path first, then the project name. Take the name line away and every row still finds its
  containers.
- **The form already treats an empty container name as normal** and says so on screen: *"leave this
  empty and Docker names the container itself, from the stack and service names."* That sentence is
  already there today.
- The only code touching this setting reads it out of a file to display it, checks it against
  multiple copies of one service, and holds its description. Nothing depends on it being set.

So this is **one line in the converter**, made optional. Not a rewrite, and nothing outside the
converter changes.

### What Docker actually shows

The container becomes `<stack>-<service>-1` — a stack called `Plex` with a service called `plex`
gives `plex-plex-1`. It appears under that name in `docker ps` and on Unraid's own Docker page. Same
image, same appdata, same ports, same everything else.

### Left-behind containers, measured

Not a pile of rubbish, and not much of one either. On your box **76 containers hold 5GB of writable
layers between them, of which 81MB belongs to the 14 that are stopped** — about 6MB each. So even 85
left-behind containers would come to a few hundred megabytes, against the 44GB of images already
there.

They are also not "orphans" in Docker's sense. They are ordinary stopped containers still tied to
their Unraid templates, which is precisely what makes going back a single click.

### The other strategy: clear the old container instead

Removing the old container **does** free its name immediately, and then an import can keep the
original name — nothing to disconnect, nothing left behind, nothing outside the stack broken.

And it is still not a one-way door: the Unraid template is untouched and appdata is untouched, so
Unraid rebuilds the container from its template in about a minute. That is an everyday Unraid
operation, not a recovery procedure.

| | Keep both | Clear the old one |
|---|---|---|
| Switch back | One click | Let Unraid recreate it, ~1 minute |
| Container name | Changes | Unchanged |
| Left behind | ~6MB per app | Nothing |
| Things outside that used the name | Need the new one | Unaffected |
| Two places can start it | Yes — Docker refuses the second, loudly | No |
| Needs building | Nothing beyond the converter option | The guarded raw-docker job |

#### DECIDED: keep the original container name

Adrian, 2026-08-18: **keep the name.** Clear the old container, and let StaXX rebuild it under the
same name. His reason is not about his own box — it is that anything referring to a container *by
name* (a reverse proxy, a script, another container) must not break, and the audience for this plugin
is wider than one server.

So the default import is: remove the old container, write the name into the compose file exactly as
the converter already does, bring it up. The converter needs **no change at all** — the line it
already writes is the one we want.

Consequences for the build order:

- **The guarded raw-docker job moves into phase 2.** Removing a container needs it, so it is no
  longer optional or last. It is on the critical path.
- **The collision is now by design, not an error.** The review screen presents it as "this replaces
  the container called X", never as a refusal.
- **Keep-both is not built.** It stays one line away — leaving the name out of the file — if it is
  ever wanted as a cautious option, but nothing is built for it now.

#### The autostart problem disappears — which is a direct reward for that decision

Read from Unraid's own startup script rather than assumed. Its autostart step walks a list of
container **names** and, for each one that exists, is not already running and has its paths, runs a
plain `docker start`. **It never creates anything from a template.**

So when StaXX rebuilds the container under the same name, Unraid's existing autostart entry goes on
working, untouched, with no new code — it simply starts the container, which is the same container
compose made, and compose still reports it running. Between the old container being removed and the
new one being created, the entry is skipped silently, because the container does not exist.

PLAN_35 called losing boot-start "the one unacceptable outcome". Under this decision it does not
happen at all. **Do not remove the autostart entry**, and do not build anything for this.

Two things to carry into the autostart plan, though, because this is implicit coupling nobody wrote
down: a *newly created* StaXX stack has no entry in that list and therefore no autostart, which is
the actual gap; and at array stop Unraid stops every running container regardless of who made it,
which is what we want but is worth knowing.

#### The earlier worry about the autostart list, corrected

Unraid keeps autostart as a plain list of **container names** — 58 of them on your box, in
`/var/lib/docker/unraid-autostart` with the ordering mirrored on the flash drive in
`dockerMan/userprefs.cfg`. Names, not references to a particular container.

That cuts both ways, and which way depends on the strategy:

- **Keep both** — the import runs under a different name, so the old entry cannot match it. Leave the
  list alone: the entry sits inert while its container is stopped and starts working again the moment
  you switch back. **Turning autostart off is unnecessary and makes going back harder.**
- **Clear the old one** — the import reuses the original name, so the stale entry matches the new
  container. I first wrote that this was a hazard needing the entry removed. **It is not**, and the
  section above says why: Unraid's autostart only ever runs `docker start` against a container that
  already exists, so it starts StaXX's container and nothing else happens. Leave the entry alone.

Worth carrying into the autostart plan: this is a plain name list on the flash drive, which is a
simple thing for StaXX to read and write when the time comes. Keep-both ships with phase 2
because it needs nothing; clear-the-old-one arrives with adoption, since removing a container needs
the same guarded job kind adoption does. Use keep-both while you are still deciding whether you trust
an import, and clear-the-old-one once you do.

### What this costs, honestly

- **The container's name changes.** Anything outside the stack that referred to it by name — a script
  running a command inside it, another container looking it up by name, a reverse proxy pointed at
  it — needs the new one. Containers *within* the same stack still find each other by service name,
  so that half is fine.
- **Never both at once.** They share the same appdata folders, the same ports and, for 32 of your
  templates, the same fixed address. Two running at once means two processes writing one database.
  Docker will refuse the second on the port or the address, which is a loud failure rather than a
  quiet corruption — but the row must say which one is live.
- **Unraid's page will still offer to start the old one.** Two places can start the same service.
  Say so on the row and in the review.
- **Disk.** The old container's writable layer stays. Small, since the data lives in appdata.

### So adoption is demoted

It is no longer the thing that makes an import startable. It becomes **optional**: "take the original
container name back too", wanted only when something outside depends on that name — plus those four
Compose Manager projects, where it is the only way in. Which means the rename machinery below is
still needed, but it is last, it is narrow, and if it is never built the importer still works.

## Adoption — the optional path

v2's sequence — stop the old container, bring the stack up, remove the old one — **cannot execute**.
A *stopped* container still owns its name, so bringing the stack up fails with a name conflict
against the very container adoption just stopped. Every time.

**The real sequence:** record whether it was running; rename the old container out of the way; bring
the stack up; on success remove the renamed one; on **any** failure rename it back and restart it if
it was running; and if the rollback itself fails, stop, leave the renamed container in place, name it
in the log, and touch nothing else. There is a second, simpler path for a compose file with no
container name.

### It cannot be one more entry on the verb allowlist

Every step of every verb is built unconditionally as `<compose> -f <this stack's file> <step>`, so
renaming, stopping and removing a container that is not a service of this file are unreachable
without changing the invocation builder — which is the exact mechanism being cited as the safety
property. Discovering that mid-build invites the quick fix (let a verb supply its own prefix), which
would turn the one place with no path from user input to an arbitrary shell command into one that has
several.

**Design the second job kind now:** a raw-docker job whose argument is a *container name*,
allowlisted by real membership in `docker ps -a`, escaped on top, with its own fixed verb list
(rename, start, remove) and no free text anywhere. The compose-verb allowlist stays untouched and the
two never share a code path.

---

## Phases

**0. Needs review, and the delete fix.** The row state, the run verbs disabled, and delete no longer
running `down` for a stack in that state. Everything below assumes this exists; it cannot be bolted
on afterwards, because it is what makes the rest safe to ship.

**1. The list.** All three sources, what each is, where it came from, whether it is running, whether
it is already imported, and what StaXX can and cannot represent about it. Reads only. Build it first:
it needs nothing from anyone, and it is how everything else gets tested. Two things it should prove
before a byte is written — what the converter would produce for all 85 templates, and which Compose
Manager projects resolve through `indirect`, through a label, or not at all.

**2. Unraid templates**, written in the background, arriving as needs-review. Normalisation first,
then provenance, category splitting, the `.xml` filter and the malformed-path check.

**3. The review screen**, including the collision check. Could merge with phase 0; kept separate
because phase 0 is a safety property and this is a user interface, and the first should ship even if
the second slips.

**4a. Compose Manager, projects with no override.**

**4b. Compose Manager, projects with an override** — blocked on multi-file support in the job runner,
the metadata reader and the validator. Split this way so the missing capability is visible rather
than discovered.

**5. Adoption**, optional, as its own guarded job with the second job kind, saying plainly that
boot-start does not come with it. Last because nothing depends on it: an import starts perfectly well
without it. Its only unavoidable customer is the four Compose Manager projects that pin a container
name.

---

## Things worth saying that v2 buried

- **Web addresses and icons.** The converter carries a template's web address across verbatim,
  including Unraid's own placeholder markers for server address and port, so an imported stack shows
  a link full of literal placeholder text on the first row you look at. And a list page showing 85
  rows meets an icon budget designed for a dozen — most likely answer is no icons on the list,
  resolve on import.
- **The self-test panel** already counts stacks and folders and runs no external commands, so it
  cannot hang. One line: give it counts for the three sources and a named reason for any it could not
  read. That makes the list page debuggable with no browser, which matters, because there is none on
  the development machine.
- **Testing.** No fixture carries a template-shaped import. Needed: a test whose acceptance criterion
  is that a user template and a catalogue entry for the same app produce an identical object, and a
  **server-side** test of the empty-element step, because that behaviour cannot be checked on Windows.
- **Documentation.** The plain-English overview never mentions bringing existing containers in — a
  headline feature. The glossary has no entry for adoption. And a new browser-side file must be added
  to the page's asset list explicitly or **it silently never loads**.
- **What happens when the source changes** after you import it. This is the second-copy-that-can-
  disagree problem the stack model was built to avoid. At minimum, say so on the row.

## Where the code goes

`include/Import.php` for reading the three sources, mirroring what the catalogue reader does.
`javascript/template-import.js` for the empty-element step, beside the converter and calling into it
— **and registered in the page's asset list**. New endpoint actions: `import-list`, `import-read`,
`import-write`, and later `adopt`.

## Measurements this plan rests on

All taken on the server, 2026-08-18, read-only:

| Claim | Measured |
|---|---|
| Cost of a full page render, per stack | 48ms (18 fixtures in 881ms) |
| Cost of a real five-service project | 45–53ms, same as a fixture |
| Cost of the cheap state refresh | 44ms, once, for the whole machine |
| Templates | 85 `.xml`, all parse |
| Empty elements | 18 distinct, up to 85 templates each |
| What an empty element produces | `command: ""` — an empty command, on 83 templates |
| Compose Manager projects with no flash file | 1 of 7 (`PenPot_Complete`) |
| Compose Manager projects pinning a container name | 4 of 7 |
| Containers stopped right now, all still holding their names | 14 of 76 |
| Delete runs `down` before any confirmation | yes, whenever the folder holds a compose file |
