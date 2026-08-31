/* StaXX — tests for the service-scope layout pass (PLAN_67, step 1).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/tidy.js
 *
 * No framework, no npm, no network — the same shape as yaml_roundtrip.js:
 * one line per case, and a non-zero exit if anything fails.
 *
 * The one thing worth trusting least here is the model under test itself, so
 * the central check — the key-path-to-span map — is read straight off the
 * raw text by a small reader written independently of compose-model.js. If
 * that reader agreed with the model's own idea of where a key's span starts
 * and ends, a bug in the model could hide behind a bug in the check built on
 * top of it. It doesn't: it walks indentation and comment runs itself, the
 * same way a person reading the file would.
 */

'use strict';

var fs   = require('fs');
var path = require('path');

var ROOT = path.join(__dirname, '..');
var MODEL_PATH  = path.join(ROOT, 'src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var STACKS_PATH = path.join(ROOT, 'src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js');

var Y = require(MODEL_PATH);

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

function skip(name, why) {
  console.log('  skip  ' + name + ' (' + why + ')');
}

/* ---- the corpus, and its five encoding variants -------------------------
 * Exactly the scan and the variant set yaml_roundtrip.js already builds:
 * a whole-document splice is exactly where a missing trailing newline, a
 * CRLF file or a BOM goes wrong, so every check below runs over all five.
 */

function findComposeFiles() {
  var out = [];
  ['tests/fixtures/test-stacks', 'examples'].forEach(function (dir) {
    var base = path.join(ROOT, dir);
    if (!fs.existsSync(base)) return;
    fs.readdirSync(base).forEach(function (entry) {
      var d = path.join(base, entry);
      if (!fs.statSync(d).isDirectory()) return;
      fs.readdirSync(d).forEach(function (f) {
        if (/^(docker-)?compose\.ya?ml$/.test(f)) out.push(path.join(d, f));
      });
    });
  });
  return out.sort();
}

var FILES = findComposeFiles();

function variantsFor(text) {
  return {
    'as written':        text,
    'no trailing NL':    text.replace(/\n+$/, ''),
    'extra trailing NL': text + '\n',
    'CRLF':              text.replace(/\n/g, '\r\n'),
    'with BOM':          '﻿' + text
  };
}

/* =========================================================================
 * An independent reader: raw text -> per-service key-path -> span-text map
 *
 * Deliberately not built on compose-model.js's own parse tree — see the
 * file header. It only needs to understand enough YAML shape to find a
 * "services:" block, the service names one level under it, and the keys one
 * level under each of those; that is exactly the shape tidy() is allowed to
 * move things around inside, so it is also exactly what this has to check.
 * ========================================================================= */

// Same splitting compose-model.js uses (strip a leading BOM, treat any CRLF
// as meaning the whole file is CRLF) — not a use of the model under test,
// just the one unavoidable fact about how a text file becomes lines.
function splitText(raw) {
  var text = String(raw == null ? '' : raw);
  if (text.charAt(0) === '﻿') text = text.slice(1);
  if (text.indexOf('\r\n') >= 0) text = text.replace(/\r\n/g, '\n');
  return text.split('\n');
}

function indentOf(line) {
  var i = 0;
  while (i < line.length && line.charAt(i) === ' ') i++;
  return i;
}

function isBlank(line) {
  return /^[ \t]*\r?$/.test(line);
}

// A key line's own shape: an optional quoted or bare key, then a colon.
// Bare enough to find "services:", a service name, or a key inside one —
// exactly the three things this reader ever asks a line about.
var KEY_LINE_RE = /^( *)(?:"((?:[^"\\]|\\.)*)"|'((?:[^']|'')*)'|([^\s:#][^:]*?)) *:(?:[ \t]|$)/;

function keyAt(line) {
  var m = KEY_LINE_RE.exec(line);
  if (!m) return null;
  var key = m[2] !== undefined ? m[2]
          : m[3] !== undefined ? m[3].replace(/''/g, "'")
          : m[4];
  return { indent: m[1].length, key: key };
}

function isCommentAt(line, indent) {
  return indentOf(line) === indent && line.charAt(indent) === '#';
}

