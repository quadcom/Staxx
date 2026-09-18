/* StaXX — the dry run for PLAN_156's four-stack merge walkthrough.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_walk_dryrun.js [outDir] [--check]
 *
 * A probe, not a suite: it drives merge-examine.js/merge-write.js/merge-
 * suggest.js against the fixtures under tests/fixtures/merge-walk/ the way
 * the wizard's own client-side code would, entirely on the dev machine,
 * before anything touches the box. It prints what it found so a person can
 * read it; with --check it also asserts the phase 4 shape and exits
 * non-zero on the first miss (see PLAN_156, "Phase 0a").
 *
 * PLAN_155 C7: every source is named exactly as the wizard names it — its
 * path under the store ("DEV-TESTING/t155-db"), not its bare folder name —
 * because that is what exposed the bug: a source identity containing "/"
 * broke a rename map that was built by string concatenation and taken apart
 * with split('/'). The run happens TWICE, in two pick orders, because the
 * live fault depended on which source got to a shared storage name first.
 *
 * It asserts nothing by default (beyond the ORDER A/B storage-integrity
 * check, which is cheap and always worth running) — its real value is in
 * what it prints; --check adds the PLAN_156 phase 4 assertions.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var ME = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-examine.js');
var MW = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-write.js');
var MS = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-suggest.js');

var FIXTURES = path.join(__dirname, 'fixtures', 'merge-walk');

var argv = process.argv.slice(2);
var CHECK = argv.indexOf('--check') >= 0;
var outArg = argv.filter(function (a) { return a !== '--check'; })[0];
var OUT_DIR = outArg ? path.resolve(outArg) : path.join(FIXTURES, '.dryrun');

var checkFails = [];   // {trap, message} — printed and turned into the exit code under --check

function section(title) {
  console.log('\n' + title);
}

function readText(leafName, rel) {
  var p = path.join(FIXTURES, leafName, rel);
  return fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : null;
}

function keyLike(p) {
  return /\.(pem|key|crt|p12|pfx)$/i.test(p) || /(^|\/)(secrets|certs)\//i.test(p);
}

// The companion-file listing merge-files would return, shaped exactly like
// staxx_merge_files()'s own reply — {path, size, mode, dir, link, target,
// outside, keyLike, referenced} — keyed by each stack's own LEAF, since
// that is what names its folder on disk. box-generated items (certs, blob,
// symlink, icons) are represented by name/size only, since their real bytes
// exist only after install.sh has run on the server.
var STACKS = {
  't155-web': {
    files: [
      { path: 'README.md', size: fs.statSync(path.join(FIXTURES, 't155-web/README.md')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false },
      { path: 'conf/default.conf', size: fs.statSync(path.join(FIXTURES, 't155-web/conf/default.conf')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: true },
      { path: 'certs/server.crt', size: 1200, dir: false, link: false, target: '', outside: false, keyLike: keyLike('certs/server.crt'), referenced: true },
      { path: 'certs/server.key', size: 1704, dir: false, link: false, target: '', outside: false, keyLike: keyLike('certs/server.key'), referenced: true },
      { path: 'php/Dockerfile', size: fs.statSync(path.join(FIXTURES, 't155-web/php/Dockerfile')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: true },
      { path: 'site', size: 0, dir: true, link: false, target: '', outside: false, keyLike: false, referenced: true },
      { path: 'site/index.php', size: fs.statSync(path.join(FIXTURES, 't155-web/site/index.php')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false },
      { path: 'data', size: 0, dir: true, link: false, target: '', outside: false, keyLike: false, referenced: true },
      { path: 'data/blob.bin', size: 12582912, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false },
      { path: 'secrets/db_password.txt', size: fs.statSync(path.join(FIXTURES, 't155-web/secrets/db_password.txt')).size, dir: false, link: false, target: '', outside: false, keyLike: keyLike('secrets/db_password.txt'), referenced: true },
      { path: 'conf/current.conf', size: 0, dir: false, link: true, target: 'default.conf', outside: false, keyLike: false, referenced: false },
      { path: '.staxx/icon.png', size: 512, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false }
    ],
    large: { path: 'data' }
  },
  't155-db': {
    files: [
      { path: 'compose.yaml.bak', size: fs.statSync(path.join(FIXTURES, 't155-db/compose.yaml.bak')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false },
      { path: 'db.env', size: fs.statSync(path.join(FIXTURES, 't155-db/db.env')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: true },
      { path: 'README.md', size: fs.statSync(path.join(FIXTURES, 't155-db/README.md')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false },
      { path: 'init', size: 0, dir: true, link: false, target: '', outside: false, keyLike: false, referenced: true },
      { path: 'init/01-schema.sql', size: fs.statSync(path.join(FIXTURES, 't155-db/init/01-schema.sql')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false },
      { path: 'secrets/db_password.txt', size: fs.statSync(path.join(FIXTURES, 't155-db/secrets/db_password.txt')).size, dir: false, link: false, target: '', outside: false, keyLike: keyLike('secrets/db_password.txt'), referenced: true },
      { path: 'secrets/root_password.txt', size: fs.statSync(path.join(FIXTURES, 't155-db/secrets/root_password.txt')).size, dir: false, link: false, target: '', outside: false, keyLike: keyLike('secrets/root_password.txt'), referenced: true },
      { path: '.staxx/icon.png', size: 512, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false }
    ],
    large: null
  },
  't155-cache': {
    files: [
      { path: 'redis.conf', size: fs.statSync(path.join(FIXTURES, 't155-cache/redis.conf')).size, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: true },
      { path: '.staxx/icon.png', size: 512, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false }
    ],
    large: null
  },
  't155-admin': {
    files: [
      { path: '.staxx/icon.png', size: 512, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: false }
    ],
    large: null
  }
};

var OVERRIDE_NAMES = [
  'compose.override.yml', 'compose.override.yaml',
  'docker-compose.override.yml', 'docker-compose.override.yaml'
];
function findOverrideName(leafName) {
  for (var i = 0; i < OVERRIDE_NAMES.length; i++) {
    if (fs.existsSync(path.join(FIXTURES, leafName, OVERRIDE_NAMES[i]))) return OVERRIDE_NAMES[i];
  }
  return null;
}

// PLAN_155 C7: every fixture stack was installed a folder deep (install.sh),
// so its identity everywhere — the `name` handed to descriptorFromText(),
// AND `rel` — is "DEV-TESTING/<leaf>", exactly as mergeSourcesForBuild() in
// stacks.js passes `rel: p.name`. Using anything else here (the bare leaf,
// as this probe used to) hides exactly the bug this run exists to catch:
// a source identity built by string concatenation elsewhere and taken apart
// with split('/') silently breaks the moment that identity already holds a
// "/", which a store path always does once a stack sits inside a folder.
function storeNameFor(leafName) { return 'DEV-TESTING/' + leafName; }

// Runs the whole walkthrough — reading, examine(), buildMergedText(),
// merge-suggest.apply(), retireText() — for one pick order, printing as it
// goes. Returns what --check needs to assert against.
function runWalk(label, LEAVES) {
  section('=== ORDER ' + label + ' (' + LEAVES.join(', ') + ') ===');

  section('0. Reading the fixtures — which text each descriptor gets');
  var descs = LEAVES.map(function (leafName) {
    var text = readText(leafName, 'compose.yaml') || readText(leafName, 'docker-compose.yml');
    var envText = readText(leafName, '.env');
    var reply = STACKS[leafName];
    var storeName = storeNameFor(leafName);
    var extra = { filesLarge: reply.large, depth: 1, rel: storeName };
    var overrideName = findOverrideName(leafName);
    if (overrideName) extra.overrideText = readText(leafName, overrideName);
    return MW.descriptorFromText(storeName, text, envText, reply.files, extra);
  });

  section('0c. C3/C5 — each source\'s override, applied onto its own main file');
  LEAVES.forEach(function (leafName, i) {
    var changes = descs[i].overrideChanges || [];
    console.log('  ' + leafName + ':');
    if (!changes.length) { console.log('    (no override changes recorded)'); return; }
    changes.forEach(function (c) {
      console.log('    line ' + c.sourceLine + '  ' + c.marker.trim() + '  — ' + c.title + ': ' + c.reason);
    });
  });

  section('1. examine() — every finding');
  var exam = ME.examine(descs, { date: '2026-09-15', newDepth: 1, newRel: 'T155-MERGED/t155-site' });
  var byKind = {};
  exam.findings.forEach(function (f) {
    byKind[f.kind] = (byKind[f.kind] || 0) + 1;
    var target = f.facts.path || f.facts.service || f.facts.volume || f.facts.declKind || f.facts.port ||
      f.facts.category || f.facts.field || '';
    var title = f.kind + (f.facts.from ? (' ' + f.facts.from + '->' + f.facts.to) : '');
    var reason = f.facts.note || f.facts.oldPath || f.facts.envVar || '';
    console.log('  ' + f.kind + '  stack=' + (f.stack || '-') + '  target=' + target +
      '  title="' + title + '"  reason="' + reason + '"');
  });
  section('   counts by kind');
  Object.keys(byKind).sort().forEach(function (k) { console.log('  ' + k + ': ' + byKind[k]); });

  section('2. buildMergedText() — phase 3\'s answers');

  function findingsOfKind(kind) { return exam.findings.filter(function (f) { return f.kind === kind; }); }
  function keyFor(kind, matchFacts) {
    var f = findingsOfKind(kind).filter(function (x) {
      return Object.keys(matchFacts).every(function (k) { return x.facts[k] === matchFacts[k]; });
    })[0];
    return f ? f.key : null;
  }

  var decisions = {};

  var readmeKey = keyFor('file-clash', { path: 'README.md' });
  if (readmeKey) decisions[readmeKey] = 'rename';
  var secretKey = keyFor('file-clash', { path: 'secrets/db_password.txt' });
  if (secretKey) decisions[secretKey] = 'keep-one';
  var overrideClashKey = keyFor('file-clash', { path: 'docker-compose.override.yml' });
  if (overrideClashKey) decisions[overrideClashKey] = 'leave-behind';

  exam.findings.filter(function (f) { return f.kind === 'unreferenced'; }).forEach(function (f) {
    if (f.facts.path === 'compose.yaml.bak') decisions[f.key] = 'leave-behind';
  });

  // The free port examine() already suggested (PLAN_155 C10) — same number
  // pickFreePort() would hand the wizard, since this decision merely
  // confirms the finding's own recommended choice rather than picking one
  // by hand.
  var portClash = findingsOfKind('port-clash')[0];
  if (portClash) decisions[portClash.key] = portClash.facts.freePort;

  var join = exam.findings.filter(function (f) { return f.kind === 'settings-join'; })[0];
  var envNames = {};
  if (join) {
    join.facts.renamed.forEach(function (r, i) {
      var changeKey = join.key + '|rename|' + i;
      decisions[changeKey] = 'choose-name';
      envNames[changeKey] = (r.from + '_' + r.stack).toUpperCase().replace(/[^A-Z0-9_]/g, '_');
    });
  }

  var sources = LEAVES.map(function (leafName, i) {
    var desc = descs[i];
    var storeName = storeNameFor(leafName);
    return {
      name: storeName, text: desc.text, envText: readText(leafName, '.env'),
      depth: 1, rel: storeName, overrideChanges: desc.overrideChanges
    };
  });
  var filesReplies = {};
  LEAVES.forEach(function (leafName) {
    filesReplies[storeNameFor(leafName)] = { files: STACKS[leafName].files, large: STACKS[leafName].large };
  });

  var built = MW.buildMergedText(sources, {
    name: 'T155-MERGED/t155-site', date: '2026-09-15',
    decisions: decisions, envNames: envNames, files: filesReplies
  });

  if (built.refusals && built.refusals.length) {
    console.log('  REFUSALS (nothing written past these):');
    built.refusals.forEach(function (f) { console.log('    ' + f.kind + ' — stack=' + f.stack); });
  }

  section('3. merge-suggest.apply() — step 5\'s answers');

  var svcRename = exam.findings.filter(function (f) {
    return f.kind === 'container-name-clash' && f.facts.field === 'service' && f.stack === storeNameFor('t155-admin');
  })[0];
  var adminSvc = svcRename ? svcRename.facts.to : 'web_t155-admin';

  var suggestAnswers = {
    deps: [
      { from: 'php', to: 'db' },
      { from: 'php', to: 'cache' },
      { from: adminSvc, to: 'db' }
    ],
    health: {
      web: { on: true, source: 'own', test: ['CMD', 'curl', '-f', 'http://localhost/'], interval: '10s', timeout: '5s', retries: 3 },
      cache: { on: true, source: 'own', test: ['CMD', 'redis-cli', 'ping'], interval: '10s', timeout: '5s', retries: 3 },
      db: { covered: true }
    },
    update: { mode: 'default' },
    notify: { touched: false }
  };

  var suggested = MS.apply(built.text, suggestAnswers);

  console.log('  changes (' + built.changes.length + '):');
  built.changes.forEach(function (c) {
    console.log('    ' + (c.file || 'compose') + ' key=' + c.key + ' title="' + c.title + '"' + (c.removed ? ' (removed)' : '') +
      (c.struckComment ? '  struckComment="' + c.struckComment + '"' : ''));
  });

  console.log('  files (' + built.files.length + '):');
  built.files.forEach(function (f) {
    console.log('    ' + f.from + '/' + f.path + ' -> ' + (f.to === null ? '(left behind)' : f.to));
  });

  console.log('\n  --- merged compose text (order ' + label + ') ---\n');
  console.log(suggested.text);
  console.log('\n  --- joined .env ---\n');
  console.log(built.env);

  section('4. retireText() — each source');
  sources.forEach(function (s) {
    var r = MW.retireText(s.text, 'T155-MERGED/t155-site', '2026-09-15');
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

var ORDER_A = ['t155-web', 't155-db', 't155-cache', 't155-admin'];
var ORDER_B = ['t155-admin', 't155-cache', 't155-db', 't155-web'];

var resultA = runWalk('A', ORDER_A);
var resultB = runWalk('B', ORDER_B);

function fail(trap, message) { checkFails.push({ trap: trap, message: message }); }

// PLAN_155 C7's own assertion, run against BOTH orders: every "- <key>:/…"
// mount under a service must name a key actually declared under the merged
// file's own volumes: block, and every carried volume's declaration line
// AND every rewritten mount line must carry a change record — a rename with
// no mark is exactly rule 2's silent-change failure, and a mount left
// pointing at the wrong storage is the data-safety bug itself.
function checkStorageIntegrity(label, text, built, exam) {
  var doc = CM.parse(text);
  var svcBlock = doc.root && doc.root.kind === 'map' ? doc.root.pairs['services'] : null;
  var svcMap = svcBlock && svcBlock.value && svcBlock.value.kind === 'map' ? svcBlock.value : null;
  var volBlock = doc.root && doc.root.kind === 'map' ? doc.root.pairs['volumes'] : null;
  var volMap = volBlock && volBlock.value && volBlock.value.kind === 'map' ? volBlock.value : null;
  var declaredKeys = volMap ? volMap.keys : [];

  if (svcMap) {
    svcMap.keys.forEach(function (svcName) {
      var p = svcMap.pairs[svcName];
      for (var i = p.start; i < p.end; i++) {
        // A named volume's shorthand mount ("data:/var/lib/mysql") always
        // starts with a bare name character; a bind mount's path always
        // starts with ".", "/", "~" or "$" instead, so this pattern never
        // mistakes one for the other.
        var m = /^\s*-\s*['"]?([A-Za-z0-9][\w.-]*):\//.exec(doc.lines[i]);
        if (!m) continue;
        if (declaredKeys.indexOf(m[1]) === -1) {
          fail('C7', 'order ' + label + ': service "' + svcName + '" mounts undeclared volume key "' +
            m[1] + '" (line ' + i + ': ' + doc.lines[i].trim() + ')');
        }
      }
    });
  }

  exam.findings.forEach(function (f) {
    if (f.kind !== 'storage-carry' || f.facts.mergedKey === f.facts.volume) return;
    var expected = (f.lines || []).length;
    var got = built.changes.filter(function (c) { return c.key === f.key; }).length;
    if (got !== expected) {
      fail('C7', 'order ' + label + ': storage-carry ' + f.stack + '/' + f.facts.volume +
        ' expected ' + expected + ' change record(s) (declaration + every mount), found ' + got);
    }
  });
}

checkStorageIntegrity('A', resultA.suggested.text, resultA.built, resultA.exam);
checkStorageIntegrity('B', resultB.suggested.text, resultB.built, resultB.exam);

/* =========================================================================
 * F7 (PLAN_156 second walk) — every change record buildMergedText() hands
 * back has to actually paint a mark somewhere a person can find it: either
 * the merged pane (its own `.line`) or its own source's pane (`.stack` +
 * `.sourceLine`) — the same two places, resolved the same way (a plain
 * array index, never a re-search for the marker TEXT — `.marker` is gone
 * by the time a caller sees these records at all) that stacks.js's own
 * mergePaintCode()/mergeChangesByMergedLine()/mergeChangesBySourceLine()
 * draw a mark from. A record neither resolves is a mark nobody will ever
 * see; two DIFFERENT records winning the exact same slot is one of them
 * invisible behind the other, since `map[i] = c` is a plain overwrite — a
 * ghost row (`removed: true`) is the one exception, since several of those
 * can share one insertion point on purpose. Live evidence this once caught
 * (2026-09-15): a heading reading "16 changes" against 15 visible marks,
 * the missing one always a storage-carry's own "Still <leaf>'s own storage"
 * declaration line.
 * ========================================================================= */

