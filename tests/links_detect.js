/* StaXX — tests for the connection detector (PLAN_70 stage 1).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/links_detect.js
 *
 * No framework, no npm, no network — the same shape as yaml_roundtrip.js and
 * meta_scaffold.js: one line per case, and a non-zero exit if anything
 * fails. Fixtures are built as compose YAML strings, since what is under
 * test is entirely about the relationship between two services' settings,
 * not about round-tripping a file on disk.
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

function links(yaml) {
  return Y.detectLinks(Y.buildForm(Y.parse(yaml)));
}

function findKind(list, kind) {
  return list.filter(function (c) { return c.kind === kind; });
}

/* ---- case 1: a genuinely shared password -------------------------------- */
(function () {
  var y = [
    'services:',
    '  app:',
    '    image: myapp:1.0',
    '    environment:',
    '      DB_PASSWORD: Tr0ub4dor&3xyz',
    '  db:',
    '    image: postgres:15',
    '    environment:',
    '      POSTGRES_PASSWORD: Tr0ub4dor&3xyz'
  ].join('\n');
  var secrets = findKind(links(y), 'secret');
  ok('case 1: a shared password is detected', secrets.length === 1, JSON.stringify(secrets));
  if (secrets.length === 1) {
    ok('case 1: certainty is inferred', secrets[0].certainty === 'inferred');
    var svcs = secrets[0].between.map(function (e) { return e.service; }).sort();
    ok('case 1: names both services', svcs[0] === 'app' && svcs[1] === 'db', svcs);
  }
})();

/* ---- case 2: TZ/PUID are not links --------------------------------------- */
(function () {
  var y = [
    'services:',
    '  app:',
    '    image: myapp:1.0',
    '    environment:',
    '      TZ: Europe/London',
    '      PUID: 99',
    '  db:',
    '    image: postgres:15',
    '    environment:',
    '      TZ: Europe/London',
    '      PUID: 99'
  ].join('\n');
  ok('case 2: TZ/PUID are not flagged as a secret', findKind(links(y), 'secret').length === 0);
})();

/* ---- case 3: an unmarked value shared by three services is a convention - */
(function () {
  var y = [
    'services:',
    '  a:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz',
    '  b:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz',
    '  c:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz'
  ].join('\n');
  ok('case 3: an unmarked value shared by three services is dropped entirely',
     findKind(links(y), 'secret').length === 0);
})();

/* ---- case 3b: the same, but one field is marked secret ------------------ */
(function () {
  var y = [
    'services:',
    '  a:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz  # -!S',
    '  b:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz',
    '  c:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz'
  ].join('\n');
  var secrets = findKind(links(y), 'secret');
  ok('case 3b: a marked value shared by three services is every pair, not dropped',
     secrets.length === 3, JSON.stringify(secrets));
  var pairKeys = secrets.map(function (c) {
    return c.between.map(function (e) { return e.service; }).sort().join('-');
  }).sort();
  ok('case 3b: the three pairs are a-b, a-c, b-c',
     pairKeys.join(',') === 'a-b,a-c,b-c', pairKeys);
})();

/* ---- case 3c: four services sharing a marked secret — combinatorial ----- */
(function () {
  var y = [
    'services:',
    '  a:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz  # -!S',
    '  b:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz',
    '  c:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz',
    '  d:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz'
  ].join('\n');
  var secrets = findKind(links(y), 'secret');
  ok('case 3c: four services sharing a marked secret produces six pairs',
     secrets.length === 6, JSON.stringify(secrets));
})();

/* ---- case 3d: the marked field is the third one encountered ------------- */
(function () {
  var y = [
    'services:',
    '  a:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz',
    '  b:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz',
    '  c:', '    image: x', '    environment:', '      SECRET: Tr0ub4dor&3xyz  # -!S'
  ].join('\n');
  var secrets = findKind(links(y), 'secret');
  ok('case 3d: a marking anywhere in the group is enough, checked across the whole group',
     secrets.length === 3, JSON.stringify(secrets));
})();