// The services: block — the half-open [start, end) line range holding
// everything indented under it, up to the next top-level key or EOF.
function servicesBlock(lines) {
  var i;
  for (i = 0; i < lines.length; i++) {
    var k = keyAt(lines[i]);
    if (k && k.indent === 0 && k.key === 'services') break;
  }
  if (i >= lines.length) return null;
  var start = i + 1, end = lines.length;
  for (var j = start; j < lines.length; j++) {
    if (isBlank(lines[j])) continue;
    if (indentOf(lines[j]) === 0 && lines[j].charAt(0) !== '#') { end = j; break; }
  }
  return { start: start, end: end };
}

// Every service entry directly under services: — name, and the [start, end)
// range of lines it owns (its own name line up to the next service's, or the
// block's end).
function serviceEntries(lines, block) {
  if (!block) return [];
  var svcIndent = -1;
  for (var i = block.start; i < block.end; i++) {
    if (isBlank(lines[i]) || lines[i].charAt(indentOf(lines[i])) === '#') continue;
    svcIndent = indentOf(lines[i]);
    break;
  }
  if (svcIndent < 0) return [];
  var starts = [];
  for (i = block.start; i < block.end; i++) {
    var k = keyAt(lines[i]);
    if (k && k.indent === svcIndent) starts.push({ name: k.key, at: i });
  }
  var out = [];
  for (i = 0; i < starts.length; i++) {
    // A service ends BEFORE the next service's own lead comment run, not at
    // its name line. Written the other way, the comment block introducing
    // "sidecar" counts as part of "app", and whichever of app's keys happens
    // to be last swallows it — so simply reordering app's keys moves that
    // comment from one key's span to another and the invariant below reports
    // a change that never happened. The comment belongs to the service it
    // introduces, at exactly that service's own indent.
    var end = i + 1 < starts.length ? starts[i + 1].at : block.end;
    while (end - 1 > starts[i].at && isCommentAt(lines[end - 1], svcIndent)) end--;
    out.push({ name: starts[i].name, start: starts[i].at, end: end });
  }
  return out;
}

// The key -> span-text map for one service, plus the list of non-blank
// lines inside it that belong to no key at all (the orphan case tidy()
// itself is meant to refuse on, per PLAN_67's second refusal row).
function keySpans(lines, svc) {
  var bodyStart = svc.start + 1, bodyEnd = svc.end;
  var keyIndent = -1;
  for (var i = bodyStart; i < bodyEnd; i++) {
    if (isBlank(lines[i]) || lines[i].charAt(indentOf(lines[i])) === '#') continue;
    keyIndent = indentOf(lines[i]);
    break;
  }
  var spans = {}, order = [];
  if (keyIndent < 0) return { spans: spans, order: order, orphans: [] };

  var keyLines = [];
  for (i = bodyStart; i < bodyEnd; i++) {
    var k = keyAt(lines[i]);
    if (k && k.indent === keyIndent) keyLines.push({ name: k.key, at: i });
  }

  for (var n = 0; n < keyLines.length; n++) {
    var at = keyLines[n].at;
    // The lead comment run: contiguous lines directly above, at exactly the
    // key's own indent — PLAN_67's fix to the "at least as indented" rule,
    // which is what let a comment migrate to the wrong key.
    var leadStart = at;
    while (leadStart - 1 >= bodyStart && isCommentAt(lines[leadStart - 1], keyIndent)) leadStart--;

    var boundary = n + 1 < keyLines.length
      ? (function () {
          var nextAt = keyLines[n + 1].at, ls = nextAt;
          while (ls - 1 > at && isCommentAt(lines[ls - 1], keyIndent)) ls--;
          return ls;
        })()
      : bodyEnd;

    // A span's end excludes trailing blanks — they belong to the gap before
    // whatever comes next, not to this key's content.
    var rawEnd = boundary - 1;
    while (rawEnd > at && isBlank(lines[rawEnd])) rawEnd--;

    var name = keyLines[n].name;
    spans[name] = lines.slice(leadStart, rawEnd + 1).join('\n');
    order.push(name);
  }

  // Orphans: any non-blank line in the body not covered by a span above.
  // A second walk marking [leadStart, rawEnd] for every key as covered —
  // kept separate from the loop that built the span texts above rather than
  // folded in, since that one returns early via a closure and this one
  // cannot short-circuit the same way.
  var covered = new Array(bodyEnd - bodyStart).fill(false);
  for (n = 0; n < keyLines.length; n++) {
    var at2 = keyLines[n].at;
    var leadStart2 = at2;
    while (leadStart2 - 1 >= bodyStart && isCommentAt(lines[leadStart2 - 1], keyIndent)) leadStart2--;
    var boundary2 = n + 1 < keyLines.length
      ? (function () {
          var nextAt = keyLines[n + 1].at, ls = nextAt;
          while (ls - 1 > at2 && isCommentAt(lines[ls - 1], keyIndent)) ls--;
          return ls;
        })()
      : bodyEnd;
    var rawEnd2 = boundary2 - 1;
    while (rawEnd2 > at2 && isBlank(lines[rawEnd2])) rawEnd2--;
    for (var li = leadStart2; li <= rawEnd2; li++) covered[li - bodyStart] = true;
  }
  var orphans = [];
  for (i = bodyStart; i < bodyEnd; i++) {
    if (!covered[i - bodyStart] && !isBlank(lines[i])) orphans.push(lines[i]);
  }

  return { spans: spans, order: order, orphans: orphans };
}

