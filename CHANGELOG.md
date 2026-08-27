# Changelog

What changed, newest first. Versions match the plugin manifest. From 1.1.0 they are
numbered; everything before it was dated.

---

## 1.1.0 — released 2026-08-27

The release that makes update checking trustworthy. StaXX now asks image registries directly
instead of going through Docker, which means it asks the right one, asks far less often, and
finally works for registries other than Docker Hub — including one you run yourself.

- Update checks now go to the registry the compose file actually names. A container hosted on
  ghcr or lscr was being asked about at Docker Hub, which wastes the one allowance that is
  genuinely tight and would have given a wrong answer the day the two drifted apart. Version
  numbers follow the same rule now, not just the fingerprint.
- Checks are cheaper and better paced. StaXX asks "has this changed since last time?" rather
  than "what is it?", so an unchanged container no longer re-reads everything about itself — three
  questions per container per check became one. A container pinned to an exact version is never
  asked at all, one on a numbered version waits a week, and only the moving tags keep the
  six-hourly rate.
- A container whose check keeps failing now says so, after five failures in a row, with how many
  and over what period — and eases off to once a day, so a repository that has gone away stops
  costing four questions daily. Every fortnight the shortcut is thrown away and the full question
  asked again, so a registry with a broken answer cannot hide an update behind it forever.
- Update checking now works against a registry on your own network, even without a proper
  certificate. Name the host in Settings and only that host is reached over plain http, or trusted
  on a certificate it made itself — no wildcards, nothing guessed, and a password is never sent to
  a host reached in the clear.
- Registries that behave unusually are handled rather than failing quietly. One serves nothing to
  identify an image by, so its fingerprint is worked out from exactly what it sent; one needs no
  sign-in at all; one asks you to sign in on a completely different address. All measured against
  twelve real registries, and every fingerprint proved identical to what Docker itself reports.
- The update badge is fixed twice over. It could only be pressed on the first draw of a row, so it
  went dead the moment you used it — a repaint replaced the button with something unpressable. And
  it came back after a successful update, because half of the comparison was still last night's
  answer about a tag that had since moved. Where the two halves disagree, the registry is now asked
  again, a few containers at a time; one that cannot be reached leaves the previous answer alone
  rather than inventing a new one.

---

## 2026.08.26 — released 2026-08-26

- Added the groundwork for filling in a stack's icon, description and links automatically: a new
  internal step that writes those fields into a compose file as commented-out placeholders with a
  short hint beside each, ready to uncomment and fill in.
- Every new stack — from Community Applications, an imported template, Docker Hub, a local image,
  or the blank starting point — now gets these fields written in automatically before you ever see
  it. Pasting a whole compose file into a new stack does the same the moment it lands. An existing
  stack with none of these fields shows a note in its editor offering to add them, and a new
  "Add missing StaXX fields to every stack…" button in Settings does the same across every stack at
  once, showing what would change before anything is written.
- Setting the web interface port now removes the leftover "not set yet" comment for it, instead of
  leaving a dead placeholder sitting above the real line.
- A service that had none of these fields yet is left with an empty block until something is
  uncommented into it, which reads as "nothing set" rather than a real setting — the metadata check
  now accepts that shape at the container level. Measured directly against Docker Compose: an empty
  or filled-in block never changes whether a running container is considered up to date with its
  file.

- A stack now owns its icon instead of guessing it afresh on every render: the picture is copied in
  beside the compose file and its name written into that service's own settings. So a stack keeps its
  look when the folder is copied to another machine, and a change to the icon collection cannot
  silently alter how it appears. Only where nothing was chosen by hand, one stack at a time through
  the ordinary save — so each file keeps its previous version and the page says what it changed —
  and there is a switch to turn the whole thing off.
- The editor now shows each service's own icon in its heading, at a size worth looking at, since that
  is the screen where an icon is chosen. A service with nothing recorded shows a letters tile rather
  than a guess, which is the useful signal, and the tile links out to the icon collection.
- An icon address copied from a file's page on GitHub now works. It is turned into the address of the
  picture itself before anything is fetched, instead of quietly failing — the page is HTML, not an
  image — and then never being retried.
- The note offering to add missing StaXX fields now names which ones are missing, in words rather
  than setting names, instead of claiming a file has none of them when it plainly holds several.
- A stack no longer reports its own container as the thing already using a port it wants. Which
  containers belong to the stack being edited is now read from the label Docker records, not guessed
  from container names — a guess that never matched a stack converted from an Unraid template, since
  those name their containers outright.
