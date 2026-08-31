# Releasing StaXX

A runbook. Follow it in order. Developer material — nothing here belongs in `README.md`, in `docs/`,
or anywhere a person using StaXX would read it.

Two channels of the same plugin. Which one somebody is on is decided by the address they pasted into
**Plugins → Install Plugin**, and they can switch by pasting the other.

| | stable | development |
|---|---|---|
| Install address | `.../quadcom/Staxx/main/staxx.plg` | `.../quadcom/Staxx/dev/staxx.plg` |
| Version | `00.02.00` | `00.02.00_dev20260831` |
| Tag | `v00.02.00` | `v00.02.00_dev20260831` |
| Cut by | pushing a tag, deliberately | pressing a button; it dates and tags itself |
| Notes | hand-written, and checked | generated from commit subjects |
| How often | seldom | as often as you like |

---

## The one fact everything rests on

**Unraid compares plugin versions with `strcmp`, not `version_compare`.** Read it yourself in
`/usr/local/emhttp/plugins/dynamix.plugin.manager/include/ShowPlugins.php`: for a plugin the test is
`strcmp($latest,$version) > 0`. Only Unraid's *own* OS version gets a numeric comparison.

What it compares is the `<!ENTITY version>` **inside the manifest** — not the tag, not the release
title. It fetches the manifest from the address the person installed from and compares it with the
copy they have.

So a version has to sort correctly **as plain text**. Everything below follows from that.

### Why every component is two digits

Unpadded, `1.10.0` sorts *below* `1.5.0`, because `1` comes before `5` one character in. The day a
minor version reached double digits, Unraid would silently stop offering updates to everybody — no
error, no sign, just nobody updating ever again.

Fixed-width fields make text order and number order the same thing. `00.01.00`, `01.00.00`,
`01.10.00`. Never `1.10.0`. The ceiling is 99 per component, which `publish.yml` still guards.

Padding has a second benefit: `01.02.00` is not the same name as `1.2.0`, so the whole scheme is
clear of the tag names burnt while releases were immutable.

### Why the dev marker is a date, and why `_dev` not `-dev`

A date is fixed width, so text order and time order are the same. A counter would need padding and
would break silently the day it overflowed it.

An underscore because **a Slackware package filename cannot hold a hyphen in its version.**
`upgradepkg` splits the name on hyphens from the right, so `staxx-01.04.00-dev...-noarch-1.txz` reads
as a package *named* `staxx-01.04.00`, and it would stop recognising new packages as replacing the
old one.

### Why the tag name is not free-form

The manifest builds its download address as
`.../releases/download/v<version>/staxx-<version>-noarch-1.txz`. The tag must be **`v` plus the
version exactly**, or the download 404s. That is how 1.2.0 shipped broken.

---

## Cutting a development release

**Nothing to prepare. It is one button.**

1. GitHub → **Actions** → **Publish tagged release** → **Run workflow**.
2. Branch: **`dev`**. Leave the version box **empty**.
3. Run it.

It reads the version `main` is heading towards from the manifest, appends today's date, checks that
tag is free (adding `b`, `c`, … if you have already cut one today), runs every gate, builds the
package, stamps the manifest, writes the notes from the commit subjects since the last dev build,
commits the stamped manifest back to `dev`, and publishes.

**Do not type a version by hand** unless you have a specific reason. A dated version typed by hand
is how a tag gets a typo in it.

---

## Cutting a stable release

Four things must say the same number before anything is built. The build checks all four and refuses
if they disagree, but doing them in this order means it never has to.

1. **Decide the number.** Patch = fixes only. Minor = new features, nothing on disk changes shape.
   Major = the user must act, because something stored on their server changes shape or a setting
   behaves differently. `01.00.00` is the first release meant for general use.

2. **On `dev`**, in one commit:
   - `CHANGELOG.md` — a new `## <version> — released <date>` section at the top, in the house voice.
   - `staxx.plg` — set `<!ENTITY version>` to the new number, and add a `###<version>` section at the
     top of the `<CHANGES>` block. That block is what Unraid's Plugin Manager shows.
   - Push `dev`.

3. **Merge `dev` into `main`.** Expect a conflict in `README.md` whenever the development banner's
   wording has changed. Resolve it by taking dev's copy whole — `git checkout dev -- README.md` —
   and then removing the banner from it. Never hand-resolve the markers.

4. **Put back both per-branch values in the merge commit** — see the section below. This is the step
   most likely to be forgotten.