// Every service's key-span map, keyed by service name, for one document.
function readModel(text) {
  var lines = splitText(text);
  var block = servicesBlock(lines);
  var entries = serviceEntries(lines, block);
  var byService = {};
  entries.forEach(function (svc) {
    byService[svc.name] = keySpans(lines, svc);
  });
  return { lines: lines, services: byService, serviceNames: entries.map(function (e) { return e.name; }) };
}

function lineMultiset(lines) {
  var m = {};
  lines.forEach(function (l) { m[l] = (m[l] || 0) + 1; });
  return m;
}

function multisetsEqual(a, b) {
  var ka = Object.keys(a), kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  for (var i = 0; i < ka.length; i++) if (a[ka[i]] !== b[ka[i]]) return false;
  return true;
}

/* =========================================================================
 * Whether tidy() exists yet — the plan that ordered this suite says the
 * function may not be built when this first runs. Say so plainly rather
 * than letting every case below crash on a missing method.
 * ========================================================================= */

var HAS_TIDY = typeof Y.tidy === 'function';
if (!HAS_TIDY) {
  ok('YAML.tidy exists', false, 'compose-model.js exports no tidy() function yet — every corpus ' +
    'check below is skipped until it does.');
}

function safeTidy(text) {
  try {
    return { result: Y.tidy(text) };
  } catch (e) {
    return { threw: e };
  }
}

/* =========================================================================
 * A. The corpus — the key-span invariant, plus three cheap checks alongside
 *    it, over all five encoding variants of every fixture.
 * ========================================================================= */

console.log('\nA. Corpus: the key-span invariant, idempotence, no new warnings/seals');

// The two fixtures PLAN_67 names by what they contain, not by filename —
// found by reading the corpus rather than guessed at.
var QUIRKS_NAME = '07-yaml-quirks';
var ALIAS_NAME  = '25-macvlan-ports-alias';
var quirksSeen = false, aliasSeen = false;
var quirksRefused = null, aliasRefused = null, quirksChanged = null;

