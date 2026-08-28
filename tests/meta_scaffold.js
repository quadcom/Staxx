/* StaXX — tests for the x-unraid field scaffolder (PLAN_83).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/meta_scaffold.js
 *
 * No framework, no npm, no network — the same shape as stash_guard.js and
 * yaml_roundtrip.js: one line per case, and a non-zero exit if anything
 * fails.
 */

'use strict';

var fs   = require('fs');
var path = require('path');

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var M = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/meta-scaffold.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// True when every line of `orig` still appears in `out`, in the same
// relative order — the generic form of "only inserted, never rewrote or
// reordered", checked without knowing anything about YAML.
function isSubsequence(orig, out) {
  var j = 0;
  for (var i = 0; i < orig.length; i++) {
    while (j < out.length && out[j] !== orig[i]) j++;
    if (j >= out.length) return { ok: false, at: i, text: orig[i] };
    j++;
  }
  return { ok: true };
}

// Walks the compose model's own parse tree into a plain JS value — comments
// carry no meaning to compose, so they are dropped — so two parses can be
// compared with a plain JSON.stringify rather than anything YAML-aware.
function toPlain(node) {
  if (!node) return null;
  if (node.kind === 'map') {
    var out = {};
    node.keys.forEach(function (k) { out[k] = toPlain(node.pairs[k].value); });
    return out;
  }
  if (node.kind === 'seq') {
    return node.items.map(function (it) { return toPlain(it.value); });
  }
  if (node.kind === 'scalar') return node.value;
  return { opaque: node.kind };
}

// The correct invariant a scaffold has to hold — NOT "the parsed content is
// byte-identical" (a service that gains an x-unraid block does gain one:
// it parses as null until something is uncommented into it). What must
// never happen is a pre-existing key's value changing, or anything new
// appearing anywhere except inside an "x-unraid" key. This is what actually
// protects against the class of bug this file is named for: adding a real
// key that changes what a *different* part of the file means.
function onlyXUnraidAdded(beforeRoot, afterRoot) {
  var beforePlain = toPlain(beforeRoot);
  var afterPlain = toPlain(afterRoot);

  function walk(b, a) {
    if (b === null || typeof b !== 'object') {
      // A scalar or absent value: unchanged is fine, and turning an absent
      // key into an x-unraid key is handled by the caller before walk() is
      // ever reached for that key — so here, plain equality is required.
      return JSON.stringify(b) === JSON.stringify(a);
    }
    if (Array.isArray(b)) {
      if (!Array.isArray(a) || a.length !== b.length) return false;
      for (var i = 0; i < b.length; i++) if (!walk(b[i], a[i])) return false;
      return true;
    }
    // Object (map): every key that existed before must still carry the same
    // value. Extra keys in `a` are fine only when they are "x-unraid".
    for (var k in b) {
      if (!walk(b[k], a[k])) return false;
    }
    for (var k2 in a) {
      if (!(k2 in b) && k2 !== 'x-unraid') return false;
    }
    return true;
  }

  return walk(beforePlain, afterPlain);
}

/* =========================================================================
 * A. The basics — no block at all, at either level
 * ========================================================================= */

console.log('\nA. No block at either level');

(function () {
  var text = 'services:\n  web:\n    image: nginx\n';
  var r = M.scaffold(text);

  ok('A1 no error', r.error === '', r.error);
  ok('A2 reports changed', r.changed === true);
  ok('A3 stack fields all added', r.added.stack.indexOf('icon') >= 0 &&
     r.added.stack.indexOf('version') >= 0 && r.added.stack.indexOf('update') >= 0);
  ok('A4 service fields all added', r.added.services.web && r.added.services.web.indexOf('webui') >= 0);

  var doc = Y.parse(r.yaml);
  ok('A5 still parses clean', doc.root.kind === 'map' && !doc.unreadTail);
  ok('A6 x-unraid sits before services', r.yaml.indexOf('x-unraid:') >= 0 &&
     r.yaml.indexOf('x-unraid:') < r.yaml.indexOf('services:'));
  ok('A7 root x-unraid has a real version key', /^\s*version: 1$/m.test(r.yaml));
  ok('A8 service x-unraid is the service\'s last key', (function () {
    var svc = doc.root.pairs.services.value.pairs.web;
    return svc.value.keys[svc.value.keys.length - 1] === 'x-unraid';
  })());
  ok('A9 original lines all survive, in order', isSubsequence(text.split('\n'), r.yaml.split('\n')).ok);
})();

