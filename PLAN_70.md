# PLAN 70 — parts that know about each other

**Status: reserved 2026-08-22. The foundational one; several later plans stand on it. Not to be built
until approved.**

## The gap, verified

**StaXX understands services. It has no concept of a relationship between them.** Confirmed by
reading the code: nothing anywhere compares a value across two services, and the metadata schema has
no notion of one setting relating to another — the idea is absent, not merely unimplemented.

The clearest symptom is in the importer. It reads a whole multi-part file out of a project's README
perfectly well — there is a test fixture doing exactly that with an application and its database —
and then treats the database password in the application and the same password in the database as
**two unrelated things the person happened to type twice.** Change one in the form and the stack
quietly stops working, with a failure that looks nothing like its cause.

## Why this is the leverage

Unraid's per-container templates can never fix this, because a template cannot see the second
container. Compose exists precisely to describe several parts as one thing. **This absence is the
single largest difference between what the file can express and what the tool understands** — and
whole applications, sharing a stack, and explaining a file all need it first.

## What it is

A panel above the per-service form showing the connections the tool can already work out, and warning
when an edit would break one. **Three kinds, all derivable from a file already fully parsed:**

| Connection | How it is spotted | Why it matters |
|---|---|---|
| **A shared secret** | the same non-trivial value appears in two services' settings | change one and authentication fails |
| **A shared folder** | two services bind the same host path | they are deliberately sharing data, or accidentally fighting over it |
| **A named service** | one service's value contains another's name | rename or remove the other and the reference dangles |

## The smallest version, which may be the whole feature

**Detect and warn. Do not write, do not propagate, do not offer to fix.** When a field takes part in a
connection, say so beside it; when an edit would break one, say which other service is affected
before the save.

The temptation is to make editing one box update its partner. **That is where this gets dangerous:**
silently changing a value in a service the person was not looking at is exactly what rule 2 forbids.
If propagation is ever built it needs the link explicitly confirmed by a person first, recorded, and
the change stated when it happens — the same shape as the existing opt-in records in our metadata.

## What must never be guessed

- **Coincidence is not connection.** Two services with `PUID=99`, `TZ`, a port number, `true`, or an
  empty string are not related. A same-value rule needs a floor on how distinctive a value is, or it
  will produce noise and be switched off.
- **A shared path is not automatically wrong.** Sharing media between two applications is the normal
  case, not a fault. This reports; it does not judge.
- **A name inside a value may be a coincidence too** — `db` appears in plenty of strings that are not
  service references.

## Open questions

1. Is the detection recomputed on every render, or recorded once the person confirms it?
2. Does a confirmed link belong in the file's metadata, or in the per-stack record of `PLAN_68`?
3. What does the warning look like when the person is editing the *other* service, which is not on
   screen?
4. Does this apply across stacks, or only within one? Two stacks sharing a folder is a real situation,
   and the existing clash warning already looks outside the stack — so the machinery to see across
   stacks partly exists.

## Size

Medium for detection and warning. Large if propagation is ever wanted. **Build the medium and stop.**