if (HAS_TIDY) {
  FILES.forEach(function (file) {
    var relName = path.relative(ROOT, file).replace(/\\/g, '/');
    var fixtureDir = path.basename(path.dirname(file));
    var rawText = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
    var variants = variantsFor(rawText);

    Object.keys(variants).forEach(function (variantName) {
      var before = variants[variantName];
      var label = relName + '  [' + variantName + ']';

      var attempt = safeTidy(before);
      if (attempt.threw) {
        ok(label + ' — does not throw', false, attempt.threw && attempt.threw.stack);
        return;
      }
      var r = attempt.result;
      var okShape = r && typeof r.text === 'string' && typeof r.changed === 'boolean' && Array.isArray(r.refusals);
      if (!ok(label + ' — returns {text, changed, refusals}', okShape)) return;

      var after = r.text;

      // Refusal contract: when everything refuses, changed is false and the
      // text comes back byte for byte.
      if (r.refusals.length && !r.changed) {
        ok(label + ' — full refusal leaves text untouched', after === before,
          after === before ? '' : 'refusals present, changed=false, but text differs from input');
      }

      // The one invariant that matters: independently computed key-span
      // maps must agree, service by service.
      var beforeModel = readModel(before);
      var afterModel = readModel(after);

      ok(label + ' — same services present', multisetsEqual(
        lineMultiset(beforeModel.serviceNames), lineMultiset(afterModel.serviceNames)
      ), 'before: ' + beforeModel.serviceNames.join(',') + '  after: ' + afterModel.serviceNames.join(','));

      beforeModel.serviceNames.forEach(function (svcName) {
        var b = beforeModel.services[svcName], a = afterModel.services[svcName];
        if (!a) return; // already reported as a missing-service failure above
        var bKeys = Object.keys(b.spans).sort(), aKeys = Object.keys(a.spans).sort();
        var sameKeys = bKeys.length === aKeys.length && bKeys.every(function (k, i) { return k === aKeys[i]; });
        if (!ok(label + ' — ' + svcName + ': same key set', sameKeys,
          'before: ' + bKeys.join(',') + '  after: ' + aKeys.join(','))) return;
        bKeys.forEach(function (k) {
          ok(label + ' — ' + svcName + '.' + k + ': span text unchanged', b.spans[k] === a.spans[k],
            b.spans[k] === a.spans[k] ? '' : 'before:\n' + b.spans[k] + '\nafter:\n' + a.spans[k]);
        });
        // Orphan lines (uncovered by any key span) must be the very same
        // lines in the same place either side of the pass — a scope with
        // one is meant to be refused whole, not partially reordered.
        ok(label + ' — ' + svcName + ': orphan lines unchanged', b.orphans.join('\n') === a.orphans.join('\n'),
          'before: ' + JSON.stringify(b.orphans) + '  after: ' + JSON.stringify(a.orphans));
      });

      // Whole-file permutation: same multiset of lines before and after,
      // whatever scope the pass actually touched.
      ok(label + ' — whole file is a permutation of its own lines',
        multisetsEqual(lineMultiset(beforeModel.lines), lineMultiset(afterModel.lines)));

      // Still parses, with no new warnings and no new sealed regions.
      var beforeDoc = Y.parse(before), afterDoc = Y.parse(after);
      var beforeWarnings = (beforeDoc.warnings || []).map(function (w) { return w.message; });
      var afterWarnings = (afterDoc.warnings || []).map(function (w) { return w.message; });
      var newWarnings = afterWarnings.filter(function (m) { return beforeWarnings.indexOf(m) < 0; });
      ok(label + ' — no new parse warnings', newWarnings.length === 0, newWarnings.join('; '));

      var beforeSealed = (beforeDoc.sealed || []).map(function (s) { return s.reason; }).sort();
      var afterSealed = (afterDoc.sealed || []).map(function (s) { return s.reason; }).sort();
      ok(label + ' — no new sealed regions', multisetsEqual(lineMultiset(beforeSealed), lineMultiset(afterSealed)),
        'before: ' + beforeSealed.join(',') + '  after: ' + afterSealed.join(','));

      // Idempotent: running it again over its own output changes nothing.
      var second = safeTidy(after);
      if (second.threw) {
        ok(label + ' — second pass does not throw', false, second.threw && second.threw.stack);
      } else {
        ok(label + ' — idempotent (text)', second.result.text === after,
          second.result.text === after ? '' : firstDiff(after, second.result.text));
        ok(label + ' — idempotent (changed=false)', second.result.changed === false);
      }
    });

    if (fixtureDir === QUIRKS_NAME) {
      quirksSeen = true;
      var qResult = safeTidy(rawText);
      quirksRefused = !qResult.threw && qResult.result.refusals.length > 0;
      quirksChanged = !qResult.threw && qResult.result.changed === true;
    }
    if (fixtureDir === ALIAS_NAME) {
      aliasSeen = true;
      var aResult = safeTidy(rawText);
      aliasRefused = !aResult.threw && aResult.result.refusals.length > 0;
    }
  });
} else {
  skip('corpus checks (key-span invariant, idempotence, warnings, seals)', 'YAML.tidy is missing');
}

function firstDiff(a, b) {
  var A = a.split('\n'), B = b.split('\n');
  for (var i = 0; i < Math.max(A.length, B.length); i++) {
    if (A[i] !== B[i]) return 'line ' + (i + 1) + '\n  was: ' + JSON.stringify(A[i]) + '\n  now: ' + JSON.stringify(B[i]);
  }
  return 'lengths ' + a.length + ' vs ' + b.length;
}

/* =========================================================================
 * B. The two fixtures PLAN_67 names specifically
 * ========================================================================= */

console.log('\nB. Named fixtures — the whole point of the anchor rule');