/* ---- case 4: marking overrides the floor --------------------------------- */
(function () {
  var y = [
    'services:',
    '  app:', '    image: x', '    environment:',
    '      PW: abc123  # -!S',
    '  db:', '    image: postgres', '    environment:',
    '      PW2: abc123  # -!S'
  ].join('\n');
  var secrets = findKind(links(y), 'secret');
  ok('case 4: a short marked-secret value is still detected', secrets.length === 1, JSON.stringify(secrets));
})();

/* ---- case 5: same host path bound in two services ------------------------ */
(function () {
  var y = [
    'services:',
    '  a:', '    image: x', '    volumes:', '      - /mnt/user/media:/data',
    '  b:', '    image: x', '    volumes:', '      - /mnt/user/media:/data'
  ].join('\n');
  var folders = findKind(links(y), 'folder');
  ok('case 5: the same folder is detected', folders.length === 1, JSON.stringify(folders));
  if (folders.length === 1) ok('case 5: variant is same', folders[0].variant === 'same');
})();

/* ---- case 6: media vs mediaserver must not match -------------------------- */
(function () {
  var y = [
    'services:',
    '  a:', '    image: x', '    volumes:', '      - /mnt/user/media:/data',
    '  b:', '    image: x', '    volumes:', '      - /mnt/user/mediaserver:/data'
  ].join('\n');
  ok('case 6: a prefix that is not a path boundary does not match', findKind(links(y), 'folder').length === 0);
})();

/* ---- case 7: a genuine nested path ---------------------------------------- */
(function () {
  var y = [
    'services:',
    '  a:', '    image: x', '    volumes:', '      - /mnt/user/media:/data',
    '  b:', '    image: x', '    volumes:', '      - /mnt/user/media/movies:/movies'
  ].join('\n');
  var folders = findKind(links(y), 'folder');
  ok('case 7: a folder nested at a real boundary is detected', folders.length === 1, JSON.stringify(folders));
  if (folders.length === 1) ok('case 7: variant is nested', folders[0].variant === 'nested');
})();

/* ---- case 8: depends_on is a declared fact -------------------------------- */
(function () {
  var y = [
    'services:',
    '  app:', '    image: x', '    depends_on:', '      - db',
    '  db:', '    image: postgres'
  ].join('\n');
  var refs = findKind(links(y), 'reference');
  ok('case 8: depends_on is detected', refs.length === 1, JSON.stringify(refs));
  if (refs.length === 1) ok('case 8: certainty is declared', refs[0].certainty === 'declared');
})();

/* ---- case 9: a four-letter-plus service name inferred from a value ------- */
(function () {
  var y = [
    'services:',
    '  app:', '    image: x', '    environment:', '      DB_HOST: postgres',
    '  postgres:', '    image: postgres'
  ].join('\n');
  var refs = findKind(links(y), 'reference');
  ok('case 9: a service name inside a value is inferred', refs.length === 1, JSON.stringify(refs));
  if (refs.length === 1) ok('case 9: certainty is inferred', refs[0].certainty === 'inferred');
})();

/* ---- case 10: a short service name must not match mid-string ------------- */
(function () {
  var y = [
    'services:',
    '  app:', '    image: x', '    environment:', '      NOTE: unrelated-dbstring-here',
    '  db:', '    image: postgres'
  ].join('\n');
  ok('case 10: a short name buried in a word does not match', findKind(links(y), 'reference').length === 0);
})();

/* ---- case 11: a short service name in host:port position matches -------- */
(function () {
  var y = [
    'services:',
    '  app:', '    image: x', '    environment:', '      DB_ADDR: db:3306',
    '  db:', '    image: postgres'
  ].join('\n');
  var refs = findKind(links(y), 'reference');
  ok('case 11: a short name in host:port position is inferred', refs.length === 1, JSON.stringify(refs));
})();

/* ---- case 12: a value equal to a declared name is not a secret ----------- */
(function () {
  var y = [
    'services:',
    '  app:', '    image: mysecretimage123', '    environment:', '      TAG: mysecretimage123',
    '  db:', '    image: postgres', '    environment:', '      TAG: mysecretimage123'
  ].join('\n');
  ok('case 12: a value equal to a declared image name is not a secret', findKind(links(y), 'secret').length === 0);
})();

