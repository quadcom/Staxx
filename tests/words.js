/* StaXX — tests for the passphrase generator's word list (PLAN_74 stage 2).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/words.js
 *
 * No framework, no npm, no network — the same shape as db_images.js: one
 * line per case, and a non-zero exit if anything fails.
 *
 * 1024 is load-bearing: it is 2^10, so each word is exactly 10 bits of
 * strength and the generator's strength figure is honest arithmetic rather
 * than a rounded claim. Duplicates are the likely failure mode when a list
 * this long is hand-built, and they silently weaken every passphrase, so
 * that check matters as much as the count itself.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var JSON_FILE = path.join(__dirname, '..', 'src/staxx/usr/local/emhttp/plugins/staxx/data/words.json');
var RAW = fs.readFileSync(JSON_FILE, 'utf8');
var TABLE = JSON.parse(RAW);
var WORDS = TABLE.words || [];

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

console.log('\nA. Shape');

ok('parses as an object with a words array',
   TABLE && typeof TABLE === 'object' && Array.isArray(WORDS));

ok('carries a note explaining why the count is 1024',
   typeof TABLE.note === 'string' && TABLE.note.length > 0);

console.log('\nB. Exactly 1024 words');

ok('word count is exactly 1024 (2^10, one word = 10 bits)', WORDS.length === 1024,
   'got ' + WORDS.length);

console.log('\nC. Every word is 3-7 lowercase letters');

(function () {
  var re = /^[a-z]{3,7}$/;
  var bad = WORDS.filter(function (w) { return typeof w !== 'string' || !re.test(w); });
  ok('all 1024 entries match /^[a-z]{3,7}$/', bad.length === 0, bad.join(', '));
})();

console.log('\nD. All words are unique');

(function () {
  var set = new Set(WORDS);
  ok('no duplicate words', set.size === WORDS.length,
     (WORDS.length - set.size) + ' duplicate(s)');
})();

console.log('\nE. The list is sorted alphabetically');

(function () {
  var sorted = WORDS.slice().sort();
  ok('words are in alphabetical order', JSON.stringify(sorted) === JSON.stringify(WORDS));
})();

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
