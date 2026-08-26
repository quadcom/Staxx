/* StaXX — tests for the browser half of "parts that know about each other"
 * (PLAN_70 stage 5, and the §11 revision after the first look in a
 * browser: password-vs-value wording and the confirmed-link auto-write).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/crosslinks.js
 *
 * stacks.js itself cannot be required here — it is page glue with no
 * module.exports, wired straight to the DOM and to fetch() — so this
 * proves the pure, DOM-free pieces that behaviour needs, which either
 * already live in compose-model.js or are the exact fact stacks.js reads
 * off it (see its own section comment above crossLooksLikeAddress()): the
 * value-shape gate that decides whether a box is even worth asking the
 * server about, the reachability filter that stops a bare name match ever
 * being shown as reachable, that a stage-5 record (a kind: reference link
 * whose target endpoint carries a `stack`) round-trips, validates against
 * the schema, and is never reported stale by a file that has no way to
 * check another stack's own; and, from §11, that a field's own .sensitive
 * flag is what licenses the word "password" and that the field-writing
 * path used for a propagated write stays valid without a rebuild between
 * two sequential writes to the same document. Everything else stacks.js
 * adds — the mark, the popover, the debounce, the fetch() calls, the
 * advice text — is reviewed by hand, not run here, the same as every other
 * stacks.js-only function in this project.
 */

'use strict';

var path = require('path');
var childProcess = require('child_process');

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var ROOT = path.join(__dirname, '..');

var pass = 0, fail = 0;

function findKind(list, kind) {
  return list.filter(function (c) { return c.kind === kind; });
}

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// Same helper links_record.js already uses — proves a written record
// against the real schema rather than a JS restatement of its rules.
function validateAgainstSchema(text) {
  var script = [
    'import sys, json, yaml',
    'from jsonschema import Draft202012Validator',
    'schema = json.load(open(' + JSON.stringify(path.join(ROOT, 'schema', 'x-unraid.schema.json')) + '))',
    'doc = yaml.safe_load(sys.stdin.read())',
    'v = Draft202012Validator(schema)',
    'errors = [str(e.message) + " at /" + "/".join(map(str, e.path)) for e in v.iter_errors(doc)]',
    'print(json.dumps({"ok": not errors, "errors": errors}))'
  ].join('\n');
  var res = childProcess.spawnSync('python', ['-c', script], { input: text, encoding: 'utf8' });
  if (res.status !== 0) return { ok: false, errors: [res.stderr || 'python failed'] };
  try { return JSON.parse(res.stdout); } catch (e) { return { ok: false, errors: [res.stdout] }; }
}

/* ---- crossLooksLikeAddress: what actually triggers a server call -------- */
(function () {
  var yes = ['db', 'mariadb-01', 'db.internal', '192.168.1.5', '192.168.1.5:3306',
             'myserver:8080', 'http://db:3306', 'mysql://user@db/app', 'localhost', '127.0.0.1'];
  yes.forEach(function (v) {
    ok('address-shaped: "' + v + '" is asked about', Y.crossLooksLikeAddress(v));
  });

  var no = ['', '   ', '12345', '/mnt/user/media', './relative', '~/x',
            'true', 'false', 'unless-stopped', 'UTC', 'has spaces here',
            'a'.repeat(254)];
  no.forEach(function (v) {
    ok('not address-shaped: "' + v.slice(0, 20) + '" is never asked about', !Y.crossLooksLikeAddress(v));
  });
})();

/* ---- crossReachableCandidates: never presented as reachable on a bare name */
(function () {
  var candidates = [
    { stack: 'Databases/mariadb', service: 'db', via: 'service-name', network: 'br0' },
    { stack: 'Databases/mariadb', service: 'db', via: 'container-name' },   // no network named — must not count
    { stack: 'Other/api', service: 'api', via: 'port', port: '8080' },      // needs no network — must count
  ];
  var reachable = Y.crossReachableCandidates(candidates);
  ok('a name match WITH a shared network is kept', reachable.indexOf(candidates[0]) >= 0);
  ok('a name match with NO shared network is dropped, not shown as reachable', reachable.indexOf(candidates[1]) < 0);
  ok('a port match needs no network and is kept', reachable.indexOf(candidates[2]) >= 0);
  ok('exactly the two genuinely reachable candidates survive', reachable.length === 2, reachable.length);

  ok('no candidates at all -> nothing reachable, no offer', Y.crossReachableCandidates([]).length === 0);
  ok('candidates undefined -> nothing reachable, no offer (a "none" or "self" reply)',
     Y.crossReachableCandidates(undefined).length === 0);
})();