function checkEveryChangePaints(label, r) {
  var built = r.built;
  var mergedLines = (built.text || '').split('\n');
  var sourceTexts = {};
  r.sources.forEach(function (s) { sourceTexts[s.name] = (s.text || '').split('\n'); });

  // Env-file changes have their own separate pane (step 4) with its own
  // separate heading — never part of this count, so never part of this check.
  var compose = (built.changes || []).filter(function (c) { return c.file !== 'env'; });

  var mergedWinner = {}, sourceWinner = {};
  compose.forEach(function (c) {
    if (!c.removed && typeof c.line === 'number') mergedWinner[c.line] = c;
    if (typeof c.sourceLine === 'number') sourceWinner[c.stack + '|' + c.sourceLine] = c;
  });

  compose.forEach(function (c) {
    var mergedOk = c.removed
      ? (typeof c.line === 'number' && c.line >= 0 && c.line <= mergedLines.length)
      : (typeof c.line === 'number' && c.line >= 0 && c.line < mergedLines.length && mergedWinner[c.line] === c);
    var srcLines = sourceTexts[c.stack];
    var sourceOk = !!srcLines && typeof c.sourceLine === 'number' && c.sourceLine >= 0 && c.sourceLine < srcLines.length &&
      sourceWinner[c.stack + '|' + c.sourceLine] === c;

    if (!mergedOk && !sourceOk) {
      fail('F7', 'order ' + label + ': ' + c.key + ' (part=' + c.part + ', "' + c.title + '", stack=' + (c.stack || '-') +
        ') resolves to no line at all — line=' + c.line + ' sourceLine=' + c.sourceLine);
    }
  });

  // A collision: two DIFFERENT records both claiming the same slot means
  // one of them is invisible there, whichever way the map's last write
  // fell — reported once per slot, naming every record that shares it.
  [{ map: (function () {
      var byLine = {};
      compose.forEach(function (c) { if (!c.removed && typeof c.line === 'number') (byLine[c.line] = byLine[c.line] || []).push(c); });
      return byLine;
    })(), where: 'merged-pane line' },
   { map: (function () {
      var byKey = {};
      compose.forEach(function (c) { if (typeof c.sourceLine === 'number') { var k = c.stack + '|' + c.sourceLine; (byKey[k] = byKey[k] || []).push(c); } });
      return byKey;
    })(), where: 'source line' }
  ].forEach(function (group) {
    Object.keys(group.map).forEach(function (slot) {
      var claimants = group.map[slot];
      if (claimants.length > 1) {
        fail('F7', 'order ' + label + ': ' + group.where + ' ' + slot + ' is claimed by ' + claimants.length +
          ' change records (' + claimants.map(function (c) { return c.key; }).join(', ') + ') — only one can show');
      }
    });
  });
}

