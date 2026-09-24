/* StaXX — round two off-box probes for the merge wizard (PLAN_169).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_trip.js
 *
 * No framework, no npm, no network — same shape as tests/merge_examine.js:
 * one "ok"/"FAIL" line per case, non-zero exit if anything fails.
 *
 * These are rows R2-1 to R2-19, plus the text half of R2-23, from
 * PLAN_169's "Round two: more ways to trip it" table. Each row gets a
 * small source pair under tests/fixtures/merge-pairs/r2-<name>/, and one
 * or more cases here asserting what the "What the merge should do" column
 * says should happen. A FAIL here is not necessarily a bug in this test —
 * some of these are the merge's own weak points, found on purpose. R2-20
 * to R2-22 are box-only (timing/state) and are not attempted here. R2-16
 * and R2-17's refusals live in include/Merge.php (staxx_merge_write()'s
 * source-count and name-clash checks), not in these JS modules, so they
 * are marked SKIP rather than faked.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var M = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-examine.js');
var MW = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-write.js');
var AUDIT = require('./merge_audit.js');   // PLAN_179 — every difference between the sources and
                                            // buildMergedText()'s own output must be accounted for.

var pass = 0, fail = 0, skip = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// PLAN_179 — every call this file makes to buildMergedText() is audited in the same pass, so a
// silent change slipped into any of these round-two probes fails here too, not only in
// tests/merge_audit_all.js's own separate run of the same fixtures. audit() itself no-ops when
// built.text is null (a refusal), so this is always safe to run, whatever the case is testing.
function auditedBuild(sources, opts) {
  var built = MW.buildMergedText(sources, opts || {});
  var a = AUDIT.audit(sources, built, opts || {});
  ok('audit: every difference from the source(s) is accounted for', a.ok, a.problems.join('\n'));
  return built;
}

function skipCase(name, where) {
  skip++;
  console.log('  SKIP  ' + name + ' (server-only: ' + where + ')');
}

/* =========================================================================
 * Loading a fixture pair as raw text — same shape as merge_examine.js's
 * loadRaw(), reused here rather than reinvented.
 * ========================================================================= */

function loadRaw(fixtureName, side) {
  var dir = path.join(__dirname, 'fixtures', 'merge-pairs', fixtureName, side);
  var text = fs.readFileSync(path.join(dir, 'compose.yaml'));
  var envPath = path.join(dir, '.env');
  var envText = fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : null;
  return { name: side, text: text.toString('utf8'), envText: envText };
}

// Reads the fixture's raw bytes back with no encoding assumption at all,
// for the CRLF/BOM row — .toString('utf8') above would already have
// normalised nothing, but this keeps the byte-for-byte intent explicit
// where it actually matters.
function loadRawBytes(fixtureName, side) {
  var dir = path.join(__dirname, 'fixtures', 'merge-pairs', fixtureName, side);
  return fs.readFileSync(path.join(dir, 'compose.yaml'));
}

function findingsOf(result, kind) {
  return result.findings.filter(function (f) { return f.kind === kind; });
}

/* =========================================================================
 * Every merged file must parse clean through compose-model, and every
 * alias must sit on a line at or after its own anchor's — checked on
 * every case, per the brief.
 * ========================================================================= */

function assertParsesClean(label, composeText) {
  var reparsed = CM.parse(composeText);
  ok(label + ' — parses clean through compose-model, no warnings',
     reparsed.warnings.length === 0,
     reparsed.warnings.map(function (w) { return typeof w === 'string' ? w : JSON.stringify(w); }).join('; '));
  return reparsed;
}

// A crude but sufficient textual check: for every "*name" alias, some
// earlier or same line must declare "&name". Good enough for these small
// fixtures without re-implementing a YAML anchor/alias resolver here.
function assertAliasesBelowAnchors(label, composeText) {
  var lines = composeText.split(/\r?\n/);
  var anchorLine = {};
  lines.forEach(function (line, i) {
    var m = /&([A-Za-z0-9_-]+)/.exec(line);
    if (m && anchorLine[m[1]] === undefined) anchorLine[m[1]] = i;
  });
  var problems = [];
  lines.forEach(function (line, i) {
    var re = /\*([A-Za-z0-9_-]+)/g, m;
    while ((m = re.exec(line))) {
      var name = m[1];
      if (!(name in anchorLine) || anchorLine[name] > i) problems.push(name + '@' + i);
    }
  });
  ok(label + ' — no alias appears above its own anchor', problems.length === 0, problems.join(', '));
}

