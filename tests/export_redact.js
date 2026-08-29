/* StaXX — tests for the export route's redactor (PLAN_76/98, Phase 4/Stage 3).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/export_redact.js
 *
 * No framework, no npm, no network — the same shape as yaml_roundtrip.js:
 * one line per case, and a non-zero exit if anything fails.
 *
 * redactText()/redactEnv() moved out of stacks.js's Sanitise pass and into
 * compose-model.js precisely so this file could exist: the span logic can
 * now be proven against the round-trip corpus from the command line,
 * instead of trusted by eye in a browser nothing here has.
 */

'use strict';

var fs   = require('fs');
var path = require('path');

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

var ROOT = path.join(__dirname, '..');
function fixture(rel) {
  return fs.readFileSync(path.join(ROOT, 'tests/fixtures/test-stacks', rel), 'utf8').replace(/\r\n/g, '\n');
}

/* =========================================================================
 * A. The corpus — blanks every ticked value, nothing else, and round-trips
 * ========================================================================= */

console.log('\nA. redactText() over the awkward-file corpus');

function everySensitiveValue(text) {
  // Every literal string a sensitive part actually holds — what must
  // vanish from the redacted text, and must not appear anywhere it lands.
  var form = Y.buildForm(Y.parse(text));
  var out = [];
  form.fields.forEach(function (f) {
    if (!f.sensitive) return;
    Object.keys(f.parts).forEach(function (k) {
      if (k === 'name') return;
      var p = f.parts[k];
      if (p && p.spot && p.value) out.push(p.value);
    });
  });
  return out;
}

[
  '07-yaml-quirks/compose.yaml',
  '05-busybox-http/compose.yaml'
].forEach(function (rel) {
  var text = fixture(rel);
  var form = Y.buildForm(Y.parse(text));
  var secrets = everySensitiveValue(text);
  var got = Y.redactText(text, form.fields);

  // Nothing but the ticked spans changed: diff line by line, and every
  // differing line must be one that held a sensitive part.
  var before = text.split('\n'), after = got.split('\n');
  var sensitiveLines = {};
  form.fields.forEach(function (f) {
    if (!f.sensitive) return;
    Object.keys(f.parts).forEach(function (k) {
      if (k === 'name') return;
      var s = f.parts[k].spot;
      if (s) sensitiveLines[s.line] = true;
    });
  });
  var stray = null;
  for (var i = 0; i < Math.max(before.length, after.length) && !stray; i++) {
    if (before[i] !== after[i] && !sensitiveLines[i]) stray = 'line ' + (i + 1);
  }
  ok(rel + ': only sensitive lines changed', !stray, stray);
  ok(rel + ': line count and everything else preserved',
     before.length === after.length,
     'lengths ' + before.length + ' vs ' + after.length);

  // THE ONE THAT MATTERS MOST: every original secret value is genuinely
  // gone from the output, not just from the fields the model reports.
  var leaked = secrets.filter(function (v) { return v !== '' && got.indexOf(v) !== -1; });
  ok(rel + ': every secret value is actually absent from the redacted text',
     leaked.length === 0, 'still present: ' + JSON.stringify(leaked));

  ok(rel + ': placeholder appears once per blanked value',
     (got.match(/\*\*REDACTED\*\*/g) || []).length === secrets.length,
     'expected ' + secrets.length + ' placeholders');
});

/* =========================================================================
 * B. Two parts, one scalar — replaced once, not twice
 * ========================================================================= */

console.log('\nB. A shared scalar is replaced once');

(function () {
  var text = [
    'services:',
    '  app:',
    '    image: alpine',
    '    ports:',
    '      - "8096:8097"  # secret port -!S'
  ].join('\n');

  var form = Y.buildForm(Y.parse(text));
  var portField = form.fields.filter(function (f) { return f.binder === 'port'; })[0];
  ok('the port field is read as sensitive', !!(portField && portField.sensitive));

  var report = [];
  var got = Y.redactText(text, form.fields, { report: report });

  ok('the whole quoted pair collapses to one placeholder',
     got.indexOf('- **REDACTED**') !== -1, got);
  ok('the report lists the shared span once, not once per part',
     report.length === 1, JSON.stringify(report));
  ok('the original port numbers are gone',
     got.indexOf('8096') === -1 && got.indexOf('8097') === -1, got);
})();

