# Changelog

What changed, newest first. Versions match the plugin manifest.

**The top section is `Unreleased`.** Work lands there as it is done, and a development build
publishes that section as its own release notes — so a change with no bullet in it is a change
nobody outside this repository is told about. Cutting a stable release renames that heading to the
version and its date; the build refuses to publish while it still says `Unreleased`.

**Numbering changed at `00.01.00`.** Every component is now two digits, and the count restarted from
`00.01.00` on the way to `01.00.00` — the first release meant for general use. The padding is not
decoration: Unraid compares plugin versions as plain text, so `1.10.0` would sort *below* `1.5.0` and
updates would silently stop being offered. Fixed-width numbers make text order and number order the
same thing. Everything above `1.1.0` in this file used the old unpadded numbering, and the versions
before that were dates.

---

## Unreleased

- **The address column lists only ports that are actually reachable.** It shows the ports Docker is
  forwarding, plus the one port a service's web page answers on. Stacks with their own network
  address, or on host networking, no longer carry a row of internal ports nothing outside can reach.

- **The update status now lives at the top of the page, not above the table.** Last checked, how
  many updates are waiting, and how many author-example findings there are now show as small tags at
  the right end of the title bar, next to "StaXX". The explanatory paragraph that used to sit above
  the table is gone — the store's location is already on the Settings page — and the table now starts
  a little higher, so one more row fits on screen.

- **On a tablet-sized window, the stack list is now a grid of cards instead of a squeezed table.**
  Each stack gets its own card — icon, name, state, address and its little graphs, three across —
  and a folder becomes a heading above its own row of cards. A stack running several services shows
  a cubes button in its corner; tap it to see those services as cards of their own, without the page
  underneath changing shape.

- **The stack table reads better on a wide screen.** Ports now stand in their own column beside the
  address instead of running on in one long line; a stopped stack's dash lines up under its column
  heading instead of sitting off to one side; the line under the column headings is now one
  unbroken rule instead of breaking into pieces; stack rows are a little taller, with a bigger icon
  and bigger WebUI/Logs/Repo/CA buttons that are easier to click; and an update pill now always
  reads "update ready", with a small tag icon showing when it also knows exactly which version —
  hover it to see both.
- **Hovering an update pill now shows a small card in the page's own style instead of the browser's
  plain tooltip.** The running and available versions, when it was last checked, when it is next
  due and why it is checked as often as it is now lay out as a short table instead of one long
  sentence. An image whose first check already found an update pending — Radarr was the one that
  gave this away — now shows its running version too, read straight off the container rather than
  left blank.
- **The page no longer grows a second scrollbar of its own.** Collapsing a stack or a folder was
  leaving a sliver of invisible extra room on the page, on top of your browser's own scrollbar —
  fixed at the source, so both scroll the same amount again.
- **Tidy no longer refuses a file over the commented example StaXX itself writes into it.** A
  trailing note indented under a setting now travels with that setting instead of being treated as
  a stray line — so a freshly scaffolded stack tidies cleanly instead of being refused outright. Its
  result is also shown as a proper notice above the file, not just on the small status line below it.
- **An image you haven't downloaded yet is no longer asked about at update checks.** There was
  nothing on your server to compare a registry's answer against, so the question was always wasted —
  it is now skipped, and asked promptly as soon as the image is actually here.
- **Checking for updates no longer spends any of your Docker Hub allowance.** It asks for a build's
  headers only, which Hub does not count — the allowance is only spent when you actually install an
  update.
- **Checking now runs every hour, asking only the images that are due, with a full look at every
  image at the time you chose.** Choosing "Once a week" used to mean each image was only asked about
  weekly; it now means the once-a-week full look happens then, while how often any one image is
  actually asked is still decided by how often it tends to change.
- **The settings panel now shows what each registry has actually been asked, and what it cost** —
  under Image updates, next to the check schedule. Being refused by Docker Hub now means something
  else on your address spent the download allowance, since a check itself costs nothing, and StaXX
  tries again within the hour rather than waiting for the next pass; the refusal notice on the stacks
  page says so and links straight to the new figures. Hovering an update pill also now says when that
  image was last asked, when it is next due, and why.
