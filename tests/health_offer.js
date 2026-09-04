/* StaXX — tests for the health-check chooser (PLAN_108 stage 3/5).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/health_offer.js
 *
 * No framework, no npm, no network — same shape as tests/db_images.js: one
 * line per case, non-zero exit if anything fails.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var H = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/health-offer.js');
var D = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/db-images.js');

var JSON_FILE = path.join(__dirname, '..', 'src/staxx/usr/local/emhttp/plugins/staxx/data/db-images.json');
var TABLE = JSON.parse(fs.readFileSync(JSON_FILE, 'utf8'));

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// Fills in every environment variable a recipe's command references, so a
// round-trip test never fails on the "missing value" refusal by accident.
function envFor(healthcheck) {
  var env = {};
  (healthcheck.test || []).forEach(function (part) {
    if (typeof part !== 'string') return;
    var m, re = /\$\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/g;
    while ((m = re.exec(part))) env[m[1]] = 'x';
  });
  return env;
}

/* =========================================================================
 * A. chooseHealthCheck — source order
 * ========================================================================= */

console.log('\nA. Source order');

(function () {
  var mariadb = D.lookupImage('mariadb');
  var fullFacts = { fileCheck: true, ownCheck: true, dbEntry: mariadb, env: envFor(mariadb.healthcheck),
    webPort: 80, tools: { curl: true } };

  var r1 = H.chooseHealthCheck(fullFacts);
  ok('fileCheck wins over everything else', r1.offer === null && r1.reason === 'already-in-file');

  var r2 = H.chooseHealthCheck(Object.assign({}, fullFacts, { fileCheck: false }));
  ok('ownCheck wins once fileCheck is false', r2.offer === null && r2.reason === 'image-checks-itself');

  var r3 = H.chooseHealthCheck(Object.assign({}, fullFacts, { fileCheck: false, ownCheck: false }));
  ok('known-image recipe wins once the first two are false', r3.source === 'known-image' && r3.offer !== null);
  ok('known-image offer carries the recipe\'s own claim', r3.claim === mariadb.healthcheck.claim);

  var r4 = H.chooseHealthCheck({ fileCheck: false, ownCheck: false, dbEntry: null, webPort: 8080, tools: { curl: true } });
  ok('web-ping wins once there is no db recipe', r4.source === 'web-ping' && r4.offer !== null);
  ok('web-ping claim says only that the web page answered',
     /web page/.test(r4.claim) && !/database/.test(r4.claim));

  var r5 = H.chooseHealthCheck({ fileCheck: false, ownCheck: false, dbEntry: null, webPort: 0, tools: null });
  ok('nothing-to-ask when there is no port and no recipe', r5.offer === null && r5.reason === 'nothing-to-ask');

  // The case the whole plan hinges on: StaXX knows nothing about this image
  // and it has no web port at all — the offer must be null, not merely absent.
  var r6 = H.chooseHealthCheck({ image: 'somebody/unknown-thing', fileCheck: false, ownCheck: false,
    dbEntry: null, env: {}, webPort: 0, tools: null });
  ok('an unknown image with no web port gets no offer at all', r6.offer === null);
  ok('and the reason says there was nothing to ask', r6.reason === 'nothing-to-ask');
})();

/* =========================================================================
 * B. The recipe's own variable requirement
 * ========================================================================= */

console.log('\nB. A recipe needing an unset value is never offered');

(function () {
  var postgres = D.lookupImage('postgres');
  var missing = H.chooseHealthCheck({ dbEntry: postgres, env: {}, webPort: 0, tools: null });
  ok('offer is null when the referenced env var is unset', missing.offer === null);
  ok('reason names the missing value', missing.reason === 'recipe-needs-a-value');

  // A recipe refusal must not fall through to a lesser source (web-ping) —
  // the plan's fixed order stops at the first source that *matches*, whether
  // or not that source ends up yielding an offer.
  var withWebToo = H.chooseHealthCheck({ dbEntry: postgres, env: {}, webPort: 80, tools: { curl: true } });
  ok('a failed recipe does not fall through to the web ping', withWebToo.offer === null &&
     withWebToo.reason === 'recipe-needs-a-value');

  var present = H.chooseHealthCheck({ dbEntry: postgres, env: envFor(postgres.healthcheck), webPort: 0, tools: null });
  ok('offered once the value is set', present.offer !== null && present.source === 'known-image');

  // POSTGRES_USER and POSTGRES_DB both have documented defaults (the image
  // falls back to 'postgres' for the user, and to the user for the database)
  // — the extremely common case of only a password being set must still get
  // an offer, not a refusal for two values the container will happily supply
  // itself.
  var passwordOnly = H.chooseHealthCheck({ dbEntry: postgres, env: { POSTGRES_PASSWORD: 'x' }, webPort: 0, tools: null });
  ok('a postgres service with only a password set still gets an offer',
     passwordOnly.offer !== null && passwordOnly.source === 'known-image');
})();

