/* StaXX — PLAN_179 part 1: run the audit over every existing merge fixture.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_audit_all.js
 *
 * Drives merge-write.js/merge-examine.js against every fixture set this project already has —
 * the three walk fixtures (merge-walk, merge-walk-six, merge-walk-ta) and every pair under
 * merge-pairs/ — builds the merge in both pick orders with every card left on its own
 * recommended answer (decisionValue()'s own fallback in merge-write.js already IS the
 * recommended choice, so `decisions: {}` gets exactly that; no fixture here needs a decision
 * overridden to exercise a real path), then audits the result with tests/merge_audit.js. One
 * line per fixture per order: "ok", or the audit's own problems, one per line. Non-zero exit if
 * any fixture reports a problem.
 *
 * This is a reader, not an editor of the modules it drives: it never changes a decision to make
 * a problem go away, and a problem printed here is either a real, previously-unnoticed silent
 * change (a PLAN_179 finding) or a gap in the audit's own understanding (fixed in
 * tests/merge_audit.js, with a comment saying why) — never "relaxed" in this file.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var JS_DIR = path.join(__dirname, '..', 'src', 'staxx', 'usr', 'local', 'emhttp', 'plugins', 'staxx', 'javascript');
var MW = require(path.join(JS_DIR, 'merge-write.js'));
var AUDIT = require('./merge_audit.js');

var FIXTURES = path.join(__dirname, 'fixtures');
var failures = 0, fixtureCount = 0;

function report(label, res) {
  fixtureCount++;
  if (res.ok) { console.log('  ok    ' + label); return; }
  failures++;
  console.log('  FAIL  ' + label);
  res.problems.forEach(function (p) { console.log('          ' + p); });
}

// Runs one already-built pair of sources through buildMergedText() in both pick orders (or just
// the one order when there is only a single source — a merge of one is still a legal call, see
// merge_trip.js's own R2-19 "self-merge" case), audits each, then compares the two orders.
function runOrders(label, sources, mergedName) {
  if (sources.length < 2) {
    var opts = { name: mergedName, date: '2026-09-24', decisions: {}, envNames: {}, files: {} };
    var built = MW.buildMergedText(sources, opts);
    report(label, built.text === null ? { ok: true, problems: [] } : AUDIT.audit(sources, built, opts));
    return;
  }

  var optsA = { name: mergedName, date: '2026-09-24', decisions: {}, envNames: {}, files: {} };
  var builtA = MW.buildMergedText(sources, optsA);
  report(label + ' (order A)', builtA.text === null ? { ok: true, problems: [] } : AUDIT.audit(sources, builtA, optsA));

  var reversed = sources.slice().reverse();
  var optsB = { name: mergedName, date: '2026-09-24', decisions: {}, envNames: {}, files: {} };
  var builtB = MW.buildMergedText(reversed, optsB);
  report(label + ' (order B)', builtB.text === null ? { ok: true, problems: [] } : AUDIT.audit(reversed, builtB, optsB));

  if (builtA.text !== null && builtB.text !== null) {
    report(label + ' (both orders agree)', AUDIT.compareOrders(sources, builtA, builtB));
  }
}

/* =========================================================================
 * merge-pairs/ — every fixture under it with at least two sides that each
 * hold their own compose.yaml. A side with no compose.yaml (r2-self-merge's
 * own "b") means the fixture is a single-source case; depth-path-folder's
 * lone "source" side needs its own rel/depth (its whole point is a folder
 * MOVING, which a single source at depth 0 with no `rel` cannot exercise),
 * so it is skipped here rather than driven wrong.
 * ========================================================================= */

function readSide(dir) {
  var composePath = path.join(dir, 'compose.yaml');
  if (!fs.existsSync(composePath)) return null;
  var envPath = path.join(dir, '.env');
  return {
    text: fs.readFileSync(composePath, 'utf8'),
    envText: fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : null
  };
}

function runPairsFixtures() {
  var base = path.join(FIXTURES, 'merge-pairs');
  var names = fs.readdirSync(base).filter(function (n) { return fs.statSync(path.join(base, n)).isDirectory(); }).sort();
  names.forEach(function (fixtureName) {
    if (fixtureName === 'depth-path-folder') { console.log('  SKIP  merge-pairs/' + fixtureName + ' (single source, needs its own depth/rel — not a pair)'); return; }
    var dir = path.join(base, fixtureName);
    var sideNames = fs.readdirSync(dir).filter(function (n) { return fs.statSync(path.join(dir, n)).isDirectory(); }).sort();
    var sources = [];
    sideNames.forEach(function (sideName) {
      var data = readSide(path.join(dir, sideName));
      if (!data) return;   // e.g. r2-self-merge's own "b" — no second compose.yaml on purpose
      sources.push({ name: sideName, text: data.text, envText: data.envText, depth: 0, rel: sideName });
    });
    if (!sources.length) { console.log('  SKIP  merge-pairs/' + fixtureName + ' (no readable compose.yaml at all)'); return; }
    runOrders('merge-pairs/' + fixtureName, sources, 'merged-' + fixtureName);
  });
}

/* =========================================================================
 * The three walk fixtures — read exactly as their own dry runs do (same
 * companion-file tables, same box-address substitution), driven the same
 * generic way as the pairs above once the sources are built.
 * ========================================================================= */