5. **Push `main`**, and wait for its `Check` run to go green. If the branch entity was missed, this
   is where you find out.

6. **Tag and push**: `git tag -a v<version> -m "StaXX <version>" && git push origin v<version>`.

7. **Watch the run.** Then verify the install route actually works rather than assuming:

   ```sh
   curl -fsSL https://raw.githubusercontent.com/quadcom/Staxx/main/staxx.plg | grep -E 'ENTITY (version|branch|packageMD5)'
   curl -fsSIL -o /dev/null -w '%{http_code}\n' \
     https://github.com/quadcom/Staxx/releases/download/v<version>/staxx-<version>-noarch-1.txz
   ```

   The manifest's two checksums must match the package you can actually download. Nothing short of
   that proves the release.

8. **Bring the stamped manifest back to `dev`.** The build commits the new checksums to `main` only.
   `git cherry-pick -x <the "Stamp staxx.plg" commit>` — it touches only the version, the two
   checksums and the package name, never the branch entity, so it is safe to carry across.

9. **Set dev's manifest to the *next* version you are heading towards**, and push. See the gotcha
   below.

---

## The two per-branch values

Both live in files that merge freely, and both are wrong the moment a merge carries them across.

| Value | On `dev` | On `main` |
|---|---|---|
| The development banner in `README.md` | present | removed |
| `<!ENTITY branch>` in `staxx.plg` | `dev` | `main` |

The banner is cosmetic. **The branch entity is not** — it decides which branch an installed plugin
polls for updates, so `dev` reaching main's manifest would quietly start offering development builds
to everyone on the stable channel.

It is not silent if you forget: the `Check` workflow reads the entity on every push and fails if it
does not name the branch it is running on. But between the merge and noticing, main's manifest is
served exactly as committed. Put both back in the merge commit.

---

## The gotcha after a stable release

A dev build takes its base from the manifest. Straight after a stable release the manifest says the
version just released, so the next dev build would come out as `00.02.00_dev...` when `00.02.00` is
already out. It still sorts correctly and nothing breaks — but the name claims to be heading
somewhere it has already arrived.

**So step 9 above is not optional.** After a stable release, set dev's `<!ENTITY version>` to the
next version you intend, and the dev builds after it will be named honestly.

## Why dev can never run ahead of main

Worth stating, because the opposite is the usual worry. Dev builds are distinguished by **date**, not
by a counter, so fifty dev builds towards `00.02.00` are all `00.02.00_dev<date>` — no version
numbers are spent. The base only advances when you deliberately decide what the next stable release
is. Dev at `01.50.00` while main sits at `01.09.00` cannot happen.

---

## What the build refuses, and what each refusal means

Every one of these fails the run before anything is published.

| Refusal | What it means |
|---|---|
| not the padded shape | You used `1.4.0`. Two digits per component. |
| tag and committed manifest disagree | Step 2 was skipped, or the tag has a typo. **This is the 1.2.0 failure.** |
| does not sort above the newest release out | The number goes backwards, or you found the 99 ceiling. |
| branch entity does not match the channel | A merge carried the other branch's value across. |
| changelog's newest heading disagrees *(stable only)* | The changelog entry is missing or misnumbered. |
| no `###<version>` in `<CHANGES>` *(stable only)* | The Plugin Manager would show the wrong notes. |

**A failed run publishes nothing and burns nothing.** Nothing is tagged until there is a release to
attach the tag to. Fix the cause and run it again.

---

## Things that are permanent, or were

Release immutability is **off**, so a tag can be deleted and reused and a botched release can be
re-cut. It was on until 2026-08-31, and everything published in that window is permanent: `v1.1.0`,
`v1.2.0` and `v1.3.0` can never be used again as tag names, whatever is deleted — disabling
immutability does not unlock tags already made immutable, and neither does deleting the repository.
The padded scheme steps around all of them.

Two consequences that cannot be fixed from here, only known about:

- Anyone still running a **dated** build (`2026.08.26` and earlier) or an **unpadded** one (`1.x`)
  will never be offered a padded release, because both sort above `00.x` as text. They would have to
  install again from the address. Nobody was, which is why the switch happened when it did.
- A **development user is never offered the stable release**, because `00.02.00` sorts below
  `00.02.00_dev20260831`. Switching to stable means pasting main's address, which installs it
  outright. That is the right shape for a channel switch, and it is deliberate.
