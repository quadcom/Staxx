# PLAN_50 — a better guess at the port, and a way to stop guessing

**Status: APPROVED, building.**
Follows PLAN_49 (`completed-plans/PLAN_49.md`), which made the web link follow the network.

## Context

PLAN_49 gave a container on host networking or macvlan one obvious place to state the port its
web page answers on: a port row with the outside half greyed, and a **+ port** button that writes a
single container-side number. It works, but the number it *suggests* is 8080 counted up until it is
unused — a value with no relationship to the container at all.

Adrian hit the consequence on **it-tools**. Its author exposes port 8080 in the image but lets you
move the web page with a `PORT` variable, which that file sets to 80. The suggested 8080 produced a
link that opened nothing. Two places in the file said 80 — the `PORT` variable, and the web address's
own `[PORT:80]` — and the form read neither.

Worth knowing, because it rules out the obvious fix: **the port the container declares would have
been 8080 too**, so reading what Docker exposes would have failed in exactly the same way.

The intended outcome: the suggested number comes from somewhere real, the form admits it when the
evidence disagrees rather than picking silently, and there is a way to find out whether the link
actually works instead of clicking it and wondering.

## Adrian's decisions, recorded

| Question | Answer |
|---|---|
| Where the suggestion comes from | **The number in the file's own web address**, then what the container exposes, then today's 8080. |
| When sources disagree | **Say so.** Name each place a port was found and what it said. The form stops pretending to know. |
| Proving it | **A button that tests the link** — the server tries the address and reports whether anything answered. |
| Reading a `PORT`-style variable | **No.** Too many `PORT` variables are databases, not web pages. |

## What the evidence says

Measured on Adrian's own server, not assumed:

- **40 of the 42 stacks** that have a web address carry a number in it.
- For the two containers that actually needed a number typed — **it-tools said 80, glances said
  61208. Both correct.**
- Where a macvlan container already had ports written, the number agreed with the container side
  3 times out of 4.

The codebase already records why the number is not trusted for *building* a link: across 85
templates it matched the host port 10 times and the container port 15, so which side it means is
anybody's guess. **That objection does not apply here.** On host networking or macvlan there is only
one port, so there is no side to get wrong — and this is a suggestion someone reads and can change,
not a link built behind their back.

---

## Part A — a suggestion from somewhere real

The **+ port** button's value is decided in one place, `newEntry()`'s port branch in
`javascript/compose-model.js`. It gains an ordered list of candidates rather than a fixed 8080:

1. **The number in the service's own web address.** Already parsed and sitting unused —
   `buildForm()` stamps the whole `webui` string on each service and nothing reads it. The token is
   pulled out with the same expression `reorderPortsForWebUI()` in `javascript/ca-convert.js`
   already uses, so there is one parser for this, not two.
2. **A port the running container exposes**, when the file's address offers nothing.
3. **Today's 8080, counted up** — unchanged, and still what a brand-new file with no address gets.

Every candidate passes the same already-taken check the current number does, and is skipped if the
file already has a row for it.

**Only the one-sided shape changes.** A bridge service's **+ port** still writes the two-sided
`8080:8080` it writes today. On a bridge the number in the address genuinely is ambiguous, and there
is no problem there to fix.

## Part B — saying when the evidence disagrees

A second line under the port row, on host and macvlan services only, naming each place a port was
found and what it said — but **only when they do not agree**. Silence when they do; a note that
appears every time is a note nobody reads.

> The web address in this file says port 80. The container declares 8080. Only one of them can be
> right, so check which one the application is actually listening on.

This goes in the same live-advice slot PLAN_49 used, so it tracks an edit rather than going stale.

## Part C — getting the exposed ports to the form

The form has no idea what a container exposes; the editor's own request reads the file and nothing
else. Two facts make this cheap:

- `staxx_container_net()` in `include/Stacks.php` **already parses the exposed-port list** on every
  render, but throws it away unless the container publishes nothing. Keeping it always is a one-line
  change to what that function returns.
