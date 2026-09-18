# The docs preview tooling — a developer note

How a readme, changelog or guide page is seen the way GitHub will render it, and how it reaches
the preview site on Adrian's own box. **Local only — neither script described here is in the
repository.** They hardcode that machine's address and a drive letter only his setup has, so they
are gitignored in place: on disk here, absent from a clone. Same reasoning as `local/`. The
address, the drive letter and the folder are in `local/machine.md`.

```sh
node tools/preview-docs.js                    # readme, changelog and the whole guide
node tools/preview-docs.js README.md          # just one
node tools/preview-docs.js --no-serve         # write the files, do not serve them
```

Serves the result at `http://localhost:8099`. **It does not approximate GitHub's formatting — it
asks GitHub to do the rendering**, through the same markdown endpoint the site itself uses, so alert
blocks, task lists and tables come back exactly as they will appear. A local markdown library gets
the common cases right and the interesting ones wrong, which is precisely backwards for something
whose job is to catch a surprise before it ships. It needs the GitHub CLI signed in, and reads no
token of its own.

Relative images and cross-page links are rewritten so the guide clicks through page to page with its
pictures loading. The output folder is self-contained — pictures are copied in beside the pages —
which is what lets it be handed to a web server elsewhere. It lands in `.preview/`, gitignored.

**The local preview site is where all of this is read.** It lives on Adrian's own box and is the
normal way he looks at anything before it is pushed:

```sh
bash tools/publish-preview.sh              # everything, from scratch
bash tools/publish-preview.sh guide        # just the user guide (and the glossary)
bash tools/publish-preview.sh readme       # just the readme
bash tools/publish-preview.sh changelog    # just the changelog
bash tools/publish-preview.sh review       # just the front page and the review history
bash tools/publish-preview.sh <file.md>…   # just those files
```

**Every sectioned build also refreshes the changelog**, whichever section was asked for. It gains a
bullet in the same commit as the change it describes, so it is edited in passing rather than
deliberately, and it is the one page nobody would think to rebuild. Rendering it costs well under a
second, which is cheaper than noticing it has gone stale.

**Rebuild only the section you changed.** A full run empties the folder first, which is what makes a
deleted page vanish; a sectioned run writes over just those pages and leaves everything else alone.
Either way the last step is the same, so the top bar goes back onto every page it touched and the
front page is rebuilt from the newest review — navigation never depends on which part was rebuilt.
The contents page always lists the whole site, not the subset a partial run happened to render.

**Its front page is the newest project review**, written by `tools/publish-review.js` from whatever
`summaries/` holds, with links across to the readme, the guide and the changelog, and a fixed
contents list down the left. The bar runs the full width along the top edge, and the index and the
review sit side by side beneath it — the index fixed and scrolling on its own, the review scrolling
normally. Both are positioned against the viewport, so the bar's height lives in one CSS variable
rather than being written out in three places that would drift apart.

Each row of the index is a card of the same shape as the finding it points at,
carrying that finding's own severity colour down its left edge and its severity word above the
title, and going green with a tick once the `housekeeping` skill has marked it `addressed`.

The bar is fixed to the top of the window, so every anchor target on a review carries a
`scroll-margin-top` of the bar's height plus a little — both read from one CSS variable. Without it a
link from the index scrolls the finding to the very top and straight behind the bar, which looks like
the link pointing at the wrong thing rather than the page hiding what it found.

The same bar is stamped onto **every** page in the preview folder, not just the review — the docs
tool's own thin nav is removed on the way past, so there is one bar rather than two that drift. The
StaXX preview name is the way back to the newest review. Links in the bar take a path prefix,
because a page inside `reviews/` needs `../` on every one of them; without it they all pointed at
`reviews/pages.html` and friends, which do not exist.

Everything on the index list — the title, the severity, the colour, the done state — is read out of
the page itself, so it cannot disagree with what the review says. Two details worth not rediscovering:
findings are matched on the class rather than the element, because older reviews wrote them as
`article` and newer ones as `div`; and the severity words differ between reviews ("critical" in one,
"Do first" in another), so the label is copied rather than mapped. The docs tool's own
contents list lives at `pages.html` so the two never fight over `index.html`. Earlier reviews stay at
`reviews/`, which is the one folder the publish script does **not** empty — everything else there is
rewritten from the repository each run, but a review exists nowhere else on that machine.

Served on port 8099 by a `docs-preview` stack in Adrian's own StaXX store — an ordinary
nginx container, visible and removable like any other stack, reading a folder it cannot write to.
It renders nothing itself; the pages are built here, because that is where the signed-in GitHub CLI
is, and written **straight into the folder nginx serves** over a mapped drive. There is no copy
step: writing the page is publishing it. Use the host's `.local` name when mapping — the bare name
does not resolve here, and this shell cannot use a UNC path at all, only a mapped letter.

**The address, the drive letter and the folder it points at are in `local/machine.md`** — read it
when you need one. They are Adrian's own machine and network, and this file is public, so they live
in the one place `.gitignore` already keeps out of the repository. Same arrangement as
`local/dev-server.md`, which holds the server's credentials. Nothing expands automatically: reading
that file *is* the lookup.

**The whole folder is emptied rather than written over**: a page deleted from the guide has to
vanish from the preview too, or believing a stale page is current — the one thing this exists to
prevent — is what it starts causing. The script refuses to empty a drive that does not look like
the preview folder, since the alternative is destroying whatever it is really pointing at.

**Two Unraid traps, both measured rather than reasoned about.** The container mounts the *pool*
path, never `/mnt/user/...`: that is a FUSE overlay, and a bind mount through it turns into `Stale
file handle` the moment the files underneath are deleted and rewritten — which is precisely what
publishing does, so every page returned 500 until the mount named the pool. And the folder needs
`chown nobody:users` before Windows can write into it at all.

**Adrian reviews docs here before they are pushed.** Offer it whenever a change to the readme or a
guide page is waiting on his approval; a page he can look at is worth more than a description of it.