/* =========================================================================
 * B. Idempotency — the rule the whole feature stands on
 * ========================================================================= */

console.log('\nB. Idempotency');

(function () {
  var text = 'services:\n  web:\n    image: nginx\n';
  var once = M.scaffold(text);
  var twice = M.scaffold(once.yaml);

  ok('B1 second run reports no change', twice.changed === false);
  ok('B2 second run adds nothing', twice.added.stack.length === 0 &&
     Object.keys(twice.added.services).length === 0);
  ok('B3 second run returns the input byte for byte', twice.yaml === once.yaml);
})();

/* =========================================================================
 * C. A partly-filled block — only the gaps get added
 * ========================================================================= */

console.log('\nC. Partly filled block');

(function () {
  var text =
    'x-unraid:\n' +
    '  version: 1\n' +
    '  icon: fa-database\n' +
    '  category: Tools:Utilities\n' +
    '\n' +
    'services:\n' +
    '  web:\n' +
    '    image: nginx\n';
  var r = M.scaffold(text);

  ok('C1 no error', r.error === '');
  ok('C2 icon and category and version not re-offered', r.added.stack.indexOf('icon') < 0 &&
     r.added.stack.indexOf('category') < 0 && r.added.stack.indexOf('version') < 0);
  ok('C3 the gaps were added', r.added.stack.indexOf('overview') >= 0 &&
     r.added.stack.indexOf('project') >= 0 && r.added.stack.indexOf('update') >= 0);
  ok('C4 existing lines untouched, in place', r.yaml.indexOf('  icon: fa-database\n') >= 0 &&
     r.yaml.indexOf('  category: Tools:Utilities\n') >= 0);
  ok('C5 added lines land after the existing ones', r.yaml.indexOf('category: Tools:Utilities') <
     r.yaml.indexOf('# overview:'));
})();

/* =========================================================================
 * D. A commented placeholder already there — not doubled
 * ========================================================================= */

console.log('\nD. Commented placeholder already present');

