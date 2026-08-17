# PLAN_24 — Fill in what an image can say about itself

**Status: outstanding.** Nothing here is in the tree. Written up after `PLAN_23.md` added Docker
Hub and local images as import sources, because what those two currently produce is thin. The
measurements below were taken live from the test box on 2026-08-17 — they are the point of this
document, and they should be re-checked rather than trusted if this sits for a year.

---

## What you would notice

Importing from the Community Applications catalogue gives you a working form: ports, paths,
variables, and a description beside every setting. Importing a Docker Hub image gives you four
lines — a name, the image, `restart: unless-stopped`, and a comment apologising for the rest.

That gap is not a rendering problem. It is that a CA template is a description of how to run
something, and a bare image reference is not.

This is about closing it: reading what the image, and its own documentation, already say.

## Why this fits what has been built — and where it does not

**A stack here is a compose file, not a container.** That is the decision the whole plugin rests
on, and it means a multi-service stack is native rather than a feature: the form iterates
`MODEL.services` (`stacks.js:3555`), the job runner has both whole-stack and single-service verb
forms (`Stacks.php:2016-2028`), and `03-multi-tier` in the test set runs four services with no
special handling anywhere. Drop a four-service compose file in a folder and it is a stack.

**But nothing we import can currently produce one.** A CA template describes exactly one container,
and the converter emits exactly one service to match — `ca-convert.js:639-641` pushes `services:`
and then a single service key. So the capability is real and completely unexercised by the import
paths.

The README route below is the first thing that could exercise it, which changes how a multi-service
block should be treated: not as something to reject, but as the case this was built for.

---

## What was measured

### The image's own config, without pulling it

Docker Hub's registry API hands over an image's config blob anonymously. Three real examples:

| | `linuxserver/jellyfin` | `library/nginx` | `library/postgres` |
|---|---|---|---|
| `ExposedPorts` | `8096/tcp`, `8920/tcp` | `80/tcp` | `5432/tcp` |
| `Volumes` | `/config` | — | `/var/lib/postgresql` |
| `Env` | 13 entries | 7 | 6 |
| `Labels` | 6+, incl. `org.opencontainers.image.*` | 1 | 0 |

Three requests: a token from `auth.docker.io`, the manifest (pick the `amd64` entry out of the
index), then the config blob. **The blob redirects to a CDN, so the fetch must follow redirects** —
without that it returns empty, which is how this looked impossible on the first attempt.

**For an image already on this server, none of that is needed.** `docker image inspect` returns the
same fields with no network at all — confirmed against `actualbudget/actual-server:latest`, which
reports `5006/tcp`. The local-images group is the cheap case and worth doing first.

### `Env` is a trap

What comes back is `PATH`, `HOME`, `LANG`, `GOSU_VERSION`, `PG_MAJOR` — build-time values baked
into the image, not settings anybody should set. Writing them into a compose file pins a snapshot
of the image's internals and breaks the container the day the image changes them.

And the variables that genuinely are required are **not in the config at all**.
`POSTGRES_PASSWORD` is the obvious one: postgres will not start without it, and nothing in the
image config mentions it. So the image config cannot answer "what does this need to run".

### The README, which is the better source

Docker Hub serves the repository README as `full_description`. Measured: 20 KB for
`linuxserver/jellyfin`, 25 KB for `library/postgres`, and **both contain exactly one fenced `yaml`
block**. What is in those blocks decides the shape of this:

- **linuxserver/jellyfin's is a complete, correct compose file** — image, `container_name`,
  `PUID`/`PGID`/`TZ`, four volume mappings, four ports with `#optional` against three of them, and
  `restart: unless-stopped`. Better than anything that could be synthesised from the image config,
  and every linuxserver image follows the same template.
- **postgres's is a two-service example** — `db` plus `adminer`, a placeholder
  `POSTGRES_PASSWORD: example`, and commented-out `tmpfs` alternatives.

---

## The shape of it

Use the README block, and fall back to the image config when there is no usable one.

1. Take the first fenced `yaml` block from the README.
2. Parse it with `compose-model.js`, which the browser already carries. No new parsing machinery,
   and the existing round-trip suite already covers that parser.
3. **Accept it if at least one of its services uses the image being imported.** That is the whole
   test. A block that never mentions the image is somebody's unrelated example and is rejected.
4. **Keep every service it declares.** This is the correction to the first draft of this plan,
   which proposed rejecting anything with more than one service on the grounds that postgres's
   `adminer` was not asked for. That is backwards: a database plus its admin tool *is* a stack, the
   form renders it, and refusing it would throw away the only route by which a multi-service import
   can ever arrive. The user sees the file before it is saved, and deleting a service they do not
   want is one of the things the editor is for.
5. **Show it before it becomes the file.** A README block carries somebody else's placeholder paths
   and, sometimes, a placeholder password — `POSTGRES_PASSWORD: example` must not slide into a saved
   file unnoticed. This wants the same treatment the CA importer already gives untranslatable
   flags: a warning naming what was brought in and what needs attention, and a comment block in the
   file itself so it travels with it.
6. **Fallback, when no block matches:** build `ports:` and `volumes:` from the image config, and
   nothing else. Never `Env`.

Either way the file says which of the two routes produced it, extending the comment `PLAN_23`
already writes.

### An aside worth keeping

The OCI labels give `org.opencontainers.image.description`, `.documentation`, `.source` and
`.title` for free. Those are candidates for the `x-unraid` block — the same place the CA converter
already puts an icon and a web-interface link.

---

## Open questions, to settle before building

- **Where the work runs.** The CA converter is browser-side because the browser holds the document.
  Three chained registry requests are a poor fit for a keystroke path but fine for a click. The
  server has `stackman_sh()`, `curl` and `stackman_hub_search()` (`Defines.php:302`) as precedent.
- **Every import, or a button?** Somebody who wants the bare image should still be able to get it.
- **Placeholder detection.** Whether to actively flag values like `example`, `changeme` and
  `/path/to/…`, or just show the file and trust the reader.

## Verification, when it is built

- `node tests/ca_convert.js` — the natural home for a README-block test harness, since it already
  has fixtures and a bulk runner.
- On the test box: import `linuxserver/jellyfin` from Docker Hub and confirm the file matches the
  README block; import `postgres` and confirm **both** services arrive and the placeholder password
  is called out; import something with no README block and confirm the ports-and-volumes fallback;
  import a local image and confirm no network request is made at all.
- `docker compose config -q` already validates on save, so a malformed block fails loudly.

## Left out

- Pulling an image in order to inspect it. The registry route needs no pull.
- Parsing more than the first fenced `yaml` block, or merging several.
- Importing from an arbitrary git repository. The question was Docker Hub and images already here;
  both have real APIs behind them.
- Anything that writes `Env` from the image config.
