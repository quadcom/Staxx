/* StaXX — tests for pinning an image to a digest.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/pin_image.js
 *
 * No framework, no npm, no network — the same shape as stash_guard.js: one
 * line per case, and a non-zero exit if anything fails.
 *
 * pinnedImageRef() is the one place that turns an image reference plus a
 * digest into a pinned reference, kept in compose-model.js specifically
 * because that file is requireable from node — the front end that will
 * actually call it is not. This file proves the rule holds, including the
 * one case (a digest with no tag) that broke the first draft: a digest
 * itself contains a colon, so the tag-separator search has to work on the
 * part before "@", not the whole string, or it finds the colon inside the
 * old digest instead of a real tag separator.
 */

'use strict';

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// 32 hex characters — the minimum this project's digest shape accepts.
var GOOD_DIGEST = 'sha256:' + 'abcdef0123456789abcdef0123456789';

console.log('\n1. Ordinary references — appended or replaced correctly');

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest', GOOD_DIGEST);
  ok('plain repo:tag gets the digest appended, tag kept',
     r.ok === true && r.ref === 'jellyfin/jellyfin:latest@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin', GOOD_DIGEST);
  ok('no tag at all — digest still appended',
     r.ok === true && r.ref === 'jellyfin/jellyfin@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('myhost:5000/app/thing:1.2', GOOD_DIGEST);
  ok('a registry port is not mistaken for the tag separator',
     r.ok === true && r.ref === 'myhost:5000/app/thing:1.2@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('ghcr.io/app/thing:${TAG}', GOOD_DIGEST);
  ok('a variable tag is left untouched and the digest still appended',
     r.ok === true && r.ref === 'ghcr.io/app/thing:${TAG}@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('${IMAGE}', GOOD_DIGEST);
  ok('a reference that is entirely a variable is refused',
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('ghcr.io/${OWNER}/app:1.0', GOOD_DIGEST);
  ok('a variable inside the repository part is refused',
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest@sha256:1111111111111111111111111111111111111111111111111111111111111111', GOOD_DIGEST);
  ok('a reference already pinned gets its digest replaced, tag still present',
     r.ok === true && r.ref === 'jellyfin/jellyfin:latest@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin@sha256:1111111111111111111111111111111111111111111111111111111111111111', GOOD_DIGEST);
  ok('a pinned reference with no tag gets its digest replaced cleanly',
     r.ok === true && r.ref === 'jellyfin/jellyfin@' + GOOD_DIGEST, JSON.stringify(r));
})();

console.log('\n2. Digest shape — every refusal');

[
  ['empty string', ''],
  ['just the algorithm', 'sha256:'],
  ['too-short hex', 'sha256:abcd'],
  ['non-hex characters', 'sha256:zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'],
  ['a shell metacharacter', 'sha256:abcdef0123456789abcdef0123456789; rm -rf /'],
  ['a newline embedded in it', 'sha256:abcdef0123456789abcdef0123456789\nghi']
].forEach(function (c) {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest', c[1]);
  ok('digest refused — ' + c[0],
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

console.log('\n3. Reference shape — empty and non-string inputs');

['', '   '].forEach(function (v) {
  var r = Y.pinnedImageRef(v, GOOD_DIGEST);
  ok('empty/whitespace reference refused: ' + JSON.stringify(v),
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

[null, undefined, 42, {}].forEach(function (v) {
  var r = Y.pinnedImageRef(v, GOOD_DIGEST);
  ok('non-string reference refused, not thrown: ' + JSON.stringify(v),
     r && r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

[null, undefined, 42, {}].forEach(function (v) {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest', v);
  ok('non-string digest refused, not thrown: ' + JSON.stringify(v),
     r && r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
if (fail > 0) process.exit(1);
