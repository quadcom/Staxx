# PLAN_43 — autostart and start order

**Status: approved 2026-08-19.** Outstanding. Phases below are the work list; tick them off here as they land.

## Context

Unraid decides what starts at boot from one plain list of container names, walked top to bottom,
each name optionally followed by a number of seconds to wait before the next. StaXX does not touch
that list today, so a stack StaXX creates never starts at boot — the gap PLAN_35 flagged and
deferred. This plan closes it by putting the switch, the order and the wait in StaXX's own list,
and keeping Unraid's file as the thing that actually holds the answer.

The design was settled with Adrian on 2026-08-19: one order for both display and boot; a wait
offered at folder, stack and service level; entries StaXX does not manage left alone; a switch per
service as well as per stack.

## What you would notice

Every folder, stack and service row grows a grip on its left edge. Drag a folder and everything in
it moves with it. Drag a stack within its folder, or a service within its stack, and it lands where
you put it. Arrow keys on the grip do the same thing, for when a mouse is awkward.

The order you see is the order things start in at boot — the list is now telling you that, rather
than being alphabetical and silent about it.

Each row's menu gains **Start at boot**, and **Wait after this…**, which asks for a number of
seconds. A row that starts at boot carries a small marker; a stack where only some services start
says so.

Flip a switch on Unraid's own Docker page and StaXX shows it the next time the page loads, and the
other way round. There is one list, not two.

## The bridge

A stack is not something Unraid's list can name, but every service in a stack is a container with a
name — so a stack is a run of consecutive lines in that list.

**Down.** Walk the tree: folders in order, stacks within each folder in order, services within each
stack in order. Each service that starts at boot contributes one line. Unraid's list is StaXX's
list with the indentation removed.

**Up.** A container name tells us its stack and its service. A stack's position is where its first
line sits; the service order inside it is the order those lines appear.

**The join is the container name**, worked out from the compose file when Docker is down — the
explicit name if the file sets one, otherwise the name compose would generate from the project and
service — and taken from Docker's own labels when Docker is up, which is also what makes a service
scaled past one container come out right. Because the name is computable before the container
exists, a brand-new stack can be given autostart immediately: Unraid's boot script skips names that
do not exist rather than failing, so it simply starts working once the stack has been up once.

**Nothing is stored twice.** Whether something starts at boot, and its wait, live only in Unraid's
file, read fresh on every render. StaXX's own file holds only positions — because Unraid's list can
only order the things that start at boot, and the rest still need a place in the list.

**Two things it cannot round-trip, both stated in the UI rather than hidden:**

- A flat list can interleave one stack's services with another's, which the tree cannot express.
  Such a stack is shown by where its first service sits, and its lines are pulled back together the
  next time anything is dragged. Only hand-reordering on Unraid's Docker page can cause it.
- Unraid starts containers with `docker start`, not `docker compose up`. A stack whose containers
  have been removed is not rebuilt at boot, and compose's own `depends_on` is not consulted — which
  is precisely why the manual service order earns its keep.

## Storage

`folders.json`, via the existing `staxx_folders_load()` / `staxx_folders_save()`. Bump to
`version: 3`. **`staxx_folders_load()` must read the new keys or the next save silently erases
them** — the one-way door its own header comment warns about.

```json
{
  "version": 3,
  "collapsed": { "Media": true },
  "start": {
    "folders":  ["Services", "Media"],
    "stacks":   { "": ["glances"], "Services": ["npm", "vaultwarden"] },
    "services": { "Services/npm": ["db", "app"] },
    "delay":    { "folder:Services": 10, "service:Services/npm/db": 5 },
    "seen":     "<md5 of the autostart file as StaXX last saw it>",
    "pending":  false
  }
}
```

Lists, not numbers: a drag rewrites one list, nothing is renumbered, and a name nobody has dragged
needs no entry. Anything absent from a list sorts after everything present, by today's natural
sort — so an install with no `start` key looks and behaves exactly as it does now.

`delay` holds only non-zero values, keyed by level so the numbers Adrian typed are the numbers he
sees back even when two of them share a line.

## Who wins, and when

- **On render:** read the file. If it differs from `seen`, somebody changed it elsewhere — adopt its
  order and its waits into `start`, and update `seen`. If `pending` is set, project instead (see
  below) and clear it.
- **On a drag or a toggle:** write `start`, then project into the file and update `seen`.
- **Docker stopped:** `/var/lib/docker` is not mounted, so the file cannot be read or written — a
  write there would land in RAM and vanish. Guard every access with the existing
  `staxx_docker_running()`. Dragging still works and still saves; it sets `pending`, and the
  projection happens the next time the page renders with Docker up.

Projection is idempotent: build the desired content, compare, write only on a difference.

## Writing the file

Rebuild it whole, from the tree:

