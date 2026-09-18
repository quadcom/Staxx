# Background stats

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

`scripts/stats-collector.sh` samples `docker stats` and GPU tools out-of-band, because
`docker stats --no-stream` takes ~2s on a 60-container server. It is **not a daemon**: the page
writes a timestamp into a heartbeat file each time it asks for stats, and the collector exits once
that goes stale (45s). Close the tab and sampling stops on its own. Snapshots are written to a temp
file and moved into place, so a reader never sees half of one. Locking is an atomic `mkdir`.
