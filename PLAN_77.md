# PLAN 77 — explaining a file you did not write

**Status: reserved 2026-08-22. The teaching half of the project's stated purpose. Not to be built
until approved.**

## Why this one exists

The project's purpose is not only to let somebody configure a container without reading YAML. It is
also that **the file itself teaches** — a person who uses the form for a year should end up
understanding compose, because the thing they were editing was always the real file.

The form does half of that: it names settings in plain English and explains what they do. The other
half is missing. **A file that arrives from somewhere else — a project's README, a converted template,
a friend, a search result — is still opaque**, and that is the moment somebody most wants to
understand it, because it is the moment it does not quite work on their machine.

This came out of looking beyond Unraid entirely. It was the one theme that was distinctly *not* about
Unraid: the wider Docker world's most repeated difficulty is understanding a file somebody else wrote.

## The three things worth explaining

### Where a value actually comes from

The most confused mechanic in compose is substitution: a setting written as a placeholder, whose value
comes from a file beside the compose file, or from a different file named inside it, or from the
environment, or from a default written into the placeholder itself — with precedence rules almost
nobody knows.

**Show the resolved value beside the field, and say where it came from.** Not the placeholder, and not
instead of the placeholder: both, so the person learns the mechanism rather than being shielded from
it.

### What the warnings mean

Compose's own messages are written for people who already understand it — an orphan container, a
project name that has drifted, a network that is in use. Each of these has a plain-English version
that says what happened and what to do, and the tool is much better placed to say it than the command
line is, because it knows which stack the person is looking at.

### What this stack is asking for

A plain summary of the powers a file requests — the whole disk, the machine's network, the control
socket, root. Useful for a file from anywhere, and the same summary a shared recipe would need, which
is why the sharing plan should reuse it rather than build its own.

## What must never be guessed

- **Never explain by guessing.** If where a value comes from cannot be determined, say it cannot.
  A confident wrong explanation teaches somebody something false, which is worse than teaching nothing
  — and it is the failure mode this project keeps catching in itself.
- **Resolving a value must not mean handing the file to something that reads it differently.** There is
  an existing decision on record against resolving through the compose command, because it pulls in
  other files against a third party's paths. Whatever is done here respects that.
- **Never rewrite a placeholder into its value.** Showing is the feature; substituting is destroying
  what the author wrote.

## Open questions

1. How is a resolved value shown without implying it is what will be saved?
2. Which warnings are worth translating? Best gathered from real ones seen in job output rather than
   invented.
3. Is the powers summary a per-stack view, a whole-server view, or both? The whole-server version —
   "everything on this machine that can reach the control socket" — may be the more valuable one.

## Size

Small per piece, and each lands independently. The value comes from doing several.