(function () {
  var text =
    'x-unraid:\n' +
    '  version: 1\n' +
    '  # icon:            already offered, some day someone will fill this in\n' +
    '\n' +
    'services:\n' +
    '  web:\n' +
    '    image: nginx\n';
  var r = M.scaffold(text);

  ok('D1 icon not offered a second time', r.added.stack.indexOf('icon') < 0);
  // Only the stack-level block matters here — the service gets its own
  // "# icon:" offer, which is a separate field at a separate level.
  var stackPart = r.yaml.slice(0, r.yaml.indexOf('services:'));
  var count = (stackPart.match(/# icon:/g) || []).length;
  ok('D2 exactly one "# icon:" line in the stack-level block', count === 1, 'count=' + count);
  ok('D3 the original placeholder text survives verbatim', r.yaml.indexOf(
    '  # icon:            already offered, some day someone will fill this in') >= 0);
})();

/* =========================================================================
 * E. Refusals — the file must come back completely unchanged
 * ========================================================================= */

console.log('\nE. Refusals');

function refusalCase(name, text) {
  var r = M.scaffold(text);
  ok(name + ' refused', r.error !== '', 'no error was set');
  ok(name + ' says changed:false', r.changed === false);
  ok(name + ' yaml is byte-identical to the input', r.yaml === text);
}

refusalCase('E1 multi-doc',
  'services:\n  a:\n    image: x\n---\nservices:\n  b:\n    image: y\n');

refusalCase('E2 tab-indent',
  'services:\n\tweb:\n\t\timage: nginx\n');

refusalCase('E3 no services key at all',
  'x-unraid:\n  version: 1\nvolumes:\n  data:\n');

refusalCase('E4 services is not a map',
  'services: nothing-here\n');

refusalCase('E5 root x-unraid is not a map',
  'x-unraid: nothing-here\nservices:\n  web:\n    image: nginx\n');

refusalCase('E6 unread tail (a bad indent partway through)',
  'services:\n  web:\n    image: nginx\n    environment:\n        TZ: UTC\n       BAD: less indented than the line above\n');

/* =========================================================================
 * F. A single bad service is skipped, the rest still done
 * ========================================================================= */

console.log('\nF. One bad service among good ones');

(function () {
  var text =
    'services:\n' +
    '  good:\n' +
    '    image: alpine\n' +
    '  bad: just-a-string\n';
  var r = M.scaffold(text);

  ok('F1 no whole-file error', r.error === '');
  ok('F2 good service gets its fields', r.added.services.good && r.added.services.good.length > 0);
  ok('F3 bad service is named in skipped', r.skipped.some(function (s) { return s.indexOf('bad') >= 0; }));
  ok('F4 bad service line is untouched', r.yaml.indexOf('  bad: just-a-string\n') >= 0);
})();

/* =========================================================================
 * G. needsScaffold matches scaffold()'s own answer
 * ========================================================================= */

console.log('\nG. needsScaffold');

(function () {
  var untouched = 'services:\n  web:\n    image: nginx\n';
  var full = M.scaffold(untouched).yaml;

  ok('G1 true for a file with gaps', M.needsScaffold(untouched) === true);
  ok('G2 false once fully scaffolded', M.needsScaffold(full) === false);
})();

/* =========================================================================
 * H. Comments, an anchor and its aliases all survive, in order
 * ========================================================================= */

console.log('\nH. Anchors and aliases (07-yaml-quirks)');

(function () {
  var file = path.join(__dirname, 'fixtures/test-stacks/07-yaml-quirks/compose.yaml');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  var r = M.scaffold(text);

  ok('H1 no error', r.error === '');
  var lines = r.yaml.split('\n');
  var anchorAt = lines.findIndex(function (l) { return /^x-defaults:\s*&defaults/.test(l); });
  var aliasLines = [];
  lines.forEach(function (l, i) { if (/^\s*<<:\s*\*defaults/.test(l)) aliasLines.push(i); });

  ok('H2 the anchor is still there', anchorAt >= 0);
  ok('H3 both aliases are still there', aliasLines.length === 2);
  ok('H4 every alias comes after its anchor', aliasLines.every(function (i) { return i > anchorAt; }));

  var doc = Y.parse(r.yaml);
  ok('H5 the scaffolded file still parses clean', doc.root.kind === 'map' && !doc.unreadTail &&
     doc.warnings.length === 0);
  ok('H6 nothing from the original was lost, in order',
     isSubsequence(text.split('\n'), lines).ok);
})();

/* =========================================================================
 * I. The whole fixture corpus, scaffolded and round-tripped
 *
 * This is the regression test for the real defect: a scaffolded service
 * with no prior x-unraid block parses to `x-unraid: null` (proven with a
 * throwaway node probe against compose-model.js), which is why the schema
 * now accepts null there (schema/x-unraid.schema.json). Measured directly
 * on the test server against compose v2.40.3: `docker compose config
 * --hash='*'` gives the SAME service hash whether x-unraid is absent, a
 * bare null block, or fully populated — compose excludes every `x-`
 * extension key from the config hash entirely. So adding or filling in an
 * x-unraid block can never make a running stack look out of date; the only
 * thing that actually has to hold is that nothing ELSE in the file changed,
 * which is what onlyXUnraidAdded() below checks.
 * ========================================================================= */

console.log('\nI. Whole fixtures corpus');

(function () {
  var base = path.join(__dirname, 'fixtures/test-stacks');
  var dirs = fs.readdirSync(base).filter(function (d) {
    return fs.statSync(path.join(base, d)).isDirectory();
  });

  dirs.forEach(function (d) {
    var file = path.join(base, d, 'compose.yaml');
    if (!fs.existsSync(file)) return;
    var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

    var r;
    try { r = M.scaffold(text); } catch (e) { r = null; }
    if (!ok(d + ': does not throw', !!r, r ? '' : 'threw')) return;

    if (r.error) {
      ok(d + ': refusal leaves the file untouched', r.yaml === text && r.changed === false);
      return;
    }

    var sub = isSubsequence(text.split('\n'), r.yaml.split('\n'));
    ok(d + ': nothing lost, in order', sub.ok, sub.ok ? '' : ('line ' + sub.at + ': ' + JSON.stringify(sub.text)));

    var doc = Y.parse(r.yaml);
    ok(d + ': scaffolded file still a plain document', doc.root.kind === 'map' && !doc.unreadTail);

    var before = Y.parse(text);
    ok(d + ': nothing changed or added except inside x-unraid',
       onlyXUnraidAdded(before.root, doc.root));

    var again = M.scaffold(r.yaml);
    ok(d + ': idempotent — a second pass changes nothing', again.changed === false && again.yaml === r.yaml);
  });
})();

/* =========================================================================
 * J. missingFields() agrees with scaffold() — the rule the whole change
 * stands on. Same field names, same services, same error, same "fresh"
 * answer, for every hand-written case below and for the entire fixture
 * corpus (one loop, so a new fixture is covered for free).
 * ========================================================================= */

console.log('\nJ. missingFields() agrees with scaffold()');

// Compares one scaffold() result against one missingFields() result over the
// same input text and reports every mismatch under one label, so a failure
// names exactly what disagreed instead of just "not equal".
function assertAgree(name, text) {
  var s = M.scaffold(text);
  var m = M.missingFields(text);

  ok(name + ': error text matches', s.error === m.error, JSON.stringify([s.error, m.error]));
  if (s.error) return;

  ok(name + ': stack fields match', JSON.stringify(s.added.stack) === JSON.stringify(m.missing.stack),
     JSON.stringify([s.added.stack, m.missing.stack]));
  ok(name + ': service fields match', JSON.stringify(s.added.services) === JSON.stringify(m.missing.services),
     JSON.stringify([s.added.services, m.missing.services]));
  ok(name + ': skipped list matches', JSON.stringify(s.skipped) === JSON.stringify(m.skipped),
     JSON.stringify([s.skipped, m.skipped]));
  ok(name + ': changed flag matches', s.changed === m.changed);

  var rootXu = Y.parse(text).root.pairs && Y.parse(text).root.pairs['x-unraid'];
  var expectFresh = !rootXu;
  ok(name + ': fresh flag correct', m.fresh === expectFresh, 'fresh=' + m.fresh);
}

assertAgree('J1 no x-unraid at all', 'services:\n  web:\n    image: nginx\n');

assertAgree('J2 partial root block',
  'x-unraid:\n  version: 1\n  icon: fa-database\n\nservices:\n  web:\n    image: nginx\n');

assertAgree('J3 full root block already scaffolded',
  M.scaffold('services:\n  web:\n    image: nginx\n').yaml);

assertAgree('J4 service with some fields already set',
  'services:\n' +
  '  web:\n' +
  '    image: nginx\n' +
  '    x-unraid:\n' +
  '      icon: fa-database\n' +
  '      webui: http://[IP]:80/\n');

assertAgree('J5 commented placeholders already present',
  'x-unraid:\n' +
  '  version: 1\n' +
  '  # icon:            already offered\n' +
  '\n' +
  'services:\n' +
  '  web:\n' +
  '    image: nginx\n');

assertAgree('J6 unreadable — multi-doc',
  'services:\n  a:\n    image: x\n---\nservices:\n  b:\n    image: y\n');
assertAgree('J7 unreadable — tab-indent', 'services:\n\tweb:\n\t\timage: nginx\n');
assertAgree('J8 unreadable — no services key', 'x-unraid:\n  version: 1\nvolumes:\n  data:\n');
assertAgree('J9 unreadable — bad tail indent',
  'services:\n  web:\n    image: nginx\n    environment:\n        TZ: UTC\n       BAD: less indented than the line above\n');

(function () {
  var base = path.join(__dirname, 'fixtures/test-stacks');
  var dirs = fs.readdirSync(base).filter(function (d) {
    return fs.statSync(path.join(base, d)).isDirectory();
  });
  dirs.forEach(function (d) {
    var file = path.join(base, d, 'compose.yaml');
    if (!fs.existsSync(file)) return;
    var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
    assertAgree(d, text);
  });
})();

/* =========================================================================
 * K. missingFields() reuses an already-parsed document, and never edits it
 * ========================================================================= */

console.log('\nK. missingFields() given a document, not text');

(function () {
  var text = 'x-unraid:\n  version: 1\n  icon: fa-database\n\nservices:\n  web:\n    image: nginx\n';
  var doc = Y.parse(text);
  var fromText = M.missingFields(text);
  var fromDoc = M.missingFields(doc);

  ok('K1 same answer from a doc as from text',
     JSON.stringify(fromText.missing) === JSON.stringify(fromDoc.missing));

  var before = Y.serialise(doc);
  M.missingFields(doc);
  var after = Y.serialise(doc);
  ok('K2 the document handed in is not modified', before === after);
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