- **The wide bar of Intel and AMD graphics figures above the stack list is gone**, and a stack
  whose file asks for a graphics card now carries a coloured badge on its own row instead — Intel
  blue, AMD red, NVIDIA green. That badge stays even while the stack is stopped, so you can see
  which stacks have hardware in them without starting them, which the old bar could never show.
- **The user guide is now a manual rather than a description.** It opens with a walk round the
  screen you land on — every button, column, mark and menu item named, with a close-up picture of
  each — and a matching walk round the editor and the settings panel. The rest of the pages are
  step-by-step: adding an app from the catalogue and bringing in a container you already run are
  written as numbered walkthroughs you can follow with the screen in front of you. Everything is
  written shorter and plainer, and where one page shows something another page explains, the two are
  now linked.
- **StaXX's own mark now sits in the corner of its page**, in place of the stock icon Unraid gives
  any plugin, so the page says whose it is at a glance.

- **A running container that nothing is checking can be offered a health check.** A green row only
  ever meant the container was going, never that the app inside was working. Click the pill on such a
  row — or use the button in the editor's health check section — and StaXX works out a question worth
  asking that image: a check written against a known database's own documentation, one the project
  published itself, or a plain fetch of the app's web page. It tries the candidate inside the running
  container first, so a check that cannot run is never offered. Where it knows no real question, it
  says so and offers nothing, because a check that can only ever say yes turns "nobody knows" into
  "looks fine". Nothing is applied without you accepting it, and the check it writes is ordinary
  compose that travels with the file to any machine.
- **A check says what it actually proves.** A web-page check tells you the page answered and nothing
  about whatever sits behind it; a database check has logged in and run a real query. The offer names
  which claim you are getting before you accept it.
- **A Community Applications template that carried a health check now keeps it.** It used to be
  explained away with a note saying compose had an equivalent, and then discarded.
- **Every compose file is laid out the same way, once, as it arrives.** However a file got here, its
  sections and each container's settings are put into one order, with a blank line between sections.
  The button in the editor brings an older stack into line. Order only: no line's text is ever
  rewritten, comments travel with the setting they sit above, blank lines are only ever added, and
  containers are never reordered against each other. Where StaXX cannot be sure a move would leave
  the file meaning the same thing, it leaves that part alone and says so.
- Fixed a device row's Notes box floating above the device's path box, and showing the word "Notes"
  twice — once as the column heading, once as a repeated hint under the box. Both boxes now line up,
  and "Notes" appears once.
- **The settings panel is now the size of the stack editor, with its controls split across five
  tabs** — General, Storage, Icons and images, Updates, and Registries and security — instead of one
  long scrolling list of twenty-five controls.
- **Starting a stack for the first time now shows the image downloading**, instead of a "Starting…"
  pill that never changed. While the image comes down the pill reads "Downloading image…" (with a
  rough count of how much has arrived, when compose reports it), and clicking it opens the same
  running log a failed start already offers. A brand-new stack started from the editor's "Save and
  start" now shows this from the moment its row appears, and it survives refreshing the page mid-download.
- **Two stacks that would end up sharing the same name are now caught before it happens.** If a
  stack folder's name matches one that already exists somewhere else in your store, a "shares the
  name" mark now appears in the state column explaining which folder it clashes with — because
  Docker can only ever run one of them, and the other reading as stopped is a symptom, not a fault.
  Removing the one that isn't running now only ever takes away its own folder, never the other
  one's containers by mistake. Renaming, moving, creating or importing a stack under a name already
  in use elsewhere is now refused up front, with a plain explanation of why.
- **A very long update badge no longer squeezes the rest of the row.** The status column had grown
  to fit the widest version pair on the page, which could crush the name and services next to it and
  add a sideways scrollbar. It now stops growing past a sensible width, and a badge too long for it
  is trimmed with an ellipsis on screen — hover it for the full "from → to" version pair.
- **The CPU, Memory, Network and GPU column headings now sit centred over their own graph**, not the
  whole column, so a heading still lines up with its figures whether the window is full width or a
  narrower one has packed those four into a compact block.
- **The column titles now sit inside each folder, not just once at the top of the list.** A folder's
  own row has nothing to do with most of those titles, so the single strip above everything read
  badly. Every expanded folder now shows its own row of titles directly above its stacks, and the
  loose stacks below the last folder get one of their own too.
