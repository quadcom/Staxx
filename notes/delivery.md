# How StaXX is actually delivered right now

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

**Submitted to Community Applications on 2026-09-04, as beta.** That is a listing, not a file
format: it is how people find StaXX and are told it has updated. `SUBMISSION.md` records what was
proved for it; `staxx.xml` and `ca_profile.xml` in the root are what it reads, committed on `main`
directly and copied to `dev`.

**The packaging chain itself is complete, and the manifest is the way in people should be pointed
at.** Paste the manifest address into Unraid's **Plugins → Install Plugin** box and it installs like
any other plugin: it survives a reboot, and Unraid notices later versions because it re-reads the
manifest from `main`. **That is the only install route a user is ever shown.**

**The copy-to-flash-and-run-the-script route is development tooling, and stays out of anything a
user reads** — the same rule the test suites are under. It is how Adrian and every agent deploy to
the test box, and it is worth keeping precisely because it is the opposite of a release: no build,
no commit, no tag, no version number, and it will carry uncommitted work. It also does not survive
a reboot, which is a feature here rather than a shortcoming, since a reboot is the panic button when
a change breaks the webGUI. It belongs in this file and in `local/dev-server.md`; it does not belong
in `README.md`, in `docs/`, or in a release note.

**The manifest install route now exists, but it is a separate act from an ordinary change.**
`pkg_build.sh` builds the real `.txz`, and `staxx.plg` carries a real version, real checksums and a
`packageURL` pointing at a GitHub release asset. A `v*` tag push runs `publish.yml`, which builds
that package, stamps the manifest and publishes both as a proper GitHub release — but that only
happens on a deliberate tag, never as a side effect of an ordinary commit. **Building a package,
stamping checksums and publishing a tagged release are still not steps in shipping a change** —
they are a separate, occasional act of cutting a release, done when a version is ready to be
installable by manifest, not on every push. Ask before cutting one.

Two facts worth not rediscovering: `pkg_build.sh` must run on Linux, since the package carries Unix
permissions and ownership; and `v1.1.0` is a public release that **does** carry its `.txz`, so the
manifest route worked at 1.1.0 — it was `v1.2.0` that was cut without running the packager, leaving
the manifest naming a package nobody uploaded and still carrying 1.1.0's two checksums. That is the
exact failure `publish.yml`'s agreement checks exist to make impossible.

CI (`release.yml`) runs every gate on each push to `main` and `dev` and publishes nothing. It used
to publish a rolling tarball of the deploy bundle; that was retired when `dev` became a real install
channel, because it was the only thing that ever put `dev-install.sh` in front of the public. The two
workflows are deliberately separate and neither should grow into the other.
