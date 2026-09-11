/* StaXX — tests for pullProgress(), the row-overlay parser (PLAN_138).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/pull_progress.js
 *
 * No framework, no npm, no network — the same shape as tidy.js and
 * registry_note.js: one line per case, non-zero exit on any failure.
 *
 * stacks.js cannot be required directly (see crosslinks.js's own note on
 * this) — it is page glue wired straight to the DOM, and the very first
 * line after its 'use strict' reads document.querySelector(). pullProgress()
 * itself touches neither the DOM nor any outer variable, so it is lifted out
 * of the file's text by brace-counting from its own `function pullProgress(`
 * and compiled standalone, rather than adding a module boundary to a file
 * that is deliberately browser-only everywhere else.
 *
 * Fixtures under tests/fixtures/pull-progress/ are real job logs captured
 * from the test box on 2026-09-10, plus one (failure.log) built by
 * truncating a copy mid-download and appending a realistic compose error —
 * see the fixture folder's own file names for what each one exercises.
 */

'use strict';

var fs   = require('fs');
var path = require('path');

var ROOT        = path.join(__dirname, '..');
var STACKS_PATH = path.join(ROOT, 'src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js');
var FIXTURE_DIR = path.join(ROOT, 'tests/fixtures/pull-progress');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

// Pulls the function's own source text out of stacks.js by matching braces
// from its declaration, then evaluates it as an expression — see the file
// header above for why this beats giving the page a module boundary it
// otherwise has no use for.
function loadPullProgress() {
  var src = fs.readFileSync(STACKS_PATH, 'utf8');
  var needle = 'function pullProgress(text) {';
  var start = src.indexOf(needle);
  if (start === -1) throw new Error('pullProgress() not found in stacks.js');
  var i = src.indexOf('{', start);
  var depth = 0, end = -1;
  for (; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) { end = i; break; } }
  }
  if (end === -1) throw new Error('could not find the end of pullProgress()');
  var body = src.slice(start, end + 1);
  // eslint-disable-next-line no-new-func
  return new Function('return (' + body + ');')();
}

var pullProgress = loadPullProgress();

function fixture(name) {
  return fs.readFileSync(path.join(FIXTURE_DIR, name), 'utf8');
}

function firstNLines(text, n) {
  return text.split('\n').slice(0, n).join('\n');
}

/* ---- the three real, successful transcripts ------------------------------ */

['multi-service-two-images.log', 'single-image-many-layers.log', 'layers-already-present.log']
  .forEach(function (name) {
    var prog = pullProgress(fixture(name));
    ok(name + ': ends done', prog.phase === 'done', prog.phase);
    ok(name + ': ends at 100%', prog.pct === 100, prog.pct);
    ok(name + ': every container reached Started/Healthy',
      Object.keys(prog.containers).every(function (c) { return /^(Started|Healthy)$/.test(prog.containers[c]); }),
      JSON.stringify(prog.containers));
  });

/* ---- the failure transcript ----------------------------------------------- */

(function () {
  var prog = pullProgress(fixture('failure.log'));
  ok('failure.log: ends failed', prog.phase === 'failed', prog.phase);
  ok('failure.log: names the real error',
    prog.error.indexOf('TLS handshake timeout') !== -1, prog.error);
})();

/* ---- "Already exists" counts as done -------------------------------------- */

(function () {
  var prog = pullProgress(fixture('layers-already-present.log'));
  var alreadyIds = ['e519b76d96e0', 'afe219c4758f', 'a8bb86b81e8e'];
  ok('Already exists layers all read as done',
    alreadyIds.every(function (id) { return prog.layers[id] && prog.layers[id].phase === 'done'; }),
    JSON.stringify(prog.layers));
})();

/* ---- whole-image download/unpack percentages ------------------------------ */

(function () {
  var prog = pullProgress(fixture('layers-already-present.log'));
  ok('layers-already-present.log: dlPct is 100', prog.dlPct === 100, prog.dlPct);
  ok('layers-already-present.log: exPct is 100', prog.exPct === 100, prog.exPct);
})();

(function () {
  var prog = pullProgress(firstNLines(fixture('single-image-many-layers.log'), 60));
  ok('single-image-many-layers.log mid-pull: download ahead of or level with unpack',
    prog.dlPct >= prog.exPct, JSON.stringify({ dlPct: prog.dlPct, exPct: prog.exPct }));
  ok('single-image-many-layers.log mid-pull: some download has happened',
    prog.dlPct > 0, prog.dlPct);
})();

