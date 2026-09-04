/* StaXX — tests for the well-known database images table (PLAN_70 stage 5).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/db_images.js
 *
 * No framework, no npm, no network — the same shape as meta_scaffold.js:
 * one line per case, and a non-zero exit if anything fails.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var D = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/db-images.js');

// The one file both the server (include/CrossLinks.php) and the browser
// (javascript/db-images.js) read — see either file's header for why there is
// now a single copy. Read directly here too, rather than only through
// db-images.js's own loader, so this test proves the bytes on disk are sound
// on their own terms.
var JSON_FILE = path.join(__dirname, '..', 'src/staxx/usr/local/emhttp/plugins/staxx/data/db-images.json');
var RAW = fs.readFileSync(JSON_FILE, 'utf8');
var TABLE = JSON.parse(RAW);

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

/* =========================================================================
 * A. The table itself is well-formed
 * ========================================================================= */

console.log('\nA. Table shape');

(function () {
  var seenImages = {};
  var dupes = [];

  D.images.forEach(function (entry) {
    ok(entry.id + ': has a password slot', Array.isArray(entry.password) && entry.password.length > 0);

    ['user', 'password', 'rootPassword', 'database'].forEach(function (slot) {
      if (!entry[slot]) return;
      ok(entry.id + ': ' + slot + ' is a non-empty array', Array.isArray(entry[slot]) && entry[slot].length > 0);
      entry[slot].forEach(function (setting) {
        ok(entry.id + ': ' + slot + ' setting name is non-empty', typeof setting === 'string' && setting.length > 0);
      });
    });

    ok(entry.id + ': images list is a non-empty array', Array.isArray(entry.images) && entry.images.length > 0);
    entry.images.forEach(function (img) {
      ok(entry.id + ': image reference "' + img + '" is non-empty', typeof img === 'string' && img.length > 0);
      if (seenImages[img]) dupes.push(img);
      seenImages[img] = true;
    });
  });

  ok('no image reference is claimed by two entries', dupes.length === 0, dupes.join(', '));
})();

/* =========================================================================
 * B. Reference normalisation and lookup
 * ========================================================================= */

console.log('\nB. Lookup by image reference');

(function () {
  ok('bare name', D.lookupImage('mariadb') !== null);
  ok('bare name matches the right entry', D.lookupImage('mariadb').id === 'mariadb');

  ok('name with a tag', D.lookupImage('postgres:16-alpine') !== null &&
     D.lookupImage('postgres:16-alpine').id === 'postgres');

  ok('digest-pinned reference', D.lookupImage('mongo@sha256:' + '0'.repeat(64)) !== null &&
     D.lookupImage('mongo@sha256:' + '0'.repeat(64)).id === 'mongo');

  ok('registry-qualified reference', D.lookupImage('docker.io/library/postgres:16-alpine') !== null &&
     D.lookupImage('docker.io/library/postgres:16-alpine').id === 'postgres');

  ok('library/ prefixed reference', D.lookupImage('library/mysql:8') !== null &&
     D.lookupImage('library/mysql:8').id === 'mysql');

  ok('LinuxServer reference', D.lookupImage('lscr.io/linuxserver/mariadb:latest') !== null &&
     D.lookupImage('lscr.io/linuxserver/mariadb:latest').id === 'linuxserver-mariadb');

  ok('a registry port is not mistaken for a tag', D.lookupImage('myregistry.example.com:5000/library/mysql:8') !== null &&
     D.lookupImage('myregistry.example.com:5000/library/mysql:8').id === 'mysql');

  ok('unknown image returns null', D.lookupImage('myapp/foo:latest') === null);
  ok('empty/garbage input returns null, does not throw', D.lookupImage('') === null && D.lookupImage(null) === null);
})();

/* =========================================================================
 * C. Redis / Valkey — password only, no username
 * ========================================================================= */

console.log('\nC. Password-only images');

(function () {
  var redis = D.lookupImage('bitnami/redis:7');
  ok('redis found', redis !== null);
  ok('redis has a password setting', redis && redis.password && redis.password.length > 0);
  ok('redis has no username setting', redis && !redis.user);

  var valkey = D.lookupImage('bitnami/valkey:8');
  ok('valkey found', valkey !== null);
  ok('valkey has a password setting', valkey && valkey.password && valkey.password.length > 0);
  ok('valkey has no username setting', valkey && !valkey.user);

  var mssql = D.lookupImage('mcr.microsoft.com/mssql/server:2022-latest');
  ok('mssql found', mssql !== null);
  ok('mssql has a password setting', mssql && mssql.password && mssql.password.length > 0);
  ok('mssql has no username setting (the account is fixed)', mssql && !mssql.user);
})();

/* =========================================================================
 * D. MariaDB and MySQL keep the root password separate from a normal user's
 * ========================================================================= */

console.log('\nD. Root password kept separate');

