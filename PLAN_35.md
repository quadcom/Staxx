# PLAN_35 — bringing containers you already run into StaXX

**Status: DRAFT, awaiting Adrian. Do not start.** Written 2026-08-18 alongside PLAN_33 and PLAN_34.
**Blocked on PLAN_34 phase 1** for a third of the real cases — see "The blocker" below.

## What you would notice

You already run containers. On the test box, 76 of them. A new page offers you a list of every one,
says where it came from, and lets you tick the ones you want. StaXX writes each as a compose file in
a stack folder, shows you the form, and — until you say so — **changes nothing**. The container keeps
running exactly as it is. Adopting it is a separate, deliberate second step.

## The three sources, measured on the real box

| Source | How many | How it is stored |
|---|---|---|
| Unraid's own Docker page | **69** | An XML template per container at `/boot/config/plugins/dockerMan/templates-user/my-<Name>.xml`; the running container is labelled `net.unraid.docker.managed=dockerman` |
| Compose Manager plugin | **5** | A folder per project at `/boot/config/plugins/compose.manager/projects/<slug>/` holding `name`, `docker-compose.yml`, sometimes `docker-compose.override.yml`, `.env`, `autostart`; containers labelled `composeman` |
| Neither | **2** | Made by hand or by something else. Only `docker inspect` knows anything about them |

86 templates exist for 69 running containers — the extras are for containers that have been removed.
Offer those too, marked as not currently running; a template is a perfectly good thing to import.

## Source 1 — Unraid templates. Most of the work is already done.

This is the happy accident that makes the plan small. **A Community Applications catalogue entry and
an Unraid user template are the same XML document** — `<Container>` with `Repository`, `Network`,
`Privileged`, `ExtraParams`, `PostArgs`, `WebUI`, and a list of `<Config>` entries typed Port, Path,
Variable, Device or Label. StaXX already converts one of those into a compose file, with all the
hard parts solved: the `docker run` argument string, the appdata path rewriting, the port and volume
mapping, the `x-unraid` metadata block.

So source 1 is: **parse the XML into the object shape the existing converter already takes, and call
it.** What that converter has never had to handle, because catalogue entries never carry them:

- **`<MyIP>` and `<MyMAC>`** — the fixed address and hardware address. This is the blocker.
- **`<Network>`** naming a real Unraid network (`br0.2`, `mybridge`, `eth0.2`, `host`) rather than a
  catalogue placeholder. It needs a declaration in the file: `external: true` for anything Unraid
  made, which is all of them.
- **The user's own edited values.** A `<Config>` element's text content is what the user set;
  `Default=` is what the template shipped with. Import the text content. Where the two differ, that
  difference *is* the user's configuration and is the whole point.
- **`Display="advanced"`**, `Required=`, `Mask=` — carry these into `x-unraid` so the form can show
  the setting the same way Unraid did. `Mask="true"` marks a password: never put it in a comment.
- **`<TailscaleStateDir>`, `<CPUset>`, `<Shell>`, `<DateInstalled>`** — Unraid-only, no compose
  equivalent. Keep in `x-unraid` rather than dropping them.

## Source 2 — Compose Manager. It is already a compose file.

The file is already what we want, so importing it is mostly a copy with the file left byte-for-byte
intact — which is exactly the never-destroy-a-hand-authored-file rule, applied to someone else's
file. Three real complications:

- **The override file.** Compose Manager writes `docker-compose.override.yml` beside the main one
  and compose merges them at run time. Merging them ourselves would change what runs. Copy both,
  keep both, and tell the user the stack has two files — the companion-file support already exists.
- **The `.env` file.** Copy it. Values in it are frequently secrets.
- **Where the file actually is.** The running container's `com.docker.compose.project.config_files`
  label gives the absolute paths compose is really using, which on the test box is
  `/mnt/user/appdata/compose_projects/<name>/`, not the flash folder. **Trust the label over the
  folder**; the flash copy can be stale. One project there has a `docker-compose.yml` containing the
  single word `services:` — an empty shell. Read the label, and skip a project whose file has no
  services rather than importing an empty stack.

## Source 3 — containers with no template

`docker inspect` gives the truth about a running container, and the truth is *more* than the file
that created it: Docker fills in defaults for everything. An honest import writes only what was set
deliberately, which means diffing the inspect output against the image's own config
(`docker image inspect`) and keeping the differences. Environment variables are the sharp case — an
image declares a dozen of its own, and re-emitting them as if the user chose them makes an
unreadable file that also breaks the day the image changes them.

Two containers on the test box are in this state. Treat this as the third phase and offer it with a
clear warning that the result needs reading before use.

## The blocker

**26 of the 86 templates carry a fixed IP, and the same containers carry a fixed hardware address.**
Nearly a third. StaXX cannot currently write either, so importing those today would produce a
compose file that quietly puts the container somewhere else on the network — the worst possible
outcome, because it looks like it worked.

Two ways forward, and the first is better:

1. **Do PLAN_34 phase 1 first.** It is the smaller half of that plan and it is needed anyway.
2. Import them with the addresses in a comment and the stack marked as needing attention. Only
   worth doing if the import tool has to ship before PLAN_34.

## Safety — the part that must not be got wrong

This tool reads a production server's container list. Every one of the rules below exists because
breaking it loses somebody's running service.

- **Import never touches Docker.** No start, no stop, no create, no remove, no pull. It reads files
  and writes a stack folder. Nothing else.
- **Import never touches the source.** The Unraid template stays where it is. The Compose Manager
  project stays where it is. Both plugins keep working; the container keeps running.
- **A container is not adopted by importing it.** A running container carries the project label of
  whatever made it, so an imported stack reads as stopped even while its container runs. Say so on
  the row, plainly, rather than letting a green dot be wrong.
- **Adoption is a separate act with its own confirmation**, and it means: stop the old container,
  bring the stack up with compose, and — only if that succeeds — remove the old container. It is a
  destructive operation on something that is currently working, so it gets a job with a log, one
  container at a time, and it stops at the first failure.
- **Never both.** Two things managing one container is how a container ends up deleted twice. If
  adoption succeeds, remove the Unraid template (after offering a copy) so the Docker page stops
  claiming it.
- **Names collide.** A stack folder named `plex` may already exist. Refuse, do not overwrite.
- **Secrets get copied.** A template's `Mask="true"` fields, a `.env` file, an API key in a
  variable. They land in a file under the stack root. Say so before the import runs, not after.

## Phases

1. **The list.** A page that shows all three sources with what each one is, where it came from,
   whether it is running, and what StaXX can and cannot yet represent about it. Reads only. This
   alone is useful, and it is how the rest gets tested.
2. **Unraid templates.** Reuse the existing converter. Ships behind PLAN_34 phase 1, or with the
   fixed-address warning.
3. **Compose Manager projects.** Copy, do not rewrite. Includes the override and `.env` files.
4. **Adoption**, as its own guarded job.
5. **Containers with no template**, from `docker inspect`, diffed against the image.

## Where the code goes

`include/Import.php` for reading the three sources server-side, mirroring what `CA.php` does for the
catalogue. `javascript/template-import.js` for the XML-to-compose step, sitting beside `ca-convert.js`
and calling into it. New endpoint actions: `import-list`, `import-read`, `import-write`, and later
`adopt`. Adoption goes through the existing job runner with its own verb, not through a new path —
the allowlist is the safety property and it must not be worked around.
