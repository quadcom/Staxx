# Version policy

*A developer note for StaXX, split out of `CLAUDE.md` on 2026-09-17 so a conversation loads only the area it is working in. `CLAUDE.md` points here and never restates this; if the two ever disagree, this file is the one that was kept up to date.*

Ordinary semver, and it is enforced by the release workflow rather than left to memory:

**Every component is two digits.** `00.01.00`, `01.00.00`, `01.10.00` — never `1.10.0`. This is
enforced by `publish.yml` and is not a style preference; see the two-channel section above for why
text comparison makes fixed width the only safe shape. It also keeps the scheme clear of the tag
names burnt while releases were immutable, since `01.02.00` is not `1.2.0`.

- **Patch** (`00.01.01`) — fixes only. Nothing new, and nothing already on disk changes shape.
- **Minor** (`00.02.00`) — new features. Everything StaXX has already written still reads exactly as
  before.
- **Major** (`01.00.00`) — the user must act. Something stored on their server changes shape and
  needs migrating, or a setting now behaves differently than it did.

`01.00.00` is the first release meant for general use; the `00.xx.xx` line is the run-up to it.

**Patch and minor releases on the `00.xx.xx` line; never propose a `01.00.00`, and never argue a
change up to major because of its shape.** Adrian's standing instructions: 2026-08-30 (minor only,
while alpha) and 2026-09-04 (**patch releases allowed from now on**, the day he called StaXX beta and
submitted it to Community Applications). So a release carrying only fixes is a patch (`00.02.01`),
one carrying anything new is a minor, and `01.00.00` stays his call alone — the listing is in, so
that conversation is open, but nobody starts it but him.

**The number is decided by what has accumulated on `dev`, and it is decided once.** A dev build
carries the number `main` is heading towards — cut `00.02.00_dev...` and you have declared the next
stable release to be `00.02.00`. If something landing later turns out to be a major change, the base
number moves and the next dev build says so; nothing is burnt either way, because a dev tag can
never collide with the stable tag it is heading towards.

**A plan does not have to migrate what is already on disk — and this rule stays in beta.** Adrian's
standing instruction, 2026-08-30, reaffirmed 2026-09-04 with his reasoning: there is no database,
and the compose YAML shape is locked in, so he does not expect a migration to ever be needed. Take
the clean shape and leave the old one behind. **Do not build migration machinery, upgrade paths, or
code that goes on reading a shape StaXX no longer writes** — every one of those is permanent weight
bought for nobody.

The one real server is Adrian's, and it is **patched by hand, as needed**: when a change would make
something on his box read wrong, say so plainly and offer a one-off fix for those files. That is a
deliberate act at the time, not a feature in the plugin.

What still holds is the *saying*: a change that alters the shape of something already written must
state plainly what now reads differently, so the hand-patch can be aimed. Silence is the failure
here, not the absence of a migration.
