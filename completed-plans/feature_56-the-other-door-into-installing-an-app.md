# FEATURE 56 — the other door into installing an app

**Concept and decisions. No code plan here.** Written 2026-08-21 from findings taken off the live
server the same day. The code plan becomes its own `PLAN_N.md` when Adrian calls for it.

---

## The problem

StaXX has its own app store: search Community Applications, pick an app, get a stack. That door is
covered. But Unraid's own **Apps** page is still sitting in the top navigation, and anyone who goes
in that way gets the old machinery — an Unraid XML template and a container that only ever appears
on the Docker page.

With the Docker tab takeover on, that is not merely untidy. The page the new container appears on
does not exist any more, so the app installs successfully and then becomes invisible.

## What was confirmed on the server

All of this was read off the live box on 2026-08-21, not assumed.

**Unraid's Apps page does not install anything.** Pressing Install sends the browser to
`/Apps/AddContainer?xmlTemplate=<type>:<path>`. Four places in Community Applications build that
URL and they all use the same shape.

**The type prefix is the discriminator we need.** `default:` means a fresh install, with the path
pointing at a temporary XML file CA has just written. `edit:` means an existing template under
`/boot/config/plugins/dockerMan/templates-user` is being opened. So a new install and an edit are
distinguishable before anything is rendered, from the query string alone.

**`AddContainer.page` can be shadowed the same way `Docker.page` already is.** Unraid's page
collector keys every page by its bare filename — `$site[$page['name']] = $page` — and loads plugin
folders in `glob('plugins/*', GLOB_ONLYDIR)` order, which is sorted. `staxx` sorts after both
`dynamix.docker.manager` and `community.applications`, so a file named `AddContainer.page` in our
folder wins. This is the mechanism `shadow/Docker.page.tmpl` already relies on, so it is proven
rather than hoped for.

**`AddContainer.page` carries no `Menu` key.** That is why the one page answers at both
`/Docker/AddContainer` and `/Apps/AddContainer`. Shadowing it adds nothing to any menu.

**The stock page is a six-line wrapper.** It merges the docker translations when the request did not
come in under `/Docker`, closes the PHP session lock, and then
`eval('?>'.parse_file(".../dynamix.docker.manager/include/CreateDocker.php"))`. So our shadow can
hand any request it does not want to Unraid's *real* form by calling the same file the same way —
an exact pass-through, not an imitation of one.

**Cond cannot be used to choose between the two.** The page is registered in `$site` regardless of
`Cond`; `Cond` is only consulted later by `page_enabled()`. A shadow whose `Cond` is false does not
fall back to the stock page — it makes the page unavailable entirely. So the shadow must always be
live and branch **inside** itself, per request. (Same reason the dormant `Docker.page.tmpl` ships
with the wrong extension rather than a false `Cond`: a registered page cannot be un-registered.)

**Apps learns what you installed by watching the template folder.** Community Applications lists
`templates-user` before an install and again after, and treats whatever is new as the app's
template. Two places do this. If StaXX intercepts and writes only a compose file, that diff comes
back empty and Apps never records the install.

## Decisions taken

Adrian's answers, 2026-08-21.

1. **Doorway plus a safety net.** Stand in front of Add Container as the main route. Also keep
   watching for containers created the old way, so the feature degrades into "offer to adopt it"
   rather than into nothing if a future Unraid release stops matching.
2. **Three doors are caught, not one.**
   - An install arriving from Apps (`default:`) opens StaXX's form.
   - **Add Container pressed by hand** opens StaXX's form too.
   - **Editing an existing old-style container** (`edit:`) shows a prompt offering to convert it to
     a stack. **Declining leaves the person in Unraid's own template form, unchanged**, with the app
     still available for adoption later whenever they choose to start it.
3. **StaXX announces itself.** The person lands straight in StaXX's form — no extra click — with a
   line at the top saying StaXX is handling this install.
4. **StaXX's own app form.** The same form the built-in app store already uses. No second form
   mimicking Unraid's layout.
5. **A template is left behind, written once and never touched again — as the exit route.** Not as a
   courtesy to the Apps page. StaXX exists to *replace* Unraid's Docker management, so anyone who
   tries it and decides against it must be able to back out to plain Unraid Docker and still have
   their apps. The template is what makes that possible. Apps' installed-or-not check happening to
   keep working is a side benefit, not the reason. It is a stamp taken at install time, not a
   description kept true: once the stack is edited the two will disagree, and that is accepted. It is
   not removed when the stack is removed.