checkEveryChangePaints('A', resultA);
checkEveryChangePaints('B', resultB);

/* =========================================================================
 * --check: the phase 4 shape, from the merged compose text alone (what a
 * real walk would also check on disk — the file itself, plus the change
 * records this run produced). Run against BOTH orders — the point of this
 * probe is that neither may disagree with the other.
 * =========================================================================
 */

// PLAN_155 C15 — a rewired connection must land on a shared network: for
// every address-rewire finding and every depends_on pair actually written
// into the merged file, the two services' network sets must intersect.
// Read from the merged text itself (via MW.serviceNetworkInfo(), the same
// reader buildMergedText()'s own join pass uses) rather than re-deriving
// networks by hand, so this fails the moment the two disagree about what
// "shares a network" means. network_mode on either side is skipped, not
// failed — it shares another service's whole network stack (or the
// host's), which is not a question of network NAMES at all.
function checkNetworksShared(label, doc, exam) {
  var svcBlock = doc.root && doc.root.kind === 'map' ? doc.root.pairs['services'] : null;
  var svcMap = svcBlock && svcBlock.value && svcBlock.value.kind === 'map' ? svcBlock.value : null;
  if (!svcMap) return;

  function checkPair(aName, bName, why) {
    if (!svcMap.pairs[aName] || !svcMap.pairs[bName]) return;
    var aInfo = MW.serviceNetworkInfo(doc, aName);
    var bInfo = MW.serviceNetworkInfo(doc, bName);
    if (aInfo.networkMode || bInfo.networkMode) return;
    var aSet = aInfo.networks.length ? aInfo.networks : ['default'];
    var bSet = bInfo.networks.length ? bInfo.networks : ['default'];
    if (!aSet.some(function (n) { return bSet.indexOf(n) >= 0; })) {
      fail('C15', 'order ' + label + ': ' + why + ' (' + aName + ' -> ' + bName + ') shares no network — ' +
        aName + ' is on [' + aSet.join(', ') + '], ' + bName + ' on [' + bSet.join(', ') + ']');
    }
  }

  svcMap.keys.forEach(function (svcName) {
    var p = svcMap.pairs[svcName];
    var dep = p.value && p.value.kind === 'map' ? p.value.pairs['depends_on'] : null;
    if (!dep || !dep.value) return;
    var deps = dep.value.kind === 'map' ? (dep.value.keys || [])
             : dep.value.kind === 'seq' ? dep.value.items.map(function (it) {
                 return (it.value && it.value.kind === 'scalar') ? String(it.value.value) : null;
               }).filter(Boolean)
             : [];
    deps.forEach(function (depName) { checkPair(svcName, depName, 'depends_on'); });
  });

  exam.findings.forEach(function (f) {
    if (f.kind !== 'address-rewire') return;
    var aName = exam.plan.serviceRenames[f.stack + '/' + f.facts.service] || f.facts.service;
    var bName = exam.plan.serviceRenames[f.facts.toStack + '/' + f.facts.toService] || f.facts.toService;
    checkPair(aName, bName, 'address-rewire');
  });
}

