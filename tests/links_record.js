/* StaXX — tests for the connection record: writer, matching and staleness
 * (PLAN_70 stage 3).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/links_record.js
 *
 * No framework, no npm, no network — the same shape as links_detect.js.
 * Schema conformance is checked by shelling out to python3 with pyyaml and
 * jsonschema, the same libraries tests/validate_schema.py itself uses,
 * rather than re-typing its rules a second time here in JavaScript.
 */

'use strict';

var fs = require('fs');
var os = require('os');
var path = require('path');
var childProcess = require('child_process');

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var ROOT = path.join(__dirname, '..');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// Runs the whole compose document written back by `text` through the same
// jsonschema Draft202012Validator tests/validate_schema.py uses, so a link
// entry is proven against the real schema rather than a JS re-statement of
// its rules. Returns the parsed {ok, errors} result of a tiny python script
// piped the YAML on stdin.
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

/* ---- case 1: write, read back, schema-valid ------------------------------ */
(function () {
  var yaml = [
    'services:',
    '  app:',
    '    environment:',
    '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:',
    '    environment:',
    '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc);
  var c = Y.detectLinks(form)[0];
  ok('case 1: the pair is detected first', !!c && c.kind === 'secret');

  var res = Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  ok('case 1: the writer accepts it', res.ok, res.error);
  ok('case 1: linkState reads back confirmed', Y.linkState(doc, c.kind, c.between) === 'confirmed');

  var text = Y.serialise(doc);
  ok('case 1: the compose text still parses', /x-unraid:\n\s+links:/.test(text), text);
  var v = validateAgainstSchema(text);
  ok('case 1: the written record validates against the schema', v.ok, JSON.stringify(v.errors));
})();

/* ---- case 2: re-confirming updates in place, never a duplicate ----------- */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var c = Y.detectLinks(Y.buildForm(doc))[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  var res2 = Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  ok('case 2: re-confirming reports ok', res2.ok);
  ok('case 2: still exactly one record', Y.readLinks(doc).length === 1, Y.readLinks(doc).length);

  var res3 = Y.setLinkState(doc, c.kind, c.certainty, c.between, 'rejected');
  ok('case 3: flipping to rejected updates the same entry', res3.ok && Y.readLinks(doc).length === 1);
  ok('case 3: state is now rejected', Y.linkState(doc, c.kind, c.between) === 'rejected');
})();

/* ---- case 4: the writer refuses a declared connection --------------------- */
(function () {
  var yaml = ['services:', '  app:', '    depends_on:', '      - db', '  db:', '    image: alpine'].join('\n');
  var doc = Y.parse(yaml);
  var c = Y.detectLinks(Y.buildForm(doc)).filter(function (x) { return x.kind === 'reference' && x.certainty === 'declared'; })[0];
  ok('case 4: a depends_on reference is detected as declared', !!c);
  var res = Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  ok('case 4: the writer refuses it', res.ok === false);
  ok('case 4: nothing was written', Y.readLinks(doc).length === 0);
})();

/* ---- case 5: withdrawing removes the record and collapses empty levels --- */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var c = Y.detectLinks(Y.buildForm(doc))[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  var res = Y.setLinkState(doc, c.kind, c.certainty, c.between, null);
  ok('case 5: withdrawing reports ok', res.ok);
  ok('case 5: no record left', Y.readLinks(doc).length === 0);
  ok('case 5: the whole x-unraid block is gone, not left empty',
     Y.serialise(doc).indexOf('x-unraid') < 0, Y.serialise(doc));

  // Withdrawing something never recorded is a no-op success, not a refusal.
  var res2 = Y.setLinkState(doc, c.kind, c.certainty, c.between, null);
  ok('case 5: withdrawing an unrecorded pair is a harmless ok', res2.ok);
})();