6. **Adoption asks first.** The safety net offers; it never destroys and rebuilds a container
   without a yes.
7. **Its own setting, on by default.**
8. **Top level, named after the app** — the same placement StaXX's own app store already uses.

## The position this feature sits inside

Adrian's standing design position, stated 2026-08-21 and not specific to this feature:

**StaXX is a replacement for Unraid's Docker management, not a companion to it.** The two are not
meant to live side by side, and where a choice exists between working alongside the stock pages and
taking over from them, StaXX takes over. But **leaving must always be possible** — someone who tries
StaXX and decides against it has to be able to back out to plain Unraid Docker and still have their
containers.

Two consequences that outlive this feature:

- A change that quietly makes leaving harder is a regression, even if nothing breaks.
- Compatibility with the stock pages is not a goal for its own sake. Do not build a bridge between
  the two just so both can be used at once.

## The tension in decision 5, stated plainly

The repository's second hard rule is that nothing is kept beside a compose file that can disagree
with it. A left-behind template is exactly such a thing, and it *will* go stale — that is decision
5's own design.

It survives the rule for two reasons worth writing down rather than discovering later. **Nothing in
StaXX ever reads it** — it is not a sidecar StaXX consults, it is the door out. And it does not live
in the stack folder; it lives in Unraid's template folder, where it would have lived anyway had
StaXX not intervened. The stack is still a folder holding a compose file and nothing else.

Being stale is a real cost against the exit route, not just against Apps: back out a year later and
the template describes the app as it was installed, not as it is now. That is a deliberate trade —
keeping it true would mean writing an Unraid template on every stack edit, in a format that cannot
express everything a compose file can, which is a second source of truth by any other name. An exit
route that lands you on day-one settings is worth more than no exit route, and honest about it.

The failure mode to check during planning: a template with no container behind it must not make the
app appear anywhere as if it were installed and stopped, and must not offer itself for re-creation
in a way that would build a duplicate container beside the stack.

## The proper exit route, later

Raised 2026-08-21 as a **future possibility, not work to plan.** A translator that runs the
conversion backwards: a StaXX stack becomes an Unraid XML template again, scoped to stacks that came
from Community Applications in the first place — those have a shape to go back to, and a hand-written
compose file does not.

It matters to decision 5 now, before any of it is built: the write-once template is the interim
answer to backing out, and its weakness is that it describes the app as installed rather than as it
is. A translator reads the stack as it stands today, which makes that weakness stop mattering. So the
consequence for this plan is a thing *not* to do — **do not invest in keeping stamped templates in
step with their stacks.** That work belongs to the translator, on demand, and paying for it twice
would buy a second source of truth nobody asked for.

## Assumptions carried into the plan

Flagged now so they are cheap to overturn:

- Apps entries that are not containers at all — plugins, language packs — pass straight through
  untouched. They never reach Add Container.
- Apps entries that are already compose projects (Community Applications can hand those to Compose
  Manager) pass through untouched too.
- The safety net is for *new* containers appearing. Sweeping up what is already installed is the
  existing importer's job and is not duplicated here.
- Adopting a container Unraid already created means destroying it and letting compose build its own.
  Data survives because the paths are unchanged. This is what decision 6 asks about before doing.
- The converter that turns a template into a compose file already exists and runs in the browser.
  This feature gives it a new entry point; it does not need a second converter.

## Open questions for the code plan

Not blocking the concept, but each needs an answer before code:

1. **How long does CA's temporary XML live?** The `default:` path points at a file CA wrote moments
   earlier. If it is cleaned up on a timer, the form must read it once and hold what it read rather
   than re-reading on submit.
2. **What does CA do when its post-install folder diff comes back empty?** Decision 5 means it
   usually will not, but the hand-pressed and adoption routes have no CA involvement at all.
3. **Does the shadow need the stock page's translation merge and session-lock release for its own
   branch too,** or only on the pass-through?
4. **What happens on a StaXX uninstall** while the shadow page is in place — the same question
   `apply_settings` already answers for the Docker button, and it must answer it here as well or
   the Add Container form disappears with the plugin.
5. **`UpdateContainer.page` is a separate page.** Whether an adopted app can still be sent down
   Unraid's update path, and whether that matters once the Docker tab is gone.

## Before this is planned

Nothing is blocked. The concept is settled and the mechanism is confirmed working on the live
server. The code plan can be written whenever Adrian asks for it.
