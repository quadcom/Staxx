# Long-running commands

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

Compose commands can take minutes, so `staxx_start_job()` detaches them with `setsid`, writes
output to `/tmp/staxx/jobs/<id>.log`, and returns a job id the page polls via the `job`
action. Completion is signalled by a `STAXX_JOB_END <exit-code>` sentinel appended to the log.
Every job launcher starts through `staxx_spawn_job()`, so the log's first line and its
permissions are written in one place.

Verbs are an allowlist (`staxx_job_verbs()`) with separate whole-stack and single-service forms;
a verb missing a form for a given scope is refused rather than falling back to the other. A service
name is checked for *membership in the compose file's services*, not just shape, and is
`escapeshellarg`'d on top of that. Multi-step verbs join with `&&` (not `;`) and attach `2>&1` to
every step (not once at the end of the chain) — both matter for reporting real failures.

## Updates run through one queue

Every update, whether Update all, a folder, a selection or one app's own Update button, is an item in
the queue (`staxx_update_queue_add()` / `staxx_update_queue_start()` in `include/UpdateRun.php`).
Starting while a queue is live appends instead of refusing, and a stack + service already waiting,
running or being checked is not added twice. One stack runs at a time, in the order added.

An item is `{stack, service, verb, state, job, error, started, checkStart, finished}` (times are unix
seconds, 0 when not yet; a part-running stack also carries `only`, the services that were running).
`verb` is `update` or `pull`, chosen once by `staxx_update_verb_for()`. Every reply carrying `queue`
also carries `now`, the server's clock, so the page counts down without trusting the browser's.

A job that exits 0 does not make the item `done`: it becomes `checking`, and each tick judges the
stack's containers (`staxx_update_check_verdict()`). Done needs every container running 10 s after the
start, plus a passing health check (waited for up to 2 minutes) where one is defined. Otherwise the
item fails with a plain sentence in `error` and `reason`. Nothing else starts until the check settles;
a `pull` starts nothing, so it skips the check. With no page open the 15-minute cron tick drives the
check, so an unattended one can wait for the next pass. A server suite stands in for Docker's
container list with `STAXX_UPDATE_CHECK_ROWS` (a JSON file of rows).
