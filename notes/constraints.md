# Constraints that bite

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

- **LF line endings, always.** `.gitattributes` forces this. A CRLF `.page` file breaks Unraid's
  `\n---\n` split and the page is silently discarded with one line in the syslog; shell scripts fail
  just as quietly with `\r: command not found`. `dev-install.sh` strips CRs defensively rather than
  trusting the copy.
- **`default.cfg` comments must start with `;`, not `#`.** PHP's `parse_ini_file()` treats a `#`
  line as content, and one syntax error makes it reject the *whole file* and return `false` —
  silently. The failure only surfaces the day a new key is added, because existing user configs
  already hold every older key.
- **`_()` returns HTML, not plain text.** Unraid's own translator turns an apostrophe into
  `&apos;` and `**bold**`/`*italic*` into tags before handing the string back, so
  `htmlspecialchars(_('the app\'s own check'))` escapes that entity's ampersand and prints
  `&apos;` on screen. Interpolate `_()` straight into markup and escape only the untrusted values
  you are splicing into it. Existing double-escaping call sites are harmless only because none of
  their strings contain an apostrophe.
- **Asset URLs carry `filemtime()`.** Without it an edited stylesheet or script sits in the browser
  cache and looks exactly like a change that did not work.
- **Every `<button>`, `<input>`, `<select>` and `<textarea>` inside the page is reset by Unraid at
  a specificity a single class cannot beat.** The `unapi` marker on the scaffold switches off
  Unraid's old button styling, but Unraid's web-component stylesheet scopes a Tailwind base reset
  under that same class: `.unapi button, .unapi input, …` sets `font: inherit`, `color: inherit`,
  `background-color: transparent` and `border-radius: 0` at one class plus one element, (0,1,1),
  and `.unapi *` zeroes margin, padding and border at (0,1,0). A control styled by one class
  therefore takes its font size and line-height from the cell around it and loses its shape. This
  made a clickable chip draw taller than the plain one beside it twice (2026-09-04 and 2026-09-10).
  Style every control at `.staxx-scaffold .staxx-xxx` or `.staxx-scaffold button.staxx-xxx` and
  restate font-size, font-weight, line-height, colour, background and border-radius in that rule.
  The exact rules and where they live are in the header of `sheets/staxx.css`.
- **A redraw keeps its scroll.** Any handler that rebuilds a scrollable panel — a toggle, a
  choice, an answer — reads the panel's `scrollTop` first and puts it back after (`keepScroll()`
  in `stacks.js`, or a save/restore by selector when the element itself is replaced). A panel
  that snaps to the top on every switch was found on the merge wizard's step 5 (2026-09-16) and,
  the next morning, on step 6 — the same fault, one step over. **When a fault like this is fixed
  in one place, grep for the sibling renders and check every one in the same pass**; Adrian's
  words: "these need to be caught when they're being made, rather than found later."
- **`staxx_hub_repo_path()` is for asking a registry, never for asking local Docker.** It turns
  `lscr.io/linuxserver/plex` into `linuxserver/plex` on purpose, for the Hub lookups — but Docker
  stores the image under the name it was pulled as, so `docker image ls|inspect linuxserver/plex…`
  finds nothing. That one mix-up made the weekly image cleanup miss every linuxserver image and made
  every linuxserver roll-back refuse as "no longer present" (both fixed 2026-09-24, PLAN_180 parts 2
  and 2c). A local Docker call uses the service's own reference with its tag and digest stripped
  (`staxx_update_local_repo()`), or reads Docker's whole listing and normalises each row's own
  name through the same rule as whatever it is compared with (`staxx_update_cleanup_pick()`).
- **`docker rmi repo@digest` frees nothing while the image still has a tag.** It only drops the
  digest reference — and then the image has no digest at all, so anything matching by digest can
  never find it again. Measured 2026-09-24 on Plex `1.42.2` and friends. Remove every `repo:tag`
  first, then the digest or ID, never `-f`, and check the image is actually gone before counting it.
- **Own the render.** Stock Unraid CSS classes are not borrowed for layout — their rules are
  invisible to us and change between releases. Every class used is `staxx-`-prefixed.
- `staxx.plg` is fully populated — real author, real repo, real checksums. Nothing there guards
  against a premature publish any more, so that job now falls to judgement: cut a tagged release
