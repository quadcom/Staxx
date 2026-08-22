# PLAN 75 — a whole application, not eight containers

**Status: reserved 2026-08-22. Stands on `PLAN_70` and cannot start before it. Not to be built until
approved.**

## The case for it

A media suite, a photo library with its machine-learning worker and database, a document manager, a
cloud drive with its database and cache: **these are one application to the person installing them,
and several containers to the machine.**

On Unraid today each part is a separate install from a separate template, and every connection between
them — the shared folder, the database name, the password that must match, the address one part uses
to reach another — is typed by hand, twice, correctly, or it does not work. There is no view anywhere
that shows the parts as one thing, because the template format cannot express it.

**This is what compose is for, and it is the strongest argument this project has for existing.** A
person who wants a photo library should get a photo library, not four installs and a wiring diagram.

## What it is

Not a new page. The stack is *already* the unit — a folder with one file describing every part. What
is missing is the tool treating it that way:

- **Installing** a multi-part application from one source in one step, with its parts already wired.
  The importer already reads a whole multi-part file out of a project's own README; what it lacks is
  any understanding of the connections inside it, which is `PLAN_70`.
- **Configuring** it as a whole: the handful of settings that actually matter to a person — where the
  photos live, what the password is, which port — surfaced together, instead of buried one service at
  a time among settings that do not.
- **Updating** it as a whole, honestly. All parts move together, because a version mismatch between an
  application and its own worker is a real and confusing failure.
- **Adding or removing a part** later, with a warning about what depends on it.

## The smallest version worth building

**Configure the whole thing on one screen.** Given a multi-part stack, show the settings that matter
across all of its services in one place — which requires knowing which settings are connected, which
is `PLAN_70`, and knowing which matter, which the metadata already expresses.

Everything else on the list above is a later step. Installing in one move is mostly already true.

## What must never be guessed

- **Never invent the wiring.** If the tool cannot tell how two parts connect, it says so and lets the
  person fill it in. A confidently wrong connection between an application and its database is the
  worst failure available here, because it looks like a working install until data goes missing.
- **Version migrations are not automatable.** An application whose new version needs its database
  migrated must be handled as a *notice* telling the person to read the release notes — never as a
  step the tool performs. There is no safe general version of this.
- **Never hide a service.** Presenting the important settings together must not mean the rest become
  unreachable. Every part stays editable in full.

## Open questions

1. How does one screen for the whole application coexist with a form that renders one service at a
   time? A summary above the existing tabs, or a genuine alternative view?
2. Where does "which settings matter" come from for an imported application nobody has annotated?
3. Does updating all parts together become a verb of its own, or a default for a stack the tool knows
   is one application?

## Size

Large, and deliberately last of the ambitious ones. **Do not start it before `PLAN_70` has landed and
been lived with** — building the connections idea and the whole-application idea at once means
guessing at both.
