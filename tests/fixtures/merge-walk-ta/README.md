# The third merge walkthrough — Tube Archivist, the way an Unraid user has it (PLAN_178)

A developer fixture. Nothing here ships — `pkg_build.sh` packages `src/staxx/` alone, so none of
this ever reaches a user's server.

Three Community Applications templates — `TubeArchivist`, `TubeArchivist-ES` and
`TubeArchivist-Redis` — as three separate stacks, one container each, finding each other only
through the server's own address and their published ports, because that is the only way
Unraid's own templates can wire containers together. No traps: every file reads the way StaXX's
own CA conversion writes a stack. The merge's whole job is the everyday one — put the three in
one stack and let them talk by name.

## Running it

```sh
bash install.sh          # copies the three stacks in, starts them, runs the "before" check
```

Then, in the browser: open the grid, pick `Demo-TubeArchivist`, `Demo-TubeArchivist-ES` and
`Demo-TubeArchivist-Redis`, name the merged stack `Demo-TubeArchivist-Stack` at the top level of
the `TubeArchivist` folder, take every recommended answer, both end switches on, Merge.

```sh
bash check-page.sh after   # the same five checks, plus the two rewired settings
bash teardown.sh           # removes everything this fixture made
```

## What passes

| Address | Proves |
|---|---|
| `<box>:17800/` | Tube Archivist's own web page answers |
| `<box>:17800/api/health/` | the app is up, not just its web server |
| logged in via the app's own API (`tubearchivist` / `t178-test-only`) | it reached Elasticsearch — it cannot serve its user store without it |
| `<box>:17920` with `elastic:t178-test-only` | Elasticsearch answers, cluster status green or yellow |
| `<box>:17637` (`PING` over `/dev/tcp`) | Redis answers `+PONG` |

Before the merge: all five green, with the app reaching the other two through the server's own
address. After: all five green on the first run, with `ES_URL` and `REDIS_CON` now naming
`archivist-es:9200` and `archivist-redis:6379`, the two published ports still published (the
merge's default since PLAN_170), the three appdata folders untouched and still in use, and the
three original stacks stopped and marked retired.

## What this fixture protects

- **Adrian's own real Tube Archivist is on this box**, as three Unraid-managed containers
  (`TubeArchivist`, `TubeArchivist-ES`, `TubeArchivist-RedisJSON`) with appdata at
  `/mnt/user/appdata/TubeArchivist`. This fixture's stacks, containers, ports and appdata path are
  all named `Demo-TubeArchivist*` so as never to be confused with, or touch, his real install.
- **The box is production.** `install.sh` writes only into the store's own `TubeArchivist` folder
  and `/mnt/user/appdata/Demo-TubeArchivist`, refuses rather than overwrites, never changes a
  system setting (`vm.max_map_count` included), and stops with Elasticsearch's own log lines if
  Elasticsearch fails to come up rather than trying to fix the box under it.
- **No real address or password in the repository.** The box's own address is filled in by
  `install.sh` at run time; the two passwords are the fixture's own throwaway `t178-test-only`,
  never a real one.

## The dry run, off the box

```sh
node tests/merge_walk_ta_dryrun.js --check
```

Drives the same three files through `merge-examine.js` / `merge-write.js` / `merge-suggest.js` the
way the wizard's own client-side code would, in both pick orders, entirely on the dev machine.
Asserts that `ES_URL` becomes `http://archivist-es:9200` and `REDIS_CON` becomes
`redis://archivist-redis:6379` with nothing else in those values changed, that the two published
ports survive, that the bind mounts are unchanged, that no storage-carry is needed (these are
bind mounts, not stack-managed volumes), and that the merged file parses. There are no traps in
this fixture, so no clash finding is expected at all — any that turns up is a real-world finding
about ordinary use, not a fixture mistake, and is reported rather than made to pass.