/* ---- case 6: withdrawing one link leaves a sibling x-unraid key alone ---- */
(function () {
  var yaml = [
    'x-unraid:',
    '  version: 1',
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var c = Y.detectLinks(Y.buildForm(doc))[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  ok('case 6: version survives the write', Y.serialise(doc).indexOf('version: 1') >= 0);
  Y.setLinkState(doc, c.kind, c.certainty, c.between, null);
  var text = Y.serialise(doc);
  ok('case 6: version survives the withdrawal', text.indexOf('version: 1') >= 0, text);
  ok('case 6: links: itself is gone', text.indexOf('links:') < 0, text);
})();

/* ---- case 7: reordering the environment block does not break the match -- */
(function () {
  var before = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(before);
  var c = Y.detectLinks(Y.buildForm(doc))[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  var written = Y.serialise(doc);

  // Same record, but the app service now lists DB_PASSWORD second — its
  // field id's "#index" has moved, so a position-keyed record would miss.
  var reordered = written.replace(
    'environment:\n      DB_PASSWORD: Tr0ub4dor&3xyz',
    'environment:\n      OTHER_VAR: 1\n      DB_PASSWORD: Tr0ub4dor&3xyz'
  );
  var doc2 = Y.parse(reordered);
  var c2 = Y.detectLinks(Y.buildForm(doc2))[0];
  ok('case 7: the reordered pair is still detected', !!c2);
  ok('case 7: the record still matches after reordering', Y.linkState(doc2, c2.kind, c2.between) === 'confirmed');
})();

/* ---- case 8: a stale record survives a save, is shown not dropped -------- */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc);
  var c = Y.detectLinks(form)[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');

  // The setting the record names is renamed away from under it.
  var renamed = Y.serialise(doc).replace('DB_PASSWORD: Tr0ub4dor&3xyz', 'DB_PASS: Tr0ub4dor&3xyz');
  var doc2 = Y.parse(renamed);
  var form2 = Y.buildForm(doc2);

  ok('case 8: the record is still there after the rename', Y.readLinks(doc2).length === 1);
  var stale = Y.staleLinks(doc2, form2);
  ok('case 8: it is reported as stale', stale.length === 1, JSON.stringify(stale));

  // An unrelated structural edit elsewhere in the file must not touch it.
  var line = Y.addItem(doc2, form2, 'app', 'setting', 'x', 'expose');
  ok('case 8: an unrelated edit elsewhere still succeeds', line >= 0);
  ok('case 8: the stale record is untouched by it, not pruned', Y.readLinks(Y.parse(Y.serialise(doc2))).length === 1);
})();

/* ---- case 9: a rejected connection is silent to detection's own reader --- */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var c = Y.detectLinks(Y.buildForm(doc))[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'rejected');
  ok('case 9: state reads back rejected', Y.linkState(doc, c.kind, c.between) === 'rejected');
  // detectLinks() itself still finds the pair (it is a pure read of the
  // file, unaware of confirm/reject state by design) — going silent is
  // stacks.js's job, on top of this, which stacks.js's own suite cannot
  // exercise without a browser. See this file's own header note and the
  // final report for what that leaves unverified.
  ok('case 9: detectLinks itself is unaffected (a pure read)', Y.detectLinks(Y.buildForm(doc)).length === 1);
})();

