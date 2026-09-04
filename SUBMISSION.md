# Submission to Community Applications

What has to be true before StaXX is listed, and what is left. First written 2026-08-21 against
Unraid's submission guide, its starter repository and the Community Applications policies;
rewritten 2026-09-04 after the 00.02.00 release, when most of the original list had been done by
the release machinery rather than by hand.

Tick things off in place.

---

## Left to do

- [ ] **Submit** at `ca.unraid.net/submit`, giving the repository address, and run their Validate
      and Scan steps. Run them again after any later change to `staxx.xml` or `ca_profile.xml`.
- [ ] **Tell the moderators two-factor sign-in is on** for the `quadcom` account if the form asks.
      It is on.
- [ ] **Clear the beta flag** in `staxx.xml` when the software no longer needs it. That is the
      moment the `01.00.00` conversation starts, and it is Adrian's call alone.

## Decided

- **Repository and owner:** `quadcom/Staxx`. Never rename or move it after listing — that reads as
  a hijack and blacklists everything under the account.
- **Author name:** Quadcom.
- **Support link:** the feedback board, `https://staxxfb.quadcom.ca`, on its own. Unraid's norm is a
  forum thread and a Discussions page is also accepted; the board was kept alone deliberately
  (2026-09-04). If the moderators push back, open a forum thread and add it.
- **Category:** `Tools:System`.
- **Listed as beta**, with both listing texts opening on a plain sentence that this is early
  software. The flag tells the system, the sentence tells the person.

## Done, and how it was proved

- **Installer placeholders** — author, repository, support link, both checksums — are all real,
  stamped by the release build. The stale "placeholder repository" note is gone.
- **A real package, published and proved.** 00.02.00 was cut through `RELEASING.md` end to end on
  2026-09-04. The manifest on `main` names the package, the package downloads, and the checksums
  match. The dev channel was proved the same way the same day.
- **Install and removal through the manifest, on a real box (2026-09-04).** Installed with
  `plugin install <main manifest>`: package installed, settings applied, Docker signed into Hub, the
  hourly update check and weekly cleanup scheduled, the page rendered, 79 containers untouched.
  Removed with `plugin remove staxx.plg`: package, plugin folder, manifest, package copies,
  schedule and temp folder all gone; settings on flash, the data store and every container left
  exactly as they were. Docker is signed out of Hub on removal and signed back in by the next
  install, which is the intended shape.
  *One trap, dev boxes only:* the deploy script's registration marker is an empty file where Unraid
  keeps a link, and `plugin remove` silently runs nothing until it is replaced by the link. A real
  user's box never has the marker. Recorded in `CLAUDE.md`.
- **Uninstall clears the schedule** — no cron entry mentions StaXX afterwards. Proved in the same
  removal.
- **Listing files** exist in the root and are reachable on `main`: `ca_profile.xml` (the repository
  card, with icon, web page, support link and three photos that load) and `staxx.xml` (name,
  overview, installer address, support, project, icon, category, beta). The installer address in
  `staxx.xml` matches the one inside the installer exactly.
- **Licence** GPL-2.0 in the root; repository public with activity.
- **Changelog** kept as work lands, published verbatim by every dev build, renamed at each stable
  release; the plugin manager's own notes block carries the same text.
- **Install banner** says something true, and the removal message now says where stacks actually
  live (the data store, not flash) — that wording ships in the next release.
- **Screenshots** exist for the listing and throughout the user guide.

## Not proved, and knowingly left

- **A missing takeover template only warns.** Move the template aside, turn "replace the Docker
  button" on, save; expect a warning, a successful save, the stock Docker button still there. Needs
  a deliberate step on the box and has not been run.

---

## Rules worth knowing, so nothing is a surprise

- Closed source is refused outright. Not an issue here.
- Anything that would be better as a container cannot be a plugin. Not an issue — this is webGUI
  work.
- Project links may not be referral or affiliate links.
- Every app in a repository needs a distinct name.
- Malicious code, or injecting anything into the interface beyond what the plugin declares, means
  immediate removal and a blacklist covering everything under the account.
- Renaming the repository or the account, or moving it to an organisation, after listing is treated
  as a possible hijack. Do it before submitting or not at all.
