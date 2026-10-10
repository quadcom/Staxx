# The second merge walkthrough — six small stacks behind one front door (PLAN_169)

A developer fixture. Nothing here ships — `pkg_build.sh` packages `src/staxx/` alone, so none of
this ever reaches a user's server.

Six stacks, nine services, deliberately unlike the first walkthrough — a reverse proxy routing by
label, an external network, anchors and aliases, a one-shot container, and clashes planted in
service names, container names, router labels, a published port and an env-file key. The merge's
job is to put all six behind one front door without losing or breaking any of it.

| Stack | What it is for |
|---|---|
| `t169-edge` | the front door — a reverse proxy routing by label to services in other stacks |
| `t169-site` | the static page the check script reads |
| `t169-api` | turns the database into JSON, reached through the edge |
| `t169-store` | the database, seeded with three rows |
| `t169-bus` | a message broker, plus a one-shot that publishes one message and exits |
| `t169-tools` | a log viewer and an idle service, in one file written with anchors and aliases |

## Running it

Copy this folder to the box under `/tmp`, then:

```sh
bash install.sh          # copies the six stacks in, starts them, runs the "before" check
```

`install.sh` refuses unless `DEV-TESTING` exists and every port and address the six stacks need is
still free. It starts everything except `idle` (`t169-tools`'s second service), which is left
stopped on purpose — it clashes with the broker's own port until the merge puts everything in one
stack, exactly the shape a person has when they stopped a service to get out of another one's way.

Then, in the browser: open the grid, pick all six stacks, name the merged stack `t169app` in
`DEV-TESTING`, approve every card (18 decisions on the last run), both end switches on, Merge.

```sh
bash check-page.sh db:5432 20000   # after the merge; db:5432 is the rewired database address,
                                    # 20000 is whichever port the merge moved "idle" to
bash teardown.sh                    # removes everything this fixture made
```

`teardown.sh` finds the merged stack whether it landed as `t169app` at the store's top level or
inside `DEV-TESTING`.

## What passes

| Address | Proves |
|---|---|
| `<box>:19080/` | the edge routes through to the static site |
| `<box>:19080/api/greetings` | the edge routes through to the API, which reaches Postgres |
| `<box>:19081/` | the edge's own dashboard answers |
| `<box>:19432` | Postgres answers, still holding its three seeded rows |
| `<box>:19883` | the broker answers, the one-shot's retained message still readable |
| `127.0.0.2:19800` | the site's own debug page answers on its own address |
| `127.0.0.3:19800` | Dozzle answers on its own address — the same port as the line above, legal because each is on an address of its own |
| `<box>:19090` (broker) | the broker's websocket listener answers, unmoved by the merge |
| `<box>:20000` (idle) | `idle`'s own page answers on the port the merge moved it to |

Plus: all six original stacks stopped and marked retired, the external network still there and
still external, and every moved or kept port matching the web address recorded against its own
service.