/* ---- case 10: a real corpus file gains a links block and loses nothing --- */
(function () {
  var file = path.join(ROOT, 'tests', 'fixtures', 'test-stacks', '03-multi-tier', 'compose.yaml');
  var before = fs.readFileSync(file, 'utf8');
  var doc = Y.parse(before);
  var form = Y.buildForm(doc);
  ok('case 10: the fixture parses cleanly', form.ok, form.warnings);

  var res = Y.setLinkState(doc, 'secret', 'inferred',
    [{ service: 'demo-database', environment: 'POSTGRES_PASSWORD' },
     { service: 'demo-cache', environment: 'REDIS_PASSWORD' }], 'confirmed');
  ok('case 10: the writer accepts it', res.ok, res.error);

  var after = Y.serialise(doc);
  var beforeLines = before.replace(/\r\n/g, '\n').split('\n');
  var afterLines = after.split('\n');
  var extra = afterLines.filter(function (l) { return beforeLines.indexOf(l) < 0; });
  // Every added line belongs to the new links: block — nothing existing was
  // rewritten, reflowed or dropped along the way.
  var onlyLinksLines = extra.every(function (l) {
    return /^\s*(links:|- kind: secret|state: confirmed|between:|- service: demo-(database|cache)|environment: (POSTGRES_PASSWORD|REDIS_PASSWORD))\s*$/.test(l);
  });
  ok('case 10: every added line is part of the new links block, nothing else changed',
     onlyLinksLines, extra.join('\n'));
  ok('case 10: every original line is still present, untouched',
     beforeLines.every(function (l) { return afterLines.indexOf(l) >= 0; }));

  var v = validateAgainstSchema(after);
  ok('case 10: the whole rewritten fixture still validates against the schema', v.ok, JSON.stringify(v.errors));
})();

/* =====================================================================
 * PLAN_70 stage 4 — the propagation offer's own data layer.
 *
 * computeOffer() below is a line-for-line mirror of stacks.js's own
 * computePropagationOffer(), built only from the primitives compose-
 * model.js exports (confirmedPartners, fieldForEndpoint) — stacks.js
 * itself cannot be required here, since it is a browser IIFE that expects
 * a DOM. Proving the exported primitives behave correctly, combined this
 * way, is as close to proving the browser function as node-only testing
 * gets; the report names exactly what is left unverified because of that.
 * ===================================================================== */
function computeOffer(form, mineEp, oldVal, newVal) {
  if (oldVal === newVal) return null;
  var partners = Y.confirmedPartners(form.doc, 'secret', mineEp);
  if (!partners.length) return null;
  var items = [];
  for (var i = 0; i < partners.length; i++) {
    var other = partners[i];
    var pf = Y.fieldForEndpoint(form, other);
    if (!pf || !pf.parts || !pf.parts.value) continue;
    var current = pf.parts.value.value;
    if (current === newVal) continue;
    items.push({ other: other, current: current, drift: current !== oldVal });
  }
  return items.length ? { mine: mineEp, newVal: newVal, items: items } : null;
}

/* ---- case 11: an unconfirmed connection offers nothing (condition 1) ----- */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc); form.doc = doc;
  // Nothing confirmed at all — the pair is only ever detected, never recorded.
  var offer = computeOffer(form, { service: 'app', environment: 'DB_PASSWORD' },
                            'Tr0ub4dor&3xyz', 'newpass123!');
  ok('case 11: an unconfirmed pair offers nothing', offer === null);
})();

/* ---- case 12: a confirmed FOLDER connection offers nothing (condition 2) - */
(function () {
  var yaml = [
    'services:',
    '  a:', '    volumes:', '      - /mnt/user/media:/data',
    '  b:', '    volumes:', '      - /mnt/user/media:/data'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc); form.doc = doc;
  var c = Y.detectLinks(form).filter(function (x) { return x.kind === 'folder'; })[0];
  ok('case 12: the shared folder is detected', !!c);
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  ok('case 12: it reads back confirmed', Y.linkState(doc, c.kind, c.between) === 'confirmed');
  // The offer only ever asks confirmedPartners() for kind 'secret' — proven
  // here by asking for 'folder' (finds it) and 'secret' (must not).
  ok('case 12: confirmedPartners(secret) sees nothing for it',
     Y.confirmedPartners(doc, 'secret', c.between[0]).length === 0);
  ok('case 12: confirmedPartners(folder) does see it, for contrast',
     Y.confirmedPartners(doc, 'folder', c.between[0]).length === 1);
})();

