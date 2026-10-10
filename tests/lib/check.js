/* StaXX — the pass/fail checker every local suite shares.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   var check = require('./lib/check.js'), ok = check.ok;
 *   ok('what is being claimed', condition, detailShownOnFailure);
 *   check.done();            // or check.done(', 3 skipped')
 *
 * Not a suite: tests/run-local.js skips this folder.
 */

'use strict';

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// The summary line tests/run-local.js reads the counts from, then the exit code.
function done(suffix) {
  console.log('\n' + pass + ' passed, ' + fail + ' failed' + (suffix || ''));
  process.exit(fail ? 1 : 0);
}

module.exports = { ok: ok, done: done };