/* ---- the record: a kind: reference link naming another stack ------------ */
(function () {
  var yaml = [
    'services:',
    '  app:',
    '    environment:',
    '      DB_HOST: db',
    '  db:',
    '    image: alpine'
  ].join('\n');
  var doc = Y.parse(yaml);

  var between = [
    { service: 'app', environment: 'DB_HOST' },
    { stack: 'Databases/mariadb', service: 'db' }
  ];
  var res = Y.setLinkState(doc, 'reference', 'inferred', between, 'confirmed');
  ok('the writer accepts a cross-stack reference', res.ok, res.error);

  var text = Y.serialise(doc);
  var v = validateAgainstSchema(text);
  ok('it validates against the real schema', v.ok, JSON.stringify(v.errors));
  ok('the far endpoint carries the other stack\'s path', /stack:\s*Databases\/mariadb/.test(text), text);

  var recs = Y.readLinks(doc);
  ok('readLinks() reads exactly one record', recs.length === 1, recs.length);
  ok('the pointing (first) endpoint carries the setting', recs[0].between[0].environment === 'DB_HOST');
  ok('the target (second) endpoint is bare but for the stack and service',
     recs[0].between[1].stack === 'Databases/mariadb' && recs[0].between[1].service === 'db' &&
     recs[0].between[1].environment === undefined);

  ok('linkState() reads it back as confirmed', Y.linkState(doc, 'reference', between) === 'confirmed');

  // The point of the linkEndpointLive() patch: this file has no way to
  // check another stack's own file, so a cross-stack endpoint must never
  // be reported stale just because this form does not declare its service.
  var form = Y.buildForm(doc);
  var stale = Y.staleLinks(doc, form);
  ok('a live cross-stack record is never reported stale', stale.length === 0, JSON.stringify(stale));
})();

/* ---- a genuinely stale record (the pointing FIELD is gone) still shows -- */
(function () {
  var yaml = [
    'services:',
    '  app:',
    '    image: alpine',   // no DB_HOST setting at all
    '  db:',
    '    image: alpine'
  ].join('\n');
  var doc = Y.parse(yaml);
  var between = [
    { service: 'app', environment: 'DB_HOST' },
    { stack: 'Databases/mariadb', service: 'db' }
  ];
  Y.setLinkState(doc, 'reference', 'inferred', between, 'confirmed');
  var form = Y.buildForm(doc);
  var stale = Y.staleLinks(doc, form);
  ok('losing the pointing field IS still caught as stale — the patch only exempts the far side',
     stale.length === 1, JSON.stringify(stale));
})();

/* ---- reference vs secret ordering: swapping the endpoints must not match  */
(function () {
  var between = [
    { service: 'app', environment: 'DB_HOST' },
    { stack: 'Databases/mariadb', service: 'db' }
  ];
  var swapped = [between[1], between[0]];
  var yaml = ['services:', '  app:', '    environment:', '      DB_HOST: db', '  db:', '    image: alpine'].join('\n');
  var doc = Y.parse(yaml);
  Y.setLinkState(doc, 'reference', 'inferred', between, 'confirmed');
  ok('a reference is order-sensitive: the swapped pair reads as unrecorded',
     Y.linkState(doc, 'reference', swapped) === null);
  ok('the original ordering still reads as confirmed', Y.linkState(doc, 'reference', between) === 'confirmed');
})();

/* ---- crossOwnSlots: which of THIS service's own boxes is the username/
 * password, from the images table — never a guess from a box's name. This
 * is the corrected replacement for the crossSlotPattern() name-matching
 * heuristic the plan explicitly ruled out (PLAN_70 10.2's closing
 * paragraph). Uses db-images.js's real table, the same one the server and
 * the browser both read, rather than a fixture of its shape. -------------- */