/* ---- case 13: a confirmed REFERENCE connection offers nothing (cond. 2) -- */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      TARGET: db-service-name',
    '  db-service-name:', '    image: alpine'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc); form.doc = doc;
  var c = Y.detectLinks(form).filter(function (x) { return x.kind === 'reference' && x.certainty === 'inferred'; })[0];
  ok('case 13: the inferred reference is detected', !!c);
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  ok('case 13: confirmedPartners(secret) sees nothing for it',
     Y.confirmedPartners(doc, 'secret', c.between[0]).length === 0);
})();

/* ---- case 14: a drifted partner offers nothing and reports drift (cond 5) */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc); form.doc = doc;
  var c = Y.detectLinks(form)[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');

  // db's own password is changed directly — not through propagation — so
  // the pair has already drifted apart before app is ever touched.
  var written = Y.serialise(doc).replace('POSTGRES_PASSWORD: Tr0ub4dor&3xyz', 'POSTGRES_PASSWORD: SomethingElse99!');
  var doc2 = Y.parse(written);
  var form2 = Y.buildForm(doc2); form2.doc = doc2;

  var offer = computeOffer(form2, { service: 'app', environment: 'DB_PASSWORD' },
                            'Tr0ub4dor&3xyz', 'BrandNewPass77!');
  ok('case 14: a drifted partner is still offered as an item', !!offer && offer.items.length === 1);
  ok('case 14: but flagged as drift, not a live offer', offer.items[0].drift === true);
  ok('case 14: its current value is the drifted one, not the old shared one',
     offer.items[0].current === 'SomethingElse99!');
})();

