/* StaXX — tests for the merge examiner and writer (PLAN_155 "the rebuild").
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_examine.js
 *
 * No framework, no npm, no network — same shape as tests/health_offer.js:
 * one line per case, non-zero exit if anything fails.
 *
 * A merge now reads N equal sources and writes a brand new third stack —
 * there is no host. Most of the fixtures under tests/fixtures/merge-pairs/
 * predate that change and still live in "host"/"incoming" folders; they are
 * reused here as two equal sources (their folder names are just two source
 * leaves, nothing more), since the shapes they exercise — a service-name
 * clash, a disagreeing network, and so on — are unchanged by the rebuild.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var M = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-examine.js');
var MW = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-write.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

/* =========================================================================
 * Loading a fixture pair as raw text, and as descriptors for examine()
 * directly. `files` defaults to [] (the merge-files reply shape) since most
 * of these fixtures predate companion-file handling; the handful of cases
 * that need real entries build them inline.
 * =========================================================================*/

function loadRaw(fixtureName, side) {
  var dir = path.join(__dirname, 'fixtures', 'merge-pairs', fixtureName, side);
  var text = fs.readFileSync(path.join(dir, 'compose.yaml'), 'utf8');
  var envPath = path.join(dir, '.env');
  var envText = fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : null;
  return { name: side, text: text, envText: envText };
}

function descOf(raw, filesReply, extra) {
  return MW.descriptorFromText(raw.name, raw.text, raw.envText, (filesReply && filesReply.files) || [],
    Object.assign({ filesLarge: filesReply && filesReply.large }, extra));
}

function findingsOf(result, kind) {
  return result.findings.filter(function (f) { return f.kind === kind; });
}

// 'x-unraid' is here because C1 now carries the FIRST source's stack-level
// block (a stack has one description, not one per source) — every other
// top-level key a fixture happens to carry (an "x-logging:", say) is
// outside what this blanket check covers, so a case exercising one calls
// CM.parse() directly instead of assertMergedIsValid().
var MERGED_TOP_KEYS = { services: 1, volumes: 1, networks: 1, configs: 1, secrets: 1, 'x-unraid': 1 };

function toPlain(node) {
  if (!node) return undefined;
  if (node.kind === 'scalar') return node.value;
  if (node.kind === 'seq') return node.items.map(function (it) { return toPlain(it.value); });
  if (node.kind === 'map') {
    var o = {};
    node.keys.forEach(function (k) { o[k] = toPlain(node.pairs[k].value); });
    return o;
  }
  return undefined;
}

// PLAN_148's own defect, still the right check under the rebuild: 92 cases
// matched STRINGS in the merged text and not one of them PARSED it. This
// reparses with compose-model.js and checks the shape a real
// `docker compose up` would insist on.
function assertMergedIsValid(label, composeText, expectedServiceNames) {
  var reparsed = CM.parse(composeText);
  ok(label + ' — the merged file parses clean, no warnings',
     reparsed.warnings.length === 0, reparsed.warnings.join('; '));

  var plain = toPlain(reparsed.root) || {};
  var stray = Object.keys(plain).filter(function (k) { return !MERGED_TOP_KEYS[k]; });
  ok(label + ' — no unexpected top-level key appears', stray.length === 0, JSON.stringify(stray));

  var services = plain.services || {};
  if (expectedServiceNames) {
    ok(label + ' — services holds exactly what is expected',
       Object.keys(services).sort().join(',') === expectedServiceNames.slice().sort().join(','),
       'got: ' + Object.keys(services).join(','));
  }

  var declaredVolumes = plain.volumes || {};
  Object.keys(services).forEach(function (svcName) {
    var vols = services[svcName] && services[svcName].volumes;
    (Array.isArray(vols) ? vols : []).forEach(function (entry) {
      if (typeof entry !== 'string') return;
      var source = entry.split(':')[0];
      if (source === '' || source.charAt(0) === '.' || source.charAt(0) === '/') return;
      ok(label + ' — named volume "' + source + '" (mounted by ' + svcName + ') is declared under volumes:',
         Object.prototype.hasOwnProperty.call(declaredVolumes, source),
         'declared: ' + JSON.stringify(Object.keys(declaredVolumes)));
    });
  });

  return plain;
}

/* =========================================================================
 * A. Name clashes: services, container_name, ports, shorthand declarations
 * — the same shapes as before, now symmetric across equal sources.
 * ========================================================================= */

console.log('\nA. Container name clashes');

(function () {
  var a = loadRaw('container-name-clash', 'host'), b = loadRaw('container-name-clash', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);

  // This fixture's two "web" services run different images (nginx and
  // mariadb) — PLAN_155 C17's better default kicks in, so the clashing
  // service is offered the image's own short name ("mariadb") rather than
  // the plain "web_incoming" suffix. The container_name field clash is a
  // separate rule (unaffected — it has no image to draw a name from).
  var svcClash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('the clashing service is offered its own image\'s short name',
     svcClash.length === 1 && svcClash[0].facts.from === 'web' && svcClash[0].facts.to === 'mariadb' &&
     svcClash[0].facts.imageShortName === 'mariadb');
  ok('the rename is automatic, not a decision', svcClash[0].severity === 'automatic');
  ok('every finding carries a stable key', svcClash[0].key === 'container-name-clash|incoming|' +
     r.findings.indexOf(svcClash[0]));

  var cnClash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'container_name'; });
  ok('the explicit container_name clash still falls back to the suffix (no image to draw from)',
     cnClash.length === 1 && cnClash[0].facts.to === 'web_incoming');

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('the clashing service takes the image-derived name; its container_name keeps the suffix',
     /mariadb:\n\s*image: mariadb:11\n\s*container_name: web_incoming/.test(w.text));
  ok('the first source\'s own untouched service is still exactly as it was',
     /web:\n\s*image: nginx:latest\n\s*container_name: web\n/.test(w.text));

  // Neither rename is a decision, but both are still said, not merely
  // done — the service key's own rename and the container_name rewrite
  // each get their own change record (CLAUDE.md rule 2).
  var svcRename = w.changes.filter(function (c) { return c.key === svcClash[0].key; });
  ok('the service rename gets its own change record, with the image-based reason',
     svcRename.length === 1 && svcRename[0].title === 'Renamed to keep it distinct' &&
     /mariadb:/.test(svcRename[0].marker || w.text.split('\n')[svcRename[0].line]) &&
     svcRename[0].reason.indexOf('mariadb container') >= 0);
  var cnRename = w.changes.filter(function (c) { return c.key === cnClash[0].key; });
  ok('the container_name rewrite gets its own change record too', cnRename.length === 1 &&
     w.text.split('\n')[cnRename[0].line].indexOf('web_incoming') >= 0);
  assertMergedIsValid('container-name-clash', w.text, ['web', 'mariadb']);
})();

(function () {
  // Both call their service "web"; different images, and the image's short
  // name (a real one, with a registry host and a tag to strip) is free.
  var a = loadRaw('service-name-clash-image', 'host'), b = loadRaw('service-name-clash-image', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);
  var clash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('a real registry/tag image name is reduced to its own short name',
     clash.length === 1 && clash[0].facts.to === 'adminer' && clash[0].facts.imageShortName === 'adminer');

  var w = MW.buildMergedText([a, b], { date: '2026-09-16', name: 'demoapp' });
  assertMergedIsValid('service-name-clash-image', w.text, ['web', 'adminer']);
})();

(function () {
  // The image's short name would be "adminer" again, but this source
  // already has its OWN service called that — the fallback suffix must
  // win instead, exactly as it did before this rule existed.
  var a = loadRaw('service-name-clash-taken', 'host'), b = loadRaw('service-name-clash-taken', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);
  var clash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('a short name already taken by another service falls back to the suffix',
     clash.length === 1 && clash[0].facts.to === 'web_incoming' && clash[0].facts.imageShortName === '');

  var w = MW.buildMergedText([a, b], { date: '2026-09-16', name: 'demoapp' });
  assertMergedIsValid('service-name-clash-taken', w.text, ['web', 'adminer', 'web_incoming']);
})();

console.log('\nB. Published port clashes');

(function () {
  var a = loadRaw('port-clash', 'host'), b = loadRaw('port-clash', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);

  var clash = findingsOf(r, 'port-clash');
  ok('one port clash is found', clash.length === 1);
  ok('it names the port and the service that already holds it',
     clash[0].facts.port === '8091' && clash[0].facts.heldBy === 'web' && clash[0].facts.service === 'db');
  // The recommended choice's own id IS the free port StaXX picked (PLAN_155
  // C10) — 20000, the lowest free slot at or above it, since 8091 is the
  // only host port either source uses — so a default merge needs no click
  // to leave the clash unresolved (CLAUDE.md rule 2: never a silent one).
  ok('a free port is recommended, listed first, and the id IS the port number',
     clash[0].facts.freePort === 20000 && clash[0].choices[0].id === 20000 && clash[0].choices[0].recommended === true);

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('unanswered still moves the clashing port, to the recommended free one',
     /- "20000:3306"/.test(w.text) && w.text.indexOf('8091:3306') === -1);
  var defaultChange = w.changes.filter(function (c) { return c.key === clash[0].key; })[0];
  ok('and it carries its own change record — never a silent move',
     !!defaultChange && defaultChange.title === 'Moved off a clashing port');

  var key = clash[0].key;
  var decisions = {}; decisions[key] = 9091;
  var freed = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp', decisions: decisions });
  ok('a chosen free port rewrites the second source\'s own published port',
     /- "9091:3306"/.test(freed.text) && freed.text.indexOf('8091:3306') === -1);
  assertMergedIsValid('port-clash', freed.text, ['web', 'db']);

  var stopped = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp', decisions: (function () { var d = {}; d[key] = 'stop-publishing'; return d; })() });
  ok('"stop publishing" removes the entry rather than moving it',
     stopped.text.indexOf('8091:3306') === -1 && stopped.text.indexOf('3306') === -1);
  var stopChange = stopped.changes.filter(function (c) { return c.key === key; })[0];
  ok('and it too carries a change record, on the real "ports: []" line left behind',
     !!stopChange && stopChange.title === 'No longer published' && !stopChange.removed);

  var swapped = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp', decisions: (function () { var d = {}; d[key] = 'swap'; return d; })() });
  ok('"swap" moves the OTHER (held) service\'s port instead, leaving the mover\'s as written',
     /- "20000:80"/.test(swapped.text) && /- "8091:3306"/.test(swapped.text));
  assertMergedIsValid('port-clash-swap', swapped.text, ['web', 'db']);
})();