function assertRoundTrip(label, composeText) {
  var reparsed = assertParsesClean(label, composeText);
  assertAliasesBelowAnchors(label, composeText);
  return reparsed;
}

/* =========================================================================
 * R2-1 — network_mode: "service:vpn" sidecar, and "vpn" is renamed by a
 * name clash. The rewrite must follow, like depends_on.
 * ========================================================================= */

console.log('\nR2-1. network_mode: "service:X" follows a rename');

(function () {
  var a = loadRaw('r2-network-mode-sidecar', 'a');
  var b = loadRaw('r2-network-mode-sidecar', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('the second source\'s "vpn" is the one renamed', clash.length === 1 && clash[0].stack === 'b' && clash[0].facts.from === 'vpn');

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  var newName = clash[0].facts.to;
  ok('the sidecar\'s network_mode: reference follows the rename',
     new RegExp('network_mode: "service:' + newName + '"').test(w.text));
  ok('the old reference is gone', w.text.indexOf('service:vpn"') === -1);
  assertRoundTrip('R2-1', w.text);
})();

/* =========================================================================
 * R2-2 — a named volume external: true, and one with its own name:. Both
 * must be kept exactly: never prefixed, never recreated.
 * ========================================================================= */

console.log('\nR2-2. An external volume, and a volume with its own name:, both left alone');

(function () {
  var a = loadRaw('r2-volume-identity', 'a');
  var b = loadRaw('r2-volume-identity', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  ok('the external volume is never offered a storage-carry decision',
     findingsOf(r, 'storage-carry').filter(function (f) { return f.facts.volume === 'extvol'; }).length === 0);

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('extvol keeps external: true, untouched', /extvol:\s*\n\s*external: true/.test(w.text));
  ok('namedvol keeps its own custom name:, untouched, and is not ALSO given a project-prefixed name: override',
     /namedvol:\s*\n\s*name: my-custom-real-name/.test(w.text) &&
     (w.text.match(/name: my-custom-real-name/g) || []).length === 1 &&
     !/name: \S*_namedvol/.test(w.text));
  assertRoundTrip('R2-2', w.text);
})();

/* =========================================================================
 * R2-3 — two sources declare the same secret name pointing at different
 * files. Treated as a name clash: rename one and every reference to it.
 * ========================================================================= */

console.log('\nR2-3. A secret name clash is renamed like any other declared name');

(function () {
  var a = loadRaw('r2-secret-clash', 'a');
  var b = loadRaw('r2-secret-clash', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'shorthand-clash').filter(function (f) { return f.facts.declKind === 'secrets'; });
  ok('the disagreeing "creds" secret is found and renamed', clash.length === 1 && clash[0].facts.from === 'creds');

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  var newName = clash.length ? clash[0].facts.to : null;
  ok('both secrets end up declared under distinct names, each keeping its own file:',
     newName && new RegExp(newName + ':\\s*\\n\\s*file: \\./secrets/b-creds\\.txt').test(w.text) &&
     /creds:\s*\n\s*file: \.\/secrets\/a-creds\.txt/.test(w.text));
  ok('the second service\'s own secrets: reference follows the rename',
     newName && new RegExp('secrets:\\s*\\n\\s*- ' + newName).test(w.text));
  assertRoundTrip('R2-3', w.text);
})();

/* =========================================================================
 * R2-4 — ${TAG:-1.25} with TAG set differently in each source's .env. The
 * disagreement must be said, and the merged .env must not silently pick.
 * ========================================================================= */

console.log('\nR2-4. Disagreeing .env values behind the same variable are said, not silently picked');

(function () {
  var a = loadRaw('r2-env-tag-disagree', 'a');
  var b = loadRaw('r2-env-tag-disagree', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB], { date: '2026-09-24' });
  var join = findingsOf(r, 'settings-join');
  ok('one settings-join finding covers the whole merge', join.length === 1);
  var renamed = join[0] && join[0].facts.renamed || [];
  ok('TAG is recognised as disagreeing and renamed rather than merged silently',
     renamed.some(function (x) { return x.from === 'TAG'; }));

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('both values survive in the merged .env, under distinct names',
     /^TAG=1\.26/m.test(w.env) && /^TAG_B=1\.30/m.test(w.env));
  assertRoundTrip('R2-4', w.text);
})();

/* =========================================================================
 * R2-5 — environment as a list in one source and a map in the other, same
 * key in both. A key is per service, not a clash — each form and value is
 * kept exactly as its own service wrote it.
 * ========================================================================= */

console.log('\nR2-5. A same-named environment key in two services is not a clash — each form is kept');

(function () {
  var a = loadRaw('r2-env-form-mix', 'a');
  var b = loadRaw('r2-env-form-mix', 'b');
  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('the first service keeps its own list-form environment entry',
     /svc:\s*\n\s*image: alpine:3\.20\s*\n\s*environment:\s*\n\s*- TZ=Europe\/London/.test(w.text));
  ok('the second service keeps its own map-form environment entry',
     /svc2:\s*\n\s*image: alpine:3\.20\s*\n\s*environment:\s*\n\s*TZ: America\/New_York/.test(w.text));
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  ok('no clash of any kind is raised over a per-service environment key',
     r.findings.filter(function (f) { return f.kind !== 'clean'; }).length === 0);
  assertRoundTrip('R2-5', w.text);
})();

/* =========================================================================
 * R2-6 — Traefik router labels written as a list in one source and a map
 * in the other, both naming a router "web". Extends trap 3: the clash
 * must be found whichever form the labels are written in.
 * ========================================================================= */

console.log('\nR2-6. A clashing router label is found in either list or map form (trap 3, extended)');

(function () {
  var a = loadRaw('r2-label-router-clash', 'a');
  var b = loadRaw('r2-label-router-clash', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  var routerClash = r.findings.filter(function (f) {
    return f.kind === 'label-clash' && f.facts && f.facts.from === 'web' &&
      (f.facts.sections || []).indexOf('routers') >= 0;
  });
  ok('a clashing Traefik router label ("web", declared in both list and map form) is found',
     routerClash.length >= 1,
     'no finding kind in merge-examine.js currently inspects labels: for a router name clash at all');
})();

/* =========================================================================
 * R2-7 — a port range overlapping a single port, the same number on TCP
 * and UDP, and an IPv6 loopback address. A range overlapping a single
 * port is a clash; protocol and address differences are not.
 * ========================================================================= */

console.log('\nR2-7. Port ranges, protocol and address are read correctly for a clash');

(function () {
  var a = loadRaw('r2-port-range-proto-addr', 'a');
  var b = loadRaw('r2-port-range-proto-addr', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'port-clash');
  ok('the range 19100-19102 overlapping the single port 19101 is found as a clash',
     clash.some(function (f) { return f.facts.port === '19100-19102' || f.facts.port === '19101'; }),
     'ports actually read: ' + JSON.stringify(clash.map(function (f) { return f.facts.port; })));
  ok('19200 on TCP and UDP is NOT flagged as a clash (different protocols)',
     !clash.some(function (f) { return f.facts.port === '19200'; }));
})();

/* =========================================================================
 * R2-8 — both sources use the same profile name for different services.
 * The profile is kept; both services join it; no clash.
 * ========================================================================= */

console.log('\nR2-8. The same profile name in two sources is kept, not clashed');

(function () {
  var a = loadRaw('r2-profile-shared', 'a');
  var b = loadRaw('r2-profile-shared', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  ok('no clash of any kind is raised over a shared profile name',
     r.findings.filter(function (f) { return f.kind !== 'clean'; }).length === 0);

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('both services keep the "optional" profile, under their own names',
     /svc:\s*\n\s*image: alpine:3\.20\s*\n\s*profiles:\s*\n\s*- optional/.test(w.text) &&
     /svc2:\s*\n\s*image: alpine:3\.20\s*\n\s*profiles:\s*\n\s*- optional/.test(w.text));
  assertRoundTrip('R2-8', w.text);
})();

/* =========================================================================
 * R2-9 — top-level include:, and a service's extends: pointing at another
 * file. Must carry the file across and fix its path, or refuse with a
 * sentence — never drop it.
 * ========================================================================= */

console.log('\nR2-9. include: and extends: — the path is carried and fixed, or refused, never dropped');

(function () {
  var a = { name: 'a', text: fs.readFileSync(path.join(__dirname, 'fixtures/merge-pairs/r2-include-extends/a/compose.yaml'), 'utf8'), depth: 1 };
  var b = { name: 'b', text: fs.readFileSync(path.join(__dirname, 'fixtures/merge-pairs/r2-include-extends/b/compose.yaml'), 'utf8'), depth: 0 };
  var descA = MW.descriptorFromText(a.name, a.text, null, [], { depth: 1 });
  var descB = MW.descriptorFromText(b.name, b.text, null, [], { depth: 0 });
  var r = M.examine([descA, descB], { newDepth: 0 });
  var depthFindings = findingsOf(r, 'depth-path');
  ok('extends.file is recognised as a relative path needing a depth fix',
     depthFindings.some(function (f) { return f.facts.oldPath && f.facts.oldPath.indexOf('base.yaml') >= 0; }));

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp', newDepth: 0 });
  ok('extends.file is re-pointed one level shallower, same as any other relative path',
     /extends:\s*\n\s*file: \.\/shared\/base\.yaml/.test(w.text));
  ok('the top-level include: entry is either carried across with its path fixed, or the merge refuses and says why',
     /include:\s*\n\s*- \.\/shared\/common\.yaml/.test(w.text) ||
     w.refusals.some(function (f) { return JSON.stringify(f).indexOf('include') >= 0; }),
     'merged text: ' + (w.text.indexOf('include:') >= 0 ? w.text.slice(w.text.indexOf('include:'), w.text.indexOf('include:') + 40) : '(no include: line at all — dropped silently)'));
})();

/* =========================================================================
 * R2-10 — a source saved with CRLF line endings, and one starting with a
 * byte-order mark. Read it; write the merged file with plain line
 * endings; lose nothing.
 * ========================================================================= */

console.log('\nR2-10. CRLF and a leading byte-order mark are read cleanly, nothing lost');

(function () {
  var rawA = loadRawBytes('r2-crlf-bom', 'a');
  var rawB = loadRawBytes('r2-crlf-bom', 'b');
  ok('the CRLF fixture really is CRLF-saved (sanity check on the fixture itself)',
     rawA.toString('utf8').indexOf('\r\n') >= 0);
  ok('the BOM fixture really does start with a byte-order mark (sanity check on the fixture itself)',
     rawB[0] === 0xEF && rawB[1] === 0xBB && rawB[2] === 0xBF);

  var a = { name: 'a', text: rawA.toString('utf8') };
  var b = { name: 'b', text: rawB.toString('utf8') };
  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('the merged file carries no CRLF of its own', w.text.indexOf('\r') === -1);
  ok('the merged file carries no byte-order mark, and no literal "ï»¿" text where one was read',
     w.text.charCodeAt(0) !== 0xFEFF && w.text.indexOf('﻿') === -1 && w.text.indexOf('ï»¿') === -1,
     w.text.slice(0, 60));
  ok('both sources\' own inline comments survive the read',
     w.text.indexOf('a CRLF-saved file') >= 0 && w.text.indexOf('a BOM-prefixed file') >= 0);
  ok('both services land under the SAME "services:" block — a source read with its BOM still '
     + 'intact would have its first top-level key ("services:") go unrecognised, splitting the '
     + 'merge into two separate "services:" blocks (invalid YAML — a duplicate top-level key)',
     (w.text.match(/^services:/gm) || []).length === 1,
     'merged text:\n' + w.text);
  assertRoundTrip('R2-10', w.text);
})();

/* =========================================================================
 * R2-11 — non-English text and emoji in comments and values. Must come
 * out byte-identical.
 * ========================================================================= */

console.log('\nR2-11. Non-English text and emoji survive byte-identical');

(function () {
  var a = loadRaw('r2-unicode-emoji', 'a');
  var b = loadRaw('r2-unicode-emoji', 'b');
  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('the French inline comment and emoji survive exactly', w.text.indexOf('Ce service sert la page d\'accueil 🚀') >= 0);
  ok('the accented value survives exactly', w.text.indexOf('café, naïve, façade') >= 0);
  ok('the Japanese inline comment and emoji survive exactly', w.text.indexOf('このサービスはデータベースです 🎉') >= 0);
  ok('the Japanese value survives exactly', w.text.indexOf('こんにちは世界') >= 0);
  assertRoundTrip('R2-11', w.text);
})();

/* =========================================================================
 * R2-11b — a bonus finding surfaced while building the row above, general
 * enough it belongs on its own: a standalone comment with nothing to its
 * OWN right (a leading file header, or a trailing comment at the very end
 * of a service's own block) is dropped outright, English or not. Recorded
 * here rather than folded into R2-11's own cases, since it is not a
 * unicode question at all.
 * ========================================================================= */

console.log('\nR2-11b. A standalone comment with no key of its own is dropped (found while building R2-11)');

(function () {
  var a = { name: 'a', text: '# a file header comment on a\nservices:\n  svc:\n    image: alpine:3.20\n    # a trailing comment at the end of a\'s own block\n' };
  var b = { name: 'b', text: 'services:\n  svc2:\n    image: alpine:3.20\n' };
  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('a leading file-header comment (attached to no key) survives the merge — CLAUDE.md rule 2',
     w.text.indexOf('a file header comment on a') >= 0,
     'merged text:\n' + w.text);
  ok('a trailing comment at the end of a service\'s own block survives the merge — CLAUDE.md rule 2',
     w.text.indexOf('a trailing comment at the end') >= 0,
     'merged text:\n' + w.text);
})();

/* =========================================================================
 * R2-12 — links: and external_links: naming a renamed service, and
 * hostname: naming the same text by coincidence. links: and
 * external_links: must follow the rename; hostname: is the container's own
 * name for itself, not a reference, and must NOT be touched (decided
 * 2026-09-24, PLAN_169 F6 — an earlier version of this probe asserted the
 * opposite and was wrong).
 * ========================================================================= */

console.log('\nR2-12. links: and external_links: follow a service rename; hostname: does not');

(function () {
  var a = loadRaw('r2-hostname-links', 'a');
  var b = loadRaw('r2-hostname-links', 'b');
  var descA = MW.descriptorFromText(a.name, a.text, a.envText, []);
  var descB = MW.descriptorFromText(b.name, b.text, b.envText, []);
  var r = M.examine([descA, descB]);
  var clash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('the second source\'s "web" is the one renamed', clash.length === 1 && clash[0].stack === 'b');
  var newName = clash[0].facts.to;

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('links: follows the rename (already covered by renameService())',
     new RegExp('links:\\s*\\n\\s*- ' + newName + ':webalias').test(w.text));
  ok('hostname: is left UNCHANGED, still naming the original service', /hostname: web\b/.test(w.text),
     'merged hostname line: ' + (w.text.match(/hostname:.*/) || ['(none)'])[0]);
  ok('external_links: follows the rename', new RegExp('external_links:\\s*\\n\\s*- ' + newName + ':extweb').test(w.text),
     'merged external_links block: ' + (w.text.match(/external_links:[\s\S]{0,40}/) || ['(none)'])[0]);
  assertRoundTrip('R2-12', w.text);
})();

/* =========================================================================
 * R2-13 — two sources whose x-unraid disagree on a service's web address
 * or icon (via a service-name clash). Each service keeps its own.
 * ========================================================================= */

console.log('\nR2-13. Two sources\' disagreeing per-service x-unraid each keep their own');

(function () {
  var a = loadRaw('r2-xunraid-disagree', 'a');
  var b = loadRaw('r2-xunraid-disagree', 'b');
  // F16 — planIconCopies() only plans a copy for a file the source's own
  // listing actually holds; this fixture's icon.png is never shipped on
  // disk, so it has to be named here the same way a real merge-files reply
  // would.
  var iconEntry = { path: '.staxx/icon.png', size: 512, dir: false, outside: false };
  var files = { a: { files: [iconEntry] }, b: { files: [iconEntry] } };
  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp', files: files });
  ok('the first source\'s own webui address survives untouched', w.text.indexOf('http://[IP]:8091/') >= 0);
  ok('the second source\'s own webui address survives untouched, on its own (renamed) service',
     w.text.indexOf('http://[IP]:8092/') >= 0);
  ok('each service\'s icon is rewritten to its own per-service file, never shared',
     /icon: \.\/\.staxx\/icon-web\.png/.test(w.text) &&
     (w.text.match(/icon: \.\/\.staxx\/icon-/g) || []).length === 2 &&
     w.text.split(/icon: \.\/\.staxx\/icon-/).slice(1).map(function (s) { return s.split(/[.\n]/)[0]; })
       .filter(function (v, i, arr) { return arr.indexOf(v) === i; }).length === 2);
  assertRoundTrip('R2-13', w.text);
})();

/* =========================================================================
 * R2-14 — a service with healthcheck: {disable: true}. The health
 * suggestions step must leave it alone (never suggest adding one, never
 * remove the disable line).
 * ========================================================================= */

console.log('\nR2-14. healthcheck: {disable: true} is left alone, never offered a suggestion');

(function () {
  var a = loadRaw('r2-healthcheck-disable', 'a');
  var b = loadRaw('r2-healthcheck-disable', 'b');
  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('the disable: true line survives the merge untouched',
     /idle:\s*\n\s*image: alpine:3\.20\s*\n\s*healthcheck:\s*\n\s*disable: true/.test(w.text));

  // health-offer.js's chooseHealthCheck() is a pure decision function: it
  // takes an already-gathered `facts.fileCheck` boolean and never reads a
  // compose file itself, so calling it directly here would only prove
  // that "if told a check already exists, it says nothing" — a tautology.
  // The real question this row asks — whether whatever scans the MERGED
  // file for an existing healthcheck (and sets that fact) treats
  // `disable: true` the same as a real check — is decided by that
  // scanning code, which lives in the browser wizard (stacks.js), not in
  // anything importable here.
  skipCase('the health-suggestions step recognises healthcheck: {disable: true} as "already decided" and leaves it alone',
    'javascript/stacks.js — health-offer.js\'s chooseHealthCheck() only consumes a pre-computed ' +
    '`facts.fileCheck` flag; the code that reads a service\'s own healthcheck block out of the ' +
    'compose file and sets that flag is in the browser wizard');
})();

/* =========================================================================
 * R2-15 — two sources whose project names collide after lower-casing
 * ("T169-A", "t169-a"). Docker itself already builds ONE real volume for
 * both of them, on the real box, before this merge ever runs — so the
 * merge must read the SAME real name for both, and carry BOTH declarations
 * pointing at it. Giving the second source an invented, disambiguated name
 * instead (Adrian's correction, 2026-09-24) would point it at a volume
 * that has never existed, so that service would come up with an empty
 * store — the real merge fault this row exists to catch is that, not two
 * distinct realNames. The merged file's own KEYS still get disambiguated
 * ("data", "data_T169_A") since a file cannot declare the same key twice —
 * that is a different, unrelated uniqueness (mergedKeyTaken's own job).
 * ========================================================================= */

console.log('\nR2-15. Project names that collide only after lower-casing still get the SAME real storage');

(function () {
  var rawA = loadRaw('r2-project-name-casefold', 'a');
  var rawB = loadRaw('r2-project-name-casefold', 'b');
  // The two folders on disk cannot both be named with the same letters in
  // different case (Windows' filesystem is case-insensitive), so the
  // casing difference this row is about is applied here, to the "name"
  // each descriptor is built under, rather than to two folder names.
  var a = { name: 'T169-A', text: rawA.text };
  var b = { name: 't169-a', text: rawB.text };
  var descA = MW.descriptorFromText(a.name, a.text, null, []);
  var descB = MW.descriptorFromText(b.name, b.text, null, []);
  var r = M.examine([descA, descB]);
  var storage = findingsOf(r, 'storage-carry');
  ok('both sources\' own "data" volume are each carried', storage.length === 2);
  ok('both realNames read as the one, real, lower-cased name Docker itself already built for them '
     + '(a real merge fault if this fails: the merge tool compared "T169-A" and "t169-a" as different '
     + 'strings and invented two different real names, when Docker Compose lower-cases project names '
     + 'and has really only ever had ONE volume, "t169-a_data", for both of them)',
     storage[0].facts.realName === 't169-a_data' && storage[1].facts.realName === 't169-a_data',
     'realName A: ' + storage[0].facts.realName + ', realName B: ' + storage[1].facts.realName);

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  ok('both carried volume declarations name: the SAME real volume — sharing it is legal, '
     + 'and is exactly the sharing that already existed',
     (w.text.match(/name: t169-a_data/g) || []).length === 2);
  assertRoundTrip('R2-15', w.text);
})();

/* =========================================================================
 * R2-16 — the new stack's name equals one of the sources' names. Refused
 * before writing.
 * ========================================================================= */

console.log('\nR2-16. The new stack\'s name equalling a source\'s name is refused');

(function () {
  skipCase('a merge refuses when the new stack\'s name equals one of its own sources',
    'include/Merge.php, staxx_merge_write() — the "$rel === $newRel" check runs against ' +
    'real folder names on the server before anything is written; neither merge-examine.js ' +
    'nor merge-write.js takes the new stack\'s own name as an input to check against its sources');
})();

/* =========================================================================
 * R2-17 — a merge of one source with itself, or of a single stack.
 * Refused before writing.
 * ========================================================================= */

console.log('\nR2-17. Merging a single stack, or the same stack twice, is refused');

(function () {
  skipCase('a merge of fewer than two distinct sources is refused',
    'include/Merge.php, staxx_merge_write() — "count($sourceRels) < 2" and the duplicate-name ' +
    'check just above it; buildMergedText() itself happily accepts a one-element sources array ' +
    '(nothing here refuses it)');

  // What buildMergedText() itself actually does with one source, recorded
  // so the gap above is not just asserted but shown.
  var a = loadRaw('r2-self-merge', 'a');
  var w = auditedBuild([a], { date: '2026-09-24', name: 'demoapp' });
  ok('(for the record) buildMergedText() does not itself refuse a single source — it writes '
     + 'the one file through, which is exactly why the refusal has to sit in Merge.php instead',
     typeof w.text === 'string' && w.text.indexOf('services:') >= 0);
})();

/* =========================================================================
 * R2-18 — an alias used in a source whose anchor lives in a DIFFERENT
 * source's x- block after reordering. The merged file must never write
 * the alias above its anchor, and must load.
 * ========================================================================= */

console.log('\nR2-18. An alias whose anchor lives in a different source still resolves after the merge');

(function () {
  var a = loadRaw('r2-alias-cross-source', 'a');
  var b = loadRaw('r2-alias-cross-source', 'b');
  // Read alone, "a" is not valid compose on its own — its alias has no
  // anchor in its own file. That is the point of this row: two sources
  // that only work if the reader treats them as one document, which is
  // exactly what compose itself does NOT do until they are merged.
  // (compose-model.js reads an alias as an opaque, locked value — see its
  // own LOCK_WORDS table — and never tries to resolve it against an
  // anchor, so CM.parse() alone gives no warning here; that is simply not
  // a check this model makes, on this file or any other. The only thing
  // provable off-box is that the MERGED file ends up with both the anchor
  // and the alias, anchor first.)

  var w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  assertRoundTrip('R2-18', w.text);
  ok('the merged file really does carry both the anchor and the alias', /&shared-logging/.test(w.text) && /\*shared-logging/g.test(w.text));
})();

/* =========================================================================
 * R2-19 — a source with a YAML error in it. Refused by name, with the
 * line; the rest is not merged silently.
 * ========================================================================= */

console.log('\nR2-19. A source with a YAML error is refused by name, not merged silently');

(function () {
  var a = loadRaw('r2-yaml-error', 'a');
  var b = loadRaw('r2-yaml-error', 'b');
  var parsedA = CM.parse(a.text);
  ok('(sanity check on the fixture) the broken source really does fail to parse cleanly',
     parsedA.warnings.length > 0);

  var threw = false, w = null;
  try {
    w = auditedBuild([a, b], { date: '2026-09-24', name: 'demoapp' });
  } catch (e) { threw = true; }

  if (threw) {
    ok('buildMergedText() refuses outright rather than crashing on undefined output', false,
       'buildMergedText() threw instead of returning a refusal the wizard could show');
  } else {
    var refusesA = w.refusals && w.refusals.some(function (f) { return JSON.stringify(f).indexOf('a') >= 0; });
    ok('the broken source is named in a refusal, and the merge does not silently write a file '
       + 'built from only the other source',
       (w.refusals && w.refusals.length > 0) || w.text === null || w.text === undefined,
       'refusals: ' + JSON.stringify(w.refusals) + '; text was produced: ' + (typeof w.text === 'string'));
  }
})();

/* =========================================================================
 * R2-23 (text half) — undo: taking the "retired" profile and its comment
 * back out must leave the source's file exactly as it was, byte for
 * byte. There is no un-retire function in merge-write.js (only
 * retireText()), so the reversal below is done by hand, in the test, to
 * prove retireText()'s own output is well-formed enough to be reversed —
 * it does not exercise any "undo" code path in src/, because none exists.
 * ========================================================================= */

console.log('\nR2-23 (text half). Undoing a retirement restores the source exactly');

function unretire(text, newName, date) {
  var header = '# Retired into ' + newName + ', ' + date +
    ' — every service carries the "retired" profile so plain docker compose up starts nothing.';
  var lines = text.split('\n');
  var out = [];
  for (var i = 0; i < lines.length; i++) {
    if (lines[i] === header) continue;   // the one line retireText() adds outright
    // A whole "- retired" list item retireText() added — added AFTER
    // whatever the source already had, so it is always the LAST item
    // under its own profiles: block (mirrors addRetiredProfile()'s own
    // "lastItemLine" logic, run in reverse).
    var lm = /^(\s*)-\s*['"]?retired['"]?\s*$/.exec(lines[i]);
    if (lm) continue;
    // A flow-style profiles: [..., "retired"] retireText() appended to.
    var fm = /^(\s*)profiles:\s*\[(.*)\]\s*(#.*)?$/.exec(lines[i]);
    if (fm) {
      var inner = fm[2].split(',').map(function (s) { return s.trim(); })
        .filter(function (s) { return !/^['"]?retired['"]?$/.test(s); });
      if (inner.length === 0) continue;   // the whole line was "profiles: [\"retired\"]" — added outright
      out.push(fm[1] + 'profiles: [' + inner.join(', ') + ']' + (fm[3] ? ' ' + fm[3] : ''));
      continue;
    }
    // A bare "profiles: [\"retired\"]" line addRetiredProfile() added
    // because the service had no profiles: key at all.
    if (/^\s*profiles:\s*\["retired"\]\s*$/.test(lines[i])) continue;
    out.push(lines[i]);
  }
  return out.join('\n');
}

(function () {
  var a = loadRaw('r2-retired-roundtrip', 'a');
  var b = loadRaw('r2-retired-roundtrip', 'b');

  [a, b].forEach(function (src) {
    var retired = MW.retireText(src.text, 'demoapp', '2026-09-24');
    ok(src.name + ' — retireText() really did change the file (sanity check)', retired !== src.text);
    var restored = unretire(retired, 'demoapp', '2026-09-24');
    ok(src.name + ' — taking the "retired" profile and its comment back out restores the source exactly',
       restored === src.text,
       'restored:\n' + restored + '\n---original:\n' + src.text);
  });

  // Applying retireText() a second time is documented as a no-op (the
  // exact header line is checked verbatim) — proven here rather than
  // assumed, since an undo step relies on that idempotency to be safe to
  // click twice.
  var once = MW.retireText(a.text, 'demoapp', '2026-09-24');
  var twice = MW.retireText(once, 'demoapp', '2026-09-24');
  ok('retiring an already-retired file a second time changes nothing', once === twice);
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed, ' + skip + ' skipped');
process.exit(fail ? 1 : 0);
