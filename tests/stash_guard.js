/* StaXX — tests for the set-aside guard (PLAN_81).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/stash_guard.js
 *
 * No framework, no npm, no network — the same shape as yaml_roundtrip.js and
 * validate_schema.py: one line per case, and a non-zero exit if anything
 * fails.
 *
 * Two writers in the compose model take a service's lines out of the file
 * verbatim and later splice them back verbatim: network_stash (the network
 * row's dropdown) and sections (stashSection/restoreSection). Both trusted
 * the stored "lines" array to still be one clean block. It is not enough for
 * a stored line to look right — it can be an array element holding its own
 * embedded newline, which becomes several real lines, at whatever column its
 * text says, the moment the document is joined back with '\n'. stashLinesOk
 * is the one shared check that closes that hole for both writers; this file
 * proves it does, and proves it does not reject any file StaXX itself wrote.
 */

'use strict';

var fs   = require('fs');
var path = require('path');

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

function firstDiff(a, b) {
  var A = a.split('\n'), B = b.split('\n');
  for (var i = 0; i < Math.max(A.length, B.length); i++) {
    if (A[i] !== B[i]) {
      return 'line ' + (i + 1) + '\n  was: ' + JSON.stringify(A[i]) + '\n  now: ' + JSON.stringify(B[i]);
    }
  }
  return 'lengths ' + a.length + ' vs ' + b.length;
}

// Names of every service the model's own form-builder can see, so a check
// for "no extra service appeared" reads the document the way the form does,
// not just as text.
function serviceNames(text) {
  return Y.buildForm(Y.parse(text)).services.map(function (s) { return s.name; });
}

/* =========================================================================
 * 1. The two proved exploits — PLAN_81's own reproductions, verbatim.
 *
 * These must never quietly start passing for the wrong reason. Do not
 * "simplify" either one down to a line count: the whole point of the bug is
 * that the file's line count and shape look fine right up until the stash
 * is put back, so the only assertion worth trusting is that no service
 * called "evil" exists afterwards.
 * ========================================================================= */

console.log('\n1. The proved exploits');

(function () {
  // PLAN_81's network_stash reproduction. The compose file itself is one
  // physical line — the "\n" inside the quoted JSON string is two literal
  // characters (backslash, n), valid inside a JSON string and inert in the
  // file as written. JSON.parse turns that escape into a real newline
  // character living inside the "lines" array element, which is the thing
  // stashLinesOk has to catch: nothing about the file on disk looks wrong.
  var src = 'services:\n' +
            '  jellyfin:\n' +
            '    image: jellyfin/jellyfin\n' +
            '    x-unraid:\n' +
            '      network_stash: \'{"after":"image","lines":["    networks:\\n      - mybridge\\n  evil:\\n    image: alpine\\n    privileged: true\\n    volumes:\\n      - /:/host"]}\'\n' +
            '    network_mode: host\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  ok('exploit file parses with one service before restoring',
     serviceNames(src).length === 1 && serviceNames(src)[0] === 'jellyfin');

  var res = Y.restoreNetworkStash(doc, form, 'jellyfin');
  ok('restoring a corrupt network_stash reports ok with nothing restored',
     res && res.ok === true && res.restored === false, JSON.stringify(res));
  ok('and says out loud that a stash was thrown away, per PLAN_81',
     res && res.discarded === true, JSON.stringify(res));

  var out = Y.serialise(doc);
  var names = serviceNames(out);
  // The assertion that matters: no service named "evil" exists in the
  // output. Not a line count — a line count can pass for the wrong reason.
  ok('the injected "evil" service does not appear in the restored file',
     names.indexOf('evil') === -1, names.join(','));
  ok('the only service left is the one that was always there',
     names.length === 1 && names[0] === 'jellyfin', names.join(','));
})();

