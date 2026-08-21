# Submission to-do

What has to be true before StaXX can be published and listed in Community Applications.
Checked against Unraid's submission guide, its starter repository and the Community
Applications policies on 2026-08-21.

Nothing here is code work unless it says so. Tick things off in place.

---

## 1. Decisions only you can make

- [ ] **The repository name, and the account that owns it.** Decide once and never change
      it. Renaming the repository or the account after being listed is treated as a possible
      hijack and blacklists everything you publish, with no warning. Same for moving it to
      an organisation later — do that before submitting, not after.
- [ ] **The author name** as you want it shown in the plugin list.
- [ ] **A support link.** A thread on the Unraid forums is the norm; a Discussions page on
      the repository is accepted. Something has to be there — it is where users will be sent.
- [ ] **A category.** One line, chosen from Unraid's own list. `Tools:System` fits; the
      Application Categorizer plugin generates valid values if you want to browse them.

## 2. Turn on two-factor sign-in

- [ ] Enable two-factor authentication on the GitHub account that will own the repository,
      and tell the Community Applications moderators it is on. This is a hard requirement,
      not a suggestion — plugins run as root on other people's servers, so they vet the
      account as well as the code.

## 3. Fill in the installer's placeholders

The installer file deliberately carries loud placeholders so a premature publish fails
rather than fetching from somewhere unintended. Four to replace, once section 1 is settled:

- [ ] Author name
- [ ] Repository (it builds both the update address and the download address)
- [ ] Support link
- [ ] Both checksums — these are produced by the build script; run it with the stamping
      option and it writes them in for you

## 4. Build a real package and check it

- [ ] Run the build script on the server with the stamping option. It prints both checksums,
      writes them into the installer, and includes the readme in the package.
- [ ] Install from the built package, not the dev script, at least once. That is the path
      real users take and the only one that exercises the installer file itself.
- [ ] Then uninstall it and confirm nothing is left behind: no scheduled jobs mentioning
      StaXX, no leftover temporary folder, settings and stacks still where they were.

## 5. Two things fixed but not yet proven

Both need a deliberate, destructive step on the server, so they are yours to green-light:

- [ ] **Uninstall really clears the schedule.** Remove the plugin, then confirm the server
      has no StaXX jobs left in its timetable.
- [ ] **A missing takeover template only warns.** Move the template aside, turn the
      "replace the Docker button" setting on, save. Expected: a warning, a successful save,
      the stock Docker button still there. Put the template back afterwards.

## 6. The listing itself

Community Applications does not read the installer file directly. It scans a public
repository for two things, both of which you have to add — they can live in this repository
alongside everything else:

- [ ] **`ca_profile.xml` in the root.** A short description of what this repository offers,
      plus optional icon, web page, support link and donation link. This is the "about the
      author" card users see.
- [ ] **`plugins/staxx.xml`** — a small wrapper describing the plugin for the listing.
      Required: the display name, a plain-text overview, the address of the installer file,
      and the author. Strongly worth filling in too: project page, readme, category, licence
      (GPLv2), an icon, and one or two screenshots.
      **The installer address in this file must match the one inside the installer file
      exactly** — a mismatch fails their scan.
- [ ] **Mark it as beta.** There is a flag for exactly this, and pre-alpha software that is
      not labelled is a policy breach. Set it, and clear it when you are ready.
- [ ] An open-source licence file in the root — already done, GPL-2.0.
- [ ] Repository must be public and show recent activity.
- [ ] Submit at `ca.unraid.net/submit`, running their Validate and Scan steps, then again
      after any change to those two XML files.

## 7. Worth doing before anyone else installs it

- [ ] **The changelog.** It currently has one entry from the day the scaffold was written,
      and it is what users read before pressing update. Every release needs a line.
- [ ] **The version stamp** moves with each build; the build script handles it, but do not
      publish two different packages under one version number — the update check compares
      nothing else.
- [ ] **Screenshots.** The listing is a wall of text without them, and this is a visual
      plugin.
- [ ] **An honest first line in the overview.** Something that says early software, expect
      rough edges. It sets expectations and it is what the beta flag is for anyway.

---

## Rules worth knowing, so nothing is a surprise

- Closed source is refused outright. Not an issue here.
- Anything that would be better as a container cannot be a plugin. Not an issue — this is
  webGUI work.
- Project links may not be referral or affiliate links.
- Every app in a repository needs a distinct name.
- Malicious code, or trying to inject anything into the interface beyond what the plugin
  declares, means immediate removal and a blacklist covering everything you publish.

## Already satisfied

The installer file is valid and installs, upgrades and removes cleanly; the settings file is
owner-only; a failed settings step no longer fails an install; both checksums and a readme
ship with the package; line endings are correct throughout; and the plugin never modifies a
stock Unraid file, including when it replaces the Docker button.
