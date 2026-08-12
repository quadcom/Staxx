# Start here

This folder holds the project's thinking: what it is trying to do, whether it can be done, and the
decisions made along the way.

New to any of the terminology? [glossary.md](glossary.md) defines everything in plain words.

---

## The problem

Unraid runs applications in **containers** — self-contained packages you download and run, like
Jellyfin or Plex or Home Assistant.

To set one up, Unraid uses a **template**: a file listing every setting the app needs, with friendly
labels and descriptions, which Unraid turns into a form for you to fill in. When a template exists,
this works nicely.

The trouble is what templates are. They are Unraid's own invention. They exist nowhere else, they
work nowhere else, and they are written and maintained by volunteers. So:

- If nobody has written a template for the app you want, you build one by hand, field by field.
- An app's own documentation never gives you a template. It gives you the standard format instead.
- Around 2000 templates exist, all needing upkeep, all duplicating information the app's own
  developers already published in a different form.

## What this project does instead

Every containerised application in the world is already described by a **compose file** — a plain
text file listing the containers you want and how to set them up. It is the industry standard.
Copy one from a project's documentation, drop it on any machine running Docker, and it works.

This project makes compose files the basis of Docker on Unraid. Two things follow from that:

**For people comfortable with compose:** paste in a file from anywhere and it runs. No conversion,
no Unraid-specific step, no waiting for someone to write a template.

**For people who are not:** the interface reads the compose file and builds a **form** from it, much
like the template form you use today. Fill in the boxes, save, done — never seeing the file itself.

The key difference from templates: the form is a *view of the compose file*, not a conversion into
something else. The compose file stays the real thing, and it stays a normal compose file that
would run on anyone else's machine.

### What makes the form friendly

A compose file says a container uses port 8096. It does not say "this is the web interface" or "this
one is a password, hide it as you type" or "you must set this one".

That missing information goes in two places, and both are already part of an ordinary compose file.

**What each setting is for** goes in the comment beside it — the `#` note a person would write there
anyway. The form shows it as the help text under the box, and typing help text into the form writes
it back as that comment. Two short marks at the end of a comment say the only two things a comment
cannot: `-!S` for "this is a secret, hide it", `-!R` for "this must not be left empty".

**What the app is** — its logo, a paragraph about it, and the address of each container's web page —
goes in a section named `x-unraid`. The compose standard sets aside sections whose name starts with
`x-` for exactly this: extra information that Docker will ignore. Your file keeps working
everywhere; ours knows how to draw a decent form from it.

None of it is required. With no notes and no `x-unraid` section you still get a form — just a plainer
one, with the names worked out from the file itself.

## Where it is going

Ship as an add-on first, prove it, then propose it to Unraid's makers as the built-in way Docker
works. Plugin first, formal proposal later.

---

## What each document is for

| Document | What it covers | Worth reading if |
|---|---|---|
| [glossary.md](glossary.md) | Plain definitions of every term used across the project | Anything below reads as jargon |
| [feasibility.md](feasibility.md) | Whether all this is actually possible, and the evidence for it | You want to know what is proven versus assumed, and where the risks are |
| [x-unraid-schema.md](x-unraid-schema.md) | The exact format of the extra information that makes the form friendly | You are writing that information by hand, or building something that generates it |

The main [README](../README.md) covers the repository itself — its layout and how to build it.

---

## How these documents are written

The person who owns this project is not a professional developer. Documents that need a developer's
vocabulary to read are documents that fail at their job, since they exist to support his decisions.

So, for anything written here:

- **Plain summary first.** The opening of every document should make sense with no prior knowledge.
  Technical depth comes after, not instead.
- **Explain each term the first time it appears**, or link it to the glossary.
- **Prefer a concrete comparison to an abstract description.** "A sticky note attached to a form"
  beats "a non-semantic annotation".
- **Short sentences.** Density is not rigour.

This is about the way in, not the level. Full technical detail belongs in these documents —
contributors will need it, and eventually so will Unraid's maintainers. It just should not be the
only thing on offer.
