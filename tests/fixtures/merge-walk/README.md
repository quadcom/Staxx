# The merge walkthrough

A throwaway four-stack website that has to keep answering at the same address after it has been
merged into one. It is the end-to-end test for the merge wizard: run it after any change to the
wizard, against the same site, and check the same page before and after.

**Developer tooling. Nothing here ships** — `pkg_build.sh` packages `src/staxx/` alone, so no part of
`tests/` reaches a user's server, and none of it belongs in `README.md`, `docs/` or a release note.

The design, the traps planted in the fixture and the history of the five walks are in
`plans/completed-plans/PLAN_156-merge-walkthrough-the-four-stack-site.md`. This file is how to run it.

## What it is

Four stacks in `DEV-TESTING`, all named `t155-*` so they sort together and read as disposable:

| Stack | Runs | Publishes |
|---|---|---|
| `t155-web` | nginx, plus a PHP image built from a Dockerfile | `18080`, `18443` |
| `t155-db` | MariaDB, seeded with one table | `13306` |
| `t155-cache` | Redis, plus Redis Commander behind a profile (dormant) | `16379` |
| `t155-admin` | Adminer, from a compose file **with a live override** | `18081` |

The page on `18080` reads a row out of the database and a counter out of the cache, and prints which
address each came from. That is the instrument: it says whether the wiring survived before any file
is opened.

## The test that decides pass or fail

Adrian's rule, 2026-09-17, sharpened 2026-09-18: **every address that answered before the merge must
answer after it.** Not "did it write a file", and not just the front door — each merged stack
published its own ports, and every one of them has to keep working once they are one stack. A single
address gone quiet fails the walk. `check-page.sh` is that rule in a script: the page on `18080`, TLS
on `18443`, Adminer on `18081`, and the database and cache proved through the page itself — which also
shows the merged services now talk to each other by service name rather than through the box's
address.

## Running it

Off the box first, since it costs nothing and catches most breakage:

```sh
node tests/merge_walk_dryrun.js          # examine, build and apply against the same fixture
node tests/merge_walk_dryrun.js --check  # plus the step 4 shape
```

On the server (credentials and the deploy loop are in `local/dev-server.md` and `notes/deploy.md`).
Copy this folder to the flash drive, then:

```sh
bash install.sh        # copies the four stacks into DEV-TESTING and starts them
bash check-page.sh     # before the merge: everything answers through the box's own address
```

Now drive the wizard in the browser: pick all four, name the new stack, choose its folder, answer
every decision on steps 3 and 4, turn on both switches at the end (stop the originals, start the new
stack), and press Merge. Then:

```sh
bash check-page.sh db:3306 cache:6379    # after: the same page, now wired by service name
bash teardown.sh                         # removes the four, the merged stack, volumes and images
```

`check-page.sh` prints the page it fetched, then one line per check. Anything other than all
`pass` lines is a failure to chase, and the page's own text usually says which half broke.

**Known gap, 2026-09-18.** The script checks the site's own addresses, not the ports the four sources
each published — so a walk reads green even though the database's published port is gone after the
merge. Under the pass rule above that is a failure, not a detail. The script grows the full inventory
when `PLAN_170` is built, which is also where the merge stops unpublishing a port it only knows is
surplus to the stacks inside the merge.

## What each script refuses to do

The box is a production server, so all three are deliberately timid:

- `install.sh` stops if the store has no `DEV-TESTING` folder, if any of the five ports is already
  in use, or if any `t155-*` stack is already there. It writes nowhere but `DEV-TESTING` and a
  testing folder of its own in appdata.
- The box's own address is **not** in the committed fixture. `install.sh` fills it in from the
  server at install time, so no real address is ever in the repository.
- `teardown.sh` is safe at any stage and names every path it removes. It knows both names the
  merged stack has been given — the scripted walk's and Adrian's own.

## Two things the fixture learnt the hard way

- **Files arrive from the flash drive owner-only**, whatever mode they were saved with, so nothing
  inside a container can read them. `install.sh` re-opens them after copying.
- **The file mapped in from outside its own stack lives at a real appdata path**, not as a relative
  climb out of the store. A path like `../DEV-TESTING/…` in the merged file reads as the file being
  moved, which is exactly the confusion a walk is meant to avoid. The relative-path rewrite itself is
  covered off the box, in `tests/merge_examine.js`.
