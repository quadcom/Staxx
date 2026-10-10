/* StaXX — tests for the compose-errors look-up list (PLAN_212).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/compose_errors.js
 *
 * No framework, no npm. The PHP side compiles each pattern with PCRE and
 * the browser may use the same list with JavaScript RegExp, so every pattern
 * has to work as a JS RegExp (named groups, no lookbehind). Each entry's
 * `sample` must match its own entry and no entry above it, since the first
 * match wins.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var file = path.join(__dirname, '..', 'src', 'staxx', 'usr', 'local', 'emhttp',
  'plugins', 'staxx', 'include', 'compose-errors.json');
var fails = 0, total = 0;
function check(what, ok, note) {
  total++;
  if (!ok) { fails++; console.log('FAIL ' + what + (note ? '  (' + note + ')' : '')); }
  else console.log('ok   ' + what);
}

var data = JSON.parse(fs.readFileSync(file, 'utf8'));
check('version is a date string', /^\d{4}-\d{2}-\d{2}$/.test(data.version));
check('nine entries to start with', data.entries.length >= 9);

var ids = {}, res = [];
data.entries.forEach(function (e, i) {
  var re = null;
  try { re = new RegExp(e.match); } catch (x) { /* reported below */ }
  check(e.id + ': pattern compiles as a JS RegExp', re !== null);
  res.push(re);
  check(e.id + ': id is unique', !ids[e.id]); ids[e.id] = 1;
  check(e.id + ': has title, means, fix, docs, sample',
    ['title', 'means', 'fix', 'docs', 'sample'].every(function (k) { return typeof e[k] === 'string' && e[k] !== ''; }));
  check(e.id + ': docs is a docs.docker.com address', /^https:\/\/docs\.docker\.com\//.test(e.docs));
  check(e.id + ': no lookbehind or possessive quantifier', !/\(\?<[=!]/.test(e.match) && !/[+*?}]\+/.test(e.match.replace(/\./g, '')));

  var groups = (e.match.match(/\(\?<([A-Za-z_]\w*)>/g) || []).map(function (g) { return g.slice(3, -1); });
  var used = ((e.means + ' ' + e.fix).match(/\{(\w+)\}/g) || []).map(function (g) { return g.slice(1, -1); });
  used.forEach(function (n) { check(e.id + ': {' + n + '} has a group', groups.indexOf(n) >= 0); });

  if (re) {
    var body = e.sample.replace(/^\s*validating\s+\S+?:\s+/, '');
    check(e.id + ': sample matches its own entry', re.test(body));
    for (var j = 0; j < i; j++) {
      check(e.id + ': sample does not match earlier ' + data.entries[j].id, !res[j] || !res[j].test(body));
    }
  }
  if (e.autofix) check(e.id + ': autofix is a known name', e.autofix === 'quote-env-key');
});
check('only number-key has an autofix',
  data.entries.filter(function (e) { return e.autofix; }).map(function (e) { return e.id; }).join() === 'number-key');

console.log('\n' + (total - fails) + ' of ' + total + ' passed.');
process.exit(fails ? 1 : 0);