(function () {
  var mariadb = D.lookupImage('mariadb:11');
  ok('mariadb has a root password slot', mariadb && mariadb.rootPassword && mariadb.rootPassword.length > 0);
  ok('mariadb root password differs from the user password', mariadb &&
     mariadb.rootPassword.join(',') !== mariadb.password.join(','));

  var mysql = D.lookupImage('mysql:8');
  ok('mysql has a root password slot', mysql && mysql.rootPassword && mysql.rootPassword.length > 0);
  ok('mysql root password differs from the user password', mysql &&
     mysql.rootPassword.join(',') !== mysql.password.join(','));

  var lsMariadb = D.lookupImage('linuxserver/mariadb:latest');
  ok('linuxserver mariadb also keeps root separate', lsMariadb && lsMariadb.rootPassword &&
     lsMariadb.rootPassword.join(',') !== lsMariadb.password.join(','));

  // Postgres has one account, not two — this is the negative case that
  // proves the table is not just adding a rootPassword everywhere.
  var postgres = D.lookupImage('postgres:16');
  ok('postgres has no separate root password (one superuser account)', postgres && !postgres.rootPassword);
})();

/* =========================================================================
 * E. The JSON file itself — the one thing that keeps the server and the
 * browser from silently disagreeing. Checked independently of db-images.js's
 * own loading, so a fault in the file is caught even if the loader were
 * accidentally forgiving about it.
 * ========================================================================= */

console.log('\nE. The shared JSON file');

(function () {
  ok('parses as an object with an images array',
     TABLE && typeof TABLE === 'object' && Array.isArray(TABLE.images));

  var seen = {};
  var dupes = [];
  (TABLE.images || []).forEach(function (entry) {
    ok(entry.id + ': has a non-empty id', typeof entry.id === 'string' && entry.id.length > 0);
    ok(entry.id + ': has a password slot', Array.isArray(entry.password) && entry.password.length > 0);
    ok(entry.id + ': images list is a non-empty array', Array.isArray(entry.images) && entry.images.length > 0);

    ['user', 'password', 'rootPassword', 'database'].forEach(function (slot) {
      if (entry[slot] === undefined) return;
      ok(entry.id + ': ' + slot + ' is a non-empty array', Array.isArray(entry[slot]) && entry[slot].length > 0);
      entry[slot].forEach(function (setting) {
        ok(entry.id + ': ' + slot + ' setting name is non-empty',
           typeof setting === 'string' && setting.trim().length > 0);
      });
    });

    entry.images.forEach(function (img) {
      ok(entry.id + ': image reference "' + img + '" is non-empty', typeof img === 'string' && img.trim().length > 0);
      if (seen[img]) dupes.push(img);
      seen[img] = true;
    });
  });
  ok('no image reference is claimed by two entries in the JSON file', dupes.length === 0, dupes.join(', '));

  // Proves db-images.js's own loader is a faithful pass-through of the file
  // on disk, not a copy that could quietly drift from it over time.
  ok('db-images.js\'s loaded table is exactly the JSON file\'s images array',
     JSON.stringify(D.images) === JSON.stringify(TABLE.images));
})();

/* =========================================================================
 * F. The optional healthcheck recipe, where an entry carries one (PLAN_108
 * stage 3) — shape only, not whether the command is a good idea, which is a
 * judgement made once against the image's own documentation when the recipe
 * is written, not something a test can re-derive.
 * ========================================================================= */

console.log('\nF. Healthcheck recipe shape');

(function () {
  var DURATION_RE = /^\d+(\.\d+)?(ms|s|m|h)$/;
  var HTTPS_RE = /^https:\/\//;

  (TABLE.images || []).forEach(function (entry) {
    var hc = entry.healthcheck;
    if (hc === undefined) return; // no recipe is a valid, deliberate choice

    ok(entry.id + ': healthcheck.test is an array', Array.isArray(hc.test));
    ok(entry.id + ': healthcheck.test starts CMD or CMD-SHELL',
       Array.isArray(hc.test) && (hc.test[0] === 'CMD' || hc.test[0] === 'CMD-SHELL'));

    ['interval', 'timeout', 'start_period'].forEach(function (field) {
      ok(entry.id + ': healthcheck.' + field + ' is a duration string',
         typeof hc[field] === 'string' && DURATION_RE.test(hc[field]), hc[field]);
    });

    ok(entry.id + ': healthcheck.retries is a positive integer',
       Number.isInteger(hc.retries) && hc.retries > 0);

    ok(entry.id + ': healthcheck.claim is a non-empty sentence',
       typeof hc.claim === 'string' && hc.claim.trim().length > 0);

    ok(entry.id + ': healthcheck.source is an https URL',
       typeof hc.source === 'string' && HTTPS_RE.test(hc.source));

    // A literal '$' must always be doubled to survive compose's own
    // variable substitution — a lone '$' left in the command would either
    // vanish (compose treats it as an empty variable reference) or, worse,
    // pull in whatever the *host's* environment happens to hold under that
    // name. Strip every correctly-doubled "$$" first; anything left with a
    // single '$' is the mistake this check exists to catch.
    (hc.test || []).forEach(function (part) {
      if (typeof part !== 'string') return;
      var stripped = part.replace(/\$\$/g, '');
      ok(entry.id + ': no lone "$" in "' + part + '"', stripped.indexOf('$') === -1);
    });
  });
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
