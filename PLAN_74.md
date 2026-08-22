# PLAN 74 — secrets: making one, and keeping it out of the file

**Status: reserved 2026-08-22. Two small pieces of one theme. Not to be built until approved.**

## Part A — a password you can actually get

The form already knows which settings hold secrets: it is stated in the file rather than guessed from
the name, which was considered and rejected precisely because no naming rule is reliable.

Having gone to that trouble, **the form then offers no way to fill one in.** So every person either
reuses a password they already have, or mashes the keyboard and hopes they can find it again. Both are
worse than the thing this project is trying to be.

**A generate button on a field already marked secret.** It fills the box with something strong, and it
does not hide what it made — the person can see it, and the value only lands when they save, like
every other field.

Small, self-contained, and the least risky thing on any of these lists.

## Part B — keeping a secret out of the file

Many images accept a secret from a **file** instead of from an environment setting, by convention
adding a suffix to the setting's name and pointing it at a path. Compose supports this properly, and
the form already covers the machinery involved.

The value is real: a password in an environment setting appears in the file, in anything that reads
the file, in the resolved configuration output, and to anyone who can inspect the container. A
password in a file read at startup does not.

**Where a setting is marked secret and its image is known to support the file convention, offer to
move it.** The offer explains the trade in one sentence, does it in one step if accepted, and says
what changed.

## What must never be guessed

- **Never assume an image supports the file convention.** It is a convention, not a standard, and
  guessing wrong turns a working application into one that starts with an empty password — which may
  be worse than failing outright. This must rest on knowing, for that image, or not be offered.
- **Never move a secret without saying so.** It changes where the value lives and adds a file the
  stack now depends on; both must be stated, and the change must be reversible.
- **Never generate over a value that is already set** without asking. Somebody's working password
  quietly replaced is a broken application and a lost credential.
- **A generated password must not be written anywhere but the field.** Not to a log, not to a
  temporary file, not to the job output.

## Open questions

1. Where does the file holding the secret live? It must not sit in the stack folder if the folder is
   ever shared or exported — which is exactly what `PLAN_76` proposes to make easy.
2. Is the source of "this image supports it" the same catalogue the update watcher already uses, or
   something new? Prefer the existing one.
3. Should the generate button appear on a *new* stack's secret fields by default, since a fresh install
   is when it is most useful and least risky?

## Size

Part A is small and can land alone. Part B is medium and depends on knowing what an image supports.