- **A stack's status and update pills no longer overlap when a narrow column pushes them onto a
  second line.** They now space out with a small gap between them and sit centred in the column,
  instead of both hugging the left edge with the wrapped line printed a pixel over the first.
- **The Stack, Services, State and Address headings now sit centred against the full header row**
  once a narrower window packs the figures into a compact block. They used to sit at the top,
  beside CPU and Memory only, with a stray line cutting across the whole row underneath them; now
  the only mid-row line is CPU's and Memory's own, and it only spans those two columns. Services
  stays left-aligned, though, so its heading sits over the left-aligned service names below it
  instead of floating centred above them.

---

## 00.01.00 — released 2026-08-30

The release that makes a folder row show you what is actually in it, gives a stack folder with no
compose file a way to get one, and keeps a copy of every compose file somewhere a dead pool cannot
take it.

- **A copy of every compose file now lives on the boot volume.** If the pool holding your stacks
  ever dies, the definition of every container you run dies with it unless you had your own backup.
  StaXX now keeps a plain copy of each stack's compose file at the top of the flash drive — which
  Unraid already backs up wholesale, and which is readable before the array has even started. It is
  a shelf, never a second opinion: StaXX never reads it while your store is there, never compares the
  two to work out which is newer, and nothing ever comes back on its own. Turning it off is one
  setting; it ships on, because the people it protects are the ones who would never go looking for
  it.
- **A stack folder with no compose file can be given one.** Until now the row said the file was
  missing and left you to fix it over a network share. It now offers the same starting file "Add
  stack" writes. It is a repair route, not a second way to make a stack, and it says so.
- **Your first version is kept, not just your second.** A stack's history used to hold only the
  version each save replaced, so a brand-new stack — or a file you dropped in by hand — had nothing
  kept until its second save. That window is closed: the file is kept the first time you open it,
  the first time you run it, and after every save as well as before one. If the file then goes
  missing, StaXX offers your last saved copy back, with the date, and loads it into the editor for
  you to look at rather than writing it anywhere.
- **A folder row shows every service inside it.** All the icons, grouped rather than shrunk to
  nothing, opening into a grid when you hover and naming whichever one is under the cursor. Each is
  a way into the stack it stands for, and the two kinds of broken — a stack with no compose file,
  and one compose cannot read — are drawn differently instead of both being a shrug.
- **A stack shows the same picture everywhere.** One place now decides a service's icon, so the row
  and the editor cannot disagree. The editor can work one out for you, and follows one you state the
  moment you state it.
- **A container with its own address no longer pretends to publish ports.** On a macvlan or ipvlan
  network the outer port is ignored by Docker entirely, so the box asking for it is gone and the
  recipe is shown instead. A port already written is kept as a note rather than deleted, and a stack
  whose ports cannot do anything says so on its row.
- **The log and the shell share the width.** A drag handle between them instead of taking turns, and
  the log keeps up, reads more easily and stays inside the dialog.
- **Fixes:** the takeover no longer asks whether it worked on top of the page that answers that; the
  glossary explains the two kinds of network and its ports section describes what ports now do; and
  a declined switch that changed nothing now says so rather than implying it did something.
- **For the record:** numbered releases install the ordinary Unraid way again. 1.2.0 shipped with a
  manifest naming a package that was never uploaded, so installing by plugin address failed. That is
  fixed, and the build now refuses to publish a release whose version, manifest and changelog do not
  all agree.

---

## 1.2.0 — released 2026-08-28

The release that gives StaXX one home. Everything it keeps — your stacks, the zips of ones you
have removed, its own settings and the icons it has downloaded — now lives in a single folder you
choose, instead of being spread across the flash drive in places nobody could reasonably find. The
flash drive keeps three lines, and only because they have to be readable before your disks have
started.

- **One folder, chosen by you.** The stacks folder and the archive folder used to be two separate
  settings. They are now one data store, with `stacks`, `archives` and `config` inside it under
  fixed names. Opening the settings shows the store and the three folders beneath it, so there is
  nothing to guess at.
