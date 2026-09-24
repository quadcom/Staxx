/* StaXX — the dry run for PLAN_178's third (Tube Archivist) merge walkthrough.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_walk_ta_dryrun.js [outDir] [--check]
 *
 * Modelled on tests/merge_walk_six_dryrun.js — same idea, a much simpler shape: it drives
 * merge-examine.js/merge-write.js/merge-suggest.js against the three fixtures under
 * tests/fixtures/merge-walk-ta/ the way the wizard's own client-side code would, entirely on the
 * dev machine, before anything touches the box. This walkthrough deliberately plants no traps —
 * it is the everyday shape of a Community Applications install, three separate stacks finding
 * each other over the server's own address and their published ports — so with --check it
 * asserts only the ordinary things a realistic merge must get right: the two address rewires,
 * that the published ports survive, that the bind mounts are unchanged, and that the merged
 * file parses. Any clash finding here is a real finding to report, not a fixture mistake to
 * assert away. Run twice, in two pick orders, for the same reason the six-stack probe does.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var ME = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-examine.js');
var MW = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-write.js');
var MS = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-suggest.js');
var AUDIT = require('./merge_audit.js');   // PLAN_179 — every difference between the sources and
                                            // buildMergedText()'s own output must be accounted for.

var FIXTURES = path.join(__dirname, 'fixtures', 'merge-walk-ta');

var argv = process.argv.slice(2);
var CHECK = argv.indexOf('--check') >= 0;
var outArg = argv.filter(function (a) { return a !== '--check'; })[0];
var OUT_DIR = outArg ? path.resolve(outArg) : path.join(FIXTURES, '.dryrun');

var checkFails = [];

function section(title) { console.log('\n' + title); }

// install.sh does this same substitution with sed before the fixture ever reaches a real box;
// done here too, with a documentation-range address (RFC 5737), so the dry run exercises the
// real address-rewire path instead of leaving a literal "__BOX_IP__" sitting where nothing
// written with a leading letter, let alone a digit, could ever match it as a host.
var BOX_IP = '192.0.2.178';

function readText(leafName, rel) {
  var p = path.join(FIXTURES, leafName, rel);
  if (!fs.existsSync(p)) return null;
  var text = fs.readFileSync(p, 'utf8');
  return text.indexOf('__BOX_IP__') === -1 ? text : text.split('__BOX_IP__').join(BOX_IP);
}

// None of the three stacks has a companion file of its own — every mount is a bind onto
// /mnt/user/appdata, outside the stack folder, so there is nothing for merge-files to carry and
// no storage-carry finding is expected.
var STACKS = {
  'Demo-TubeArchivist': { files: [], large: null },
  'Demo-TubeArchivist-ES': { files: [], large: null },
  'Demo-TubeArchivist-Redis': { files: [], large: null }
};

var LEAVES = ['Demo-TubeArchivist', 'Demo-TubeArchivist-ES', 'Demo-TubeArchivist-Redis'];

function storeNameFor(leafName) { return 'TubeArchivist/' + leafName; }

function readDescriptors(leaves) {
  return leaves.map(function (leafName) {
    var text = readText(leafName, 'compose.yaml');
    var reply = STACKS[leafName];
    var storeName = storeNameFor(leafName);
    var extra = { filesLarge: reply.large, depth: 1, rel: storeName };
    return MW.descriptorFromText(storeName, text, null, reply.files, extra);
  });
}

var MERGED_NAME = 'TubeArchivist/Demo-TubeArchivist-Stack';

function runWalk(label, leaves) {
  section('=== ORDER ' + label + ' (' + leaves.join(', ') + ') ===');

  section('0. Reading the fixtures');
  var descs = readDescriptors(leaves);

  section('1. examine() — every finding');
  var exam = ME.examine(descs, { date: '2026-09-24', newDepth: 1, newRel: MERGED_NAME });
  var byKind = {};
  exam.findings.forEach(function (f) {
    byKind[f.kind] = (byKind[f.kind] || 0) + 1;
    var target = f.facts.path || f.facts.service || f.facts.volume || f.facts.declKind || f.facts.port ||
      f.facts.category || f.facts.field || '';
    var title = f.kind + (f.facts.from ? (' ' + f.facts.from + '->' + f.facts.to) : '');
    console.log('  ' + f.kind + '  stack=' + (f.stack || '-') + '  target=' + target + '  title="' + title + '"');
  });
  section('   counts by kind');
  Object.keys(byKind).sort().forEach(function (k) { console.log('  ' + k + ': ' + byKind[k]); });

  section('2. buildMergedText() — no decisions overridden: every wiring finding takes its own default');

  var sources = leaves.map(function (leafName, i) {
    var desc = descs[i];
    var storeName = storeNameFor(leafName);
    return { name: storeName, text: desc.text, envText: null, depth: 1, rel: storeName };
  });
  var filesReplies = {};
  leaves.forEach(function (leafName) {
    filesReplies[storeNameFor(leafName)] = { files: STACKS[leafName].files, large: STACKS[leafName].large };
  });

  var buildOpts = {
    name: MERGED_NAME, date: '2026-09-24',
    decisions: {}, envNames: {}, files: filesReplies
  };
  var built = MW.buildMergedText(sources, buildOpts);

  if (built.refusals && built.refusals.length) {
    console.log('  REFUSALS (nothing written past these):');
    built.refusals.forEach(function (f) { console.log('    ' + f.kind + ' — stack=' + f.stack); });
  }

  section('3. merge-suggest.apply() — no new cross-stack suggestions offered in this walk');
  var suggested = MS.apply(built.text, { deps: [], health: {}, update: { mode: 'default' }, notify: { touched: false } });

  console.log('  changes (' + built.changes.length + '):');
  built.changes.forEach(function (c) {
    console.log('    ' + (c.file || 'compose') + ' key=' + c.key + ' title="' + c.title + '"' + (c.removed ? ' (removed)' : ''));
  });

  console.log('\n  --- merged compose text (order ' + label + ') ---\n');
  console.log(suggested.text);
  console.log('\n  --- joined .env ---\n');
  console.log(built.env);

  section('4. retireText() — each source');
  sources.forEach(function (s) {
    var r = MW.retireText(s.text, MERGED_NAME, '2026-09-24');
    console.log('  --- ' + s.name + ' retired ---');
    console.log(r);
  });

  fs.mkdirSync(OUT_DIR, { recursive: true });
  var outFile = path.join(OUT_DIR, 'compose-' + label + '.yaml');
  fs.writeFileSync(outFile, suggested.text);
  section('5. wrote merged file (order ' + label + ')');
  console.log('  ' + outFile);

  return { exam: exam, built: built, suggested: suggested, sources: sources, opts: buildOpts };
}

var ORDER_A = ['Demo-TubeArchivist', 'Demo-TubeArchivist-ES', 'Demo-TubeArchivist-Redis'];
var ORDER_B = ['Demo-TubeArchivist-Redis', 'Demo-TubeArchivist-ES', 'Demo-TubeArchivist'];

var resultA = runWalk('A', ORDER_A);
var resultB = runWalk('B', ORDER_B);

function fail(item, message) { checkFails.push({ item: item, message: message }); }

if (!CHECK) {
  process.exit(checkFails.length ? 1 : 0);
}

/* =========================================================================
 * --check: the ordinary things a realistic, trap-free merge must get right, read from the
 * merged text and change records alone (what a real walk would also check on disk). Run
 * against BOTH orders — the point of this probe is that neither may disagree with the other.
 * No clash finding of any kind is asserted away here: this fixture has none planted, so any
 * that turns up is printed as a finding, never treated as an expected pass.
 * ========================================================================= */