(function () {
  // PLAN_81 rule 5, found during the build rather than during the review.
  // network_stash splices its lines back at the indentation they were STORED
  // with, and every other rule here is self-relative — a block whose key sits
  // at column zero has children indented deeper than it, so it passes all of
  // them. Spliced into the middle of a service it ends the services map and
  // orphans everything below. It cannot invent a service, so this is
  // destruction rather than injection, but a file quietly wrecked is the same
  // rule broken from the other end.
  var src = 'services:\n' +
            '  jellyfin:\n' +
            '    image: jellyfin/jellyfin\n' +
            '    x-unraid:\n' +
            '      network_stash: \'{"after":"image","lines":["networks:","  bad:","    x: 1"]}\'\n' +
            '    network_mode: host\n' +
            '    restart: always\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  ok('a stash at column zero fails the check despite every other rule passing',
     Y.readNetworkStash(doc, 'jellyfin') === null);

  var res = Y.restoreNetworkStash(doc, form, 'jellyfin');
  ok('the column-zero stash is discarded and reported, not spliced',
     res.ok === true && res.restored === false && res.discarded === true,
     JSON.stringify(res));

  var out = Y.serialise(doc);
  // The assertion that matters: the service keeps the setting that sat below
  // where the block would have landed. A line count would pass for the wrong
  // reason here, since the splice adds and orphans in the same move.
  ok('the service keeps the setting that the splice would have orphaned',
     /\n {4}restart: always/.test(out), out);
  ok('no top-level networks: block was created mid-file',
     !/\nnetworks:/.test(out), out);
})();

(function () {
  // The same shape must still be accepted where the indent genuinely matches
  // the service, or the rule above would be refusing real files.
  ok('stashLinesOk accepts a block whose indent matches what is asked for',
     Y.stashLinesOk(['    networks:', '      - mybridge'], 'networks', 4) === true);
  ok('stashLinesOk rejects the same block when the service sits elsewhere',
     Y.stashLinesOk(['    networks:', '      - mybridge'], 'networks', 2) === false);
  ok('stashLinesOk ignores indent entirely when none is asked for',
     Y.stashLinesOk(['networks:', '  - mybridge'], 'networks') === true);
})();

(function () {
  // PLAN_81's sections reproduction, in the single-line form that actually
  // survives JSON.parse (a literal, un-escaped newline inside a JSON string
  // is invalid JSON and would throw before ever reaching stashLinesOk — the
  // exploit has to arrive as an escape, exactly as the network_stash one
  // above does). The stash sits under the file's own x-unraid.sections,
  // rooted at the document rather than the service, exactly as
  // stashSection/restoreSection read and write it.
  var src = 'services:\n' +
            '  jellyfin:\n' +
            '    image: jellyfin/jellyfin\n' +
            'x-unraid:\n' +
            '  sections:\n' +
            '    jellyfin:\n' +
            '      deploy: \'{"after":null,"lines":["deploy:\\n  evil:\\n    image: alpine\\n    privileged: true"]}\'\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  ok('sections exploit file parses with one service before restoring',
     serviceNames(src).length === 1 && serviceNames(src)[0] === 'jellyfin');
  ok('sectionStashOk reports the stored stash as failing the check',
     Y.sectionStashOk(doc, 'jellyfin', 'deploy') === false);

  var res = Y.restoreSection(doc, form, 'jellyfin', 'deploy');
  ok('restoreSection reports success (the corrupt entry is dropped, not a refusal)', res === true);

  var out = Y.serialise(doc);
  var names = serviceNames(out);
  ok('the injected "evil" service does not appear in the restored file',
     names.indexOf('evil') === -1, names.join(','));
  ok('the only service left is the one that was always there',
     names.length === 1 && names[0] === 'jellyfin', names.join(','));

  var docAfter = Y.parse(out);
  ok('the discarded section entry is gone rather than left behind unreadable',
     Y.sectionStashOk(docAfter, 'jellyfin', 'deploy') === null,
     JSON.stringify(Y.readSections(docAfter)));
})();

/* =========================================================================
 * 2. stashLinesOk — one case per rule.
 * ========================================================================= */

console.log('\n2. stashLinesOk — negative cases (each closes one hole)');

ok('an embedded newline in a line is rejected — this is the rule that matters',
   Y.stashLinesOk(['networks:', '  - my\nbridge'], 'networks') === false);

ok('an embedded carriage return in a line is rejected',
   Y.stashLinesOk(['networks:', '  - my\rbridge'], 'networks') === false);

ok('a tab in the leading whitespace is rejected — YAML forbids tabs for indent',
   Y.stashLinesOk(['networks:', '\t- mybridge'], 'networks') === false);

ok('a sibling key at the base indent is rejected',
   Y.stashLinesOk(['networks:', '  - mybridge', 'evil:', '  image: alpine'], 'networks') === false);

ok('two key-shaped lines both at the base indent are rejected',
   Y.stashLinesOk(['networks:', 'volumes:'], null) === false);

ok('a line dedented past the base indent is rejected',
   Y.stashLinesOk(['    networks:', '      - mybridge', '  - orphan'], 'networks') === false);