- **StaXX now asks where its data should go, and explains the choice.** Nothing is written anywhere
  until you have chosen — the flash drive is no longer a silent default. The screen suggests the
  folder beside wherever your application data already lives and shows its working: which pool,
  whether Unraid reports it as redundant, and how much room is free. Eight reasons sit collapsed to
  a line each, so you can accept the suggestion in one press or read why a pool beats the share
  layer, why an array disk or a network mount is a poor home, what Unraid's mover would do to the
  wrong choice, and that nothing is backing any of it up until you say so.
- **It will not let you choose badly while your disks are still starting.** A machine that has only
  just powered on can show the flash drive as the only visible option, which is exactly the trap
  this exists to remove. StaXX says the disks are still coming up, offers nothing selectable, and
  invites you to look again shortly.
- **Pointing it at a folder that already holds something tells you what is there first.** A folder
  StaXX already manages is adopted and its stacks listed. A pile of ordinary compose files is
  adopted too, with the plain statement that nothing has been filled in for them yet — no icons
  matched, nothing described — and that they will run exactly as they are.
- **Moving the store moves all of it**, verified file by file, with the copy checked byte for byte
  before anything is switched over and the original left untouched until it has been. A large move
  now reports where it has got to instead of appearing to hang. Nothing is sampled or skipped: the
  zip of a removed stack is the only copy of it, and there is no re-fetching that.
- **Almost nothing is left on the flash drive.** Three lines remain — where the store is, and the
  two settings deciding where StaXX appears in the menus — because those have to be readable before
  your array is up, or when StaXX itself is not working. That means Unraid's own settings page for
  StaXX keeps working as the way back to the ordinary Docker menu, whatever else has gone wrong. A
  new [Where StaXX keeps its things](docs/guide/where-things-live.md) page explains all of it.
- **While your array is starting, StaXX says so** rather than looking normal. Its settings are on a
  pool that has not mounted yet, so it shows the shipped defaults for a moment and tells you that is
  what you are looking at. Nothing is lost and it corrects itself.
- **Nothing was backing your compose files up, and now StaXX says so.** The Appdata Backup plugin
  most servers run works from each container's volume mappings, and StaXX's own folder is not one —
  so sitting inside appdata was never enough. StaXX reads that plugin's settings and tells you
  whether its folder is listed, hands over the path and a link to the right field, then watches for
  it to appear. It never writes to another plugin's settings, and it only ever says "listed", never
  "backed up". After a move it also points out the old entry now naming a folder that no longer
  exists, which would otherwise let a backup report success while copying nothing.
- **Where the stacks may live is stricter, and explained.** One rule now decides what counts as a
  risky location, because the bug behind this was two places disagreeing: the suggestions checked
  whether Unraid's mover would empty a share and a typed path did not, so a share about to be
  drained onto the array came back as usable. A new setting decides whether that rule refuses or
  merely warns. Two refusals ignore it, being risks nobody accepts on purpose — a location that
  lives in memory loses everything at the next reboot, and a whole share as the stacks folder makes
  every folder in it read as a stack.
- **The folder browser now knows what it is choosing for.** Picking where the stacks live shows
  array disks, unassigned drives and network mounts greyed out with the reason rather than hidden,
  and its Create button no longer quietly makes a new Unraid share. Choosing a volume path for a
  container is unchanged.
- **StaXX can now fill in a stack's icon, description, category, links and web address for you** —
  and shows where each answer came from before writing anything. A field that is empty is filled and
  named afterwards; one that disagrees with what was found is shown beside it with neither chosen
  for you; the icon is shown as two pictures, because you cannot judge an icon by its name. Nothing
  is remembered between runs, so the compose file stays the only source of truth.
- **A service held back by a compose profile now says so.** StaXX does not pass profiles when it
  starts a stack, so such a service is skipped — with no error and no mark on the row, because
  compose is doing exactly as it was asked. The field now says what will happen.
- **A name matching another stack now says why it did not connect.** Previously it was dropped in
  silence; it now names the stack, the service and the network that would have to join them. A
  container holding its own address on your network is recognised when that address is typed, worded
  as depending on the network allowing it, because StaXX cannot see your router.

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
`plans/completed-plans/`. That folder is the real history for anything earlier than this entry.