// PLAN_160 A — every address-rewire finding actually applied must leave
// exactly one x-unraid.links "reference" record behind, naming the FINAL
// service names (after every rename), so the editor never re-asks a
// question this wizard already answered. Schema conformance is checked the
// same way tests/links_record.js checks it — shelling out to python with
// pyyaml and jsonschema — rather than re-typing the schema's own rules here.
function validateAgainstSchema(text) {
  var script = [
    'import sys, json, yaml',
    'from jsonschema import Draft202012Validator',
    'schema = json.load(open(' + JSON.stringify(path.join(__dirname, '..', 'schema', 'x-unraid.schema.json')) + '))',
    'doc = yaml.safe_load(sys.stdin.read())',
    'v = Draft202012Validator(schema)',
    'errors = [str(e.message) + " at /" + "/".join(map(str, e.path)) for e in v.iter_errors(doc)]',
    'print(json.dumps({"ok": not errors, "errors": errors}))'
  ].join('\n');
  var res = require('child_process').spawnSync('python', ['-c', script], { input: text, encoding: 'utf8' });
  if (res.status !== 0) return { ok: false, errors: [res.stderr || 'python failed'] };
  try { return JSON.parse(res.stdout); } catch (e) { return { ok: false, errors: [res.stdout] }; }
}

