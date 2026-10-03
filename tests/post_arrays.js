/* StaXX — the request body a list field is sent as.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/post_arrays.js
 *
 * PHP builds an array from a POST only when the key repeats with [] on the end;
 * `templates=a&templates=b` arrives as the lone string "b" (the 2026-09-29
 * fault). The two settings scripts each carry a private call() that must add
 * the brackets, so this cuts that function out of each file and runs it
 * against a stub fetch. The server half (parse_str) is in tests/server/leftovers.php.
 */

'use strict';

var fs = require('fs');

var DIR = 'src/staxx/usr/local/emhttp/plugins/staxx/javascript/';
var HEADER = 'function call(action, fields) {';
var passed = 0, failed = 0;

function ok(what, pass, note) {
  if (pass) passed++; else failed++;
  console.log((pass ? 'ok    ' : 'FAIL  ') + what + (!pass && note ? '  (' + note + ')' : ''));
}

// Source of the function starting at HEADER, found by matching braces.
function cutCall(src) {
  var first = src.indexOf(HEADER);
  if (first < 0 || src.indexOf(HEADER, first + 1) >= 0) return null;
  var depth = 0;
  for (var i = first + HEADER.length - 1; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}' && --depth === 0) return src.slice(first, i + 1);
  }
  return null;
}

function run(fn, fields) {
  var seen = {};
  var stubFetch = function (url, opts) { seen.body = String(opts.body); return Promise.resolve({ json: function () { return { ok: true }; } }); };
  var scaffold = function () { return { dataset: { csrf: 't', endpoint: '/e' } }; };
  var call = new Function('scaffold', 'fetch', 'URLSearchParams', 'return ' + fn)(scaffold, stubFetch, URLSearchParams);
  call('x', fields);
  return seen.body;
}

['leftovers.js', 'unraid-templates.js'].forEach(function (file) {
  var fn = cutCall(fs.readFileSync(DIR + file, 'utf8'));
  if (!fn) { ok(file + ': found exactly one "' + HEADER + '"', false); return; }
  var two, one;
  try {
    two = run(fn, { templates: ['a', 'b'], composeManager: '1' });
    one = run(fn, { templates: ['a'] });
  } catch (e) { ok(file + ': call() runs against the stubs', false, e.message); return; }
  ok(file + ': a two-item list goes as repeated key[]',
    two === 'csrf_token=t&action=x&templates%5B%5D=a&templates%5B%5D=b&composeManager=1', two);
  ok(file + ': a one-item list still goes as key[]', one === 'csrf_token=t&action=x&templates%5B%5D=a', one);
});

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
