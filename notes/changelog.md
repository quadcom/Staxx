# The changelog is written as the work lands, not at release time

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

`CHANGELOG.md`'s top section is `## Unreleased`, and **a change that a person would notice gets its
bullet there in the same commit as the change itself.** This is not bookkeeping deferred to release
day: a dev build publishes that section verbatim as its own release notes, so a change with no
bullet is a change nobody outside this repository is ever told about. `publish.yml` refuses a dev
build whose `Unreleased` section is empty, for exactly that reason.

Cutting a stable release **renames** that heading to `## <version> — released <date>` — the section
is not written then, it has been filling up all along. The build refuses to publish a stable release
while the heading still says `Unreleased`.

Three consequences worth stating, because each one is a mistake somebody would otherwise make:

- **One file, no per-branch copy.** `main` only ever receives commits by merging `dev` at a release,
  so its changelog cannot change at any other time without anyone having to remember anything. A
  separate dev changelog would become a third thing to put back by hand at every merge, alongside the
  readme banner and the branch entity — and those two are already the step most often forgotten.
- **Bullets are user-facing prose, not commit subjects.** Say what a person can now do and what it
  means for them. The commit message is for whoever reads the code; this is for whoever runs it.
- **The feedback board's changelog mirrors `main`'s**, published by hand at release time from the
  same section. It is the only one of the three that reaches people who never look at the repository,
  and publishing it is visible to everyone at once — so it stays a deliberate act, never a build step.
