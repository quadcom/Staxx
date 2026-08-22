# PLAN 69 — choosing which parts of a stack start

**Status: reserved 2026-08-22. Written from a live trap found in the feature review. Not to be built
until approved.**

## The trap, as it stands today

The form offers a **Profiles** field on every service, with help text explaining that it marks the
service as only starting when a matching profile is chosen. **Nothing in StaXX ever chooses one.**
Verified: the start verb runs `up -d --remove-orphans` with no `--profile`, and the string "profile"
does not appear anywhere in the server-side code.

So the sequence is: a person reads a helpful description, fills the field in, presses Start, and the
service never comes back. No error, because compose is doing exactly as asked. The only way out is to
find the line and delete it by hand — the one thing this project exists to spare people.

This is currently the only item on any feature list that can lose somebody a running container.

## What it should do

A per-stack record of **which profiles are active**, offered wherever the stack is started, and passed
to compose as `--profile` on every verb that brings containers up.

The important property: **this is a server setting, not file content.** Compose takes the active
profile from the command line or the environment, never from the file. So recording our choice
outside the file keeps rule 1 intact — the file still runs unmodified anywhere, and somebody running
it by hand simply chooses their own profiles as they always would.

## The smallest version

1. Read the profile names a stack's file declares — the parser already lists them.
2. If a stack declares none, nothing changes anywhere. No new UI, no new setting. **Most stacks.**
3. If it declares any, the stack offers a set of tick boxes, defaulting to all off, and says plainly
   that a service tagged with an inactive profile will not start.
4. Every verb that brings containers up carries the active profiles. Stop and remove must carry them
   too, or compose will not see the containers it is being asked to stop.

## Where the choice lives

Open question, and the one thing to settle before building. Candidates:

- **Alongside the stack**, in the per-stack record reserved as `PLAN_68` Part A — the natural home,
  but that plan is not built and sits behind moving stacks off the flash drive.
- **In the plugin's own settings**, keyed by stack path — available today, but it is a second place
  that can disagree with the stack, and it does not travel when a stack is moved or archived.
- **In `x-unraid`** inside the file — travels correctly and cannot be orphaned, but it means our
  metadata records an operational choice rather than a description, which is a line worth thinking
  about before crossing.

## What must never be guessed

**Never activate a profile on the person's behalf.** A service tagged with a profile has been
deliberately made optional by whoever wrote the file, and quietly turning it on could start a
database, expose a port, or run a migration nobody asked for. Default off, always.

## The interim question

Until this is built, the Profiles field is a hole with a sign inviting people into it. Either the
field comes out, or its help text has to say plainly that StaXX cannot yet start a service tagged
this way. **The second is a one-line change and should not wait for the rest of this plan.**

## Size

Small, once the storage question is answered. The storage question is the plan.