/* ---- byte sums: a fully-finished pull has downloaded and unpacked every
 * byte its layers ever claimed, with nothing left outstanding on either side */

(function () {
  var prog = pullProgress(fixture('single-image-many-layers.log'));
  ok('single-image-many-layers.log: dlTotal is known',
    prog.dlTotal > 0, prog.dlTotal);
  ok('single-image-many-layers.log: every downloaded byte accounted for',
    prog.dlBytes === prog.dlTotal, JSON.stringify({ dlBytes: prog.dlBytes, dlTotal: prog.dlTotal }));
  ok('single-image-many-layers.log: every unpacked byte accounted for',
    prog.exBytes === prog.exTotal, JSON.stringify({ exBytes: prog.exBytes, exTotal: prog.exTotal }));
})();

/* ---- mid-file snapshot: still pulling, nothing done yet ------------------- */

(function () {
  // Line 20 of multi-service-two-images.log is mid-download, before any
  // "Container" line has appeared — see the fixture's own line numbers.
  var prog = pullProgress(firstNLines(fixture('multi-service-two-images.log'), 20));
  ok('mid-pull: phase still pull', prog.phase === 'pull', prog.phase);
  ok('mid-pull: eight layers seen', prog.layerTotal === 8, prog.layerTotal);
  ok('mid-pull: none finished yet', prog.layerDone === 0, prog.layerDone);
  ok('mid-pull: percentage is low but not zero', prog.pct > 0 && prog.pct < 50, prog.pct);
})();

/* ---- byte-unit parsing: kB vs MB must be converted before the ratio is taken */

(function () {
  var text =
    '$ compose pull\n' +
    ' aaaaaaaaaaaa Downloading [>                                                  ]  500kB/1000kB\n' +
    ' bbbbbbbbbbbb Downloading [>                                                  ]  0.5MB/1MB\n';
  var prog = pullProgress(text);
  ok('kB/kB halfway reads as 25% of that layer (half of the 0-50 download half)',
    Math.abs(prog.layers.aaaaaaaaaaaa.cur / prog.layers.aaaaaaaaaaaa.total - 0.5) < 1e-9,
    prog.layers.aaaaaaaaaaaa);
  ok('MB/MB halfway reads the same ratio regardless of unit',
    Math.abs(prog.layers.bbbbbbbbbbbb.cur / prog.layers.bbbbbbbbbbbb.total - 0.5) < 1e-9,
    prog.layers.bbbbbbbbbbbb);
  ok('overall pct is 25 for two layers each halfway through their download half',
    prog.pct === 25, prog.pct);
})();

(function () {
  // A single layer whose two sides are given in DIFFERENT units — the ratio
  // has to convert both to bytes first, or a kB/MB pair reads as almost
  // finished when it has barely started.
  var text =
    '$ compose pull\n' +
    ' cccccccccccc Downloading [>                                                  ]  310.7kB/29.76MB\n';
  var prog = pullProgress(text);
  var l = prog.layers.cccccccccccc;
  ok('mixed kB/MB pair converts both sides before dividing',
    l.cur < l.total && (l.cur / l.total) < 0.02, JSON.stringify(l));
})();

/* ---- recent holds the last three lines, in order -------------------------- */

(function () {
  var prog = pullProgress(fixture('layers-already-present.log'));
  ok('recent keeps exactly three lines', prog.recent.length === 3, prog.recent.length);
  ok('recent is in file order, oldest first', prog.recent.join('|') ===
    ['Container Dozzle  Recreated', 'Container Dozzle  Starting', 'Container Dozzle  Started'].join('|'),
    JSON.stringify(prog.recent));
})();

/* ---- incremental parsing (re-parsing the whole accumulated log on every
 * tick, per the design note beside pullProgress() itself) agrees with a
 * single whole-text parse, however the text happens to be chopped up ------- */

['multi-service-two-images.log', 'failure.log'].forEach(function (name) {
  var text  = fixture(name);
  var lines = text.split('\n');
  var mid   = Math.floor(lines.length / 2);
  var chunk1 = lines.slice(0, mid).join('\n');
  var chunk2 = text;   // the accumulated text after the second chunk arrives

  var afterFirstTick  = pullProgress(chunk1);
  var afterSecondTick = pullProgress(chunk2);
  var whole            = pullProgress(text);

  ok(name + ': second tick (full accumulated text) matches a one-shot whole-text parse',
    JSON.stringify(afterSecondTick) === JSON.stringify(whole),
    'first tick was: ' + JSON.stringify(afterFirstTick));
});

console.log('');
console.log(pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