function checkLinkRecords(label, doc, exam, text) {
  var records = CM.readLinks(doc).filter(function (r) { return r.kind === 'reference'; });
  exam.findings.forEach(function (f) {
    if (f.kind !== 'address-rewire') return;
    var fromSvc = exam.plan.serviceRenames[f.stack + '/' + f.facts.service] || f.facts.service;
    var toSvc = exam.plan.serviceRenames[f.facts.toStack + '/' + f.facts.toService] || f.facts.toService;
    var vars = f.facts.split ? [f.facts.hostVar, f.facts.portVar] : [f.facts.envVar];
    vars.forEach(function (envVar) {
      var matches = records.filter(function (r) {
        return r.between[0].service === fromSvc && r.between[0].environment === envVar && r.between[1].service === toSvc;
      });
      if (matches.length !== 1) {
        fail('160A', 'order ' + label + ': expected exactly one confirmed link record for ' +
          fromSvc + '.' + envVar + ' -> ' + toSvc + ', found ' + matches.length);
      }
    });
  });

  var v = validateAgainstSchema(text);
  if (!v.ok) fail('160A', 'order ' + label + ': the merged file\'s link records do not validate against the schema — ' + JSON.stringify(v.errors));
}

if (CHECK) {
  [{ label: 'A', r: resultA }, { label: 'B', r: resultB }].forEach(function (o) {
    section('--check (order ' + o.label + ')');
    var built = o.r.built, text = o.r.suggested.text;
    var doc = CM.parse(text);

    checkNetworksShared(o.label, doc, o.r.exam);
    checkLinkRecords(o.label, doc, o.r.exam, text);

    ['t155-web_data', 't155-db_data', 't155-cache_data'].forEach(function (n) {
      if (text.indexOf('name: ' + n) === -1) fail(1, 'order ' + o.label + ': volume "' + n + '" has no name: line');
    });

    var backendCount = (text.match(/^\s*backend:\s*$/mg) || []).length;
    if (backendCount !== 1) fail(11, 'order ' + o.label + ': expected exactly one "backend:" network, found ' + backendCount);

    var secretCount = (text.match(/^\s*db_password:\s*$/mg) || []).length;
    if (secretCount !== 1) fail(6, 'order ' + o.label + ': expected exactly one "db_password:" secret, found ' + secretCount);

    if (/ports: \[\]/.test(text)) fail(9, 'order ' + o.label + ': a "ports: []" survived in the written file');

    var anchorLines = {}, missingAnchor = false, aliasBeforeAnchor = false;
    doc.lines.forEach(function (line, i) {
      var am = /&([A-Za-z0-9_-]+)\b/.exec(line);
      if (am && !(am[1] in anchorLines)) anchorLines[am[1]] = i;
      var m = /\*([A-Za-z0-9_-]+)\b/.exec(line);
      if (m) {
        var name = m[1];
        if (!(name in anchorLines)) missingAnchor = true;
        else if (i < anchorLines[name]) aliasBeforeAnchor = true;
      }
    });
    if (missingAnchor) fail(18, 'order ' + o.label + ': a *alias survives with no matching &anchor anywhere in the merged file');
    if (aliasBeforeAnchor) fail(18, 'order ' + o.label + ': a *alias appears before its &anchor');

    if (doc.unreadTail) fail(0, 'order ' + o.label + ': the merged file does not fully parse (unreadTail present)');

    if (/^version:/m.test(text)) fail(18, 'order ' + o.label + ': a "version:" line survived into the merged file');
    if (!built.changes.some(function (c) { return c.title === 'The `version:` line is not carried'; })) {
      fail(18, 'order ' + o.label + ': the version: line\'s own removal has no change record');
    }

    if (!built.changes.length) fail(8, 'order ' + o.label + ': no change records were produced at all — a rewritten line with no mark');

    if (text.indexOf('18081:8080') === -1) fail(16, 'order ' + o.label + ': the override\'s published port (18081:8080) is missing from the merged file');
    if (text.indexOf('ADMINER_DESIGN: pepa-linha') === -1) fail(16, 'order ' + o.label + ': the override\'s ADMINER_DESIGN setting is missing from the merged file');

    ['18080:80', '18443:443', '19999:80'].forEach(function (port) {
      if (text.indexOf(port) === -1) fail(15, 'order ' + o.label + ': the web service is missing published port ' + port);
    });
    if (!built.changes.some(function (c) { return c.title === 'From the override file' && c.stack === storeNameFor('t155-web'); })) {
      fail(15, 'order ' + o.label + ': t155-web\'s 19999 line has no change record marking it as coming from the override file');
    }

    built.files.forEach(function (f) {
      if (/(^|\/)docker-compose\.override\.ya?ml$/i.test(f.path)) {
        fail(15, 'order ' + o.label + ': docker-compose.override.yml appears among the carried companions: ' + f.from + '/' + f.path);
      }
    });

    // The shared file sits at an absolute appdata path (Adrian, 2026-09-16), so the
    // merge must carry it through untouched — no depth rewrite, no relative climb.
    if (text.indexOf('/mnt/user/appdata/staxx-testing/shared/dhparam.pem:/etc/nginx/dhparam.pem:ro') === -1 || text.indexOf('t155-shared') !== -1) {
      fail(12, 'order ' + o.label + ': the dhparam bind mount is not carried through unchanged from appdata');
    }
  });

  if (checkFails.length) {
    console.log('  FAILED:');
    checkFails.forEach(function (f) { console.log('    trap ' + f.trap + ': ' + f.message); });
    process.exit(1);
  }
  console.log('  all checks passed');
} else if (checkFails.length) {
  // The storage-integrity check above always runs, --check or not, because
  // it is cheap and it is the one this whole rework exists to prove.
  console.log('\nSTORAGE INTEGRITY FAILURES (see PLAN_155 C7):');
  checkFails.forEach(function (f) { console.log('  trap ' + f.trap + ': ' + f.message); });
  process.exitCode = 1;
}