if (HAS_TIDY) {
  if (quirksSeen) {
    // PLAN_67 asks that this fixture refuse AT THE TOP LEVEL, and the top
    // level is step 2. Its own services are already in house order, so at
    // service scope there is genuinely nothing to refuse and nothing to
    // change — which is what is asserted here instead. The refusal proper
    // becomes a real assertion the moment top-level scope lands; left
    // visible as a pending line rather than quietly dropped, so nobody has
    // to remember it.
    ok(QUIRKS_NAME + ' is left untouched at service scope (already in house order)',
       quirksRefused === false && quirksChanged === false);
    skip(QUIRKS_NAME + ' refuses at the TOP level', 'waiting on PLAN_67 step 2 — top-level scope');
  } else {
    skip(QUIRKS_NAME + ' refuses', 'fixture not found in tests/fixtures/test-stacks');
  }
  if (aliasSeen) {
    ok(ALIAS_NAME + ' does not refuse (anchor and its alias sit in different services)', aliasRefused === false);
  } else {
    skip(ALIAS_NAME + ' does not refuse', 'fixture not found in tests/fixtures/test-stacks');
  }
} else {
  skip(QUIRKS_NAME + ' refuses', 'YAML.tidy is missing');
  skip(ALIAS_NAME + ' does not refuse', 'YAML.tidy is missing');
}

/* =========================================================================
 * B2. The three hazards the corpus does not hold.
 *
 * These are written here rather than added to tests/fixtures/test-stacks/
 * because that corpus exists to prove the round-trip promise and its own
 * suite counts its cases; a hazard invented for THIS pass belongs with this
 * pass. Each one was checked by removing the rule it guards and confirming
 * this file goes red — a refusal nothing can make fail is not a test.
 * ========================================================================= */

console.log('\nB2. Hazards the corpus does not hold');

// The one the whole pass rests on. The note sits at the END of the ports
// list, indented deeper than the key column, directly above the next key.
// Under the loose "at least as indented" rule the next key claims it, and
// moving that key carries the note away from the list it is about. Under the
// strict rule it belongs to nothing, which is an orphan, which refuses.
var HAZARD_DEEP_COMMENT = [
  'services:',
  '  web:',
  '    ports:',
  '      - "8080:80"',
  '      # the second one is only for the admin page',
  '    image: alpine:3.20',
  ''
].join('\n');

// The model keeps the first pair and discards the second, but the second's
// lines stay in the file — teleporting one past its twin changes which value
// compose actually uses.
var HAZARD_DUPLICATE_KEY = [
  'services:',
  '  web:',
  '    ports:',
  '      - "8080:80"',
  '    image: alpine:3.20',
  '    image: alpine:3.21',
  ''
].join('\n');

// "|+" keeps its trailing blank lines as part of the value, and a span's end
// deliberately excludes trailing blanks — so moving one silently shortens
// what it says.
var HAZARD_KEEP_CHOMP = [
  'services:',
  '  web:',
  '    ports:',
  '      - "8080:80"',
  '    command: |+',
  '      one',
  '',
  '',
  '    image: alpine:3.20',
  ''
].join('\n');

function refusesUnchanged(label, text) {
  if (!HAS_TIDY) { skip(label, 'YAML.tidy is missing'); return; }
  var r;
  try { r = Y.tidy(text); } catch (e) { ok(label, false, 'threw: ' + e.message); return; }
  ok(label + ' refuses', r.refusals.length > 0,
     'refusals: ' + JSON.stringify(r.refusals));
  ok(label + ' changes nothing', r.changed === false && r.text === text);
  ok(label + ' says why in a sentence',
     r.refusals.length > 0 && typeof r.refusals[0].why === 'string' && r.refusals[0].why.length > 10,
     r.refusals.length ? r.refusals[0].why : '(no refusal)');
}

// The one that would actually break the file rather than merely muddle it.
// The anchor is declared on "labels" and reused by "environment"; the house
// order puts environment first, which would leave an alias standing above the
// anchor it points at — a file compose can no longer load. The corpus does
// not cover this: its own anchor and alias sit in two different services,
// which are never reordered relative to each other.
var HAZARD_ANCHOR_CROSSES = [
  'services:',
  '  web:',
  '    labels: &shared',
  '      a: b',
  '    environment: *shared',
  ''
].join('\n');

refusesUnchanged('an anchor its alias would cross', HAZARD_ANCHOR_CROSSES);
refusesUnchanged('a note indented deeper than its key', HAZARD_DEEP_COMMENT);
refusesUnchanged('a duplicate key', HAZARD_DUPLICATE_KEY);
refusesUnchanged('a |+ block scalar', HAZARD_KEEP_CHOMP);

