# Changelog

What changed, newest first. Versions are dates, matching the plugin manifest.

---

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
