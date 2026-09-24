/* StaXX — PLAN_179 part 3: merges the Community Applications corpus.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_corpus.js
 *
 * Drives merge-examine.js/merge-write.js/merge-suggest.js against every family under
 * tests/fixtures/ca-corpus/ (built by tests/tools/build_ca_corpus.js) the way the wizard's own
 * client-side code would — same shape as tests/merge_walk_six_dryrun.js and
 * tests/merge_walk_ta_dryrun.js, but driving every family in one pass rather than one fixture's
 * own planted traps. Every family is merged in both pick orders with every clash left on its own
 * recommended default (decisions: {}), then:
 *
 *   - the merged text must parse cleanly (no unreadTail);
 *   - every wiring variable build_ca_corpus.js pointed at the box's address must have become a
 *     service-name address once the merge gave every sibling a real place in one file — the
 *     whole reason PLAN_179 exists is that a merge is only trustworthy when nothing it changes
 *     goes unaccounted for, and an address that still reads "192.0.2.10" after the merge is
 *     exactly that: a silent non-change where one was expected;
 *   - tests/merge_audit.js's own audit(), when present, is run over both pick orders and its
 *     problems printed — this file was still being written by another agent when this suite was
 *     drafted, so it is loaded defensively and skipped with a note if it is not there yet.
 *
 * A family that raises a clash (a container-name clash, a port clash) is not a failure here — it
 * is printed, because a real clash between two genuine Community Applications templates is worth
 * knowing about on its own account. What IS a failure is the merged file not parsing, a wiring
 * variable not resolving to a service address, or the audit reporting a problem.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var ME = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-examine.js');
var MW = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-write.js');
var MS = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-suggest.js');

var AUDIT = null;
try { AUDIT = require('./merge_audit.js'); } catch (e) { /* not built yet — see module header */ }

var CORPUS_DIR = path.join(__dirname, 'fixtures', 'ca-corpus');

var fails = [];
function fail(where, message) { fails.push(where + ': ' + message); }

/* =========================================================================
 * Reading a family off disk
 * ========================================================================= */

function listFamilies() {
  return fs.readdirSync(CORPUS_DIR).filter(function (name) {
    if (name === '.feed' || name === 'INDEX.md') return false;
    return fs.statSync(path.join(CORPUS_DIR, name)).isDirectory();
  });
}

function listMembers(family) {
  var dir = path.join(CORPUS_DIR, family);
  return fs.readdirSync(dir).filter(function (name) {
    var p = path.join(dir, name);
    return fs.statSync(p).isDirectory() && fs.existsSync(path.join(p, 'compose.yaml'));
  }).sort();
}

function sourceNameFor(family, member) { return family + '/' + member; }

function readSources(family, members) {
  return members.map(function (member) {
    var text = fs.readFileSync(path.join(CORPUS_DIR, family, member, 'compose.yaml'), 'utf8');
    var name = sourceNameFor(family, member);
    return { name: name, text: text, envText: null, depth: 1, rel: name };
  });
}

/* =========================================================================
 * Per-family wiring expectations — the env values build_ca_corpus.js pointed at the box's own
 * address (192.0.2.10), which a merge should turn into a plain service-name address once every
 * sibling shares one file. Nothing here is re-derived from the corpus; it names exactly what
 * that generator's own WIRING.md records it changed, so this check can never quietly agree with
 * whatever the merge happens to produce.
 * ========================================================================= */

var BOX_IP = '192.0.2.10';

var WIRING_CHECKS = {
  'tube-archivist': [
    { envKey: 'REDIS_CON', mustEndWith: ':6379"' },
    { envKey: 'ES_URL', mustEndWith: ':9200"' }
  ]
  // immich: nothing was rewired at generation time (already addressed by real container name
  // over a shared external network) — nothing to check here.
  // nextcloud-mariadb: neither template names the other at all — nothing to check here.
  // vpn-downloader: wired through network_mode, not an address — checked separately below.
};