- Every line whose container is **not** in a StaXX stack is kept, in its existing order.
- Every line that is becomes one contiguous run, inserted at the index where the first such line
  sits today, or appended if there are none. On Adrian's box those twelve lines are already
  clustered at positions 19–36, so almost nothing moves the first time.
- A line's wait is the sum of what lands on it: the service's own wait, plus the stack's if it is
  that stack's last starting service, plus the folder's if it is also the last starting service of
  the folder's last starting stack. Written as `name seconds`, or bare `name` when the sum is zero.
- Adopting back: if a line's wait does not match the sum StaXX would have written, the whole number
  is taken as that service's wait and the group waits that landed on it are cleared.

## Files

**New — `include/Autostart.php`.** Everything above, and nothing else. Reads and writes
`/var/lib/docker/unraid-autostart` behind a define that a test can point elsewhere, the way
`STACK_ROOT` already works. Roughly: read the file; resolve a stack's service-to-container names;
report per-stack and per-service state including a stack's all/some/none; project the tree; set a
switch; set a wait; adopt. Reuse the container listing already in `Defines.php` for the live
project/service/name map rather than adding another `docker ps` — extend its format string if the
service label is not already captured.

**`include/Folders.php`** — carry `start` through load and save; sort in
`staxx_folder_layout()`, which is already the single point where display order is decided; keep the
lists honest in `staxx_folder_rename`, `staxx_folder_assign`, `staxx_folder_delete` (members merge
into the top-level list) and the stack rename path. Do not prune on load: a stack that is briefly
absent should not lose its place.

**`include/Stacks.php`** — `staxx_compose_meta()` also captures each service's `container_name`, for
the offline name. Service rows come out in the stored order, falling back to compose document order.

**`include/StacksTable.php`** — a leading grip track on the row grid, with a grip on folder, stack
and container rows; the boot marker in the state cell. Follow the doctrine already written into
`staxx_row_actions_html()`: the control is drawn on **every** row, disabled with a title saying why,
never omitted — omitting it pulls the name column out of alignment.

**`include/action.php`** — three cases beside the existing `folder-collapse`:
`start-order` (`scope`, `parent`, and the complete sibling order as one `;`-joined string, following
Unraid's own `UserPrefs` precedent — StaXX's names cannot contain a semicolon), `autostart`
(`scope`, `stack`, `service`, `on`), `autostart-wait` (`scope`, key, `wait`). Each replies `{ok}`;
the page then calls the existing `rows`, whose comment already anticipates a reorder.

**`javascript/stacks.js`** — the drag is a straight copy of the port-row reorder from PLAN_40, which
already solves the parts that bite: `draggable` set only while the grip is held so text selection
survives, disarming on both `pointerup` and `dragend`, `setData` for Firefox, hiding the row one
tick late for Chrome's drag image, a placeholder the same height to stop the gap oscillating, and
Arrow/Home/End on the grip doing the same move. The toggles go on the existing delegated click
handler; the wait dialog reuses the confirm scaffolding with a number field.

**Docs** — `docs/x-unraid-schema.md` keeps its dividing line (this is machine policy, so none of it
goes in the compose file) but its line saying service display order is document order needs the
"unless StaXX has been told otherwise" clause. `README.md`/`docs/README.md` gain a paragraph.

## Phases

Sonnet agents, one phase at a time, Opus reading each diff before the next starts.

1. `Autostart.php` plus the `folders.json` store, the layout sorting and the rename/move/delete
   upkeep. No UI. Verifiable on the server without a browser.
2. The three endpoint cases.
3. Markup and CSS: grip track, boot marker, menu items.
4. The browser side: drag, keys, toggles, wait dialog.
5. `tests/server/autostart.php` and the doc updates.

## Verification

Local, before every deploy: `node --check` on both browser files, `node tests/js_undeclared.js`,
`node tests/yaml_roundtrip.js`, `python tests/validate_schema.py`.

On the server, `php -l` over `include/*.php`, then a new `tests/server/autostart.php` run against a
copy of the real file in `/tmp` — projection is idempotent; a foreign line keeps its place and its
wait; a stack toggled on adds exactly its services; the summed wait lands on the right line and
comes back apart the way it went together; an interleaved stack is reported and then gathered; a
stack with no containers yet still projects a usable name.

Then by hand, since it is a drag: `/Docker/Stacks`, drag a folder, a stack and a service, reload and
confirm the order held; check Unraid's own Docker page shows the same order and switches; toggle one
there and confirm StaXX picks it up; stop the Docker service, drag, start it, reload, confirm the
drag survived. `diff` the file against a saved copy at each step — 58 lines, so the whole thing is
readable.

**Do not test by rebooting.** The box is production. Reading `/var/lib/docker/unraid-autostart`
against what StaXX would write is the whole proof; Unraid's boot script does nothing more than walk
it.
