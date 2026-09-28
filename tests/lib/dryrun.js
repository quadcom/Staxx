/* StaXX — the three merge dry-run walks' shared start: argument parsing, the
 * failure accumulator and the section printer.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   var D = require('./lib/dryrun.js')(FIXTURES, 'DEV-TESTING');
 *   var CHECK = D.CHECK, OUT_DIR = D.OUT_DIR, checkFails = D.fails,
 *       section = D.section, fail = D.fail, storeNameFor = D.storeNameFor;
 *
 * Not a suite: tests/run-local.js skips this folder.
 */

'use strict';

var path = require('path');

// dryrun(fixturesDir, storeFolder) -> { CHECK, OUT_DIR, fails, section, fail, storeNameFor }
module.exports = function dryrun(fixturesDir, storeFolder) {
  var argv = process.argv.slice(2);
  var outArg = argv.filter(function (a) { return a !== '--check'; })[0];
  var fails = [];   // {key, message}: printed, and turned into the exit code, by the walk itself
  return {
    CHECK: argv.indexOf('--check') >= 0,
    OUT_DIR: outArg ? path.resolve(outArg) : path.join(fixturesDir, '.dryrun'),
    fails: fails,
    section: function (title) { console.log('\n' + title); },
    fail: function (key, message) { fails.push({ key: key, message: message }); },
    storeNameFor: function (leafName) { return storeFolder + '/' + leafName; }
  };
};