- `staxx_state_snapshot()` in `include/StacksTable.php` is **already keyed by service name** and
  already polled by the page while the editor is open. A `ports` list beside the address it already
  sends costs no extra work on the server.

So the browser keeps the most recent state reply in a variable and the form reads the exposed ports
for its own service out of it. No new request, no Docker call added to opening the editor — which is
the one route that could make the editor feel slow, and the reason it is not taken.

A stack that has never run supplies nothing here, and the file's own address carries the suggestion
alone. That is the honest answer rather than a fallback worth building.

## Part D — a button that tests the link

**Where:** the container row's menu, beside Logs — *"Test web page"*. Not in the editor: the address
depends on the saved file *and* the running container, so a test run against unsaved text would be
answering a different question.

**What it does.** A new endpoint case resolves the address exactly as the row's button does, then
asks for it once with a four-second limit and reports what came back. It follows the existing
`probe` pattern precisely: the browser sends a stack and a service, never a URL, so there is no path
from the page to an arbitrary address. The address itself comes from the user's own compose file and
is already guaranteed to be `http` or `https` before anything is emitted, and it is shell-quoted on
top of that.

**What it says** — full sentences, in the log panel the self-test already uses:

- *"Something answered at http://192.168.202.64:80 — the web page opens."*
- *"Nothing answered at http://192.168.202.64:8080 within four seconds. Check the port in the ports
  section against the port the application is listening on."*
- *"http://…:8080 replied 404. Something is listening there, but that is not the web page — the port
  is probably right and the path wrong."*
- *"This container has no web address to test."* / *"Start the container first."*

A reply of any kind is the useful signal; the code it replies with is the detail.

---

## Files

| File | Change |
|---|---|
| `javascript/compose-model.js` | The candidate list in `newEntry()`'s port branch; the disagreement line in the advice pass. |
| `javascript/stacks.js` | Keep the last state reply; pass the service's exposed ports into the form; the menu item and its verdict. |
| `include/Stacks.php` | `staxx_container_net()` keeps the exposed list always. One small function resolving a stack and service to its web address, and one that tries it. |
| `include/StacksTable.php` | `staxx_state_snapshot()` carries the exposed ports per service. |
| `include/action.php` | One new case, in the existing allowlist switch. |

## Verification

Nothing runs on the development machine — no PHP, no Docker, no browser.

**Locally:** `node --check` on both browser files; `tests/js_undeclared.js`; `tests/yaml_roundtrip.js`
and `tests/ca_convert.js` must both pass with their counts unchanged.

**On the server:** `php -l` over the changed includes, and all twelve suites. Extend
`tests/server/webui.php` with the address-resolving function — a stack that does not exist, a service
that does not exist, a service with no address, and a real one. The trying-it function is tested
against a URL that cannot answer, to prove it reports a failure rather than hanging.

**In the browser, on the real server:**
- **it-tools** — the case that started this. Delete its port row, click **+ port**, and confirm the
  suggestion is 80 and not 8080, with a line naming the 8080 the container declares.
- **glances** — no ports at all. Confirm the suggestion is 61208.
- A **bridge** stack — confirm **+ port** still writes a two-sided mapping and shows no new line.
- **Test web page** on a container that works, on it-tools with a deliberately wrong port, and on a
  stopped container.

## Risks

- **The number in the address is still only evidence.** It was right both times it mattered here and
  wrong once out of four where it did not, so it is offered, explained, and editable — never used to
  build a link behind anyone's back. That remains the rule PLAN_49 set.
- **The server makes an outbound request.** Only to an address out of the user's own compose file, on
  their own machine, capped at four seconds, and reachable only by naming a stack and a service. The
  page cannot hand it a URL.
- **A note that fires too often stops being read.** Hence silence when the sources agree — which, on
  the evidence, is most of the time.
- **The exposed-port list rides on the state reply.** If that reply is ever trimmed for speed the
  suggestion quietly loses its second source. Worth a comment where the field is added, saying what
  reads it.
