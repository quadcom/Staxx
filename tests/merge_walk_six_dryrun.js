/* StaXX — the dry run for PLAN_169's second (six-stack) merge walkthrough.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_walk_six_dryrun.js [outDir] [--check]
 *
 * Modelled on tests/merge_walk_dryrun.js (PLAN_156's own probe) — same idea, a different
 * shape: it drives merge-examine.js/merge-write.js/merge-suggest.js against the six fixtures
 * under tests/fixtures/merge-walk-six/ the way the wizard's own client-side code would,
 * entirely on the dev machine, before anything touches the box. It prints what it found so a
 * person can read it; with --check it also asserts PLAN_169's own thirteen traps, plus a
 * fourteenth added for the address-rewire fix (F12, 2026-09-24), and exits non-zero on the
 * first miss.
 *
 * Every source is named exactly as the wizard names it — its path under the store
 * ("DEV-TESTING/t169-site"), not its bare folder name — for the same reason PLAN_156's own
 * probe does this (PLAN_155 C7): a source identity containing "/" once broke a rename map
 * built by string concatenation and taken apart with split('/'). Run twice, in two pick
 * orders, because a live fault can depend on which source got to a shared name first.
 *
 * Some --check assertions below are expected to fail. This fixture exists to hunt merge bugs,
 * not to prove there are none — a failure here is a finding, not a broken test, unless the
 * comment beside it says otherwise.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var ME = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-examine.js');
var MW = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-write.js');
var MS = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-suggest.js');

var FIXTURES = path.join(__dirname, 'fixtures', 'merge-walk-six');

var argv = process.argv.slice(2);
var CHECK = argv.indexOf('--check') >= 0;
var outArg = argv.filter(function (a) { return a !== '--check'; })[0];
var OUT_DIR = outArg ? path.resolve(outArg) : path.join(FIXTURES, '.dryrun');

var checkFails = [];   // {trap, message} — printed and turned into the exit code under --check

function section(title) { console.log('\n' + title); }

// install.sh does this same substitution with sed before the fixture ever reaches a real box
// (t169-api/compose.yaml's own comment explains why the placeholder is there); done here too,
// with a documentation-range address (RFC 5737), so the dry run exercises the real
// address-rewire path instead of leaving a literal "__BOX_IP__" sitting where nothing written
// with a leading letter, let alone a digit, could ever match it as a host.
var BOX_IP = '192.0.2.169';

function readText(leafName, rel) {
  var p = path.join(FIXTURES, leafName, rel);
  if (!fs.existsSync(p)) return null;
  var text = fs.readFileSync(p, 'utf8');
  return text.indexOf('__BOX_IP__') === -1 ? text : text.split('__BOX_IP__').join(BOX_IP);
}

function sizeOf(leafName, rel) { return fs.statSync(path.join(FIXTURES, leafName, rel)).size; }

function file(rel, size, referenced) {
  return { path: rel, size: size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: !!referenced };
}
function dir(rel) {
  return { path: rel, size: 0, dir: true, link: false, target: '', outside: false, keyLike: false, referenced: true };
}

// The companion-file listing merge-files would return, keyed by each stack's own LEAF —
// shaped exactly like tests/merge_walk_dryrun.js's own STACKS table.
var STACKS = {
  't169-edge': { files: [], large: null },
  't169-site': {
    files: [
      dir('html'),
      file('html/index.html', sizeOf('t169-site', 'html/index.html'), true)
    ],
    large: null
  },
  't169-api': {
    files: [ file('api.env', sizeOf('t169-api', 'api.env'), true) ],
    large: null
  },
  't169-store': {
    files: [
      file('db.env', sizeOf('t169-store', 'db.env'), true),
      dir('seed'),
      file('seed/01-seed.sql', sizeOf('t169-store', 'seed/01-seed.sql'), true)
    ],
    large: null
  },
  't169-bus': {
    files: [
      dir('config'),
      file('config/mosquitto.conf', sizeOf('t169-bus', 'config/mosquitto.conf'), true),
      file('config/banner.txt', sizeOf('t169-bus', 'config/banner.txt'), true)
    ],
    large: null
  },
  't169-tools': {
    files: [
      dir('www'),
      file('www/index.html', sizeOf('t169-tools', 'www/index.html'), true)
    ],
    large: null
  }
};

var LEAVES = ['t169-edge', 't169-site', 't169-api', 't169-store', 't169-bus', 't169-tools'];

function storeNameFor(leafName) { return 'DEV-TESTING/' + leafName; }

function readDescriptors(leaves) {
  return leaves.map(function (leafName) {
    var text = readText(leafName, 'compose.yaml');
    var envText = readText(leafName, '.env');
    var reply = STACKS[leafName];
    var storeName = storeNameFor(leafName);
    var extra = { filesLarge: reply.large, depth: 1, rel: storeName };
    return MW.descriptorFromText(storeName, text, envText, reply.files, extra);
  });
}

// Runs the whole walkthrough — reading, examine(), buildMergedText(), merge-suggest.apply(),
// retireText() — for one pick order, printing as it goes.
function runWalk(label, leaves) {
  section('=== ORDER ' + label + ' (' + leaves.join(', ') + ') ===');

  section('0. Reading the fixtures');
  var descs = readDescriptors(leaves);

  section('1. examine() — every finding');
  var exam = ME.examine(descs, { date: '2026-09-24', newDepth: 1, newRel: 'T169-MERGED/t169-all' });
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

  section('2. buildMergedText()');

  function findingsOfKind(kind) { return exam.findings.filter(function (f) { return f.kind === kind; }); }

  var decisions = {};
  // Trap 4 — the one real published-port clash (t169-bus and t169-tools, both on 19090).
  // examine()'s own recommended free port is accepted, the same one pickFreePort() would hand
  // the wizard.
  findingsOfKind('port-clash').forEach(function (f) { decisions[f.key] = f.facts.freePort; });

  // Any settings-join renames (root .env only — t169-store is the only source with one, so
  // this is expected to be empty; kept for shape parity with the first walk's own probe).
  var envNames = {};
  var join = findingsOfKind('settings-join')[0];
  if (join) {
    join.facts.renamed.forEach(function (r, i) {
      var changeKey = join.key + '|rename|' + i;
      decisions[changeKey] = 'choose-name';
      envNames[changeKey] = (r.from + '_' + r.stack).toUpperCase().replace(/[^A-Z0-9_]/g, '_');
    });
  }

  var sources = leaves.map(function (leafName, i) {
    var desc = descs[i];
    var storeName = storeNameFor(leafName);
    return { name: storeName, text: desc.text, envText: readText(leafName, '.env'), depth: 1, rel: storeName };
  });
  var filesReplies = {};
  leaves.forEach(function (leafName) {
    filesReplies[storeNameFor(leafName)] = { files: STACKS[leafName].files, large: STACKS[leafName].large };
  });

  var built = MW.buildMergedText(sources, {
    name: 'T169-MERGED/t169-all', date: '2026-09-24',
    decisions: decisions, envNames: envNames, files: filesReplies
  });

  if (built.refusals && built.refusals.length) {
    console.log('  REFUSALS (nothing written past these):');
    built.refusals.forEach(function (f) { console.log('    ' + f.kind + ' — stack=' + f.stack); });
  }

  section('3. merge-suggest.apply() — no new cross-stack suggestions offered in this walk');
  var suggested = MS.apply(built.text, { deps: [], health: {}, update: { mode: 'default' }, notify: { touched: false } });

  console.log('  changes (' + built.changes.length + '):');
  built.changes.forEach(function (c) {
    console.log('    ' + (c.file || 'compose') + ' key=' + c.key + ' title="' + c.title + '"' + (c.removed ? ' (removed)' : '') +
      (c.struckComment ? '  struckComment="' + c.struckComment.join(' / ') + '"' : ''));
  });

  console.log('\n  --- merged compose text (order ' + label + ') ---\n');
  console.log(suggested.text);
  console.log('\n  --- joined .env ---\n');
  console.log(built.env);

  section('4. retireText() — each source');
  sources.forEach(function (s) {
    var r = MW.retireText(s.text, 'T169-MERGED/t169-all', '2026-09-24');
    console.log('  --- ' + s.name + ' retired ---');
    console.log(r);
  });

  fs.mkdirSync(OUT_DIR, { recursive: true });
  var outFile = path.join(OUT_DIR, 'compose-' + label + '.yaml');
  fs.writeFileSync(outFile, suggested.text);
  fs.writeFileSync(path.join(OUT_DIR, '.env-' + label), built.env);
  section('5. wrote merged files (order ' + label + ')');
  console.log('  ' + outFile);

  return { exam: exam, built: built, suggested: suggested, sources: sources };
}

var ORDER_A = ['t169-edge', 't169-site', 't169-api', 't169-store', 't169-bus', 't169-tools'];
var ORDER_B = ['t169-tools', 't169-bus', 't169-store', 't169-api', 't169-site', 't169-edge'];

var resultA = runWalk('A', ORDER_A);
var resultB = runWalk('B', ORDER_B);

function fail(trap, message) { checkFails.push({ trap: trap, message: message }); }

if (!CHECK) {
  process.exit(checkFails.length ? 1 : 0);
}

/* =========================================================================
 * --check: the thirteen traps from PLAN_169, plus trap 14 (the F12 address-rewire fix), read
 * from the merged text and
 * change records alone (what a real walk would also check on disk). Run
 * against BOTH orders — the point of this probe is that neither may
 * disagree with the other.
 * ========================================================================= */