ok('a comment sitting at the base indent after the key line is rejected',
   Y.stashLinesOk(['networks:', '  - mybridge', '# a comment back at column zero'], 'networks') === false);

ok('an empty array is rejected',
   Y.stashLinesOk([], 'networks') === false);

ok('an array holding a non-string element is rejected',
   Y.stashLinesOk(['networks:', 42], 'networks') === false);

(function () {
  var lines = ['networks:'];
  for (var i = 0; i < 200; i++) lines.push('  - net' + i);
  ok('201 lines is over the count cap and is rejected', Y.stashLinesOk(lines, 'networks') === false);
})();

(function () {
  var lines = ['networks:', '  - ' + new Array(20000).join('x')];
  ok('a total length over 20000 characters is rejected', Y.stashLinesOk(lines, 'networks') === false);
})();

console.log('\n3. stashLinesOk — positive cases (a file StaXX wrote must always pass)');

ok('a plain two-entry networks block passes',
   Y.stashLinesOk(['    networks:', '      - mybridge', '      - proxy'], 'networks') === true);

ok('a comment sitting above the networks: key passes — the capture deliberately keeps it',
   Y.stashLinesOk(['    # a note about networking', '    networks:', '      - mybridge'], 'networks') === true);

ok('a comment on an entry inside the block passes',
   Y.stashLinesOk(['    networks:', '      - mybridge',
                   '      # proxy is for admin access only', '      - proxy'], 'networks') === true);

ok('a blank line inside the block passes',
   Y.stashLinesOk(['    networks:', '      - mybridge', '', '      - proxy'], 'networks') === true);

ok('a fixed IP address written as a nested mapping under a network name passes',
   Y.stashLinesOk(['    networks:', '      mybridge:', '        ipv4_address: 192.168.1.50'], 'networks') === true);

ok('a networks block that was the service\u2019s first key (indent 2, not 4) passes',
   Y.stashLinesOk(['  networks:', '    - mybridge'], 'networks') === true);

ok('a section entry stored with zero base indent passes — sections strip and re-pad, so 0 is normal there',
   Y.stashLinesOk(['deploy:', '  resources:', '    limits:', '      cpus: \'0.50\''], null) === true);

/* =========================================================================
 * 3. sectionStashOk — the three-way return.
 * ========================================================================= */

console.log('\n4. sectionStashOk');

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n';
  var doc = Y.parse(src);
  ok('no stashed block for the key reports null',
     Y.sectionStashOk(doc, 'a', 'healthcheck') === null);
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  ok('stashSection succeeds so there is a genuine stash to check', Y.stashSection(doc, form, 'a', ['healthcheck']));
  ok('a stash StaXX itself wrote reports true',
     Y.sectionStashOk(doc, 'a', 'healthcheck') === true);
})();

(function () {
  // Same malformed entry as the exploit above, but read directly rather
  // than through a restore.
  var src = 'services:\n  a:\n    image: alpine\n' +
            'x-unraid:\n  sections:\n    a:\n' +
            '      healthcheck: \'{"after":null,"lines":["healthcheck:\\n  evil:\\n    image: alpine"]}\'\n';
  var doc = Y.parse(src);
  ok('a stash present but failing the check reports false',
     Y.sectionStashOk(doc, 'a', 'healthcheck') === false);
})();

/* =========================================================================
 * 4. Full round trip through the real writers — byte for byte.
 * ========================================================================= */

console.log('\n5. Full round trip: setNetworkMode(host) then restoreNetworkStash, byte-identical');

(function () {
  var file = path.join(__dirname, 'fixtures', 'test-stacks', '19-missing-network', 'compose.yaml');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

  var doc = Y.parse(text), form = Y.buildForm(doc);
  var setRes = Y.setNetworkMode(doc, form, 'app', 'host');
  ok('setNetworkMode(host) succeeds on a service with a real networks: block',
     setRes && setRes.ok === true, JSON.stringify(setRes));

  var restoreRes = Y.restoreNetworkStash(doc, form, 'app');
  ok('restoreNetworkStash reports the stash as genuinely restored, not discarded',
     restoreRes && restoreRes.ok === true && restoreRes.restored === true && !restoreRes.discarded,
     JSON.stringify(restoreRes));

  var out = Y.serialise(doc);
  ok('the round trip is byte-for-byte identical to the original file',
     out === text, firstDiff(text, out));
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
if (fail > 0) process.exit(1);