/* =========================================================================
 * C. SERVICE_ORDER and the form's group order agree — the same
 *    "one source both the thing and its check read" arrangement
 *    meta_scaffold.js already uses.
 * ========================================================================= */

console.log('\nC. SERVICE_ORDER covers every compose key a form group is about');

// Cheap block extraction: from the declaration to the matching "];" that
// closes it at the same indent — good enough for this file's own style,
// and reading the source text by regex is what PLAN_67 itself asks for.
function sourceBlock(src, startRe) {
  var m = startRe.exec(src);
  if (!m) return null;
  var start = m.index + m[0].length;
  var end = src.indexOf('];', start);
  return end < 0 ? null : src.slice(start, end);
}

var stacksSrc = fs.readFileSync(STACKS_PATH, 'utf8');
var modelSrc  = fs.readFileSync(MODEL_PATH, 'utf8');

var groupsBlock = sourceBlock(stacksSrc, /var GROUPS\s*=\s*\[/);
var groupKeys = [];
if (groupsBlock) {
  var gre = /\{\s*key:\s*'([^']+)'/g, gm;
  while ((gm = gre.exec(groupsBlock))) groupKeys.push(gm[1]);
}
ok('stacks.js has a GROUPS table to read', groupKeys.length > 0);

var sectionsBlock = sourceBlock(stacksSrc, /var SECTIONS\s*=\s*\[/);
var pathByGroupKey = {};
if (sectionsBlock) {
  var sre = /\{\s*key:\s*'([^']+)'[\s\S]*?path:\s*\[([^\]]*)\]/g, sm;
  while ((sm = sre.exec(sectionsBlock))) {
    var keys = [], pre = /'([^']+)'/g, pm;
    while ((pm = pre.exec(sm[2]))) keys.push(pm[1]);
    pathByGroupKey[sm[1]] = keys;
  }
}
ok('stacks.js has a SECTIONS table naming each group\'s compose key', Object.keys(pathByGroupKey).length > 0);

// 'container' and 'advanced' are catch-alls with no single compose key of
// their own (see SECTIONS's own comment) — every other group names exactly
// the compose key(s) it renders, and SECTIONS's path's first element is the
// top-level one (e.g. resources -> deploy.resources, checked as 'deploy').
var composeKeysFromGroups = [];
groupKeys.forEach(function (k) {
  if (k === 'container' || k === 'advanced') return;
  var p = pathByGroupKey[k];
  if (p && p.length) composeKeysFromGroups.push(p[0]);
});
composeKeysFromGroups = composeKeysFromGroups.filter(function (k, i, arr) { return arr.indexOf(k) === i; });

var orderMatch = /SERVICE_ORDER\s*=\s*(\[[\s\S]*?\n\s*\]);/.exec(modelSrc);
if (!orderMatch) {
  ok('compose-model.js has a SERVICE_ORDER table', false, 'no "SERVICE_ORDER = [ ... ]" found');
} else {
  var orderBlock = orderMatch[1];
  var orderTokens = {};
  var tre = /'([^']+)'/g, tm;
  while ((tm = tre.exec(orderBlock))) orderTokens[tm[1]] = true;
  composeKeysFromGroups.forEach(function (key) {
    ok('SERVICE_ORDER names the "' + key + '" compose key', !!orderTokens[key]);
  });
}

/* =========================================================================
 * D. Degenerate inputs never throw
 * ========================================================================= */

console.log('\nD. Degenerate inputs');

if (HAS_TIDY) {
  [
    ['empty string', ''],
    ['null', null],
    ['comments only', '# just a comment\n# and another\n']
  ].forEach(function (pair) {
    var name = pair[0], input = pair[1];
    var attempt = safeTidy(input);
    if (!ok('tidy(' + name + ') does not throw', !attempt.threw, attempt.threw && attempt.threw.stack)) return;
    var r = attempt.result;
    ok('tidy(' + name + ') returns {text, changed, refusals}',
      r && typeof r.text === 'string' && typeof r.changed === 'boolean' && Array.isArray(r.refusals));
    if (input === '' || input === null) {
      ok('tidy(' + name + ') changes nothing', r && r.changed === false && r.text === '');
    }
  });
} else {
  skip('degenerate inputs', 'YAML.tidy is missing');
}

/* ---- summary -------------------------------------------------------------- */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