/* =========================================================================
 * C. redactEnv() — comments, blanks and order survive; junk passes through
 * ========================================================================= */

console.log('\nC. redactEnv() over a real .env');

(function () {
  var text = fixture('05-busybox-http/.env');
  var pick = function (name) { return name === 'SITE_TITLE'; };
  var report = [];
  var got = Y.redactEnv(text, pick, { report: report });

  var beforeLines = text.split('\n'), afterLines = got.split('\n');
  ok('same number of lines, same order', beforeLines.length === afterLines.length);
  ok('the comment line is untouched', afterLines[0] === beforeLines[0]);
  ok('an unpicked name is untouched', afterLines.indexOf('TZ=America/Toronto') !== -1);
  ok('the picked name is blanked', got.indexOf('SITE_TITLE=**REDACTED**') !== -1);
  ok('the original value is gone', got.indexOf('Busybox Page Server') === -1);
  ok('the report names exactly what was blanked', report.length === 1 && report[0] === 'SITE_TITLE');
})();

console.log('\nC2. redactEnv() passes an unparseable line through untouched');

(function () {
  var text = [
    '# a heading',
    '',
    'export GREETING="hello there"',
    'this is not a valid line at all',
    'PLAIN=value'
  ].join('\n');

  var report = [];
  var got = Y.redactEnv(text, function () { return true; }, { report: report });
  var lines = got.split('\n');

  ok('the comment survives', lines[0] === '# a heading');
  ok('the blank line survives', lines[1] === '');
  ok('export keeps its keyword, value blanked, quotes gone',
     lines[2] === 'export GREETING=**REDACTED**', lines[2]);
  ok('the unparseable line passes through byte for byte',
     lines[3] === 'this is not a valid line at all', lines[3]);
  ok('a plain NAME=value line is still blanked', lines[4] === 'PLAIN=**REDACTED**', lines[4]);
  ok('nothing is reordered', lines.length === text.split('\n').length);
})();

/* =========================================================================
 * D. secretEnvNames() — a sensitive setting that points at a .env entry
 * ========================================================================= */

console.log('\nD. secretEnvNames() follows a sensitive ${VAR} to its .env name');

(function () {
  var text = [
    'services:',
    '  app:',
    '    image: alpine',
    '    environment:',
    '      DB_PASS: ${DB_PASSWORD}  # -!S',
    '      TZ: ${TZ}                # not secret'
  ].join('\n');

  var form = Y.buildForm(Y.parse(text));
  var names = Y.secretEnvNames(form);

  ok('the secret setting\'s .env name is picked up', names.indexOf('DB_PASSWORD') !== -1, names);
  ok('the plain setting\'s .env name is not', names.indexOf('TZ') === -1, names);
})();

/* =========================================================================
 * E. The default placeholder path matches Sanitise's own behaviour exactly
 * ========================================================================= */

console.log('\nE. The default call is byte-for-byte what Sanitise produced before the move');

(function () {
  // The pre-move implementation, kept here verbatim as a fixed point to
  // compare against — not to be "fixed" if it and the real one diverge,
  // since divergence IS the bug this proves absent.
  function legacyRedact(text, fields) {
    var lines = text.split('\n');
    var seen = {}, byLine = {};

    fields.forEach(function (f) {
      if (!f.sensitive) return;
      Object.keys(f.parts).forEach(function (k) {
        if (k === 'name') return;
        var s = f.parts[k].spot;
        if (!s) return;
        var key = s.line + ':' + s.col;
        if (seen[key]) return;
        seen[key] = true;
        (byLine[s.line] = byLine[s.line] || []).push(s);
      });
    });

    Object.keys(byLine).forEach(function (n) {
      var spots = byLine[n].sort(function (a, b) { return b.col - a.col; });
      var line = lines[n];
      spots.forEach(function (s) {
        line = line.slice(0, s.col) + '**REDACTED**' + line.slice(s.col + s.len);
      });
      lines[n] = line;
    });

    return lines.join('\n');
  }

  [
    '07-yaml-quirks/compose.yaml',
    '05-busybox-http/compose.yaml'
  ].forEach(function (rel) {
    var text = fixture(rel);
    var form = Y.buildForm(Y.parse(text));
    var want = legacyRedact(text, form.fields);
    var got  = Y.redactText(text, form.fields);
    ok(rel + ': default call matches the pre-move implementation', got === want);
  });
})();

/* ---- summary -------------------------------------------------------------- */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
