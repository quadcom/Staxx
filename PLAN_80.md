# PLAN 80 — three fixes from the overnight audit

**Status: approved 2026-08-22, in progress.** Adrian read the audit summary and approved this order.
Findings 1, 3 and 4 of four; finding 2 (a crafted set-aside splicing arbitrary lines back in) is
deliberately **not** in this plan — it needs a decision about what a valid stash may contain.

---

## Fix 1 — a failed mode switch must not keep the half it managed

### What is wrong, verified by reading it

`setNetworkMode()` removes a service's `networks:` and stashes it **first**, then writes
`network_mode`. **Three failure paths return an error after that removal has already landed:**

1. `writeScalar()` on an existing `network_mode` fails — service keeps its old mode, networks gone.
2. `network_mode` exists in a shape the writer cannot change — same.
3. `insertChild()` fails — **the service ends with neither `networks` nor `network_mode`.**

The caller makes it worse. `switchServiceToMode()` answers a failure with `undoStack.pop()` and stops
— **discarding** the snapshot instead of restoring from it, so the half-applied edit stays and the one
thing that could reverse it is thrown away.

### How bad this actually is — corrected 2026-08-22

Adrian challenged the original framing here and was right to. **A service with neither a mode nor a
network list is valid compose**: it joins the project's default network. So none of these paths
corrupts a file or stops a stack running, and the first write-up of this overstated it as file damage.

The real harm, in order:

1. **The person is told it failed when it partly succeeded.** That is the serious one. Believing
   nothing changed, they have no reason to look — which is precisely the "changing a file without
   saying so" that rule 2 forbids.
2. **The container silently moves network.** Host networking to the project default bridge is a real
   behavioural change: published ports behave differently and what it can reach changes.
3. **One value is genuinely lost, in one case only.** Switching *away* from a mode removes
   `network_mode` **without stashing it**, unlike `networks:` which is stashed before removal. With the
   snapshot discarded too, the fact that the service was ever on host mode is gone.

Everything else is recoverable, because the stash holds the networks. So this is worth fixing for
reason 1, not reason 3 — and it is **rare**, needing a file written in a shape the writer refuses to
touch.

### The fix

**Always restore from the snapshot when the switch fails.** Not a partial unwind inside the model —
unwinding is more writes that can themselves fail. Restoring is unconditionally safe: if nothing was
written, the snapshot equals the current text and the restore is a no-op.

The pattern already exists in `commit()`, written 2026-08-21 for the same class of bug: pop the
snapshot, put the text back, repaint, reparse. **Lift it into one small helper and use it in both
places** rather than writing the sequence a second time.

### Also — survey, do not sweep

There are roughly two dozen other `undoStack.pop()` sites. **Most are correct** — nothing was
written, so dropping the snapshot is right. Some may follow a multi-step write and have this same
hazard. Report which, with reasons. **Change nothing else in this plan**; a blind sweep of two dozen
call sites is how a small fix becomes a large regression.

### What the survey found, and why the scope grew

The survey was worth more than the fix. Of roughly two dozen other snapshot-discard sites, most are
correct — nothing was written, so dropping the snapshot is right. **Three carry the identical bug**,
all in the same network-mode neighbourhood, and all now fixed the same way:

1. **Switching away from a mode, when the network was not yet declared.** The declaration has already
   landed by the time the mode removal is attempted; discarding threw it away.
2. **Switching away from a mode, when adding the network row fails.** `network_mode` has *already*
   been removed — and, unlike `networks:`, it is not stashed anywhere first. With the snapshot
   discarded as well, that value is genuinely gone. **The only one of the three that loses
   something**; the stack still runs, on the default network.
3. **Putting set-aside networks back.** The restore removes `network_mode` before it removes the
   stash, so a failure on the second leaves the first already done.

These were not in the audit, which named the symptom it had found rather than every instance. Fixing
them was a deliberate scope increase: same bug, same one-line fix, and leaving known instances of
something just fixed is worse than the extra three lines. **The one remaining discard in that
function is correct** — nothing has been written at that point.

Not verifiable by a headless probe, since these paths need the editor's DOM. The model-level premise
*was* proven directly: a refused mode switch does return failure with the file already changed.

---

## Fix 2 — job logs must not be world-readable

### What is wrong, confirmed on the server

`/tmp/staxx/jobs` is `drwxr-xr-x`, its logs `-rw-r--r--`. One of the verbs writes the **resolved**
configuration into a log — every variable already substituted — so a password from an environment
file sits there in plain text, readable by any other local account, until the log is pruned.

The audit's claim that the log-follower directory is stricter was **wrong**: it is also `0755`.
Both need tightening.

### The fix

`0700` on the directories, `0600` on the log files. Two details that matter:

- **`mkdir()`'s mode is filtered by the process umask**, so the mode must be set again with `chmod()`
  afterwards. There is already a comment in this codebase recording that lesson — follow it.
- The directory is created at **several** sites with the mode repeated at each. One small helper,
  used everywhere, rather than the same two lines copied again.

Nothing else reads these files: the webGUI and the collector both run as root.

---

## Fix 3 — a stack must not clash with itself

### What is wrong

The clash check excludes a stack's own containers by testing whether a container's name starts with
the project name followed by a hyphen. **Containers created by older compose, or by the Compose
Manager plugin, are named with an underscore instead** — so for such a stack every one of its own
ports reads as a clash with itself.

Cosmetic in effect, corrosive in practice: a warning that cries wolf about your own stack is a warning
people learn to dismiss, and the whole value of `PLAN_65` is that it is believed.

### The fix

Accept either separator. One condition, and the reason recorded in a comment — the underscore is not
an oddity, it is what compose used to do.

---

## Verification

Both JavaScript checks (`node --check` on each browser file, plus the undeclared-name sweep), the
round-trip suite, and `php -l` on every include. For fix 1, a probe proving the file is byte-identical
to its starting state after a refused switch — **the failing case is the point of the fix**, so it is
the case that must be tested rather than the happy path.

Fix 2 is verified on the server by looking at the modes after a job runs.