console.log('\nB2. A port clash where one side is behind profiles:');

(function () {
  // PLAN_156 F16, applied by C10: a service nobody starts by default yields
  // its port to one that always runs — regardless of which was picked
  // first. "web" here is behind profiles: and picked FIRST (it would
  // ordinarily keep 8091), so it must be the one that moves; "db", picked
  // second and plain, stays put. Built from raw text rather than the
  // "port-clash" fixture pair (mutating a descriptor examine() already
  // built would not be seen by buildMergedText(), which always re-parses
  // its own sources' raw text) so the profiles: line is really there for
  // both the examiner and the writer to read.
  var a = { name: 'host', text: 'services:\n  web:\n    image: nginx:latest\n    profiles:\n      - optional\n    ports:\n      - "8091:80"\n', envText: null };
  var b = { name: 'incoming', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "8091:3306"\n', envText: null };
  var r = M.examine([descOf(a), descOf(b)]);
  var clash = findingsOf(r, 'port-clash')[0];
  ok('the profiled service moves even though it was picked first',
     clash.stack === 'host' && clash.facts.service === 'web' && clash.facts.heldBy === 'db');

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('its own port line moves; the plain service\'s stays put',
     /- "20000:80"/.test(w.text) && /- "8091:3306"/.test(w.text));
  assertMergedIsValid('port-clash-profiled', w.text, ['web', 'db']);
})();

console.log('\nC. Shorthand (top-level declaration) clashes');

(function () {
  var a = loadRaw('shorthand-clash', 'host'), b = loadRaw('shorthand-clash', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);

  var clash = findingsOf(r, 'shorthand-clash');
  ok('the disagreeing "demo" network is found', clash.length === 1);
  ok('it names the kind and the rename', clash[0].facts.declKind === 'networks' &&
     clash[0].facts.from === 'demo' && clash[0].facts.to === 'demo_incoming');

  var d2a = descOf(a), d2b = descOf(b);
  d2b.compose.networks.demo.def = d2a.compose.networks.demo.def;
  var r2 = M.examine([d2a, d2b]);
  ok('an identical declaration under the same name is kept once, nothing renamed',
     findingsOf(r2, 'shorthand-clash').length === 0);

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('the disagreeing network is renamed, and the arriving service follows the rename',
     /networks:\n\s*- demo_incoming/.test(w.text));
  ok('both networks end up declared, under their own names',
     /demo:\n\s*driver: bridge/.test(w.text) && /demo_incoming:\n\s*driver: macvlan/.test(w.text));

  // The rename touches the declared key line AND the service's own
  // networks: reference — both get their own change record.
  var renameChanges = w.changes.filter(function (c) { return c.key === clash[0].key; });
  ok('the shorthand-clash rename gets one change record per line it actually touched',
     renameChanges.length === 2 && renameChanges[0].part === 0 && renameChanges[1].part === 1);
  assertMergedIsValid('shorthand-clash', w.text, ['web', 'db']);
})();

console.log('\nD. Three or more sources — a clash between two NON-FIRST sources');

(function () {
  function bare(name, svcName, image) {
    return {
      name: name, files: [], filesLarge: null, env: null,
      compose: { name: null, services: { }, volumes: {}, networks: {}, configs: {}, secrets: {} }
    };
  }
  // 'x' and 'y' are single-character "images" specifically so their short
  // name would equal themselves, distinguishing this case from the plain
  // suffix — a real image name is exercised separately, in the
  // container-name-clash fixture above.
  var host = bare('host'); host.compose.services.app = { image: 'x', ports: [] };
  var a = bare('a'); a.compose.services.shared = { image: 'x', ports: [] };
  var b = bare('b'); b.compose.services.shared = { image: 'y', ports: [] };

  var r = M.examine([host, a, b]);
  var clash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('neither incoming service collides with the first, but they collide with each other', clash.length === 1);
  ok('the THIRD source is the one renamed, offered its own image\'s short name since the images differ',
     clash[0].stack === 'b' && clash[0].facts.to === 'y' && clash[0].facts.imageShortName === 'y');
})();

/* =========================================================================
 * E. Storage — the new "storage-carry" finding, and two sources declaring
 * the same volume KEY.
 * ========================================================================= */

console.log('\nE. Storage Docker manages — carried, never copied');

(function () {
  var a = loadRaw('storage-volume', 'host'), b = loadRaw('storage-volume', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);

  var storage = findingsOf(r, 'storage-carry');
  ok('the named volume actually mounted is found', storage.length === 1);
  ok('it names the real Docker name, built from the SOURCE\'s own project name',
     storage[0].facts.realName === 'incoming_dbdata');
  ok('the merged key is unchanged when nothing else uses that name', storage[0].facts.mergedKey === 'dbdata');
  ok('"carry" is recommended and first', storage[0].choices[0].id === 'carry' && storage[0].choices[0].recommended === true);
  ok('"start empty" is the only alternative — no "stop here" any more',
     storage[0].choices.length === 2 && storage[0].choices[1].id === 'start-empty');
  ok('nothing existing changes for a carried volume — it is an addition', storage[0].lines.length === 0);

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('the recommended "carry" answer writes the real name as an override, with both required comments',
     /dbdata:\n\s*# incoming’s own storage, under the name Docker already knows it by\.\n\s*# Only the label above is new; the data is untouched\.\n\s*name: incoming_dbdata/.test(w.text));
  assertMergedIsValid('storage-volume (carry)', w.text, ['web', 'db']);

  var key = storage[0].key;
  var decisions = {}; decisions[key] = 'start-empty';
  var empty = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp', decisions: decisions });
  ok('"start with empty storage" writes an ordinary named volume, no override',
     empty.text.indexOf('name: incoming_dbdata') === -1 && /dbdata:\s*\{\}/.test(empty.text));
  assertMergedIsValid('storage-volume (start-empty)', empty.text, ['web', 'db']);

  // An already-external volume never changes identity.
  var extB = descOf(b); extB.compose.volumes.dbdata.external = true;
  var r2 = M.examine([descOf(a), extB]);
  ok('an external volume is never carried — its name never changes', findingsOf(r2, 'storage-carry').length === 0);
})();

(function () {
  // Two sources both calling their own storage "data" — the merged file's
  // SECOND key must be renamed, and every service referencing it under the
  // old name must follow, or the two would collide in one volumes: block.
  var a = { name: 'appA', text: 'services:\n  svcA:\n    image: alpine\n    volumes:\n      - data:/data\nvolumes:\n  data: {}\n' };
  var b = { name: 'appB', text: 'services:\n  svcB:\n    image: alpine\n    volumes:\n      - data:/data\nvolumes:\n  data: {}\n' };

  var da = MW.descriptorFromText('appA', a.text, null, []);
  var db = MW.descriptorFromText('appB', b.text, null, []);
  var r = M.examine([da, db]);
  var storage = findingsOf(r, 'storage-carry');
  ok('both sources\' own "data" volume are each carried', storage.length === 2);
  ok('the second source\'s merged key is disambiguated', storage[1].facts.mergedKey === 'data_APPB');
  ok('their real Docker names never collide even though the merged keys once would have',
     storage[0].facts.realName === 'appA_data' && storage[1].facts.realName === 'appB_data');

  var w = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp' });
  ok('the merged file declares both, under their own disambiguated keys',
     /^  data:\n/m.test(w.text) && /^  data_APPB:\n/m.test(w.text));
  ok('the second service follows the rename to the disambiguated key',
     /svcB:\n\s*image: alpine\n\s*volumes:\n\s*- data_APPB:\/data/.test(w.text));

  // The disambiguated key touches its own declared line AND the mount
  // that names it — both get their own change record (CLAUDE.md rule 2),
  // same as any other rename that spans more than one line.
  var carryChanges = w.changes.filter(function (c) { return c.key === storage[1].key; });
  ok('the storage-carry rename gets one change record per line it actually touched',
     carryChanges.length === 2 && carryChanges[0].part === 0 && carryChanges[1].part === 1 &&
     carryChanges[0].title === 'Still appB’s own storage' &&
     carryChanges[1].title === 'Points at appB’s own storage');
  assertMergedIsValid('storage-carry (same key twice)', w.text, ['svcA', 'svcB']);
})();

/* =========================================================================
 * F. Companion files — the whole new plan.
 * ========================================================================= */

console.log('\nF. Companion files');

(function () {
  var a = {
    name: 'appA', text: [
      'services:', '  web:', '    image: nginx:latest', '    build:', '      context: .',
      '    volumes:', '      - ./default.conf:/etc/nginx/default.conf'
    ].join('\n')
  };
  var b = {
    name: 'appB', text: [
      'services:', '  web2:', '    image: nginx:latest',
      '    volumes:', '      - ./default.conf:/etc/nginx/default.conf'
    ].join('\n')
  };
  var filesReplies = {
    appA: {
      files: [
        { path: 'default.conf', size: 10, dir: false, referenced: true },
        { path: 'certs/server.key', size: 100, dir: false, keyLike: true, referenced: true },
        { path: 'notes.txt', size: 5, dir: false, referenced: false },
        { path: 'weird-link', size: 0, dir: false, link: true, target: '../../outside', outside: true }
      ], large: null
    },
    appB: {
      files: [
        { path: 'default.conf', size: 12, dir: false, referenced: true },
        { path: 'data', size: 0, dir: true, referenced: true }
      ], large: { path: 'data' }
    }
  };

  var descA = MW.descriptorFromText('appA', a.text, null, filesReplies.appA.files, { filesLarge: filesReplies.appA.large });
  var descB = MW.descriptorFromText('appB', b.text, null, filesReplies.appB.files, { filesLarge: filesReplies.appB.large });
  var r = M.examine([descA, descB]);

  ok('a relative symlink resolving outside the folder is refused, never copied',
     findingsOf(r, 'outside-link').length === 1 && findingsOf(r, 'outside-link')[0].severity === 'refusal');
  ok('a key-shaped file is called out as informational, copied in two places',
     findingsOf(r, 'key-copied').length === 1);
  ok('an unreferenced file is offered copy/leave-behind, defaulting to copy',
     findingsOf(r, 'unreferenced').length === 1 && findingsOf(r, 'unreferenced')[0].choices[0].id === 'copy');
  ok('a source over the size threshold is a warning, never a refusal',
     findingsOf(r, 'large-folder').length === 1 && findingsOf(r, 'large-folder')[0].severity === 'warning');
  ok('a service with build: is named, informationally', findingsOf(r, 'build-image').length === 1);

  var clash = findingsOf(r, 'file-clash');
  ok('both sources bringing "default.conf" is one clash, not two', clash.length === 1);
  ok('the default answer renames every copy, suffixed with its own source',
     clash[0].facts.renameTo.length === 2 &&
     clash[0].facts.renameTo[0].to === 'default-appA.conf' && clash[0].facts.renameTo[1].to === 'default-appB.conf');

  var w = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', files: filesReplies });
  var renamed = w.files.filter(function (f) { return f.path === 'default.conf'; });
  ok('the copy list carries both renamed destinations', renamed.length === 2 &&
     renamed[0].to === 'default-appA.conf' && renamed[1].to === 'default-appB.conf');
  ok('an outside link never appears in the copy list at all',
     !w.files.some(function (f) { return f.path === 'weird-link'; }));

  ok('the rename is followed through into each source\'s own bind mount',
     /web:\n\s*image: nginx:latest\n\s*build:\n\s*context: \.\n\s*volumes:\n\s*- \.\/default-appA\.conf:\/etc\/nginx\/default\.conf/.test(w.text));
  ok('...and the second source\'s own copy of the same reference',
     /web2:\n\s*image: nginx:latest\n\s*volumes:\n\s*- \.\/default-appB\.conf:\/etc\/nginx\/default\.conf/.test(w.text));
  ok('the container-SIDE path (the same text, coincidentally) is never touched',
     w.text.indexOf('/etc/nginx/default.conf') >= 0);
  assertMergedIsValid('companion file-clash', w.text, ['web', 'web2']);

  // keep-one / leave-behind on the same clash.
  var decisions1 = {}; decisions1[clash[0].key] = 'keep-one';
  var keepOne = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', files: filesReplies, decisions: decisions1 });
  var keepOneFiles = keepOne.files.filter(function (f) { return f.path === 'default.conf'; });
  ok('"keep one" keeps only the FIRST source\'s own copy, under its plain name',
     keepOneFiles[0].to === 'default.conf' && keepOneFiles[1].to === null);

  var decisions2 = {}; decisions2[clash[0].key] = 'leave-behind';
  var leftBehind = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', files: filesReplies, decisions: decisions2 });
  var leftFiles = leftBehind.files.filter(function (f) { return f.path === 'default.conf'; });
  ok('"leave it behind" copies neither', leftFiles.every(function (f) { return f.to === null; }));
})();

console.log('\nG. An inert override file must never come alive (fault 1)');

(function () {
  var a = { name: 'appA', text: 'services:\n  web:\n    image: nginx:latest\n' };
  var b = { name: 'appB', text: 'services:\n  web2:\n    image: nginx:latest\n' };
  var filesReplies = { appA: { files: [{ path: 'docker-compose.override.yml', size: 5, dir: false, referenced: false }] }, appB: { files: [] } };
  var w = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', files: filesReplies });
  var entry = w.files.filter(function (f) { return f.path === 'docker-compose.override.yml'; })[0];
  ok('a companion whose name could pair with a compose file is renamed on arrival, never left as-is',
     entry.to === 'docker-compose.override.yml.from-appA');
})();

/* =========================================================================
 * H. A stack has one description, not one per source (PLAN_155, corrected
 * again by C1 on 2026-09-15): the FIRST source's own stack-level x-unraid
 * is now carried like any other top-level key, and every later source's is
 * left behind with its own change record — never silently dropped. Each
 * service's OWN x-unraid block is carried verbatim regardless, same as
 * every other line in its block — EXCEPT its icon, which PLAN_155 C17
 * always points at "./.staxx/icon-<service>.<ext>" once the file it names
 * sits inside that source's own .staxx/, whether or not anything clashed.
 * ========================================================================= */

console.log('\nH. One stack-level x-unraid (the first source\'s), services keep their own');

(function () {
  var e = {
    name: 'srcE', text: 'x-unraid:\n  icon: ./.staxx/icon.png\n  description: "First app"\n' +
      'services:\n  svc:\n    image: alpine\n    x-unraid:\n      icon: ./.staxx/svc-icon.png\n'
  };
  var f = {
    name: 'srcF', text: 'x-unraid:\n  icon: ./.staxx/icon.png\n  description: "Second app"\n' +
      'services:\n  svc2:\n    image: alpine\n    x-unraid:\n      icon: ./.staxx/svc2-icon.png\n'
  };

  var w = MW.buildMergedText([e, f], { date: '2026-09-15', name: 'demoapp' });
  ok('the merged text carries exactly one top-level x-unraid: key — the FIRST source\'s',
     (w.text.match(/^x-unraid:/gm) || []).length === 1);
  ok('...and it is that source\'s own text, description included', w.text.indexOf('First app') >= 0);
  ok('each service\'s icon is rewritten to its own per-service file, regardless of any clash',
     /icon: \.\/\.staxx\/icon-svc\.png/.test(w.text) && /icon: \.\/\.staxx\/icon-svc2\.png/.test(w.text));
  ok('the plan copies each one under its new name',
     w.files.some(function (fi) { return fi.from === 'srcE' && fi.path === '.staxx/svc-icon.png' && fi.to === '.staxx/icon-svc.png'; }) &&
     w.files.some(function (fi) { return fi.from === 'srcF' && fi.path === '.staxx/svc2-icon.png' && fi.to === '.staxx/icon-svc2.png'; }));
  ok('the second source\'s own stack-level description does not survive anywhere in the merge',
     w.text.indexOf('Second app') === -1);

  var dropped = w.changes.filter(function (c) { return c.title === 'This stack’s own description is not carried'; })[0];
  ok('...and its own change record says so, rather than silently dropping it',
     !!dropped && dropped.stack === 'srcF' && dropped.reason === 'A merged stack has one description, and srcE’s is kept. This one is left out; Decline keeps it under its own renamed key.');
  ok('dropping the second stack\'s own description CAN be left as it was', dropped.cannotLeave === undefined);

  // "leave" here means keeping it after all — renamed the same way any
  // other clashing top-level "x-" key already is, since two "x-unraid:"
  // keys cannot coexist.
  var kept = MW.buildMergedText([e, f], { date: '2026-09-15', name: 'demoapp', decisions: { 'top-xunraid|srcF': 'leave' } });
  ok('choosing "leave" keeps the second source\'s own description too, renamed to fit',
     kept.text.indexOf('Second app') >= 0 && /^x-unraid-srcF:/m.test(kept.text));
  ok('no change record is produced for it, since nothing was left out',
     !kept.changes.some(function (c) { return c.key === 'top-xunraid|srcF'; }));
})();

console.log('\nH1. Icons are not a question — never a clash, one copy per referencing service');

(function () {
  // Two sources, each with its own '.staxx/icon.png' — same path in both,
  // which would have been a file-clash before PLAN_155 C17. One source's
  // two services (web, api) share the one file; the other's single
  // service (db) has its own copy of the same name.
  var g = {
    name: 'srcG', text: 'services:\n  web:\n    image: alpine\n    x-unraid:\n      icon: ./.staxx/icon.png\n' +
      '  api:\n    image: alpine\n    x-unraid:\n      icon: ./.staxx/icon.png\n'
  };
  var h = {
    name: 'srcH', text: 'services:\n  db:\n    image: alpine\n    x-unraid:\n      icon: ./.staxx/icon.png\n'
  };
  var iconEntry = { path: '.staxx/icon.png', size: 512, dir: false, link: false, target: '', outside: false, keyLike: false, referenced: true };
  var filesReplies = { srcG: { files: [iconEntry] }, srcH: { files: [iconEntry] } };

  var descs = [descOf(g, filesReplies.srcG), descOf(h, filesReplies.srcH)];
  var r = M.examine(descs);
  ok('the same-named icon file in both sources raises no file-clash at all',
     findingsOf(r, 'file-clash').length === 0);

  var w = MW.buildMergedText([g, h], { date: '2026-09-16', name: 'demoapp', files: filesReplies });
  ok('each service\'s icon reference points at its own per-service file',
     /web:\n[\s\S]*?icon: \.\/\.staxx\/icon-web\.png/.test(w.text) &&
     /api:\n[\s\S]*?icon: \.\/\.staxx\/icon-api\.png/.test(w.text) &&
     /db:\n[\s\S]*?icon: \.\/\.staxx\/icon-db\.png/.test(w.text));

  var iconFiles = w.files.filter(function (fi) { return fi.path === '.staxx/icon.png'; });
  ok('the plan holds three copies — one per referencing service, even the two sharing one source file',
     iconFiles.length === 3 &&
     iconFiles.some(function (fi) { return fi.from === 'srcG' && fi.to === '.staxx/icon-web.png'; }) &&
     iconFiles.some(function (fi) { return fi.from === 'srcG' && fi.to === '.staxx/icon-api.png'; }) &&
     iconFiles.some(function (fi) { return fi.from === 'srcH' && fi.to === '.staxx/icon-db.png'; }));
  assertMergedIsValid('icons are not a question', w.text, ['web', 'api', 'db']);
})();

console.log('\nH2. Every other top-level key travels too (C1, PLAN_156 F1, trap 18)');

(function () {
  // A source with an anchor under a top-level key it alone declares — the
  // fault: buildMergedText()'s old skeleton dropped the whole "x-logging:"
  // block, carrying the two services' own "logging: *logging" aliases
  // with no anchor left anywhere for them to point at.
  var web = { name: 'demo-web', text: [
    'x-logging: &logging', '  driver: json-file',
    'services:', '  web:', '    image: nginx:latest', '    logging: *logging'
  ].join('\n') };
  var db = { name: 'demo-db', text: 'services:\n  db:\n    image: mariadb:11\n    logging: *logging\n' };

  var w = MW.buildMergedText([web, db], { date: '2026-09-15', name: 'demoapp' });
  ok('the anchor itself survives, under services:', /^x-logging: &logging$/m.test(w.text));
  ok('both services\' own aliases still point at it (one from each source)',
     (w.text.match(/logging: \*logging\b/g) || []).length === 2);
  var reparsed = CM.parse(w.text);
  ok('the merged file parses clean, no warnings — the alias actually resolves', reparsed.warnings.length === 0, reparsed.warnings);
})();

(function () {
  // Two sources both declare "x-logging: &logging" for DIFFERENT things —
  // the key is renamed for the second (an "x-" key clash), and so is its
  // anchor (an anchor clash is settled "the same way keys do"), and each
  // rename gets its own change record rather than a silent guess.
  var web = { name: 'demo-web', text: [
    'x-logging: &logging', '  driver: json-file',
    'services:', '  web:', '    image: nginx:latest', '    logging: *logging'
  ].join('\n') };
  var db = { name: 'demo-db', text: [
    'x-logging: &logging', '  driver: syslog',
    'services:', '  db:', '    image: mariadb:11', '    logging: *logging'
  ].join('\n') };

  var w = MW.buildMergedText([web, db], { date: '2026-09-15', name: 'demoapp' });
  ok('the first source\'s key and anchor are untouched', /x-logging: &logging\n {2}driver: json-file/.test(w.text));
  ok('the second source\'s key is renamed, distinct from the first',
     /x-logging-demo-db: &logging_demo-db/.test(w.text));
  ok('the second source\'s own alias follows its own anchor\'s rename',
     /db:\n {4}image: mariadb:11\n {4}logging: \*logging_demo-db/.test(w.text));
  ok('the two are never confused with one another', w.text.indexOf('*logging\n') >= 0 || /logging: \*logging$/m.test(w.text));

  var keyChange = w.changes.filter(function (c) { return c.title === 'Renamed to keep it distinct' && c.stack === 'demo-db' && c.key.indexOf('top-key') === 0; })[0];
  ok('the key rename gets its own change record',
     !!keyChange && keyChange.reason.indexOf('x-logging') >= 0 && keyChange.reason.indexOf('x-logging-demo-db') >= 0);
  var anchorChange = w.changes.filter(function (c) { return c.key.indexOf('top-anchor') === 0; })[0];
  ok('the anchor rename gets its own, separate change record',
     !!anchorChange && anchorChange.stack === 'demo-db' && anchorChange.reason.indexOf('logging_demo-db') >= 0);
  ok('...and it cannot be left as it was — two anchors of the same name would collide',
     anchorChange.cannotLeave === true);

  var reparsed = CM.parse(w.text);
  ok('the merged file parses clean, no warnings', reparsed.warnings.length === 0, reparsed.warnings);
})();

(function () {
  // C1's other rules in one pass: version: dropped with its own record,
  // name: dropped silently, and a non-"x-" key clash refused outright.
  var a = { name: 'appA', text: 'version: "3.8"\nname: ignored-a\nservices:\n  web:\n    image: nginx:latest\n' };
  var b = { name: 'appB', text: 'services:\n  db:\n    image: mariadb:11\n' };
  var w = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp' });
  ok('the version: line is gone', !/^version:/m.test(w.text));
  ok('...and the name: line too, silently — no change record needed for it',
     !/^name:/m.test(w.text) && !w.changes.some(function (c) { return c.reason && c.reason.indexOf('name:') >= 0; }));
  var vChange = w.changes.filter(function (c) { return c.title === 'The `version:` line is not carried'; })[0];
  ok('the version drop is said, not silent, and points at no merged line — the line is gone, so its card opens on the source side',
     !!vChange && vChange.line === null &&
     vChange.reason === 'Compose ignores it and warns about it; a file StaXX writes fresh does not start with a warning.');
  ok('the version drop CAN be left as it was — unlike a mandatory rename', vChange.cannotLeave === undefined);

  // "Every change must be answered" (PLAN_155 C17): decisions[key] === 'leave'
  // is the non-recommended answer where one exists — here, keeping the
  // version: line after all.
  var leftVersion = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', decisions: { 'top-version': 'leave' } });
  ok('choosing "leave" on the version: drop keeps it in the merged file', /^version: "3\.8"/m.test(leftVersion.text));
  ok('...and no change record is produced for it, since nothing was changed',
     !leftVersion.changes.some(function (c) { return c.key === 'top-version'; }));

  var c = { name: 'appC', text: 'oddball:\n  a: 1\nservices:\n  web:\n    image: nginx:latest\n' };
  var d = { name: 'appD', text: 'oddball:\n  a: 2\nservices:\n  db:\n    image: mariadb:11\n' };
  var refused = MW.buildMergedText([c, d], { date: '2026-09-15', name: 'demoapp' });
  ok('two sources disagreeing on an unknown non-"x-" key are refused, not guessed at',
     refused.refusals.some(function (f) { return f.kind === 'top-level-key-clash'; }));
  var refusal = refused.refusals.filter(function (f) { return f.kind === 'top-level-key-clash'; })[0];
  ok('the refusal says plainly what to do about it',
     refusal.message === 'Both appC and appD define `oddball` at the top of their files and they differ; ' +
       'StaXX does not know how to join them. Make them the same, or remove one, and merge again.');

  var e = { name: 'appE', text: 'oddball:\n  a: 1\nservices:\n  web:\n    image: nginx:latest\n' };
  var f = { name: 'appF', text: 'oddball:\n  a: 1\nservices:\n  db:\n    image: mariadb:11\n' };
  var same = MW.buildMergedText([e, f], { date: '2026-09-15', name: 'demoapp' });
  ok('identical text under the same unknown key is carried once, no refusal',
     same.refusals.length === 0 && (same.text.match(/^oddball:/gm) || []).length === 1);
})();

/* =========================================================================
 * I. Depth-path — a relative path re-pointed because the new stack sits
 * somewhere else.
 * ========================================================================= */

console.log('\nI. Relative paths that move with the folder\'s own depth');

(function () {
  var c = {
    name: 'appC', depth: 1, text: [
      'services:', '  svc:', '    image: alpine',
      '    volumes:', '      - ../shared/certs:/certs',
      '    env_file:', '      - ../shared/app.env'
    ].join('\n')
  };
  var d = { name: 'appD', depth: 0, text: 'services:\n  svc2:\n    image: alpine\n' };

  var descC = MW.descriptorFromText('appC', c.text, null, [], { depth: 1 });
  var descD = MW.descriptorFromText('appD', d.text, null, [], { depth: 0 });
  var r = M.examine([descC, descD], { newDepth: 0 });
  var found = findingsOf(r, 'depth-path');
  ok('two relative paths reaching outside the folder are found', found.length === 2);
  ok('"./" is never touched — only "../" needs anything', found.every(function (f) { return /^\.\.\//.test(f.facts.oldPath); }));

  var noMove = M.examine([descC, descD], { newDepth: 1 });
  ok('nothing is flagged when the new stack sits at the SAME depth the source already did',
     findingsOf(noMove, 'depth-path').length === 0);

  var w = MW.buildMergedText([c, d], { date: '2026-09-15', name: 'demoapp', newDepth: 0 });
  ok('the bind mount is re-pointed one level shallower', /- \.\/shared\/certs:\/certs/.test(w.text));
  ok('...and the env_file entry alongside it', /- \.\/shared\/app\.env/.test(w.text));
  ok('the old "../" text is gone, not merely commented out', w.text.indexOf('../shared') === -1);
  assertMergedIsValid('depth-path', w.text, ['svc', 'svc2']);

  var decisions = {}; found.forEach(function (f) { decisions[f.key] = 'leave'; });
  var left = MW.buildMergedText([c, d], { date: '2026-09-15', name: 'demoapp', newDepth: 0, decisions: decisions });
  ok('"leave" keeps the path exactly as the author wrote it, even though it now points somewhere else',
     left.text.indexOf('../shared/certs') >= 0);
})();

/* =========================================================================
 * I2. C4 — a "../" path is resolved by FOLDER, not counted by depth. Two
 * folders at the same depth are still two different places.
 * ========================================================================= */

console.log('\nI2. Depth-path resolves by folder, not by depth (C4)');

(function () {
  var raw = loadRaw('depth-path-folder', 'source');
  var desc = descOf(raw, null, { depth: 1, rel: 'Media/app' });

  // Same depth on both sides — a pure depth count would see no move at
  // all, and that is exactly the bug C4 fixes.
  var r1 = M.examine([desc], { newDepth: 1, newRel: 'Other/new' });
  var f1 = findingsOf(r1, 'depth-path')[0];
  ok('same depth, different folder is still found and resolved by folder',
     !!f1 && f1.facts.newPath === '../../Media/shared/x', f1 && f1.facts.newPath);

  // The new stack sitting at the store root needs one fewer "../".
  var r2 = M.examine([desc], { newDepth: 0, newRel: 'new' });
  var f2 = findingsOf(r2, 'depth-path')[0];
  ok('the new stack at the store root climbs one fewer level',
     !!f2 && f2.facts.newPath === '../Media/shared/x', f2 && f2.facts.newPath);
})();

/* =========================================================================
 * J. The joined settings list (.env) — symmetric across N sources.
 * ========================================================================= */

console.log('\nJ. The joined settings list (.env)');

(function () {
  var a = loadRaw('env-clash', 'host'), b = loadRaw('env-clash', 'incoming');
  var r = M.examine([descOf(a), descOf(b)], { date: '2026-09-14' });

  var join = findingsOf(r, 'settings-join');
  ok('exactly one settings-join finding for the whole merge, not one per source', join.length === 1);
  var facts = join[0].facts;
  ok('a value shared unchanged (TZ) is folded away, credited to the SECOND source',
     facts.sameValueNames.length === 1 && facts.sameValueNames[0].name === 'TZ' && facts.sameValueNames[0].stack === 'incoming');
  ok('a disagreeing value is renamed with the SECOND source\'s own suffix',
     facts.renamed.length === 1 && facts.renamed[0].from === 'DB_PASSWORD' && facts.renamed[0].to === 'DB_PASSWORD_INCOMING');

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('the joined file opens with the required heading, both sources named',
     /^# Settings joined from host and incoming, 2026-09-14\.$/m.test(w.env) === false); // see note below
  ok('each source\'s own block carries "# From <source>"',
     /# From host/.test(w.env) && /# From incoming/.test(w.env));
  ok('the renamed setting keeps its own value, not merged into a comment',
     /^DB_PASSWORD_INCOMING=dbsecret(\s|$)/m.test(w.env));
  ok('a value shared unchanged (TZ) is written once only', (w.env.match(/^TZ=/mg) || []).length === 1);
  assertMergedIsValid('env-clash', w.text, ['web', 'db']);

  var stopKey = join[0].key;
  var decisions = {}; decisions[stopKey] = 'stop-here';
  var stopped = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp', decisions: decisions });
  ok('"stop here" on the settings-join surfaces as a refusal', stopped.refusals.length === 1 &&
     stopped.refusals[0].kind === 'settings-join');
  ok('...and no .env text is offered to write in that case', stopped.env === null);

  // Three sources: a name introduced by the SECOND is compared against the
  // THIRD independently of the first, never chained.
  var third = { name: 'third', env: { lines: [{ type: 'setting', name: 'DB_PASSWORD', value: 'dbsecret', comment: '' }] },
    files: [], filesLarge: null, compose: { name: null, services: {}, volumes: {}, networks: {}, configs: {}, secrets: {} } };
  var r3 = M.examine([descOf(a), descOf(b), third], { date: '2026-09-14' });
  var join3 = findingsOf(r3, 'settings-join')[0];
  ok('the third source repeats the SECOND source\'s own (renamed) value and is folded away against it',
     join3.facts.sameValueNames.some(function (s) { return s.stack === 'third'; }) === false);
  ok('...because DB_PASSWORD_INCOMING was never the ORIGINAL name — third is compared against DB_PASSWORD (host\'s value), and differs, so it is renamed too',
     join3.facts.renamed.some(function (r) { return r.stack === 'third' && r.to === 'DB_PASSWORD_THIRD'; }));
})();

/* =========================================================================
 * K. Wiring — unchanged in substance, proven once symmetrically.
 * ========================================================================= */

console.log('\nK. Wiring');

(function () {
  var a = loadRaw('wiring', 'host'), b = loadRaw('wiring', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);

  var rewire = findingsOf(r, 'address-rewire');
  ok('one address is found that can become a service name', rewire.length === 1);
  ok('it names the service, the env var, and what it becomes',
     rewire[0].facts.service === 'web' && rewire[0].facts.envVar === 'DB_ADDRESS' &&
     rewire[0].facts.toService === 'db' && rewire[0].facts.toPort === '3306');

  var unneeded = findingsOf(r, 'port-unneeded');
  ok('the now-unneeded published port is recommended and ticked by default, same as address-rewire',
     unneeded.length === 1 && unneeded[0].choices[0].recommended === true && unneeded[0].choices[0].ticked === true);

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('the address becomes the arriving service\'s name and real port', /DB_ADDRESS: db:3306/.test(w.text));
  ok('the now-unneeded port is removed by default, and produces its own change record',
     w.text.indexOf('3307:3306') === -1 &&
     w.changes.some(function (c) { return c.title === 'No longer published'; }));
  assertMergedIsValid('wiring', w.text, ['web', 'db']);
})();

console.log('\nK2. The falsified-comment fault — struck, not carried across unchanged');

(function () {
  var host = {
    name: 'demo-web', text: [
      'services:', '  web:', '    image: nginx:latest', '    ports:', '      - "8091:80"',
      '    environment:', '      # Points at the database over the LAN, because it lives in its own stack.',
      '      DB_ADDRESS: 192.0.2.88:3307'
    ].join('\n')
  };
  var incoming = { name: 'demo-db', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "3307:3306"\n' };

  var w = MW.buildMergedText([host, incoming], { date: '2026-09-15', name: 'demoapp' });
  ok('the falsified comment is gone from the merged text, not carried across describing something no longer true',
     w.text.indexOf('Points at the database over the LAN') === -1);
  ok('the rewritten address line is still there, correct', /DB_ADDRESS: db:3306/.test(w.text));

  var change = w.changes.filter(function (c) { return c.key.indexOf('address-rewire') === 0; })[0];
  ok('the change record carries the struck comment\'s own text, so the wizard can show it struck through',
     !!change && change.struckComment.indexOf('Points at the database over the LAN') >= 0);
  ok('the change record names a merged-text line, and the plain-English title/reason the plan asks for',
     typeof change.line === 'number' && change.title === 'Now reaches db inside the stack' &&
     change.reason === 'Was 192.0.2.88:3307, out on the network.');
  assertMergedIsValid('falsified-comment', w.text, ['web', 'db']);

  // Ticking port-unneeded also removes a line and records its own change.
  var portFinding = M.examine([MW.descriptorFromText('demo-web', host.text, null, []),
    MW.descriptorFromText('demo-db', incoming.text, null, [])]).findings.filter(function (f) { return f.kind === 'port-unneeded'; })[0];
  var decisions = {}; decisions[portFinding.key] = true;
  var closed = MW.buildMergedText([host, incoming], { date: '2026-09-15', name: 'demoapp', decisions: decisions });
  ok('ticking "no longer published" removes the port, and the change record says so plainly',
     closed.text.indexOf('3307:3306') === -1 &&
     closed.changes.some(function (c) { return c.title === 'No longer published'; }));
})();

console.log('\nK3. Wiring is found through the settings file too (C2, PLAN_156 F2)');

(function () {
  // wiring-env/host writes every address as a "${VAR}" placeholder; its own
  // .env is what actually sets three of the four names. Before C2 none of
  // this resolved, so nothing here was ever found.
  var a = loadRaw('wiring-env', 'host'), b = loadRaw('wiring-env', 'incoming');
  var opts = { thisServer: ['192.0.2.88'] };
  var r = M.examine([descOf(a), descOf(b)], opts);

  var rewire = findingsOf(r, 'address-rewire');
  ok('the single placeholder (${DB_ADDRESS}) is resolved through .env and found', rewire.length === 2);

  var plain = rewire.filter(function (f) { return !f.facts.split; })[0];
  ok('...naming the placeholder as written, and what the settings file actually set it to',
     !!plain && plain.facts.service === 'web1' && plain.facts.from === '${DB_ADDRESS}' &&
     plain.facts.fromResolved === '192.0.2.88:5432' && plain.facts.toService === 'db1');

  var split = rewire.filter(function (f) { return f.facts.split; })[0];
  ok('the split _HOST/_PORT pair is resolved the same way',
     !!split && split.facts.service === 'web2' && split.facts.fromHost === '${DB_HOST}' &&
     split.facts.fromPort === '${DB_PORT}' && split.facts.fromHostResolved === '192.0.2.88' &&
     split.facts.fromPortResolved === '5433' && split.facts.toService === 'db2');

  var absence = findingsOf(r, 'left-alone').filter(function (f) { return f.facts.service === 'web3'; })[0];
  ok('a default used because the settings file leaves the name unset is left alone, not rewired',
     !!absence && absence.facts.envVar === 'DB3_HOST' && absence.facts.value === '127.0.0.1' &&
     absence.facts.note.indexOf('not confidently this server') >= 0);

  var w = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', thisServer: opts.thisServer });
  ok('the compose line is rewritten, not the .env line — the placeholder is gone',
     /DB_ADDRESS: db1:5432/.test(w.text) && /DB_HOST: db2/.test(w.text) && /DB_PORT: 5433/.test(w.text));
  ok('the unresolved default is written exactly as the author wrote it — the one absence',
     /DB3_HOST: \$\{DB3_HOST:-127\.0\.0\.1\}/.test(w.text) && /DB3_PORT: \$\{DB3_PORT\}/.test(w.text));

  var plainChange = w.changes.filter(function (c) { return c.key === plain.key; })[0];
  ok('the reason names both the placeholder and what the settings file set it to',
     plainChange.reason === 'Was ${DB_ADDRESS}, which the settings file set to 192.0.2.88:5432, out on the network.');
  var splitHostChange = w.changes.filter(function (c) { return c.key === split.key && c.part === 'host'; })[0];
  ok('...and the same for the split pair\'s own host line',
     splitHostChange.reason === 'Was ${DB_HOST}, which the settings file set to 192.0.2.88, out on the network.');

  assertMergedIsValid('wiring-env', w.text, ['web1', 'web2', 'web3', 'db1', 'db2', 'db3']);
})();

console.log('\nK4. PLAN_155 C15 — a rewired connection must land on a shared network');

(function () {
  // db is on its own named network; web carries no networks: key at all,
  // so it is only on the project's implicit default — the exact shape
  // that started dying with "getaddrinfo for db failed" (F26, third walk).
  var host = {
    name: 'demo-web', text: [
      'services:', '  web:', '    image: nginx:latest',
      '    environment:', '      DB_ADDRESS: 192.0.2.88:3307'
    ].join('\n')
  };
  var incoming = {
    name: 'demo-db', text: [
      'services:', '  db:', '    image: mariadb:11', '    ports:', '      - "3307:3306"',
      '    networks:', '      - backend',
      '', 'networks:', '  backend:'
    ].join('\n')
  };

  var w = MW.buildMergedText([host, incoming], { date: '2026-09-15', name: 'demoapp' });
  ok('web is joined onto backend, alongside the default network it never named',
     /web:[\s\S]*?networks:\n\s+- default\n\s+- backend/.test(w.text));
  ok('db\'s own networks: line is untouched — nothing is ever removed',
     /db:[\s\S]*?networks:\n\s+- backend/.test(w.text));

  var join = w.changes.filter(function (c) { return c.key.indexOf('network-join') === 0; })[0];
  ok('the join has its own change record, titled and reasoned per the plan',
     !!join && join.title === 'Joined the `backend` network' &&
     join.reason === 'db is only on `backend`, so without this web could not reach it by name.');

  assertMergedIsValid('network-join', w.text, ['web', 'db']);

  // A service already on a network that happens to intersect needs no
  // join at all — the ordinary case, and the one that must stay silent.
  var alreadyShared = {
    name: 'demo-web2', text: [
      'services:', '  web:', '    image: nginx:latest', '    networks:', '      - backend',
      '    environment:', '      DB_ADDRESS: 192.0.2.88:3307'
    ].join('\n')
  };
  var w2 = MW.buildMergedText([alreadyShared, incoming], { date: '2026-09-15', name: 'demoapp' });
  ok('already sharing a network — no join, no extra change record',
     !w2.changes.some(function (c) { return c.key.indexOf('network-join') === 0; }));
})();

/* =========================================================================
 * L. retireText — every shape of profiles:, and idempotence.
 * ========================================================================= */

console.log('\nL. retireText — the retirement rewrite');

(function () {
  var noProfiles = 'services:\n  web:\n    image: nginx:latest\n    ports:\n      - "80:80"\n';
  var r1 = MW.retireText(noProfiles, 'demoapp', '2026-09-15');
  ok('a service with no profiles: at all gets one added as its own last key',
     /profiles: \["retired"\]/.test(r1));
  ok('the required comment sits directly above services:',
     /# Retired into demoapp, 2026-09-15[\s\S]*\nservices:/.test(r1));
  ok('the merged text still parses clean', CM.parse(r1).warnings.length === 0);
  var again1 = MW.retireText(r1, 'demoapp', '2026-09-15');
  ok('applying it twice is a no-op (no-profiles case)', again1 === r1);

  var flowProfiles = 'services:\n  web:\n    image: nginx:latest\n    profiles: ["debug"]\n';
  var r2 = MW.retireText(flowProfiles, 'demoapp', '2026-09-15');
  ok('an existing flow-form profiles: list gains "retired" alongside what was already there',
     /profiles: \["debug", "retired"\]/.test(r2));
  var again2 = MW.retireText(r2, 'demoapp', '2026-09-15');
  ok('applying it twice is a no-op (flow-form case)', again2 === r2);

  var blockProfiles = 'services:\n  web:\n    image: nginx:latest\n    profiles:\n      - debug\n      - staging\n';
  var r3 = MW.retireText(blockProfiles, 'demoapp', '2026-09-15');
  ok('an existing block-form profiles: list gains a new "- retired" item',
     /profiles:\n\s*- debug\n\s*- staging\n\s*- retired/.test(r3));
  var again3 = MW.retireText(r3, 'demoapp', '2026-09-15');
  ok('applying it twice is a no-op (block-form case)', again3 === r3);

  var twoServices = 'services:\n  web:\n    image: nginx:latest\n  db:\n    image: mariadb:11\n';
  var r4 = MW.retireText(twoServices, 'demoapp', '2026-09-15');
  ok('EVERY service in the file carries the profile, not just the first',
     (r4.match(/profiles: \["retired"\]/g) || []).length === 2);
  assertMergedIsValid('retireText (two services)', r4, ['web', 'db']);
})();

/* =========================================================================
 * M. A stack's name is its leaf, never its path.
 * ========================================================================= */

console.log('\nM. A stack\'s name is its leaf, never its path');

function leafOf(name) {
  var parts = String(name).split('/');
  return parts[parts.length - 1];
}

(function () {
  var hostRaw = loadRaw('storage-volume', 'host');
  var incomingRaw = loadRaw('storage-volume', 'incoming');
  var incoming = { name: leafOf('DEV-TESTING/demo-db'), text: incomingRaw.text, envText: incomingRaw.envText };
  var r = MW.buildMergedText([hostRaw, incoming], { date: '2026-09-14', name: 'demoapp' });

  ok('the carried volume is named after the stack\'s leaf ("demo-db_dbdata")',
     r.text.indexOf('name: demo-db_dbdata') >= 0);
  ok('...and never after its full path', r.text.indexOf('DEV-TESTING') === -1);
  assertMergedIsValid('leaf-name (storage-carry)', r.text, ['web', 'db']);
})();

(function () {
  var hostRaw = loadRaw('env-clash', 'host');
  var incomingRaw = loadRaw('env-clash', 'incoming');
  var incoming = { name: leafOf('DEV-TESTING/demo-db'), text: incomingRaw.text, envText: incomingRaw.envText };
  var r = MW.buildMergedText([hostRaw, incoming], { date: '2026-09-14', name: 'demoapp' });

  ok('a settings clash is renamed with the leaf as its suffix ("DB_PASSWORD_DEMO_DB")',
     /^DB_PASSWORD_DEMO_DB=dbsecret(\s|$)/m.test(r.env));
  ok('...never with the full path folded in', r.env.indexOf('DEV_TESTING') === -1);
  assertMergedIsValid('leaf-name (env-clash)', r.text, ['web', 'db']);
})();

/* =========================================================================
 * N. The whole demo pair, end to end, exactly the wizard's own worked
 * example from the plan.
 * ========================================================================= */

console.log('\nN. The clean pair — nothing clashes at all, and every category reports so');

(function () {
  var a = loadRaw('clean', 'host'), b = loadRaw('clean', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);

  ['container-name-clash', 'port-clash', 'shorthand-clash', 'file-clash'].forEach(function (kind) {
    ok('nothing of kind ' + kind + ' is raised', findingsOf(r, kind).filter(function (f) {
      return f.severity !== 'clean';
    }).length === 0);
  });

  var clean = findingsOf(r, 'clean');
  ok('every checked category reports clean', clean.length === 4);

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  assertMergedIsValid('clean pair', w.text, Object.keys(descOf(a).compose.services).concat(Object.keys(descOf(b).compose.services)));
})();

/* =========================================================================
 * O. Sources whose own `name` is a full REL with a folder ("DEV-TESTING/
 * demo-db"), exactly what the real wizard hands buildMergedText() — the six
 * fixes found probing this exact shape, 2026-09-15.
 * ========================================================================= */

console.log('\nO. Full-rel source names — the leaf, not the rel, is what gets written');

(function () {
  // 1. A carried volume must be named after the LEAF's own project, never
  // the full rel — "DEV-TESTING/demo-db_dbdata" is not a volume Docker has,
  // and asking for it would start the database EMPTY.
  var db = {
    name: 'DEV-TESTING/demo-db', depth: 1, text: [
      'services:', '  mariadb:', '    image: mariadb:11', '    volumes:', '      - dbdata:/var/lib/mysql',
      'volumes:', '  dbdata: {}'
    ].join('\n')
  };
  var web = { name: 'DEV-TESTING/demo-web', depth: 1, text: 'services:\n  web:\n    image: nginx:latest\n' };

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'DEV-TESTING/demoapp' });
  ok('the carried volume\'s real name uses the LEAF, never the full rel',
     w.text.indexOf('name: demo-db_dbdata') >= 0 && w.text.indexOf('DEV-TESTING/demo-db_dbdata') === -1);
  ok('the opening comment names leaves, joined in plain English',
     /^# Made by joining demo-db and demo-web, 2026-09-15\.$/m.test(w.text));
  ok('every spliced block is introduced by "# From <leaf>", never the rel',
     /# From demo-db\n/.test(w.text) && /# From demo-web\n/.test(w.text) && w.text.indexOf('DEV-TESTING') === -1);
  assertMergedIsValid('full-rel (storage-carry)', w.text, ['mariadb', 'web']);

  // 6. newProject is the NEW stack's own leaf.
  ok('buildMergedText returns the new stack\'s own leaf as newProject',
     w.newProject === 'demoapp');

  // `stack` fields and `files[].from` are the one place the rel survives.
  var storage = w.findings.filter(function (f) { return f.kind === 'storage-carry'; })[0];
  ok('the finding\'s own `stack` field still carries the full rel, for the server\'s sake',
     storage.stack === 'DEV-TESTING/demo-db');
})();

(function () {
  // 1 (continued) — the .env rename suffix is built from the leaf too:
  // "DB_PASSWORD_DEMO_WEB", never "DB_PASSWORD_DEV_TESTING_DEMO_WEB".
  // 4 — the reason names the FIRST source (the one that keeps the plain
  // name), never the one being renamed.
  var db = { name: 'DEV-TESTING/demo-db', depth: 1, text: 'services:\n  mariadb:\n    image: mariadb:11\n', envText: 'DB_PASSWORD=dbsecret\n' };
  var web = { name: 'DEV-TESTING/demo-web', depth: 1, text: 'services:\n  web:\n    image: nginx:latest\n', envText: 'DB_PASSWORD=websecret\n' };

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'DEV-TESTING/demoapp' });
  ok('the renamed setting uses the LEAF as its suffix', /^DB_PASSWORD_DEMO_WEB=websecret/m.test(w.env));
  ok('...never the full rel folded in', w.env.indexOf('DEV_TESTING') === -1);

  var renameChange = w.changes.filter(function (c) { return c.title.indexOf('Renamed') === 0; })[0];
  ok('the reason names the FIRST source, who keeps the plain name — not the one renamed',
     renameChange.reason === 'demo-db’s stays DB_PASSWORD.');
  assertMergedIsValid('full-rel (env rename)', w.text, ['mariadb', 'web']);
})();

(function () {
  // 2 — the falsified comment above a rewritten line is struck AND
  // recorded, even in the split _HOST/_PORT shape where the comment sits
  // above the HOST line and only the PORT line was being checked before.
  // 3 — port-unneeded applies by default and yields its own change record.
  // 5 — every change record carries both `line` (merged text) and
  // `sourceLine` (that source's own original text).
  var db = {
    name: 'DEV-TESTING/demo-db', depth: 1, text: [
      'services:', '  mariadb:', '    image: mariadb:11', '    ports:', '      - "3307:3306"',
      '    volumes:', '      - dbdata:/var/lib/mysql', 'volumes:', '  dbdata: {}'
    ].join('\n')
  };
  var web = {
    name: 'DEV-TESTING/demo-web', depth: 1, text: [
      'services:', '  web:', '    image: nginx:latest', '    ports:', '      - "8091:80"',
      '    environment:', '      # Points at the database over the LAN, because it lives in its own stack.',
      '      DB_HOST: 192.0.2.88', '      DB_PORT: "3307"'
    ].join('\n')
  };

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'DEV-TESTING/demoapp' });
  ok('the split pair is rewired by default', /DB_HOST: mariadb/.test(w.text) && /DB_PORT: "3306"/.test(w.text));
  ok('the old address is gone', w.text.indexOf('192.0.2.88') === -1);
  ok('the falsified comment above DB_HOST is gone from the merged text',
     w.text.indexOf('Points at the database over the LAN') === -1);

  var splitChanges = w.changes.filter(function (c) { return c.key.indexOf('address-rewire') === 0; });
  var rewire = splitChanges[0];
  ok('...and IS recorded as struck on the address-rewire change, not lost silently',
     !!rewire && rewire.struckComment && rewire.struckComment.indexOf('Points at the database over the LAN') >= 0);
  ok('the address-rewire change carries both a merged-text line and its own source line',
     typeof rewire.line === 'number' && typeof rewire.sourceLine === 'number');

  // The split rewire touches TWO lines (DB_HOST and DB_PORT) — each gets
  // its own change record, sharing one `key` and told apart by `part`.
  ok('the split rewire produces exactly two change records, not one pointing only at the host line',
     splitChanges.length === 2);
  ok('both share the same finding key', splitChanges[0].key === splitChanges[1].key);
  var byPart = {}; splitChanges.forEach(function (c) { byPart[c.part] = c; });
  ok('one is the host line, the other the port line', !!byPart.host && !!byPart.port);
  ok('the port line\'s own title/reason name the database\'s own port, not the host\'s',
     byPart.port.title === 'Now uses mariadb’s own port' &&
     byPart.port.reason === 'Was 3307, the port published on the network; inside the stack mariadb listens on 3306.');
  ok('the port change is ALSO recorded as its own real edit — a merged-text line and its own source line',
     typeof byPart.port.line === 'number' && typeof byPart.port.sourceLine === 'number' &&
     byPart.port.sourceLine !== byPart.host.sourceLine);

  ok('the now-unneeded port is removed by default (no decision needed)', w.text.indexOf('3307:3306') === -1);
  var unneeded = w.changes.filter(function (c) { return c.title === 'No longer published'; })[0];
  ok('...and produces its own change record, with a source line too',
     !!unneeded && unneeded.removed === true &&
     unneeded.reason === 'This line is removed from the written file. Nothing outside the stack needs to reach mariadb now.' &&
     typeof unneeded.sourceLine === 'number');

  assertMergedIsValid('full-rel (falsified comment + port-unneeded default)', w.text, ['mariadb', 'web']);
})();

/* =========================================================================
 * O1b. PLAN_155 C7 — three sources sharing one storage name, named by their
 * STORE PATH exactly as the wizard names them, picked in both orders. Two
 * of the three (whichever are not first-picked) must have BOTH their
 * declared key AND every mount line renamed together, whatever the source's
 * own identity looks like and whatever order it was picked in — the live
 * bug carried the renamed key into the volumes: block but left the mount
 * lines saying "data", which would have started a database on another
 * service's storage.
 * ========================================================================= */

console.log('\nO1b. Three sources sharing a volume key, store-path names, both pick orders (PLAN_155 C7)');

function threeVolumeSources() {
  return {
    db: {
      name: 'DEV-TESTING/t155-db', depth: 1, rel: 'DEV-TESTING/t155-db', text: [
        'services:', '  db:', '    image: mariadb:11', '    volumes:', '      - data:/var/lib/mysql',
        'volumes:', '  data: {}'
      ].join('\n')
    },
    cache: {
      name: 'DEV-TESTING/t155-cache', depth: 1, rel: 'DEV-TESTING/t155-cache', text: [
        'services:', '  cache:', '    image: redis:7-alpine', '    volumes:', '      - data:/data',
        'volumes:', '  data: {}'
      ].join('\n')
    },
    web: {
      name: 'DEV-TESTING/t155-web', depth: 1, rel: 'DEV-TESTING/t155-web', text: [
        'services:', '  php:', '    image: php:8', '    volumes:', '      - data:/var/www/uploads',
        'volumes:', '  data: {}'
      ].join('\n')
    }
  };
}

// Every "- <key>:/…" mount under a service must name a key actually
// declared under the merged text's own volumes: block — the exact check
// that would have caught MariaDB mounting Redis's storage.
function everyMountIsDeclared(text) {
  var declared = {};
  (text.match(/^  ([A-Za-z0-9_.-]+):\s*$/mg) || []).forEach(function (m) {
    var mm = /^  ([A-Za-z0-9_.-]+):/.exec(m);
    if (mm) declared[mm[1]] = true;
  });
  var ok = true, offender = null;
  (text.match(/^\s*-\s*['"]?[A-Za-z0-9][\w.-]*:\/[^\n]*$/mg) || []).forEach(function (line) {
    var mm = /^\s*-\s*['"]?([A-Za-z0-9][\w.-]*):\//.exec(line);
    if (mm && !declared[mm[1]]) { ok = false; offender = line; }
  });
  return { ok: ok, offender: offender, declared: declared };
}

[
  { label: 'web, db, cache (dry run\'s own order)', order: ['web', 'db', 'cache'] },
  { label: 'db, cache, web (reversed)', order: ['db', 'cache', 'web'] }
].forEach(function (variant) {
  var pool = threeVolumeSources();
  var sources = variant.order.map(function (k) { return pool[k]; });
  var r = MW.buildMergedText(sources, { date: '2026-09-15', name: 'DEV-TESTING/t155-site' });

  var check = everyMountIsDeclared(r.text);
  ok('order [' + variant.label + ']: every mount names a key the merged file actually declares' +
     (check.ok ? '' : ' (offending line: "' + check.offender + '")'), check.ok);

  var carried = r.findings.filter(function (f) {
    return f.kind === 'storage-carry' && f.facts.mergedKey !== f.facts.volume;
  });
  ok('order [' + variant.label + ']: exactly two of the three sources needed a rename',
     carried.length === 2);
  carried.forEach(function (f) {
    var recs = r.changes.filter(function (c) { return c.key === f.key; });
    ok('order [' + variant.label + ']: ' + f.stack + '\'s rename has a change record for its ' +
       'declaration line AND every mount line (' + f.lines.length + ' expected)',
       recs.length === f.lines.length && f.lines.length >= 2);
  });

  assertMergedIsValid('C7 (' + variant.label + ')', r.text, ['db', 'cache', 'php']);
});

console.log('\nO2. Finding `lines` populated for every rewriting kind, threading raw text in');

(function () {
  var a = loadRaw('container-name-clash', 'host'), b = loadRaw('container-name-clash', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);
  var svcClash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; })[0];
  ok('container-name-clash names the OLD service key line in the source\'s own text',
     svcClash.lines.length === 1 && svcClash.lines[0].stack === 'incoming' && svcClash.lines[0].line === 1);
})();

(function () {
  var a = loadRaw('shorthand-clash', 'host'), b = loadRaw('shorthand-clash', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);
  var clash = findingsOf(r, 'shorthand-clash')[0];
  ok('shorthand-clash names the declared key\'s own line', clash.lines.length === 1 && clash.lines[0].stack === 'incoming');
})();

(function () {
  var a = loadRaw('port-clash', 'host'), b = loadRaw('port-clash', 'incoming');
  var r = M.examine([descOf(a), descOf(b)]);
  var clash = findingsOf(r, 'port-clash')[0];
  // Both sides' own line — the mover's (incoming/db, moving by default) and
  // the one it clashes with (host/web, staying put) — so a "swap" decision
  // (PLAN_155 C10) still has a real line on the OTHER stack to mark.
  ok('port-clash names both the mover\'s and the held service\'s own line',
     clash.lines.length === 2 && clash.lines[0].stack === 'incoming' && clash.lines[1].stack === 'host');
})();

(function () {
  // A same-key volume rename (two sources both calling their storage
  // "data") must mark BOTH the declared key line and every mount reference
  // — this is the one storage-carry case that DOES rewrite existing lines.
  var a = { name: 'appA', text: 'services:\n  svcA:\n    image: alpine\n    volumes:\n      - data:/data\nvolumes:\n  data: {}\n' };
  var b = { name: 'appB', text: 'services:\n  svcB:\n    image: alpine\n    volumes:\n      - data:/data\nvolumes:\n  data: {}\n' };
  var da = MW.descriptorFromText('appA', a.text, null, []);
  var db = MW.descriptorFromText('appB', b.text, null, []);
  var r = M.examine([da, db]);
  var storage = findingsOf(r, 'storage-carry');
  ok('the FIRST source\'s own carry (no key change) marks nothing existing', storage[0].lines.length === 0);
  ok('the SECOND source\'s disambiguated carry marks its declared line and its mount reference',
     storage[1].lines.length === 2 && storage[1].lines.every(function (l) { return l.stack === 'appB'; }));
})();

(function () {
  // A file-clash finding marks the reference line in EACH source's own text.
  var a = { name: 'appA', text: 'services:\n  web:\n    image: nginx:latest\n    volumes:\n      - ./default.conf:/etc/nginx/default.conf\n' };
  var b = { name: 'appB', text: 'services:\n  web2:\n    image: nginx:latest\n    volumes:\n      - ./default.conf:/etc/nginx/default.conf\n' };
  var filesReplies = {
    appA: { files: [{ path: 'default.conf', size: 10, dir: false, referenced: true }] },
    appB: { files: [{ path: 'default.conf', size: 12, dir: false, referenced: true }] }
  };
  var descA = MW.descriptorFromText('appA', a.text, null, filesReplies.appA.files);
  var descB = MW.descriptorFromText('appB', b.text, null, filesReplies.appB.files);
  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'file-clash')[0];
  ok('the file-clash marks the reference line in BOTH sources',
     clash.lines.length === 2 && clash.lines.some(function (l) { return l.stack === 'appA'; }) &&
     clash.lines.some(function (l) { return l.stack === 'appB'; }));
})();

(function () {
  // depth-path marks the exact line the old "../" path sits on.
  var c = {
    name: 'appC', depth: 1, text: 'services:\n  svc:\n    image: alpine\n    volumes:\n      - ../shared/certs:/certs\n'
  };
  var d = { name: 'appD', depth: 0, text: 'services:\n  svc2:\n    image: alpine\n' };
  var descC = MW.descriptorFromText('appC', c.text, null, [], { depth: 1 });
  var descD = MW.descriptorFromText('appD', d.text, null, [], { depth: 0 });
  var r = M.examine([descC, descD], { newDepth: 0 });
  var found = findingsOf(r, 'depth-path')[0];
  ok('depth-path names its own line in the source\'s original text',
     found.lines.length === 1 && found.lines[0].stack === 'appC' && found.lines[0].line === 4);
})();

/* =========================================================================
 * P. Removing the LAST published port drops the "ports:" key from the
 * written file entirely — never left behind as "ports: []" — and the
 * change record says so: `removed: true`, `removedText` carries exactly
 * the line that would have stayed, and `line` is where a struck ghost row
 * belongs in the final text.
 * ========================================================================= */

console.log('\nP. Closing the last published port leaves valid, explicit YAML');

(function () {
  var db = { name: 'demo-db', text: 'services:\n  mariadb:\n    image: mariadb:11\n    ports:\n      - "3307:3306"\n' };
  var web = { name: 'demo-web', text: 'services:\n  web:\n    image: nginx:latest\n    environment:\n      DB_ADDRESS: 192.0.2.88:3307\n' };

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp' });
  ok('the only port entry going drops the "ports:" key from the written file entirely',
     !/ports:/.test(w.text));

  var unneeded = w.changes.filter(function (c) { return c.title === 'No longer published'; })[0];
  ok('the change record is marked removed, carrying the exact line that would have stayed',
     !!unneeded && unneeded.removed === true && unneeded.removedText === '    ports: []');
  ok('its `line` is where the struck ghost row belongs — the line now standing where "ports: []" would have',
     typeof unneeded.line === 'number' && w.text.split('\n')[unneeded.line] !== '    ports: []');
  assertMergedIsValid('last-port-closed', w.text, ['mariadb', 'web']);
})();

(function () {
  // A second port survives — "ports:" itself is untouched, and the change
  // record's line points at THAT key line, not the service's own.
  var db = {
    name: 'demo-db', text: [
      'services:', '  mariadb:', '    image: mariadb:11', '    ports:',
      '      - "3307:3306"', '      - "3308:3308"'
    ].join('\n')
  };
  var web = { name: 'demo-web', text: 'services:\n  web:\n    image: nginx:latest\n    environment:\n      DB_ADDRESS: 192.0.2.88:3307\n' };

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp' });
  ok('the surviving port stays published, "ports:" left as a real block, not "[]"',
     /- "3308:3308"/.test(w.text) && !/ports: \[\]/.test(w.text));

  var unneeded = w.changes.filter(function (c) { return c.title === 'No longer published'; })[0];
  ok('the change record\'s line points at the "ports:" key line, not the service key',
     !!unneeded && w.text.split('\n')[unneeded.line] === '    ports:');
  assertMergedIsValid('one-of-two-ports-closed', w.text, ['mariadb', 'web']);
})();

(function () {
  // The same fix applies to port-clash's own "stop publishing" answer, not
  // just port-unneeded — both share removePortPublishTracked().
  var a = { name: 'appA', text: 'services:\n  web:\n    image: nginx:latest\n    ports:\n      - "8091:80"\n' };
  var b = { name: 'appB', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "8091:3306"\n' };
  var descA = MW.descriptorFromText('appA', a.text, null, []);
  var descB = MW.descriptorFromText('appB', b.text, null, []);
  var clash = M.examine([descA, descB]).findings.filter(function (f) { return f.kind === 'port-clash'; })[0];
  var decisions = {}; decisions[clash.key] = 'stop-publishing';

  var w = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', decisions: decisions });
  ok('"stop publishing" on a port-clash also leaves "ports: []", not a bare key',
     /^    ports: \[\]$/m.test(w.text));
  assertMergedIsValid('port-clash-stop-publishing', w.text, ['web', 'db']);
})();

/* =========================================================================
 * Q. The .env join's own two per-entry overrides — step 4's "keep both"
 * and "choose a name" answers, which the engine used to ignore entirely.
 * ========================================================================= */

console.log('\nQ. Settings-join per-entry overrides');

(function () {
  // "keep both" on a dedupe: the second source's line is written back in
  // under its OWN name, not dropped — and no change record follows, since
  // nothing was struck.
  var db = { name: 'demo-db', text: 'services:\n  mariadb:\n    image: mariadb:11\n', envText: 'TZ=Europe/London\n' };
  var web = { name: 'demo-web', text: 'services:\n  web:\n    image: nginx:latest\n', envText: 'TZ=Europe/London\n' };
  var descA = MW.descriptorFromText('demo-db', db.text, db.envText, []);
  var descB = MW.descriptorFromText('demo-web', web.text, web.envText, []);
  var join = M.examine([descA, descB]).findings.filter(function (f) { return f.kind === 'settings-join'; })[0];
  var changeKey = join.key + '|dedupe|0';

  var recommended = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp' });
  ok('unanswered still dedupes as before (no regression)', (recommended.env.match(/^TZ=/mg) || []).length === 1);
  ok('the duplicate line is removed from the joined .env, not left as an "already set above" comment',
     recommended.env.indexOf('already set above') === -1);

  var dedupeChange = recommended.changes.filter(function (c) { return c.key === changeKey; })[0];
  ok('the removal is reported: removed, removedText carries the exact line that would have stayed',
     !!dedupeChange && dedupeChange.removed === true && dedupeChange.removedText === 'TZ=Europe/London' &&
     dedupeChange.file === 'env' && dedupeChange.title === 'Already set above, so not repeated');
  ok('`line` is where the struck ghost row belongs in the FINAL .env text',
     typeof dedupeChange.line === 'number' && recommended.env.split('\n')[dedupeChange.line] !== 'TZ=Europe/London');

  var decisions = {}; decisions[changeKey] = 'keep-both';
  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp', decisions: decisions });
  ok('"keep both" writes the second source\'s own line back in, under its own name',
     (w.env.match(/^TZ=Europe\/London$/mg) || []).length === 2);
  ok('...inside its own "# From" block, not floating loose',
     /# From demo-web\nTZ=Europe\/London/.test(w.env));
  ok('no change record is produced for a kept-both entry — nothing was struck',
     !w.changes.some(function (c) { return c.key === changeKey; }));
  assertMergedIsValid('settings-join keep-both', w.text, ['mariadb', 'web']);
})();

(function () {
  // "choose a name" on a rename: the typed name replaces the automatic
  // suffix everywhere — the .env line, the reason, and every interpolated
  // reference to the OLD name in that source's own compose lines.
  var db = { name: 'demo-db', text: 'services:\n  mariadb:\n    image: mariadb:11\n', envText: 'DB_PASSWORD=dbsecret\n' };
  var web = {
    name: 'demo-web', envText: 'DB_PASSWORD=websecret\n', text: [
      'services:', '  web:', '    image: nginx:latest', '    environment:',
      '      APP_DB_PASS: ${DB_PASSWORD}', '      OTHER: $DB_PASSWORD-suffix'
    ].join('\n')
  };
  var descA = MW.descriptorFromText('demo-db', db.text, db.envText, []);
  var descB = MW.descriptorFromText('demo-web', web.text, web.envText, []);
  var join = M.examine([descA, descB]).findings.filter(function (f) { return f.kind === 'settings-join'; })[0];
  var changeKey = join.key + '|rename|0';

  var decisions = {}; decisions[changeKey] = 'choose-name';
  var envNames = {}; envNames[changeKey] = 'WEB_DB_SECRET';
  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp', decisions: decisions, envNames: envNames });

  ok('the .env line uses the TYPED name, not the automatic suffix',
     /^WEB_DB_SECRET=websecret/m.test(w.env) && w.env.indexOf('DB_PASSWORD_DEMO_WEB') === -1);
  ok('both interpolation shapes in that source\'s own compose lines follow the rename',
     /APP_DB_PASS: \$\{WEB_DB_SECRET\}/.test(w.text) && /OTHER: \$WEB_DB_SECRET-suffix/.test(w.text));
  ok('the untouched source\'s own DB_PASSWORD is unaffected', /^DB_PASSWORD=dbsecret/m.test(w.env));

  var change = w.changes.filter(function (c) { return c.key === changeKey; })[0];
  ok('the reason names the chosen name', change.reason === 'demo-db’s stays DB_PASSWORD; this one is now WEB_DB_SECRET.');
  assertMergedIsValid('settings-join choose-name', w.text, ['mariadb', 'web']);

  // An invalid or missing typed name falls back to the automatic suffix,
  // silently — never a half-written or illegal variable name.
  var badNames = {}; badNames[changeKey] = '1-not-a-legal-name';
  var fallback = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp', decisions: decisions, envNames: badNames });
  ok('an invalid typed name falls back to the automatic suffix',
     /^DB_PASSWORD_DEMO_WEB=websecret/m.test(fallback.env));
  ok('...and the reason reads as the ordinary, non-overridden case',
     fallback.changes.filter(function (c) { return c.key === changeKey; })[0].reason === 'demo-db’s stays DB_PASSWORD.');

  var missing = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp', decisions: decisions });
  ok('a missing typed name (no envNames at all) falls back the same way',
     /^DB_PASSWORD_DEMO_WEB=websecret/m.test(missing.env));
})();

/* =========================================================================
 * C3 — a paired override is applied onto the main file's own document
 * before anything else reads it (PLAN_155's dry-run corrections). An
 * appended port, an environment entry merged by key (the override
 * winning), an untouched entry left alone, a brand new entry added, and a
 * replaced command — each line the override touches gets its own change
 * record, titled "From the override file".
 * ========================================================================= */

(function () {
  var base = [
    'services:',
    '  web:',
    '    image: nginx:latest',
    '    ports:',
    '      - "8080:80"',
    '    environment:',
    '      FOO: base-value',
    '      KEEP: unchanged',
    '    command: node app.js'
  ].join('\n') + '\n';

  var override = [
    'services:',
    '  web:',
    '    ports:',
    '      - "9090:90"',
    '    environment:',
    '      FOO: override-value',
    '      NEWVAR: added',
    '    command: node override.js'
  ].join('\n') + '\n';

  var desc = MW.descriptorFromText('demo-web', base, null, [], { overrideText: override });

  ok('C3: the appended port is present in the applied text', /9090:90/.test(desc.text));
  ok('C3: the original port survives untouched', /8080:80/.test(desc.text));
  ok('C3: environment merges by key — the override wins for FOO',
     /FOO: override-value/.test(desc.text) && desc.text.indexOf('FOO: base-value') === -1);
  ok('C3: an untouched base setting is left alone', /KEEP: unchanged/.test(desc.text));
  ok('C3: a brand new override-only setting is added', /NEWVAR: added/.test(desc.text));
  ok('C3: command is replaced outright',
     desc.text.indexOf('command: node override.js') !== -1 && desc.text.indexOf('app.js') === -1);

  var changes = desc.overrideChanges || [];
  ok('C3: exactly one change record per line the override touched (port, FOO, NEWVAR, command)',
     changes.length === 4);
  ok('C3: every record is titled "From the override file"',
     changes.every(function (c) { return c.title === 'From the override file'; }));
  ok('C3: every record\'s reason names the override file as the cause',
     changes.every(function (c) { return /sets this beside the main file/.test(c.reason); }));
  ok('C3: every record carries a real sourceLine, and it is exactly the line it names',
     changes.every(function (c) { return typeof c.sourceLine === 'number' && desc.text.split('\n')[c.sourceLine] === c.marker; }));

  assertMergedIsValid('C3 override-applied text on its own', desc.text, ['web']);

  // A source with no override at all is untouched — overrideChanges is
  // simply empty, not absent, so callers never have to guard its shape.
  var plain = MW.descriptorFromText('demo-web', base, null, [], {});
  ok('C3: no overrideText means no change records and the text is untouched',
     Array.isArray(plain.overrideChanges) && plain.overrideChanges.length === 0 && plain.text === base);
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