(function () {
  var DbImages = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/db-images.js');
  var mariadbEntry = DbImages.lookupImage('mariadb');
  ok('the real table has a mariadb entry to test against', !!mariadbEntry);

  // A CONNECTING service that is itself a MariaDB (a replica pointing at a
  // primary, say) and happens to declare the documented setting names —
  // every one of them is resolved automatically, exactly the images-table
  // route PLAN_70 10.2 asks for.
  var ownNames = ['MARIADB_USER', 'MARIADB_PASSWORD', 'SOME_OTHER_SETTING'];
  var slots = Y.crossOwnSlots(mariadbEntry, ownNames);
  ok('a recognised image resolves both slots to real setting names it actually has',
     slots.user === 'MARIADB_USER' && slots.password === 'MARIADB_PASSWORD', JSON.stringify(slots));

  // An unrecognised image (entry null/undefined, exactly what
  // db-images.js's lookupImage() returns for anything not in the table)
  // offers a choice rather than filling anything — resolves NEITHER slot,
  // which is what tells stacks.js to show its own picker instead of a guess.
  var none = Y.crossOwnSlots(null, ['APP_USER', 'APP_PASSWORD']);
  ok('an unrecognised connecting image resolves nothing — offers a choice, fills nothing',
     Object.keys(none).length === 0, JSON.stringify(none));
  ok('the same holds for undefined, not just null',
     Object.keys(Y.crossOwnSlots(undefined, ['APP_USER'])).length === 0);

  // A recognised image whose documented setting names this particular
  // service does NOT actually declare (a service typed by hand, missing
  // one of them) never invents a box — the slot is simply left unresolved,
  // exactly like the unrecognised-image case above, one slot at a time.
  var partial = Y.crossOwnSlots(mariadbEntry, ['MARIADB_USER']);
  ok('a name the images table gives but this service does not have is never invented',
     partial.user === 'MARIADB_USER' && partial.password === undefined, JSON.stringify(partial));
  ok('and neither resolving at all answers {} — never a half-guessed object with junk in it',
     Object.keys(Y.crossOwnSlots(mariadbEntry, ['NEITHER_SETTING'])).length === 0);

  // MariaDB's own backward-compatibility fallback (MYSQL_* still works) is
  // exercised the same priority-ordered way the target side already uses —
  // the first name actually present wins, proving this shares the real
  // table's own ordering rather than a simplified copy of it.
  var legacy = Y.crossOwnSlots(mariadbEntry, ['MYSQL_USER', 'MYSQL_PASSWORD']);
  ok('the table\'s own priority order (MARIADB_* first, MYSQL_* fallback) is honoured here too',
     legacy.user === 'MYSQL_USER' && legacy.password === 'MYSQL_PASSWORD', JSON.stringify(legacy));
})();

/* ---- PLAN_70 §11.1: "password" is said only where the FILE marks a box
 * sensitive, never inferred from the fact that two settings match ---------
 *
 * linkAdviceText() itself lives in stacks.js, which cannot be required here
 * (see this file's own header) — but the one fact it now depends on for
 * this decision, a field's own .sensitive flag, comes straight out of
 * compose-model.js and IS provable here: build a form, confirm the flag
 * reads correctly off the -!S marker for each field detectLinks() names,
 * exactly the lookup applyLinkAdvice() makes (YAML.fieldById(form, id)
 * .sensitive) before ever building the sentence. -------------------------- */