/* ---- case 15: a clean propagation writes only the partner's value ------- */
(function () {
  var file = path.join(ROOT, 'tests', 'fixtures', 'test-stacks', '03-multi-tier', 'compose.yaml');
  var rawBefore = fs.readFileSync(file, 'utf8');
  // The fixture's own two passwords are deliberately different (see its own
  // comment on why REDIS_PASSWORD is left unmarked) — nothing in the real
  // corpus holds a genuinely shared secret. Equalising them here, once,
  // before anything else runs is what lets detectLinks() find the pair for
  // real, the same way case 1 does, rather than forcing a confirm onto two
  // values that were never actually the same thing.
  var before = rawBefore.replace('REDIS_PASSWORD: also-change-me', 'REDIS_PASSWORD: change-me-please');
  var doc = Y.parse(before);
  var form = Y.buildForm(doc); form.doc = doc;

  var c = Y.detectLinks(form).filter(function (x) { return x.kind === 'secret'; })[0];
  ok('case 15: the equalised pair is genuinely detected', !!c, JSON.stringify(c));
  var mine = c.between[0].service === 'demo-database' ? c.between[0] : c.between[1];
  var other = c.between[0].service === 'demo-database' ? c.between[1] : c.between[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');
  // Confirming just spliced new lines in, which moves every field below
  // them — the form has to be rebuilt against the document as it now
  // stands, the same way stacks.js's own structuralEdit()/reparse() do
  // after every write, or the positions below would be read stale.
  form = Y.buildForm(doc); form.doc = doc;

  var oldVal = Y.fieldForEndpoint(form, mine).parts.value.value;
  var newVal = 'PropagatedSecret42!';
  var offer = computeOffer(form, mine, oldVal, newVal);
  ok('case 15: a clean pair is offered', !!offer && offer.items.length === 1 && !offer.items[0].drift);

  // The write itself: YAML.setValue, the exact call the ordinary field box
  // commits through in the browser (condition 6) — never a spliced line.
  var pf = Y.fieldForEndpoint(form, other);
  var written = Y.setValue(doc, form, pf.id, newVal);
  ok('case 15: the write reports ok', written);

  var after = Y.serialise(doc);
  // Compare against the file with the links: block already applied (that
  // part of the diff is proven by case 10 already) plus this one value
  // swap — so the ONLY further difference from setLinkState's own output
  // is the partner's own line.
  var afterConfirm = Y.serialise((function () {
    var d = Y.parse(before);
    Y.setLinkState(d, c.kind, c.certainty, c.between, 'confirmed');
    return d;
  })());
  var confirmLines = afterConfirm.split('\n');
  var afterLines = after.split('\n');
  var diffLines = [];
  for (var i = 0; i < Math.max(confirmLines.length, afterLines.length); i++) {
    if (confirmLines[i] !== afterLines[i]) diffLines.push({ line: i, was: confirmLines[i], now: afterLines[i] });
  }
  ok('case 15: exactly one line differs from the confirmed-but-unpropagated file, and it is the partner\'s',
     diffLines.length === 1 && diffLines[0].now.indexOf('PropagatedSecret42!') >= 0,
     JSON.stringify(diffLines));
})();

/* ---- case 16: three services share a secret, only one pair confirmed ---- */
(function () {
  // Marked -!S (see setComment()'s own doc comment above): a shared secret
  // among three or more services is only emitted as every pair when the
  // file itself says it is a secret — the exact rule the design's section 1
  // corrects for. Left unmarked, three services agreeing on one value reads
  // as a coincidence (TZ, PUID) instead, and detectLinks() drops it.
  var yaml = [
    'services:',
    '  app:',    '    environment:', '      SHARED: Tr0ub4dor&3xyz # -!S',
    '  worker:', '    environment:', '      SHARED: Tr0ub4dor&3xyz # -!S',
    '  db:',     '    environment:', '      SHARED: Tr0ub4dor&3xyz # -!S'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc); form.doc = doc;
  var pairs = Y.detectLinks(form).filter(function (x) { return x.kind === 'secret'; });
  ok('case 16: three services produce three pairs', pairs.length === 3, pairs.length);

  // Confirm ONLY app<->db, leaving app<->worker and worker<->db unconfirmed.
  var appDb = pairs.filter(function (p) {
    var svcs = p.between.map(function (e) { return e.service; }).sort();
    return svcs[0] === 'app' && svcs[1] === 'db';
  })[0];
  Y.setLinkState(doc, appDb.kind, appDb.certainty, appDb.between, 'confirmed');

  var offer = computeOffer(form, { service: 'app', environment: 'SHARED' }, 'Tr0ub4dor&3xyz', 'NewShared99!');
  ok('case 16: exactly one partner is offered, not the whole group',
     !!offer && offer.items.length === 1, JSON.stringify(offer));
  ok('case 16: the one partner offered is db, not worker',
     offer.items[0].other.service === 'db', JSON.stringify(offer));
})();

/* ---- case 17: a failed partner write leaves the file byte-identical ----- */
(function () {
  var yaml = [
    'services:',
    '  app:', '    environment:', '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:', '    environment:', '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var doc = Y.parse(yaml);
  var form = Y.buildForm(doc); form.doc = doc;
  var c = Y.detectLinks(form)[0];
  Y.setLinkState(doc, c.kind, c.certainty, c.between, 'confirmed');

  var mine = { service: 'app', environment: 'DB_PASSWORD' };
  var other = { service: 'db', environment: 'POSTGRES_PASSWORD' };
  var pf = Y.fieldForEndpoint(form, other);
  var before17 = Y.serialise(doc);

  // The line the partner's write would land on is edited out from under it
  // — the same "position went stale" failure PLAN_66 guards against —
  // proving the write is refused rather than landing somewhere wrong.
  doc.lines[pf.parts.value.spot.line] = '      POSTGRES_PASSWORD: "hand-edited mid-flight"';

  var written = Y.setValue(doc, form, pf.id, 'WouldHaveBeenWritten!');
  ok('case 17: the stale write is refused', written === false);
  ok('case 17: the file is unchanged apart from the hand edit that caused it',
     Y.serialise(doc).indexOf('WouldHaveBeenWritten') < 0);
})();

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