/* =========================================================================
 * C. tools: null means "ask for a trial", not "no offer"
 * ========================================================================= */

console.log('\nC. A web port with no trial yet');

(function () {
  var r = H.chooseHealthCheck({ dbEntry: null, webPort: 8080, tools: null });
  ok('reason is needs-a-trial, offer stays null', r.offer === null && r.reason === 'needs-a-trial');

  var none = H.chooseHealthCheck({ dbEntry: null, webPort: 8080, tools: { curl: false, wget: false } });
  ok('a trial that found neither tool yields nothing-to-ask', none.offer === null && none.reason === 'nothing-to-ask');

  var wgetOnly = H.chooseHealthCheck({ dbEntry: null, webPort: 8080, tools: { curl: false, wget: true } });
  ok('falls back to wget when curl is absent', wgetOnly.offer !== null && /wget/.test(wgetOnly.offer.test[1]));
})();

/* =========================================================================
 * D. Every recipe in db-images.json round-trips through the chooser
 * ========================================================================= */

console.log('\nD. Every recipe round-trips');

(function () {
  (TABLE.images || []).forEach(function (entry) {
    if (!entry.healthcheck) return;
    var r = H.chooseHealthCheck({ dbEntry: entry, env: envFor(entry.healthcheck), webPort: 0, tools: null });
    ok(entry.id + ': recipe is offered once its variables are set', r.offer !== null && r.source === 'known-image');
    ok(entry.id + ': claim is a non-empty sentence', typeof r.claim === 'string' && r.claim.trim().length > 0);
  });
})();

/* =========================================================================
 * E. acceptPublishedCheck — the narrow door
 * ========================================================================= */

console.log('\nE. acceptPublishedCheck refusals');

(function () {
  ok('not an array is refused', H.acceptPublishedCheck('curl localhost', {}).accepted === false);
  ok('not an array names the refusal', H.acceptPublishedCheck(null, {}).why === 'shape-not-recognised');

  ok('a mode that is not CMD/CMD-SHELL is refused',
     H.acceptPublishedCheck(['RUN', 'curl -fsS http://localhost/ -o /dev/null'], { curl: true }).accepted === false);

  ok('a curl of a non-local host is refused',
     H.acceptPublishedCheck(['CMD-SHELL', 'curl -fsS http://example.com/ -o /dev/null'], { curl: true }).accepted === false);

  ok('a shell metacharacter tacked onto an otherwise-local curl is refused',
     H.acceptPublishedCheck(['CMD-SHELL', 'curl -fsS http://localhost/ -o /dev/null; rm -rf /'], { curl: true }).accepted === false);

  ok('an unknown first word is refused',
     H.acceptPublishedCheck(['CMD-SHELL', 'some-random-tool --check'], { 'some-random-tool': true }).accepted === false);

  ok('a known tool that the trial did not find is refused',
     H.acceptPublishedCheck(['CMD-SHELL', 'mysql -uroot -e "SELECT 1"'], {}).accepted === false);

  ok('curl of localhost is not offered if curl itself is absent',
     H.acceptPublishedCheck(['CMD-SHELL', 'curl -fsS http://localhost:8080/ -o /dev/null'], { curl: false }).accepted === false);

  // The coordinator's two exact examples of the hole this fix closes: the
  // known-tool branch used to stop looking at the first word and let
  // whatever followed ride along unread.
  ok('a known tool followed by a second command via ";" is refused, not truncated',
     H.acceptPublishedCheck(['CMD-SHELL', 'redis-cli -a x ; rm -rf /'], { 'redis-cli': true }).accepted === false);
  ok('a command substitution ahead of a known tool is refused',
     H.acceptPublishedCheck(['CMD-SHELL', 'FOO=$(something) redis-cli ping'], { 'redis-cli': true }).accepted === false);

  // One case per rejected metacharacter, each otherwise a known, present tool.
  ['redis-cli ping & true', 'redis-cli ping | true', 'redis-cli `ping`', 'redis-cli ping > /tmp/x',
    'redis-cli ping < /tmp/x', 'redis-cli ping # comment', 'redis-cli $(ping)', 'redis-cli ${X}',
    'redis-cli ping\nrm -rf /'
  ].forEach(function (candidate) {
    ok('metacharacter refused: ' + JSON.stringify(candidate),
       H.acceptPublishedCheck(['CMD-SHELL', candidate], { 'redis-cli': true }).accepted === false);
  });
})();

