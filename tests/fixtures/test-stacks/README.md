# Test stacks

Throwaway compose files for testing the plugin. **Not tracked by git** — the
whole `scratch/` folder is ignored, so nothing here ends up in the repository.

Every stack uses a plain base operating-system image (Alpine, Debian, Ubuntu,
Fedora, BusyBox) that sits and does nothing. They are tiny, they download in
seconds, and they cannot break anything. The point is not what they run — it is
what their compose files look like.

Each one is in its own folder, and each declares its own `name:`. That name
becomes the label Docker stamps on the containers, which is the thing the UI
groups by. Two stacks in the same folder would share a name and defeat the
test.

---

## What each one is testing

| Folder | Containers | Host ports | The question it asks |
|---|---|---|---|
| `01-alpine-minimal` | 1 | 15101 | With no metadata at all, is the form still usable? Everything must be worked out from the file itself. |
| `02-debian-annotated` | 1 | 15102 | The opposite extreme — a note on every setting, and both markers used. |
| `03-multi-tier` | 3 | 15103–15105 | Do three related containers stay grouped together? Also: one service is fully documented, one partly, one not at all. |
| `04-ubuntu-secrets` | 1 | 15106 | Are exactly the four values marked `-!S` hidden, and every look-alike left showing? Nothing may be guessed from a name. |
| `05-busybox-http` | 1 | 15107 | Values come from a `.env` file rather than the compose file. Serves a real page. |
| `06-fedora-advanced` | 2 | 15108–15109 | The long-hand spellings of ports and volumes, plus devices, labels, and an optional service. |
| `07-yaml-quirks` | 2 | 15110–15111 | **The important one.** Can the form save a change without wrecking a hand-written file? |
| `08-deliberately-broken` | — | — | Does a bad file give a clear error instead of a blank page? |
| `11-companion-files` | 1 | 15112 | A folder holding more than its compose file — a `.env`, a binary, an orphan, a subdirectory. One tab each, except the directory, which never gets one. |
| `12-missing-companions` | 1 | 15113 | A file that names companions which are not there. Both must be flagged, and creating the env one must prefill it. |
| `13-swarm-notes` | 1 | — | Settings Docker only honours in a swarm cluster. Each must say so rather than look effective. |
| `14-long-forms` | 2 | 15114–15115 | Every long form in one file, valid on purpose — each row editable, and saving it back changing nothing. |
| `15-long-forms-broken` | — | — | Its twin, with the two entries that used to vanish from the form altogether. |
| `16-doc-marker` | 1 | — | A document marker (`---`) above the compose content. |
| `17-uncovered-keys` | 3 | — | Keys with no label table entry of their own. Each must still read as a sentence, never as a raw key. |
| `18-declaration-shapes` | 2 | — | The five shapes a network declaration can take — a flow map, an anchor, an alias — where choosing a driver used to write a file compose could not read. |
| `19-missing-network` | 2 | — | A service joining a network the file never declares, beside one that joins a network it does. Only the first gets the note and the button that writes the declaration. |

Host ports run 15101–15115 with no overlaps, so every stack can run at once.
Several of the later fixtures publish nothing, because what they test is how the
file reads in the form rather than anything that happens once it runs — and
`19-missing-network` is a file compose refuses outright, which is the whole point
of it.

**A symlink inside a stack folder has no fixture here, and cannot have one.** A data
store left unchosen on flash rather than a share or pool is vfat and cannot hold a
symlink at all — `symlink()` there simply fails. That case is covered instead by
`tests/server/links.php`, which points `STORE_ROOT` at `/tmp` for the length of one
run. It is worth knowing about, because deleting a stack walks the folder, and a
symlink followed rather than unlinked would take a real share with it.

---

## The two that matter most

**`07-yaml-quirks`** is the real test. It is written the way a person writes a
file: comments everywhere, blank lines for spacing, shared settings defined
once and reused, a mix of quoting styles. Saving a change through the form must
alter that one field and nothing else.

The way to check it:

```sh
cd 07-yaml-quirks
cp compose.yaml compose.yaml.before   # keep an untouched copy
# ...now change one field in the UI and save...
diff compose.yaml.before compose.yaml
```

One field changed is a pass. Comments gone, settings copied out of the shared
block, or lines reordered is a fail — and the `.before` copy shows exactly what
was lost.

**`05-busybox-http`** separates the file from the running container. The file
says the port is `${HTTP_PORT}`; the `.env` file next to it says `HTTP_PORT` is
15107; the running container reports 15107. Whatever the form shows, saving must
not replace `${HTTP_PORT}` with `15107` — that would hard-code the value and
break the file for anyone else using it.

---

## Running them by hand

From inside any stack's folder:

```sh
docker compose config          # show the settings Docker actually worked out
docker compose config --services
docker compose up -d
docker compose ps
docker compose logs -f
docker compose down
```

All of them at once, from this folder:

```sh
for d in 0*/; do (cd "$d" && docker compose up -d); done
docker ps --format '{{.Names}}\t{{index .Labels "com.docker.compose.project"}}'
for d in 0*/; do (cd "$d" && docker compose down); done
```

That second line prints exactly what the plugin reads to group containers, so
it is a quick way to confirm grouping will work before any UI exists.

Two stacks serve a real page you can open in a browser:
`http://<server>:15103/` and `http://<server>:15107/`.

`06-fedora-advanced` has a second container held back behind a *profile* —
an optional extra that only starts when asked for:

```sh
docker compose --profile extras up -d
```

`08-deliberately-broken` will fail, which is the point. Leave it alone.

`09-gpu-encode` is the only stack here that does real work: it runs a hardware
video encode so the GPU column has a number to show. It stops by itself after
ten minutes and is set to `restart: "no"`, so it will not start again on its
own or sit on the encoder.

---

## GPU test stacks

Three stacks carry a graphics card, to cover the cases separately:

| Stack | Card | What it should show |
|---|---|---|
| `03-multi-tier` | AMD (`renderD129`) | mapped but idle — a red AMD chip and `0.0%` |
| `05-busybox-http` | Intel (`renderD128`) | mapped but idle — a blue Intel chip and `0.0%` |
| `09-gpu-encode` | AMD (`renderD129`) | genuinely busy — around 38% while running |

Every other stack has no GPU and its GPU cell stays blank, which is the point:
the column is reserved for containers that actually have one.

Node numbering is **not** fixed. `renderD128` and `renderD129` are handed out in
probe order and may swap between machines or after a reboot. The plugin reads
the vendor from `/sys/class/drm/<node>/device/vendor` rather than assuming, but
these test files have to name a node, so they name whichever was correct when
they were written. Check with:

```sh
for n in /sys/class/drm/renderD*; do
  echo "$(basename $n) -> $(cat $n/device/vendor)"   # 0x8086 Intel, 0x1002 AMD
done
```

---

## Notes

- `./data/...` folders are created by Docker on first run. Delete them freely.
- `docker compose down -v` also removes stored data. Safe here; nothing is real.
- `docker compose config` prints a tidied-up copy of the settings and leaves
  your own file alone. It **keeps** the `x-unraid` sections — checked against
  `02-debian-annotated`, whose two entries both survive it — which is exactly
  why the plugin can read its metadata out of this command's output rather than
  parsing the file itself. What it does not survive is a file compose refuses:
  the command fails and prints nothing, so nothing can be read from a broken
  file this way.