var CLASH_KINDS = { 'container-name-clash': 1, 'port-clash': 1, 'shorthand-clash': 1, 'label-clash': 1, 'file-clash': 1 };

[{ label: 'A', r: resultA }, { label: 'B', r: resultB }].forEach(function (o) {
  section('--check (order ' + o.label + ')');
  var built = o.r.built, exam = o.r.exam, text = o.r.suggested.text;
  var doc = CM.parse(text);

  if (doc.unreadTail) fail('parse', 'order ' + o.label + ': the merged file does not fully parse (unreadTail present)');

  // Any clash of any kind is a finding to report, never a trap to assert away — this fixture
  // has none planted, so this loop only ever prints what it finds.
  exam.findings.forEach(function (f) {
    if (CLASH_KINDS[f.kind]) {
      console.log('  FINDING (unplanned): ' + f.kind + ' stack=' + f.stack + ' facts=' + JSON.stringify(f.facts));
    }
  });

  // ES_URL: http://<box>:17920 -> http://archivist-es:9200, nothing else in the value changed.
  if (text.indexOf('ES_URL: "http://archivist-es:9200"') === -1 && text.indexOf('ES_URL: http://archivist-es:9200') === -1) {
    fail('rewire-es', 'order ' + o.label + ': ES_URL did not come out as http://archivist-es:9200');
  }
  // REDIS_CON: redis://<box>:17637 -> redis://archivist-redis:6379, nothing else changed.
  if (text.indexOf('REDIS_CON: "redis://archivist-redis:6379"') === -1 && text.indexOf('REDIS_CON: redis://archivist-redis:6379') === -1) {
    fail('rewire-redis', 'order ' + o.label + ': REDIS_CON did not come out as redis://archivist-redis:6379');
  }

  var rewires = exam.findings.filter(function (f) { return f.kind === 'address-rewire'; });
  if (rewires.length < 2) fail('rewire-count', 'order ' + o.label + ': expected at least two address-rewire findings (ES and Redis), found ' + rewires.length);

  // PLAN_178 F2 — a comment naming what a field is FOR ("# Redis", "# ElasticSearch"), not the
  // address being replaced, stays true after the rewire and must survive it, marker and all —
  // otherwise Sanitise mode would quietly stop blanking these two addresses.
  if (text.indexOf('# Redis -!S') === -1) fail('comment-redis', 'order ' + o.label + ': the "# Redis -!S" label above REDIS_CON did not survive the rewire');
  if (text.indexOf('# ElasticSearch -!S') === -1) fail('comment-es', 'order ' + o.label + ': the "# ElasticSearch -!S" label above ES_URL did not survive the rewire');

  // The two published ports survive (PLAN_170's default: a rewired port is left published
  // unless the wizard is explicitly told to stop publishing it, which this walk never does).
  if (text.indexOf('17920:9200') === -1) fail('ports-es', 'order ' + o.label + ': the Elasticsearch port (17920:9200) did not survive the merge');
  if (text.indexOf('17637:6379') === -1) fail('ports-redis', 'order ' + o.label + ': the Redis port (17637:6379) did not survive the merge');
  if (text.indexOf('17800:8000') === -1) fail('ports-app', 'order ' + o.label + ': the app\'s own port (17800:8000) did not survive the merge');

  var portUnneeded = exam.findings.filter(function (f) { return f.kind === 'port-unneeded'; });
  portUnneeded.forEach(function (f) {
    if (f.choices && f.choices[0] && f.choices[0].ticked) {
      fail('port-unneeded-default', 'order ' + o.label + ': a port-unneeded finding (' + f.facts.service + ':' + f.facts.port + ') came recommended/ticked, which would stop it publishing by default');
    }
  });

  // The bind mounts are unchanged — none of these three stacks shares an appdata path with
  // another, so nothing here should be treated as a storage clash or renamed.
  ['/mnt/user/appdata/Demo-TubeArchivist/youtube:/youtube',
   '/mnt/user/appdata/Demo-TubeArchivist/cache:/cache',
   '/mnt/user/appdata/Demo-TubeArchivist/es:/usr/share/elasticsearch/data',
   '/mnt/user/appdata/Demo-TubeArchivist/redis:/data'].forEach(function (mount) {
    if (text.indexOf(mount) === -1) fail('mounts', 'order ' + o.label + ': the bind mount "' + mount + '" did not survive unchanged');
  });

  var storageCarry = exam.findings.filter(function (f) { return f.kind === 'storage-carry'; });
  if (storageCarry.length) fail('storage-carry', 'order ' + o.label + ': a storage-carry finding was raised (' + storageCarry.length + ') — these are bind mounts outside the store, none should be carried');

  // No settings-join finding — nothing here has a root .env, so findSettingsJoin() should
  // have nothing to do.
  var join = exam.findings.filter(function (f) { return f.kind === 'settings-join'; });
  if (join.length) fail('settings-join', 'order ' + o.label + ': an unexpected settings-join finding was raised');

  // General sanity, same shape as the other two walks' own probes.
  if (/^version:/m.test(text)) fail('gen', 'order ' + o.label + ': a "version:" line survived into the merged file');
  if (!built.changes.length) fail('gen', 'order ' + o.label + ': no change records were produced at all — a rewritten line with no mark');

  // PLAN_179 — every difference between this order's own sources and buildMergedText()'s own
  // output (never the post-suggest text) must be explained by a change record.
  var auditResult = AUDIT.audit(o.r.sources, built, o.r.opts);
  if (!auditResult.ok) {
    auditResult.problems.forEach(function (p) { fail('audit', 'order ' + o.label + ': ' + p); });
  }
});

// PLAN_179 — the two pick orders' own merged configs must agree apart from block order.
var orderCmp = AUDIT.compareOrders(resultA.sources, resultA.built, resultB.built);
if (!orderCmp.ok) orderCmp.problems.forEach(function (p) { fail('audit-order', p); });

if (checkFails.length) {
  console.log('\nFAILED:');
  checkFails.forEach(function (f) { console.log('  ' + f.item + ': ' + f.message); });
  process.exit(1);
}
console.log('\nall checks passed');
