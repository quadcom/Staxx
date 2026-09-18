# Long-running commands

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

Compose commands can take minutes, so `staxx_start_job()` detaches them with `setsid`, writes
output to `/tmp/staxx/jobs/<id>.log`, and returns a job id the page polls via the `job`
action. Completion is signalled by a `STAXX_JOB_END <exit-code>` sentinel appended to the log.

Verbs are an allowlist (`staxx_job_verbs()`) with separate whole-stack and single-service forms;
a verb missing a form for a given scope is refused rather than falling back to the other. A service
name is checked for *membership in the compose file's services*, not just shape, and is
`escapeshellarg`'d on top of that. Multi-step verbs join with `&&` (not `;`) and attach `2>&1` to
every step (not once at the end of the chain) — both matter for reporting real failures.