/* ---- case 13: two pointers at the same variable are not a shared secret --
 * Both sides read the value from outside the file, so they agree by
 * construction and always will. Linking them would be noise; honouring the
 * link would be destructive, since propagation writes a plain value and so
 * would replace both pointers with a fixed string. Marking one secret must
 * not buy a way past this — a pointer is still a pointer. */
(function () {
  var y = [
    'services:',
    '  app:', '    image: myapp', '    environment:',
    '      DB_PASSWORD: ${DB_PASSWORD:?set DB_PASSWORD in .env}',
    '      DB_USER: ${DB_USER:-supstack}',
    '      BARE: $SOME_LONG_VARIABLE_NAME',
    '  db:', '    image: mariadb', '    environment:',
    '      MARIADB_PASSWORD: ${DB_PASSWORD:?set DB_PASSWORD in .env}',
    '      MARIADB_USER: ${DB_USER:-supstack}',
    '      BARE: $SOME_LONG_VARIABLE_NAME'
  ].join('\n');
  ok('case 13: values reading a variable are not a shared secret',
     findKind(links(y), 'secret').length === 0, JSON.stringify(findKind(links(y), 'secret')));

  var marked = [
    'services:',
    '  app:', '    image: myapp', '    environment:',
    '      DB_PASSWORD: ${DB_PASSWORD}   # -!S',
    '  db:', '    image: mariadb', '    environment:',
    '      MARIADB_PASSWORD: ${DB_PASSWORD}   # -!S'
  ].join('\n');
  ok('case 13: marking a pointer secret does not make it one',
     findKind(links(marked), 'secret').length === 0, JSON.stringify(findKind(links(marked), 'secret')));

  // $$ is an escaped literal dollar, so this really is a shared plain value.
  var escaped = [
    'services:',
    '  app:', '    image: myapp', '    environment:', '      TOKEN: $$Tr0ub4dor&3xyz',
    '  db:', '    image: mariadb', '    environment:', '      TOKEN: $$Tr0ub4dor&3xyz'
  ].join('\n');
  ok('case 13: an escaped dollar is a plain value, still detected',
     findKind(links(escaped), 'secret').length === 1, JSON.stringify(findKind(links(escaped), 'secret')));
})();

/* ---- case 14: a service name in the wrong case still matches -------------
 * Docker's own name resolution ignores case — a container answers to its
 * name however it is written, confirmed on the server by asking a running
 * container to look one up both ways. So a name typed in the wrong case is
 * a working address and has to be recognised. NAMES only: case 15 holds the
 * line for values. */
(function () {
  var y = [
    'services:',
    '  app:', '    image: myapp', '    environment:', '      DB_HOST: POSTGRES',
    '  postgres:', '    image: postgres'
  ].join('\n');
  var refs = findKind(links(y), 'reference');
  ok('case 14: a four-plus-letter service name in the wrong case is matched',
     refs.length === 1, JSON.stringify(refs));

  var short = [
    'services:',
    '  app:', '    image: myapp', '    environment:', '      DB_HOST: DB:3306',
    '  db:', '    image: mariadb'
  ].join('\n');
  ok('case 14: a short name in host position, wrong case, is matched',
     findKind(links(short), 'reference').length === 1,
     JSON.stringify(findKind(links(short), 'reference')));
})();

/* ---- case 15: a shared value is still compared exactly ------------------
 * The line the case above must not cross. Two passwords differing only in
 * case are two passwords, and nothing about DNS says otherwise. */
(function () {
  var y = [
    'services:',
    '  app:', '    image: myapp', '    environment:', '      TOKEN: Tr0ub4dor&3xyz',
    '  db:', '    image: mariadb', '    environment:', '      TOKEN: tr0ub4dor&3XYZ'
  ].join('\n');
  ok('case 15: two passwords differing only in case are not the same secret',
     findKind(links(y), 'secret').length === 0, JSON.stringify(findKind(links(y), 'secret')));
})();

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