[{ label: 'A', r: resultA }, { label: 'B', r: resultB }].forEach(function (o) {
  section('--check (order ' + o.label + ')');
  var built = o.r.built, exam = o.r.exam, text = o.r.suggested.text;
  var doc = CM.parse(text);

  if (doc.unreadTail) fail(0, 'order ' + o.label + ': the merged file does not fully parse (unreadTail present)');

  // Trap 1 — same service name ("app") in t169-site and t169-api. Both must survive under two
  // DIFFERENT names, and the losing name must not simply vanish.
  var svcClash = exam.findings.filter(function (f) { return f.kind === 'container-name-clash' && f.facts.field === 'service'; });
  if (!svcClash.length) {
    fail(1, 'order ' + o.label + ': examine() raised no service-name clash for "app"');
  } else {
    var siteAppName = exam.plan.serviceRenames[storeNameFor('t169-site') + '/app'] || 'app';
    var apiAppName = exam.plan.serviceRenames[storeNameFor('t169-api') + '/app'] || 'app';
    if (siteAppName === apiAppName) fail(1, 'order ' + o.label + ': both clashing "app" services ended up with the same final name "' + siteAppName + '"');
    [siteAppName, apiAppName].forEach(function (name) {
      if (!new RegExp('^\\s{2}' + name + ':\\s*$', 'm').test(text)) {
        fail(1, 'order ' + o.label + ': service "' + name + '" (one side of the "app" clash) is missing from the merged file');
      }
    });
  }

  // Trap 2 — same container_name (t169-data) on t169-store and t169-tools's "idle" (never
  // started by install.sh — a real Docker host can only hold one "t169-data" at a time).
  var dataCount = (text.match(/container_name:\s*t169-data\b/mg) || []).length;
  if (dataCount !== 1) fail(2, 'order ' + o.label + ': expected exactly one surviving "container_name: t169-data", found ' + dataCount);
  var cnClash = exam.findings.filter(function (f) { return f.kind === 'container-name-clash' && f.facts.field === 'container_name'; });
  if (!cnClash.length) fail(2, 'order ' + o.label + ': examine() raised no container_name clash for "t169-data"');

  // Trap 3 — t169-site and t169-api both name their router
  // "${COMPOSE_PROJECT_NAME}-web", which Traefik reads box-wide, so it is distinct per stack
  // (t169-site-web, t169-api-web) before the merge and becomes ONE name the moment both land
  // in one project. This is a label VALUE, a shape examine.js has never been asked to
  // understand (no "label" finding kind exists at all) — expected to fail until that is
  // built. Real merge gap, not a fixture mistake: flagging it here is the point of this trap.
  var webRouterCount = text.split('traefik.http.routers.${COMPOSE_PROJECT_NAME}-web.rule=').length - 1;
  if (webRouterCount > 1) fail(3, 'order ' + o.label + ': two "${COMPOSE_PROJECT_NAME}-web" router rules survive in one project unresolved (' + webRouterCount + ') — the merge itself created this clash by putting both stacks under one project name');

  // Trap 4 — the real published-port clash (t169-bus's broker and t169-tools's "idle", both on
  // 19090 — "idle" is never started by install.sh, exactly the shape a person has when they
  // stopped a service because it clashed, but its own file still declares the port). One must
  // move; examine() must have raised it, and the survivor's own recorded web address must
  // match whichever port it actually kept.
  var portClash = exam.findings.filter(function (f) { return f.kind === 'port-clash'; });
  if (!portClash.length) {
    fail(4, 'order ' + o.label + ': examine() raised no port-clash for 19090');
  } else {
    var moved = portClash[0].facts.freePort;
    if (text.indexOf(moved + ':80') === -1 && text.indexOf(moved + ':9090') === -1) {
      fail(4, 'order ' + o.label + ': neither service actually landed on the recommended free port ' + moved);
    }
    var webuiPorts = (text.match(/webui:\s*"?http:\/\/\[IP\]:(\d+)\/?"?/g) || []);
    if (!webuiPorts.some(function (m) { return m.indexOf(':' + moved + '/') >= 0; })) {
      fail(4, 'order ' + o.label + ': no x-unraid.webui address was rewritten to the moved port ' + moved);
    }
  }

  // Trap 5 — two different top-level "x-common: &common" blocks (t169-bus, t169-tools). Every
  // alias must still resolve to an anchor ABOVE it once the merge is done reordering anything.
  var anchorLines = {}, missingAnchor = false, aliasBeforeAnchor = false;
  doc.lines.forEach(function (ln, i) {
    var am = /&([A-Za-z0-9_-]+)\b/.exec(ln);
    if (am && !(am[1] in anchorLines)) anchorLines[am[1]] = i;
    var m = /\*([A-Za-z0-9_-]+)\b/.exec(ln);
    if (m) {
      if (!(m[1] in anchorLines)) missingAnchor = true;
      else if (i < anchorLines[m[1]]) aliasBeforeAnchor = true;
    }
  });
  if (missingAnchor) fail(5, 'order ' + o.label + ': a *alias survives with no matching &anchor anywhere in the merged file');
  if (aliasBeforeAnchor) fail(5, 'order ' + o.label + ': a *alias appears before its &anchor');
  var commonCount = (text.match(/^x-common:\s*&common\b/mg) || []).length;
  if (commonCount > 1) fail(5, 'order ' + o.label + ': two "x-common: &common" blocks both survive under the same name (' + commonCount + ') — a real merge gap: examine() has no finding kind for a top-level x- clash');

  // Trap 6 — the external network (t169-edge, t169-site, t169-api) must survive unrenamed and
  // still external, as exactly one declaration.
  var netCount = (text.match(/^\s*t169-edge-net:\s*$/mg) || []).length;
  if (netCount !== 1) fail(6, 'order ' + o.label + ': expected exactly one "t169-edge-net:" network, found ' + netCount);
  if (!/t169-edge-net:\s*\n\s*external:\s*true/.test(text)) fail(6, 'order ' + o.label + ': "t169-edge-net" no longer reads external: true');
  if (text.indexOf('t169-all_t169-edge-net') !== -1 || text.indexOf('T169-MERGED_t169-edge-net') !== -1) {
    fail(6, 'order ' + o.label + ': the external network was renamed with the new stack\'s prefix');
  }

  // Trap 7 — the one-shot "seed" must never be offered a healthcheck by merge-suggest, and
  // its restart: "no" must survive unchanged.
  if (!/restart:\s*"no"/.test(text)) fail(7, 'order ' + o.label + ': "restart: \\"no\\"" did not survive on the one-shot seed service');
  var seedBlockMatch = /\n\s{2}seed:\n([\s\S]*?)(?=\n\s{2}\S|\n\S|$)/.exec(text);
  if (seedBlockMatch && /healthcheck:/.test(seedBlockMatch[1])) fail(7, 'order ' + o.label + ': the one-shot "seed" service was given a healthcheck');

  // Trap 8 — PGPASSWORD set to a different value by t169-api's own api.env and
  // t169-store's own db.env. PLAN_169 F10: verified before "fixing" anything — each
  // service reads its OWN env_file (api.env for "rest", db.env for "db"), so the two
  // values never actually meet; there is no root .env in this fixture for either
  // source, so findSettingsJoin() (which only ever reads a root .env) has nothing to
  // do here regardless. The real hazard this trap is built to catch would be the two
  // companion FILES colliding once both land in one folder — a file-clash finding, or
  // the merged env_file: lines both ending up naming the same copy — neither of which
  // happens, since "api.env" and "db.env" are already distinct names.
  if (/^\s*PGPASSWORD:/m.test(text)) fail(8, 'order ' + o.label + ': PGPASSWORD was inlined into the merged compose text as an environment: entry — it belongs only in each service\'s own env_file companion');
  var envFileClash = exam.findings.some(function (f) { return f.kind === 'file-clash' && (f.facts.path === 'api.env' || f.facts.path === 'db.env'); });
  if (envFileClash) fail(8, 'order ' + o.label + ': "api.env"/"db.env" were wrongly treated as a file-clash — they are already distinct names, nothing to rename');
  if (!/env_file:\s*\n(?:\s*#[^\n]*\n)*\s*-\s*\.\/api\.env/.test(text)) fail(8, 'order ' + o.label + ': t169-api\'s own "rest" service no longer points its env_file at "./api.env"');
  if (!/env_file:\s*\n(?:\s*#[^\n]*\n)*\s*-\s*\.\/db\.env/.test(text)) fail(8, 'order ' + o.label + ': t169-store\'s own "db" service no longer points its env_file at "./db.env"');

  // Trap 9 — the pre-existing depends_on with a condition (t169-api's own "app" companion),
  // which must be rewired if "app" changed identity in the merge.
  var apiRenamed = exam.findings.filter(function (f) {
    return f.kind === 'container-name-clash' && f.facts.field === 'service' && f.stack === storeNameFor('t169-api');
  })[0];
  // Comments can sit between "depends_on:" and its own child key, so this looks for the
  // target key anywhere in the few lines that follow rather than demanding an exact substring.
  function dependsOnTargets(name) {
    var re = new RegExp('depends_on:\\n(?:\\s*#[^\\n]*\\n)*\\s*' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ':\\n\\s*condition: service_healthy');
    return re.test(text);
  }
  if (apiRenamed) {
    if (!dependsOnTargets(apiRenamed.facts.to)) {
      fail(9, 'order ' + o.label + ': t169-api\'s own depends_on was not rewired from "app" to "' + apiRenamed.facts.to + '"');
    }
  } else {
    // t169-site's "app" was the one renamed instead — t169-api keeps its own "app" unchanged,
    // and the pre-existing depends_on must still read "app" (no rewrite needed).
    if (!dependsOnTargets('app')) {
      fail(9, 'order ' + o.label + ': t169-api\'s own depends_on on "app" did not survive');
    }
  }

  // Trap 10 — long-syntax volumes (t169-store) and a read_only bind must both survive.
  if (!/type:\s*bind[\s\S]{0,80}read_only:\s*true/.test(text)) fail(10, 'order ' + o.label + ': the long-syntax read_only bind did not survive');
  if (!/type:\s*volume[\s\S]{0,60}source:\s*data/.test(text)) fail(10, 'order ' + o.label + ': the long-syntax named-volume mount did not survive');

  // Trap 10 (PLAN_169 F18) — t169-store and t169-bus both call their own volume "data";
  // t169-store's db mounts it LONG-syntax, t169-bus's mqtt mounts it SHORT-syntax. Before the
  // fix, a long-syntax mount was invisible to the examiner: t169-store's own declaration read
  // as unused, was never carried and never clash-renamed, and one pick order left db pointed
  // at t169-bus's volume outright. Both real names must appear, each under its own merged key,
  // and each service's own mount (long or short) must follow that same key.
  function keyForRealName(realName) {
    var idx = text.indexOf('name: ' + realName);
    if (idx === -1) return null;
    var before = text.slice(0, idx).split('\n');
    for (var i = before.length - 1; i >= 0; i--) {
      var km = /^  ([A-Za-z0-9_.-]+):\s*$/.exec(before[i]);
      if (km) return km[1];
    }
    return null;
  }
  var storeVolKey = keyForRealName('t169-store_data');
  var busVolKey = keyForRealName('t169-bus_data');
  if (!storeVolKey) fail(10, 'order ' + o.label + ': no declared volume carries "name: t169-store_data" — t169-store\'s own long-syntax mount was never carried');
  if (!busVolKey) fail(10, 'order ' + o.label + ': no declared volume carries "name: t169-bus_data" — t169-bus\'s own short-syntax mount was never carried');
  if (storeVolKey && busVolKey && storeVolKey === busVolKey) fail(10, 'order ' + o.label + ': t169-store and t169-bus\'s own "data" volumes were merged under the SAME key (' + storeVolKey + ') — one would start on the other\'s storage');
  if (storeVolKey && !new RegExp('source:\\s*' + storeVolKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b').test(text)) {
    fail(10, 'order ' + o.label + ': t169-store\'s own db service no longer mounts its own volume key "' + storeVolKey + '" long-syntax');
  }
  if (busVolKey && text.indexOf('- ' + busVolKey + ':/mosquitto/data') === -1) {
    fail(10, 'order ' + o.label + ': t169-bus\'s own mqtt service no longer mounts its own volume key "' + busVolKey + '" short-syntax');
  }

  // Trap 11 — top-level configs: (t169-bus).
  if (!/^configs:\s*$/m.test(text) || text.indexOf('bus_banner') === -1) fail(11, 'order ' + o.label + ': the top-level "configs:" block (bus_banner) did not survive');

  // Trap 12 — 127.0.0.2:19800 (site) and 127.0.0.3:19800 (tools) are NOT a clash and must
  // both survive untouched.
  if (text.indexOf('127.0.0.2:19800:80') === -1) fail(12, 'order ' + o.label + ': the site\'s own 127.0.0.2:19800 debug port did not survive unchanged');
  if (text.indexOf('127.0.0.3:19800:8080') === -1) fail(12, 'order ' + o.label + ': Dozzle\'s own 127.0.0.3:19800 debug port did not survive unchanged');
  var falsePortClash = exam.findings.some(function (f) { return f.kind === 'port-clash' && String(f.facts.port) === '19800'; });
  if (falsePortClash) fail(12, 'order ' + o.label + ': examine() wrongly raised a port-clash for 19800, which is on two different addresses');

  // Trap 13 — t169-site's heavily commented file: the comment claiming uniqueness of the
  // "web" router must be struck, not carried unchanged, once a second "web" router lands in
  // the same file (rule 2: a falsified comment is struck, never silently kept).
  var uniquenessCommentSurvives = text.indexOf('called "${COMPOSE_PROJECT_NAME}-web".') !== -1;
  if (uniquenessCommentSurvives && webRouterCount > 1) {
    fail(13, 'order ' + o.label + ': the falsified "only router... called ${COMPOSE_PROJECT_NAME}-web" comment was carried unchanged rather than struck');
  }
  // Every other comment in t169-site's file should still be present somewhere (nothing here
  // was touched by the merge except the one line above).
  ['kept off the box\'s shared address so it never collides', 'Every stack in this fixture keeps its own timezone'].forEach(function (snippet) {
    if (text.indexOf(snippet) === -1) fail(13, 'order ' + o.label + ': an untouched comment from t169-site did not survive: "' + snippet + '"');
  });

  // Trap 14 — t169-api's PGRST_DB_URI (postgres://authenticator:t169pass@__BOX_IP__:19432/t169)
  // is a whole database URI in one env var, not a bare "host:port"; the address-rewire fix
  // (F12, 2026-09-24) must move only the host:port inside it onto the store's own service name
  // and its real port, keeping the login, scheme and database name exactly as the author wrote
  // them (CLAUDE.md rule 2).
  var storeName = exam.plan.serviceRenames[storeNameFor('t169-store') + '/db'] || 'db';
  var wantUri = 'postgres://authenticator:t169pass@' + storeName + ':5432/t169';
  if (text.indexOf('PGRST_DB_URI: ' + wantUri) === -1) {
    fail(14, 'order ' + o.label + ': PGRST_DB_URI did not come out as "' + wantUri + '"');
  }

  // F13 — one label-clash finding per service per clashing name, covering every namespace it
  // clashed in, never one identical-looking card per namespace. This fixture's own
  // "${COMPOSE_PROJECT_NAME}-web" clash (trap 3) is the regression guard: t169-api's "rest"
  // must get exactly one finding, not one per namespace the name happens to be used in.
  var webClashes = exam.findings.filter(function (f) { return f.kind === 'label-clash' && f.facts.from === '${COMPOSE_PROJECT_NAME}-web'; });
  var webClashCounts = {};
  webClashes.forEach(function (f) { webClashCounts[f.stack + '|' + f.facts.service] = (webClashCounts[f.stack + '|' + f.facts.service] || 0) + 1; });
  Object.keys(webClashCounts).forEach(function (k) {
    if (webClashCounts[k] !== 1) {
      fail('F13', 'order ' + o.label + ': ' + k + ' got ' + webClashCounts[k] + ' label-clash findings for "${COMPOSE_PROJECT_NAME}-web", not one');
    }
  });

  // F15/F2 — stripCommentAbove() strikes the WHOLE contiguous comment run directly above a
  // rewritten line, not just the one line touching it, but (PLAN_178 F2) only when that run
  // actually names the value being replaced. t169-api's own PGRST_DB_URI carries a three-line
  // comment above it, and none of it names the old address, host or port — "the address below
  // is filled in by install.sh" and "a merge... must rewrite this to a service name instead"
  // are both still true after the rewrite — so, unlike before F2, this one now SURVIVES.
  ['The address below is filled in by install.sh',
   'A merge that reaches this database over a shared network must rewrite this to a',
   'service name instead.'].forEach(function (snippet) {
    if (text.indexOf(snippet) === -1) {
      fail('F15', 'order ' + o.label + ': the true comment above PGRST_DB_URI was struck though it names no value: "' + snippet + '"');
    }
  });

  // General sanity, same shape as the first walk's own probe.
  if (/^version:/m.test(text)) fail('gen', 'order ' + o.label + ': a "version:" line survived into the merged file');
  if (!built.changes.length) fail('gen', 'order ' + o.label + ': no change records were produced at all — a rewritten line with no mark');
});

if (checkFails.length) {
  console.log('\nFAILED:');
  checkFails.forEach(function (f) { console.log('  trap ' + f.trap + ': ' + f.message); });
  process.exit(1);
}
console.log('\nall checks passed');
