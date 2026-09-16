/* StaXX — tests for the merge wizard's step 5 ("Suggestions") writer.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_suggest.js
 *
 * No framework, no npm, no network — the same shape as pin_image.js: one
 * line per case, and a non-zero exit if anything fails. merge-suggest.js
 * never re-derives the merged file itself (that stays buildMergedText()'s
 * job); this only proves it writes depends_on/healthcheck/x-unraid.update
 * correctly on top of whatever text it is handed, and — the one rule the
 * whole step exists to enforce — that turning a target's health check off
 * silently downgrades every condition pointing at it.
 */

'use strict';

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var MS = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-suggest.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

var BASE = 'services:\n' +
           '  web:\n' +
           '    image: nginx:latest\n' +
           '  db:\n' +
           '    image: mariadb:11\n';

// A text with an existing healthcheck on db, exactly as a source's own
// file would carry it through unchanged from steps 3-4.
var WITH_EXISTING = 'services:\n' +
                    '  web:\n' +
                    '    image: nginx:latest\n' +
                    '  db:\n' +
                    '    image: mariadb:11\n' +
                    '    healthcheck:\n' +
                    '      test: ["CMD", "healthcheck.sh"]\n';

function parsesClean(text) {
  var doc = CM.parse(text);
  return !doc.unreadTail;
}

console.log('\n1. Dependencies — condition follows the target\'s own health, not the tick alone');

(function () {
  var r = MS.apply(BASE, { deps: [{ from: 'web', to: 'db' }] });
  ok('no health suggested at all: waits for it to START, not to be healthy',
     /depends_on:\n\s+db:\n\s+condition: service_started/.test(r.text), r.text);
})();

(function () {
  var r = MS.apply(BASE, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { on: true, source: 'own', test: ['CMD-SHELL', 'true'], interval: '10s', timeout: '5s', retries: 3 } }
  });
  ok('a target getting a real check written this same call: waits for it to be HEALTHY',
     /depends_on:\n\s+db:\n\s+condition: service_healthy/.test(r.text), r.text);
})();

(function () {
  // The single most important behaviour on the step (PLAN_155): ticking a
  // health row on with nothing decided yet ("StaXX will work one out",
  // source 'later') must never let a waiter claim service_healthy —
  // nothing is written into the file for compose to watch, so the wait
  // could never pass.
  var r = MS.apply(BASE, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { on: true, source: 'later' } }
  });
  ok('switched-on-but-undecided (source later, nothing written) downgrades to service_started',
     /condition: service_started/.test(r.text) && !/service_healthy/.test(r.text), r.text);
})();

(function () {
  // The explicit downgrade case: health was on, then switched off — proven
  // by calling apply() again against the SAME pristine text with the
  // updated answer, which is how the wizard itself always calls this (see
  // this file's own header comment).
  var on = MS.apply(BASE, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { on: true, source: 'own', test: ['CMD-SHELL', 'true'], interval: '10s', timeout: '5s', retries: 3 } }
  });
  var off = MS.apply(BASE, { deps: [{ from: 'web', to: 'db' }], health: { db: { on: false } } });
  ok('switching the target\'s health check off downgrades the condition',
     /service_healthy/.test(on.text) && /service_started/.test(off.text) && !/service_healthy/.test(off.text));
})();

(function () {
  var r = MS.apply(BASE, { deps: [{ from: 'ghost', to: 'db' }, { from: 'web', to: 'ghost' }] });
  ok('a dependency naming a service that does not exist in the file writes nothing',
     r.text === CM.serialise(CM.parse(BASE)), r.text);
})();

(function () {
  var r = MS.apply(WITH_EXISTING, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { covered: true } }
  });
  ok('a target whose check already lived in the source file (covered) still counts as healthy',
     /condition: service_healthy/.test(r.text), r.text);
})();

(function () {
  var r = MS.apply(BASE, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { covered: true } }
  });
  ok('an image\'s own DECLARED check (covered, nothing written for it) still counts as healthy',
     /condition: service_healthy/.test(r.text) && !/healthcheck:/.test(r.text), r.text);
})();

console.log('\n2. Health checks — writing, and never overwriting what a source already carried');

(function () {
  var r = MS.apply(BASE, {
    health: { db: { on: true, source: 'own', test: ['CMD-SHELL', 'mysqladmin ping'], interval: '15s', timeout: '5s', retries: 3 } }
  });
  ok('writes test/interval/timeout/retries under the named service',
     /db:\n\s+image: mariadb:11\n\s+healthcheck:\n\s+test: \["CMD-SHELL", "mysqladmin ping"\]\n\s+interval: 15s\n\s+timeout: 5s\n\s+retries: 3/.test(r.text),
     r.text);
  ok('the written file still parses cleanly', parsesClean(r.text));
})();

(function () {
  var r = MS.apply(BASE, { health: { web: { on: true, source: 'own', test: ['NONE'] } } });
  ok('mode NONE writes healthcheck: {test: ["NONE"]} rather than being refused',
     /web:\n\s+image: nginx:latest\n\s+healthcheck:\n\s+test: \["NONE"\]/.test(r.text), r.text);
})();

