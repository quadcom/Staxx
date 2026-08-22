# PLAN 72 — noticing what you have not set

**Status: reserved 2026-08-22. A family of small advisory notices, grouped because they are the same
shape of work. Not to be built until approved.**

## The shape

The form already covers very nearly the whole compose specification — logging limits, health checks,
dependencies, restart policy and the rest all have fields. **What is missing is not the field. It is
anybody pointing out that the field matters.** A person who does not know a setting exists will never
go looking for it, which makes silent coverage worth very little.

So this plan is not new settings. It is a small, deliberately short list of notices that appear where
a common and costly omission has been made, each offering the sensible value rather than just naming
the problem.

## The two worth doing first, both real and both cheap

### Logs will eat the disk

Docker keeps a container's log output **forever** unless told otherwise. A chatty container quietly
filling the drive is one of the commonest ways a self-hosted machine falls over, and the failure
arrives as a full disk rather than as anything pointing at the container responsible.

The form can already set a size limit and a file count. Nothing suggests it. **Offer two sensible
numbers where the setting lives, and say plainly what happens without them.**

### A dependency that does almost nothing

Saying service A depends on service B waits only for B to *exist*, not to be *ready*. Without a health
check on B, an application still starts before its database is awake — the single most common "worked
on the second try" bug in compose.

Both halves already exist in the form. Nothing connects them. **Where a dependency is set and the
service depended on has no health check, say so, and offer to add one.**

## What must never be guessed

- **A health check is application-specific.** Offering a generic one that reports healthy when the
  application is not, or unhealthy when it is fine, is worse than offering nothing. Either suggest
  only where the answer is genuinely known for that image, or offer the shape and let the person fill
  it in — and say which is happening.
- **Never apply a notice silently.** Every one of these is an offer.
- **Absence is not always an omission.** Somebody may have decided against a log limit deliberately.
  A notice that cannot be dismissed becomes a notice that gets ignored.

## Keeping the list short

The obvious failure of this plan is becoming a linter with forty rules that everybody switches off.
**A notice earns its place only if the omission is common, costly, and has an answer that is right
almost always.** Two qualify today. Others should be added when found in the wild, one at a time,
against that test — not brainstormed in advance.

Candidates noted and deliberately **not** included yet, because none clearly passes the test: no
restart policy set; no memory limit on a container known to leak; a container running as root where
the image supports otherwise; a published port on a service nothing needs to reach.

## Size

Small. Each notice is independent, so this can land one notice at a time.
