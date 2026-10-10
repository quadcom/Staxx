/* StaXX — one command for every local check: the same set CI runs.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/run-local.js
 *
 * Three groups, in order: node --check on every browser script, every
 * tests/*.js suite run bare (the top level of tests/ only — tests/lib/,
 * tests/tools/, tests/server/ and tests/fixtures/ are never entered), and
 * the schema self-test. This is exactly what .github/workflows/release.yml
 * and publish.yml run, found by listing rather than named, because the set
 * differs between branches and a named list would fail the gate on a
 * branch that lacks one of them.
 *
 * Every check runs even after one fails — CI's shell loop stops at the
 * first, which is the gap this file closes for the dev machine too.
 */

'use strict';

var fs = require('fs');
var path = require('path');
var spawnSync = require('child_process').spawnSync;

var ROOT = path.join(__dirname, '..');
var JS_DIR = 'src/staxx/usr/local/emhttp/plugins/staxx/javascript';

var checks = [];

fs.readdirSync(path.join(ROOT, JS_DIR)).filter(function (f) { return /\.js$/.test(f); })
  .sort().forEach(function (f) {
    checks.push({ name: JS_DIR + '/' + f, cmd: 'node', args: ['--check', JS_DIR + '/' + f] });
  });

fs.readdirSync(path.join(ROOT, 'tests')).filter(function (f) {
  return /\.js$/.test(f) && f !== 'run-local.js' && fs.statSync(path.join(ROOT, 'tests', f)).isFile();
}).sort().forEach(function (f) {
  checks.push({ name: 'tests/' + f, cmd: 'node', args: ['tests/' + f] });
});

checks.push({ name: 'tests/validate_schema.py', cmd: 'python', args: ['tests/validate_schema.py'] });

var failed = 0;
var started = Date.now();

checks.forEach(function (c) {
  var t0 = Date.now();
  var res = spawnSync(c.cmd, c.args, { cwd: ROOT, encoding: 'utf8' });
  var ms = Date.now() - t0;
  var output = (res.stdout || '') + (res.stderr || '');
  var lines = output.split('\n').filter(function (l) { return /\d+ passed, \d+ failed/.test(l); });
  var summary = lines.length ? lines[lines.length - 1].trim() : '';
  var ok = res.status === 0;

  if (ok) {
    console.log('  ok    ' + c.name + '  ' + ms + ' ms' + (summary ? '  ' + summary : ''));
  } else {
    failed++;
    console.log('  FAIL  ' + c.name + '  ' + ms + ' ms');
    console.log(output.replace(/^/gm, '        '));
  }
});

var seconds = ((Date.now() - started) / 1000).toFixed(1);
console.log('\n' + checks.length + ' checks, ' + failed + ' failed, ' + seconds + ' s');
process.exit(failed ? 1 : 0);