(function () {
  var r = MS.apply(WITH_EXISTING, {
    health: { db: { covered: true } }
  });
  ok('a service marked covered (the file already has one) is left byte-for-byte alone',
     r.text === CM.serialise(CM.parse(WITH_EXISTING)), r.text);
})();

(function () {
  var r = MS.apply(BASE, { health: { db: { on: true, source: 'later' } } });
  ok('"on" with source later writes nothing — no known-image shortcut writes a check at this step',
     r.text === CM.serialise(CM.parse(BASE)), r.text);
})();

console.log('\n3. Updates and notifications');

(function () {
  var r = MS.apply(BASE, { update: { mode: 'default', notify: { touched: false, found: true, installed: false, failed: true } } });
  ok('an all-quiet update answer (Default, notify untouched) writes no x-unraid block at all, even though the current answers are still passed in',
     r.text === CM.serialise(CM.parse(BASE)), r.text);
})();

(function () {
  var r = MS.apply(BASE, { update: { mode: 'manual' } });
  ok('Manual writes mode: manual and nothing else',
     /x-unraid:\n\s+update:\n\s+mode: manual\n/.test(r.text) && !/delay:/.test(r.text) && !/notify:/.test(r.text),
     r.text);
})();

(function () {
  var r = MS.apply(BASE, { update: { mode: 'auto', immediate: true } });
  ok('Automatic + Immediately writes mode: auto and delay: 0',
     /mode: auto\n\s+delay: 0\n/.test(r.text), r.text);
})();

(function () {
  var r = MS.apply(BASE, { update: { mode: 'auto', immediate: false } });
  ok('Automatic + after the (global) delay writes mode: auto with no delay key',
     /mode: auto\n/.test(r.text) && !/delay:/.test(r.text), r.text);
})();

(function () {
  var r = MS.apply(BASE, { update: { notify: { touched: true, found: true, installed: false, failed: true } } });
  ok('touching the row writes all three switches, in order',
     /notify:\n\s+found: true\n\s+installed: false\n\s+failed: true\n/.test(r.text), r.text);
  ok('the old single boolean spelling is never written by this wizard', !/notify: (true|false)\n/.test(r.text), r.text);
})();

(function () {
  var r = MS.apply(BASE, { update: { notify: { touched: false, found: false, installed: false, failed: false } } });
  ok('every notify switch left untouched writes no notify key at all', !/notify:/.test(r.text), r.text);
})();

(function () {
  var r = MS.apply(BASE, { update: { notify: { touched: true, found: false, installed: false, failed: false } } });
  ok('touching the row writes all three even when every switch now reads off',
     /notify:\n\s+found: false\n\s+installed: false\n\s+failed: false\n/.test(r.text), r.text);
})();

console.log('\n4. What the right-hand pane highlights, and idempotence');

(function () {
  var r = MS.apply(BASE, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { on: true, source: 'own', test: ['CMD-SHELL', 'true'], interval: '10s', timeout: '5s', retries: 3 } },
    update: { mode: 'auto', immediate: true, notify: { touched: true, found: true, installed: true, failed: true } }
  });
  var lines = r.text.split('\n');
  var everyAddedLineIsNew = r.added.every(function (i) { return BASE.indexOf(lines[i]) === -1 || lines[i].trim() === ''; });
  ok('added lines are all lines this call actually introduced', r.added.length > 0 && everyAddedLineIsNew, JSON.stringify(r.added));

  var again = MS.apply(r.text, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { on: true, source: 'own', test: ['CMD-SHELL', 'true'], interval: '10s', timeout: '5s', retries: 3 } },
    update: { mode: 'auto', immediate: true, notify: { touched: true, found: true, installed: true, failed: true } }
  });
  ok('applying the very same answers again is a true no-op', again.text === r.text && again.added.length === 0, again.text);
})();

(function () {
  var r = MS.apply(BASE, {});
  ok('no suggestion at all leaves the file exactly as it was',
     r.text === CM.serialise(CM.parse(BASE)) && r.added.length === 0);
})();

console.log('\n5. The result always round-trips through compose-model.js itself');

(function () {
  var r = MS.apply(BASE, {
    deps: [{ from: 'web', to: 'db' }],
    health: { db: { on: true, source: 'own', test: ['CMD', 'mysqladmin', 'ping'], interval: '10s', timeout: '5s', retries: 3 } },
    update: { mode: 'auto', immediate: false, notify: { touched: true, found: false, installed: false, failed: true } }
  });
  var doc = CM.parse(r.text);
  ok('re-parsing the written text hits no unread tail', !doc.unreadTail, r.text);
  ok('re-serialising a fresh parse of it is byte-identical', CM.serialise(doc) === r.text);
})();

console.log('\n' + pass + ' passed, ' + fail + ' failed');
if (fail > 0) process.exit(1);