- A password can now be made up for you in the box that needs it, as a passphrase or a random string,
  and turned into the hashed form some containers ask for. The hashing runs in StaXX's own tiny
  container, built only when asked, with no volume, no port and no network, and the password is
  handed to it on its input stream rather than on a command line anyone listing processes could read.
  Four hash formats, each refused until a self-test has proved it works on this machine.
- StaXX now notices when two services in one stack share a password or a folder, or name each other,
  and asks whether that is deliberate. Say yes and the answer is kept in the stack's own file, so it
  travels with it; from then on, changing one side writes the other to match, both boxes and the file
  together inside a single Undo.
- The buttons on the offer to point a new stack at a database living in another stack now work. They
  did nothing at all before, and said nothing about why.
- A container's name is now recognised whatever case it is written in, the same way Docker itself
  resolves it, both for a container in another stack and one named inside the same file. This is not
  loose matching: a shared password is still compared exactly, because two passwords differing only
  in case are two passwords.
- An update that has been installed is now noticed at once. The "update ready" badge was drawn from a
  remembered comparison that nothing refreshed after a pull, so an update that plainly succeeded kept
  reading as pending until the next scheduled check came round. The automatic update queue had the
  same blind spot, and could re-apply a stack it had already finished every fifteen minutes.

## 2026.08.21 — released 2026-08-22

The first version worth writing down. Nothing before this was packaged or tagged, so this entry
covers the state of the whole plugin rather than a list of differences.

### Where it stands

In daily use on the author's own server, and far enough along to judge on its merits. What it is
not yet is packaged: installing means copying the plugin files onto a server yourself, there is no
one-click install, and it is not listed in Community Applications. The installer manifest still has
deliberate blanks where the author name, repository and checksums go, so a premature publish fails
loudly instead of quietly shipping something broken.

### The stack model

- A stack is a folder holding a compose file. The page is a direct view of one folder on the
  server; no database, no index, no metadata kept alongside.
- Folders one level deep, created and renamed as ordinary directories.
- Running containers are matched back to their folder even after the folder has been moved.

### The editor

- Form, file, and both side by side, with the highlight following between them.
- More than twenty groups of settings, switchable, with anything the form cannot take apart shown
  in an Advanced block rather than hidden.
- Comments become help text and help text becomes comments; two marks for "secret" and "required".
- Suggestions, hover help, and find-and-replace in the file view.
- Advisory checks that never block a save: missing folders, unfillable placeholders, missing
  companion files, ports already in use, and the file put past Docker itself.
- Pickers for a folder, a timezone, and a device from this server's hardware.
- Every file in the stack folder editable as a tab, including binary uploads such as certificates.
- Sanitise, which hides values marked secret for screenshots and locks the window while on.

### The stacks page

- Per-container processor, memory, network and graphics figures with sparklines. Graphics is read
  per container for Intel and AMD cards; Nvidia is recognised but reports no figures.
- Per-container state, uptime and health.
- Web page, logs, project page and support thread buttons on every row.
- Badges for updates, withdrawn tags, registry moves, drift from a source, and an image mismatch.
- Rows follow Docker's own event stream rather than polling; the statistics sampler stops itself
  when no page is watching.

### Getting apps in

- Search across Community Applications, Docker Hub, and images already on the server.
- Community Applications templates converted to compose files, with descriptions kept as comments
  and anything that cannot be translated named in a warning.
- Installs made through Unraid's own Apps page can be caught and made into stacks.
- Existing containers imported from Unraid templates or Compose Manager projects, arriving locked
  until reviewed.

### Running things

- Start, stop, restart, update and remove, per stack or per container, detached with streamed
  output.
- Restart rebuilds only what no longer matches the file.
- Per-container live log, root command line, and a file browser inside the running container.

### Updates

- Registry checks that tell an ordinary update from a moved base image, a withdrawn tag or a
  changed registry.
- Comparison against the example compose file an image's own author publishes.
- Show, notify, or install automatically, with a delay, a countdown, a quiet time, a queue, and
  per-stack overrides.
- Roll back to a kept previous version; automatic clean-up of images nothing needs.
- Optional Docker Hub sign-in to raise the hourly check limit.

### Everything else

- Autostart written into and read back from Unraid's own boot list, with drag-to-order and
  per-entry pauses.
- Removing a stack archives its whole folder as a zip rather than deleting anything.
- One settings panel covering placement, storage, what may leave the server, and updates.
- Optional takeover of the Docker menu, which modifies no Unraid file and is fully reversible.

---

## Before this

The work landed as a long series of small pieces, each recorded with its reasoning in
`completed-plans/`. That folder is the real history for anything earlier than this entry.