function fileEntry(rel, size, referenced) {
  return { path: rel, size: size, dir: false, link: false, target: '', outside: false, keyLike: /\.(pem|key|crt|p12|pfx)$/i.test(rel) || /(^|\/)(secrets|certs)\//i.test(rel), referenced: !!referenced };
}
function dirEntry(rel) { return { path: rel, size: 0, dir: true, link: false, target: '', outside: false, keyLike: false, referenced: true }; }

function readBoxSub(fixtureDir, leafName, rel, boxIp) {
  var p = path.join(fixtureDir, leafName, rel);
  if (!fs.existsSync(p)) return null;
  var text = fs.readFileSync(p, 'utf8');
  return text.indexOf('__BOX_IP__') === -1 ? text : text.split('__BOX_IP__').join(boxIp);
}

function runWalkFixture(fixtureSubdir, storePrefix, boxIp, stacksTable, leaves, mergedName, findOverrideName) {
  var fixtureDir = path.join(FIXTURES, fixtureSubdir);
  function storeName(leafName) { return storePrefix + '/' + leafName; }

  var sources = leaves.map(function (leafName) {
    var text = readBoxSub(fixtureDir, leafName, 'compose.yaml', boxIp) || readBoxSub(fixtureDir, leafName, 'docker-compose.yml', boxIp);
    var envText = readBoxSub(fixtureDir, leafName, '.env', boxIp);
    var name = storeName(leafName);
    if (findOverrideName) {
      var overrideName = findOverrideName(fixtureDir, leafName);
      if (overrideName) {
        var overrideText = readBoxSub(fixtureDir, leafName, overrideName, boxIp);
        var desc = MW.descriptorFromText(name, text, envText, [], { overrideText: overrideText });
        text = desc.text;   // the override applied — see descriptorFromText()'s own comment
      }
    }
    return { name: name, text: text, envText: envText, depth: 1, rel: name };
  });

  runOrders(fixtureSubdir, sources, mergedName);
  // The companion-file plan (files:{}) is left empty above deliberately — none of the audit's
  // own checks read built.files, and the three walks' own dry runs already prove the file-copy
  // plan on their own terms (tests/merge_walk_*_dryrun.js). stacksTable/leaves are accepted for
  // parity with those dry runs' own signature but are not otherwise used here.
  void stacksTable;
}

var OVERRIDE_NAMES = ['compose.override.yml', 'compose.override.yaml', 'docker-compose.override.yml', 'docker-compose.override.yaml'];
function findOverrideNameWeb(fixtureDir, leafName) {
  for (var i = 0; i < OVERRIDE_NAMES.length; i++) {
    if (fs.existsSync(path.join(fixtureDir, leafName, OVERRIDE_NAMES[i]))) return OVERRIDE_NAMES[i];
  }
  return null;
}

/* =========================================================================
 * tests/fixtures/ca-corpus/ — PLAN_179 part 3's own corpus of real Community Applications
 * families (tests/merge_corpus.js drives this same directory in more depth, with its own
 * wiring-specific checks; this is the same fixture set folded into the generic audit runner for
 * good measure, since every fixture this project has is meant to be audited — PLAN_179 itself).
 * ========================================================================= */

function runCorpusFixtures() {
  var base = path.join(FIXTURES, 'ca-corpus');
  if (!fs.existsSync(base)) { console.log('  SKIP  ca-corpus (not built — see tests/tools/build_ca_corpus.js)'); return; }
  var families = fs.readdirSync(base).filter(function (n) {
    if (n === '.feed' || n === 'INDEX.md') return false;
    return fs.statSync(path.join(base, n)).isDirectory();
  }).sort();
  families.forEach(function (family) {
    var dir = path.join(base, family);
    var members = fs.readdirSync(dir).filter(function (n) {
      return fs.statSync(path.join(dir, n)).isDirectory() && fs.existsSync(path.join(dir, n, 'compose.yaml'));
    }).sort();
    var sources = members.map(function (member) {
      var name = family + '/' + member;
      return { name: name, text: fs.readFileSync(path.join(dir, member, 'compose.yaml'), 'utf8'), envText: null, depth: 1, rel: name };
    });
    if (!sources.length) { console.log('  SKIP  ca-corpus/' + family + ' (no members with a compose.yaml)'); return; }
    runOrders('ca-corpus/' + family, sources, family + '-merged');
  });
}

/* =========================================================================
 * Run everything.
 * ========================================================================= */

console.log('=== walk fixtures ===');
runWalkFixture('merge-walk', 'DEV-TESTING', null,
  {}, ['t155-web', 't155-db', 't155-cache', 't155-admin'], 'T155-MERGED/t155-site', findOverrideNameWeb);
runWalkFixture('merge-walk-six', 'DEV-TESTING', '192.0.2.169',
  {}, ['t169-edge', 't169-site', 't169-api', 't169-store', 't169-bus', 't169-tools'], 'T169-MERGED/t169-all', null);
runWalkFixture('merge-walk-ta', 'TubeArchivist', '192.0.2.178',
  {}, ['Demo-TubeArchivist', 'Demo-TubeArchivist-ES', 'Demo-TubeArchivist-Redis'], 'TubeArchivist/Demo-TubeArchivist-Stack', null);

console.log('\n=== merge-pairs fixtures ===');
runPairsFixtures();

console.log('\n=== ca-corpus fixtures ===');
runCorpusFixtures();

console.log('\n' + fixtureCount + ' checks run, ' + failures + ' failed.');
process.exit(failures ? 1 : 0);
