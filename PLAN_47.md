# PLAN_47 — archiving a stack instead of deleting it

**Status: SKELETON, needs its own session.** Branch `archive-on-delete`. Pulled out of PLAN_46 on
Adrian's instruction because it changes something every stack relies on and has nothing to do with
importing. Nothing here is built, and the design is not finished — what follows is what has already
been decided, and what still has to be asked.

## Context

Deleting a stack today removes its files. The compose file, the settings file and the review note go
without ceremony; anything else in the folder makes delete stop and ask first. That is the one
irreversible act in the plugin, and it sits one confirmation away from a file somebody hand-wrote.

Adrian's decision: **delete stops destroying things.** Removing a stack should mean it stops running
and leaves the stacks tree — not that its files cease to exist.

## Decided

- **Nothing is deleted.** The stack's folder is zipped and moved somewhere outside the stacks tree.
- **The whole folder, at disk level.** Not just the compose file and its override — *every* file the
  stack has, which is the same set the editor already shows as tabs, plus anything else sitting
  beside them. The archive mirrors what is on disk.
- **The containers do go.** The stack is stopped and its containers removed, so nothing of it is
  live in Docker afterwards. Only the files survive.

## Still to decide — this is the session's agenda

- **Where archives live.** The stack root is on the flash drive by default, and flash is the one
  place a growing pile of zip files should not go. Appdata? A configured path? A refusal until one is
  set?
- **Naming, and collisions.** Two archives of the same stack a week apart. Timestamped? And is the
  stack's path under the root part of the name, since two folders can hold a stack with one name.
- **Getting one back.** Is restore part of this, a later plan, or explicitly "unzip it yourself"? If
  it is built: what happens when a stack of that name already exists.
- **Whether the archive is browsable in the UI**, or just a file on disk with a path in the log.
- **What happens to what the containers left behind.** Appdata is not in the stack folder and is not
  archived. The confirmation has to be honest that the data is untouched and still on disk.
- **How much this can weigh.** A stack folder is normally tiny, but nothing stops someone keeping
  something large in it, and zipping happens while the page waits. Likely a detached job, like every
  other long-running thing.
- **What the button says.** "Delete" is the wrong word for what this now does.
- **Whether real deletion survives at all**, for someone who wants the folder gone, and if so how
  clearly it is separated from the safe one.

## Where it touches

The delete path in the stack model, its confirmation in the browser, and the endpoint action behind
it. The list of which files belong to a stack is shared with PLAN_46's override work — that plan
needs the override counted as one of the stack's own files, and this one needs the same list to know
what to archive.