function checkWiring(family, text, order) {
  var checks = WIRING_CHECKS[family];
  if (!checks) return;
  checks.forEach(function (c) {
    var re = new RegExp(c.envKey + ':\\s*"[^"]*' + c.mustEndWith.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    if (text.indexOf(BOX_IP) === -1) return;   // already gone everywhere — nothing left to flag
    var stillBoxIp = new RegExp(c.envKey + ':\\s*"[^"]*' + BOX_IP.replace(/\./g, '\\.') + '[^"]*"');
    if (stillBoxIp.test(text)) {
      fail(family + ' (' + order + ')', '`' + c.envKey + '` still reads the box\'s own address (' +
        BOX_IP + ') after the merge — it should have become a service-name address, the same way ' +
        'tests/merge_walk_ta_dryrun.js already proves for a hand-written Tube Archivist fixture.');
    } else if (!re.test(text)) {
      console.log('  NOTE: `' + c.envKey + '` changed but does not match the expected ":' +
        c.mustEndWith + '" shape — check by eye, this may just be a stricter pattern than the merge produced.');
    }
  });
}

function checkVpnDownloader(family, text, order) {
  if (family !== 'vpn-downloader') return;
  // PLAN_179 P2 — binhex-qbittorrent's own network_mode named binhex-official-gluetun's
  // container_name before the two templates shared a stack; the merge now rewrites it to
  // "service:<gluetun's final service key>" (a network-mode-join finding, approved by
  // default), which is what tests/merge_audit.js checks it against too. A leftover
  // "container:" reference here would mean the fix regressed, not merely an uncovered shape.
  if (/network_mode:\s*"container:/.test(text)) {
    fail(family + ' (' + order + ')', 'binhex-qbittorrent\'s network_mode still reads "container:…" ' +
      'after the merge — PLAN_179 P2\'s rewrite to "service:<name>" did not happen.');
    return;
  }
  var m = /network_mode:\s*"service:([^"]+)"/.exec(text);
  if (!m) {
    fail(family + ' (' + order + ')', 'binhex-qbittorrent\'s network_mode is not "service:<name>" ' +
      'after the merge — expected PLAN_179 P2\'s rewrite to have applied.');
  }
}

/* =========================================================================
 * One family, one pick order
 * ========================================================================= */

function runOrder(family, members, label) {
  var sources = readSources(family, members);
  var descs = sources.map(function (s) {
    return MW.descriptorFromText(s.name, s.text, s.envText, [], { filesLarge: null, depth: s.depth, rel: s.rel });
  });

  var mergedName = family + '/MERGED';
  var exam = ME.examine(descs, { date: '2026-09-24', newDepth: 1, newRel: mergedName });

  var built = MW.buildMergedText(sources, {
    name: mergedName, date: '2026-09-24', decisions: {}, envNames: {}, files: {}
  });

  var suggested = { text: built.text };
  if (built.text !== null) {
    suggested = MS.apply(built.text, { deps: [], health: {}, update: { mode: 'default' }, notify: { touched: false } });
  }

  return { sources: sources, exam: exam, built: built, suggested: suggested };
}

/* =========================================================================
 * Run every family
 * ========================================================================= */

var CLASH_KINDS = { 'container-name-clash': 1, 'port-clash': 1, 'shorthand-clash': 1, 'label-clash': 1, 'file-clash': 1 };

listFamilies().forEach(function (family) {
  var members = listMembers(family);
  console.log('\n=== ' + family + ' (' + members.length + ' members: ' + members.join(', ') + ') ===');

  var orderA = members;
  var orderB = members.slice().reverse();
  var runA = runOrder(family, orderA, 'A');
  var runB = runOrder(family, orderB, 'B');

  [{ label: 'A', r: runA }, { label: 'B', r: runB }].forEach(function (o) {
    var text = o.r.suggested.text;
    if (text === null || text === undefined) {
      fail(family + ' (' + o.label + ')', 'buildMergedText() refused — ' +
        (o.r.built.refusals || []).map(function (f) { return f.kind; }).join(', '));
      return;
    }

    var doc = CM.parse(text);
    if (doc.unreadTail) fail(family + ' (' + o.label + ')', 'the merged file does not fully parse (unreadTail present)');

    var clashes = o.r.exam.findings.filter(function (f) { return CLASH_KINDS[f.kind]; });
    clashes.forEach(function (f) {
      console.log('  CLASH (' + o.label + '): ' + f.kind + ' stack=' + f.stack + ' facts=' + JSON.stringify(f.facts));
    });

    checkWiring(family, text, o.label);
    checkVpnDownloader(family, text, o.label);

    if (AUDIT) {
      var res = AUDIT.audit(o.r.sources, o.r.built, {});
      if (!res.ok) {
        res.problems.forEach(function (p) { fail(family + ' audit (' + o.label + ')', p); });
      }
      console.log('  audit (' + o.label + '): ' + (res.ok ? 'ok' : res.problems.length + ' problem(s), see above'));
    }
  });

  if (AUDIT && runA.built.text !== null && runB.built.text !== null) {
    var cmp = AUDIT.compareOrders(runA.sources, runA.built, runB.built);
    if (!cmp.ok) cmp.problems.forEach(function (p) { fail(family + ' order-compare', p); });
    console.log('  order A vs B: ' + (cmp.ok ? 'ok' : cmp.problems.length + ' problem(s), see above'));
  }

  console.log('  ' + family + ': ' + (fails.filter(function (f) { return f.indexOf(family) === 0 || f.indexOf(family + ' ') === 0; }).length === 0 ? 'ok' : 'see FAILED below'));
});

if (!AUDIT) {
  console.log('\nNOTE: tests/merge_audit.js was not found — its per-family audit and the order-' +
    'compare check were skipped. Re-run this suite once PLAN_179 part 1 lands.');
}

if (fails.length) {
  console.log('\nFAILED:');
  fails.forEach(function (f) { console.log('  ' + f); });
  process.exit(1);
}
console.log('\nall checks passed');
