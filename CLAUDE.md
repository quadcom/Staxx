# CLAUDE.md

Project: **StaXX**  ·  Plan tag: **`STX`**

- publish: README.md CHANGELOG.md docs/README.md docs/guide docs/glossary.md
- repo: quadcom/Staxx
- per-branch: staxx.plg branch
- release-ignore-tags: 1.* 20[0-9][0-9].*

*The first two lines are read by the `preview-site` skill: what this project publishes to the shared
preview site, and which repository to render against so a bare issue reference looks right. The tag
is the folder it publishes into and the prefix its plan files carry.*

*The last two are read by the `cut-a-release` skill. `per-branch` names every value that differs
between the two branches, so the check can refuse a release whose manifest still says `dev` on
`main` — the merge step most often forgotten, and the one that quietly offers development builds to
everyone on the stable channel. `release-ignore-tags` excludes the tags a padded version can never
sort above: the unpadded `1.x` names burnt while releases were immutable, and the dated scheme used
before them. Without it every stable release would be refused for ever.*

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

StaXX is an Unraid 7.2+ webGUI plugin that replaces Unraid's proprietary XML Docker
templates with standard Compose files, and renders those compose files as a form so non-technical
users can configure containers without touching YAML. In beta (Adrian's word, 2026-09-04); see `README.md` for the design
commitments and `docs/README.md` for the plain-English overview.

Two rules override most other judgement calls:

1. **A file authored here must run unmodified under plain `docker compose up` anywhere.** UI
   metadata lives only in optional `x-unraid:` extension keys, which the compose spec ignores.
2. **Never lose what the author wrote.** The thing under protection is their *meaning and their
   annotations*, not the byte order. Comments, anchors, values and intent survive a write-back.
   Rearranging is not the harm — two files holding the same configuration in a different order are
   the same stack. **Losing** something is the harm, and so is changing a file without saying so.
   A file that is genuinely wrong should be *fixed*, not refused: say what was wrong, say what
   changed, and make it undoable. A silent correction is the real danger, not an edit.

   Two places where order is genuinely not neutral, so this is not a licence to normalise:
   a comment sits on a line rather than on a key, so it has to travel with what it annotates; and a
   YAML alias must come after its anchor, so some reorderings turn a valid file into one that will
   not load.

## Development environment

Development happens on **Windows**; the code runs on a **Linux Unraid server**. PHP and Docker live
on the server, not on the dev machine, so a webGUI page cannot be *rendered* locally — but Chrome is
here and driven directly, so a page already deployed to the server can be opened, clicked and read.
Docker questions are answered on the server, where the containers actually are. But
node and python are both present here, and every JavaScript and schema suite — the compose model's
own round-trip tests, the Community Applications converter, the image importer, the undeclared-name
check, the schema self-test — runs on the dev machine, not just a syntax check of it. `compose-
model.js` is requireable from node directly, so a suspected round-trip bug can be proven with a
throwaway probe instead of guessed at. Only PHP is genuinely absent locally.

**The interpreter is called `python` here, never `python3`.** Windows keeps a stub named `python3`
on the path whose only purpose is to print "Python was not found" and offer the Microsoft Store, so
the habitual Linux spelling fails with exactly the message that looks like Python being missing.
It is not: 3.13 is installed, with `pyyaml` and `jsonschema` both available, and
`validate_schema.py` runs locally. Reach for another approach only after `python` itself has failed.

**CRITICAL:** Never rewrite entire files. Provide targeted patch diffs or isolated code blocks only.
**Execution:** Before executing any multi-file changes, write your proposed architecture to `PLAN.md` and wait for user approval. If during the process there are new sub-plans built. Create PLAN_X.md incrementing 'X' to keep track of all the steps that are outstanding. Once the plan(s) are complete then the plan files can be marked as complete. Keep the plans for future quick reference but move them into `plans/completed-plans/`.

**Where a plan lives:** every plan is a file in `plans/`. Everything still in play sits in the root
of that folder — in progress, not started yet, or waiting on a decision. A finished plan moves down
into `plans/completed-plans/`. Nothing plan-shaped is left loose in the project root.

**A plan file is never deleted. There is no exception to this and no case where deleting one is the
tidy answer.** A plan has exactly three ends: it is built, and moves to `plans/completed-plans/`; it
is abandoned, and its status line says so and why, and it moves to `plans/completed-plans/` as the
record of a road not taken; or it is still open, and it stays in `plans/`. Superseding a plan does not delete it
either — the old one's status says what replaced it. A number is never reused. **Never remove a plan
file, never fold two into one by deleting the loser, and never propose deleting one as housekeeping.**

The reason is not sentiment. A plan is the only record of *why* something was decided, and newer
plans cite older ones by number — so a missing file turns every citation to it into a claim nobody
can check. This already happened once: 57 finished plans, numbers 1 to 58, disappeared from disk on
2026-08-25 when the commit that stopped carrying them in the repository took the working copies with
it. They were recovered from the repository's history on 2026-09-01, but that door is now shut —
the whole of `plans/` is gitignored, so **git is no longer a safety net for them.** They
survive on Adrian's own real-time backup of his development directory and nowhere else.

**A commit is checked for private detail before it is made, and it can quietly exclude a file.** A
local pre-commit hook — in `.git/hooks/`, so never carried in a clone — reads the lines a commit
*adds* and refuses the commit over an address on the real server's network, a password hash carrying
real salt and hash material, a private key, a GitHub or Docker Hub token, Adrian's email address, or
this machine's own name or mapped drive. It ignores the example addresses the code and tests use
throughout, the bare hash scheme names the docs discuss, and any line ending `# allow-private`. Past
it once with `STAXX_ALLOW_PRIVATE=1 git commit`, and only with a reason.

**Plan-shaped paths are treated differently and this is the part that surprises people:** a staged
plan is read in *full*, not just its changed lines, and if anything is found that one file is
**dropped from the commit** while everything else lands. So a commit can legitimately contain less
than was staged — the hook says which file it held and why. Nothing is lost; the plan is untouched on
disk and commits once it has been sanitised. The whole text is read rather than the diff because a
credential committed three commits ago is already in the history for good, and it is a check rather
than a "sanitised on <date>" marker because a marker is a claim that goes stale on the next edit.
The `housekeeping` skill carries the sanitising rules the check enforces.
**COMMENTS AND DOCUMENTATION** Comments and documentation should reflect what something does not what it used to do along with what it now does.
**WRITING CODE** When writing code, Fable (the main session) always makes the plan and multiple Sonnet agents write the code. Fable then verifies the code that was written. Just before writing starts tell me "Sonnet agents are writing". (Fable replaced Opus in the planning and verifying roles on 2026-09-02, for the time being; the Sonnet step is unchanged.)
**TOKEN USAGE** At all times be conservative on token usage.


**The full catalogue of suites is `tests/README.md`** — every local command, the server-only table
with what each suite needs, the traps none of the code records, and the round-trip corpus. Read the
header of any suite before running it; the table there is an index, not a substitute. Three things
from it belong here because they are rules, not catalogue:

- **Run both browser syntax checks after every edit, and run both**, because they catch different
  things. `stacks.js` is one big IIFE, so a single typo kills the whole page's behaviour silently;
  `node --check` is the cheapest guard there is. But both browser files are strict mode, where
  assigning to a name nothing declared throws instead of quietly making a global — and `node --check`
  cannot see that, since the file parses perfectly and the error only exists at run time. One such
  line inside a function every render calls kills the whole page. `node tests/js_undeclared.js`
  catches it. There is no PHP linter locally; run `php -l` on the server after every deploy, over
  `include/*.php`.

  ```sh
  node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js
  node --check src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js
  node tests/js_undeclared.js
  ```

- **None of this is a shipped component, and it must never be presented as one.** `pkg_build.sh`
  packages `src/staxx/` alone, so nothing under `tests/` ever reaches a user's server. Keep every
  mention of the suites — and of `tests/server/`, which has to be copied to a machine to run at all —
  inside developer notes: this file, `tests/README.md`, the plan files, and source comments. It does
  not belong in `README.md`, in `docs/`, in a `.page`, in a translation string, or anywhere else a
  person using StaXX would read it. The one testing-shaped thing that *is* user-facing is
  `staxx_selftest()`, the cheap health check on the settings page, and that is a different thing
  with a different audience.

- **A server suite that redirects `STORE_ROOT` points it at `/tmp` and puts the real value back on
  every exit path.** Moving the store even for one command makes every real stack vanish from the
  webGUI for as long as it is moved, which is not acceptable on Adrian's box. The opt-in suites behind an
  environment flag are run when the code they cover is touched, and before a release.

## Seeing a page the way GitHub will render it

**How the preview tooling works is in `tools/README.md`** — the GitHub-rendered docs preview, the
preview site on Adrian's box, how each section of it is rebuilt, and the two Unraid traps that were
measured rather than reasoned about. Neither script is in the repository: they are local-only and
gitignored, and their address, drive letter and folder live in `local/machine.md`. The rules that
matter every day:

- **Adrian reviews docs on the preview site before they are pushed.** A change to the readme, a
  guide page or a review is published there in the same pass, unasked, and offered whenever it waits
  on his approval — a page he can look at is worth more than a description of it.

  ```sh
  bash tools/publish-preview.sh guide        # just the user guide (and the glossary)
  bash tools/publish-preview.sh readme       # just the readme
  bash tools/publish-preview.sh review       # just the front page and the review history
  bash tools/publish-preview.sh <file.md>…   # just those files
  ```

- **Rebuild only the section you changed.** A full run empties the folder first, which is what makes
  a deleted page vanish; every sectioned run also refreshes the changelog, since it is the one page
  nobody would think to rebuild.
- **The preview asks GitHub to render**, through the same markdown endpoint the site uses, so it
  needs the GitHub CLI signed in. It does not approximate GitHub's formatting.

## Deploying to the test server

The deploy loop is in `notes/deploy.md` and the `deploy` skill does it; the three things that have
gone wrong are: credentials live in `local/dev-server.md` (gitignored); OpenSSH cannot take a
password unattended here, so use `plink -ssh -batch` and `pscp`; and what is staged beside
`dev-install.sh` is the plugin folder's *contents*
(`src/staxx/usr/local/emhttp/plugins/staxx`), never `src/staxx`. Nothing under
`/usr/local/emhttp` survives a reboot, so a reboot is the panic button; settings on the flash drive do.

## How StaXX is delivered

The full picture — the Community Applications listing, the packaging chain, the two workflows — is
in `notes/delivery.md`. Three rules from it:

- **The manifest address pasted into Plugins → Install Plugin is the only install route a user is
  ever shown.**
- **The copy-to-flash-and-run-the-script route is development tooling** and appears in nothing a user
  reads: not `README.md`, not `docs/`, not a release note. It belongs in this file, the notes and
  `local/dev-server.md`.
- **Building a package and publishing a tagged release are a separate, occasional act, never a step
  in shipping a change.** Ask before cutting one.

## The changelog is written as the work lands

**A change a person would notice gets its bullet under `## Unreleased` in the same commit as the
change**, written as what a person can now do, not as a commit subject. A dev build publishes that
section verbatim as its release notes, and the build refuses a dev build whose section is empty.
The reasoning, and why there is one file and no per-branch copy, is in `notes/changelog.md`.

## Two release channels

**`RELEASING.md` is the runbook — follow it to the letter when cutting either kind of release.** It
carries the ordered steps, every refusal the build can produce, the two facts the whole scheme rests
on (Unraid compares versions as plain text; a package filename cannot hold a hyphen), and the two
per-branch values a merge gets wrong. Two of those matter outside a release:

- **The `branch` entity in `staxx.plg` is the channel switch and is per-branch content**, like the
  README's development banner. Never trust the committed value.
- **A merge from `dev` to `main` has two things to put back**: the banner (`cleanreadme`) and that
  entity. Do both in the merge commit.

## Version policy

Ordinary semver, enforced by the release workflow. The reasoning and history are in
`notes/versioning.md`; these are Adrian's standing instructions and they shape every plan:

- **Every component is two digits** — `00.02.01`, never `0.2.1`. Text order must equal number order.
- **Patch** for fixes only, **minor** for anything new, both on the `00.xx.xx` line. **Never propose
  `01.00.00`** and never argue a change up to major because of its shape; it is his call alone
  (2026-08-30, reaffirmed 2026-09-04).
- **The number is decided by what has accumulated on `dev`, once**: a dev build declares the next
  stable number.
- **No migration machinery, upgrade paths, or code that keeps reading a shape StaXX no longer
  writes.** Take the clean shape and leave the old one behind. The one real server is patched by hand
  when a change would make something on it read wrong — so **a change that alters the shape of
  anything already written must say plainly what now reads differently.** Silence is the failure.

## Verifying server-side logic without the UI

For server-side logic, calling plugin functions from a throwaway PHP script beats driving the UI.
`staxx_start_job()` returns `''` plus an error string for every refusal, so guard and allowlist
behaviour can be tested exhaustively — and safely, since a refusal never reaches the shell.

To check row markup, data attributes and tag balance, call
`staxx_render_rows(staxx_folder_layout(staxx_list_stacks()), true)` with a stub `_()`
defined first and dump the HTML to a file. Strip HTML comments before counting tags — the markup is
full of explanatory comments that mention `<span>` and throw off a naive balance check.

## Architecture — read the note for the area you are touching

The description of how StaXX is built lives in `notes/`, one file per area, so a conversation loads
only the part it needs. **Open the note before changing anything in its area**; each one records
decisions and failures that cannot be recovered from the code. A pointer here never restates a note.

- `notes/source-tree.md` — the Slackware-style tree under `src/staxx/` and why the plugin's
  directory name has to sort after `dynamix.docker.manager`. Open before adding or moving a file.
- `notes/pages.md` — how `.page` files mount, the three pages and the two marker files that keep
  exactly one view page live. Open before touching a `.page` header or the settings that feed it.
- `notes/stack-model.md` — a stack is a directory holding a compose file and nothing else; folders,
  identity, the blank store root, how a moved stack is still found. Open before any change to
  listing, naming, folders or the store.
- `notes/php-layer.md` — the `staxx_` files and what each owns, and why every external command goes
  through one wrapper that cannot hang. Open before adding a PHP function or running a command.
- `notes/endpoint.md` — the single JSON endpoint: POST only, one `switch`, two refresh sizes. Open
  before adding an action or a refresh.
- `notes/jobs.md` — detached compose jobs, the log sentinel, the verb allowlist and its two scopes.
  Open before adding a verb or anything that runs for more than a moment.
- `notes/stats.md` — the background stats collector that stops itself when the page goes away. Open
  before touching stats or the heartbeat.
- `notes/x-unraid.md` — where UI metadata lives inside the compose file and the form renderer built
  on it. Open before changing the schema or the form.

## Constraints that bite

One line each; the reasoning behind every one is in `notes/constraints.md`. Each was found the hard
way, so read the note before arguing with a line.

- **LF line endings, always.** A CRLF `.page` file or shell script fails silently.
- **`default.cfg` comments start with `;`, never `#`.** One `#` line silently rejects the whole file.
- **`_()` returns HTML, not plain text.** Never wrap it in `htmlspecialchars()`; escape only the
  values spliced into it.
- **Asset URLs carry `filemtime()`.** Without it an edited script looks like a change that did not work.
- **Every control inside the page is reset by Unraid at a specificity one class cannot beat.** Style
  controls at `.staxx-scaffold .staxx-xxx` and restate font, colour, background and radius there.
- **A redraw keeps its scroll.** Save and restore the panel's position, and when fixing one panel
  check every sibling render in the same pass.
- **Own the render.** Every class is `staxx-`-prefixed; stock Unraid classes are never borrowed.
- **`staxx.plg` is fully populated**, so nothing guards against a premature publish but judgement.

## Writing code

**Keep it light. Given two ways to reach the same result, take the shorter, simpler one.** Fewer
functions, fewer layers, fewer moving parts. Do not add abstraction for a second case that does not
exist yet, and do not build configurability nobody asked for. If a patch is getting long, that is a
signal to look for the smaller version of it before continuing.

Other conventions:

- Comments explain **why**, not what — especially where a subtle failure was diagnosed the hard way,
  since that reasoning is not recoverable from the code. Keep them brief and factual. Some existing
  comments run long; match their intent, not their length.
- British spelling in prose and comments.
- User-facing strings are full sentences that say what to do next, not error codes.
- No client-side libraries. `stacks.js` is plain browser JavaScript.
- GPL-2.0 header on every source file, matching `unraid/webgui` for an eventual upstream PR.

## Explaining things

Adrian is smart, and is not a professional developer. He owns and directs this project and makes
its decisions, so anything he cannot read easily is a document that fails at its job. Explain in
layman's terms — pitch the *vocabulary* low, never the substance.

- **Layman's English.** Say what something does, not what it is called. If a technical term is
  genuinely unavoidable, define it the first time it appears.
- **No file names, function names or line numbers.** He does not read the code, so
  `compose-model.js:1822` tells him nothing — say "the one line that made Image required" instead.
  Precision like that belongs in code comments, plan files and briefs to other agents, not in an
  explanation written for him.
- **Short analogies are welcome.** One sentence that makes a thing click is worth more than a
  paragraph of accuracy. What is not welcome is the extended metaphor that runs for a paragraph
  and has to be maintained.
- **Short and sweet.** Keep an explanation to 3-4 sentences.
- Lead with the summary, then the detail but only if asked for it. This is not a reason to leave
  detail out; it just gets an understandable way in.
- Applies to conversation, commit messages, comments and docs alike.
