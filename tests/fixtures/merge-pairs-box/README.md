# The round-two torture run, on the box

**Developer-only. Nothing here ships** — `pkg_build.sh` packages `src/staxx/` alone, and none of
this is mentioned anywhere a user reads.

PLAN_169's "round two" table (23 more ways to trip the merge wizard) is proven two ways: the small
source pairs under `tests/fixtures/merge-pairs/r2-*` are run off the box through
`tests/merge_trip.js`, straight against `buildMergedText()`. This folder puts the same 20 pairs
onto the box as real stacks, so every one of them can also be walked **through the wizard itself**
— picking, answering cards, pressing Merge — which is the only way to prove the four rows that
only exist in the browser or the server (R2-16, R2-17, R2-20 to R2-22) and to catch anything the
off-box probe cannot see (a missing file, a refusal that reaches the person oddly, and so on).

## Copy up and install

Copy this folder and its neighbour `tests/fixtures/merge-pairs/` up to the box together —
`install.sh` looks for `../merge-pairs` next to itself, exactly as `tests/merge_trip.js` does
locally. Then, from inside the copied `merge-pairs-box/` folder on the box:

```sh
bash install.sh
```

It refuses unless `DEV-TESTING` already exists and none of its own targets do yet, and it never
runs `docker` at all — nothing is started or pulled. It prints every stack path it made. Two
pairs are installed under names that differ from the plain `r2-<name>-a` / `-b` shape, and it also
writes one small support folder; both are explained in its own header comment:

- `r2-project-name-casefold` → `R2-PROJECT-NAME-CASEFOLD-A` and `r2-project-name-casefold-a` (both
  folding to the identical Compose project name once lower-cased — the whole point of the row).
- `r2-self-merge` → `r2-self-merge-a` only; the fixture has no second side.
- `DEV-TESTING/shared/{common,base}.yaml` — small placeholder files so `r2-include-extends`'
  `include:`/`extends:` paths resolve; not a stack.
- `r2-secret-clash-a/secrets/a-creds.txt` and `r2-secret-clash-b/secrets/b-creds.txt` — placeholder
  files so the `secrets:` block in each side resolves.

## The walk

For every ordinary pair (all of them except `r2-self-merge`, `r2-name-equals-source` and
`r2-project-name-casefold`, which have their own box-only rows below): in the StaXX webGUI, open
the merge wizard, pick both of the pair's two stacks (`r2-<name>-a` and `r2-<name>-b`), accept
every card's **default** answer, name the new stack `r2m-<name>`, and make sure **both switches at
the end are OFF** — nothing here is meant to stop or start anything. Press Merge, then on the box:

```sh
cd "$DEST/r2m-<name>" && docker compose config -q
```

It must accept the file with no output (compose resolves; nothing is pulled). If it doesn't,
that's a real merge fault — note which pair and what `config` said.

A note on `r2-secret-clash`: if `docker compose config -q` fails on `r2m-secret-clash` because one
of the two `secrets/*-creds.txt` files is missing from the merged folder, that means the merge's
file-copy step doesn't carry a `secrets:` file target across the way it does an icon — copy the
missing placeholder into the merged folder by hand to keep walking, and record it as a finding
distinct from R2-3 (which only checks the *name* gets renamed, not that the file itself travels).

## The box-only rows

These four sit in the browser or the server rather than in `buildMergedText()`, so
`tests/merge_trip.js` only records them as SKIP. Walked here instead:

- **R2-14** — `healthcheck: {disable: true}`. Merge `r2-healthcheck-disable-a` /
  `-b` as an ordinary pair (above). On step 5, confirm the service carrying `disable: true` is
  never offered a health-check suggestion.
- **R2-16** — the new stack's name equals a source's own name. Pick `r2-name-equals-source-a` and
  `-b`, but name the new stack `r2-name-equals-source-a` itself (not `r2m-name-equals-source`).
  Must be refused before anything is written.
- **R2-17** — a single stack, or a stack merged with itself. Open the wizard with only
  `r2-self-merge-a` picked. Must be refused before anything is written. (If the picker will not
  let the same folder be picked twice, that half of the row cannot be reached through the UI at
  all — note that rather than forcing it.)
- **R2-19** — a source with a YAML error. Try to pick `r2-yaml-error-a` (deliberately
  mis-indented). Must be refused at pick time, by name, with the line.
- **R2-20** — a source edited between the wizard reading it and Merge being pressed. Pick
  `r2-env-form-mix-a` / `-b`, open the wizard, then edit `r2-env-form-mix-a`'s compose file in the
  stack editor (another tab) before pressing Merge. The wizard must notice the change and ask to
  re-read, rather than write from the stale copy.
- **R2-21** — Merge pressed twice, or two merge windows open at once. Pick any ordinary pair (e.g.
  `r2-volume-identity-a` / `-b`) and either click Merge twice quickly, or open the wizard in two
  browser tabs against the same pair. One merge must run; the other must be refused.
- **R2-22** — a source stopped, or one of its containers unhealthy, when the merge starts. Use
  `r2-profile-shared-a` / `-b` (only `alpine:3.20`, already pulled by other suites on this box).
  Start `r2-profile-shared-a` with `docker compose up -d`; leave `-b` stopped. Merge both with
  "stop the originals" ON. It must still work — an already-stopped source is not a failure.
- **R2-23**, box half — undo. Merge `r2-retired-roundtrip-a` / `-b` with "stop the originals" ON,
  so both sources are retired (their services gain the `retired` profile and its comment). Take
  that profile and its comment back out of one source by hand; the file must read exactly as it
  did before the merge, byte for byte, and `docker compose up -d` must start it again. (The text
  half of this row — that the reversal really is byte-exact — is already proven off the box in
  `tests/merge_trip.js`; this only proves the container starts again.)

## Things to watch for, not merge faults

- `r2-network-mode-sidecar` pulls `linuxserver/wireguard:latest` and `qmcgaw/gluetun:latest`. Its
  images resolve and `docker compose config -q` accepts the file, but **never actually start**
  either container from this fixture** — both need `NET_ADMIN` and a real `/dev/net/tun`, which
  the fixture deliberately leaves out (the row is about a `network_mode: "service:vpn"` reference
  following a rename, not about running a working VPN).
- `r2-yaml-error/a/compose.yaml` is deliberately broken YAML (a mis-indented line) — that is the
  point of R2-19, not a mistake to fix.

## Teardown

```sh
bash teardown.sh
```

Safe to run at any stage. Removes every `r2-*` and `r2m-*` stack under `DEV-TESTING` (and
`R2-TORTURE`, if a walk ever created one by hand), running `docker compose --profile '*' down -v
--remove-orphans` in each one first, then `DEV-TESTING/shared/`'s two support files once nothing
else needs them. It never touches anything else in `DEV-TESTING`.