console.log('\nE. acceptPublishedCheck acceptances');

(function () {
  ok('curl of localhost, with curl present, is accepted',
     H.acceptPublishedCheck(['CMD-SHELL', 'curl -fsS http://localhost:8080/ -o /dev/null'], { curl: true }).accepted === true);

  ok('curl of 127.0.0.1 is accepted',
     H.acceptPublishedCheck(['CMD-SHELL', 'curl -fsS http://127.0.0.1/ -o /dev/null'], { curl: true }).accepted === true);

  ok('wget of localhost, with wget present, is accepted',
     H.acceptPublishedCheck(['CMD-SHELL', 'wget -q -O /dev/null http://localhost:8080/'], { wget: true }).accepted === true);

  // A plain call to a known client tool, with nothing else in the command,
  // is accepted. Our OWN redis/valkey recipes pipe the reply into grep and
  // read an environment variable — legitimate for a curated recipe StaXX
  // wrote itself, but this door only ever sees somebody ELSE's file, so the
  // same shape must be refused here (covered under "the two examples from
  // the coordinator" below), never waved through just because the first
  // word matches.
  ok('a known client tool as the first word, with nothing else, is accepted',
     H.acceptPublishedCheck(['CMD-SHELL', 'redis-cli -a x ping'], { 'redis-cli': true }).accepted === true);

  ok('a CMD-array known tool (mongosh) is accepted the same way',
     H.acceptPublishedCheck(['CMD', 'mongosh', '--eval', "db.adminCommand('ping')"], { mongosh: true }).accepted === true);

  ok('a full-path known tool matches on its basename (mssql\'s sqlcmd)',
     H.acceptPublishedCheck(['CMD-SHELL', '/opt/mssql-tools/bin/sqlcmd -S localhost -U sa -P "$$X" -Q "SELECT 1"'],
       { sqlcmd: true }).accepted === true);
})();

/* =========================================================================
 * F. chooseHealthCheck — source 2, the project's own published example
 * ========================================================================= */

console.log('\nF. Published source order and refusals');

(function () {
  var published = {
    test: ['CMD-SHELL', 'curl -fsS http://localhost:8080/ -o /dev/null'],
    interval: '30s', timeout: '5s', retries: 3, start_period: '30s'
  };

  var mariadb = D.lookupImage('mariadb');
  var withRecipe = H.chooseHealthCheck({
    fileCheck: false, ownCheck: false, dbEntry: mariadb, env: envFor(mariadb.healthcheck),
    published: published, webPort: 8080, tools: { curl: true }
  });
  ok('the curated recipe wins over a published one when both exist',
     withRecipe.source === 'known-image');

  var noRecipe = H.chooseHealthCheck({
    fileCheck: false, ownCheck: false, dbEntry: null,
    published: published, webPort: 8080, tools: { curl: true }
  });
  ok('a published check is accepted when there is no recipe', noRecipe.source === 'published');
  ok('an accepted web-fetch shape claims only that the web page answered',
     /web page/.test(noRecipe.claim) && !/database/.test(noRecipe.claim));
  ok('the accepted offer carries the published shape verbatim',
     noRecipe.offer.interval === '30s' && noRecipe.offer.test[1] === published.test[1]);

  var refusedShape = {
    test: ['CMD-SHELL', 'curl -fsS http://example.com/ -o /dev/null'],
    interval: '30s', timeout: '5s', retries: 3, start_period: '30s'
  };
  var fallsThrough = H.chooseHealthCheck({
    fileCheck: false, ownCheck: false, dbEntry: null,
    published: refusedShape, webPort: 8080, tools: { curl: true }
  });
  ok('a published check the narrow door refuses falls through to the web ping',
     fallsThrough.source === 'web-ping');

  var refusedNoWeb = H.chooseHealthCheck({
    fileCheck: false, ownCheck: false, dbEntry: null,
    published: refusedShape, webPort: 0, tools: { curl: true }
  });
  ok('refused with no web port at all yields no offer', refusedNoWeb.offer === null &&
     refusedNoWeb.reason === 'nothing-to-ask');

  var needsTrial = H.chooseHealthCheck({
    fileCheck: false, ownCheck: false, dbEntry: null,
    published: published, webPort: 8080, tools: null
  });
  ok('a published candidate with no trial yet asks for one, rather than guessing',
     needsTrial.offer === null && needsTrial.reason === 'needs-a-trial');
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