(function () {
  // Neither box is marked -!S: a shared database NAME, not a password —
  // the case Adrian actually hit. Both fields must read as not sensitive.
  var yName = [
    'services:',
    '  app:',
    '    environment:',
    '      DB_NAME: shared_catalogue',
    '  db:',
    '    environment:',
    '      POSTGRES_DB: shared_catalogue'
  ].join('\n');
  var formName = Y.buildForm(Y.parse(yName));
  var secretsName = findKind(Y.detectLinks(formName), 'secret');
  ok('an unmarked shared value is still detected as kind: secret (the detector still cannot know what it is)',
     secretsName.length === 1, JSON.stringify(secretsName));
  if (secretsName.length === 1) {
    var c = secretsName[0];
    var isPassword = !!(Y.fieldById(formName, c.fields[0]) || {}).sensitive ||
                      !!(c.fields[1] && (Y.fieldById(formName, c.fields[1]) || {}).sensitive);
    ok('neither endpoint is marked sensitive, so the "password" wording must not fire',
       isPassword === false);
  }

  // The same shape, but one endpoint IS marked -!S — a genuine password.
  var ySecret = [
    'services:',
    '  app:',
    '    environment:',
    '      DB_PASSWORD: Tr0ub4dor&3xyz   # -!S',
    '  db:',
    '    environment:',
    '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var formSecret = Y.buildForm(Y.parse(ySecret));
  var secretsSecret = findKind(Y.detectLinks(formSecret), 'secret');
  ok('a genuinely marked pair is still detected', secretsSecret.length === 1, JSON.stringify(secretsSecret));
  if (secretsSecret.length === 1) {
    var c2 = secretsSecret[0];
    var isPassword2 = !!(Y.fieldById(formSecret, c2.fields[0]) || {}).sensitive ||
                       !!(c2.fields[1] && (Y.fieldById(formSecret, c2.fields[1]) || {}).sensitive);
    ok('one endpoint marked -!S is enough to license the word "password"', isPassword2 === true);
  }
})();

/* ---- PLAN_70 §11.3: a confirmed link's partner write and the edit that
 * caused it are one undo step, not two -------------------------------------
 *
 * commit() itself is stacks.js page glue and cannot be run here either, but
 * the mechanics it now leans on to avoid a second, more expensive model
 * rebuild between the two writes ARE provable at the compose-model layer:
 * that a field found via fieldForEndpoint() on a form built BEFORE the
 * first write is still valid to write through AFTER it (writeScalar()
 * replaces text on one line without changing the line count, so a
 * DIFFERENT field's own line and column are untouched), and that taking
 * ONE text snapshot before both writes — exactly what pushUndo() captures,
 * once, before commit()'s own write — restores both fields together. ------ */
(function () {
  var yaml = [
    'services:',
    '  app:',
    '    environment:',
    '      DB_PASSWORD: old-value',
    '  db:',
    '    environment:',
    '      POSTGRES_PASSWORD: old-value'
  ].join('\n');
  var doc = Y.parse(yaml);
  var between = [
    { service: 'app', environment: 'DB_PASSWORD' },
    { service: 'db', environment: 'POSTGRES_PASSWORD' }
  ];
  Y.setLinkState(doc, 'secret', 'inferred', between, 'confirmed');

  // The single snapshot an Undo restores to — taken before EITHER write,
  // the same moment pushUndo() takes it in commit().
  var snapshotBeforeEdit = Y.serialise(doc);

  var form = Y.buildForm(doc);   // built once, before any write — mirrors MODEL at the top of commit()
  var mineField = Y.fieldForEndpoint(form, between[0]);
  ok('the edited box itself can be found on the pre-write form', !!mineField);
  var wroteMine = Y.setValue(doc, form, mineField.id, 'new-value');
  ok('the own-field write succeeds', wroteMine);

  // The partner is looked up on the SAME (now technically post-one-write)
  // form, never rebuilt in between — proving the assumption commit() relies
  // on to skip that rebuild: the partner's own line was never touched by
  // the write above, so its recorded position is still good.
  var partnerField = Y.fieldForEndpoint(form, between[1]);
  ok('the partner box can still be found on that SAME form after the first write, with no rebuild', !!partnerField);
  var wrotePartner = Y.setValue(doc, form, partnerField.id, 'new-value');
  ok('the propagated write succeeds too, through the same field-writing path', wrotePartner);

  var afterBoth = Y.serialise(doc);
  ok('both values actually changed', /DB_PASSWORD:\s*new-value/.test(afterBoth) &&
     /POSTGRES_PASSWORD:\s*new-value/.test(afterBoth), afterBoth);

  // The Undo itself: restoring the ONE snapshot taken before either write
  // (never a snapshot taken between them, which is what a second pushUndo()
  // call would have captured) puts BOTH fields back, in one step.
  var restoredDoc = Y.parse(snapshotBeforeEdit);
  var restoredForm = Y.buildForm(restoredDoc);
  var restoredMine = Y.fieldForEndpoint(restoredForm, between[0]);
  var restoredPartner = Y.fieldForEndpoint(restoredForm, between[1]);
  ok('one Undo restores the edited side', restoredMine.parts.value.value === 'old-value');
  ok('the SAME one Undo restores the propagated side too — never left changed on its own',
     restoredPartner.parts.value.value === 'old-value');
})();

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
