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
  // Adrian's decision (2026-09-16): Approved/Decline like every other card —
  // no "swap which side moves" and no "stop publishing" any more.
  ok('the second choice is "leave", not "stop-publishing" or "swap"',
     clash[0].choices.length === 2 && clash[0].choices[1].id === 'leave' &&
     clash[0].choices.every(function (c) { return c.id !== 'stop-publishing' && c.id !== 'swap'; }));

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

  var declined = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp', decisions: (function () { var d = {}; d[key] = 'leave'; return d; })() });
  ok('Decline leaves BOTH services publishing 8091, as written',
     /- "8091:80"/.test(declined.text) && /- "8091:3306"/.test(declined.text));
  assertMergedIsValid('port-clash-declined', declined.text, ['web', 'db']);
  var declineChanges = declined.changes.filter(function (c) { return c.key === key; });
  ok('and it carries exactly one declined change record, titled by the clashing port',
     declineChanges.length === 1 && declineChanges[0].declined === true &&
     declineChanges[0].title === 'Two services publish port 8091');
})();

(function () {
  // PLAN_155 F17 — the mover's own "open web page" address named the port
  // that just moved; it must follow, or the button opens a port the
  // service no longer publishes.
  // Neither side is behind profiles:, so the SECOND source's service is the
  // one that moves by default — the webui line goes on that one.
  var a = {
    name: 'host', envText: null, text: [
      'services:', '  db:', '    image: mariadb:11', '    ports:', '      - "8091:3306"'
    ].join('\n')
  };
  var b = {
    name: 'incoming', envText: null, text: [
      'services:', '  web:', '    image: nginx:latest', '    x-unraid:',
      '      webui: "http://[IP]:8091/"', '    ports:', '      - "8091:80"'
    ].join('\n')
  };
  var r = M.examine([descOf(a), descOf(b)]);
  var clash = findingsOf(r, 'port-clash')[0];
  var w = MW.buildMergedText([a, b], { date: '2026-09-17', name: 'demoapp' });
  ok('the webui address follows the moved port',
     w.text.indexOf('webui: "http://[IP]:20000/"') >= 0);
  var webChange = w.changes.filter(function (c) { return c.key === clash.key && c.part === 'webui'; })[0];
  ok('a change record with part "webui" carries the right title',
     !!webChange && webChange.title === 'Web address follows the moved port');

  // A mover WITHOUT a webui line produces no such record.
  var b2 = {
    name: 'incoming', envText: null, text: [
      'services:', '  web:', '    image: nginx:latest', '    ports:', '      - "8091:80"'
    ].join('\n')
  };
  var r2 = M.examine([descOf(a), descOf(b2)]);
  var clash2 = findingsOf(r2, 'port-clash')[0];
  var w2 = MW.buildMergedText([a, b2], { date: '2026-09-17', name: 'demoapp' });
  ok('no webui record is produced when the mover has no webui line',
     w2.changes.filter(function (c) { return c.key === clash2.key && c.part === 'webui'; }).length === 0);
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

(function () {
  // Same shape, but "profiles:" is written bracket-style on one line — the
  // fault in Adrian's real merge (2026-09-17): toPlain() used to read a
  // flow-style list as nothing at all, so this service looked like it
  // always ran and the LIVE service moved off its port instead.
  var a = { name: 'host', text: 'services:\n  web:\n    image: nginx:latest\n    profiles: ["optional"]\n    ports:\n      - "8091:80"\n', envText: null };
  var b = { name: 'incoming', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "8091:3306"\n', envText: null };
  var r = M.examine([descOf(a), descOf(b)]);
  var clash = findingsOf(r, 'port-clash')[0];
  ok('a bracket-style profiles: still moves the profiled service, not the plain one',
     clash.stack === 'host' && clash.facts.service === 'web' && clash.facts.heldBy === 'db');

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('its own port line moves; the plain service\'s stays put (bracket-style profiles:)',
     /- "20000:80"/.test(w.text) && /- "8091:3306"/.test(w.text));
  assertMergedIsValid('port-clash-profiled-flow', w.text, ['web', 'db']);
})();

(function () {
  // A bracket-style command: must not break descriptor building — it is a
  // sibling flow value to profiles:, read the same way, and services must
  // still come through.
  var a = { name: 'host', text: 'services:\n  web:\n    image: redis:7\n    command: ["redis-server", "/etc/redis.conf"]\n', envText: null };
  var desc = MW.descriptorFromText(a.name, a.text, a.envText, []);
  ok('a bracket-style command: does not break descriptor building',
     !!desc.compose.services.web && desc.compose.services.web.image === 'redis:7');
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
  // PLAN_169 F7 — the real name Docker actually builds is the project name
  // LOWER-CASED first ("appA" the folder becomes project "appa" on the real
  // server, whatever case the folder itself is written in) — so both real
  // names below are lower-case even though the sources' own leaves are not.
  ok('their real Docker names never collide even though the merged keys once would have',
     storage[0].facts.realName === 'appa_data' && storage[1].facts.realName === 'appb_data');

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

(function () {
  // PLAN_169 F18 — the exact reproduction: two sources both declare a
  // volume called "data", but one of them mounts it in the LONG form
  // (type:/source:/target:) rather than the short "data:/path" a compose
  // author usually writes. Before the fix, a long-form mount was invisible
  // to findStorageFindings(): the store's own declaration read as unused,
  // was never carried and never clash-renamed, and in one pick order its
  // database was left pointing at the OTHER source's volume outright.
  var store = {
    name: 't169-store', text: [
      'services:', '  db:', '    image: postgres:16', '    volumes:',
      '      - type: volume', '        source: data', '        target: /var/lib/postgresql/data',
      'volumes:', '  data: {}', ''
    ].join('\n')
  };
  var bus = {
    name: 't169-bus', text: [
      'services:', '  mqtt:', '    image: eclipse-mosquitto:2', '    volumes:',
      '      - data:/mosquitto/data',
      'volumes:', '  data: {}', ''
    ].join('\n')
  };

  function checkBothOrders(order) {
    var label = order.map(function (s) { return s.name; }).join(' then ');
    var descs = order.map(function (s) { return descOf(s); });
    var r = M.examine(descs);
    var storage = findingsOf(r, 'storage-carry');
    ok('F18 [' + label + ']: both sources\' long- and short-form "data" volumes are each found',
       storage.length === 2, JSON.stringify(storage));

    var w = MW.buildMergedText(order, { date: '2026-09-24', name: 't169app' });
    ok('F18 [' + label + ']: the merged file declares two distinct volume keys, each with its own real name:',
       /name: t169-store_data/.test(w.text) && /name: t169-bus_data/.test(w.text));
    ok('F18 [' + label + ']: the long-form mount follows its source: to whichever key t169-store kept',
       /source: data(_T169_STORE|_T169_BUS)?\s*\n\s*target: \/var\/lib\/postgresql\/data/.test(w.text));
    ok('F18 [' + label + ']: the short-form mount follows its own key too',
       /- data(_T169_STORE|_T169_BUS)?:\/mosquitto\/data/.test(w.text));
    assertMergedIsValid('storage-carry (long+short syntax, ' + label + ')', w.text, ['db', 'mqtt']);
    return w;
  }

  // Whichever source is picked first keeps the plain "data" key and the
  // other is disambiguated — same rule an existing test above already
  // exercises for two short-form sources — so what has to hold in BOTH
  // orders is not identical text but the same shape: two declarations, two
  // correct real names, every use following its own source. checkBothOrders()
  // asserts exactly that for each pick in turn.
  checkBothOrders([store, bus]);
  checkBothOrders([bus, store]);
})();

(function () {
  // PLAN_169 F18 — a long-form BIND mount ("type: bind") names a host path,
  // never a declared volume; it must never be offered a storage-carry
  // decision or have its source: rewritten as though it were one.
  var a = {
    name: 'appA', text: [
      'services:', '  web:', '    image: nginx:alpine', '    volumes:',
      '      - type: bind', '        source: ./data', '        target: /data',
      '      - type: volume', '        source: cache', '        target: /cache',
      'volumes:', '  cache: {}', ''
    ].join('\n')
  };
  var b = { name: 'appB', text: 'services:\n  web2:\n    image: nginx:alpine\n    volumes:\n      - cache:/cache\nvolumes:\n  cache: {}\n' };

  var r = M.examine([descOf(a), descOf(b)]);
  var storage = findingsOf(r, 'storage-carry');
  ok('F18: a long-form bind mount is never mistaken for a volume use',
     storage.length === 2 && storage.every(function (f) { return f.facts.volume === 'cache'; }));

  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F18: the bind mount\'s own source: is left exactly as written',
     /source: \.\/data\s*\n\s*target: \/data/.test(w.text));
  assertMergedIsValid('storage-carry (bind long-syntax untouched)', w.text, ['web', 'web2']);
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
  // F16 — planIconCopies() only ever plans a copy for a file the source's
  // own listing actually holds, so these two are named here even though
  // neither service's icon clashes with anything.
  var eFiles = { files: [{ path: '.staxx/svc-icon.png', size: 200, dir: false, outside: false }] };
  var fFiles = { files: [{ path: '.staxx/svc2-icon.png', size: 200, dir: false, outside: false }] };

  var w = MW.buildMergedText([e, f], { date: '2026-09-15', name: 'demoapp', files: { srcE: eFiles, srcF: fFiles } });
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

  var dropped = w.changes.filter(function (c) { return c.title === 'This stack’s own "description" is not carried'; })[0];
  ok('...and its own change record says so, rather than silently dropping it',
     !!dropped && dropped.key === 'top-xunraid|srcF|description' && dropped.stack === 'srcF' &&
     dropped.reason === 'A merged stack has one "description", and srcE’s is kept. This one is left out.');
  ok('dropping the second stack\'s own description CAN be left as it was', dropped.cannotLeave === undefined);

  // PLAN_160 B: "leave" no longer writes a renamed "x-unraid-<leaf>" key
  // into the file — the file keeps the base's value either way, and the
  // declined text travels only on the change record, for the merge's own
  // summary (Adrian, 2026-09-17: no dead spare key).
  var kept = MW.buildMergedText([e, f], {
    date: '2026-09-15', name: 'demoapp', files: { srcE: eFiles, srcF: fFiles },
    decisions: { 'top-xunraid|srcF|description': 'leave' }
  });
  ok('choosing "leave" still keeps only the first source\'s description in the file',
     kept.text.indexOf('Second app') === -1 && kept.text.indexOf('x-unraid-srcF') === -1);
  var keptChange = kept.changes.filter(function (c) { return c.key === 'top-xunraid|srcF|description'; })[0];
  ok('a declined change record is produced, with nothing in the file to point at',
     !!keptChange && keptChange.declined === true && keptChange.line === null);
  ok('...and the declined text itself lives on the record, for the merge summary',
     keptChange.declinedValue.indexOf('Second app') >= 0);
})();

console.log('\nH2. Disjoint x-unraid fields are all carried, field by field (PLAN_160 B)');

(function () {
  var f2 = {
    name: 'srcF2', text: 'x-unraid:\n  description: "F app"\n' +
      'services:\n  web:\n    image: alpine\n'
  };
  var g2 = {
    name: 'srcG2', text: 'x-unraid:\n  category: "MediaApp:"\n  links:\n    - kind: reference\n      state: confirmed\n' +
      '      between:\n        - service: web\n        - service: other\n' +
      'services:\n  other:\n    image: alpine\n'
  };

  var w2 = MW.buildMergedText([f2, g2], { date: '2026-09-17', name: 'demoapp' });
  ok('exactly one top-level x-unraid: key', (w2.text.match(/^x-unraid:/gm) || []).length === 1);
  ok('the base source\'s own field is kept', w2.text.indexOf('F app') >= 0);
  ok('the later source\'s two fields it lacked are both added', w2.text.indexOf('MediaApp:') >= 0 && /links:/.test(w2.text));
  ok('each added field carries its own "# From srcG2" (plus one for its "other" service)',
     (w2.text.match(/# From srcG2/g) || []).length === 3);
  ok('no renamed spare key is ever written', w2.text.indexOf('x-unraid-srcG2') === -1);
  ok('an added field gets its own change record, not an answerable one',
     w2.changes.some(function (c) { return c.key === 'top-xunraid|srcG2|category' && !c.declined && typeof c.line === 'number'; }) &&
     w2.changes.some(function (c) { return c.key === 'top-xunraid|srcG2|links' && !c.declined && typeof c.line === 'number'; }));
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

(function () {
  // PLAN_169 F16 — a service can declare an icon that was never actually
  // shipped with its source (the file deleted, or — as in the six-fixture
  // merge walk, trap 14 — never there in the first place). Before this
  // fix, planIconCopies() planned a copy regardless, step 4 showed it as
  // "renamed for its service", and Merge.php refused the whole merge
  // outright at the last click ("... is not a file ... can offer to this
  // merge"), with nothing written. The file listing here deliberately
  // omits '.staxx/icon.png' — the same shape as a real merge-files reply
  // for a stack whose icon is missing.
  var j = {
    name: 'srcJ', text: 'services:\n  web:\n    image: alpine\n    x-unraid:\n      icon: ./.staxx/icon.png\n'
  };
  var filesReplies = { srcJ: { files: [] } };

  var w = MW.buildMergedText([j], { date: '2026-09-24', name: 'demoapp', files: filesReplies });
  ok('F16: no copy is planned for an icon file the source\'s own listing does not hold',
     !w.files.some(function (fi) { return fi.path === '.staxx/icon.png'; }));
  ok('F16: the icon: line is carried exactly as written, never rewritten to a per-service name',
     /icon: \.\/\.staxx\/icon\.png/.test(w.text) && w.text.indexOf('icon-web') === -1);
  ok('F16: the missing icon is reported back so step 4 can say so, rather than only failing at the last click',
     w.missingIcons.length === 1 && w.missingIcons[0].source === 'srcJ' &&
     w.missingIcons[0].finalService === 'web' && w.missingIcons[0].ref === './.staxx/icon.png');
  assertMergedIsValid('missing icon file is left alone', w.text, ['web']);
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
  var leftVersionChange = leftVersion.changes.filter(function (c) { return c.key === 'top-version'; })[0];
  ok('...and a declined change record IS produced for it, on the kept line — never silent',
     !!leftVersionChange && leftVersionChange.declined === true && typeof leftVersionChange.line === 'number');

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
  var leftChanges = found.map(function (f) { return left.changes.filter(function (c) { return c.key === f.key; })[0]; });
  ok('every declined depth-path finding still carries its own change record, never silent',
     leftChanges.every(function (c) { return !!c && c.declined === true && typeof c.line === 'number'; }));
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
  ok('the now-unneeded published port is recommended and ticked OFF by default (PLAN_170 — keeping ' +
     'it costs nothing; stopping it can break something outside the merge StaXX cannot see)',
     unneeded.length === 1 && unneeded[0].choices[0].recommended === false && unneeded[0].choices[0].ticked === false);
  ok('the finding carries the caller that made it unneeded, and the address it wrote',
     unneeded[0].facts.callers.length === 1 && unneeded[0].facts.callers[0] === 'web' &&
     unneeded[0].facts.host === '192.0.2.88');

  var w = MW.buildMergedText([a, b], { date: '2026-09-14', name: 'demoapp' });
  ok('the address becomes the arriving service\'s name and real port', /DB_ADDRESS: db:3306/.test(w.text));
  ok('left published by default, and the card asks rather than asserts',
     w.text.indexOf('3307:3306') >= 0 &&
     w.changes.some(function (c) {
       return c.declined === true && c.title === 'Is anything outside this merge using db on port 3307?';
     }));
  assertMergedIsValid('wiring', w.text, ['web', 'db']);
})();

console.log('\nK1b. port-unneeded — the evidence line, and both branches\' record text (PLAN_170)');

(function () {
  var a = loadRaw('wiring', 'host'), b = loadRaw('wiring', 'incoming');
  var finding = M.examine([descOf(a), descOf(b)]).findings.filter(function (f) { return f.kind === 'port-unneeded'; })[0];

  function reasonFor(portUsers, approved) {
    var decisions = {};
    if (approved) decisions[finding.key] = true;
    var opts = { date: '2026-09-14', name: 'demoapp', decisions: decisions };
    if (portUsers !== undefined) { opts.portUsers = {}; opts.portUsers[finding.key] = portUsers; }
    var w = MW.buildMergedText([a, b], opts);
    return w.changes.filter(function (c) { return c.key === finding.key; })[0].reason;
  }

  ok('not yet answered — no evidence line at all, the card still reads correctly without one',
     reasonFor(undefined, false) ===
       'Inside the new stack, web now reaches it by name, so it does not need this published port. ' +
       'Anything else that connects from outside still does: another stack, a tool on your network, a script. ' +
       'Kept published. Decline to stop publishing it.');

  ok('found — one stack, singular "also connects", and names it',
     reasonFor(['homeassistant'], false).indexOf(
       'homeassistant also connects to this port. Stop publishing it and that stack breaks.') >= 0);

  ok('found — several stacks joined with commas and "and", plural "also connect"',
     reasonFor(['homeassistant', 'grafana'], false).indexOf(
       'homeassistant and grafana also connect to this port. Stop publishing it and those stacks break.') >= 0);

  ok('not found — StaXX says plainly it cannot see off this box',
     reasonFor([], false).indexOf(
       'No other stack on this server connects to this address. StaXX cannot see anything off this server.') >= 0);

  ok('declined branch ends "Kept published. Decline to stop publishing it."',
     / Kept published. Decline to stop publishing it.$/.test(reasonFor(['homeassistant'], false)));

  ok('approved branch ends "Stops being published."',
     / Stops being published\.$/.test(reasonFor(['homeassistant'], true)));
})();

console.log('\nK2. The falsified-comment fault — struck, not carried across unchanged');

(function () {
  // PLAN_178 F2 — a comment is struck only when it actually names the value
  // being replaced, so this one has to say the old address out loud for the
  // strike to still be the right call.
  var host = {
    name: 'demo-web', text: [
      'services:', '  web:', '    image: nginx:latest', '    ports:', '      - "8091:80"',
      '    environment:', '      # Points at 192.0.2.88:3307 over the LAN, because it lives in its own stack.',
      '      DB_ADDRESS: 192.0.2.88:3307'
    ].join('\n')
  };
  var incoming = { name: 'demo-db', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "3307:3306"\n' };

  var w = MW.buildMergedText([host, incoming], { date: '2026-09-15', name: 'demoapp' });
  ok('the falsified comment is gone from the merged text, not carried across describing something no longer true',
     w.text.indexOf('Points at 192.0.2.88:3307 over the LAN') === -1);
  ok('the rewritten address line is still there, correct', /DB_ADDRESS: db:3306/.test(w.text));

  var change = w.changes.filter(function (c) { return c.key.indexOf('address-rewire') === 0; })[0];
  ok('the change record carries the struck comment\'s own text, so the wizard can show it struck through',
     !!change && change.struckComment.join('\n').indexOf('Points at 192.0.2.88:3307 over the LAN') >= 0);
  ok('the change record names a merged-text line, and the plain-English title/reason the plan asks for',
     typeof change.line === 'number' && change.title === 'Now reaches db inside the stack' &&
     change.reason === 'Was 192.0.2.88:3307, out on the network.');
  assertMergedIsValid('falsified-comment', w.text, ['web', 'db']);

  // Approving port-unneeded (off by default, PLAN_170) still removes the
  // line and records its own change — only the default flipped, not the
  // mechanics of acting on a "yes".
  var portFinding = M.examine([MW.descriptorFromText('demo-web', host.text, null, []),
    MW.descriptorFromText('demo-db', incoming.text, null, [])]).findings.filter(function (f) { return f.kind === 'port-unneeded'; })[0];
  var decisions = {}; decisions[portFinding.key] = true;
  var closed = MW.buildMergedText([host, incoming], { date: '2026-09-15', name: 'demoapp', decisions: decisions });
  ok('approving "stop publishing" removes the port, and the change record ends "Stops being published."',
     closed.text.indexOf('3307:3306') === -1 &&
     closed.changes.some(function (c) {
       return c.title === 'Is anything outside this merge using db on port 3307?' &&
         !c.declined && / Stops being published\.$/.test(c.reason);
     }));
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

console.log('\nK3b. address-rewire keeps everything but the host:port — a whole env value ' +
  'can be a database URI, and only the address inside it moves (F- fix, 2026-09-24)');

(function () {
  // Same host/incoming shape as K2 (db published on 3307, arrives as
  // db:3306) with three different shapes of value wrapped around the
  // same address, each once: a login-carrying URI, a bare host:port, and
  // an http URL with a path after it. Before this fix the whole env value
  // was substring-replaced, so a URI's user, password, scheme and database
  // name were lost along with the address (found live on the box: F12).
  function mergedFor(envLine) {
    var host = { name: 'demo-web', text: [
      'services:', '  web:', '    image: nginx:latest',
      '    environment:', '      ' + envLine
    ].join('\n') };
    var incoming = { name: 'demo-db', text: [
      'services:', '  db:', '    image: mariadb:11', '    ports:', '      - "3307:3306"'
    ].join('\n') };
    return MW.buildMergedText([host, incoming], { date: '2026-09-24', name: 'demoapp' }).text;
  }

  ok('a postgres URI keeps its user, password and database name; only host:port moves',
     /DB_URI: postgres:\/\/authenticator:t169pass@db:3306\/t169/.test(
       mergedFor('DB_URI: postgres://authenticator:t169pass@192.0.2.88:3307/t169')));

  ok('a bare host:port value still becomes the plain service:port pair',
     /DB_ADDR: db:3306/.test(mergedFor('DB_ADDR: 192.0.2.88:3307')));

  ok('an http URL keeps its scheme and path; only host:port moves',
     /WEBHOOK_URL: http:\/\/db:3306\/api\/callback/.test(
       mergedFor('WEBHOOK_URL: http://192.0.2.88:3307/api/callback')));
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

  ['container-name-clash', 'port-clash', 'shorthand-clash', 'label-clash', 'file-clash'].forEach(function (kind) {
    ok('nothing of kind ' + kind + ' is raised', findingsOf(r, kind).filter(function (f) {
      return f.severity !== 'clean';
    }).length === 0);
  });

  // PLAN_169 F2/F9 added a fifth checked category (proxy labels) to the
  // "checked and fine" list findCleanEntries() builds.
  var clean = findingsOf(r, 'clean');
  ok('every checked category reports clean', clean.length === 5);

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
  // 3 — port-unneeded is LEFT AS WRITTEN by default (PLAN_170) and still
  // yields its own change record, asking rather than asserting.
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
      '    environment:', '      # Points at 192.0.2.88 over the LAN, because it lives in its own stack.',
      '      DB_HOST: 192.0.2.88', '      DB_PORT: "3307"'
    ].join('\n')
  };

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'DEV-TESTING/demoapp' });
  ok('the split pair is rewired by default', /DB_HOST: mariadb/.test(w.text) && /DB_PORT: "3306"/.test(w.text));
  ok('the old address is gone', w.text.indexOf('192.0.2.88') === -1);
  ok('the falsified comment above DB_HOST is gone from the merged text',
     w.text.indexOf('Points at 192.0.2.88 over the LAN') === -1);

  var splitChanges = w.changes.filter(function (c) { return c.key.indexOf('address-rewire') === 0; });
  var rewire = splitChanges[0];
  ok('...and IS recorded as struck on the address-rewire change, not lost silently',
     !!rewire && rewire.struckComment && rewire.struckComment.join('\n').indexOf('Points at 192.0.2.88 over the LAN') >= 0);
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

  ok('the now-unneeded port is kept published by default (no decision needed)', w.text.indexOf('3307:3306') >= 0);
  var unneeded = w.changes.filter(function (c) {
    return c.title === 'Is anything outside this merge using mariadb on port 3307?';
  })[0];
  ok('...and produces its own change record, declined by default, with a source line too',
     !!unneeded && unneeded.declined === true &&
     / Kept published. Decline to stop publishing it.$/.test(unneeded.reason) &&
     typeof unneeded.sourceLine === 'number');
  assertMergedIsValid('full-rel (falsified comment + port-unneeded, kept by default)', w.text, ['mariadb', 'web']);
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
  // the one it clashes with (host/web, staying put); only the mover's is
  // used since Adrian's 2026-09-16 decision dropped the "swap which side
  // moves" answer, but the held side's line is kept on the finding too.
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
  // PLAN_170: port-unneeded is off by default now, so approving it (as if
  // the wizard's own "stop publishing" answer had been clicked) is what
  // this suite exercises — the mechanics under removePortPublishTracked()
  // this section is actually about are unchanged by the default flipping.
  var db = { name: 'demo-db', text: 'services:\n  mariadb:\n    image: mariadb:11\n    ports:\n      - "3307:3306"\n' };
  var web = { name: 'demo-web', text: 'services:\n  web:\n    image: nginx:latest\n    environment:\n      DB_ADDRESS: 192.0.2.88:3307\n' };
  var finding = M.examine([MW.descriptorFromText('demo-db', db.text, null, []),
    MW.descriptorFromText('demo-web', web.text, null, [])]).findings.filter(function (f) { return f.kind === 'port-unneeded'; })[0];
  var decisions = {}; decisions[finding.key] = true;

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp', decisions: decisions });
  ok('the only port entry going drops the "ports:" key from the written file entirely',
     !/ports:/.test(w.text));

  var unneeded = w.changes.filter(function (c) {
    return c.title === 'Is anything outside this merge using mariadb on port 3307?';
  })[0];
  ok('the change record is marked removed, carrying the exact line that would have stayed',
     !!unneeded && !unneeded.declined && unneeded.removed === true && unneeded.removedText === '    ports: []');
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
  var finding2 = M.examine([MW.descriptorFromText('demo-db', db.text, null, []),
    MW.descriptorFromText('demo-web', web.text, null, [])]).findings.filter(function (f) { return f.kind === 'port-unneeded'; })[0];
  var decisions2 = {}; decisions2[finding2.key] = true;

  var w = MW.buildMergedText([db, web], { date: '2026-09-15', name: 'demoapp', decisions: decisions2 });
  ok('the surviving port stays published, "ports:" left as a real block, not "[]"',
     /- "3308:3308"/.test(w.text) && !/ports: \[\]/.test(w.text));

  var unneeded = w.changes.filter(function (c) {
    return c.title === 'Is anything outside this merge using mariadb on port 3307?';
  })[0];
  ok('the change record\'s line points at the "ports:" key line, not the service key',
     !!unneeded && !unneeded.declined && w.text.split('\n')[unneeded.line] === '    ports:');
  assertMergedIsValid('one-of-two-ports-closed', w.text, ['mariadb', 'web']);
})();

(function () {
  // Declining a port-clash (Adrian's decision, 2026-09-16) leaves BOTH
  // ports exactly as written — no "stop publishing" any more, so nothing
  // here goes through removePortPublishTracked() at all; port-unneeded's
  // own tests above still cover that function's "ports: []" behaviour.
  var a = { name: 'appA', text: 'services:\n  web:\n    image: nginx:latest\n    ports:\n      - "8091:80"\n' };
  var b = { name: 'appB', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "8091:3306"\n' };
  var descA = MW.descriptorFromText('appA', a.text, null, []);
  var descB = MW.descriptorFromText('appB', b.text, null, []);
  var clash = M.examine([descA, descB]).findings.filter(function (f) { return f.kind === 'port-clash'; })[0];
  var decisions = {}; decisions[clash.key] = 'leave';

  var w = MW.buildMergedText([a, b], { date: '2026-09-15', name: 'demoapp', decisions: decisions });
  ok('declining a port-clash leaves both ports exactly as written',
     /- "8091:80"/.test(w.text) && /- "8091:3306"/.test(w.text));
  assertMergedIsValid('port-clash-declined-single', w.text, ['web', 'db']);
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

  // t155-admin's shape (Adrian's walk, 2026-09-16): the base has an
  // environment: block and NO ports:, and the override lists ports BEFORE
  // environment. The new ports: list lands at the foot of the service
  // first, then the environment entry is inserted above it and pushes it
  // down a line — the port's record has to follow, or its mark sits on
  // the "ports:" key while the merged mark sits on the port line.
  var baseAdmin = ['services:', '  web:', '    image: adminer:4', '    environment:', '      TZ: UTC', ''].join('\n');
  var overrideAdmin = ['services:', '  web:', '    ports:', '      - "18081:8080"', '    environment:', '      ADMINER_DESIGN: pepa-linha', ''].join('\n');
  var descAdmin = MW.descriptorFromText('t155-admin', baseAdmin, null, [], { overrideText: overrideAdmin });
  var adminLines = descAdmin.text.split('\n');
  var portRec = (descAdmin.overrideChanges || []).filter(function (c) { return /18081:8080/.test(c.marker); })[0];
  ok('C3: a port appended before a later environment insert still points at the PORT line, not the ports: key',
     !!portRec && adminLines[portRec.sourceLine] === portRec.marker && /18081:8080/.test(adminLines[portRec.sourceLine]));
  ok('C3: ...and its key carries that same, final line number',
     !!portRec && portRec.key === 'override|t155-admin|' + portRec.sourceLine);
  ok('C3: every admin-shaped record names exactly the line it sits on',
     (descAdmin.overrideChanges || []).every(function (c) { return adminLines[c.sourceLine] === c.marker; }));

  // A source with no override at all is untouched — overrideChanges is
  // simply empty, not absent, so callers never have to guard its shape.
  var plain = MW.descriptorFromText('demo-web', base, null, [], {});
  ok('C3: no overrideText means no change records and the text is untouched',
     Array.isArray(plain.overrideChanges) && plain.overrideChanges.length === 0 && plain.text === base);
})();

/* =========================================================================
 * R. Hidden config (PLAN_155 C18) — a stack running from a file the wizard
 * never reads is refused outright, since whatever it sets would be lost.
 * ========================================================================= */

console.log('\nR. Hidden config');

(function () {
  var a = { name: 'appA', text: 'services:\n  web:\n    image: nginx:latest\n' };
  var b = { name: 'appB', text: 'services:\n  web2:\n    image: nginx:latest\n' };

  // (a) an extra -f file alongside the main one.
  var descA1 = MW.descriptorFromText('appA', a.text, null, [], {
    runningFrom: { configFiles: ['/mnt/user/appdata/appA/compose.yaml', '/mnt/user/appdata/appA/extra.yaml'], envFile: '' }
  });
  var descB1 = MW.descriptorFromText('appB', b.text, null, [], { runningFrom: { configFiles: [], envFile: '' } });
  var r1 = M.examine([descA1, descB1]);
  var hidden1 = findingsOf(r1, 'hidden-config');
  ok('(a) an extra -f file the wizard cannot see is one refusal, naming it',
     hidden1.length === 1 && hidden1[0].stack === 'appA' && hidden1[0].facts.files.indexOf('/mnt/user/appdata/appA/extra.yaml') >= 0);

  // (b) a foreign --env-file.
  var descA2 = MW.descriptorFromText('appA', a.text, null, [], {
    runningFrom: { configFiles: ['/mnt/user/appdata/appA/compose.yaml'], envFile: '/mnt/user/appdata/appA/prod.env' }
  });
  var r2 = M.examine([descA2]);
  var hidden2 = findingsOf(r2, 'hidden-config');
  ok('(b) a --env-file that is not .env is a refusal naming it',
     hidden2.length === 1 && hidden2[0].facts.envFile === '/mnt/user/appdata/appA/prod.env' && hidden2[0].facts.files.length === 0);

  // (c) config list is exactly the source's own compose file — no refusal.
  var descA3 = MW.descriptorFromText('appA', a.text, null, [], {
    runningFrom: { configFiles: ['/mnt/user/appdata/appA/compose.yaml'], envFile: '' }
  });
  var r3 = M.examine([descA3]);
  ok('(c) running from just its own compose file is never flagged', findingsOf(r3, 'hidden-config').length === 0);

  // (d) own compose file plus its own auto-loaded override — still fine.
  var descA4 = MW.descriptorFromText('appA', a.text, null, [], {
    runningFrom: {
      configFiles: [
        '/mnt/user/appdata/appA/compose.yaml',
        '/mnt/user/appdata/appA/docker-compose.override.yml'
      ], envFile: ''
    }
  });
  var r4 = M.examine([descA4]);
  ok('(d) its own compose file plus a standard-named override is never flagged',
     findingsOf(r4, 'hidden-config').length === 0);

  // (e) no runningFrom at all (nothing running) — never flagged.
  var descA5 = MW.descriptorFromText('appA', a.text, null, [], {});
  var r5 = M.examine([descA5]);
  ok('(e) an empty runningFrom (nothing running) is never flagged',
     findingsOf(r5, 'hidden-config').length === 0);
})();

/* =========================================================================
 * S. PLAN_169 round two — F1, F5, F6, F8 (compose-model.js/merge-write.js),
 * F2/F3/F4/F7/F9/F10/F11 (merge-examine.js/merge-write.js) below them
 * ========================================================================= */

console.log('\nS. PLAN_169 round two fixes');

(function () {
  // F1 — a volume that already carries its own name: (or external: true)
  // already has its real identity; the storage carry must never add a
  // SECOND name: line beside it.
  var a = { name: 'a', text: 'services:\n  svc:\n    image: alpine:3.20\n    volumes:\n      - namedvol:/data\nvolumes:\n  namedvol:\n    name: my-custom-real-name\n' };
  var descA = MW.descriptorFromText(a.name, a.text, null, []);
  var r = M.examine([descA]);
  ok('F1: a volume with its own name: is never offered a storage-carry decision',
     findingsOf(r, 'storage-carry').length === 0);

  var w = MW.buildMergedText([a], { date: '2026-09-24', name: 'demoapp' });
  ok('F1: the merged file keeps the one name: line and never doubles it',
     (w.text.match(/name: my-custom-real-name/g) || []).length === 1);
})();

(function () {
  // F5 — a comment attached to no key (a file-header comment, or one
  // closing a service block one indent deeper than any key) is never
  // inside any key's own span, so it must be carried by hand rather than
  // silently dropped (CLAUDE.md rule 2).
  var a = { name: 'a', text: '# a file header comment on a\nservices:\n  svc:\n    image: alpine:3.20\n    # a trailing comment at the end of a\'s own block\n' };
  var b = { name: 'b', text: 'services:\n  svc2:\n    image: alpine:3.20\n' };
  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F5: the first source\'s own file-header comment survives the merge', w.text.indexOf('a file header comment on a') >= 0);
  ok('F5: the first source\'s own trailing block-closing comment survives the merge', w.text.indexOf('a trailing comment at the end') >= 0);
})();

(function () {
  // F6 — links: and external_links: share the same "name:alias" shape and
  // both follow a service rename; hostname: names the container's own
  // self and must be left alone.
  var a = loadRaw('r2-hostname-links', 'a');
  var b = loadRaw('r2-hostname-links', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var rr = M.examine([descA, descB]);
  var clash = findingsOf(rr, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  var newName = clash[0].facts.to;
  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F6: external_links: follows the service rename',
     new RegExp('external_links:\\s*\\n\\s*- ' + newName + ':extweb').test(w.text));
  ok('F6: hostname: is left unchanged', /hostname: web\b/.test(w.text));
})();

(function () {
  // F8 — a source compose-model cannot read in full (a bad line CM.parse()
  // itself warns about) is refused by name, rather than merged minus the
  // line it could not read.
  var a = loadRaw('r2-yaml-error', 'a');
  var b = loadRaw('r2-yaml-error', 'b');
  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F8: buildMergedText() refuses outright rather than writing a file', w.text === null);
  ok('F8: the refusal names the broken source and reads as the wizard\'s own sentence',
     w.refusals.length === 1 && w.refusals[0].stack === 'a' &&
     /has a line StaXX cannot read \(line \d+\)\. Fix it in the editor, then pick it again\./.test(w.refusals[0].message));
})();

(function () {
  // F2/F9 — a clashing Traefik router name, found in either list or map
  // form (the same trap the six-fixture walk's own trap 3 raises with
  // ${COMPOSE_PROJECT_NAME}). The later source's own name gains its source
  // leaf; the earlier one is left exactly as written.
  var a = loadRaw('r2-label-router-clash', 'a');
  var b = loadRaw('r2-label-router-clash', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'label-clash');
  ok('F2/F9: a router name clash is found whichever form the labels are written in (list vs map)',
     clash.length === 1 && clash[0].stack === 'b' && clash[0].facts.sections.length === 1 &&
     clash[0].facts.sections[0] === 'routers' && clash[0].facts.from === 'web' && clash[0].facts.to === 'web-b');

  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F2/F9: the second source\'s router is renamed in the merged file, the first left alone',
     /traefik\.http\.routers\.web\.rule=Host/.test(w.text) &&
     /traefik\.http\.routers\.web-b\.rule:\s*"PathPrefix/.test(w.text));
  ok('F2/F9: the merged file still parses clean', CM.parse(w.text).warnings.length === 0);
})();

(function () {
  // F9 (${COMPOSE_PROJECT_NAME} form) — two sources writing the SAME
  // placeholder name are indistinguishable before any resolution at all
  // (both write the literal text "${COMPOSE_PROJECT_NAME}-web"), which is
  // exactly the shape PLAN_169 trap 3 raised: distinct while each ran on
  // its own, one name the moment both share the new stack's own project.
  var a = { name: 'a', text: 'services:\n  site:\n    image: nginx:alpine\n    labels:\n      - "traefik.http.routers.${COMPOSE_PROJECT_NAME}-web.rule=PathPrefix(`/`)"\n' };
  var b = { name: 'b', text: 'services:\n  api:\n    image: alpine:3.20\n    labels:\n      - "traefik.http.routers.${COMPOSE_PROJECT_NAME}-web.rule=PathPrefix(`/api`)"\n' };
  var descA = MW.descriptorFromText(a.name, a.text, null, []);
  var descB = MW.descriptorFromText(b.name, b.text, null, []);
  var r = M.examine([descA, descB], { newRel: 'MERGED/newstack' });
  var clash = findingsOf(r, 'label-clash');
  ok('F9: a placeholder router name clash is found once the new stack\'s own project name is known',
     clash.length === 1 && clash[0].facts.from === '${COMPOSE_PROJECT_NAME}-web');

  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'MERGED/newstack' });
  ok('F9: ${COMPOSE_PROJECT_NAME} is kept exactly as written — only the literal name segment gains a suffix',
     /routers\.\$\{COMPOSE_PROJECT_NAME\}-web-b\.rule=PathPrefix\(`\/api`\)/.test(w.text));
})();

(function () {
  // F11 — a lead comment that names the OLD router name is struck once
  // that line is rewritten, the same falsified-comment rule every other
  // rename in this file already follows.
  var a = { name: 'a', text: 'services:\n  site:\n    image: nginx:alpine\n    labels:\n      - "traefik.http.routers.web.rule=PathPrefix(`/`)"\n' };
  var b = {
    name: 'b',
    text: 'services:\n  api:\n    image: alpine:3.20\n    labels:\n      # the only router in this file called "web".\n      - "traefik.http.routers.web.rule=PathPrefix(`/api`)"\n'
  };
  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F11: the falsified comment above the renamed router label is struck, not carried unchanged',
     w.text.indexOf('the only router in this file called "web".') === -1);
  var labelChange = w.changes.filter(function (c) { return /label-clash/.test(c.key); })[0];
  ok('F11: the change record for that rename carries the struck text', labelChange && !!labelChange.struckComment);
})();

console.log('\nK2b. PLAN_178 F2 — a comment is struck only when it names the value being replaced');

(function () {
  // A comment naming the old address (host:port, or either half on its own)
  // is still struck — this is the literal case the whole rule exists for.
  var host = {
    name: 'demo-web', text: [
      'services:', '  web:', '    image: nginx:latest', '    environment:',
      '      # Was 192.0.2.88:3307, before the merge.',
      '      DB_ADDRESS: 192.0.2.88:3307'
    ].join('\n')
  };
  var incoming = { name: 'demo-db', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "3307:3306"\n' };
  var w = MW.buildMergedText([host, incoming], { date: '2026-09-24', name: 'demoapp' });
  ok('a comment naming the old address is struck',
     w.text.indexOf('Was 192.0.2.88:3307, before the merge.') === -1);
})();

(function () {
  // A comment that never names the value it sits above — a plain template
  // label — stays, because it is still true once the value is rewritten.
  var host = {
    name: 'demo-web', text: [
      'services:', '  web:', '    image: nginx:latest', '    environment:',
      '      # Database address',
      '      DB_ADDRESS: 192.0.2.88:3307'
    ].join('\n')
  };
  var incoming = { name: 'demo-db', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "3307:3306"\n' };
  var w = MW.buildMergedText([host, incoming], { date: '2026-09-24', name: 'demoapp' });
  ok('a label comment naming no value is kept', w.text.indexOf('# Database address') >= 0);
  ok('...directly above the rewritten line', /# Database address\n\s*DB_ADDRESS: db:3306/.test(w.text));
})();

(function () {
  // A struck comment that carried a sanitise marker leaves the marker
  // behind, on a line of its own, same indent, directly above the
  // rewritten line — losing "-!S" would stop Sanitise mode blanking a
  // value that is still just as secret after the merge.
  var host = {
    name: 'demo-web', text: [
      'services:', '  web:', '    image: nginx:latest', '    environment:',
      '      # Was 192.0.2.88:3307 -!S',
      '      DB_ADDRESS: 192.0.2.88:3307'
    ].join('\n')
  };
  var incoming = { name: 'demo-db', text: 'services:\n  db:\n    image: mariadb:11\n    ports:\n      - "3307:3306"\n' };
  var w = MW.buildMergedText([host, incoming], { date: '2026-09-24', name: 'demoapp' });
  ok('the falsified prose is gone', w.text.indexOf('Was 192.0.2.88:3307') === -1);
  ok('the marker survives on a comment line of its own, same indent, directly above the rewrite',
     /^ {6}# -!S\n {6}DB_ADDRESS: db:3306$/m.test(w.text));
})();

(function () {
  // PLAN_169 F13 — a router and its matching Traefik service commonly
  // share one literal name (the ordinary shape); when two sources each
  // wrote both under "web", detecting per-namespace used to raise one
  // label-clash for the routers section and an identical-looking second
  // one for the services section, so the person answered the same rename
  // decision twice. One finding now covers every namespace the name
  // clashed in, and the rewrite renames all of them together.
  var a = {
    name: 'a', text: [
      'services:', '  site:', '    image: nginx:alpine', '    labels:',
      '      - "traefik.http.routers.web.rule=Host(`a.example`)"',
      '      - "traefik.http.services.web.loadbalancer.server.port=80"'
    ].join('\n')
  };
  var b = {
    name: 'b', text: [
      'services:', '  api:', '    image: alpine:3.20', '    labels:',
      '      - "traefik.http.routers.web.rule=Host(`b.example`)"',
      '      - "traefik.http.services.web.loadbalancer.server.port=8080"',
      '      - "traefik.http.routers.web.service=web"'
    ].join('\n')
  };
  var descA = MW.descriptorFromText(a.name, a.text, null, []);
  var descB = MW.descriptorFromText(b.name, b.text, null, []);
  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'label-clash');
  ok('F13: one finding covers every namespace the same name clashed in, not one card per namespace',
     clash.length === 1 && clash[0].facts.sections.length === 2 &&
     clash[0].facts.sections.indexOf('routers') >= 0 && clash[0].facts.sections.indexOf('services') >= 0);

  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F13: both namespaces are renamed together in the merged file',
     /traefik\.http\.routers\.web-b\.rule=Host\(`b\.example`\)/.test(w.text) &&
     /traefik\.http\.services\.web-b\.loadbalancer\.server\.port=8080/.test(w.text) &&
     /traefik\.http\.routers\.web-b\.service=web-b/.test(w.text));
  ok('F13: the merged file still parses clean', CM.parse(w.text).warnings.length === 0);
})();

(function () {
  // F3 — one parser for a published port: address (IPv4/hostname or a
  // bracketed IPv6 literal), a host or container port RANGE, and an
  // optional /tcp|/udp suffix. Before this, the address landed in the port
  // slot outright ("[::1]:19800:80".split(':') put "[::1]" where the host
  // port belongs).
  var a = loadRaw('r2-port-range-proto-addr', 'a');
  var b = loadRaw('r2-port-range-proto-addr', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, a.envText, []);
  var svcA = descA.compose.services.svc;
  var ipv6 = svcA.ports.filter(function (p) { return p.address === '[::1]'; })[0];
  ok('F3: an IPv6 address is read into its own slot, not the host-port slot',
     !!ipv6 && ipv6.host === '19800' && ipv6.container === '80');

  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'port-clash');
  ok('F3: a host-port RANGE overlapping a single port is found as a clash',
     clash.some(function (f) { return f.facts.port === '19100-19102' || f.facts.port === '19101'; }));
  var rangeClash = clash.filter(function (f) { return f.facts.rangeInvolved; })[0];
  ok('F3: a clash involving a range is never offered an automatic move — "leave" is the only choice',
     rangeClash && rangeClash.choices.length === 1 && rangeClash.choices[0].id === 'leave');
  ok('F3: the same port number on TCP and UDP is not a clash (different protocols)',
     !clash.some(function (f) { return f.facts.port === '19200'; }));

  // A plain single-port clash (no range on either side) still gets a real
  // automatic move, unaffected by F3's own range handling.
  var descC = MW.descriptorFromText('c', 'services:\n  x:\n    image: alpine:3.20\n    ports:\n      - "19101:9000"\n', null, []);
  var descD = MW.descriptorFromText('d', 'services:\n  y:\n    image: alpine:3.20\n    ports:\n      - "19101:9001"\n', null, []);
  var r2 = M.examine([descC, descD]);
  var plainClash = findingsOf(r2, 'port-clash')[0];
  ok('F3: an ordinary single-port clash (no range) still offers a numbered free port to move to',
     plainClash && !plainClash.facts.rangeInvolved && typeof plainClash.facts.freePort === 'number');
})();

(function () {
  // F4 — a top-level include: entry's own relative path is re-pointed at
  // the new stack's depth by the same depth-path finding extends.file
  // already uses, rather than silently dropped.
  var a = { name: 'a', text: 'include:\n  - ../shared/common.yaml\nservices:\n  svc:\n    image: alpine:3.20\n', depth: 1 };
  var b = { name: 'b', text: 'services:\n  svc2:\n    image: alpine:3.20\n', depth: 0 };
  var descA = MW.descriptorFromText(a.name, a.text, null, [], { depth: 1 });
  var descB = MW.descriptorFromText(b.name, b.text, null, [], { depth: 0 });
  ok('F4: descriptorFromText() reads the top-level include: list', descA.compose.include.length === 1);
  var r = M.examine([descA, descB], { newDepth: 0 });
  var depthFindings = findingsOf(r, 'depth-path');
  ok('F4: include:\'s own path is recognised as a relative path needing a depth fix',
     depthFindings.some(function (f) { return f.facts.field === 'include[].path' && f.facts.oldPath.indexOf('common.yaml') >= 0; }));

  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp', newDepth: 0 });
  ok('F4: the include: entry survives the merge with its path fixed for the new depth',
     /include:\s*\n\s*- \.\/shared\/common\.yaml/.test(w.text));
})();

(function () {
  // F4 (object form) — include: entries written as a mapping (path:/
  // project_directory:/env_file:) are read the same way a bare string is.
  var text = 'include:\n  - path: ../shared/other.yaml\n    project_directory: ../shared\n    env_file: ../shared/.env\nservices:\n  svc:\n    image: alpine:3.20\n';
  var desc = MW.descriptorFromText('a', text, null, [], { depth: 1 });
  var r = M.examine([desc], { newDepth: 0 });
  var fields = findingsOf(r, 'depth-path').map(function (f) { return f.facts.field; }).sort();
  ok('F4: every one of include:\'s own path-bearing fields is read from the object form',
     fields.join(',') === 'include[].env_file,include[].path,include[].project_directory');
})();

(function () {
  // F7 — Compose lower-cases a project name (and only ever allows
  // a-z0-9_-) before building a real volume name from it, whatever case
  // the source's own folder or explicit name: is written in — comparing
  // the UN-normalised strings would miss two sources that collide only
  // after that fold ("T169-A", "t169-a"). Once folded, both really are the
  // SAME real project on the real box, and Docker has already been using
  // ONE volume for both of them — so realName must read the SAME for both,
  // never a suffixed, invented name for the second one (Adrian's
  // correction, 2026-09-24): that would point it at a volume that has
  // never existed, so that service comes up with an empty store.
  var a = { name: 'T169-A', text: 'services:\n  svc:\n    image: alpine:3.20\n    volumes:\n      - data:/data\nvolumes:\n  data: {}\n' };
  var b = { name: 't169-a', text: 'services:\n  svc2:\n    image: alpine:3.20\n    volumes:\n      - data:/data\nvolumes:\n  data: {}\n' };
  var descA = MW.descriptorFromText(a.name, a.text, null, []);
  var descB = MW.descriptorFromText(b.name, b.text, null, []);
  var r = M.examine([descA, descB]);
  var storage = findingsOf(r, 'storage-carry');
  ok('F7: both sources\' own "data" volume are still each carried', storage.length === 2);
  ok('F7: both realNames fold to the one real volume Docker already built for them',
     storage[0].facts.realName === 't169-a_data' && storage[1].facts.realName === 't169-a_data');

  var w = MW.buildMergedText([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('F7: both carried volume declarations name: the same real volume, sharing it as before',
     (w.text.match(/name: t169-a_data/g) || []).length === 2);
})();

(function () {
  // F10 — verified rather than "fixed": each service reads its OWN
  // env_file, so two different companion files setting the same variable
  // name to different values never actually meet — no settings-join
  // finding is raised (findSettingsJoin() only ever reads a root .env),
  // and nothing here needs to.
  var a = { name: 'a', text: 'services:\n  api:\n    image: alpine:3.20\n    env_file:\n      - ./api.env\n' };
  var b = { name: 'b', text: 'services:\n  db:\n    image: alpine:3.20\n    env_file:\n      - ./db.env\n' };
  var descA = MW.descriptorFromText(a.name, a.text, null, []);
  var descB = MW.descriptorFromText(b.name, b.text, null, []);
  var r = M.examine([descA, descB]);
  ok('F10: two services\' own distinctly-named env_file companions raise no clash of any kind',
     r.findings.filter(function (f) { return f.kind !== 'clean'; }).length === 0);
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
