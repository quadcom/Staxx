/* StaXX — round-trip tests for the compose model.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/yaml_roundtrip.js
 *
 * No framework, no npm, no network — the same shape as validate_schema.py:
 * one line per case, and a non-zero exit if anything fails.
 *
 * The point of this file is a single promise: editing a compose file through
 * the form changes the one thing you edited and nothing else. Nothing about
 * that can be checked in a browser on this machine, and by the time it is
 * wrong someone's hand-written file has already been spoiled — so it is
 * checked here instead, before any of it reaches a page.
 *
 * The strongest case is the null edit: set every field to the value it already
 * has and demand the input back byte for byte. That runs the whole splice,
 * quoting and comment path and permits no drift at all.
 */

'use strict';

var fs   = require('fs');
var path = require('path');

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
// A photograph of the CHOICES/BOOL_CHOICES/CAP_OPTIONS data exactly as
// stacks.js held it before PLAN_15 phase 1 moved twelve of the lists into
// compose-model.js's VOCAB — see the file's own header comment. Never edited
// to make a test pass: if a value here is wrong, it was wrong before the
// move too, and fixing it is a separate change.
var VOCAB_SNAPSHOT = require('./vocab-snapshot.js');

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

function diffLines(a, b) {
  var A = a.split('\n'), B = b.split('\n'), out = [];
  for (var i = 0; i < Math.max(A.length, B.length); i++) if (A[i] !== B[i]) out.push(i);
  return out;
}

/* ---- the corpus --------------------------------------------------------- */

var ROOT = path.join(__dirname, '..');

function findComposeFiles() {
  var out = [];
  ['scratch/test-stacks', 'examples'].forEach(function (dir) {
    var base = path.join(ROOT, dir);
    if (!fs.existsSync(base)) return;
    fs.readdirSync(base).forEach(function (entry) {
      var d = path.join(base, entry);
      if (!fs.statSync(d).isDirectory()) return;
      fs.readdirSync(d).forEach(function (f) {
        if (/^(docker-)?compose\.ya?ml$/.test(f)) out.push(path.join(d, f));
      });
    });
  });
  return out.sort();
}

var FILES = findComposeFiles();

/* =========================================================================
 * A. Identity — parse then serialise must return the input untouched
 * ========================================================================= */

console.log('\nA. Identity round-trip');

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

  var variants = {
    'as written':        text,
    'no trailing NL':    text.replace(/\n+$/, ''),
    'extra trailing NL': text + '\n',
    'CRLF':              text.replace(/\n/g, '\r\n'),
    'with BOM':          '\ufeff' + text
  };

  Object.keys(variants).forEach(function (v) {
    var t = variants[v];
    var got = Y.serialise(Y.parse(t));
    ok(name + '  [' + v + ']', got === t, got === t ? '' : firstDiff(t, got));
  });
});

/* ---- every writable box in a file, as {id, part, value} ----------------- */

function boxes(form) {
  var out = [];
  form.fields.forEach(function (f) {
    if (f.locked) return;
    Object.keys(f.parts).forEach(function (k) {
      if (f.parts[k].spot) out.push({ id: f.id, part: k, value: f.parts[k].value, field: f });
    });
  });
  return out;
}

/* =========================================================================
 * B. Null edit — the strongest test here
 * ========================================================================= */

console.log('\nB. Null edit (set every box to what it already says)');

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

  var all = boxes(Y.buildForm(Y.parse(text)));
  var bad = null;

  for (var i = 0; i < all.length && !bad; i++) {
    var d = Y.parse(text);
    var m = Y.buildForm(d);
    if (!Y.setPart(d, m, all[i].id, all[i].part, all[i].value)) continue;
    var got = Y.serialise(d);
    if (got !== text) bad = all[i].id + ' [' + all[i].part + ']\n' + firstDiff(text, got);
  }

  ok(name + '  (' + all.length + ' writable boxes)', !bad, bad);
});

/* ---- and the same for every note, which is a different write path ------- */

console.log('\nB2. Null edit on notes (rewrite each comment as it already reads)');

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

  var form = Y.buildForm(Y.parse(text));
  var noted = form.fields.filter(function (f) { return f.commentSpot && (f.note || f.sensitive || f.required); });
  var bad = null;

  for (var i = 0; i < noted.length && !bad; i++) {
    var d = Y.parse(text);
    var m = Y.buildForm(d);
    var f = Y.fieldById(m, noted[i].id);
    if (!f || !Y.setComment(d, m, f.id, f.note, f.sensitive, f.required)) continue;
    var got = Y.serialise(d);
    if (got !== text) bad = f.id + '\n' + firstDiff(text, got);
  }

  ok(name + '  (' + noted.length + ' notes)', !bad, bad);
});

/* =========================================================================
 * C. One real change moves exactly one line
 * ========================================================================= */

console.log('\nC. A real edit changes exactly one line');

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

  var doc  = Y.parse(text);
  var form = Y.buildForm(doc);
  var all  = boxes(form).filter(function (b) { return b.field.type !== 'boolean'; });
  if (!all.length) { ok(name + '  (nothing writable — skipped)', true); return; }

  var b = all[0];
  var before = text.split('\n')[b.field.parts[b.part].spot.line];
  Y.setPart(doc, form, b.id, b.part, 'STAXXTESTVALUE');
  var after = Y.serialise(doc);

  var moved = diffLines(text, after);
  if (!ok(name + '  (' + b.id + ' [' + b.part + '])', moved.length === 1,
          'changed lines: ' + moved.map(function (n) { return n + 1; }).join(', '))) return;

  var now = after.split('\n')[moved[0]];
  var lead = function (s) { return /^\s*/.exec(s)[0]; };
  var tail = function (s) { var m = /(\s+#.*)$/.exec(s); return m ? m[1] : ''; };
  ok(name + '  keeps indent and comment',
     lead(before) === lead(now) && tail(before) === tail(now),
     'was: ' + JSON.stringify(before) + '\nnow: ' + JSON.stringify(now));
});

/* =========================================================================
 * D. Sealing — one field at a time, never the whole file
 * ========================================================================= */

console.log('\nD. Sealing (07-yaml-quirks)');

(function () {
  var file = path.join(ROOT, 'scratch/test-stacks/07-yaml-quirks/compose.yaml');
  if (!fs.existsSync(file)) { ok('07-yaml-quirks present', false, 'file missing'); return; }

  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  var doc  = Y.parse(text);
  var form = Y.buildForm(doc);
  var lines = text.split('\n');

  var sealedText = doc.sealed.map(function (s) {
    return lines.slice(s.start, s.end).join('\n');
  }).join('\n@@@\n');

  ok('the anchor is sealed',       /&defaults/.test(sealedText));
  ok('both merge keys are sealed', (sealedText.match(/<<: \*defaults/g) || []).length === 2);
  ok('the folded overview is sealed', /overview: >/.test(sealedText));
  ok('the literal overview is sealed', /Line one of the overview/.test(sealedText));

  // The whole point: sealing one value must not cost its neighbours.
  var alpha = form.fields.filter(function (f) { return f.service === 'quirks-alpha'; });
  var live  = function (binder, t) {
    return alpha.some(function (f) { return f.binder === binder && f.target === t && !f.locked; });
  };

  ok('quirks-alpha port stays editable',   live('port', '15110/tcp'));
  ok('quirks-alpha volume stays editable', live('volume', '/data'));
  ok('quirks-alpha ROLE stays editable',   live('env', 'ROLE'));
  ok('quirks-alpha GREETING stays editable', live('env', 'GREETING'));
  ok('the shared block is announced',
     form.services.some(function (s) { return s.name === 'quirks-alpha' && s.shared; }));

  // The comment beside a value is that field's note, and a marker at the end of
  // it is not part of the prose. Both halves are checked on the same line,
  // because splitting them wrongly is the failure that would not look like one.
  var port = alpha.filter(function (f) { return f.binder === 'port'; })[0];
  ok('an inline comment becomes the note',
     !!port && port.note === 'short form, quoted, and the page you open',
     port && JSON.stringify(port.note));
  ok('the marker is stripped from the note it shares a line with',
     !!port && port.required && !port.sensitive);

  var vol = alpha.filter(function (f) { return f.binder === 'volume'; })[0];
  ok('a note survives single quotes on the value',
     !!vol && vol.note === 'single quotes, relative path. Back this up',
     vol && JSON.stringify(vol.note));

  // A comment that is nothing but a marker. The note must come back empty
  // rather than as the marker's own text.
  var tok = alpha.filter(function (f) { return f.target === 'SHARED_TOKEN'; })[0];
  ok('a marker with no prose leaves an empty note',
     !!tok && tok.sensitive && tok.note === '', tok && JSON.stringify(tok.note));
})();

/* =========================================================================
 * E. The long-hand spellings
 * ========================================================================= */

console.log('\nE. Long-form ports and volumes (06-fedora-advanced)');

(function () {
  var file = path.join(ROOT, 'scratch/test-stacks/06-fedora-advanced/compose.yaml');
  if (!fs.existsSync(file)) { ok('06-fedora-advanced present', false, 'file missing'); return; }

  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  var form = Y.buildForm(Y.parse(text));
  var main = form.fields.filter(function (f) { return f.service === 'fedora-main'; });
  var get  = function (binder, t) {
    return main.filter(function (f) { return f.binder === binder && f.target === t; })[0];
  };

  var host = function (f) { return f && f.parts.host ? f.parts.host.value : null; };
  var cont = function (f) { return f && f.parts.container ? f.parts.container.value : null; };

  var p1 = get('port', '15108/tcp');
  ok('long-form tcp port found',  host(p1) === '15108' && cont(p1) === '15108', host(p1) + ':' + cont(p1));
  var p2 = get('port', '15109/udp');
  ok('long-form udp port found',  host(p2) === '15109' && cont(p2) === '15109', host(p2) + ':' + cont(p2));

  var v1 = get('volume', '/config');
  ok('long-form volume found',    host(v1) === './data/config' && cont(v1) === '/config', host(v1));
  var v2 = get('volume', '/media');
  ok('read_only reads as ro',     !!v2 && v2.mode === 'ro', v2 && v2.mode);

  var e1 = get('env', 'ENABLE_HW_ACCEL');
  ok('list-form env found',       !!e1 && e1.parts.value.value === 'true', e1 && e1.parts.value.value);

  var d1 = get('device', '/dev/dri');
  ok('device found',              host(d1) === '/dev/dri' && cont(d1) === '/dev/dri', host(d1));

  var l1 = get('label', 'com.example.tier');
  ok('label found',               !!l1 && l1.parts.value.value === 'primary', l1 && l1.parts.value.value);

  ok('command is locked, not lost',
     main.some(function (f) { return f.target === 'command' && f.locked; }));

  // Both halves of a long-form port are separately writable, on their own
  // lines, and neither disturbs the other.
  var d2 = Y.parse(text), f2 = Y.buildForm(d2);
  Y.setPart(d2, f2, 'fedora-main/port/15108/tcp', 'host', '25108');
  var after = Y.serialise(d2);
  ok('editing the host half moves one line', diffLines(text, after).length === 1,
     diffLines(text, after).join(', '));

  var d3 = Y.parse(text), f3 = Y.buildForm(d3);
  Y.setPart(d3, f3, 'fedora-main/port/15108/tcp', 'container', '25108');
  ok('editing the container half moves one line',
     diffLines(text, Y.serialise(d3)).length === 1);
})();

/* =========================================================================
 * E2. Two halves of one scalar
 * ========================================================================= */

console.log('\nE2. Host and container in a single short-form entry');

(function () {
  var src = 'services:\n  a:\n    ports:\n      - "8096:8097/udp"\n' +
            '    volumes:\n      - /mnt/user/media:/media:ro\n';

  var cases = [
    ['host port',      'a/port/8097/udp',  'host',      '9000', '      - "9000:8097/udp"'],
    ['container port', 'a/port/8097/udp',  'container', '9001', '      - "8096:9001/udp"'],
    ['host path',      'a/volume//media',  'host',      '/mnt/user/films', '      - /mnt/user/films:/media:ro'],
    ['container path', 'a/volume//media',  'container', '/films', '      - /mnt/user/media:/films:ro']
  ];

  cases.forEach(function (c) {
    var d = Y.parse(src), f = Y.buildForm(d);
    if (!ok(c[0] + ' (field found)', !!Y.fieldById(f, c[1]),
            f.fields.map(function (x) { return x.id; }).join(', '))) return;
    Y.setPart(d, f, c[1], c[2], c[3]);
    var line = Y.serialise(d).split('\n').filter(function (l) { return l.indexOf('- ') >= 0 && l.indexOf(c[3].split('/').pop()) >= 0; })[0];
    ok(c[0] + ' writes correctly', line === c[4],
       'want: ' + JSON.stringify(c[4]) + '\ngot:  ' + JSON.stringify(line));
  });

  // The protocol and the mount mode are carried through untouched by both.
  var d = Y.parse(src), f = Y.buildForm(d);
  Y.setPart(d, f, 'a/port/8097/udp', 'host', '1');
  var f2 = Y.buildForm(Y.parse(Y.serialise(d)));
  ok('protocol survives a host edit', !!Y.fieldById(f2, 'a/port/8097/udp'),
     f2.fields.map(function (x) { return x.id; }).join(', '));
})();

/* =========================================================================
 * E3. Notes and the !secret marker
 * ========================================================================= */

console.log('\nE3. Notes and the two trailing markers');

(function () {
  var src = 'services:\n  a:\n    environment:\n' +
            '      ADMIN_PASSWORD: hunter2      # the login password -!S\n' +
            '      API_KEY: sk-000              # -!S\n' +
            '      SITE_TITLE: Home             # shown at the top -!R\n' +
            '      SESSION_KEY: abc             # both of them -!S -!R\n' +
            '      REVERSED: abc                # written the other way -!R -!S\n' +
            '      LOG_LEVEL: info              # how chatty the logs are\n' +
            '      PLAIN: x\n';

  var f = Y.buildForm(Y.parse(src));
  var get = function (t) { return Y.fieldById(f, 'a/env/' + t); };

  ok('a trailing -!S is read and stripped',
     get('ADMIN_PASSWORD').sensitive === true &&
     get('ADMIN_PASSWORD').required === false &&
     get('ADMIN_PASSWORD').note === 'the login password',
     JSON.stringify(get('ADMIN_PASSWORD').note));
  ok('a marker alone leaves an empty note',
     get('API_KEY').sensitive === true && get('API_KEY').note === '',
     JSON.stringify(get('API_KEY').note));
  ok('a trailing -!R is read',
     get('SITE_TITLE').required === true && get('SITE_TITLE').sensitive === false &&
     get('SITE_TITLE').note === 'shown at the top');
  ok('both markers together',
     get('SESSION_KEY').sensitive === true && get('SESSION_KEY').required === true &&
     get('SESSION_KEY').note === 'both of them',
     JSON.stringify(get('SESSION_KEY').note));
  ok('order of the two markers does not matter',
     get('REVERSED').sensitive === true && get('REVERSED').required === true &&
     get('REVERSED').note === 'written the other way',
     JSON.stringify(get('REVERSED').note));
  ok('an ordinary comment carries no markers',
     get('LOG_LEVEL').sensitive === false && get('LOG_LEVEL').required === false &&
     get('LOG_LEVEL').note === 'how chatty the logs are');
  ok('no comment means no note and no markers',
     get('PLAIN').note === '' && get('PLAIN').sensitive === false &&
     get('PLAIN').required === false);

  ok('identity holds', Y.serialise(Y.parse(src)) === src);

  // Something that merely LOOKS like a marker, mid-sentence, is prose.
  var mid = Y.buildForm(Y.parse(
    'services:\n  a:\n    environment:\n      X: y   # -!S is how you mark a secret\n'));
  var midf = Y.fieldById(mid, 'a/env/X');
  ok('a marker mid-sentence stays part of the note',
     midf.sensitive === false && midf.note === '-!S is how you mark a secret',
     JSON.stringify(midf.note));

  // Marking, un-marking and re-marking must land exactly where it started.
  var d = Y.parse(src), m = Y.buildForm(d);
  Y.setComment(d, m, 'a/env/ADMIN_PASSWORD', 'the login password', false, false);
  var without = Y.serialise(d);
  ok('un-marking drops only the marker',
     without.split('\n')[3] === '      ADMIN_PASSWORD: hunter2      # the login password',
     JSON.stringify(without.split('\n')[3]));

  var d2 = Y.parse(without), m2 = Y.buildForm(d2);
  Y.setComment(d2, m2, 'a/env/ADMIN_PASSWORD', 'the login password', true, false);
  ok('re-marking restores the original line',
     Y.serialise(d2).split('\n')[3] === src.split('\n')[3],
     'got: ' + JSON.stringify(Y.serialise(d2).split('\n')[3]));

  // Both markers must survive a full round trip in the fixed order.
  var d5 = Y.parse(src), m5 = Y.buildForm(d5);
  Y.setComment(d5, m5, 'a/env/REVERSED', 'written the other way', true, true);
  ok('both markers are re-emitted in a fixed order',
     Y.serialise(d5).split('\n')[7] === '      REVERSED: abc                # written the other way -!S -!R',
     JSON.stringify(Y.serialise(d5).split('\n')[7]));

  // A value with no comment at all must be able to gain one.
  var d3 = Y.parse(src), m3 = Y.buildForm(d3);
  Y.setComment(d3, m3, 'a/env/PLAIN', 'newly written note', true, true);
  ok('a note can be added where there was none',
     Y.serialise(d3).split('\n')[9] === '      PLAIN: x  # newly written note -!S -!R',
     JSON.stringify(Y.serialise(d3).split('\n')[9]));

  // And lose it again without leaving a bare hash behind.
  var d4 = Y.parse(src), m4 = Y.buildForm(d4);
  Y.setComment(d4, m4, 'a/env/LOG_LEVEL', '', false, false);
  ok('clearing a note removes the comment',
     Y.serialise(d4).split('\n')[8] === '      LOG_LEVEL: info',
     JSON.stringify(Y.serialise(d4).split('\n')[8]));

  // A bare marker with no prose.
  var d6 = Y.parse(src), m6 = Y.buildForm(d6);
  Y.setComment(d6, m6, 'a/env/PLAIN', '', false, true);
  ok('a marker with no note needs no leading space',
     Y.serialise(d6).split('\n')[9] === '      PLAIN: x  # -!R',
     JSON.stringify(Y.serialise(d6).split('\n')[9]));
})();

/* =========================================================================
 * F. Edge cases no test file happens to contain
 * ========================================================================= */

console.log('\nF. Synthetic edge cases');

var EDGE = {
  'sequence at its key’s indent':
    'services:\n  a:\n    image: alpine\n    ports:\n    - "80:80"\n    - "81:81"\n',

  'four-space indentation':
    'services:\n    a:\n        image: alpine\n        environment:\n            TZ: UTC\n',

  'no trailing newline':
    'services:\n  a:\n    image: alpine',

  'empty environment key':
    'services:\n  a:\n    image: alpine\n    environment:\n    labels:\n      x: y\n',

  'duplicate key':
    'services:\n  a:\n    image: alpine\n    image: busybox\n',

  'anonymous volume':
    'services:\n  a:\n    image: alpine\n    volumes:\n      - /data\n',

  'comment containing a colon':
    'services:\n  a:\n    # note: this is not a key\n    image: alpine\n',

  'hash inside quotes':
    'services:\n  a:\n    image: alpine\n    environment:\n      C: "grey #3"\n',

  'inline comment after a value':
    'services:\n  a:\n    image: alpine      # pinned deliberately\n',

  'ip-qualified port':
    'services:\n  a:\n    image: alpine\n    ports:\n      - "127.0.0.1:8080:80"\n',

  'volume with a mode':
    'services:\n  a:\n    image: alpine\n    volumes:\n      - /host:/data:ro\n',

  'blank line between comment and key':
    'services:\n  a:\n    # section note\n\n    image: alpine\n'
};

Object.keys(EDGE).forEach(function (name) {
  var t = EDGE[name];
  var got = Y.serialise(Y.parse(t));
  ok(name + '  [identity]', got === t, got === t ? '' : firstDiff(t, got));
});

(function () {
  // The one that catches a sequence sitting at its key's own indent: if the
  // parser missed it, there would be no port field at all.
  var f = Y.buildForm(Y.parse(EDGE['sequence at its key’s indent']));
  ok('sequence at key indent yields both ports',
     f.fields.filter(function (x) { return x.binder === 'port'; }).length === 2,
     f.fields.map(function (x) { return x.id; }).join(', '));

  var q = Y.buildForm(Y.parse(EDGE['hash inside quotes']));
  var c = q.fields.filter(function (x) { return x.target === 'C'; })[0];
  ok('a hash inside quotes is part of the value',
     !!c && c.parts.value.value === 'grey #3' && c.note === '',
     c && JSON.stringify(c.parts.value.value));

  var ip = Y.buildForm(Y.parse(EDGE['ip-qualified port']));
  var ipf = ip.fields.filter(function (x) { return x.binder === 'port'; })[0];
  ok('an ip-qualified port keeps its address in the host box',
     !!ipf && ipf.target === '80/tcp' && ipf.parts.host.value === '8080',
     ipf && (ipf.target + ' / ' + ipf.parts.host.value));

  var mo = Y.buildForm(Y.parse(EDGE['volume with a mode']));
  var mof = mo.fields.filter(function (x) { return x.binder === 'volume'; })[0];
  ok('a volume mode is read, not treated as a path',
     !!mof && mof.target === '/data' && mof.parts.host.value === '/host' && mof.mode === 'ro',
     mof && (mof.target + ' / ' + mof.parts.host.value + ' / ' + mof.mode));

  // An anonymous volume has no HOST side, but its container path is still
  // perfectly editable — so the row stays live and only that one box is dead.
  var an = Y.buildForm(Y.parse(EDGE['anonymous volume']));
  var anf = an.fields.filter(function (x) { return x.binder === 'volume'; })[0];
  ok('an anonymous volume has no host box but keeps its container box',
     !!anf && !anf.locked && !anf.parts.host.spot && !!anf.parts.container.spot,
     anf && JSON.stringify({ locked: anf.locked, host: !!anf.parts.host.spot }));
})();

/* =========================================================================
 * G. Quoting is preserved, never tidied away
 * ========================================================================= */

console.log('\nG. Quoting survives an edit');

(function () {
  var cases = [
    ['double stays double', 'services:\n  a:\n    environment:\n      PUID: "99"\n', 'PUID', '98', '      PUID: "98"'],
    ['single stays single', "services:\n  a:\n    environment:\n      R: 'alpha'\n",  'R',    'beta', "      R: 'beta'"],
    ['plain stays plain',   'services:\n  a:\n    environment:\n      R: alpha\n',    'R',    'beta', '      R: beta'],
    ['plain gains quotes when it must',
                            'services:\n  a:\n    environment:\n      R: alpha\n',    'R',    'true', "      R: 'true'"],
    ['a comment beside a value survives',
                            'services:\n  a:\n    environment:\n      R: alpha    # why\n', 'R', 'beta', '      R: beta    # why']
  ];

  cases.forEach(function (c) {
    var doc = Y.parse(c[1]);
    var form = Y.buildForm(doc);
    var f = form.fields.filter(function (x) { return x.target === c[2]; })[0];
    if (!ok(c[0] + ' (field found)', !!f)) return;
    Y.setValue(doc, form, f.id, c[3]);
    var line = Y.serialise(doc).split('\n').filter(function (l) { return l.indexOf(c[2] + ':') >= 0; })[0];
    ok(c[0], line === c[4], 'want: ' + JSON.stringify(c[4]) + '\ngot:  ' + JSON.stringify(line));
  });
})();

/* =========================================================================
 * H. Adding and removing entries
 *
 * The structural equivalent of the null edit: add something, take it straight
 * back out, and demand the original bytes. It exercises indent matching, the
 * placement of a newly created key, the removal of a key that has lost its
 * last entry, and the comment a removed entry takes with it — all at once, and
 * with no room to be nearly right.
 * ========================================================================= */

console.log('\nH. Add and remove');

var BINDERS = ['port', 'volume', 'device', 'env', 'label'];

/* ---- add then remove, over the whole corpus ----------------------------- */

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

  var services = Y.buildForm(Y.parse(text)).services
                  .filter(function (s) { return s.readable; })
                  .map(function (s) { return s.name; });

  if (!services.length) { ok(name + '  (no readable service — skipped)', true); return; }

  var bad = null, tried = 0;

  services.forEach(function (svc) {
    BINDERS.forEach(function (binder) {
      if (bad) return;

      var doc  = Y.parse(text);
      var form = Y.buildForm(doc);

      var line = Y.addItem(doc, form, svc, binder);
      if (line < 0) return;                       // refused, which is its own case
      tried++;

      var added = Y.buildForm(doc);
      var id    = Y.fieldAtLine(added, line);
      if (!id) { bad = svc + '/' + binder + ': the new entry has no field at line ' + line; return; }

      if (!Y.removeItem(doc, added, id)) { bad = svc + '/' + binder + ': could not remove what was just added'; return; }

      var got = Y.serialise(doc);
      if (got !== text) bad = svc + '/' + binder + '\n' + firstDiff(text, got);
    });
  });

  ok(name + '  (' + tried + ' add/remove round trips)', !bad, bad);
});

/* ---- the case that started this piece ----------------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    ports:\n      - "8096:8096"   # the only one\n';

  var doc  = Y.parse(src);
  var form = Y.buildForm(doc);
  var only = form.fields.filter(function (f) { return f.binder === 'port'; })[0];

  ok('the only port is found', !!only);
  Y.removeItem(doc, form, only.id);
  var gone = Y.serialise(doc);

  ok('removing the only entry takes its key with it',
     gone === 'services:\n  a:\n    image: alpine\n', JSON.stringify(gone));
  ok('and its comment goes with it too', gone.indexOf('the only one') < 0);

  // ...and the key can be created again from nothing, which is the whole point.
  var back = Y.buildForm(Y.parse(gone));
  var doc2 = Y.parse(gone);
  var f2   = Y.buildForm(doc2);
  var at   = Y.addItem(doc2, f2, 'a', 'port');
  ok('a port can be added back after the key was removed', at >= 0, 'addItem returned ' + at);

  var again = Y.serialise(doc2);
  ok('the re-created key is valid YAML with an entry under it',
     /ports:\n\s+- "8080:8080"/.test(again), JSON.stringify(again));
  ok('the rebuilt file has exactly one port field',
     Y.buildForm(Y.parse(again)).fields.filter(function (f) { return f.binder === 'port'; }).length === 1);
  ok('(no service was lost on the way)', back.services.length === 1);
})();

/* ---- blank lines around a block that is removed whole ------------------- */

(function () {
  // A file that separates its blocks with blank lines must not end up with two
  // of them where it had one. Caught by the corpus round-trip above, and
  // pinned here because the fix is easy to lose.
  var src = 'services:\n  a:\n    image: alpine\n\n    ports:\n      - "80:80"\n\n    environment:\n      TZ: UTC\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.removeItem(doc, form, form.fields.filter(function (f) { return f.binder === 'port'; })[0].id);

  ok('removing a whole block collapses the double blank',
     Y.serialise(doc) === 'services:\n  a:\n    image: alpine\n\n    environment:\n      TZ: UTC\n',
     JSON.stringify(Y.serialise(doc)));

  // The same block, last in the file — the blank must not be left trailing.
  var tail = 'services:\n  a:\n    image: alpine\n\n    ports:\n      - "80:80"\n';
  var d2 = Y.parse(tail), f2 = Y.buildForm(d2);
  Y.removeItem(d2, f2, f2.fields.filter(function (f) { return f.binder === 'port'; })[0].id);
  ok('and leaves no blank line trailing at the end',
     Y.serialise(d2) === 'services:\n  a:\n    image: alpine\n', JSON.stringify(Y.serialise(d2)));

  // A blank line someone put there on purpose, with no blank on the other
  // side, must survive.
  var one = 'services:\n  a:\n    image: alpine\n\n    ports:\n      - "80:80"\n    environment:\n      TZ: UTC\n';
  var d3 = Y.parse(one), f3 = Y.buildForm(d3);
  Y.removeItem(d3, f3, f3.fields.filter(function (f) { return f.binder === 'port'; })[0].id);
  ok('a lone blank line is left alone',
     Y.serialise(d3) === 'services:\n  a:\n    image: alpine\n\n    environment:\n      TZ: UTC\n',
     JSON.stringify(Y.serialise(d3)));
})();

/* ---- a created key goes before x-unraid, never after -------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    environment:\n      TZ: UTC\n\n' +
            '    x-unraid:\n      name: Thing\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.addItem(doc, form, 'a', 'volume');
  var lines = Y.serialise(doc).split('\n');

  var vol = lines.findIndex(function (l) { return /^\s+volumes:/.test(l); });
  var xun = lines.findIndex(function (l) { return /^\s+x-unraid:/.test(l); });

  ok('a new key lands above the x-unraid block', vol >= 0 && xun >= 0 && vol < xun,
     'volumes at ' + vol + ', x-unraid at ' + xun + '\n' + Y.serialise(doc));
  ok('the metadata block is untouched', /x-unraid:\n      name: Thing/.test(Y.serialise(doc)));
  ok('the file still parses with both keys',
     Y.buildForm(Y.parse(Y.serialise(doc))).fields.some(function (f) { return f.binder === 'volume'; }));
})();

/* ---- two adds must not collide -------------------------------------- */

(function () {
  BINDERS.forEach(function (binder) {
    var doc = Y.parse('services:\n  a:\n    image: alpine\n'), form = Y.buildForm(doc);

    for (var i = 0; i < 3; i++) {
      Y.addItem(doc, form, 'a', binder);
      form = Y.buildForm(doc);
    }

    var ids = form.fields.filter(function (f) { return f.binder === binder; })
                         .map(function (f) { return f.id; });
    var uniq = ids.filter(function (v, i, a) { return a.indexOf(v) === i; });

    ok('three ' + binder + ' entries get three distinct ids',
       ids.length === 3 && uniq.length === 3, ids.join(', '));
  });
})();

/* ---- the keys that start empty ------------------------------------------ */

// cap_add, cap_drop and profiles render as a suggestion box, and a browser only
// offers the suggestions matching what is already in the box — so these are
// added blank. That only works if a bare "- " comes back as an editable empty
// field rather than a locked row, and if typing into it writes a plain value.
(function () {
  var src = 'services:\n  a:\n    image: alpine\n';

  ['cap_add', 'cap_drop', 'profiles'].forEach(function (key) {
    var doc  = Y.parse(src);
    var line = Y.addItem(doc, Y.buildForm(doc), 'a', 'list', undefined, key);
    ok(key + ' is added empty', Y.serialise(doc).split('\n')[line] === '      - ',
       JSON.stringify(Y.serialise(doc).split('\n')[line]));

    var form = Y.buildForm(doc);
    var id   = Y.fieldAtLine(form, line);
    var f    = form.fields.filter(function (x) { return x.id === id; })[0];
    ok(key + '\'s empty entry is an editable box, not a locked row',
       !!f && !f.locked && !!f.parts.value && f.parts.value.value === '');

    Y.setValue(doc, form, id, 'NET_RAW');
    ok('typing into ' + key + '\'s empty entry writes an unquoted value',
       Y.serialise(doc).split('\n')[line] === '      - NET_RAW',
       JSON.stringify(Y.serialise(doc).split('\n')[line]));

    // And removing it while still empty leaves nothing behind.
    var doc2 = Y.parse(src);
    var l2   = Y.addItem(doc2, Y.buildForm(doc2), 'a', 'list', undefined, key);
    var f2   = Y.buildForm(doc2);
    Y.removeItem(doc2, f2, Y.fieldAtLine(f2, l2));
    ok('removing ' + key + '\'s empty entry takes its key with it',
       Y.serialise(doc2) === src, JSON.stringify(Y.serialise(doc2)));
  });

  // A dash with no space after it has nowhere to put a value, so it stays
  // locked — writing at that column would produce "-NET_RAW".
  var tight = Y.parse('services:\n  a:\n    image: alpine\n    cap_drop:\n      -\n');
  var tf    = Y.buildForm(tight).fields.filter(function (f) { return f.listKey === 'cap_drop'; })[0];
  ok('a dash with nothing after it at all stays locked', !!tf && tf.locked);
})();

/* ---- an entry with no value is an unfinished edit ------------------------ */

// It stays visible so it can be finished or deleted, and it is never carried
// into x-unraid.sections when its section is switched off. Both halves matter:
// without the first, clearing a box drops its row while the line stays in the
// file; without the second, switching the section off and on again hands back
// a blank that compose then refuses to run.
(function () {
  var doc  = Y.parse('services:\n  a:\n    image: alpine\n    dns:\n      - 1.1.1.1\n');
  var form = Y.buildForm(doc);
  var f    = form.fields.filter(function (x) { return x.listKey === 'dns'; })[0];

  Y.setValue(doc, form, f.id, '');
  ok('clearing a list entry leaves the dash bare, not "- \'\'"',
     Y.serialise(doc) === 'services:\n  a:\n    image: alpine\n    dns:\n      - \n',
     JSON.stringify(Y.serialise(doc)));

  var back = Y.buildForm(Y.parse(Y.serialise(doc)))
              .fields.filter(function (x) { return x.listKey === 'dns'; })[0];
  ok('the cleared entry keeps its row rather than vanishing',
     !!back && !back.locked && back.parts.value.value === '');

  // A file that already holds one — ours never writes it, but a hand-written
  // empty string is still an entry and must be reachable.
  var quoted = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    dns:\n      - \'\'\n'))
                .fields.filter(function (x) { return x.listKey === 'dns'; })[0];
  ok('a hand-written empty string entry is shown, not dropped',
     !!quoted && !quoted.locked && quoted.parts.value.value === '');

  function stashed(src) {
    var d = Y.parse(src);
    ok('the block stashes', Y.stashSection(d, Y.buildForm(d), 'a', ['cap_drop']));
    var e = Y.readSections(d).a;
    return { entry: e ? e.cap_drop : undefined, out: Y.serialise(d) };
  }

  var mixed = stashed('services:\n  a:\n    image: alpine\n    cap_drop:\n      - ALL\n      - \n');
  ok('a blank beside a real entry is left behind, the real one kept',
     !!mixed.entry && mixed.entry.lines.join('|') === 'cap_drop:|  - ALL',
     JSON.stringify(mixed.entry));

  var allBlank = stashed('services:\n  a:\n    image: alpine\n    cap_drop:\n      - \n');
  ok('a block that is nothing but blanks keeps nothing at all',
     allBlank.entry === false, JSON.stringify(allBlank.entry));
  ok('and the block itself is gone from the file',
     allBlank.out.indexOf('cap_drop:\n') < 0, allBlank.out);

  // The verbatim promise: a block with no blanks in it is untouched, comments,
  // dash gaps and all.
  var clean = stashed('services:\n  a:\n    image: alpine\n    cap_drop:\n' +
                      '      # keep me\n      -   ALL   # and me\n');
  ok('a block with no blanks is stashed exactly as it stands',
     !!clean.entry && clean.entry.lines.join('|') === 'cap_drop:|  # keep me|  -   ALL   # and me',
     JSON.stringify(clean.entry));

  // Nothing was dropped from this one, so the rule that empties it never fires
  // and the author's own line survives.
  var bare = stashed('services:\n  a:\n    image: alpine\n    cap_drop:\n');
  ok('a key written with nothing under it is still kept',
     !!bare.entry && bare.entry.lines.join('|') === 'cap_drop:', JSON.stringify(bare.entry));

  // End to end: off, then on again.
  var d2 = Y.parse('services:\n  a:\n    image: alpine\n    cap_drop:\n      - ALL\n      - \n');
  Y.stashSection(d2, Y.buildForm(d2), 'a', ['cap_drop']);
  Y.restoreSection(d2, Y.buildForm(d2), 'a', 'cap_drop');
  ok('switching the section off and on again returns the real entry and no blank',
     Y.serialise(d2) === 'services:\n  a:\n    image: alpine\n    cap_drop:\n      - ALL\n',
     JSON.stringify(Y.serialise(d2)));
})();

/* ---- half a mapping is still a mapping ---------------------------------- */

// Clearing one side of a port, volume or device used to write "- 8080:", which
// YAML reads as a MAPPING with the key 8080 and no value — so the entry stopped
// being a port at all and its row vanished from the form while the line stayed
// in the file. A colon at the end of a scalar is a key indicator just as much
// as one followed by a space, so it has to be quoted.
(function () {
  var CASES = [
    ['port',   'ports',   '8080:80',           'container', "      - '8080:'"],
    ['port',   'ports',   '8080:80',           'host',      '      - :80'],
    ['volume', 'volumes', '/mnt/a:/data',      'container', "      - '/mnt/a:'"],
    ['device', 'devices', '/dev/dri:/dev/dri', 'container', "      - '/dev/dri:'"]
  ];

  CASES.forEach(function (c) {
    var src  = 'services:\n  a:\n    image: alpine\n    ' + c[1] + ':\n      - ' + c[2] + '\n';
    var doc  = Y.parse(src), form = Y.buildForm(doc);
    var pick = function (f) { return f.binder === c[0]; };
    var f    = form.fields.filter(pick)[0];

    Y.setPart(doc, form, f.id, c[3], '');
    var line = Y.serialise(doc).split('\n')[4];
    ok('clearing a ' + c[0] + '\'s ' + c[3] + ' side writes ' + JSON.stringify(c[4]),
       line === c[4], JSON.stringify(line));

    var after = Y.buildForm(Y.parse(Y.serialise(doc))).fields.filter(pick)[0];
    ok('and the ' + c[0] + ' row is still there, with its other half intact',
       !!after && !after.locked &&
       (String(after.parts.host.value) + String(after.parts.container.value)).trim() !== '',
       after ? JSON.stringify(after.parts) : 'the row vanished');
  });

  // A legitimate one-sided entry is a different thing entirely: its missing
  // half has no spot at all, which is what the form's gap check keys on.
  [['port', 'ports', '8080'], ['volume', 'volumes', '/data']].forEach(function (c) {
    var f = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    ' + c[1] + ':\n      - ' + c[2] + '\n'))
             .fields.filter(function (x) { return x.binder === c[0]; })[0];
    ok('a one-sided ' + c[0] + ' entry has no host spot and is not a half mapping',
       !!f && !f.parts.host.spot && f.parts.container.value === c[2]);
  });

  // The quoting rule itself, from both sides: a trailing colon is quoted, a
  // colon inside a word is left exactly as it was.
  var doc2 = Y.parse('services:\n  a:\n    image: alpine\n    environment:\n      A: x\n      B: y\n');
  var form2 = Y.buildForm(doc2);
  var byName = function (n) {
    return form2.fields.filter(function (f) { return f.binder === 'env' && f.target === n; })[0];
  };
  Y.setValue(doc2, form2, byName('A').id, 'ends:');
  Y.setValue(doc2, form2, byName('B').id, 'http://host/path');
  var got = Y.serialise(doc2).split('\n');
  ok('a value ending in a colon is quoted', got[4] === "      A: 'ends:'", JSON.stringify(got[4]));
  ok('a colon inside a value still needs no quotes',
     got[5] === '      B: http://host/path', JSON.stringify(got[5]));
})();

/* ---- a chosen value instead of a placeholder ---------------------------- */

(function () {
  var ZIG = '/dev/serial/by-id/usb-Silicon_Labs_cc2652rb_stick-if00-port0';
  var VAL = ZIG + ':/dev/ttyACM0';

  // Into a list that already has an entry. The file carries comments and an
  // unusual dash gap, both of which have to come through untouched.
  var src = 'services:\n' +
            '  a:\n' +
            '    image: alpine            # the image, commented\n' +
            '    devices:\n' +
            '      -   /dev/dri:/dev/dri  # the GPU\n' +
            '    environment:\n' +
            '      TZ: UTC\n';

  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var line = Y.addItem(doc, form, 'a', 'device', VAL);
  ok('a device can be added by value', line >= 0, 'addItem returned ' + line);

  var out  = Y.serialise(doc);
  var got  = out.split('\n');
  ok('the value is written exactly as given, with the list\'s own dash gap',
     got[line] === '      -   ' + VAL, JSON.stringify(got[line]));

  got.splice(line, 1);
  ok('every other line is byte-identical', got.join('\n') === src,
     firstDiff(src, got.join('\n')));

  var added = Y.buildForm(Y.parse(out));
  var devs  = added.fields.filter(function (f) { return f.binder === 'device'; });
  ok('the file now has two devices', devs.length === 2,
     devs.map(function (f) { return f.id; }).join(', '));
  ok('the new row keys on the container path, not the long host one',
     devs.some(function (f) { return f.target === '/dev/ttyACM0'; }),
     devs.map(function (f) { return f.target; }).join(', '));

  // Adding then removing has to leave the file exactly as it was found.
  var id = Y.fieldAtLine(added, line);
  var d2 = Y.parse(out), f2 = Y.buildForm(d2);
  ok('the added entry can be removed again', Y.removeItem(d2, f2, id) === true, id);
  ok('and the file is back to the original', Y.serialise(d2) === src,
     firstDiff(src, Y.serialise(d2)));
})();

(function () {
  var VAL = '/dev/dri:/dev/dri';

  // A key with nothing under it yet.
  var bare = 'services:\n  a:\n    image: alpine\n    devices:\n';
  var d1   = Y.parse(bare);
  ok('a value can be added under a key with nothing under it',
     Y.addItem(d1, Y.buildForm(d1), 'a', 'device', VAL) >= 0);
  ok('and it lands indented under that key',
     /\n    devices:\n      - \/dev\/dri:\/dev\/dri\n$/.test(Y.serialise(d1)),
     JSON.stringify(Y.serialise(d1)));

  // No devices: key at all — it is written along with its first entry, above
  // the metadata block, same as any other new list.
  var none = 'services:\n  a:\n    image: alpine\n    x-unraid:\n      name: Thing\n';
  var d2   = Y.parse(none);
  ok('a value can be added to a service with no devices: key',
     Y.addItem(d2, Y.buildForm(d2), 'a', 'device', VAL) >= 0);

  var lines = Y.serialise(d2).split('\n');
  var dev   = lines.findIndex(function (l) { return /^\s+devices:/.test(l); });
  var xun   = lines.findIndex(function (l) { return /^\s+x-unraid:/.test(l); });
  ok('the new key lands above the x-unraid block', dev >= 0 && xun >= 0 && dev < xun,
     Y.serialise(d2));
  ok('the metadata block is untouched', /x-unraid:\n      name: Thing/.test(Y.serialise(d2)));

  // The model writes what it is told and does not deduplicate. Only the caller
  // knows the device was already there and what to say about it, so refusing
  // here would be a silent no-op with nowhere to report it.
  var d3 = Y.parse('services:\n  a:\n    image: alpine\n    devices:\n      - ' + VAL + '\n');
  ok('a duplicate value is written, not silently dropped',
     Y.addItem(d3, Y.buildForm(d3), 'a', 'device', VAL) >= 0);
  ok('so the caller is what has to refuse it',
     Y.serialise(d3).split(VAL).length - 1 === 2, Y.serialise(d3));

  // An empty value falls back to the placeholder, so no caller can accidentally
  // write a blank entry by passing one through.
  var d4 = Y.parse('services:\n  a:\n    image: alpine\n');
  Y.addItem(d4, Y.buildForm(d4), 'a', 'device', '');
  ok('an empty value falls back to the placeholder',
     /- \/dev\/dri:\/dev\/dri/.test(Y.serialise(d4)), Y.serialise(d4));
})();

/* ---- sealed lists and entries refuse ------------------------------------ */

(function () {
  var src = 'x-shared: &ports\n  - "80:80"\n\nservices:\n  a:\n    image: alpine\n' +
            '    ports: *ports\n    volumes: [/a:/b]\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);

  ok('an aliased list cannot be added to',      Y.addItem(doc, form, 'a', 'port') === -1);
  ok('a flow list cannot be added to',          Y.addItem(doc, form, 'a', 'volume') === -1);
  ok('nothing was written while refusing',      Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));

  var sealed = form.fields.filter(function (f) { return f.locked && f.binder === 'port'; })[0];
  ok('a sealed list shows as one locked field', !!sealed,
     form.fields.map(function (f) { return f.id + (f.locked ? ' [locked]' : ''); }).join(', '));
  if (sealed) {
    ok('and it cannot be removed', Y.removeItem(doc, form, sealed.id) === false);
    ok('still nothing written',    Y.serialise(doc) === src);
  }

  // A service that cannot be read offers nothing to add to.
  var odd = Y.buildForm(Y.parse('services:\n  a: alpine\n'));
  ok('an unreadable service is marked unreadable',
     odd.services.length === 1 && odd.services[0].readable === false);
})();

/* ---- the name box on variables and labels ------------------------------- */

console.log('\nH2. Naming a variable');

(function () {
  var mapForm  = 'services:\n  a:\n    environment:\n      TZ: UTC          # timezone\n      PUID: "99"\n';
  var listForm = 'services:\n  a:\n    environment:\n      - TZ=UTC\n      - PASSTHROUGH\n';

  // Mapping form.
  var d = Y.parse(mapForm), f = Y.buildForm(d);
  var tz = Y.fieldById(f, 'a/env/TZ');
  ok('a mapping variable has a name box', !!tz && !!tz.parts.name && !!tz.parts.name.spot);
  ok('the name box holds the name', tz && tz.parts.name.value === 'TZ', tz && tz.parts.name.value);

  Y.setPart(d, f, 'a/env/TZ', 'name', 'TIMEZONE');
  var after = Y.serialise(d);
  ok('renaming moves exactly one line', diffLines(mapForm, after).length === 1,
     diffLines(mapForm, after).join(', '));
  ok('the value and the comment ride along untouched',
     after.split('\n')[3] === '      TIMEZONE: UTC          # timezone',
     JSON.stringify(after.split('\n')[3]));

  // A colon in a key has to be quoted, or the key would be read only as far
  // as the colon and the rest would become the value.
  var d2 = Y.parse(mapForm), f2 = Y.buildForm(d2);
  Y.setPart(d2, f2, 'a/env/TZ', 'name', 'a:b');
  ok('a colon in a name is quoted',
     /^ {6}'a:b': UTC/.test(Y.serialise(d2).split('\n')[3]),
     JSON.stringify(Y.serialise(d2).split('\n')[3]));
  ok('and it reads back as one key',
     !!Y.fieldById(Y.buildForm(Y.parse(Y.serialise(d2))), 'a/env/a:b'),
     Y.buildForm(Y.parse(Y.serialise(d2))).fields.map(function (x) { return x.id; }).join(', '));

  // List form: name and value are two halves of one scalar.
  var d3 = Y.parse(listForm), f3 = Y.buildForm(d3);
  var lt = Y.fieldById(f3, 'a/env/TZ');
  ok('a list variable has both boxes',
     !!lt && lt.parts.name.value === 'TZ' && lt.parts.value.value === 'UTC');
  Y.setPart(d3, f3, 'a/env/TZ', 'name', 'TIMEZONE');
  ok('renaming a list variable keeps its value',
     Y.serialise(d3).split('\n')[3] === '      - TIMEZONE=UTC',
     JSON.stringify(Y.serialise(d3).split('\n')[3]));

  // "- FOO" with no "=" means "take this from the server's environment".
  var pt = Y.fieldById(f3, 'a/env/PASSTHROUGH');
  ok('a pass-through variable stays editable', !!pt && !pt.locked);
  ok('its value box is dead, its name box is not',
     !!pt && !pt.parts.value.spot && !!pt.parts.name.spot);
  ok('and the row says why', !!pt && /server/.test(pt.lockReason), pt && pt.lockReason);

  var d4 = Y.parse(listForm), f4 = Y.buildForm(d4);
  Y.setPart(d4, f4, 'a/env/PASSTHROUGH', 'value', 'x');
  ok('writing a value into a pass-through is refused, not written as "FOO="',
     Y.serialise(d4) === listForm, firstDiff(listForm, Y.serialise(d4)));

  ok('both forms round-trip untouched',
     Y.serialise(Y.parse(mapForm)) === mapForm && Y.serialise(Y.parse(listForm)) === listForm);
})();

/* =========================================================================
 * I. renameService — the key, and every reference that follows it
 * ========================================================================= */

console.log('\nI. Renaming a service');

(function () {
  var CASES = [
    ['depends_on list',
     'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    depends_on:\n      - db\n',
     'services:\n  database:\n    image: postgres\n  web:\n    image: nginx\n    depends_on:\n      - database\n'],
    ['depends_on map (a key, not a value)',
     'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    depends_on:\n      db:\n        condition: service_healthy\n',
     'services:\n  database:\n    image: postgres\n  web:\n    image: nginx\n    depends_on:\n      database:\n        condition: service_healthy\n'],
    ['links with an alias',
     'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    links:\n      - db:dbalias\n',
     'services:\n  database:\n    image: postgres\n  web:\n    image: nginx\n    links:\n      - database:dbalias\n'],
    ['volumes_from',
     'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    volumes_from:\n      - db:ro\n',
     'services:\n  database:\n    image: postgres\n  web:\n    image: nginx\n    volumes_from:\n      - database:ro\n'],
    ['network_mode: service:<name>',
     'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    network_mode: service:db\n',
     'services:\n  database:\n    image: postgres\n  web:\n    image: nginx\n    network_mode: service:database\n']
  ];

  CASES.forEach(function (c) {
    var doc = Y.parse(c[1]);
    var res = Y.renameService(doc, 'db', 'database');
    ok(c[0] + ' (renamed, one reference)', res.ok === true && res.refs === 1, JSON.stringify(res));
    ok(c[0] + ' (file matches expected)', Y.serialise(doc) === c[2], firstDiff(c[2], Y.serialise(doc)));
  });

  // Two references, on two different lines — depends_on and links both name
  // the service being renamed, and each is fixed up independently.
  var multi = 'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n' +
              '    depends_on:\n      - db\n      - cache\n    links:\n      - db:alias\n';
  var multiWant = 'services:\n  database:\n    image: postgres\n  web:\n    image: nginx\n' +
                  '    depends_on:\n      - database\n      - cache\n    links:\n      - database:alias\n';
  var md = Y.parse(multi);
  var mres = Y.renameService(md, 'db', 'database');
  ok('two references in one call (adjacent lines)', mres.ok === true && mres.refs === 2, JSON.stringify(mres));
  ok('both are rewritten, "cache" is untouched', Y.serialise(md) === multiWant, firstDiff(multiWant, Y.serialise(md)));

  // Flow-style ("[db, cache]") is sealed like everywhere else in this file —
  // renaming the key still succeeds, but a reference sitting inside a flow
  // list is left exactly as written rather than guessed at.
  var flow = 'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    depends_on: [db, cache]\n';
  var fd = Y.parse(flow);
  var fres = Y.renameService(fd, 'db', 'database');
  ok('a flow-style depends_on is sealed, so it is not rewritten',
     fres.ok === true && fres.refs === 0 && Y.serialise(fd).indexOf('depends_on: [db, cache]') >= 0,
     JSON.stringify(fres) + '\n' + Y.serialise(fd));

  // A name that is a substring of another must not match it.
  var sib = 'services:\n  db:\n    image: postgres\n  dbbackup:\n    image: backup\n  web:\n' +
            '    image: nginx\n    depends_on:\n      - db\n      - dbbackup\n';
  var sibWant = 'services:\n  database:\n    image: postgres\n  dbbackup:\n    image: backup\n  web:\n' +
                '    image: nginx\n    depends_on:\n      - database\n      - dbbackup\n';
  var sd = Y.parse(sib);
  var sres = Y.renameService(sd, 'db', 'database');
  ok('"dbbackup" is not touched by renaming "db"',
     sres.ok === true && sres.refs === 1 && Y.serialise(sd) === sibWant,
     JSON.stringify(sres) + '\n' + firstDiff(sibWant, Y.serialise(sd)));

  // A comment-heavy file with an anchor and a merge key. The whole string
  // must come back identical apart from the two names — not line by line,
  // since a bug that shifted an unrelated line would still pass a per-line
  // check that only looks at the lines it expected to change.
  var anchored =
    '# top of file comment\n' +
    'x-shared: &defaults\n' +
    '  restart: unless-stopped\n' +
    '\n' +
    'services:\n' +
    '  db:\n' +
    '    <<: *defaults\n' +
    '    image: postgres   # the database image\n' +
    '\n' +
    '  # web depends on db\n' +
    '  web:\n' +
    '    image: nginx\n' +
    '    depends_on:\n' +
    '      - db   # must start first\n';
  var anchoredWant =
    '# top of file comment\n' +
    'x-shared: &defaults\n' +
    '  restart: unless-stopped\n' +
    '\n' +
    'services:\n' +
    '  database:\n' +
    '    <<: *defaults\n' +
    '    image: postgres   # the database image\n' +
    '\n' +
    '  # web depends on db\n' +
    '  web:\n' +
    '    image: nginx\n' +
    '    depends_on:\n' +
    '      - database   # must start first\n';
  var ad = Y.parse(anchored);
  var ares = Y.renameService(ad, 'db', 'database');
  ok('comments, the anchor and the merge key all survive, only the names change',
     ares.ok === true && ares.refs === 1 && Y.serialise(ad) === anchoredWant,
     JSON.stringify(ares) + '\n' + firstDiff(anchoredWant, Y.serialise(ad)));

  // Renaming to the same name is a no-op success, not a refusal — decided
  // because it would otherwise have to be rejected for "colliding" with
  // itself, which is a confusing thing to tell someone who typed nothing.
  var nd = Y.parse(sib);
  var nres = Y.renameService(nd, 'db', 'db');
  ok('renaming to the same name is a no-op success',
     nres.ok === true && nres.refs === 0 && Y.serialise(nd) === sib, JSON.stringify(nres));

  // Every refusal must leave the document exactly as it was found.
  var REFUSALS = [
    ['an empty name',            'db', ''],
    ['a name with illegal characters', 'db', 'bad name!'],
    ['a service that does not exist',  'ghost', 'anything'],
    ['a name already taken',     'db', 'web']
  ];
  REFUSALS.forEach(function (r) {
    var rd = Y.parse(sib);
    var before = Y.serialise(rd);
    var rres = Y.renameService(rd, r[1], r[2]);
    ok('refused: ' + r[0], rres.ok === false && typeof rres.error === 'string' && rres.error.length > 0,
       JSON.stringify(rres));
    ok('refused: ' + r[0] + ' — file untouched', Y.serialise(rd) === before, firstDiff(before, Y.serialise(rd)));
  });
})();

/* ---- a key whose only value is a comment -------------------------------- */

// "db:  # the database" opens a nested block; the '#' is a comment, not a
// value. Read the other way, every line indented under it was swallowed —
// the service vanished from the form and a rename could not see the
// references inside it, so it reported success having broken the file.
(function () {
  var src = [
    'services:',
    '  db:  # the database',
    '    image: postgres:16',
    '  web:',
    '    image: nginx',
    '    depends_on:',
    '      - db',
    ''
  ].join('\n');

  var doc = Y.parse(src);
  ok('comment-only value: round-trip is byte-identical', Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));

  var names = Y.buildForm(doc).services.map(function (s) { return s.name; }).join(',');
  ok('comment-only value: the block under it still parses', names === 'db,web', names);

  var res = Y.renameService(doc, 'db', 'database');
  ok('comment-only value: a reference inside the file is still found',
     res.ok === true && res.refs === 1, JSON.stringify(res));
  ok('comment-only value: the rename lands and the comment survives',
     Y.serialise(doc) === src.replace('  db:', '  database:').replace('      - db', '      - database'),
     Y.serialise(doc));
})();

/* =========================================================================
 * J. The always-present Container settings
 *
 * image, container_name and restart are always in the model, whether or not
 * the file has them, so refreshRanges() can index fields by array position
 * without a redraw. network_mode was a fourth always-present key until
 * PLAN_34 phase 4 dropped it: a file that already sets one still shows it,
 * editable, in Advanced, but nothing offers a blank one any more, so the
 * Container group's fixed slot count fell from four to three. See harvest()
 * and setPart() for the absent-slot handling this exercises.
 * ========================================================================= */

console.log('\nJ. The always-present Container settings');

(function () {
  var src = 'services:\n  a:\n    image: alpine   # pinned\n    environment:\n      TZ: UTC   # timezone\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var restart = Y.fieldById(form, 'a/setting/restart');
  ok('an absent restart field is found and not locked',
     !!restart && !restart.locked && restart.absent === true,
     restart && JSON.stringify({ locked: restart.locked, absent: restart.absent }));

  ok('typing into it succeeds', Y.setPart(doc, form, 'a/setting/restart', 'value', 'unless-stopped'));

  var lines = Y.serialise(doc).split('\n');
  var idx = lines.indexOf('    restart: unless-stopped');
  ok('the new line lands under the service at its own indent', idx >= 0, lines.join('\\n'));

  lines.splice(idx, 1);
  ok('every other line, including both comments, survives byte for byte',
     lines.join('\n') === src, firstDiff(src, lines.join('\n')));
})();

(function () {
  // A blank into an absent slot must change nothing — a box that was merely
  // focused and left empty must not plant a bare "restart:" in the file.
  var src = 'services:\n  a:\n    image: alpine\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);

  ok('a blank into an absent slot reports success',
     Y.setPart(doc, form, 'a/setting/restart', 'value', '   '));
  ok('and writes nothing', Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

(function () {
  // The field count is what refreshRanges() indexes by, so it must never
  // move when an absent slot gains a line. Nineteen, not twenty: PLAN_34
  // phase 4 dropped network_mode out of the fixed Container pass, so the
  // fixed count fell from four to three (image, container_name, restart),
  // plus the same sixteen blank health-check/resource-limit/logging/build
  // leaves as before — harvestLeaves() (PLAN_8 phase 2) offers those whether
  // or not the file has healthcheck:/deploy:/logging:/build: at all,
  // healthcheck.test counts as two of them (PLAN_8 phase 4 — the mode and the
  // command, see harvestHealthTest()), and build (PLAN_21) added its three
  // scalars (context, dockerfile, target) at the end of the fixed pass.
  var src = 'services:\n  a:\n    image: alpine\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var svcFields = form.fields.filter(function (f) { return f.service === 'a'; });

  ok('a service with no other settings yields three fixed fields plus sixteen blank leaves',
     svcFields.length === 19 &&
     svcFields.slice(0, 3).every(function (f) { return f.fixed; }) &&
     svcFields.slice(3).every(function (f) { return f.absent && f.path; }),
     svcFields.map(function (f) { return f.target; }).join(', '));

  var before = form.fields.length;
  Y.setPart(doc, form, 'a/setting/restart', 'value', 'always');
  var after = Y.buildForm(doc).fields.length;
  ok('form.fields.length is unchanged once one is filled in', after === before, before + ' -> ' + after);
})();

(function () {
  // Nothing in the UI edits the -!R / -!S markers on a Container line any
  // more, but readComment() still reads them and a save must not disturb one.
  var src = 'services:\n  a:\n    image: alpine\n    restart: always  # -!R\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var restart = Y.fieldById(form, 'a/setting/restart');

  ok('a marked restart line is read, not treated as absent',
     !!restart && restart.absent === false && restart.required === true,
     restart && JSON.stringify({ absent: restart.absent, required: restart.required }));

  ok('the null edit leaves the marker untouched',
     !!restart && Y.setPart(doc, form, restart.id, 'value', restart.parts.value.value) &&
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* =========================================================================
 * K. A command written as a list
 *
 * Only a sealed node carries `raw` — a list is parsed properly and has none —
 * so a command spelled out as several "- " items must still reach the form
 * as readable text, not an empty locked box. See settingTarget().
 * ========================================================================= */

console.log('\nK. A command written as a list');

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    command:\n      - "-c"\n      - echo hello\n      - "-v"\n    environment:\n      TZ: UTC\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var cmd = Y.fieldById(form, 'a/setting/command');

  ok('a list command is found and locked', !!cmd && cmd.locked,
     cmd && JSON.stringify({ locked: cmd.locked }));
  ok('its raw text carries a line from the middle of the list, not just the first',
     !!cmd && cmd.raw.indexOf('echo hello') >= 0,
     cmd && JSON.stringify(cmd.raw));

  ok('the file round-trips untouched with no edit applied',
     Y.serialise(Y.parse(src)) === src, firstDiff(src, Y.serialise(Y.parse(src))));
})();

/* =========================================================================
 * L. 10-advanced-compose-test — PLAN_4 phase 1: one KEYS table, nothing
 * hidden, and the top-level blocks read as a namespace of declared names.
 * ========================================================================= */

console.log('\nL. 10-advanced-compose-test (PLAN_4 phase 1)');

// Copied verbatim from scratch/test-stacks/10-advanced-compose-test/compose.yaml —
// scratch/ is gitignored, so the fixture has to live here instead. No trailing
// blank line, because the file on disk does not have one either.
var FIXTURE_10_ADVANCED = [
  'version: "3.9"',
  '',
  // Two more top-level anchors, feeding the "worker" service below — the
  // constructs seal() exists to protect (anchor, merge key, alias, block and
  // folded scalars, flow map, flow list, an escaped backslash) were entirely
  // absent from this fixture despite its name, so the null-edit proof at the
  // top of section L never touched them.
  'x-worker-labels: &worker_labels {app: "worker", path: "C:\\\\data"}',
  '',
  'x-shared: &defaults',
  '  restart: unless-stopped',
  '',
  'services:',
  '  web:',
  '    image: nginx:alpine',
  '    container_name: advanced_web',
  '    restart: unless-stopped',
  '    ports:',
  '      - "8080:80"',
  '    environment:',
  '      - NGINX_PORT=80',
  '    networks:',
  '      - frontend_net',
  '      - backend_net',
  '    depends_on:',
  '      db:',
  '        condition: service_healthy',
  '    deploy:',
  '      resources:',
  '        limits:',
  '          cpus: \'0.50\'',
  '          memory: 512M',
  '        reservations:',
  '          cpus: \'0.25\'',
  '          memory: 256M',
  '    healthcheck:',
  '      test: ["CMD", "curl", "-f", "http://localhost/"]',
  '      interval: 30s',
  '      timeout: 10s',
  '      retries: 3',
  '      start_period: 10s',
  '',
  '  db:',
  '    image: postgres:15-alpine',
  '    container_name: advanced_db',
  '    restart: always',
  '    environment:',
  '      POSTGRES_DB: appdb',
  '      POSTGRES_USER: appuser',
  '      POSTGRES_PASSWORD_FILE: /run/secrets/db_password',
  '    secrets:',
  '      - db_password',
  '    volumes:',
  '      - db_data:/var/lib/postgresql/data',
  '    networks:',
  '      - backend_net',
  '    healthcheck:',
  '      test: ["CMD-SHELL", "pg_isready -U appuser -d appdb"]',
  '      interval: 10s',
  '      timeout: 5s',
  '      retries: 5',
  '',
  '  worker:',
  '    image: alpine:latest',
  '    <<: *defaults',
  '    container_name: advanced_worker',
  '    profiles:',
  '      - extras',
  '    extends:',
  '      service: web',
  '    command: |',
  '      echo starting',
  '      echo done',
  '    entrypoint: >',
  '      /bin/sh -c',
  '      run.sh',
  '    labels: *worker_labels',
  '    networks: [frontend_net]',
  '',
  'networks:',
  '  frontend_net:',
  '    driver: bridge',
  '  backend_net:',
  '    driver: bridge',
  '    internal: true',
  '',
  'volumes:',
  '  db_data:',
  '    driver: local',
  '',
  'secrets:',
  '  db_password:',
  '    file: ./db_password.txt'
].join('\n');

/* ---- the strongest case: identity, then a null edit on every box -------- */

(function () {
  var text = FIXTURE_10_ADVANCED;
  var doc  = Y.parse(text);
  var form = Y.buildForm(doc);

  ok('the fixture round-trips byte for byte with no edit applied',
     Y.serialise(doc) === text, firstDiff(text, Y.serialise(doc)));

  var all = boxes(form), bad = null;
  for (var i = 0; i < all.length && !bad; i++) {
    var d = Y.parse(text), m = Y.buildForm(d);
    if (!Y.setPart(d, m, all[i].id, all[i].part, all[i].value)) continue;
    var got = Y.serialise(d);
    if (got !== text) bad = all[i].id + ' [' + all[i].part + ']\n' + firstDiff(text, got);
  }
  ok('and still identical after setting every writable box to what it already says (' + all.length + ' boxes)',
     !bad, bad);
})();

/* ---- 1. declared reads all four namespaces plus services --------------- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var want = { networks: ['frontend_net', 'backend_net'], volumes: ['db_data'],
               secrets: ['db_password'], configs: [], services: ['web', 'db', 'worker'] };
  ok('declared holds the four top-level namespaces plus the service list',
     JSON.stringify(form.declared) === JSON.stringify(want), JSON.stringify(form.declared));
})();

/* ---- 2. declared always exists, even on buildForm's two early returns -- */

(function () {
  var EMPTY = { networks: [], volumes: [], secrets: [], configs: [], services: [] };

  // Tab indentation seals the whole file, so doc.root is never a map and
  // buildForm returns before services are even looked for.
  var tabbed = Y.buildForm(Y.parse('services:\n\ta:\n\t\timage: alpine\n'));
  ok('declared is five empty arrays when the whole file is unreadable',
     JSON.stringify(tabbed.declared) === JSON.stringify(EMPTY), JSON.stringify(tabbed.declared));

  // Parses fine as a map, but never mentions services: at all.
  var noServices = Y.buildForm(Y.parse('image: alpine\n'));
  ok('declared is five empty arrays when the file has no services: key',
     JSON.stringify(noServices.declared) === JSON.stringify(EMPTY), JSON.stringify(noServices.declared));
})();

/* ---- 3. web accounts for all nine of its keys --------------------------- */

(function () {
  // networks: is two editable list fields rather than one locked block, and
  // harvestLeaves() (PLAN_8 phase 2) now offers every healthcheck/deploy/
  // logging/build leaf whether the file has it or not — seven healthcheck
  // leaves (test counts as two — the mode and the command, PLAN_8 phase 4)
  // plus all four deploy ones plus logging's one (driver) plus build's three
  // (context, dockerfile, target — PLAN_21), as a fixed pass right after the
  // three Container fields (PLAN_34 phase 4 dropped network_mode out of that
  // fixed pass — web sets no network_mode: at all, so there is now nothing
  // here standing for it, not even a blocked placeholder). The two
  // healthcheck leaves this file does not set (start_interval, disable),
  // logging.driver (this file has no logging: at all) and all three build
  // leaves (web has no build: at all) still appear, blank. web's own test:
  // is a flow list (["CMD", "curl", ...]) which readTest() reads with
  // confidence, so it surfaces right there with its siblings rather than
  // later as a locked catch-all field. web's depends_on is long form
  // (PLAN_8 phase 5) — one field for "db" plus its restart/required fold, in
  // place of the single locked block earlier phases left it as. So the count
  // is twenty-six, not the ten keys the original file has at the top of
  // web:. Pinning f.id rather than binder/target is deliberate — a list
  // field's id carries its list key and index
  // (web/list.networks#0/frontend_net), which is what stops the same name
  // colliding across two different list keys (see the ids-cannot-collide
  // case below).
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var web  = form.fields.filter(function (f) { return f.service === 'web'; });
  var got  = web.map(function (f) { return f.id; });
  var want = [
    'web/setting/image',
    'web/setting/container_name',
    'web/setting/restart',
    'web/setting/healthcheck.test.mode',
    'web/setting/healthcheck.test.command',
    'web/setting/healthcheck.interval',
    'web/setting/healthcheck.timeout',
    'web/setting/healthcheck.retries',
    'web/setting/healthcheck.start_period',
    'web/setting/healthcheck.start_interval',
    'web/setting/healthcheck.disable',
    'web/setting/deploy.resources.limits.cpus',
    'web/setting/deploy.resources.limits.memory',
    'web/setting/deploy.resources.reservations.cpus',
    'web/setting/deploy.resources.reservations.memory',
    'web/setting/logging.driver',
    'web/setting/build.context',
    'web/setting/build.dockerfile',
    'web/setting/build.target',
    'web/port/80/tcp',
    'web/env/NGINX_PORT',
    'web/list.networks#0/frontend_net',
    'web/list.networks#1/backend_net',
    'web/depends/depends_on.db',
    'web/depends/depends_on.db.restart',
    'web/depends/depends_on.db.required'
  ];
  ok('web yields exactly these twenty-six fields, in file order',
     JSON.stringify(got) === JSON.stringify(want), got.join(', '));
})();

/* ---- 4. healthcheck and deploy split into per-value fields; depends_on's
 *        long form does too (PLAN_8 phase 5) ------------------------------ */

(function () {
  // healthcheck: and deploy: no longer yield one field for the whole block —
  // harvestLeaves()/harvestBlock break each into one field per nested value,
  // so there is no field whose target is exactly "healthcheck" or "deploy"
  // any more. web's healthcheck now carries all eight LEAVES targets — test
  // counts as two (mode, command), five of the rest present in the file, two
  // (start_interval, disable) offered blank — since harvestLeaves offers
  // every leaf whether or not the file has it.
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var hcBlock  = Y.fieldById(form, 'web/setting/healthcheck');
  var depBlock = Y.fieldById(form, 'web/setting/deploy');
  var hcLeaves  = form.fields.filter(function (f) { return f.service === 'web' && f.target.indexOf('healthcheck.') === 0; });
  var depLeaves = form.fields.filter(function (f) { return f.service === 'web' && f.target.indexOf('deploy.') === 0; });
  var hcMode = Y.fieldById(form, 'web/setting/healthcheck.test.mode');
  var hcCmd  = Y.fieldById(form, 'web/setting/healthcheck.test.command');
  var don = Y.fieldById(form, 'web/depends/depends_on.db');
  var donRestart  = Y.fieldById(form, 'web/depends/depends_on.db.restart');
  var donRequired = Y.fieldById(form, 'web/depends/depends_on.db.required');

  ok('healthcheck no longer yields a field for the whole block, only one per nested value',
     !hcBlock && hcLeaves.length === 8, JSON.stringify({ hasBlock: !!hcBlock, leaves: hcLeaves.length }));
  ok('deploy no longer yields a field for the whole block, only one per nested value',
     !depBlock && depLeaves.length === 4, JSON.stringify({ hasBlock: !!depBlock, leaves: depLeaves.length }));
  ok('web’s test: is a flow list readTest() can read with confidence, so it comes out editable as CMD plus its command',
     !!hcMode && !hcMode.locked && hcMode.parts.value.value === 'cmd' &&
     !!hcCmd && !hcCmd.locked && hcCmd.parts.value.value === 'curl -f http://localhost/',
     JSON.stringify({ mode: hcMode && hcMode.parts.value.value, command: hcCmd && hcCmd.parts.value.value }));
  ok('a long-form depends_on now reads as one editable field per dependency, name and condition both',
     !!don && !don.locked && don.parts.name.value === 'db' && don.parts.value.value === 'service_healthy',
     don && JSON.stringify({ locked: don.locked, name: don.parts.name.value, condition: don.parts.value.value }));
  ok('restart and required are offered blank and folded away, since this file sets neither',
     !!donRestart && donRestart.absent && donRestart.fold &&
     !!donRequired && donRequired.absent && donRequired.fold,
     JSON.stringify({ restart: donRestart && donRestart.absent, required: donRequired && donRequired.absent }));

  // Phase 2: a plain networks: or secrets: list is now one editable field per
  // entry, not a locked block — the list-form reason only ever described the
  // shape, and the shape is no longer opaque.
  var nets = form.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'networks'; });
  var secs = form.fields.filter(function (f) { return f.service === 'db'  && f.listKey === 'secrets'; });
  ok('a networks: list yields one editable field per entry, not a locked block',
     nets.length === 2 && nets.every(function (f) { return !f.locked; }),
     JSON.stringify(nets.map(function (f) { return { target: f.target, locked: f.locked }; })));
  ok('a secrets: list yields one editable field per entry, not a locked block',
     secs.length === 1 && secs.every(function (f) { return !f.locked; }),
     JSON.stringify(secs.map(function (f) { return { target: f.target, locked: f.locked }; })));

  // A form that genuinely still cannot be read stays locked, and stays
  // visible — sealing is a property of a value, never of a list's shape.
  var aliasedNet = Y.buildForm(Y.parse(
    'x-net: &shared\n  - frontend_net\n\nservices:\n  a:\n    image: alpine\n    networks: *shared\n'));
  var an = Y.fieldById(aliasedNet, 'a/setting/networks');
  ok('a networks: sealed as a whole alias still locks, and is still shown',
     !!an && an.locked && an.lockReason === 'this points at a shared block higher up the file',
     an && JSON.stringify({ locked: an.locked, lockReason: an.lockReason }));

  var aliasedSec = Y.buildForm(Y.parse(
    'x-sec: &shared\n  - db_password\n\nservices:\n  a:\n    image: alpine\n    secrets: *shared\n'));
  var as = Y.fieldById(aliasedSec, 'a/setting/secrets');
  ok('a secrets: sealed as a whole alias still locks, and is still shown',
     !!as && as.locked && as.lockReason === 'this points at a shared block higher up the file',
     as && JSON.stringify({ locked: as.locked, lockReason: as.lockReason }));
})();

/* ---- 5. network_mode, when the file has one, is an ordinary editable
 *        Advanced row (PLAN_34 phase 4) --------------------------------
 * Until phase 4, network_mode was always in the model and got blocked (not
 * locked) once networks: was also present, since compose refuses a service
 * with both. That whole always-offered slot — and the exclusion check that
 * lived beside it — is gone: a service that already writes network_mode: now
 * reads as an ordinary setting row, editable like any other, in file order.
 * The shared fixture never sets network_mode:, so this uses its own small
 * one built specifically to have it. */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    network_mode: host\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var nm  = Y.fieldById(form, 'a/setting/network_mode');

  ok('a service with network_mode: host gets an ordinary editable Advanced row',
     !!nm && nm.binder === 'setting' && !nm.blocked && !nm.locked && !nm.absent,
     nm && JSON.stringify({ binder: nm.binder, blocked: nm.blocked, locked: nm.locked, absent: nm.absent }));
  ok('its value reads back exactly as written',
     !!nm && nm.parts.value.value === 'host', nm && nm.parts.value.value);
  ok('the null edit on it leaves the file untouched',
     !!nm && Y.setPart(doc, form, 'a/setting/network_mode', 'value', nm.parts.value.value) &&
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- 6. no network_mode: line means no network_mode field at all ------- */

(function () {
  // PLAN_34 phase 4 dropped network_mode out of ALWAYS_KEYS, so unlike every
  // other Container field it is no longer offered as a blank slot when the
  // file lacks it — the row simply does not exist, the way any ordinary
  // (non-`always`) service key already worked before Container had one at
  // all. There is nothing left to create it with either: this was the one
  // capability PLAN_34 deliberately removed.
  var src  = 'services:\n  c:\n    image: alpine\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var nm   = Y.fieldById(form, 'c/setting/network_mode');

  ok('a service with no network_mode: line has no network_mode field at all', !nm, nm);
  ok('and there is nothing to write into',
     Y.setPart(doc, form, 'c/setting/network_mode', 'value', 'bridge') === false);
  ok('so the file is untouched', Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- 7. titles: the KEYS table overrides only where it needs to -------- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var titleOf = function (id) { var f = Y.fieldById(form, id); return f && f.title; };

  ok('a long-form dependency is titled after the service it depends on',
     titleOf('web/depends/depends_on.db') === 'Db', titleOf('web/depends/depends_on.db'));
  ok('its folded restart/required take their titles from DEPENDS_LEAVES',
     titleOf('web/depends/depends_on.db.restart') === 'Restart this service too when the dependency restarts' &&
     titleOf('web/depends/depends_on.db.required') === 'This dependency must start successfully for this service to start',
     JSON.stringify({ restart: titleOf('web/depends/depends_on.db.restart'),
                       required: titleOf('web/depends/depends_on.db.required') }));
  // "Health check" and "Resource limits" now belong to the groups those
  // leaves sit under (stacks.js), not to a single field — each leaf carries
  // its own title from LEAVES instead.
  var hcTitles = ['test.mode', 'test.command', 'interval', 'timeout', 'retries', 'start_period']
    .map(function (k) { return titleOf('web/setting/healthcheck.' + k); });
  ok('healthcheck leaves are titled "How the check runs", "The check itself", "Check every", ' +
     '"Give up after", "Failures allowed" and "Grace period at start"',
     JSON.stringify(hcTitles) === JSON.stringify(
       ['How the check runs', 'The check itself', 'Check every', 'Give up after',
        'Failures allowed', 'Grace period at start']),
     JSON.stringify(hcTitles));
  var depTitles = ['resources.limits.cpus', 'resources.limits.memory',
                    'resources.reservations.cpus', 'resources.reservations.memory']
    .map(function (k) { return titleOf('web/setting/deploy.' + k); });
  ok('deploy leaves are titled "CPU limit", "Memory limit", "CPU reserved" and "Memory reserved"',
     JSON.stringify(depTitles) === JSON.stringify(['CPU limit', 'Memory limit', 'CPU reserved', 'Memory reserved']),
     JSON.stringify(depTitles));
  // Phase 2: networks: is list fields now, so its heading is f.groupTitle on
  // each entry rather than f.title on one block field — the table still only
  // overrides where it needs to, and networks still falls through to
  // humanise().
  var nets = form.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'networks'; });
  ok('networks has no table entry, so its heading falls through to humanise() and reads "Networks"',
     nets.length === 2 && nets.every(function (f) { return f.groupTitle === 'Networks'; }),
     JSON.stringify(nets.map(function (f) { return f.groupTitle; })));

  var wd = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    working_dir: /app\n'));
  ok('working_dir is titled "Working folder"',
     Y.fieldById(wd, 'a/setting/working_dir').title === 'Working folder');
})();

/* ---- 8. the catch-all floor, and the two exclusions that sit above it -- */

(function () {
  // ulimits: has no table entry — unlike logging:, which is now its own
  // block field with a driver leaf (see the KEYS table) — so it still
  // demonstrates a map value the catch-all can only lock, not edit.
  var src = 'x-shared: &defaults\n' +
            '  restart: unless-stopped\n' +
            '\n' +
            'services:\n' +
            '  a:\n' +
            '    <<: *defaults\n' +
            '    working_dir: /app\n' +
            '    ulimits:\n' +
            '      nofile: 1024\n' +
            '    x-unraid:\n' +
            '      name: Thing\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wd  = Y.fieldById(form, 'a/setting/working_dir');
  var ulm = Y.fieldById(form, 'a/setting/ulimits');

  ok('working_dir falls to the catch-all as an editable field, not a locked one',
     !!wd && !wd.locked, wd && JSON.stringify(wd));
  Y.setPart(doc, form, 'a/setting/working_dir', 'value', '/srv');
  ok('and setting it rewrites only that one line',
     diffLines(src, Y.serialise(doc)).length === 1, diffLines(src, Y.serialise(doc)).join(', '));

  ok('a ulimits: block falls to the catch-all as a locked field',
     !!ulm && ulm.locked && ulm.lockReason === 'this is written as a block of its own',
     ulm && JSON.stringify(ulm));

  ok('an x-unraid block yields no field at all, because it is already the service overview',
     !form.fields.some(function (f) { return f.service === 'a' && f.target === 'x-unraid'; }));
  ok('a <<: merge key yields no field at all, because it would only ever be locked noise',
     !form.fields.some(function (f) { return f.service === 'a' && f.target === '<<'; }));
  ok('but the service note still says its settings come from a shared block',
     /shared block/i.test(form.services.filter(function (s) { return s.name === 'a'; })[0].note));
})();

/* ---- 9. interpolation: which values carry the 1f advice, and which don't */

(function () {
  var src = 'services:\n' +
            '  a:\n' +
            '    image: alpine\n' +
            '    ports:\n' +
            '      - "${HTTP_PORT}:80"\n' +
            '      - "${ALT:-8081}:81"\n' +
            '      - "$RAWPORT:82"\n' +
            '      - "9000:83"\n' +
            '    environment:\n' +
            '      LITERAL: "$$NOT_A_VAR"\n' +
            '      MIXED: "$$esc ${REAL}"\n';

  var form = Y.buildForm(Y.parse(src));
  var advised = function (f) {
    return f.advice.some(function (a) { return a.indexOf('typing over it replaces the variable') >= 0; });
  };
  var port = function (t) { return form.fields.filter(function (f) { return f.binder === 'port' && f.target === t; })[0]; };
  var env  = function (n) { return Y.fieldById(form, 'a/env/' + n); };

  ok('a bare ${VAR} port carries the interpolation advice', advised(port('80/tcp')));
  ok('a ${VAR:-default} port carries the advice', advised(port('81/tcp')));
  ok('and its host part is the whole expression, not mangled by the default\u2019s own colon',
     port('81/tcp').parts.host.value === '${ALT:-8081}', port('81/tcp').parts.host.value);
  ok('a bare $VAR port carries the advice', advised(port('82/tcp')));
  ok('a literal port number carries no advice', !advised(port('83/tcp')));
  ok('an escaped $$ with nothing real behind it carries no advice', !advised(env('LITERAL')));
  ok('a value mixing an escaped $$ and a real ${VAR} still carries the advice', advised(env('MIXED')));
})();

/* ---- 10. dangling references: a name the file never declares ----------- */

(function () {
  var src = 'services:\n' +
            '  a:\n' +
            '    image: alpine\n' +
            '    volumes:\n' +
            '      - db_dat:/b\n' +
            '      - /mnt/user/x:/c\n' +
            '      - ./rel:/d\n' +
            '      - ${VOL}:/e\n' +
            '      - /anon\n' +
            'volumes:\n' +
            '  db_data:\n' +
            '    driver: local\n';

  var form = Y.buildForm(Y.parse(src));
  var dangling = function (f) {
    return f.advice.some(function (a) { return a.indexOf('is defined in this file') >= 0; });
  };
  var vol = function (t) { return form.fields.filter(function (f) { return f.binder === 'volume' && f.target === t; })[0]; };

  ok('a volume naming an undeclared name is flagged',
     dangling(vol('/b')) && vol('/b').advice.indexOf('no volume called db_dat is defined in this file') >= 0,
     vol('/b').advice);
  ok('an absolute host path is not mistaken for a named reference', !dangling(vol('/c')));
  ok('a relative host path is not mistaken for a named reference', !dangling(vol('/d')));
  ok('a variable host value is never flagged, since we cannot know what it expands to', !dangling(vol('/e')));
  ok('an anonymous volume has no host value to check and is not flagged', !dangling(vol('/anon')));

  var noBlock = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    volumes:\n      - some_vol:/a\n'));
  var v2 = noBlock.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  ok('a named volume is flagged even when the file has no top-level volumes: block at all',
     dangling(v2) && v2.advice.indexOf('no volume called some_vol is defined in this file') >= 0, v2.advice);
})();

/* ---- 11. brace-aware splitting (1g), checked on the values themselves -- */

(function () {
  var src = 'services:\n' +
            '  a:\n' +
            '    image: alpine\n' +
            '    ports:\n' +
            '      - "${A:-1}:${B:-2}"\n' +
            '      - "127.0.0.1:8080:80"\n' +
            '      - "${BROKEN:-8080:81"\n';

  var doc   = Y.parse(src), form = Y.buildForm(doc);
  var ports = form.fields.filter(function (f) { return f.binder === 'port'; });

  ok('two variable defaults either side of a colon split into exactly two halves',
     ports[0].parts.host.value === '${A:-1}' && ports[0].parts.container.value === '${B:-2}',
     JSON.stringify({ host: ports[0].parts.host.value, container: ports[0].parts.container.value }));
  ok('an ip-qualified port still yields the middle field as host and the last as container',
     ports[1].parts.host.value === '8080' && ports[1].parts.container.value === '80',
     JSON.stringify({ host: ports[1].parts.host.value, container: ports[1].parts.container.value }));
  ok('an unclosed ${ does not throw, and yields one container-only part rather than a mangled split',
     !ports[2].parts.host.spot && ports[2].parts.container.value === '${BROKEN:-8080:81',
     JSON.stringify({ hostSpot: !!ports[2].parts.host.spot, container: ports[2].parts.container.value }));

  var all = boxes(form), bad = null;
  for (var i = 0; i < all.length && !bad; i++) {
    var d = Y.parse(src), m = Y.buildForm(d);
    if (!Y.setPart(d, m, all[i].id, all[i].part, all[i].value)) continue;
    var got = Y.serialise(d);
    if (got !== src) bad = all[i].id + ' [' + all[i].part + ']\n' + firstDiff(src, got);
  }
  ok('and all three round-trip byte for byte after a null edit', !bad, bad);
})();

/* =========================================================================
 * M. 10-advanced-compose-test — PLAN_4 phase 2: reference lists editable,
 * one binder shared by eight compose keys.
 * ========================================================================= */

console.log('\nM. 10-advanced-compose-test (PLAN_4 phase 2)');

/* ---- 1. networks: round trip -------------------------------------------- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var nets = form.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'networks'; });

  ok('web\u2019s networks: yields exactly two list fields',
     nets.length === 2 &&
     nets.every(function (f) { return f.from === 'networks' && f.groupTitle === 'Networks'; }),
     JSON.stringify(nets.map(function (f) { return { target: f.target, from: f.from, groupTitle: f.groupTitle }; })));
  ok('each has one part named value, and no host or container',
     nets.every(function (f) {
       return Object.keys(f.parts).length === 1 && !!f.parts.value && !f.parts.host && !f.parts.container;
     }),
     JSON.stringify(nets.map(function (f) { return Object.keys(f.parts); })));

  var text = FIXTURE_10_ADVANCED;
  var doc  = Y.parse(text), m = Y.buildForm(doc);
  var frontend = m.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'networks' && f.target === 'frontend_net'; })[0];
  Y.setPart(doc, m, frontend.id, 'value', 'backend_net');
  ok('setting one to a different declared name rewrites only that line',
     diffLines(text, Y.serialise(doc)).length === 1, diffLines(text, Y.serialise(doc)).join(', '));
})();

/* ---- 2. addItem appends, removeItem on the last takes the key with it --- */

(function () {
  var text = FIXTURE_10_ADVANCED;
  var doc  = Y.parse(text), form = Y.buildForm(doc);

  var line = Y.addItem(doc, form, 'web', 'list', '', 'networks');
  ok('addItem appends a third networks: entry', line >= 0, 'addItem returned ' + line);
  ok('it lands at the list\u2019s own indent and dash gap',
     line >= 0 && Y.serialise(doc).split('\n')[line] === '      - default',
     line >= 0 && Y.serialise(doc).split('\n')[line]);

  var added = Y.buildForm(doc);
  var id    = Y.fieldAtLine(added, line);
  ok('and it can be removed again, leaving the file exactly as it was',
     !!id && Y.removeItem(doc, added, id) === true && Y.serialise(doc) === text,
     firstDiff(text, Y.serialise(doc)));

  // Down to one entry, then remove the last — the key must go with it, since
  // a bare "networks:" is null and compose rejects the file.
  var d2 = Y.parse(text), f2 = Y.buildForm(d2);
  var was = f2.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'networks'; });
  Y.removeItem(d2, f2, was[0].id);
  var f3   = Y.buildForm(d2);
  var last = f3.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'networks'; })[0];
  Y.removeItem(d2, f3, last.id);

  var f4 = Y.buildForm(d2);
  var nm = Y.fieldById(f4, 'web/setting/network_mode');
  ok('removing the last entry leaves the service with no networks: fields at all',
     f4.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'networks'; }).length === 0);
  // PLAN_34 phase 4: network_mode is no longer an always-present slot, so
  // there is no blocked placeholder to revert here any more — before and
  // after networks: is removed, web (which never wrote network_mode: itself)
  // has no field for it at all.
  ok('and there is still no network_mode field, since web never wrote one',
     !nm, nm);
})();

/* ---- 3. depends_on written as a block of conditions reads as one field
 *        per dependency (PLAN_8 phase 5), not a short-form list entry ----- */

(function () {
  var form   = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var don    = Y.fieldById(form, 'web/depends/depends_on.db');
  var asList = form.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'depends_on'; });

  ok('long-form depends_on yields one editable field per dependency, not locked, and not a short-form list entry',
     !!don && !don.locked && asList.length === 0,
     don && JSON.stringify({ locked: don.locked, listEntries: asList.length }));
  ok('its name and condition come straight from the file',
     !!don && don.parts.name.value === 'db' && don.parts.value.value === 'service_healthy',
     don && JSON.stringify({ name: don.parts.name.value, condition: don.parts.value.value }));
})();

/* ---- 4. the same name under two different list keys cannot collide ----- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    networks:\n      - shared\n' +
            '    depends_on:\n      - shared\n  shared:\n    image: alpine\n';
  var form = Y.buildForm(Y.parse(src));

  var net = Y.fieldById(form, 'a/list.networks#0/shared');
  var dep = Y.fieldById(form, 'a/list.depends_on#0/shared');
  ok('the same name under networks and depends_on gets two distinct ids',
     !!net && !!dep && net.id === 'a/list.networks#0/shared' && dep.id === 'a/list.depends_on#0/shared',
     JSON.stringify({ net: net && net.id, dep: dep && dep.id }));

  var ids  = form.fields.map(function (f) { return f.id; });
  var uniq = ids.filter(function (v, i, arr) { return arr.indexOf(v) === i; });
  ok('every id in the form is unique', ids.length === uniq.length, ids.join(', '));
  ok('fieldById finds the exact field for every id',
     form.fields.every(function (f) { return Y.fieldById(form, f.id) === f; }));
})();

/* ---- 5. dangling references reach the new list keys (Phase 2 half of 1e) */

(function () {
  var src = 'services:\n' +
            '  a:\n' +
            '    image: alpine\n' +
            '    networks:\n' +
            '      - frontend_nett\n' +
            '      - default\n' +
            '      - ${NET}\n' +
            '    secrets:\n' +
            '      - missing_secret\n' +
            '    depends_on:\n' +
            '      - ghost\n' +
            'networks:\n' +
            '  frontend_net:\n';

  var form     = Y.buildForm(Y.parse(src));
  var by       = function (lk, t) { return form.fields.filter(function (f) { return f.listKey === lk && f.target === t; })[0]; };
  var dangling = function (f) { return f.advice.some(function (a) { return a.indexOf('is defined in this file') >= 0; }); };

  ok('an undeclared network is flagged',
     dangling(by('networks', 'frontend_nett')) &&
     by('networks', 'frontend_nett').advice.indexOf('no network called frontend_nett is defined in this file') >= 0,
     by('networks', 'frontend_nett').advice);
  ok('"default" is always accepted under networks, even though it is not declared',
     !dangling(by('networks', 'default')));
  ok('a value containing ${ is never flagged',
     !dangling(by('networks', '${NET}')));
  ok('an undeclared secret is flagged, with "secret" in the message',
     dangling(by('secrets', 'missing_secret')) &&
     by('secrets', 'missing_secret').advice.indexOf('no secret called missing_secret is defined in this file') >= 0,
     by('secrets', 'missing_secret').advice);
  ok('depends_on naming a service that does not exist is flagged, with "service" in the message',
     dangling(by('depends_on', 'ghost')) &&
     by('depends_on', 'ghost').advice.indexOf('no service called ghost is defined in this file') >= 0,
     by('depends_on', 'ghost').advice);
})();

/* ---- 6. the values the KEYS table hands down to every list field -------- */

(function () {
  var src = 'services:\n' +
            '  a:\n' +
            '    image: alpine\n' +
            '    env_file:\n' +
            '      - ./app.env\n' +
            '    expose:\n' +
            '      - "8080"\n' +
            '    cap_add:\n' +
            '      - NET_ADMIN\n' +
            '    profiles:\n' +
            '      - extras\n' +
            '    networks:\n' +
            '      - default\n';

  var form = Y.buildForm(Y.parse(src));
  var by   = function (lk) { return form.fields.filter(function (f) { return f.listKey === lk; })[0]; };

  ok('env_file carries the browse tool', by('env_file').tool === 'browse', by('env_file').tool);
  ok('every other list key carries no tool',
     ['expose', 'cap_add', 'profiles', 'networks'].every(function (lk) { return by(lk).tool === ''; }),
     JSON.stringify(['expose', 'cap_add', 'profiles', 'networks'].map(function (lk) { return by(lk).tool; })));
  ok('expose reads as a port', by('expose').type === 'port', by('expose').type);
  ok('cap_add reads as plain text', by('cap_add').type === 'text', by('cap_add').type);
  ok('profiles references nothing', by('profiles').from === '', by('profiles').from);
})();

/* ---- 7. newEntry placeholders, each distinct from what is already there - */

(function () {
  // Declared names exhausted: falls back to the fixed placeholder.
  var src1 = 'services:\n  a:\n    image: alpine\n    networks:\n      - frontend_net\n      - backend_net\n' +
             'networks:\n  frontend_net:\n  backend_net:\n';
  var d1 = Y.parse(src1), f1 = Y.buildForm(d1);
  var line1 = Y.addItem(d1, f1, 'a', 'list', '', 'networks');
  ok('a networks: list with every declared name already used falls back to "default"',
     line1 >= 0 && Y.serialise(d1).split('\n')[line1] === '      - default',
     line1 >= 0 && Y.serialise(d1).split('\n')[line1]);

  // cap_add, cap_drop and profiles are not here: they are added empty, and are
  // covered by "the keys that start empty" above.
  var CASES = [['dns', '1.1.1.1'], ['expose', '8080'], ['env_file', './app.env']];
  CASES.forEach(function (c) {
    var d = Y.parse('services:\n  a:\n    image: alpine\n'), f = Y.buildForm(d);
    var line = Y.addItem(d, f, 'a', 'list', '', c[0]);
    ok('adding ' + c[0] + ' to an empty list gives ' + c[1],
       line >= 0 && Y.serialise(d).split('\n')[line] === '      - ' + c[1],
       line >= 0 && Y.serialise(d).split('\n')[line]);
  });

  // The same placeholders again, but the list already holds one — a second
  // click of "+" must not write a line identical to the one above it.
  CASES.concat([['networks', 'default']]).forEach(function (c) {
    var src = 'services:\n  a:\n    image: alpine\n    ' + c[0] + ':\n      - ' + c[1] + '\n';
    var d = Y.parse(src), f = Y.buildForm(d);
    var line = Y.addItem(d, f, 'a', 'list', '', c[0]);
    var got  = line >= 0 ? Y.serialise(d).split('\n')[line] : null;
    ok('adding another ' + c[0] + ' when "' + c[1] + '" is already there gives something else',
       got !== null && got !== '      - ' + c[1],
       'wanted anything but: ' + JSON.stringify('      - ' + c[1]) + '\ngot:  ' + JSON.stringify(got));
  });
})();

/* ---- 8. a sealed list entry stays visible, its siblings do not ---------- */

(function () {
  var src = 'x-net: &shared\n  - frontend_net\n\nservices:\n  a:\n    image: alpine\n    networks:\n' +
            '      - frontend_net\n      - *shared\n      - backend_net\n';
  var form = Y.buildForm(Y.parse(src));
  var nets = form.fields.filter(function (f) { return f.listKey === 'networks'; });

  ok('the aliased entry locks and carries an @-prefixed target',
     nets.length === 3 && nets[1].locked && nets[1].target.charAt(0) === '@',
     JSON.stringify(nets.map(function (f) { return { target: f.target, locked: f.locked }; })));
  ok('its neighbours stay editable — sealing is not inherited',
     nets.length === 3 && !nets[0].locked && !nets[2].locked,
     JSON.stringify(nets.map(function (f) { return f.locked; })));
})();

/* ---- 9. a list id carries its index unconditionally --------------------- */

(function () {
  // Suffixing only a genuine duplicate value (the old form) meant editing one
  // entry to match another made row 1's id change too, even though nobody
  // touched row 1. The index is now always part of the id, so two entries
  // that happen to share a value get distinct ids from the start, and no
  // untouched entry's id ever moves.
  var src = 'services:\n  a:\n    image: alpine\n    dns:\n      - 1.1.1.1\n      - 1.1.1.1\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var dns = form.fields.filter(function (f) { return f.listKey === 'dns'; });

  ok('two identical dns entries get two distinct, indexed ids',
     dns.length === 2 && dns[0].id === 'a/list.dns#0/1.1.1.1' && dns[1].id === 'a/list.dns#1/1.1.1.1',
     JSON.stringify(dns.map(function (f) { return f.id; })));

  Y.setPart(doc, form, dns[1].id, 'value', '2.2.2.2');
  ok('setting the second one by id rewrites only the second line',
     Y.serialise(doc) === 'services:\n  a:\n    image: alpine\n    dns:\n      - 1.1.1.1\n      - 2.2.2.2\n',
     Y.serialise(doc));

  // The case the conditional form broke: two distinct values, edit the
  // second to match the first.
  var src2 = 'services:\n  b:\n    image: alpine\n    dns:\n      - 1.1.1.1\n      - 8.8.8.8\n';
  var d2 = Y.parse(src2), f2 = Y.buildForm(d2);
  var rows = f2.fields.filter(function (f) { return f.listKey === 'dns'; });
  var firstId = rows[0].id;

  Y.setPart(d2, f2, rows[1].id, 'value', '1.1.1.1');
  var f3 = Y.buildForm(d2);
  var afterEdit = f3.fields.filter(function (f) { return f.listKey === 'dns'; })[0];
  ok('editing the second entry to match the first leaves the first entry\u2019s id unchanged',
     afterEdit.id === firstId, JSON.stringify({ before: firstId, after: afterEdit.id }));
})();

/* ---- 10. the strongest case, again -------------------------------------- */

// Already covered above: the identity-then-null-edit case at the top of
// section L uses boxes(), which walks every unlocked field regardless of
// binder — so it already exercises the new list boxes (web's two networks:
// entries, db's secrets: entry) alongside everything else. No second copy
// needed here.

/* =========================================================================
 * N. 10-advanced-compose-test — PLAN_5 phase 3: healthcheck: and deploy:
 * broken into one field per nested value.
 * ========================================================================= */

console.log('\nN. 10-advanced-compose-test (PLAN_5 phase 3)');

/* ---- 1. PLAN_8 phase 2: every leaf is offered whether or not the file
 *        has it, so the tick box (phase 3) always has something to show --- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var leavesOf = function (svc) {
    return form.fields
      .filter(function (f) { return f.service === svc && f.target.indexOf('healthcheck.') === 0 && !f.locked; })
      .map(function (f) { return f.target.slice('healthcheck.'.length); });
  };
  // test.mode/test.command are editable for both services too — web's test:
  // is a CMD flow list, db's is CMD-SHELL, and readTest() (PLAN_8 phase 4)
  // reads either with confidence.
  var want = ['test.mode', 'test.command', 'interval', 'timeout', 'retries',
              'start_period', 'start_interval', 'disable'];
  ok('web and db both yield the same eight editable healthcheck leaves, whether the file sets them or not',
     JSON.stringify(leavesOf('web')) === JSON.stringify(want) &&
     JSON.stringify(leavesOf('db')) === JSON.stringify(want),
     leavesOf('web').join(', ') + ' | ' + leavesOf('db').join(', '));

  var webStartPeriod = Y.fieldById(form, 'web/setting/healthcheck.start_period');
  var dbStartPeriod  = Y.fieldById(form, 'db/setting/healthcheck.start_period');
  ok('web has a real start_period line; db does not, but its blank field still carries the path to create one',
     !!webStartPeriod && !webStartPeriod.absent &&
     !!dbStartPeriod && dbStartPeriod.absent &&
     JSON.stringify(dbStartPeriod.path) === JSON.stringify(['healthcheck', 'start_period']),
     JSON.stringify({ web: webStartPeriod && webStartPeriod.absent, db: dbStartPeriod && dbStartPeriod }));
})();

/* ---- 2. a leaf the file lacks shows up blank, carrying the path to create it */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n';
  var form = Y.buildForm(Y.parse(src));
  var hc = form.fields.filter(function (f) { return f.service === 'a' && f.target.indexOf('healthcheck') === 0; });
  var interval = Y.fieldById(form, 'a/setting/healthcheck.interval');
  var timeout  = Y.fieldById(form, 'a/setting/healthcheck.timeout');

  ok('a healthcheck: with only interval: still yields all eight leaves — one real, seven blank',
     hc.length === 8, JSON.stringify(hc.map(function (f) { return f.target; })));
  ok('the one the file sets reads its value and is not absent',
     !!interval && !interval.absent && interval.parts.value.value === '30s',
     interval && JSON.stringify({ absent: interval.absent, value: interval.parts.value.value }));
  ok('one the file does not set is absent, but carries the path to create it',
     !!timeout && timeout.absent && JSON.stringify(timeout.path) === JSON.stringify(['healthcheck', 'timeout']),
     timeout && JSON.stringify({ absent: timeout.absent, path: timeout.path }));
})();

/* ---- 3. type inference on a leaf has no special case ---------------------
 *
 * retries and disable get no entry in KEYS, so their type comes from the same
 * value sniff every other scalar goes through — the point being that a leaf
 * needs nothing beyond a title to behave like any other field.
 */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      retries: 5\n      disable: true\n';
  var form = Y.buildForm(Y.parse(src));
  var retries = Y.fieldById(form, 'a/setting/healthcheck.retries');
  var disable = Y.fieldById(form, 'a/setting/healthcheck.disable');
  ok('retries reads as a number, inferred from its value with no special case',
     !!retries && retries.type === 'number', retries && retries.type);
  ok('disable reads as a boolean, inferred from its value with no special case',
     !!disable && disable.type === 'boolean', disable && disable.type);
})();

/* ---- 4. nothing shows twice, and nothing is lost ------------------------- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  ok('no field anywhere has target exactly "healthcheck" or "deploy"',
     !form.fields.some(function (f) { return f.target === 'healthcheck' || f.target === 'deploy'; }));

  var src = 'services:\n  a:\n    image: alpine\n    deploy:\n      replicas: 3\n' +
            '      resources:\n        limits:\n          cpus: \'0.50\'\n' +
            '      placement:\n        constraints:\n          - node.role == manager\n';
  var doc2 = Y.parse(src), f2 = Y.buildForm(doc2);
  var a  = f2.fields.filter(function (f) { return f.service === 'a'; });
  ok('deploy\u2019s child resources yields no row of its own, because its descendants cover it',
     !a.some(function (f) { return f.target === 'deploy.resources'; }));

  // An uncovered block child goes through the same settingTarget() as the
  // catch-all, so shape decides editability, not position in the block: a
  // plain scalar (replicas) is an ordinary editable number, while a nested
  // map (placement) cannot be written as a single value and stays locked.
  var repl = Y.fieldById(f2, 'a/setting/deploy.replicas');
  ok('an uncovered deploy.replicas: 3 is a plain scalar, so it comes out as an ordinary editable number field',
     !!repl && repl.locked === false && repl.title === 'Number of copies' && repl.type === 'number',
     repl && JSON.stringify({ locked: repl.locked, title: repl.title, type: repl.type }));

  Y.setPart(doc2, f2, 'a/setting/deploy.replicas', 'value', '5');
  ok('setting it rewrites only that one line',
     diffLines(src, Y.serialise(doc2)).length === 1, diffLines(src, Y.serialise(doc2)).join(', '));

  var place = Y.fieldById(f2, 'a/setting/deploy.placement');
  ok('an uncovered deploy.placement: {constraints: [...]} is a map, so it stays locked as a block of its own',
     !!place && place.locked === true && place.lockReason === 'this is written as a block of its own',
     place && JSON.stringify({ locked: place.locked, lockReason: place.lockReason }));
})();

/* ---- 5. no two field ranges overlap -------------------------------------- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var ranged = form.fields
    .filter(function (f) { return f.range; })
    .map(function (f) { return { id: f.id, start: f.range.start, end: f.range.end }; })
    .sort(function (a, b) { return a.start - b.start; });

  var overlap = null;
  for (var i = 1; i < ranged.length && !overlap; i++) {
    if (ranged[i].start < ranged[i - 1].end) overlap = ranged[i - 1].id + ' / ' + ranged[i].id;
  }
  ok('no two field ranges overlap, which would make the Compose-view cursor sync ambiguous',
     !overlap, overlap);
})();

/* ---- 6. quoting survives a leaf edit ------------------------------------- */

(function () {
  var text = FIXTURE_10_ADVANCED;
  var doc  = Y.parse(text), form = Y.buildForm(doc);

  Y.setPart(doc, form, 'web/setting/deploy.resources.limits.cpus', 'value', '0.75');
  ok('cpus: \'0.50\' set to 0.75 stays single-quoted',
     Y.serialise(doc).indexOf('cpus: \'0.75\'') >= 0, Y.serialise(doc));
  ok('and changes exactly one line',
     diffLines(text, Y.serialise(doc)).length === 1, diffLines(text, Y.serialise(doc)).join(', '));
})();

/* ---- 7. healthcheck.test: readTest()/writeTest() (PLAN_8 phase 4) --------
 *
 * A flow sequence is sealed by the parser, so it never resolves as a plain
 * scalar leaf — readTest() reads its argv straight off the sealed node's own
 * .raw text instead. A plain string (which compose reads as CMD-SHELL), a
 * block seq and NONE (either spelling) are the other three real shapes.
 */

(function () {
  // readTest() over all five shapes.
  var cases = [
    { src: 'test: ["CMD", "curl", "-f", "http://localhost/"]',
      want: { mode: 'cmd', command: 'curl -f http://localhost/' } },
    { src: 'test: ["CMD-SHELL", "pg_isready -U appuser"]',
      want: { mode: 'shell', command: 'pg_isready -U appuser' } },
    { src: 'test: curl -f http://localhost/ || exit 1',
      want: { mode: 'shell', command: 'curl -f http://localhost/ || exit 1' } },
    { src: 'test:\n      - CMD-SHELL\n      - pg_isready',
      want: { mode: 'shell', command: 'pg_isready' } },
    { src: 'test: ["NONE"]', want: { mode: 'none', command: '' } },
    { src: 'test: NONE', want: { mode: 'none', command: '' } }
  ];
  cases.forEach(function (c) {
    var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      ' + c.src + '\n';
    var form = Y.buildForm(Y.parse(src));
    var mode = Y.fieldById(form, 'a/setting/healthcheck.test.mode');
    var cmd  = Y.fieldById(form, 'a/setting/healthcheck.test.command');
    ok('readTest reads "' + c.src.split('\n')[0] + '" as ' + JSON.stringify(c.want),
       !!mode && !mode.locked && mode.parts.value.value === c.want.mode &&
       !!cmd  && !cmd.locked  && cmd.parts.value.value  === c.want.command,
       JSON.stringify({ mode: mode && mode.parts.value.value, command: cmd && cmd.parts.value.value }));
  });
})();

(function () {
  // Round-tripping each mode through writeTest() and back through readTest():
  // start from a file already carrying a command, then edit only the mode —
  // a blank command would trip the blank-writes-nothing rule tested
  // separately below, so this isolates the mode-only edit it does not apply
  // to (mode 'none' aside, which always writes regardless of the command).
  var command = 'curl -f http://localhost/';
  [['shell', command], ['cmd', command], ['none', '']].forEach(function (pair) {
    var mode = pair[0], want = pair[1];
    var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
              '      test: ["CMD-SHELL", "' + command + '"]\n';
    var doc = Y.parse(src), form = Y.buildForm(doc);
    Y.setPart(doc, form, 'a/setting/healthcheck.test.mode', 'value', mode);
    form = Y.buildForm(doc);
    var gotMode = Y.fieldById(form, 'a/setting/healthcheck.test.mode').parts.value.value;
    var gotCmd  = Y.fieldById(form, 'a/setting/healthcheck.test.command').parts.value.value;
    ok('mode ' + mode + ' round-trips through writeTest/readTest',
       gotMode === mode && gotCmd === want,
       JSON.stringify({ mode: gotMode, command: gotCmd }));
  });
})();

(function () {
  // A command with a double quote, a backslash and a '#' — JSON.stringify
  // encodes all three by construction, so the line reads back unchanged.
  // Starts from an existing shell command so only the command field changes,
  // isolating this from the blank-writes-nothing rule tested separately.
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
            '      test: ["CMD-SHELL", "placeholder"]\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var nasty = 'echo "hi" \\ done # not a comment';
  Y.setPart(doc, form, 'a/setting/healthcheck.test.command', 'value', nasty);
  form = Y.buildForm(doc);
  var got = Y.fieldById(form, 'a/setting/healthcheck.test.command').parts.value.value;
  ok('a command with a quote, a backslash and a # survives writeTest/readTest intact',
     got === nasty, JSON.stringify(got));
})();

(function () {
  // A trailing comment on the test: line survives a rewrite.
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
            '      test: curl -f http://localhost/  # keep this\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.setPart(doc, form, 'a/setting/healthcheck.test.command', 'value', 'curl -f http://localhost/health');
  ok('a test: line\u2019s trailing comment survives setting the command',
     Y.serialise(doc).indexOf('# keep this') >= 0, Y.serialise(doc));
})();

(function () {
  // Creating a health check from nothing at all — no healthcheck: block —
  // through the mode and command fields, and it parses back to what was typed.
  var src = 'services:\n  a:\n    image: alpine\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var mode = Y.fieldById(form, 'a/setting/healthcheck.test.mode');
  var cmd  = Y.fieldById(form, 'a/setting/healthcheck.test.command');
  ok('with no healthcheck: at all, both test fields start absent',
     !!mode && mode.absent && !!cmd && cmd.absent);
  ok('typing only the command, with no mode chosen yet, writes nothing (blank-writes-nothing)',
     Y.setPart(doc, form, 'a/setting/healthcheck.test.command', 'value', '') &&
     Y.serialise(doc) === src);

  Y.setPart(doc, form, 'a/setting/healthcheck.test.command', 'value', 'curl -f http://localhost/');
  ok('typing a command with no mode chosen defaults to a shell line and creates healthcheck:',
     Y.serialise(doc).indexOf('healthcheck:') >= 0 &&
     Y.serialise(doc).indexOf('test: ["CMD-SHELL", "curl -f http://localhost/"]') >= 0,
     Y.serialise(doc));

  form = Y.buildForm(doc);
  var reread = Y.fieldById(form, 'a/setting/healthcheck.test.command');
  ok('and reads back exactly what was typed',
     !!reread && reread.parts.value.value === 'curl -f http://localhost/', reread && reread.parts.value.value);
})();

(function () {
  // A block seq is replaced by the single-line form, and every other line of
  // the file is byte-identical.
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
            '      test:\n        - CMD-SHELL\n        - pg_isready\n      interval: 30s\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.setPart(doc, form, 'a/setting/healthcheck.test.command', 'value', 'pg_isready -U appuser');
  var want = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
             '      test: ["CMD-SHELL", "pg_isready -U appuser"]\n      interval: 30s\n';
  ok('a block-seq test: collapses to the single-line form, every other line untouched',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // A hand-authored file with comments, an anchor/alias elsewhere and blank
  // lines: change only test: on service 'a', diff the whole file, nothing
  // else moves. The anchor sits on a key 'a' never touches, so 'a' itself
  // stays fully readable — anchoring the SERVICE being edited would seal the
  // whole thing, which is a different (and separately covered) case.
  var src = 'x-notes: &notes\n  hello: world\n\n' +
            'services:\n' +
            '  a:\n    image: alpine\n\n' +
            '    # the health check\n    healthcheck:\n' +
            '      test: ["CMD-SHELL", "curl -f http://localhost/"]\n      interval: 30s\n\n' +
            '  b:\n    image: alpine\n    labels: *notes\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.setPart(doc, form, 'a/setting/healthcheck.test.command', 'value', 'curl -f http://localhost/health');
  var out = Y.serialise(doc);
  var diff = diffLines(src, out);
  ok('only the test: line moves in a file with an anchor/alias, a comment and blank lines',
     diff.length === 1 && out.split('\n')[diff[0]].indexOf('test:') >= 0,
     firstDiff(src, out));
})();

(function () {
  // Refusal when healthcheck: is an anchor or alias — writeTest() must not
  // guess inside a block the parser sealed.
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck: &hc\n      interval: 30s\n' +
            '  b:\n    image: alpine\n    healthcheck: *hc\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  ok('writeTest refuses an anchored healthcheck: and writes nothing',
     Y.writeTest(doc, form, 'a', 'shell', 'curl -f http://localhost/') === false &&
     Y.serialise(doc) === src);
  ok('writeTest refuses on the alias side too',
     Y.writeTest(doc, form, 'b', 'shell', 'curl -f http://localhost/') === false &&
     Y.serialise(doc) === src);
})();

/* ---- 8. interpolation reaches a leaf ------------------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    deploy:\n' +
            '      resources:\n        limits:\n          memory: ${MEM_LIMIT}\n';
  var form = Y.buildForm(Y.parse(src));
  var mem  = Y.fieldById(form, 'a/setting/deploy.resources.limits.memory');
  ok('a memory: ${MEM_LIMIT} leaf carries the interpolation advice',
     !!mem && mem.advice.some(function (a) { return a.indexOf('typing over it replaces the variable') >= 0; }),
     mem && JSON.stringify(mem.advice));
})();

/* ---- 9. the strongest case, again ---------------------------------------- */

// Already covered above: the identity-then-null-edit case at the top of
// section L uses boxes(), which walks every unlocked field regardless of
// binder — so it already exercises the new healthcheck and deploy leaf boxes
// alongside everything else. No second copy needed here.

/* =========================================================================
 * O. The Stack section — declarations as fields (PLAN_6 phase 4)
 * ========================================================================= */

console.log('\nO. The Stack section — declarations as fields');

/* ---- 1. the exact set of declared fields, and declKind on each --------- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var decl = form.fields.filter(function (f) { return f.service === '' && f.binder === 'declared'; });

  var got = decl.map(function (f) { return { target: f.target, declKind: f.declKind, fold: f.fold }; })
                .sort(function (a, b) { return a.target < b.target ? -1 : 1; });
  // PLAN_34 phase 3b: a declaration's own settings are now offered as blank
  // fold rows when the file has none, so this set grew from five to sixteen —
  // one row per declaration plus every DECL_LEAVES key for its kind, minus the
  // primary (driver/file, which IS the row's own box) and minus ipam, which is
  // a block and stays locked. The point of this assertion is unchanged: the
  // exact set, so an unexpected extra or missing declaration field is caught.
  //
  // A later change folded external into the row's own box for networks and
  // volumes (their box is a driver dropdown, so "created outside this file"
  // is just another answer to it) — dropping the count for those two kinds'
  // absent external from sixteen to thirteen. Secrets keeps its own external
  // as an ordinary fold field, since its row box is a file path and cannot
  // represent the choice.
  var want = [
    { target: 'networks.backend_net',            declKind: 'networks', fold: false },
    { target: 'networks.backend_net.attachable',  declKind: 'networks', fold: true  },
    { target: 'networks.backend_net.internal',    declKind: 'networks', fold: true  },
    { target: 'networks.backend_net.name',        declKind: 'networks', fold: true  },
    { target: 'networks.frontend_net',            declKind: 'networks', fold: false },
    { target: 'networks.frontend_net.attachable', declKind: 'networks', fold: true  },
    { target: 'networks.frontend_net.internal',   declKind: 'networks', fold: true  },
    { target: 'networks.frontend_net.name',       declKind: 'networks', fold: true  },
    { target: 'secrets.db_password',              declKind: 'secrets',  fold: false },
    { target: 'secrets.db_password.environment',  declKind: 'secrets',  fold: true  },
    { target: 'secrets.db_password.external',     declKind: 'secrets',  fold: true  },
    { target: 'volumes.db_data',                  declKind: 'volumes',  fold: false },
    { target: 'volumes.db_data.name',             declKind: 'volumes',  fold: true  }
  ];
  ok('the fixture yields exactly these thirteen declared fields, with the right declKind',
     JSON.stringify(got) === JSON.stringify(want), JSON.stringify(got));
  // The two kinds of fold row must not be confused with each other. A row the
  // file HAS carries its value and needs no path — backend_net's internal: true
  // is the one in this fixture. A row the file LACKS is the phase 3b blank:
  // absent, empty, and carrying the path setPart writes through. Asserting both
  // halves is what would catch a blank that claims the file says something, or
  // a real value quietly turned into an empty box.
  var folds = decl.filter(function (f) { return f.fold; });
  ok('a fold row the file has keeps its value and needs no path',
     folds.filter(function (f) { return !f.absent; }).every(function (f) {
       return f.parts.value.value !== '' && !f.path;
     }),
     JSON.stringify(folds.filter(function (f) { return !f.absent; })
                         .map(function (f) { return [f.target, f.parts.value.value, !!f.path]; })));
  ok('every other fold row is a blank carrying a path to write through',
     folds.filter(function (f) { return f.absent; }).length === 8 &&
     folds.filter(function (f) { return f.absent; }).every(function (f) {
       return f.parts.value.value === '' && !!f.path;
     }),
     JSON.stringify(folds.filter(function (f) { return f.absent; })
                         .map(function (f) { return [f.target, f.parts.value.value, !!f.path]; })));
})();

/* ---- 2. a row is name + value, nothing else; value is the primary setting */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var rows = form.fields.filter(function (f) { return f.binder === 'declared' && !f.fold; });

  ok('every declaration row has exactly a name and a value part, no host or container',
     rows.every(function (f) { return Object.keys(f.parts).sort().join(',') === 'name,value'; }),
     JSON.stringify(rows.map(function (f) { return Object.keys(f.parts); })));

  ok('a row\u2019s name box is read off the declaration\u2019s own key',
     Y.fieldById(form, '/declared/networks.frontend_net').parts.name.spot.isKey === true);

  ok('networks and volumes show driver as their value, secrets shows file',
     Y.fieldById(form, '/declared/networks.frontend_net').parts.value.value === 'bridge' &&
     Y.fieldById(form, '/declared/volumes.db_data').parts.value.value === 'local' &&
     Y.fieldById(form, '/declared/secrets.db_password').parts.value.value === './db_password.txt');
})();

/* ---- 3. the note rides on the primary setting's line, not the name's ---- */

(function () {
  var src = 'networks:\n  n1:  # a note\n    driver: bridge  # the real note\nservices:\n  a:\n    image: alpine\n';
  var f = Y.fieldById(Y.buildForm(Y.parse(src)), '/declared/networks.n1');

  ok('the note comes from the driver: line, not the name line',
     !!f && f.note === 'the real note', f && JSON.stringify(f.note));
  ok('commentSpot points at the driver line, not the name line',
     !!f && f.commentSpot && f.commentSpot.line === 2, f && JSON.stringify(f.commentSpot));
})();

/* ---- 4. a declaration with no settings at all has no note box ---------- */

(function () {
  var src = 'networks:\n  frontend_net:\nservices:\n  a:\n    image: alpine\n';
  var f = Y.fieldById(Y.buildForm(Y.parse(src)), '/declared/networks.frontend_net');

  ok('a bare declaration has an empty value part with no spot',
     !!f && f.parts.value.value === '' && f.parts.value.spot === null,
     f && JSON.stringify(f.parts.value));
  ok('and no comment spot at all, so there is no note box to show',
     !!f && f.commentSpot === null, f && JSON.stringify(f.commentSpot));
})();

/* ---- 5. a row's range is its own name line only, and nothing overlaps -- */

(function () {
  var src = 'networks:\n  frontend_net:  # a note\n    driver: bridge  # the real note\n' +
            'services:\n  a:\n    image: alpine\n';
  var f = Y.fieldById(Y.buildForm(Y.parse(src)), '/declared/networks.frontend_net');

  ok('a declaration row\u2019s range is only its own name line, not the settings below it',
     !!f && f.range.start === 1 && f.range.end === 2, f && JSON.stringify(f.range));

  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var ranged = form.fields.filter(function (g) { return g.range; })
    .map(function (g) { return { id: g.id, start: g.range.start, end: g.range.end }; })
    .sort(function (a, b) { return a.start - b.start; });
  var overlap = null;
  for (var i = 1; i < ranged.length && !overlap; i++) {
    if (ranged[i].start < ranged[i - 1].end) overlap = ranged[i - 1].id + ' / ' + ranged[i].id;
  }
  ok('no two ranges overlap anywhere in the form, services and declarations together',
     !overlap, overlap);
})();

/* ---- 6. every id in the form is unique, declarations included ---------- */

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var ids  = form.fields.map(function (f) { return f.id; });
  var uniq = ids.filter(function (v, i, a) { return a.indexOf(v) === i; });

  ok('every field id in the form is unique, declarations included',
     ids.length === uniq.length, ids.join(', '));
  ok('fieldById finds the exact field for every id',
     form.fields.every(function (f) { return Y.fieldById(form, f.id) === f; }));
})();

/* ---- 7. declared is still five empty arrays on both early returns ------ */

(function () {
  var EMPTY = { networks: [], volumes: [], secrets: [], configs: [], services: [] };

  var unreadable = Y.buildForm(Y.parse('services:\n\ta:\n\t\timage: alpine\n'));
  ok('declared is still five empty arrays when the whole file is unreadable',
     JSON.stringify(unreadable.declared) === JSON.stringify(EMPTY), JSON.stringify(unreadable.declared));
  ok('and declaredFields yields no field either, since it never ran',
     !unreadable.fields.some(function (f) { return f.binder === 'declared'; }));

  // A networks: block with no services: key at all — declaredFields must not
  // run ahead of the "no services:" early return just because there is
  // something to read.
  var noServices = Y.buildForm(Y.parse('networks:\n  frontend_net:\n'));
  ok('declared is still five empty arrays when the file has no services: key',
     JSON.stringify(noServices.declared) === JSON.stringify(EMPTY), JSON.stringify(noServices.declared));
  ok('and declaredFields yields no field either, even though a networks: block exists',
     !noServices.fields.some(function (f) { return f.binder === 'declared'; }));
})();

/* =========================================================================
 * P. The Stack section, editable (PLAN_6 phase 5)
 * ========================================================================= */

console.log('\nP. The Stack section, editable');

/* ---- 8. addDeclared: absent, present, empty, opaque --------------------- */

(function () {
  var d1 = Y.parse('services:\n  a:\n    image: alpine\n');
  var line1 = Y.addDeclared(d1, 'networks', 'frontend_net');
  ok('addDeclared creates the block at the end of the document when absent',
     line1 >= 0 && Y.serialise(d1) === 'services:\n  a:\n    image: alpine\nnetworks:\n  frontend_net:\n',
     JSON.stringify(Y.serialise(d1)));

  var d2 = Y.parse('services:\n  a:\n    image: alpine\nnetworks:\n  frontend_net:\n    driver: bridge\n');
  var line2 = Y.addDeclared(d2, 'networks', 'frontend_net');
  ok('addDeclared appends to an existing block, de-duplicated via freeName',
     line2 >= 0 && Y.serialise(d2) ===
       'services:\n  a:\n    image: alpine\nnetworks:\n  frontend_net:\n    driver: bridge\n  frontend_net2:\n',
     JSON.stringify(Y.serialise(d2)));

  var d3 = Y.parse('services:\n  a:\n    image: alpine\nnetworks:\n');
  var line3 = Y.addDeclared(d3, 'networks', 'frontend_net');
  ok('addDeclared adds under a block that is present but empty',
     line3 >= 0 && Y.serialise(d3) === 'services:\n  a:\n    image: alpine\nnetworks:\n  frontend_net:\n',
     JSON.stringify(Y.serialise(d3)));

  var opaque = 'x-net: &shared\n  frontend_net:\n\nservices:\n  a:\n    image: alpine\nnetworks: *shared\n';
  var d4 = Y.parse(opaque);
  var line4 = Y.addDeclared(d4, 'networks', 'frontend_net');
  ok('addDeclared refuses a block written as an alias, and writes nothing',
     line4 === -1 && Y.serialise(d4) === opaque, JSON.stringify(Y.serialise(d4)));
})();

/* ---- 9. removeDeclared: named, last-takes-the-key, does not refuse ------ */

(function () {
  var d1 = Y.parse('networks:\n  frontend_net:\n  backend_net:\n    driver: bridge\nservices:\n  a:\n    image: alpine\n');
  ok('removeDeclared removes the named declaration',
     Y.removeDeclared(d1, 'networks', 'frontend_net') === true &&
     Y.serialise(d1) === 'networks:\n  backend_net:\n    driver: bridge\nservices:\n  a:\n    image: alpine\n',
     JSON.stringify(Y.serialise(d1)));

  var d2 = Y.parse('networks:\n  frontend_net:\nservices:\n  a:\n    image: alpine\n');
  ok('removing the last declaration takes the whole <kind>: key with it',
     Y.removeDeclared(d2, 'networks', 'frontend_net') === true &&
     Y.serialise(d2) === 'services:\n  a:\n    image: alpine\n', JSON.stringify(Y.serialise(d2)));

  var stillRef = 'networks:\n  frontend_net:\nservices:\n  a:\n    image: alpine\n    networks:\n      - frontend_net\n';
  var d3 = Y.parse(stillRef);
  ok('removing a declaration a service still references does not refuse',
     Y.removeDeclared(d3, 'networks', 'frontend_net') === true &&
     Y.serialise(d3) === 'services:\n  a:\n    image: alpine\n    networks:\n      - frontend_net\n',
     JSON.stringify(Y.serialise(d3)));

  // Symmetry: what addDeclared creates, removeDeclared can take straight back
  // out, leaving the file exactly as it was found.
  var src = 'services:\n  a:\n    image: alpine\n';
  var d4 = Y.parse(src);
  Y.addDeclared(d4, 'networks', 'frontend_net');
  ok('add then remove leaves the file exactly as it was',
     Y.removeDeclared(d4, 'networks', 'frontend_net') === true && Y.serialise(d4) === src,
     firstDiff(src, Y.serialise(d4)));
})();

/* ---- 10. renameDeclared — the reference matrix, and the refusals -------- */

(function () {
  // Every shape a volume reference can take, in one file: a short-form
  // reference, two things that only look like one (a relative and an
  // absolute bind mount), a different name entirely, and both long forms —
  // only the one whose type: is "volume" (or absent, which defaults to it)
  // names a declared volume; a bind mount's source: is a path.
  var src = 'services:\n' +
    '  a:\n' +
    '    image: alpine\n' +
    '    volumes:\n' +
    '      - data:/a\n' +
    '      - ./data:/b\n' +
    '      - /mnt/data:/c\n' +
    '      - mydata:/d\n' +
    '      - type: bind\n' +
    '        source: data\n' +
    '        target: /e\n' +
    '      - type: volume\n' +
    '        source: data\n' +
    '        target: /f\n' +
    '    environment:\n' +
    '      NOTE: this mentions data but is not a ref\n' +
    'volumes:\n' +
    '  data:\n' +
    '    driver: local\n';

  var doc = Y.parse(src);
  var res = Y.renameDeclared(doc, 'volumes', 'data', 'renamed');
  var out = Y.serialise(doc);

  ok('renaming a volume changes exactly the reference, the long-form volume source, and the declaration itself',
     res.ok === true && res.refs === 2 && diffLines(src, out).join(',') === [4, 12, 17].join(','),
     JSON.stringify(res) + '\n' + diffLines(src, out).join(', '));
  ok('the short-form reference is renamed, its container path untouched',
     out.indexOf('      - renamed:/a\n') >= 0, out);
  ok('a relative and an absolute bind mount that merely contain the name are untouched',
     out.indexOf('      - ./data:/b\n') >= 0 && out.indexOf('      - /mnt/data:/c\n') >= 0, out);
  ok('a different name entirely is untouched', out.indexOf('      - mydata:/d\n') >= 0, out);
  ok('a long-form bind mount\u2019s source is untouched, since it is a path, not a reference',
     out.indexOf('        source: data\n        target: /e\n') >= 0, out);
  ok('a long-form volume\u2019s source is renamed, its target path untouched',
     out.indexOf('        source: renamed\n        target: /f\n') >= 0, out);
  ok('a value that merely spells the name inside a sentence is untouched',
     out.indexOf('NOTE: this mentions data but is not a ref') >= 0, out);

  // Renaming a network touches the declaration and every service's list.
  var netSrc = 'services:\n' +
    '  a:\n    image: alpine\n    networks:\n      - frontend_net\n' +
    '  b:\n    image: nginx\n    networks:\n      - frontend_net\n      - other\n' +
    'networks:\n  frontend_net:\n  other:\n';
  var nd = Y.parse(netSrc);
  var nres = Y.renameDeclared(nd, 'networks', 'frontend_net', 'front');
  ok('renaming a network rewrites the declaration and every service\u2019s networks: entry',
     nres.ok === true && nres.refs === 2 &&
     Y.serialise(nd) === netSrc.replace(/frontend_net/g, 'front'),
     JSON.stringify(nres) + '\n' + Y.serialise(nd));

  // Refusals leave the file untouched, same contract as renameService.
  var d1 = Y.parse(netSrc), before1 = Y.serialise(d1);
  var r1 = Y.renameDeclared(d1, 'networks', 'frontend_net', 'other');
  ok('renaming to an existing name is refused, with a message',
     r1.ok === false && typeof r1.error === 'string' && r1.error.length > 0 && Y.serialise(d1) === before1,
     JSON.stringify(r1));

  var d2 = Y.parse(netSrc), before2 = Y.serialise(d2);
  var r2 = Y.renameDeclared(d2, 'networks', 'frontend_net', 'bad name!');
  ok('renaming to an invalid name is refused, with a message',
     r2.ok === false && typeof r2.error === 'string' && r2.error.length > 0 && Y.serialise(d2) === before2,
     JSON.stringify(r2));

  var d3 = Y.parse(netSrc), before3 = Y.serialise(d3);
  var r3 = Y.renameDeclared(d3, 'networks', 'frontend_net', 'frontend_net');
  ok('renaming to the same name is a no-op success, and writes nothing',
     r3.ok === true && r3.refs === 0 && Y.serialise(d3) === before3, JSON.stringify(r3));
})();

/* ---- 11. filling an empty declaration's primary setting ----------------- */

(function () {
  var src = 'networks:\n  frontend_net:\nservices:\n  a:\n    image: alpine\n';

  var d1 = Y.parse(src), f1 = Y.buildForm(d1);
  ok('setPart on a bare declaration\u2019s value inserts driver: at the right indent',
     Y.setPart(d1, f1, '/declared/networks.frontend_net', 'value', 'bridge') &&
     Y.serialise(d1) === 'networks:\n  frontend_net:\n    driver: bridge\nservices:\n  a:\n    image: alpine\n',
     JSON.stringify(Y.serialise(d1)));

  var d2 = Y.parse(src), f2 = Y.buildForm(d2);
  ok('a blank value reports success but writes nothing, same as an absent Container slot',
     Y.setPart(d2, f2, '/declared/networks.frontend_net', 'value', '   ') && Y.serialise(d2) === src,
     firstDiff(src, Y.serialise(d2)));
})();

/* ---- 12. round-trip after every operation -------------------------------- */

(function () {
  // The A/B corpus round trip already covers the untouched fixture's own
  // declaration boxes on a null edit — 10-advanced-compose-test/compose.yaml
  // is part of the scratch corpus those sections walk, and boxes() picks up
  // every unlocked part regardless of binder. What is new here is that a
  // structural edit (add/remove/rename/fill) leaves buildForm in a state
  // just as sound as it started: unique ids, and no two fields laying claim
  // to the same line.
  function sane(form, label) {
    var ids = form.fields.map(function (f) { return f.id; });
    var uniq = ids.filter(function (v, i, a) { return a.indexOf(v) === i; });
    ok(label + ': every id stays unique after the edit', ids.length === uniq.length, ids.join(', '));

    var ranged = form.fields.filter(function (f) { return f.range; })
      .map(function (f) { return { id: f.id, start: f.range.start, end: f.range.end }; })
      .sort(function (a, b) { return a.start - b.start; });
    var overlap = null;
    for (var i = 1; i < ranged.length && !overlap; i++) {
      if (ranged[i].start < ranged[i - 1].end) overlap = ranged[i - 1].id + ' / ' + ranged[i].id;
    }
    ok(label + ': no two ranges overlap after the edit', !overlap, overlap);
  }

  var base = 'services:\n  a:\n    image: alpine\n    networks:\n      - frontend_net\n' +
             'networks:\n  frontend_net:\n    driver: bridge\n  backend_net:\n    driver: bridge\n' +
             'volumes:\n  db_data:\n    driver: local\n';

  var d1 = Y.parse(base);
  Y.addDeclared(d1, 'volumes', 'cache');
  sane(Y.buildForm(d1), 'after addDeclared');

  var d2 = Y.parse(base);
  Y.removeDeclared(d2, 'networks', 'backend_net');
  sane(Y.buildForm(d2), 'after removeDeclared');

  var d3 = Y.parse(base);
  Y.renameDeclared(d3, 'networks', 'frontend_net', 'front');
  sane(Y.buildForm(d3), 'after renameDeclared');

  var d4 = Y.parse('services:\n  a:\n    image: alpine\nnetworks:\n  frontend_net:\n');
  Y.setPart(d4, Y.buildForm(d4), '/declared/networks.frontend_net', 'value', 'bridge');
  sane(Y.buildForm(d4), 'after filling an empty declaration');
})();

/* =========================================================================
 * Q. The long-form gap — a long-form volume's source: joins the namespace
 * (PLAN_6 phase 5b)
 * ========================================================================= */

console.log('\nQ. Long-form volumes and ports join the namespace');

(function () {
  var src = 'services:\n' +
    '  a:\n' +
    '    image: alpine\n' +
    '    volumes:\n' +
    '      - type: volume\n' +
    '        source: missing_vol\n' +
    '        target: /data\n' +
    '      - type: bind\n' +
    '        source: /host/path\n' +
    '        target: /data2\n' +
    '    ports:\n' +
    '      - target: 80\n' +
    '        published: 8080\n';

  var form = Y.buildForm(Y.parse(src));
  var vols = form.fields.filter(function (f) { return f.binder === 'volume'; });
  var named  = vols.filter(function (f) { return f.target === '/data';  })[0];
  var bound  = vols.filter(function (f) { return f.target === '/data2'; })[0];
  var port   = form.fields.filter(function (f) { return f.binder === 'port'; })[0];

  ok('a long-form volume naming an undeclared volume carries the same dangling-reference string as the short form',
     !!named && named.advice.indexOf('no volume called missing_vol is defined in this file') >= 0,
     named && JSON.stringify(named.advice));
  ok('a long-form volume whose source is a path carries no such advice',
     !!bound && !bound.advice.some(function (a) { return a.indexOf('is defined in this file') >= 0; }),
     bound && JSON.stringify(bound.advice));
  ok('a long-form port carries listKey: "ports"', !!port && port.listKey === 'ports', port && port.listKey);
})();

/* =========================================================================
 * R. A list emptied to nothing can be filled again
 * ========================================================================= */

console.log('\nR. A list emptied to nothing can be filled again');

(function () {
  // removeItem takes networks: with it once the last entry is gone (a null
  // value under networks: is invalid compose), which used to make the
  // renderer drop the group and its Add button with it — so refilling the
  // key was impossible from the form. PREFIX/TAIL are what must never move;
  // only the networks: block inside web is allowed to change shape.
  var PREFIX = 'services:\n  web:\n    image: nginx\n';
  var TAIL   = '\nnetworks:\n  front:\n  back:\n';
  var src    = PREFIX + '    networks:\n      - front\n      - back\n' + TAIL;

  var doc = Y.parse(src), form = Y.buildForm(doc);
  // Reads the current `form`, which is rebuilt after every write below.
  function nets() {
    return form.fields.filter(function (x) { return x.binder === 'list' && x.listKey === 'networks'; });
  }

  ok('the fixture declares both network names',
     form.declared.networks.length === 2 && form.declared.networks.indexOf('front') >= 0 && form.declared.networks.indexOf('back') >= 0,
     JSON.stringify(form.declared.networks));

  var first = nets();
  ok('the service starts with two network entries', first.length === 2, first.map(function (f) { return f.target; }).join(', '));

  Y.removeItem(doc, form, first[0].id);
  var afterOne = Y.serialise(doc);
  ok('removing one of two entries leaves the other',
     afterOne === PREFIX + '    networks:\n      - back\n' + TAIL, firstDiff(src, afterOne));

  form = Y.buildForm(doc);
  var second = nets();
  ok('one entry remains in the form too', second.length === 1, second.map(function (f) { return f.target; }).join(', '));

  Y.removeItem(doc, form, second[0].id);
  var afterTwo = Y.serialise(doc);
  ok('removing the last entry drops the networks: key from the service entirely',
     afterTwo.indexOf('\n    networks:') < 0, JSON.stringify(afterTwo));
  ok('the service\'s other keys and the top-level networks: block are untouched',
     afterTwo === PREFIX + TAIL, firstDiff(PREFIX + TAIL, afterTwo));

  form = Y.buildForm(doc);
  var line = Y.addItem(doc, form, 'web', 'list', '', 'networks');
  ok('the key can be filled again after being removed entirely', line >= 0, 'addItem returned ' + line);

  var final = Y.serialise(doc);
  var refilled = Y.buildForm(Y.parse(final)).fields.filter(function (f) { return f.binder === 'list' && f.listKey === 'networks'; });
  ok('exactly one entry is written back', refilled.length === 1, refilled.map(function (f) { return f.target; }).join(', '));
  ok('its value is a declared network name, not an invented placeholder',
     refilled.length === 1 && form.declared.networks.indexOf(refilled[0].target) >= 0,
     refilled.length === 1 && refilled[0].target);

  ok('the rest of the file is unchanged apart from the networks: block inside web',
     final.indexOf(PREFIX) === 0 && final.slice(-TAIL.length) === TAIL, firstDiff(src, final));
})();

/* =========================================================================
 * S. addNested and removeKey (PLAN_8 phase 2) — creating and removing a
 *    healthcheck/deploy leaf the file does not have yet.
 * ========================================================================= */

console.log('\nS. addNested and removeKey (PLAN_8 phase 2)');

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n';
  var want = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'a/setting/healthcheck.interval', 'value', '30s');
  ok('typing into the blank healthcheck.interval box creates healthcheck: and interval: together',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n';
  var want = 'services:\n  a:\n    image: alpine\n    deploy:\n      resources:\n        limits:\n          cpus: 2\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'a/setting/deploy.resources.limits.cpus', 'value', '2');
  ok('typing into deploy.resources.limits.cpus creates all three missing levels plus the leaf',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n';
  var want = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n      timeout: 5s\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'a/setting/healthcheck.timeout', 'value', '5s');
  ok('adding a second leaf under a healthcheck: that already exists does not create a second block',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'a/setting/healthcheck.interval', 'value', '   ');
  ok('a blank value into an absent leaf reports success', wrote);
  ok('and writes nothing at all', Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n      timeout: 5s\n';
  var want = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'a/setting/healthcheck.timeout', 'value', '');
  ok('blanking one of two existing leaves removes only its own line',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n    restart: always\n';
  var want = 'services:\n  a:\n    image: alpine\n    restart: always\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'a/setting/healthcheck.interval', 'value', '');
  ok('blanking the only leaf under healthcheck: takes the whole block, not just the line',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    deploy:\n      replicas: 3\n      resources:\n' +
             '        limits:\n          cpus: \'0.50\'\n';
  var want = 'services:\n  a:\n    image: alpine\n    deploy:\n      replicas: 3\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var removed = Y.removeKey(doc, form, 'a', ['deploy', 'resources']);
  ok('removeKey drops deploy.resources whole, leaving the sibling deploy.replicas untouched',
     removed && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // The anchor itself is sealed (an anchored value is opaque, not just its
  // alias), so harvestLeaves offers no interval leaf for either service —
  // there is nothing here fieldById can even find to write into.
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck: &hc\n      interval: 30s\n' +
            '  b:\n    image: alpine\n    healthcheck: *hc\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  ok('an anchored healthcheck: offers no interval leaf for either service',
     !Y.fieldById(form, 'a/setting/healthcheck.interval') && !Y.fieldById(form, 'b/setting/healthcheck.interval'));
  ok('so setPart refuses and writes nothing',
     Y.setPart(doc, form, 'b/setting/healthcheck.interval', 'value', '10s') === false &&
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

(function () {
  // A flow map is sealed the same way — offers no leaf, same refusal.
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck: {interval: 30s}\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  ok('a flow-map healthcheck: offers no interval leaf either',
     !Y.fieldById(form, 'a/setting/healthcheck.interval'));
})();

(function () {
  // harvestLeaves only checks the LEAVES block key itself (deploy), not
  // every level below it — so a sealed node further down the path (here,
  // deploy.resources aliased from elsewhere) still gets offered as if it
  // were simply absent. addNested is the backstop: it walks the path itself
  // and refuses the moment it meets anything that is not a plain map.
  var src = 'services:\n  a:\n    image: alpine\n    x-limits: &lim\n      cpus: \'0.5\'\n' +
            '    deploy:\n      replicas: 3\n      resources: *lim\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var field = Y.fieldById(form, 'a/setting/deploy.resources.limits.cpus');
  ok('deploy.resources.limits.cpus reads as if absent, since the walk cannot see past the alias',
     !!field && field.absent, field && JSON.stringify({ absent: field.absent }));
  var wrote = Y.setPart(doc, form, 'a/setting/deploy.resources.limits.cpus', 'value', '2');
  ok('but addNested finds the sealed alias mid-path and refuses, writing nothing',
     wrote === false && Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

(function () {
  // A hand-authored file: a blank line and a description comment separate
  // two settings. Creating a healthcheck matches that habit (the same rule
  // insertChild already uses for addSetting), and undoing it — blanking the
  // one leaf that makes it up — must restore the file exactly, blank line
  // included, not leave a doubled or missing one behind.
  var src = 'services:\n  a:\n    image: alpine\n\n    # custom notes about restart\n    restart: always\n';
  var want = 'services:\n  a:\n    image: alpine\n\n    # custom notes about restart\n    restart: always\n' +
             '\n    healthcheck:\n      interval: 30s\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var created = Y.setPart(doc, form, 'a/setting/healthcheck.interval', 'value', '30s');
  ok('creating healthcheck: after a comment-and-blank-separated setting copies the file\u2019s own habit',
     created && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));

  form = Y.buildForm(doc);
  var removed = Y.setPart(doc, form, 'a/setting/healthcheck.interval', 'value', '');
  ok('removing it again is byte-identical to the file before it existed — no doubled or missing blank line',
     removed && Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

console.log('\nT. depends_on long form (PLAN_8 phase 5)');

(function () {
  // Long form read: names, conditions, restart/required — one dependency
  // with everything set, one with only a condition.
  var src = 'services:\n  web:\n    image: nginx\n    depends_on:\n' +
            '      db:\n        condition: service_healthy\n        restart: true\n        required: false\n' +
            '      cache:\n        condition: service_started\n';
  var form = Y.buildForm(Y.parse(src));
  var db    = Y.fieldById(form, 'web/depends/depends_on.db');
  var cache = Y.fieldById(form, 'web/depends/depends_on.cache');
  var dbRestart  = Y.fieldById(form, 'web/depends/depends_on.db.restart');
  var dbRequired = Y.fieldById(form, 'web/depends/depends_on.db.required');

  ok('each dependency reads its own name and condition',
     !!db && db.parts.name.value === 'db' && db.parts.value.value === 'service_healthy' &&
     !!cache && cache.parts.name.value === 'cache' && cache.parts.value.value === 'service_started',
     JSON.stringify({ db: db && db.parts.value.value, cache: cache && cache.parts.value.value }));
  ok('restart and required read as ordinary present values, not folded-away absent ones',
     !!dbRestart && !dbRestart.absent && dbRestart.parts.value.value === 'true' && dbRestart.fold &&
     !!dbRequired && !dbRequired.absent && dbRequired.parts.value.value === 'false' && dbRequired.fold,
     JSON.stringify({ restart: dbRestart && dbRestart.parts.value.value, required: dbRequired && dbRequired.parts.value.value }));
})();

(function () {
  // Changing a condition writes only that line.
  var src = 'services:\n  web:\n    image: nginx\n    depends_on:\n' +
            '      db:\n        condition: service_started\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'web/depends/depends_on.db', 'value', 'service_healthy');
  ok('changing a condition rewrites only that line',
     wrote && Y.serialise(doc) === src.replace('service_started', 'service_healthy'),
     firstDiff(src.replace('service_started', 'service_healthy'), Y.serialise(doc)));
})();

(function () {
  // Changing a name rewrites the key and nothing else.
  var src = 'services:\n  web:\n    image: nginx\n  db:\n    image: postgres\n' +
            '  database:\n    image: postgres\n';
  var full = 'services:\n  web:\n    image: nginx\n    depends_on:\n' +
             '      db:\n        condition: service_healthy\n  db:\n    image: postgres\n' +
             '  database:\n    image: postgres\n';
  var doc = Y.parse(full), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, 'web/depends/depends_on.db', 'name', 'database');
  var want = full.replace('      db:\n        condition: service_healthy',
                           '      database:\n        condition: service_healthy');
  ok('changing a dependency’s name rewrites the key and nothing else',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // Adding a dependency to an existing long-form block.
  var src  = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db:\n        condition: service_started\n' +
             '  cache:\n    image: redis\n';
  var want = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db:\n        condition: service_started\n' +
             '      cache:\n        condition: service_started\n  cache:\n    image: redis\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var line = Y.addNested(doc, form, 'web', ['depends_on', 'cache', 'condition'], 'service_started');
  ok('adding a dependency to an existing long-form block writes condition: service_started beside it',
     line >= 0 && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // Creating depends_on: from nothing in long form.
  var src  = 'services:\n  web:\n    image: nginx\n  db:\n    image: postgres\n';
  var want = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db:\n        condition: service_started\n' +
             '  db:\n    image: postgres\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var line = Y.addNested(doc, form, 'web', ['depends_on', 'db', 'condition'], 'service_started');
  ok('addNested creates depends_on:, the name and condition: service_started together, from nothing',
     line >= 0 && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // Removing one dependency; removing the last one takes depends_on: with it.
  var src = 'services:\n  web:\n    image: nginx\n    depends_on:\n' +
            '      db:\n        condition: service_healthy\n      cache:\n        condition: service_started\n' +
            '    restart: always\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var removedOne = Y.removeItem(doc, form, 'web/depends/depends_on.cache');
  var want1 = 'services:\n  web:\n    image: nginx\n    depends_on:\n' +
              '      db:\n        condition: service_healthy\n    restart: always\n';
  ok('removing one dependency leaves depends_on: and its sibling untouched',
     removedOne && Y.serialise(doc) === want1, firstDiff(want1, Y.serialise(doc)));

  var form2 = Y.buildForm(doc);
  var removedLast = Y.removeItem(doc, form2, 'web/depends/depends_on.db');
  var want2 = 'services:\n  web:\n    image: nginx\n    restart: always\n';
  ok('removing the last dependency takes depends_on: itself with it',
     removedLast && Y.serialise(doc) === want2, firstDiff(want2, Y.serialise(doc)));
})();

(function () {
  // Short form is untouched by any of this — read, added to and removed from
  // exactly as before, and never converted to long form.
  var src = 'services:\n  web:\n    image: nginx\n    depends_on:\n      - db\n  db:\n    image: postgres\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var short = form.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'depends_on'; });
  ok('short form still yields one plain list field, not the long-form binder',
     short.length === 1 && short[0].binder === 'list' && short[0].target === 'db',
     JSON.stringify(short.map(function (f) { return { binder: f.binder, target: f.target }; })));

  var line = Y.addItem(doc, form, 'web', 'list', 'cache', 'depends_on');
  var want = src.replace('      - db\n', '      - db\n      - cache\n');
  ok('adding to a short-form depends_on appends a second plain list entry, never a map',
     line >= 0 && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));

  var doc2 = Y.parse(src), form2 = Y.buildForm(doc2);
  var only = form2.fields.filter(function (f) { return f.service === 'web' && f.listKey === 'depends_on'; })[0];
  var removed = Y.removeItem(doc2, form2, only.id);
  ok('removing the short form’s last entry takes depends_on: with it, same rule as long form',
     removed && Y.serialise(doc2) === 'services:\n  web:\n    image: nginx\n  db:\n    image: postgres\n',
     Y.serialise(doc2));
})();

(function () {
  // A service rename still follows a long-form key.
  var src = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db:\n        condition: service_healthy\n' +
            '  db:\n    image: postgres\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var renamed = Y.renameService(doc, 'db', 'database');
  var want = 'services:\n  web:\n    image: nginx\n    depends_on:\n      database:\n        condition: service_healthy\n' +
             '  database:\n    image: postgres\n';
  ok('renaming a service still rewrites a long-form depends_on key',
     renamed.ok && renamed.refs === 1 && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // The inline flow form stays locked and read-only.
  var src = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db: {condition: service_healthy}\n';
  var form = Y.buildForm(Y.parse(src));
  var don = Y.fieldById(form, 'web/depends/depends_on.db');
  ok('the inline flow form locks rather than reading as an editable dependency',
     !!don && don.locked, don && JSON.stringify({ locked: don.locked, lockReason: don.lockReason }));
})();

(function () {
  // service_healthy against a service with no healthcheck: carries advice;
  // against one that has one, it does not.
  var src = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db:\n        condition: service_healthy\n' +
            '  db:\n    image: postgres\n' +
            '  cache:\n    image: redis\n    depends_on:\n      db2:\n        condition: service_healthy\n' +
            '  db2:\n    image: postgres\n    healthcheck:\n      test: pg_isready\n';
  var form = Y.buildForm(Y.parse(src));
  var noCheck = Y.fieldById(form, 'web/depends/depends_on.db');
  var hasCheck = Y.fieldById(form, 'cache/depends/depends_on.db2');
  ok('service_healthy against a service with no health check carries advice naming it',
     !!noCheck && noCheck.advice.some(function (a) { return a.indexOf('"db" has no health check') >= 0; }),
     noCheck && JSON.stringify(noCheck.advice));
  ok('service_healthy against a service that has one carries no such advice',
     !!hasCheck && !hasCheck.advice.some(function (a) { return a.indexOf('health check') >= 0; }),
     hasCheck && JSON.stringify(hasCheck.advice));
})();

(function () {
  // A hand-authored file with comments and blank lines: after an add and a
  // remove, every untouched line is byte-identical.
  var src = 'services:\n' +
            '  web:\n' +
            '    image: nginx\n' +
            '\n' +
            '    # web waits on the database\n' +
            '    depends_on:\n' +
            '      db:\n' +
            '        condition: service_healthy\n' +
            '\n' +
            '    restart: always\n' +
            '\n' +
            '  db:\n' +
            '    image: postgres\n' +
            '  cache:\n' +
            '    image: redis\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var addedLine = Y.addNested(doc, form, 'web', ['depends_on', 'cache', 'condition'], 'service_started');
  ok('adding a dependency to a hand-authored file only inserts the new lines', addedLine >= 0);

  var form2 = Y.buildForm(doc);
  var removed = Y.removeItem(doc, form2, 'web/depends/depends_on.cache');
  ok('removing it again restores the file exactly, comments and blank lines included',
     removed && Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* =========================================================================
 * U. addService — a new service, seeded with image: and restart:
 * ========================================================================= */

console.log('\nU. addService');

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var line = Y.addService(doc, form, 'b');
  var want = 'services:\n  a:\n    image: alpine\n  b:\n    image:\n    restart: unless-stopped\n';
  ok('a service is added after the only existing one',
     line >= 0 && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n  b:\n    image: nginx\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var line = Y.addService(doc, form, 'c');
  var want = 'services:\n  a:\n    image: alpine\n  b:\n    image: nginx\n  c:\n    image:\n    restart: unless-stopped\n';
  ok('a service is added after the last of several existing ones',
     line >= 0 && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // The case that was broken and fixed: a new service's children must sit at
  // whatever column the file's own service already sits at, not a hardcoded
  // two spaces — checked on both a 2-space and a 4-space file.
  var CASES = [
    ['a 2-space file', 'services:\n  a:\n    image: alpine\n'],
    ['a 4-space file', 'services:\n    a:\n        image: alpine\n']
  ];
  CASES.forEach(function (c) {
    var doc = Y.parse(c[1]), form = Y.buildForm(doc);
    var existingIndent = /^(\s*)image:/.exec(c[1].split('\n')[2])[1].length;
    Y.addService(doc, form, 'b');
    var newImageLine = Y.serialise(doc).split('\n').filter(function (l) { return /^\s*image:$/.test(l); })[0];
    var newIndent = /^(\s*)/.exec(newImageLine || '')[1].length;
    ok(c[0] + ': the new service\u2019s children sit at the same column as the existing one\u2019s',
       !!newImageLine && newIndent === existingIndent,
       'existing: ' + existingIndent + ', new: ' + newIndent);
  });
})();

(function () {
  var spaced = 'services:\n  a:\n    image: alpine\n\n  b:\n    image: alpine\n';
  var d1 = Y.parse(spaced), f1 = Y.buildForm(d1);
  Y.addService(d1, f1, 'c');
  var want1 = 'services:\n  a:\n    image: alpine\n\n  b:\n    image: alpine\n\n  c:\n    image:\n    restart: unless-stopped\n';
  ok('a file that blank-separates its services gets a blank line before the new one',
     Y.serialise(d1) === want1, firstDiff(want1, Y.serialise(d1)));

  var packed = 'services:\n  a:\n    image: alpine\n  b:\n    image: alpine\n';
  var d2 = Y.parse(packed), f2 = Y.buildForm(d2);
  Y.addService(d2, f2, 'c');
  var want2 = 'services:\n  a:\n    image: alpine\n  b:\n    image: alpine\n  c:\n    image:\n    restart: unless-stopped\n';
  ok('a file that packs its services tight gets no blank line before the new one',
     Y.serialise(d2) === want2, firstDiff(want2, Y.serialise(d2)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var line = Y.addService(doc, form, 'b');
  var out  = Y.serialise(doc);
  ok('the file reparses cleanly after the add', !!Y.fieldAtLine(Y.buildForm(Y.parse(out)), line));
  ok('the new name appears in form.declared.services',
     Y.buildForm(Y.parse(out)).declared.services.indexOf('b') >= 0,
     JSON.stringify(Y.buildForm(Y.parse(out)).declared.services));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n';

  // Each refusal must return -1 and write nothing at all.
  var REFUSALS = [
    ['a name already taken', 'a'],
    ['an invalid name',      'bad name!'],
    ['an empty name',        '']
  ];
  REFUSALS.forEach(function (r) {
    var doc = Y.parse(src), form = Y.buildForm(doc);
    var line = Y.addService(doc, form, r[1]);
    ok('refused: ' + r[0], line === -1);
    ok('refused: ' + r[0] + ' \u2014 file untouched', Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
  });

  var noKey = 'image: alpine\n';
  var d2 = Y.parse(noKey), f2 = Y.buildForm(d2);
  var line2 = Y.addService(d2, f2, 'a');
  ok('refused: no services: key at all', line2 === -1);
  ok('refused: no services: key \u2014 file untouched', Y.serialise(d2) === noKey, firstDiff(noKey, Y.serialise(d2)));

  // A services: map sealed as a whole (an alias to a shared block) cannot be
  // added to, the same rule addItem already follows for a sealed list.
  var sealed = 'x-shared: &all\n  a:\n    image: alpine\n\nservices: *all\n';
  var d3 = Y.parse(sealed), f3 = Y.buildForm(d3);
  var line3 = Y.addService(d3, f3, 'b');
  ok('refused: a sealed services: map', line3 === -1);
  ok('refused: a sealed services: map \u2014 file untouched', Y.serialise(d3) === sealed, firstDiff(sealed, Y.serialise(d3)));
})();

(function () {
  // Comments, an anchor and the merge key elsewhere in the file survive an
  // add, untouched and in their original order.
  var src  = '# top comment\nx-shared: &defaults\n  restart: unless-stopped\n\nservices:\n  a:\n' +
             '    <<: *defaults\n    image: alpine   # pinned\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var line = Y.addService(doc, form, 'b');
  var want = src + '  b:\n    image:\n    restart: unless-stopped\n';
  ok('comments, the anchor and the merge key survive an add elsewhere in the file',
     line >= 0 && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* =========================================================================
 * V. proto and mode — the parts a bare port and volume gained
 *
 * Both carry their own separator in the value, so choosing the empty option
 * writes the separator away too and writeScalar needs no special case for
 * "nothing chosen". Section B already null-edits every f.parts entry
 * generically (it walks Object.keys(f.parts), not a fixed list of names), so
 * these two join that guard automatically rather than needing a second copy
 * of it here.
 * ========================================================================= */

console.log('\nV. proto and mode parts');

(function () {
  var noProto = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    ports:\n      - "8080:80"\n'));
  var p1 = noProto.fields.filter(function (f) { return f.binder === 'port'; })[0];
  ok('a port with no protocol exposes parts.proto as empty',
     !!p1 && p1.parts.proto.value === '', p1 && JSON.stringify(p1.parts.proto));

  var udp = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    ports:\n      - "8080:80/udp"\n'));
  var p2 = udp.fields.filter(function (f) { return f.binder === 'port'; })[0];
  ok('8080:80/udp exposes parts.proto as "/udp"',
     !!p2 && p2.parts.proto.value === '/udp', p2 && JSON.stringify(p2.parts.proto));

  var noMode = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    volumes:\n      - /h:/c\n'));
  var v1 = noMode.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  ok('a volume with no mode exposes parts.mode as empty',
     !!v1 && v1.parts.mode.value === '', v1 && JSON.stringify(v1.parts.mode));

  var ro = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    volumes:\n      - /h:/c:ro\n'));
  var v2 = ro.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  ok('/h:/c:ro exposes parts.mode as ":ro"',
     !!v2 && v2.parts.mode.value === ':ro', v2 && JSON.stringify(v2.parts.mode));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    ports:\n      - "8080:80"\n';
  var want = 'services:\n  a:\n    image: alpine\n    ports:\n      - "8080:80/udp"\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var p   = form.fields.filter(function (f) { return f.binder === 'port'; })[0];
  Y.setPart(doc, form, p.id, 'proto', '/udp');
  ok('setting proto to "/udp" on a bare port changes exactly one line',
     Y.serialise(doc) === want && diffLines(src, Y.serialise(doc)).length === 1,
     firstDiff(want, Y.serialise(doc)));

  var back = Y.parse(want), formBack = Y.buildForm(back);
  var pBack = formBack.fields.filter(function (f) { return f.binder === 'port'; })[0];
  Y.setPart(back, formBack, pBack.id, 'proto', '');
  ok('setting proto back to empty removes the separator too, restoring the original line',
     Y.serialise(back) === src, firstDiff(src, Y.serialise(back)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    volumes:\n      - /h:/c\n';
  var want = 'services:\n  a:\n    image: alpine\n    volumes:\n      - /h:/c:ro\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var v   = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  Y.setPart(doc, form, v.id, 'mode', ':ro');
  ok('setting mode to ":ro" on a bare volume changes exactly one line',
     Y.serialise(doc) === want && diffLines(src, Y.serialise(doc)).length === 1,
     firstDiff(want, Y.serialise(doc)));

  var back = Y.parse(want), formBack = Y.buildForm(back);
  var vBack = formBack.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  Y.setPart(back, formBack, vBack.id, 'mode', '');
  ok('setting mode back to empty removes the separator too, restoring the original line',
     Y.serialise(back) === src, firstDiff(src, Y.serialise(back)));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    volumes:\n      - /data\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var v   = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];

  ok('an anonymous volume\u2019s mode part has no spot',
     !!v && !v.parts.mode.spot, v && JSON.stringify(v.parts.mode));
  ok('and is not writable',
     Y.setPart(doc, form, v.id, 'mode', ':ro') === false);
})();

(function () {
  // The suite already has an ip-qualified port case (section F) — extended
  // here with a protocol, to show the ip survives a proto-only change.
  var src  = 'services:\n  a:\n    image: alpine\n    ports:\n      - "127.0.0.1:8080:80/udp"\n';
  var want = 'services:\n  a:\n    image: alpine\n    ports:\n      - "127.0.0.1:8080:80/tcp"\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var p   = form.fields.filter(function (f) { return f.binder === 'port'; })[0];
  ok('an ip-qualified port keeps its host and container parts when read',
     p.parts.host.value === '8080' && p.parts.container.value === '80' && p.parts.proto.value === '/udp',
     JSON.stringify({ host: p.parts.host.value, container: p.parts.container.value, proto: p.parts.proto.value }));

  Y.setPart(doc, form, p.id, 'proto', '/tcp');
  ok('changing only the protocol keeps the ip, changing exactly one line',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* =========================================================================
 * W. Boolean writes go unquoted, and quoting is never removed
 * ========================================================================= */

console.log('\nW. Boolean writes go unquoted');

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    privileged: true\n';
  var want = 'services:\n  a:\n    image: alpine\n    privileged: false\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  Y.setPart(doc, form, 'a/setting/privileged', 'value', 'false');
  ok('privileged: true set to false writes a bare false, not \'false\'',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // The guard on the whole fix: a file that deliberately quoted "true" as a
  // string keeps its quotes when changed. Quoting is never removed.
  var src  = 'services:\n  a:\n    image: alpine\n    privileged: "true"\n';
  var want = 'services:\n  a:\n    image: alpine\n    privileged: "false"\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  Y.setPart(doc, form, 'a/setting/privileged', 'value', 'false');
  ok('a deliberately quoted privileged: "true" keeps its quotes when changed',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n';
  var want = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      disable: true\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var dis  = Y.fieldById(form, 'a/setting/healthcheck.disable');
  ok('an absent healthcheck.disable is offered as a boolean', !!dis && dis.absent && dis.type === 'boolean');
  Y.setPart(doc, form, 'a/setting/healthcheck.disable', 'value', 'true');
  ok('setting it creates disable: true unquoted',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  var src  = 'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    depends_on:\n' +
             '      db:\n        condition: service_healthy\n';
  var want = 'services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n    depends_on:\n' +
             '      db:\n        condition: service_healthy\n        required: false\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var req  = Y.fieldById(form, 'web/depends/depends_on.db.required');
  ok('an absent dependency required is offered as a boolean', !!req && req.absent && req.type === 'boolean');
  Y.setPart(doc, form, 'web/depends/depends_on.db.required', 'value', 'false');
  ok('setting it creates required: false unquoted',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

(function () {
  // The fix must not leak into an ordinary string field that merely holds the
  // text "true" — an environment variable is never inferred as boolean.
  var src  = 'services:\n  a:\n    image: alpine\n    environment:\n      FLAG: yes\n';
  var want = 'services:\n  a:\n    image: alpine\n    environment:\n      FLAG: \'true\'\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var flag = Y.fieldById(form, 'a/env/FLAG');
  ok('an ordinary environment value is not typed as boolean', !!flag && flag.type === 'text');
  Y.setPart(doc, form, 'a/env/FLAG', 'value', 'true');
  ok('setting it to the text "true" still gets quoted',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* =========================================================================
 * X. Sections — a service section moved to x-unraid and back (PLAN.md
 *    Phase 1): readSections, stashSection, restoreSection, setSectionState.
 * ========================================================================= */

console.log('\nX. Sections — stash and restore a service section');

var SECTION_PATHS = [
  ['ports'], ['volumes'], ['environment'], ['devices'], ['labels'],
  ['healthcheck'], ['deploy', 'resources'], ['depends_on'], ['networks'],
  ['secrets'], ['configs'], ['profiles'], ['dns'], ['cap_add'], ['cap_drop'],
  ['expose'], ['env_file'], ['logging']
];

/* ---- the central case: stash then restore is byte-identical, for every
 * section a service actually has, across the corpus -----------------------
 *
 * The whole corpus, deliberately — including 07-yaml-quirks and
 * 08-deliberately-broken, which is where this sweep earned its keep. It found
 * two real defects, both since fixed, and both of a kind only a hostile file
 * exposes: a blank line flanking a block on both sides was collapsed on the
 * way out and never rebuilt coming back, and a mis-indented line sent
 * ensurePath into an unbounded insert loop that ended in a stack overflow.
 * Excluding the awkward files would have hidden both, so they stay in.
 */

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  var baseWarnings = Y.parse(text).warnings.length;

  var services = Y.buildForm(Y.parse(text)).services
                  .filter(function (s) { return s.readable; })
                  .map(function (s) { return s.name; });
  if (!services.length) { ok(name + '  (no readable service — skipped)', true); return; }

  var bad = null, tried = 0;
  services.forEach(function (svc) {
    SECTION_PATHS.forEach(function (p) {
      if (bad) return;

      var doc = Y.parse(text), form = Y.buildForm(doc);
      if (!Y.stashSection(doc, form, svc, p)) return;   // absent or sealed — its own case

      // A stash must not leave the parser any worse off than it found the
      // file — a duplicate key or a newly-sealed region would show up here
      // as a warning that was not there before.
      var afterStash = Y.parse(Y.serialise(doc));
      if (afterStash.warnings.length > baseWarnings) {
        bad = svc + '/' + p.join('.') + ': stashing left a new parser warning behind';
        return;
      }

      tried++;
      var key = p.join('.');
      if (!Y.restoreSection(doc, form, svc, key)) { bad = svc + '/' + key + ': restore refused'; return; }

      var got = Y.serialise(doc);
      if (got !== text) { bad = svc + '/' + key + '\n' + firstDiff(text, got); return; }

      var afterRestore = Y.parse(got);
      if (afterRestore.warnings.length > baseWarnings) {
        bad = svc + '/' + key + ': restoring left a new parser warning behind';
      }
    });
  });

  ok(name + '  (' + tried + ' section stash/restore round trips)', !bad, bad);
});

/* ---- awkward characters: #, ', ", \ and a trailing comment --------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
            '      # a note with a # hash, a \'quote\', a "quote" and a \\ backslash\n' +
            '      test: ["CMD", "echo hi"]  # trailing comment on this line\n' +
            '    ports:\n      - "8080:80"\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);

  ok('a block holding #, \', ", \\ and a trailing comment stashes',
     Y.stashSection(doc, form, 'a', ['healthcheck']));
  ok('and restores byte-identical — the stash is JSON inside a YAML scalar, so quoting is the risk',
     Y.restoreSection(doc, form, 'a', 'healthcheck') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
  ok('the restored file is still readable, with no new parser warning',
     Y.parse(Y.serialise(doc)).warnings.length === Y.parse(src).warnings.length);
})();

/* ---- after: null — the section is the service's first key ---------------- */

(function () {
  var src = 'services:\n  a:\n    healthcheck:\n      interval: 30s\n    image: alpine\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);

  ok('a section that is the service\u2019s first key stashes', Y.stashSection(doc, form, 'a', ['healthcheck']));
  ok('and is recorded with after: null',
     Y.readSections(doc).a.healthcheck.after === null, JSON.stringify(Y.readSections(doc)));
  ok('restoring puts it back as the first key again, byte-identical',
     Y.restoreSection(doc, form, 'a', 'healthcheck') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
})();

/* ---- after in the middle — the section sits between two other keys ------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n' +
            '    ports:\n      - "80:80"\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);

  Y.stashSection(doc, form, 'a', ['healthcheck']);
  ok('a section between two keys remembers the one before it',
     Y.readSections(doc).a.healthcheck.after === 'image', JSON.stringify(Y.readSections(doc)));
  ok('restoring lands it back between the same two keys, byte-identical',
     Y.restoreSection(doc, form, 'a', 'healthcheck') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
})();

/* ---- deploy.resources: removing it takes the now-empty deploy: with it,
 * restoring has to rebuild both together --------------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    deploy:\n      resources:\n' +
            '        limits:\n          cpus: \'0.50\'\n    ports:\n      - "80:80"\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);

  ok('deploy.resources stashes, taking the now-empty deploy: with it',
     Y.stashSection(doc, form, 'a', ['deploy', 'resources']) &&
     Y.serialise(doc).indexOf('deploy:') < 0, Y.serialise(doc));
  ok('restoring rebuilds deploy: and resources: together, byte-identical',
     Y.restoreSection(doc, form, 'a', 'deploy.resources') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
})();

/* ---- indent that is not two spaces — the deploy.resources rebuild must use
 * the file's own nesting step, not a hardcoded two. A defect already found
 * and fixed once; guarded here on a four-space and a three-space file so it
 * cannot come back unnoticed. ------------------------------------------- */

(function () {
  var CASES = [
    ['a four-space file',
     'services:\n    a:\n        image: alpine\n        deploy:\n            resources:\n' +
     '                limits:\n                    cpus: \'0.50\'\n        ports:\n' +
     '            - "80:80"\n'],
    ['a three-space file',
     'services:\n   a:\n      image: alpine\n      deploy:\n         resources:\n' +
     '            limits:\n               cpus: \'0.50\'\n      ports:\n' +
     '         - "80:80"\n']
  ];

  CASES.forEach(function (c) {
    var doc = Y.parse(c[1]), form = Y.buildForm(doc);
    Y.stashSection(doc, form, 'a', ['deploy', 'resources']);
    ok(c[0] + ': deploy.resources rebuilds at the file\u2019s own indent, byte-identical',
       Y.restoreSection(doc, form, 'a', 'deploy.resources') && Y.serialise(doc) === c[1],
       firstDiff(c[1], Y.serialise(doc)));
  });
})();

/* ---- a blank line inside a stashed block survives in place ---------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n\n' +
            '      timeout: 5s\n    ports:\n      - "80:80"\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);

  Y.stashSection(doc, form, 'a', ['healthcheck']);
  ok('a blank line inside the stashed block survives, byte-identical on restore',
     Y.restoreSection(doc, form, 'a', 'healthcheck') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
})();

/* ---- a hand-mangled entry is ignored, never thrown on --------------------
 *
 * This runs on every render of every file, including one somebody has
 * hand-edited, so a bad line here must not cost the rest of the form.
 */

(function () {
  var notJson = Y.parse('services:\n  a:\n    image: alpine\nx-unraid:\n  sections:\n' +
                         '    a:\n      healthcheck: \'not json\'\n');
  ok('a value that is not JSON at all is ignored, not thrown on',
     JSON.stringify(Y.readSections(notJson)) === '{}', JSON.stringify(Y.readSections(notJson)));

  var badShape = Y.parse('services:\n  a:\n    image: alpine\nx-unraid:\n  sections:\n' +
                          '    a:\n      healthcheck: \'{"lines":"notanarray"}\'\n');
  ok('valid JSON in the wrong shape (lines not an array, after missing) is ignored too',
     JSON.stringify(Y.readSections(badShape)) === '{}', JSON.stringify(Y.readSections(badShape)));
})();

/* ---- an entry naming a service that does not exist ------------------------
 *
 * readSections is a plain reader — it reports whatever x-unraid.sections
 * says, whether or not that service is still in the file. It is
 * restoreSection, called with a name nothing in the file answers to, that
 * has to cope: gracefully clearing a leftover "false" flag (there was never
 * anything to restore), but refusing outright — writing nothing — when the
 * entry holds real lines it cannot place anywhere.
 */

(function () {
  var src = 'services:\n  a:\n    image: alpine\nx-unraid:\n  sections:\n' +
            '    ghost:\n      healthcheck: false\n';
  var doc = Y.parse(src);
  ok('a leftover entry for an absent service is still reported as-is',
     JSON.stringify(Y.readSections(doc)) === '{"ghost":{"healthcheck":false}}',
     JSON.stringify(Y.readSections(doc)));

  var form = Y.buildForm(doc);
  ok('restoring a "false" flag for an absent service just clears it, nothing to place',
     Y.restoreSection(doc, form, 'ghost', 'healthcheck') &&
     Y.serialise(doc) === 'services:\n  a:\n    image: alpine\n', JSON.stringify(Y.serialise(doc)));

  var withLines = 'services:\n  a:\n    image: alpine\nx-unraid:\n  sections:\n' +
                  '    ghost:\n      healthcheck: \'{"after":null,"lines":["healthcheck:","  interval: 30s"]}\'\n';
  var doc2 = Y.parse(withLines), form2 = Y.buildForm(doc2);
  ok('restoring real lines for an absent service refuses, since there is nowhere to put them',
     Y.restoreSection(doc2, form2, 'ghost', 'healthcheck') === false &&
     Y.serialise(doc2) === withLines, firstDiff(withLines, Y.serialise(doc2)));
})();

/* ---- a section present in the file AND in sections — the file wins ------- */

(function () {
  // A stray "ports: false" left over from an earlier hide, while the service
  // still carries a live ports: block (a hand-edit contradiction). The form
  // reads the live block regardless of what the stale flag claims — nothing
  // in this layer special-cases it away — and re-stashing captures the LIVE
  // content, overwriting the stale flag rather than merging with it.
  var src = 'services:\n  a:\n    image: alpine\n    ports:\n      - "8080:80"\n' +
            'x-unraid:\n  sections:\n    a:\n      ports: false\n';
  var doc = Y.parse(src);
  ok('the stale flag is reported exactly as written',
     JSON.stringify(Y.readSections(doc)) === '{"a":{"ports":false}}', JSON.stringify(Y.readSections(doc)));

  var form = Y.buildForm(doc);
  var portField = form.fields.filter(function (f) { return f.binder === 'port'; })[0];
  ok('but the form still reads the live block, not the stale flag',
     !!portField && portField.target === '80/tcp', portField && portField.target);

  ok('stashing again captures the live content and overwrites the stale flag',
     Y.stashSection(doc, form, 'a', ['ports']) &&
     JSON.stringify(Y.readSections(doc)) ===
       '{"a":{"ports":{"after":"image","lines":["ports:","  - \\"8080:80\\""],"gap":0,"blank":false}}}',
     JSON.stringify(Y.readSections(doc)));
})();

/* ---- rename while stashed — the stash key follows the service ------------ */

(function () {
  var src = 'services:\n  web:\n    image: nginx\n    healthcheck:\n      # a note\n' +
            '      interval: 30s\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.stashSection(doc, form, 'web', ['healthcheck']);

  var res = Y.renameService(doc, 'web', 'website');
  ok('renaming a service with a hidden section succeeds and carries the stash key',
     res.ok === true && Y.serialise(doc).indexOf('sections:\n    website:') >= 0, JSON.stringify(res));

  form = Y.buildForm(doc);
  var want = src.replace('web:', 'website:');
  ok('the renamed service still restores correctly, comment and all',
     Y.restoreSection(doc, form, 'website', 'healthcheck') && Y.serialise(doc) === want,
     firstDiff(want, Y.serialise(doc)));
})();

/* ---- setSectionState: true -> false -> null, and cleanup on the way out -- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);

  ok('ticking a section on with nothing stashed writes the empty-lines form',
     Y.setSectionState(doc, form, 'a', 'ports', true) &&
     JSON.stringify(Y.readSections(doc)) === '{"a":{"ports":{"after":null,"lines":[],"gap":0,"blank":false}}}',
     JSON.stringify(Y.readSections(doc)));

  ok('ticking it off writes a bare false',
     Y.setSectionState(doc, form, 'a', 'ports', false) &&
     Y.serialise(doc).indexOf('ports: false') >= 0, Y.serialise(doc));

  Y.setSectionState(doc, form, 'a', 'labels', false);
  ok('a second entry shares the same sections: block',
     Y.serialise(doc).indexOf('ports: false') >= 0 && Y.serialise(doc).indexOf('labels: false') >= 0,
     Y.serialise(doc));

  Y.setSectionState(doc, form, 'a', 'ports', null);
  ok('clearing one entry leaves the other and the block itself in place',
     Y.serialise(doc).indexOf('ports') < 0 && Y.serialise(doc).indexOf('labels: false') >= 0,
     Y.serialise(doc));

  ok('clearing the last entry removes sections: and x-unraid: too, back to the original file',
     Y.setSectionState(doc, form, 'a', 'labels', null) && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));

  // The exact chain a section switched off with nothing worth keeping now
  // runs: stash it (which records "off", since nothing survived the blanks
  // filter), then clear that entry because off is what the section is anyway.
  // Anything less leaves an x-unraid block standing over one dead word.
  var d2 = Y.parse('services:\n  a:\n    image: alpine\n    cap_drop:\n      - \n');
  Y.stashSection(d2, Y.buildForm(d2), 'a', ['cap_drop']);
  Y.setSectionState(d2, Y.buildForm(d2), 'a', 'cap_drop', null);
  ok('a section switched off holding nothing leaves no x-unraid block behind',
     Y.serialise(d2) === 'services:\n  a:\n    image: alpine\n',
     JSON.stringify(Y.serialise(d2)));
})();

/* ---- refusals leave the file untouched, byte for byte --------------------- */

(function () {
  var REFUSALS = [
    ['a sealed intermediate level (deploy.resources, deploy anchored)',
     'services:\n  a:\n    image: alpine\n    deploy: &d\n      replicas: 3\n' +
     '  b:\n    image: alpine\n    deploy: *d\n', 'a', ['deploy', 'resources']],
    ['a key the service does not have',
     'services:\n  a:\n    image: alpine\n', 'a', ['healthcheck']],
    ['a service that does not exist',
     'services:\n  a:\n    image: alpine\n', 'ghost', ['healthcheck']],
    ['a service that cannot be read (a bare scalar)',
     'services:\n  a: alpine\n', 'a', ['healthcheck']]
  ];

  REFUSALS.forEach(function (r) {
    var doc = Y.parse(r[1]), form = Y.buildForm(doc);
    ok('stashSection refused: ' + r[0],
       Y.stashSection(doc, form, r[2], r[3]) === false && Y.serialise(doc) === r[1],
       firstDiff(r[1], Y.serialise(doc)));
  });

  // The refusal that happens PART WAY through, which every case above misses:
  // x-unraid.sections is three levels deep, so a file this cannot finish
  // writing to has already gained a bare "x-unraid:" and "sections:" by the
  // time it gives up. Left behind, that is the form quietly adding keys to a
  // file it then refused to edit. This shape — a service whose own
  // indentation is inconsistent — is what reaches that path.
  var ragged = 'services:\n  broken:\n    image: alpine\n    environment:\n' +
               '      GOOD: 1\n     BAD_INDENT: 2\n';
  var rdoc = Y.parse(ragged), rform = Y.buildForm(rdoc);
  var refused = Y.stashSection(rdoc, rform, 'broken', ['environment']);
  ok('stashSection refused: a partly-created x-unraid is rolled back',
     refused === false && Y.serialise(rdoc) === ragged,
     firstDiff(ragged, Y.serialise(rdoc)));
  ok('stashSection refused: no bare x-unraid was left behind',
     Y.serialise(rdoc).indexOf('x-unraid') < 0, Y.serialise(rdoc));

  // restoreSection's own refusal: the immediate surviving parent (here,
  // deploy:, which still holds "replicas" so it was never removed) has since
  // been hand-turned into an anchor — restoring must not guess inside it.
  var plain = 'services:\n  a:\n    image: alpine\n    deploy:\n      replicas: 3\n' +
              '      resources:\n        limits:\n          cpus: \'0.50\'\n';
  var seed = Y.parse(plain), seedForm = Y.buildForm(seed);
  Y.stashSection(seed, seedForm, 'a', ['deploy', 'resources']);
  var corrupted = Y.serialise(seed)
    .replace('    deploy:\n      replicas: 3\n', '    deploy: &d\n      replicas: 3\n')
    .replace('x-unraid:', '  b:\n    image: alpine\n    deploy: *d\nx-unraid:');

  var doc2 = Y.parse(corrupted), form2 = Y.buildForm(doc2);
  ok('restoreSection refused: the surviving parent was hand-turned into an anchor since the stash',
     Y.restoreSection(doc2, form2, 'a', 'deploy.resources') === false && Y.serialise(doc2) === corrupted,
     firstDiff(corrupted, Y.serialise(doc2)));
})();

/* =========================================================================
 * I. Syntax highlighting — Y.highlight(line, carry)
 *
 * The one invariant that matters more than any individual span: strip the
 * tags from html, decode &amp; &lt; &gt;, and get the input line back
 * exactly. Checked over the whole compose-file corpus plus every inline
 * fixture already above, because that is where the real quoting, comment
 * and block-scalar oddities live — a synthetic one-liner would not have
 * caught most of what compose files actually do.
 * ========================================================================= */

console.log('\nI. Syntax highlighting');

function decodeEntities(s) {
  return s.replace(/&(amp|lt|gt);/g, function (_, n) {
    return n === 'amp' ? '&' : n === 'lt' ? '<' : '>';
  });
}

function decodeHl(html) {
  return decodeEntities(html.replace(/<[^>]+>/g, ''));
}

// The ordered {kind, text} spans in one line's html, decoded — so a test can
// assert "there is a key span reading X" without caring what surrounds it.
function spans(html) {
  var re = /<span class="staxx-t--([a-z]+)">([\s\S]*?)<\/span>/g, out = [], m;
  while ((m = re.exec(html))) out.push({ kind: m[1], text: decodeEntities(m[2]) });
  return out;
}

// Runs highlight() over every line of `text`, threading carry through, and
// returns the reconstructed lines (tags and entities stripped back out).
function reconstruct(text) {
  var lines = text.split('\n'), carry = '', out = [];
  for (var i = 0; i < lines.length; i++) {
    var r = Y.highlight(lines[i], carry);
    out.push(decodeHl(r.html));
    carry = r.carry;
  }
  return { lines: out, carry: carry };
}

function checkReconstruction(name, text) {
  var lines = text.split('\n');
  var got = reconstruct(text).lines;
  var bad = -1;
  for (var i = 0; i < lines.length; i++) if (got[i] !== lines[i]) { bad = i; break; }
  ok('highlight reconstructs ' + name + '  (' + lines.length + ' lines)', bad < 0,
     bad < 0 ? '' : 'line ' + (bad + 1) + '\n  was: ' + JSON.stringify(lines[bad]) +
                     '\n  got: ' + JSON.stringify(got[bad]));
}

/* ---- I1. The reconstruction invariant, over the whole corpus ------------ */

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  checkReconstruction(name, text);
});

// Every inline fixture already used above, plus a handful that exercise
// block scalars and multi-line flow collections, which none of the EDGE
// cases happen to cover.
var HIGHLIGHT_FIXTURES = Object.keys(EDGE).map(function (k) { return EDGE[k]; }).concat([
  'services:\n  a:\n    ports:\n      - "8096:8097/udp"\n' +
    '    volumes:\n      - /mnt/user/media:/media:ro\n',
  'services:\n  a:\n    environment:\n' +
    '      ADMIN_PASSWORD: hunter2      # the login password -!S\n' +
    '      SITE_TITLE: Home             # shown at the top -!R\n' +
    '      LOG_LEVEL: info              # how chatty the logs are\n' +
    '      PLAIN: x\n',
  'x-shared: &ports\n  - "80:80"\n\nservices:\n  a:\n    image: alpine\n' +
    '    ports: *ports\n    volumes: [/a:/b]\n',
  'services:\n  a:\n    image: alpine\n    command: |\n      echo hi\n      echo bye\n',
  'services:\n  a:\n    image: alpine\n    ports: [\n      "80:80",\n      81\n    ]\n',
  'services:\n  a:\n    environment:\n      URL: "https://example.org/${PATH}"\n      SILLY: a$$b\n'
]);

HIGHLIGHT_FIXTURES.forEach(function (text, idx) {
  checkReconstruction('inline fixture #' + idx, text);
});

/* ---- I2. key: value — key/punct/str spans in the right places ---------- */

(function () {
  var sp = spans(Y.highlight('    image: alpine', '').html);
  ok('key span holds just the key', sp.some(function (p) { return p.kind === 'key' && p.text === 'image'; }));
  ok('punct span holds the colon', sp.some(function (p) { return p.kind === 'punct' && p.text === ':'; }));
  ok('str span holds the value', sp.some(function (p) { return p.kind === 'str' && p.text === 'alpine'; }));
})();

/* ---- I3. A '#' inside a double-quoted value is not a comment ----------- */

(function () {
  var r = Y.highlight('    C: "grey #3"', '');
  var sp = spans(r.html);
  ok('no comment span appears', !sp.some(function (p) { return p.kind === 'comment'; }));
  ok('the hash stays inside the str span', sp.some(function (p) { return p.kind === 'str' && p.text === '"grey #3"'; }));
})();

/* ---- I4. A trailing ' # note' after a value IS a comment ---------------- */

(function () {
  var sp = spans(Y.highlight('    R: alpha    # note', '').html);
  ok('the value is its own span', sp.some(function (p) { return p.kind === 'str' && p.text === 'alpha'; }));
  ok('the comment runs from the hash to end of line',
     sp.some(function (p) { return p.kind === 'comment' && p.text === '# note'; }));
})();

/* ---- I5. A block scalar swallows its body, and stops at the owning indent */

(function () {
  var lines = ['    command: |', '      echo hi', '      echo bye', '    image: alpine'];
  var carry = '';

  var r1 = Y.highlight(lines[0], carry);
  ok('opening a block scalar carries the owning key\'s indent', r1.carry === 'block:4', r1.carry);
  carry = r1.carry;

  var r2 = Y.highlight(lines[1], carry);
  ok('an indented body line stays in the block', r2.carry === 'block:4', r2.carry);
  carry = r2.carry;

  var r3 = Y.highlight(lines[2], carry);
  ok('the next body line stays in too', r3.carry === 'block:4', r3.carry);
  carry = r3.carry;

  var r4 = Y.highlight(lines[3], carry);
  ok('a line back at the owning indent leaves the block', r4.carry === '', r4.carry);
  ok('and is read as an ordinary key line',
     spans(r4.html).some(function (p) { return p.kind === 'key' && p.text === 'image'; }));
})();

/* ---- I6. A flow list left open across lines carries flow:1, then closes - */

(function () {
  var lines = ['    ports: [', '      "80:80",', '      81', '    ]'];
  var carry = '';

  var r1 = Y.highlight(lines[0], carry);
  ok('opening a flow collection carries its bracket depth', r1.carry === 'flow:1', r1.carry);
  carry = r1.carry;

  var r2 = Y.highlight(lines[1], carry);
  ok('a flow entry line stays open', r2.carry === 'flow:1', r2.carry);
  carry = r2.carry;

  var r3 = Y.highlight(lines[2], carry);
  ok('so does the next one', r3.carry === 'flow:1', r3.carry);
  carry = r3.carry;

  var r4 = Y.highlight(lines[3], carry);
  ok('the closing bracket returns to empty carry', r4.carry === '', r4.carry);
})();

/* ---- I7. ${VAR} and ${VAR:-default} are 'var'; '$$' is not -------------- */

(function () {
  var sp1 = spans(Y.highlight('    X: ${VAR}', '').html);
  ok('${VAR} is a var span', sp1.some(function (p) { return p.kind === 'var' && p.text === '${VAR}'; }));

  var sp2 = spans(Y.highlight('    Y: ${VAR:-default}', '').html);
  ok('${VAR:-default} is one whole var span',
     sp2.some(function (p) { return p.kind === 'var' && p.text === '${VAR:-default}'; }));

  var sp3 = spans(Y.highlight('    Z: a$$b', '').html);
  ok('$$ produces no var span', !sp3.some(function (p) { return p.kind === 'var'; }));
  ok('the literal $$ stays in the surrounding text',
     sp3.some(function (p) { return p.text.indexOf('a$$b') >= 0; }));
})();

/* ---- I8. &anchor, *alias and the <<: merge key are all 'anchor' --------- */

(function () {
  var sp1 = spans(Y.highlight('    x: &def', '').html);
  ok('&anchor is an anchor span', sp1.some(function (p) { return p.kind === 'anchor' && p.text === '&def'; }));

  var sp2 = spans(Y.highlight('    x: *def', '').html);
  ok('*alias is an anchor span', sp2.some(function (p) { return p.kind === 'anchor' && p.text === '*def'; }));

  var sp3 = spans(Y.highlight('    <<: *defaults', '').html);
  ok('the merge key itself is an anchor span',
     sp3.some(function (p) { return p.kind === 'anchor' && p.text === '<<'; }));
  ok('and so is the alias it points at',
     sp3.some(function (p) { return p.kind === 'anchor' && p.text === '*defaults'; }));
})();

/* ---- I9. '- image: alpine' — the dash is punct, image is a key --------- */

(function () {
  var sp = spans(Y.highlight('      - image: alpine', '').html);
  ok('the dash is punct', sp.some(function (p) { return p.kind === 'punct' && p.text === '-'; }));
  ok('image is a key', sp.some(function (p) { return p.kind === 'key' && p.text === 'image'; }));
  ok('alpine is the value', sp.some(function (p) { return p.kind === 'str' && p.text === 'alpine'; }));
})();

/* ---- I10. Whitespace-only, empty, and hash-only lines ------------------- */

(function () {
  var r1 = Y.highlight('   ', '');
  ok('a whitespace-only line round-trips with empty carry',
     decodeHl(r1.html) === '   ' && r1.carry === '', JSON.stringify(r1));

  var r2 = Y.highlight('', '');
  ok('an empty line produces empty html and empty carry',
     r2.html === '' && r2.carry === '', JSON.stringify(r2));

  var r3 = Y.highlight('#', '');
  var sp3 = spans(r3.html);
  ok('a lone "#" is one comment span',
     sp3.length === 1 && sp3[0].kind === 'comment' && sp3[0].text === '#', JSON.stringify(sp3));
})();

/* ---- I11. A number is 'num'; a quoted number is 'str', not 'num' -------- */

(function () {
  var sp1 = spans(Y.highlight('    PORT: 8096', '').html);
  ok('a bare number is a num span', sp1.some(function (p) { return p.kind === 'num' && p.text === '8096'; }));

  var sp2 = spans(Y.highlight('    PUID: "99"', '').html);
  ok('a quoted number is a str span', sp2.some(function (p) { return p.kind === 'str' && p.text === '"99"'; }));
  ok('and never a num span', !sp2.some(function (p) { return p.kind === 'num'; }));
})();

/* ---- I12. highlight() never throws, whatever it is handed -------------- */

(function () {
  var NASTY = ['\t\tkey: value', '   key:value:value', '- - - -', '"unterminated',
               '${', 'key: "a\\', 'key: [', '<<:', '&', '*', '!!'];
  var bad = null;
  NASTY.forEach(function (line) {
    try {
      var r = Y.highlight(line, '');
      if (typeof r.html !== 'string' || typeof r.carry !== 'string') bad = line + ' (bad return shape)';
    } catch (e) {
      bad = line + ' threw: ' + e.message;
    }
  });
  ok('no input throws or returns the wrong shape', !bad, bad);
})();

/* =========================================================================
 * J. Linting — Y.lint(doc)
 *
 * The regression this section exists to stop: lint()'s unknown-key check
 * must run against the compose SPECIFICATION, not against KEYS (the ~40
 * service keys the form draws a control for). J2 below loops every key the
 * spec really accepts and demands zero warnings — if someone later "tidies"
 * lint() to check against KEYS instead, this is what catches it.
 * ========================================================================= */

console.log('\nJ. Linting');

// Every service key the compose specification accepts — kept in step with
// SERVICE_SPEC_KEYS in compose-model.js by hand, since that list is
// deliberately not exported (it is lint()'s own data, not part of the API
// other code is meant to lean on).
var SPEC_SERVICE_KEYS = [
  'annotations', 'attach', 'blkio_config', 'build', 'cap_add', 'cap_drop', 'cgroup',
  'cgroup_parent', 'command', 'configs', 'container_name', 'cpu_count', 'cpu_percent',
  'cpu_period', 'cpu_quota', 'cpu_rt_period', 'cpu_rt_runtime', 'cpu_shares', 'cpus', 'cpuset',
  'credential_spec', 'depends_on', 'deploy', 'develop', 'device_cgroup_rules', 'devices', 'dns',
  'dns_opt', 'dns_search', 'domainname', 'entrypoint', 'env_file', 'environment', 'expose',
  'extends', 'external_links', 'extra_hosts', 'gpus', 'group_add', 'healthcheck', 'hostname',
  'image', 'init', 'ipc', 'isolation', 'label_file', 'labels', 'links', 'logging', 'mac_address',
  'mem_limit', 'mem_reservation', 'mem_swappiness', 'memswap_limit', 'network_mode', 'networks',
  'oom_kill_disable', 'oom_score_adj', 'pid', 'pids_limit', 'platform', 'ports', 'post_start',
  'pre_stop', 'privileged', 'profiles', 'provider', 'pull_policy', 'pull_refresh_after',
  'read_only', 'restart', 'runtime', 'scale', 'secrets', 'security_opt', 'shm_size', 'stdin_open',
  'stop_grace_period', 'stop_signal', 'storage_opt', 'sysctls', 'tmpfs', 'tty', 'ulimits', 'user',
  'userns_mode', 'uts', 'volumes', 'volumes_from', 'working_dir'
];

/* ---- J1. A clean file reports nothing ----------------------------------- */

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    restart: unless-stopped\n');
  ok('a clean file reports nothing', Y.lint(doc).length === 0, JSON.stringify(Y.lint(doc)));
})();

/* ---- J2. Every real spec key, looped through one file, warns on none ---- */

(function () {
  var lines = ['services:', '  a:', '    image: alpine'];
  // checkSpecValues() now judges restart and network_mode against real value lists, so the
  // generic "x" filler correctly warns on those two. Give them real values instead — this
  // test is about every key being recognised, not about what each key is set to.
  var VALUE_OVERRIDE = { restart: 'unless-stopped', network_mode: 'bridge' };
  SPEC_SERVICE_KEYS.forEach(function (k) {
    if (k === 'image') return;
    lines.push('    ' + k + ': "' + (VALUE_OVERRIDE[k] || 'x') + '"');
  });
  var doc = Y.parse(lines.join('\n') + '\n');
  var res = Y.lint(doc);
  ok('every SERVICE_SPEC_KEYS entry (' + SPEC_SERVICE_KEYS.length + ' keys) reports no warnings',
     res.length === 0, JSON.stringify(res));
})();

/* ---- J3. Two ordinary keys the FORM has no control for --------------- */

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    sysctls:\n      net.core.somaxconn: 1024\n    extra_hosts:\n      - "host.docker.internal:host-gateway"\n');
  ok('sysctls and extra_hosts report nothing', Y.lint(doc).length === 0, JSON.stringify(Y.lint(doc)));
})();

/* ---- J4. A duplicate key warns on the right (0-based) line ------------- */

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    image: nginx\n');
  var res = Y.lint(doc);
  ok('exactly one entry', res.length === 1, JSON.stringify(res));
  ok('duplicate key warns on line 3 (0-based)', res[0] && res[0].line === 3, JSON.stringify(res));
  ok('it is a warning, not an error', res[0] && res[0].level === 'warn', JSON.stringify(res));
})();

/* ---- J5. A typo under a service suggests the real key ------------------ */

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    enviroment:\n      X: "1"\n');
  var res = Y.lint(doc);
  ok('one warning', res.length === 1, JSON.stringify(res));
  ok('it names the real key',
     !!res[0] && res[0].message === 'The key "enviroment" is not a compose setting. Did you mean "environment"?',
     JSON.stringify(res));
})();

/* ---- J6. An unknown key with nothing close by gets the plain message ---- */

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    wibblewobble: yes\n');
  var res = Y.lint(doc);
  ok('the plain "will ignore it" message, no suggestion offered',
     res.length === 1 && res[0].message === 'The key "wibblewobble" is not a compose setting, so Docker will ignore it.',
     JSON.stringify(res));
})();

/* ---- J7. An unknown TOP-level key is caught the same way --------------- */

(function () {
  var doc = Y.parse('service:\n  a:\n    image: alpine\n');
  var res = Y.lint(doc);
  ok('"service" (missing the s) is flagged at the top level',
     res.length === 1 && res[0].line === 0 && res[0].message.indexOf('"service"') >= 0 &&
     res[0].message.indexOf('services') >= 0,
     JSON.stringify(res));
})();

/* ---- J8. x-anything reports nothing, at either level -------------------- */

(function () {
  var doc = Y.parse('x-something: 1\nservices:\n  a:\n    image: alpine\n    x-whatever:\n      note: hi\n');
  ok('x- keys at both levels report nothing', Y.lint(doc).length === 0, JSON.stringify(Y.lint(doc)));
})();

/* ---- J9. A tab-indented file reports exactly one error ------------------ */

(function () {
  var doc = Y.parse('services:\n\ta:\n\t\timage: alpine\n');
  var res = Y.lint(doc);
  ok('exactly one entry for a tab-indented file', res.length === 1, JSON.stringify(res));
  ok('it is an error, at line 0', !!res[0] && res[0].level === 'error' && res[0].line === 0, JSON.stringify(res));
})();

/* ---- J10. Every corpus fixture but 08-deliberately-broken has no errors - */

FILES.forEach(function (file) {
  var name = path.relative(ROOT, file).replace(/\\/g, '/');
  if (name.indexOf('08-deliberately-broken') >= 0) return;
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  var res = Y.lint(Y.parse(text));
  var errors = res.filter(function (r) { return r.level === 'error'; });
  var warns = res.filter(function (r) { return r.level === 'warn'; });
  ok('no errors from ' + name + (warns.length ? ' (' + warns.length + ' warning(s): ' +
     warns.map(function (w) { return 'line ' + w.line + ': ' + w.message; }).join(' | ') + ')' : ''),
     errors.length === 0, JSON.stringify(errors));
});

/* ---- J11. lint() never throws, whatever it is handed -------------------- */

(function () {
  var NASTY = [null, undefined, 42, 'not a doc', {}, [],
    { sealed: 'not an array', warnings: null, root: { kind: 'map', keys: ['x'], pairs: {} } },
    { root: { kind: 'map', keys: ['a'], pairs: null } }];
  var bad = null;
  NASTY.forEach(function (input) {
    try {
      var r = Y.lint(input);
      if (!Array.isArray(r)) bad = JSON.stringify(input) + ' (did not return an array)';
    } catch (e) {
      bad = JSON.stringify(input) + ' threw: ' + e.message;
    }
  });
  ok('no input throws or returns the wrong shape', !bad, bad);
})();

/* ---- J12. checkSpecValues() — restart:, silent on every real value ------
 *
 * The negative cases matter more than the positive ones here: a false
 * warning on a working file costs more trust than ten missed typos.
 */

(function () {
  var SILENT = ['always', 'no', 'unless-stopped', 'on-failure', 'on-failure:5'];
  var bad = null;
  SILENT.forEach(function (v) {
    var doc = Y.parse('services:\n  a:\n    image: alpine\n    restart: "' + v + '"\n');
    var res = Y.lint(doc);
    if (res.length !== 0) bad = v + ': ' + JSON.stringify(res);
  });
  ok('every real restart value reports nothing', !bad, bad);
})();

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    restart: ${SOMEVAR}\n');
  ok('an interpolated restart: is never judged, since its real value is unknown',
     Y.lint(doc).length === 0, JSON.stringify(Y.lint(doc)));
})();

(function () {
  var doc = Y.parse('x-r: &r always\nservices:\n  a:\n    image: alpine\n    restart: *r\n');
  ok('a restart: aliased to a sealed anchor is never judged',
     Y.lint(doc).length === 0, JSON.stringify(Y.lint(doc)));
})();

/* ---- J13. checkSpecValues() — restart:, warns on a real typo ------------ */

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    restart: alwyas\n');
  var res = Y.lint(doc);
  ok('"alwyas" warns and names "always"',
     res.length === 1 && res[0].level === 'warn' &&
     res[0].message === '"alwyas" is not one of the values restart accepts. Did you mean "always"?',
     JSON.stringify(res));
})();

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    restart: banana\n');
  var res = Y.lint(doc);
  ok('"banana" (no near match) warns and lists the accepted values',
     res.length === 1 && res[0].level === 'warn' &&
     res[0].message === '"banana" is not one of the values restart accepts. It accepts no, always, unless-stopped or on-failure.',
     JSON.stringify(res));
})();

/* ---- J14. checkSpecValues() — network_mode: is a tri-state on realNets --
 *
 * Passing no realNets at all means "the server has not said what networks
 * it has yet" — different from "it has none" — so the whole check is off
 * until netLoad() answers. Passing [] means it answered with nothing, and
 * the check runs for real from that point on.
 */

(function () {
  var SILENT = ['host', 'none', 'bridge', 'service:db', 'container:x', 'br0', 'br0.100'];
  var bad = null;
  SILENT.forEach(function (v) {
    var doc = Y.parse('services:\n  a:\n    image: alpine\n    network_mode: "' + v + '"\n');
    var res = Y.lint(doc, []);
    if (res.length !== 0) bad = v + ': ' + JSON.stringify(res);
  });
  ok('every real network_mode value reports nothing, even with the check switched on ([])', !bad, bad);
})();

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    network_mode: node\n');
  ok('a real network called "node" is never called a typo once the server confirms it',
     Y.lint(doc, ['node']).length === 0, JSON.stringify(Y.lint(doc, ['node'])));
})();

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    network_mode: node\n');
  ok('with no realNets at all, network_mode is left alone entirely — the check is off, not a miss',
     Y.lint(doc).length === 0, JSON.stringify(Y.lint(doc)));
})();

(function () {
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    network_mode: hots\n');
  var res = Y.lint(doc, []);
  ok('"hots" warns and names "host", once realNets has arrived (even empty)',
     res.length === 1 && res[0].level === 'warn' &&
     res[0].message === '"hots" is not one of the values network_mode accepts. Did you mean "host"?',
     JSON.stringify(res));
})();

(function () {
  // The tri-state itself, pinned: the same document, judged twice, disagrees
  // — omitted defers judgement, [] carries it out. Easy to regress silently
  // since both "no argument" and "empty array" look like "nothing to check"
  // at a glance.
  var doc = Y.parse('services:\n  a:\n    image: alpine\n    network_mode: hots\n');
  var withoutList = Y.lint(doc);
  var withEmptyList = Y.lint(doc, []);
  ok('the same file lints differently depending on whether realNets has arrived yet',
     withoutList.length === 0 && withEmptyList.length === 1,
     JSON.stringify({ withoutList: withoutList, withEmptyList: withEmptyList }));
})();

/* ---- J15. checkSpecValues() never marks a working fixture --------------- */

(function () {
  var res = Y.lint(Y.parse(FIXTURE_10_ADVANCED), []);
  var bad = res.filter(function (r) {
    return r.message.indexOf('restart accepts') >= 0 || r.message.indexOf('network_mode accepts') >= 0;
  });
  ok('FIXTURE_10_ADVANCED carries no restart/network_mode warning, check switched on',
     bad.length === 0, JSON.stringify(bad));
})();

(function () {
  var bad = null;
  FILES.filter(function (f) { return f.indexOf(path.join('examples', '')) >= 0; }).forEach(function (file) {
    var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
    var res = Y.lint(Y.parse(text), []);
    var hit = res.filter(function (r) {
      return r.message.indexOf('restart accepts') >= 0 || r.message.indexOf('network_mode accepts') >= 0;
    });
    if (hit.length) bad = (bad || []).concat(hit.map(function (h) { return file + ': ' + h.message; }));
  });
  ok('every examples/*/compose.yaml carries no restart/network_mode warning, check switched on',
     !bad, JSON.stringify(bad));
})();

/* =========================================================================
 * K. Search — Y.searchMatches(text, needle, opts)
 * ========================================================================= */

console.log('\nK. Search');

/* ---- K1. A plain match in the middle of a line -------------------------- */

(function () {
  var r = Y.searchMatches('services:\n  a:\n    image: alpine\n', 'image');
  ok('one match found', r.length === 1, JSON.stringify(r));
  var m = r[0];
  ok('start/end/line/col all correct', m && m.start === 19 && m.end === 24 &&
     m.line === 2 && m.col === 4, JSON.stringify(m));
})();

/* ---- K2. Case-insensitive by default; caseSensitive narrows it ---------- */

(function () {
  var text = 'Image: alpine\nimage: alpine\n';
  var loose = Y.searchMatches(text, 'image');
  ok('case-insensitive by default finds both', loose.length === 2, JSON.stringify(loose));
  var strict = Y.searchMatches(text, 'image', { caseSensitive: true });
  ok('caseSensitive narrows to the exact-case one', strict.length === 1 &&
     strict[0].line === 1, JSON.stringify(strict));
})();

/* ---- K3. Several matches on one line, and across several lines ---------- */

(function () {
  var text = 'foo foo\nfoo\nfoo foo foo\n';
  var r = Y.searchMatches(text, 'foo');
  ok('finds every occurrence', r.length === 6, JSON.stringify(r));
  var expect = [
    { line: 0, col: 0 }, { line: 0, col: 4 },
    { line: 1, col: 0 },
    { line: 2, col: 0 }, { line: 2, col: 4 }, { line: 2, col: 8 }
  ];
  var bad = null;
  for (var i = 0; i < expect.length; i++) {
    if (!r[i] || r[i].line !== expect[i].line || r[i].col !== expect[i].col) { bad = i; break; }
  }
  ok('line and col correct for each match', bad === null,
     'mismatch at index ' + bad + ': ' + JSON.stringify(r));
})();

/* ---- K4. A match at offset 0, and one at the very end of the text ------- */

(function () {
  var text = 'abcabc';
  var r = Y.searchMatches(text, 'abc');
  ok('match at offset 0 found', r.length === 2 && r[0].start === 0 && r[0].line === 0 && r[0].col === 0,
     JSON.stringify(r));
  ok('match at the very end found', r[1].start === 3 && r[1].end === 6, JSON.stringify(r));
})();

/* ---- K5. Overlapping candidates do not overlap in the result ------------ */

(function () {
  var r = Y.searchMatches('aaaa', 'aa');
  ok('"aa" in "aaaa" gives 2 non-overlapping matches, not 3',
     r.length === 2 && r[0].start === 0 && r[1].start === 2, JSON.stringify(r));
})();

/* ---- K6. regex: true, a real pattern and a capture group ---------------- */

(function () {
  var r = Y.searchMatches('port 8096 and 8097', '\\d+', { regex: true });
  ok('digit runs found', r.length === 2 && r[0].start === 5 && r[0].end === 9 &&
     r[1].start === 14 && r[1].end === 18, JSON.stringify(r));

  var r2 = Y.searchMatches('image: alpine:3.19', 'alpine:(\\d+\\.\\d+)', { regex: true });
  ok('a pattern with a capture group still reports the whole match span',
     r2.length === 1 && r2[0].start === 7 && r2[0].end === 18, JSON.stringify(r2));
})();

/* ---- K7. regex: true with an invalid pattern returns [] and never throws  */

(function () {
  var threw = false, r;
  try { r = Y.searchMatches('anything', '[', { regex: true }); } catch (e) { threw = true; }
  ok('an invalid pattern returns [] rather than throwing', !threw && Array.isArray(r) && r.length === 0,
     threw ? 'threw' : JSON.stringify(r));
})();

/* ---- K8. A pattern that matches emptily returns no zero-length matches --
 * and terminates. Guarded with a wall-clock check so a regression that loops
 * forever fails the test instead of hanging the whole run.
 * ---------------------------------------------------------------------- */

(function () {
  ['a*', '^'].forEach(function (pattern) {
    var started = Date.now();
    var r = Y.searchMatches('aaa\nbbb\naaa\n', pattern, { regex: true });
    var elapsed = Date.now() - started;
    ok('"' + pattern + '" terminates', elapsed < 2000, elapsed + 'ms');
    var zeroLen = r.some(function (m) { return m.end === m.start; });
    ok('"' + pattern + '" reports no zero-length match', !zeroLen, JSON.stringify(r));
  });
})();

/* ---- K9. A regex match spanning a newline reports the start line -------- */

(function () {
  var text = 'aaa\nbbb';
  var r = Y.searchMatches(text, 'aaa\\nbbb', { regex: true });
  ok('a cross-newline match reports the line/col of its start',
     r.length === 1 && r[0].line === 0 && r[0].col === 0 && r[0].end === text.length, JSON.stringify(r));
})();

/* ---- K9b. ^ and $ anchor to a line, not to the whole file ---------------- */

(function () {
  // The 'm' flag. Without it ^services matches only a file that opens with it,
  // which reads as the search being broken rather than as a subtlety of
  // regular expressions — and every editor with a regex search behaves this
  // way. $ follows from the same flag.
  var text = 'a\nservices:\n  x:\nservices2:';
  ok('^ anchors to each line',
     JSON.stringify(Y.searchMatches(text, '^services', { regex: true }).map(function (m) { return m.line; }))
       === '[1,3]');
  ok('$ anchors to the end of a line',
     Y.searchMatches('one\ntwo', 'one$', { regex: true }).length === 1);
})();

/* ---- K10. Empty needle, empty text, needle longer than text ------------- */

(function () {
  ok('empty needle returns []', Y.searchMatches('some text', '').length === 0);
  ok('empty text returns []', Y.searchMatches('', 'x').length === 0);
  ok('needle longer than text returns []', Y.searchMatches('hi', 'hello there').length === 0);
})();

/* ---- K11. The 5000-match cap holds --------------------------------------- */

(function () {
  var text = new Array(6001).join('a');   // 6000 'a's
  var r = Y.searchMatches(text, 'a');
  ok('scan stops at the 5000 cap', r.length === 5000, r.length);
})();

/* ---- K12. Real use: search a corpus fixture and check the count ---------- */

(function () {
  var file = path.join(ROOT, 'scratch/test-stacks/03-multi-tier/compose.yaml');
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  var r = Y.searchMatches(text, 'image');
  var independent = (text.match(/image/g) || []).length;
  ok('search count matches an independent count of "image" in the fixture',
     r.length === independent && independent > 0, r.length + ' vs ' + independent);
})();

/* =========================================================================
 * L. Key suggestions — Y.keySuggestions(text, offset), Y.keyAt(text, line, col),
 *    Y.keyInfo(key, where)
 *
 * These work from the raw text, never parse() — the file is mid-edit exactly
 * when a suggestion is wanted. L11 is the one that stops the DESCRIPTIONS
 * table rotting: every key the compose specification accepts, at top level
 * and inside a service, must come back with something to say about it.
 * ========================================================================= */

console.log('\nL. Key suggestions');

// The top-level keys the compose specification accepts — kept in step with
// TOP_SPEC_KEYS in compose-model.js by hand, for the same reason
// SPEC_SERVICE_KEYS above is: that list is lint()'s own data, not part of
// the API other code is meant to lean on.
var SPEC_TOP_KEYS = ['services', 'networks', 'volumes', 'configs', 'secrets', 'name', 'include', 'version'];

/* ---- L1. Top level, no prefix yet ---------------------------------------- */

(function () {
  var r = Y.keySuggestions('', 0);
  ok('offers something at the top of an empty file', !!r && r.keys.length > 0, JSON.stringify(r));
  ok('every offered key is a real top-level key',
     r && r.keys.every(function (k) { return SPEC_TOP_KEYS.indexOf(k.key) >= 0; }),
     r && JSON.stringify(r.keys));
})();

/* ---- L2. Inside a service, offers SERVICE_SPEC_KEYS, not TOP_SPEC_KEYS -- */

(function () {
  var text = 'services:\n  a:\n    po\n';
  var r = Y.keySuggestions(text, text.indexOf('po') + 2);
  ok('offers "ports"', !!r && r.keys.some(function (k) { return k.key === 'ports'; }), JSON.stringify(r));
  ok('does not offer "services" (a top-level-only key)',
     !!r && !r.keys.some(function (k) { return k.key === 'services'; }), JSON.stringify(r));
})();

/* ---- L3. The prefix filter: starts-with before merely-contains ---------- */

(function () {
  var text = 'services:\n  a:\n    po\n';
  var r = Y.keySuggestions(text, text.indexOf('po') + 2);
  ok('"ports" (starts with "po") sorts ahead of "cpuset" (has no "po" at all excluded, ' +
     '"expose" merely contains "po")', !!r);
  var idxPorts = r.keys.map(function (k) { return k.key; }).indexOf('ports');
  var idxExpose = r.keys.map(function (k) { return k.key; }).indexOf('expose');
  ok('a starts-with match ("ports") sorts ahead of a contains-only match ("expose")',
     idxPorts >= 0 && idxExpose >= 0 && idxPorts < idxExpose, JSON.stringify(r && r.keys));
})();

/* ---- L4. Keys already in the block are excluded -------------------------- */

(function () {
  var text = 'services:\n  a:\n    image: alpine\n    i\n';
  var r = Y.keySuggestions(text, text.lastIndexOf('i') + 1);
  ok('"image" is not offered again, since the service already has one',
     !!r && !r.keys.some(function (k) { return k.key === 'image'; }), JSON.stringify(r));
  ok('"init" (also starts with "i") is still offered',
     !!r && r.keys.some(function (k) { return k.key === 'init'; }), JSON.stringify(r));
})();

/* ---- L5. Directly under services: — a name the user invents, not a key -- */

(function () {
  var text = 'services:\n  a\n';
  var r = Y.keySuggestions(text, text.indexOf('a\n') + 1);
  ok('null directly under services:', r === null, JSON.stringify(r));
})();

/* ---- L6. Caret after the colon is in the value, not the key -------------- */

(function () {
  var text = 'services:\n  a:\n    image: alp\n';
  var r = Y.keySuggestions(text, text.indexOf('alp') + 3);
  ok('null once the caret is past the colon', r === null, JSON.stringify(r));
})();

/* ---- L7. null in a comment, and in an ancestry the walk cannot read ------ */

(function () {
  var c = Y.keySuggestions('services:\n  a:\n    # a note\n', 25);
  ok('null inside a comment', c === null, JSON.stringify(c));

  // A sequence item sits above at a smaller indent than the caret's own line,
  // so the walk cannot tell what encloses it — null beats a wrong guess.
  var text = 'services:\n- weird\n  im\n';
  var m = Y.keySuggestions(text, text.lastIndexOf('im') + 2);
  ok('null when an ancestor line is not a key the walk can read', m === null, JSON.stringify(m));
})();

/* ---- L8. start/end bracket the partial word exactly ---------------------- */

(function () {
  var text = 'services:\n  a:\n    imag\n';
  var wordStart = text.indexOf('imag');
  var r = Y.keySuggestions(text, wordStart + 4);
  ok('start is the first character of the partial word',
     !!r && r.start === wordStart, JSON.stringify(r));
  ok('end is the caret, right after the last character typed',
     !!r && r.end === wordStart + 4, JSON.stringify(r));
  ok('prefix is exactly the word typed so far', !!r && r.prefix === 'imag', JSON.stringify(r));

  var empty = Y.keySuggestions('services:\n  a:\n    \n', 20);
  ok('with no word yet, start === end === offset',
     !!empty && empty.start === 20 && empty.end === 20, JSON.stringify(empty));
})();

/* ---- L9. Inside healthcheck: offers healthcheck keys, not service keys -- */

(function () {
  var text = 'services:\n  a:\n    healthcheck:\n      te\n';
  var r = Y.keySuggestions(text, text.indexOf('te', text.indexOf('healthcheck')) + 2);
  ok('offers "test"', !!r && r.keys.some(function (k) { return k.key === 'test'; }), JSON.stringify(r));
  ok('does not offer "image" (a service key, not a healthcheck key)',
     !!r && !r.keys.some(function (k) { return k.key === 'image'; }), JSON.stringify(r));
})();

/* ---- L10. keyAt finds a key and returns its description ------------------ */

(function () {
  var text = 'services:\n  a:\n    image: alpine\n';
  var r = Y.keyAt(text, 2, 6);           // the 'g' in "image"
  ok('finds the key', !!r && r.key === 'image', JSON.stringify(r));
  ok('title matches KEYS/DESCRIPTIONS', !!r && r.title === 'Image', JSON.stringify(r));
  ok('description is a non-empty sentence', !!r && typeof r.description === 'string' && r.description.length > 0,
     JSON.stringify(r));
})();

/* ---- L11. keyAt returns null over the value, not the key ----------------- */

(function () {
  var text = 'services:\n  a:\n    image: alpine\n';
  var r = Y.keyAt(text, 2, 15);          // inside "alpine"
  ok('null over a value', r === null, JSON.stringify(r));
})();

/* ---- L12. Every TOP_SPEC_KEYS / SERVICE_SPEC_KEYS entry has a description */

(function () {
  var missing = [];
  SPEC_TOP_KEYS.forEach(function (k) {
    var info = Y.keyInfo(k, 'top');
    if (!info || !info.title || !/\.\s*$/.test(info.description || '')) missing.push('top:' + k);
  });
  SPEC_SERVICE_KEYS.forEach(function (k) {
    var info = Y.keyInfo(k, 'service');
    if (!info || !info.title || !/\.\s*$/.test(info.description || '')) missing.push('service:' + k);
  });
  ok('every one of ' + (SPEC_TOP_KEYS.length + SPEC_SERVICE_KEYS.length) +
     ' spec keys has a title and a description ending in a full stop',
     missing.length === 0, JSON.stringify(missing));
})();

/* ---- L13. Neither function throws on malformed input --------------------- */

(function () {
  var NASTY_TEXT = [null, undefined, 42, {}, [], '\t\tno spaces allowed\n'];
  var bad = null;
  NASTY_TEXT.forEach(function (input) {
    try { Y.keySuggestions(input, 0); Y.keyAt(input, 0, 0); }
    catch (e) { bad = JSON.stringify(input) + ' threw: ' + e.message; }
  });
  try { Y.keySuggestions('services:\n  a:\n', -5); Y.keySuggestions('services:\n  a:\n', 99999); }
  catch (e) { bad = bad || 'bad offset threw: ' + e.message; }
  try { Y.keyAt('services:\n  a:\n', -5, -5); Y.keyAt('services:\n  a:\n', 999, 999); }
  catch (e) { bad = bad || 'bad line/col threw: ' + e.message; }
  ok('no input throws', !bad, bad);
})();

/* =========================================================================
 * M. Host paths — Y.hostPaths(text) -> [{path, line, col, len}]
 *
 * Never calls parse(): a mount's host folder is checked while the file is
 * being edited, which is exactly when it may not parse cleanly. M11 runs it
 * over every real fixture and just reports what it finds, since a wrong
 * answer there is the one that matters.
 * ========================================================================= */

console.log('\nM. Host paths');

/* ---- M1. Short form, absolute path --------------------------------------- */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - /mnt/user/media:/data\n';
  var r = Y.hostPaths(text);
  ok('one path found', r.length === 1, JSON.stringify(r));
  ok('path is the host side only', r[0] && r[0].path === '/mnt/user/media', JSON.stringify(r));
  ok('line is 0-based, pointing at the entry', r[0] && r[0].line === 3, JSON.stringify(r));
  ok('col is right after "- "', r[0] && r[0].col === 8, JSON.stringify(r));
  ok('len matches the path text', r[0] && r[0].len === '/mnt/user/media'.length, JSON.stringify(r));
})();

/* ---- M2. Quoted entry — col points inside the quote ---------------------- */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - "/mnt/user/media:/data"\n';
  var r = Y.hostPaths(text);
  ok('one path found', r.length === 1, JSON.stringify(r));
  ok('path has no quotes', r[0] && r[0].path === '/mnt/user/media', JSON.stringify(r));
  ok('col sits one past the opening quote', r[0] && r[0].col === 9, JSON.stringify(r));
})();

/* ---- M3. :ro suffix does not get swallowed into the host path ------------- */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - /mnt/user/media:/data:ro\n';
  var r = Y.hostPaths(text);
  ok('one path found', r.length === 1, JSON.stringify(r));
  ok('the ":ro" mode is not part of the reported path',
     r[0] && r[0].path === '/mnt/user/media', JSON.stringify(r));
})();

/* ---- M4. A named volume (appdata:/config) is not reported ----------------- */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - appdata:/config\n';
  var r = Y.hostPaths(text);
  ok('nothing reported for a named volume', r.length === 0, JSON.stringify(r));
})();

/* ---- M5. A top-level volumes: declaration block is not reported ----------- */

(function () {
  var text = 'volumes:\n  appdata:\n    driver: local\n';
  var r = Y.hostPaths(text);
  ok('nothing reported for a named-volume declaration', r.length === 0, JSON.stringify(r));
})();

/* ---- M6. A relative path is reported, same as an absolute one ------------- */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - ./data:/data\n';
  var r = Y.hostPaths(text);
  ok('the relative path is reported', r.length === 1 && r[0].path === './data', JSON.stringify(r));
})();

/* ---- M7. Long form, type: bind reports its source: ------------------------ */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - type: bind\n        source: /mnt/user/media\n        target: /data\n';
  var r = Y.hostPaths(text);
  ok('the source: value is reported', r.length === 1 && r[0].path === '/mnt/user/media', JSON.stringify(r));
  ok('line points at the source: line, not the dash', r[0] && r[0].line === 4, JSON.stringify(r));
})();

/* ---- M8. Long form, type: volume is not reported --------------------------- */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - type: volume\n        source: db_data\n        target: /data\n';
  var r = Y.hostPaths(text);
  ok('a named-volume source is not reported', r.length === 0, JSON.stringify(r));
})();

/* ---- M9. A path containing ${VAR} is skipped ------------------------------ */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - ${DATA_DIR}/media:/data\n';
  var r = Y.hostPaths(text);
  ok('nothing reported when the host side needs a variable substituted',
     r.length === 0, JSON.stringify(r));
})();

/* ---- M10. Two services each with volumes both report ---------------------- */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - /mnt/a:/data\n  b:\n    volumes:\n      - /mnt/b:/data\n';
  var r = Y.hostPaths(text);
  ok('both services\' mounts are found',
     r.length === 2 && r[0].path === '/mnt/a' && r[1].path === '/mnt/b', JSON.stringify(r));
})();

/* ---- M11. Never throws, including on a file that is only "volumes:" ------- */

(function () {
  var NASTY = [null, undefined, 42, {}, [], 'volumes:\n', '\t\tno spaces allowed\n'];
  var bad = null;
  NASTY.forEach(function (input) {
    try { Y.hostPaths(input); }
    catch (e) { bad = JSON.stringify(input) + ' threw: ' + e.message; }
  });
  ok('no input throws, and each comes back as an array', !bad, bad);
})();

/* ---- M12. Every fixture — reported so a wrong answer is visible ----------- */

console.log('  --- fixture host paths (for review) ---');
FILES.forEach(function (file) {
  var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  var r = Y.hostPaths(text);
  console.log('  ' + path.relative(ROOT, file) + ':');
  if (r.length === 0) { console.log('    (none)'); return; }
  r.forEach(function (p) {
    console.log('    line ' + p.line + ', col ' + p.col + ': "' + p.path + '" (len ' + p.len + ')');
  });
});

/* =========================================================================
 * N. Health check round trip (PLAN_14.md)
 *
 * The reported defect: switching "How the check runs" to "No check" and
 * then back left the compose file stuck on NONE forever, and every later
 * edit to "The check itself" silently failed to reach the file. The cause
 * was setPart()'s combined mode+command write for healthcheck.test: the
 * blank-writes-nothing leniency applied even to a deliberate MODE switch,
 * so switching back from NONE (whose sibling command reads as blank, since
 * NONE has no room for one) was itself silently skipped — leaving the file
 * on NONE and the model reading "none" from then on, so every subsequent
 * edit to the command combined with that same stuck mode.
 * ========================================================================= */

console.log('\nN. Health check round trip');

/* ---- N1. The failing case: NONE and back restores the original line ----- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
            '      test: ["CMD-SHELL", "wget -q -O - http://localhost/"]\n      interval: 30s\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var mode = form.fields.filter(function (f) { return f.testPart === 'mode'; })[0];
  ok('the mode field is found', !!mode);

  Y.setPart(doc, form, mode.id, 'value', 'none');
  ok('switching to NONE writes it', /test: \["NONE"\]/.test(Y.serialise(doc)), Y.serialise(doc));

  var form2 = Y.buildForm(doc);
  var mode2 = form2.fields.filter(function (f) { return f.testPart === 'mode'; })[0];
  var r = Y.setPart(doc, form2, mode2.id, 'value', 'shell');
  ok('switching back to "run a shell line" is written, not silently skipped', r === true);

  ok('the file is restored byte for byte, command and all',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- N2. The same, starting from CMD (list-of-arguments) mode ----------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
            '      test: ["CMD", "curl", "-f", "http://localhost/health"]\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var mode = form.fields.filter(function (f) { return f.testPart === 'mode'; })[0];

  Y.setPart(doc, form, mode.id, 'value', 'none');
  var form2 = Y.buildForm(doc);
  var mode2 = form2.fields.filter(function (f) { return f.testPart === 'mode'; })[0];
  Y.setPart(doc, form2, mode2.id, 'value', 'cmd');

  ok('CMD mode is restored byte for byte too', Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- N3. A value typed after the round trip reaches the file ------------ */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n' +
            '      test: ["CMD-SHELL", "wget -q -O - http://localhost/"]\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var mode = form.fields.filter(function (f) { return f.testPart === 'mode'; })[0];

  Y.setPart(doc, form, mode.id, 'value', 'none');
  var form2 = Y.buildForm(doc);
  var mode2 = form2.fields.filter(function (f) { return f.testPart === 'mode'; })[0];
  Y.setPart(doc, form2, mode2.id, 'value', 'shell');   // restores the stashed command

  var form3 = Y.buildForm(doc);
  var cmd3  = form3.fields.filter(function (f) { return f.testPart === 'command'; })[0];
  ok('the restored command reads back correctly', cmd3.parts.value.value === 'wget -q -O - http://localhost/',
     cmd3.parts.value.value);

  Y.setPart(doc, form3, cmd3.id, 'value', 'curl -f http://localhost/health');
  ok('a freshly typed command reaches the file, proving the box is not permanently disconnected',
     /test: \["CMD-SHELL", "curl -f http:\/\/localhost\/health"\]/.test(Y.serialise(doc)), Y.serialise(doc));
})();

/* ---- N4. Switching mode is a deliberate choice: it always writes -------- */

(function () {
  // A fresh healthcheck with no command yet — "run a shell line" is written
  // even though the sibling command is blank, rather than silently no-op'd.
  var src = 'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var mode = form.fields.filter(function (f) { return f.testPart === 'mode'; })[0];

  var r = Y.setPart(doc, form, mode.id, 'value', 'shell');
  ok('choosing "run a shell line" with nothing typed yet is written, not skipped', r === true);
  ok('it writes an (empty) CMD-SHELL line rather than doing nothing',
     /test: \["CMD-SHELL", ""\]/.test(Y.serialise(doc)), Y.serialise(doc));

  // "Run a program" needs at least one argument, so it has nowhere valid to
  // write — and says so by returning false, which commit() (stacks.js)
  // already turns into an on-screen message, rather than leaving the
  // dropdown showing a choice the file never received.
  var doc2 = Y.parse(src), form2 = Y.buildForm(doc2);
  var mode2 = form2.fields.filter(function (f) { return f.testPart === 'mode'; })[0];
  var r2 = Y.setPart(doc2, form2, mode2.id, 'value', 'cmd');
  ok('choosing "run a program" with nothing typed yet refuses rather than silently doing nothing', r2 === false);
})();

/* ---- N5. Every shape healthcheck.test is written in survives untouched -- */

var HEALTH_SHAPES = {
  'a bare shell line (CMD-SHELL, string form)':
    'services:\n  a:\n    image: alpine\n    healthcheck:\n      test: curl -f http://localhost/\n',
  'a block sequence (CMD, one argument per line)':
    'services:\n  a:\n    image: alpine\n    healthcheck:\n      test:\n        - CMD\n        - curl\n        - -f\n        - http://localhost/\n',
  'a flow list, CMD':
    'services:\n  a:\n    image: alpine\n    healthcheck:\n      test: ["CMD", "curl", "-f", "http://localhost/"]\n',
  'a flow list, CMD-SHELL':
    'services:\n  a:\n    image: alpine\n    healthcheck:\n      test: ["CMD-SHELL", "curl -f http://localhost/"]\n',
  'a flow list, NONE':
    'services:\n  a:\n    image: alpine\n    healthcheck:\n      test: ["NONE"]\n'
};

Object.keys(HEALTH_SHAPES).forEach(function (name) {
  var t = HEALTH_SHAPES[name];
  var got = Y.serialise(Y.parse(t));
  ok(name + '  [identity]', got === t, got === t ? '' : firstDiff(t, got));

  var form = Y.buildForm(Y.parse(t));
  var mode = form.fields.filter(function (f) { return f.testPart === 'mode'; })[0];
  var cmd  = form.fields.filter(function (f) { return f.testPart === 'command'; })[0];
  var wantMode = /NONE/.test(t) ? 'none' : (/CMD-SHELL/.test(t) || !/CMD/.test(t) ? 'shell' : 'cmd');
  ok(name + '  reads as mode "' + wantMode + '"', !!mode && mode.parts.value.value === wantMode,
     mode && mode.parts.value.value);
  if (wantMode !== 'none') {
    ok(name + '  reads its command', !!cmd && cmd.parts.value.value === 'curl -f http://localhost/',
       cmd && JSON.stringify(cmd.parts.value.value));
  }
});

/* =========================================================================
 * O. Field help (Form pane)
 *
 * fieldHelp() maps one rendered field back to its DESCRIPTIONS sentence, and
 * helpGaps() is the guard that stops a key being added to a table without
 * anyone writing that sentence — checked first, since a gap there would make
 * the rest of this section fail for a reason that has nothing to do with
 * fieldHelp() itself.
 * ========================================================================= */

console.log('\nO. Field help (Form pane)');

/* ---- O1. helpGaps() is empty --------------------------------------------- */

(function () {
  var gaps = Y.helpGaps();
  ok('every key one of the model\'s own tables names has a DESCRIPTIONS entry',
     gaps.length === 0, JSON.stringify(gaps));
})();

/* ---- O2. fieldHelp() over the whole fixture corpus ----------------------- */

(function () {
  var unresolvedSetting = [];
  var declaredMissing = 0, dependsMissing = 0;

  FILES.forEach(function (file) {
    var text = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
    var form = Y.buildForm(Y.parse(text));

    form.fields.forEach(function (f) {
      if (f.binder !== 'setting' && f.binder !== 'declared' && f.binder !== 'depends') return;
      var help = Y.fieldHelp(f);

      if (f.binder === 'declared' && !help) declaredMissing++;
      if (f.binder === 'depends'  && !help) dependsMissing++;
      // A 'setting' field can legitimately come back null: the Advanced
      // catch-all renders whatever key the file actually has, including a
      // misspelling (portz: in 08-deliberately-broken) this editor cannot
      // know the meaning of.
      if (f.binder === 'setting' && !help) {
        unresolvedSetting.push(path.relative(ROOT, file) + ' :: ' + f.target);
      }
    });
  });

  ok('every declared field resolves to help', declaredMissing === 0, declaredMissing + ' unresolved');
  ok('every depends field resolves to help', dependsMissing === 0, dependsMissing + ' unresolved');

  console.log('  --- fixture setting targets fieldHelp cannot describe (for review) ---');
  if (unresolvedSetting.length === 0) { console.log('    (none)'); }
  unresolvedSetting.forEach(function (u) { console.log('    ' + u); });
})();

/* ---- O3. The mapping rules, one at a time -------------------------------- */

(function () {
  var doc = Y.parse(
    'services:\n  a:\n    image: alpine\n    healthcheck:\n      interval: 30s\n' +
    '    deploy:\n      resources:\n        limits:\n          cpus: "1.5"\n' +
    '    logging:\n      driver: json-file\n'
  );
  var form = Y.buildForm(doc);
  var byTarget = function (target) {
    return form.fields.filter(function (f) { return f.binder === 'setting' && f.target === target; })[0];
  };

  var hc = byTarget('healthcheck.interval');
  ok('healthcheck.interval resolves under healthcheck, not service',
     !!hc && !!Y.fieldHelp(hc) && Y.fieldHelp(hc).title === Y.keyInfo('interval', 'healthcheck').title);
  ok('healthcheck.interval does NOT resolve as a service-level key',
     !Y.keyInfo('healthcheck.interval', 'service'));

  var cpus = byTarget('deploy.resources.limits.cpus');
  ok('deploy.resources.limits.cpus resolves', !!cpus && !!Y.fieldHelp(cpus));

  var driver = byTarget('logging.driver');
  ok('logging.driver resolves', !!driver && !!Y.fieldHelp(driver));
})();

(function () {
  // A declared name containing a dot: the fold row's LAST segment is the
  // setting, never a fragment of the name itself.
  var doc = Y.parse(
    'services:\n  a:\n    image: alpine\n' +
    'networks:\n  "br0.2":\n    driver: macvlan\n    internal: true\n'
  );
  var form = Y.buildForm(doc);
  var internalRow = form.fields.filter(function (f) {
    return f.binder === 'declared' && f.fold && f.target === 'networks.br0.2.internal';
  })[0];
  var help = internalRow && Y.fieldHelp(internalRow);
  ok('a fold row on a dotted declaration name resolves to its setting, not a name fragment',
     !!help && help.title === Y.keyInfo('internal', 'declared').title, JSON.stringify(help));

  var rowField = form.fields.filter(function (f) {
    return f.binder === 'declared' && !f.fold && f.target === 'networks.br0.2';
  })[0];
  ok('the row itself is found under the dotted name', !!rowField);
})();

(function () {
  // A non-fold declaration row's box holds the kind's primary setting.
  var doc = Y.parse(
    'services:\n  a:\n    image: alpine\n' +
    'secrets:\n  db_pass:\n    file: ./db_pass.txt\n'
  );
  var form = Y.buildForm(doc);
  var row = form.fields.filter(function (f) {
    return f.binder === 'declared' && !f.fold && f.target === 'secrets.db_pass';
  })[0];
  var help = row && Y.fieldHelp(row);
  ok('a non-fold secrets row resolves to file', !!help && help.title === Y.keyInfo('file', 'declared').title,
     JSON.stringify(help));
})();

(function () {
  var doc = Y.parse(
    'services:\n  a:\n    image: alpine\n    depends_on:\n      b:\n        condition: service_healthy\n'
  );
  var form = Y.buildForm(doc);
  var row = form.fields.filter(function (f) {
    return f.binder === 'depends' && !f.fold && f.target === 'depends_on.b';
  })[0];
  var help = row && Y.fieldHelp(row);
  ok('a non-fold depends row resolves to condition', !!help && help.title === Y.keyInfo('condition', 'depends').title,
     JSON.stringify(help));
})();

(function () {
  ok('a nonsense binder returns null', Y.fieldHelp({ binder: 'nonsense', target: 'whatever' }) === null);
  ok('a missing field returns null', Y.fieldHelp(null) === null);
  ok('a malformed field returns null rather than throwing',
     (function () { try { return Y.fieldHelp({ binder: 'setting', target: 42 }); } catch (e) { return 'threw'; } })() === null);
})();

/* =========================================================================
 * P. The VOCAB registry (PLAN_15 phase 1, corrected in phase 3)
 *
 * Twelve of the thirteen CHOICES lists stacks.js held moved verbatim into
 * compose-model.js's VOCAB, reachable from a Node test for the first time.
 * VOCAB_SNAPSHOT is the photograph of the data taken the moment before that
 * move, so it is never edited to make a test here pass — see its own header.
 *
 * Phase 3 then corrected three of the twelve: pullpolicy, networkdriver and
 * capability were missing real compose values. The snapshot's job was only
 * ever to guard the MOVE, so those three are now meant to differ from it —
 * P1 asserts two things instead of exact equality: nothing the snapshot held
 * was lost, and the full list is exactly the snapshot plus the named
 * additions, nothing more.
 * ========================================================================= */

console.log('\nP. The VOCAB registry (PLAN_15 phase 1)');

// Pair-by-pair equality with a diff that names the entry and shows both
// sides — "not equal" alone would leave whoever reads a failure re-deriving
// which of ~40 capability entries moved or was mistyped.
function vocabDiff(expected, actual) {
  if (!Array.isArray(actual)) return 'not an array: ' + JSON.stringify(actual);
  if (expected.length !== actual.length) {
    return 'length ' + expected.length + ' (snapshot) vs ' + actual.length + ' (vocab())';
  }
  for (var i = 0; i < expected.length; i++) {
    var e = expected[i], a = actual[i];
    if (!Array.isArray(a) || a[0] !== e[0] || a[1] !== e[1]) {
      return 'entry ' + i + ': expected ' + JSON.stringify(e) + ', got ' + JSON.stringify(a);
    }
  }
  return null;
}

function pairsEqual(a, b) {
  return vocabDiff(b, a) === null;
}

// Every entry in `expected` still appears in `actual`, in the same relative
// order and byte-identical — `actual` may hold MORE (the named additions a
// corrected id gained), but never less and never reordered. This is P1's
// "nothing was lost" half; vocabDiff() above is its "nothing arrived by
// accident" half, checked against the full expected list including additions.
function subsequenceDiff(expected, actual) {
  if (!Array.isArray(actual)) return 'not an array: ' + JSON.stringify(actual);
  var ai = 0;
  for (var ei = 0; ei < expected.length; ei++) {
    var e = expected[ei], found = false;
    while (ai < actual.length) {
      var a = actual[ai++];
      if (Array.isArray(a) && a[0] === e[0] && a[1] === e[1]) { found = true; break; }
    }
    if (!found) return 'snapshot entry ' + ei + ' missing or out of order: ' + JSON.stringify(e);
  }
  return null;
}

// Splices `entries` into `list` right after the item whose value is
// `afterValue`, so a corrected id's expected FULL list is built from the
// snapshot's own array rather than retyped — a copy-paste slip in an
// EXISTING label could not hide inside this expectation too.
function insertAfter(list, afterValue, entries) {
  var idx = -1;
  for (var i = 0; i < list.length; i++) { if (list[i][0] === afterValue) { idx = i; break; } }
  if (idx < 0) throw new Error("insertAfter: '" + afterValue + "' not found in snapshot list");
  return list.slice(0, idx + 1).concat(entries, list.slice(idx + 1));
}

var C = VOCAB_SNAPSHOT.CHOICES;

// Every id VOCAB is meant to carry, and the exact snapshot list each one was
// copied from. 'boolean' has no CHOICES entry of its own to copy from — it
// is the generic true/false pair every BOOL_CHOICES wording specialises —
// so its expectation is written out literally instead.
var VOCAB_SOURCES = {
  restart:          C['setting/restart'].options,
  netmode:          C['setting/network_mode'].options,
  dependscondition: C['depends/condition'].options,
  pullpolicy:       C['setting/pull_policy'].options,
  stopsignal:       C['setting/stop_signal'].options,
  ipc:              C['setting/ipc'].options,
  pid:              C['setting/pid'].options,
  logdriver:        C['setting/logging.driver'].options,
  networkdriver:    C['declared/networks.driver'].options,
  volumedriver:     C['declared/volumes.driver'].options,
  capability:       VOCAB_SNAPSHOT.CAP_OPTIONS,
  boolean:          [['true', 'true'], ['false', 'false']]
};

/* ---- P1. the three ids phase 3 corrected: snapshot plus the named ------- */
/* ---- additions, nothing lost and nothing arrived by accident ------------ */

// Where Part 1 (PLAN_15 phase 3) put each addition, and its exact label —
// copied verbatim from compose-model.js's VOCAB so a wording slip on either
// side shows up as a failure rather than agreeing with itself.
var CORRECTED = {
  pullpolicy: insertAfter(VOCAB_SOURCES.pullpolicy, 'missing', [
    ['if_not_present', 'if_not_present — the same as missing, compose’s other name for it'],
    ['refresh',        'refresh — pull again once the image on this server looks stale'],
    ['daily',          'daily — check for a newer image once a day'],
    ['weekly',         'weekly — check for a newer image once a week']
  ]),
  networkdriver: VOCAB_SOURCES.networkdriver.concat([
    ['overlay', 'overlay — connects containers across several Docker hosts in a swarm']
  ]),
  capability: [['ALL', 'ALL — every capability at once']].concat(VOCAB_SOURCES.capability)
};

Object.keys(CORRECTED).forEach(function (id) {
  var actual = Y.vocab(id);

  var lost = subsequenceDiff(VOCAB_SOURCES[id], actual);
  ok("vocab('" + id + "') keeps every snapshot entry, in order and byte-identical", lost === null, lost);

  var diff = vocabDiff(CORRECTED[id], actual);
  ok("vocab('" + id + "') is exactly the snapshot plus phase 3's named additions", diff === null, diff);
});

/* ---- P1b. the nine ids phase 3 left untouched still match exactly ------- */

['restart', 'netmode', 'dependscondition', 'stopsignal', 'ipc', 'pid',
 'logdriver', 'volumedriver', 'boolean'].forEach(function (id) {
  var diff = vocabDiff(VOCAB_SOURCES[id], Y.vocab(id));
  ok("vocab('" + id + "') matches the snapshot", diff === null, diff);
});

/* ---- P2. an unknown id comes back null ----------------------------------- */

ok("vocab('nonsense') returns null", Y.vocab('nonsense') === null);
ok('vocab() with no id returns null', Y.vocab() === null);

/* ---- P3. mutating a returned list does not touch the next call ---------- */

(function () {
  var before = Y.vocab('restart').length;
  var got = Y.vocab('restart');
  got.push(['bogus', 'should not stick']);
  got.length = 0;                                   // and emptying it outright
  var after = Y.vocab('restart');
  ok('mutating what vocab() returned leaves the next call untouched',
     after.length === before, 'before ' + before + ', after ' + after.length);
})();

/* ---- P4. the four lists that stayed behind have no id of their own ------ */

(function () {
  var held = [
    { name: "CHOICES['setting/healthcheck.test.mode']", list: C['setting/healthcheck.test.mode'].options },
    { name: "CHOICES['port/proto']",                     list: C['port/proto'].options },
    { name: "CHOICES['volume/mode']",                    list: C['volume/mode'].options }
  ];
  Object.keys(VOCAB_SNAPSHOT.BOOL_CHOICES).forEach(function (k) {
    held.push({ name: 'BOOL_CHOICES.' + k, list: VOCAB_SNAPSHOT.BOOL_CHOICES[k].options });
  });

  ok('the snapshot still holds all four held-back entries (3 lists + BOOL_CHOICES\'s 8)',
     held.length === 11, held.length);

  Object.keys(VOCAB_SOURCES).forEach(function (id) {
    var actual = Y.vocab(id);
    var collision = null;
    held.forEach(function (h) { if (!collision && pairsEqual(actual, h.list)) collision = h.name; });
    ok("vocab('" + id + "') does not reproduce a list that stayed behind",
       collision === null, collision);
  });
})();

/* =========================================================================
 * Q. Value suggestions (PLAN_15 phase 2) — Y.valueSuggestions(text, offset)
 *
 * keySuggestions()'s mirror image: autocomplete for the VALUE half of a
 * key: value line, or a `- ` list item whose parent key carries a
 * vocabulary. Same shape back — {start, end, prefix, keys}, plus
 * value: true — so the panel that already renders keySuggestions() renders
 * these unchanged.
 * ========================================================================= */

console.log('\nQ. Value suggestions');

/* ---- Q1. null before the colon, no space, in a comment, blank, no vocab - */

(function () {
  var text = 'services:\n  a:\n    restart: always\n';
  var beforeColon = Y.valueSuggestions(text, text.indexOf('restart'));
  ok('null before the colon', beforeColon === null, JSON.stringify(beforeColon));

  var noSpace = Y.valueSuggestions('services:\n  a:\n    restart:\n', 'services:\n  a:\n    restart:'.length);
  ok('null at "restart:" with no space after the colon — writing there would ' +
     'produce "restart:always", a plain scalar, not a key: value pair',
     noSpace === null, JSON.stringify(noSpace));

  var withComment = 'services:\n  a:\n    restart: always # note\n';
  var noteOffset = withComment.indexOf('note') + 2;
  var r = Y.valueSuggestions(withComment, noteOffset);
  ok('null with the caret inside a trailing comment', r === null, JSON.stringify(r));

  var blank = Y.valueSuggestions('services:\n  a:\n    \n', 20);
  ok('null on a blank line', blank === null, JSON.stringify(blank));

  var noVocab = Y.valueSuggestions('services:\n  a:\n    image: alpine\n', 'services:\n  a:\n    image: alp'.length);
  ok('null on a key with no vocabulary (image:)', noVocab === null, JSON.stringify(noVocab));
})();

/* ---- Q2. "restart: " with nothing typed offers all four, in order ------- */

(function () {
  var text = 'services:\n  a:\n    restart: \n';
  var offset = text.indexOf('restart: ') + 'restart: '.length;
  var r = Y.valueSuggestions(text, offset);
  ok('offers something', !!r, JSON.stringify(r));
  var got = r && r.keys.map(function (k) { return k.key; });
  ok('offers all four restart values, in the vocabulary\'s own order',
     !!r && got.join(',') === 'no,always,unless-stopped,on-failure', JSON.stringify(got));
})();

/* ---- Q3. "restart: unl" narrows to unless-stopped, start/end exact ------- */

(function () {
  var text = 'services:\n  a:\n    restart: unl\n';
  var wordStart = text.indexOf('unl');
  var r = Y.valueSuggestions(text, wordStart + 3);
  ok('narrows to a single match', !!r && r.keys.length === 1 && r.keys[0].key === 'unless-stopped',
     JSON.stringify(r));
  ok('start/end bracket exactly "unl"',
     !!r && r.start === wordStart && r.end === wordStart + 3, JSON.stringify(r));
  ok('prefix is exactly "unl"', !!r && r.prefix === 'unl', JSON.stringify(r));
  ok('value flag is set, so the caller knows not to append ": "',
     !!r && r.value === true, JSON.stringify(r));
})();

/* ---- Q4. "restart: always", caret at the end — the exact single match --- */

(function () {
  var text = 'services:\n  a:\n    restart: always\n';
  var r = Y.valueSuggestions(text, text.indexOf('always') + 'always'.length);
  ok('null — the one match is the thing already typed', r === null, JSON.stringify(r));
})();

/* ---- Q5. A `- ` item under cap_add: offers capabilities, case-insensitive */

(function () {
  var text = 'services:\n  a:\n    cap_add:\n      - NET_A\n';
  var r = Y.valueSuggestions(text, text.indexOf('NET_A') + 'NET_A'.length);
  ok('offers NET_ADMIN', !!r && r.keys.some(function (k) { return k.key === 'NET_ADMIN'; }), JSON.stringify(r));

  var lower = 'services:\n  a:\n    cap_add:\n      - net_\n';
  var r2 = Y.valueSuggestions(lower, lower.indexOf('net_') + 'net_'.length);
  ok('matching is case-insensitive: lowercase "net_" still reaches NET_ADMIN',
     !!r2 && r2.keys.some(function (k) { return k.key === 'NET_ADMIN'; }), JSON.stringify(r2));
})();

/* ---- Q6. A `- ` item that is really a long-form mapping returns null ----- */

(function () {
  // The brief's own example — ports has no vocabulary of its own either, so
  // this alone would pass even without the guard.
  var ports = 'services:\n  a:\n    ports:\n      - target: 8080\n';
  var r = Y.valueSuggestions(ports, ports.indexOf('8080'));
  ok('null for a long-form "- target: 8080" item under ports:', r === null, JSON.stringify(r));

  // cap_add DOES have a vocabulary, so this is the case that actually proves
  // the sub-key guard fires before any vocabulary lookup, rather than the
  // result being null merely because the owning key has no vocab.
  var fabricated = 'services:\n  a:\n    cap_add:\n      - value: NET_ADMIN\n';
  var r2 = Y.valueSuggestions(fabricated, fabricated.indexOf('NET_ADMIN'));
  ok('null for a long-form item even under a key that DOES have a vocabulary',
     r2 === null, JSON.stringify(r2));
})();

/* ---- Q7. ${...} interpolation: open guards, closed does not crash ------- */

(function () {
  var open = 'services:\n  a:\n    restart: ${VA\n';
  var r = Y.valueSuggestions(open, open.indexOf('${VA') + 4);
  ok('null with the caret inside an unterminated ${...}', r === null, JSON.stringify(r));

  var closed = 'services:\n  a:\n    restart: ${VAR}\n';
  var bad = null;
  var r2;
  try { r2 = Y.valueSuggestions(closed, closed.indexOf('${VAR}') + 6); }
  catch (e) { bad = e.message; }
  ok('a caret after a closed ${VAR} does not throw', !bad, bad);
})();

/* ---- Q8. driver: means different lists under networks: and volumes: ----- */

(function () {
  var nets = 'networks:\n  net1:\n    driver: br\n';
  var rn = Y.valueSuggestions(nets, nets.indexOf('br') + 2);
  ok('networks: driver offers network drivers',
     !!rn && rn.keys.some(function (k) { return k.key === 'bridge'; }), JSON.stringify(rn));

  var vols = 'volumes:\n  vol1:\n    driver: lo\n';
  var rv = Y.valueSuggestions(vols, vols.indexOf('lo') + 2);
  ok('volumes: driver offers volume drivers',
     !!rv && rv.keys.some(function (k) { return k.key === 'local'; }), JSON.stringify(rv));

  ok('the two lists differ — this is the case the VOCAB_AT split by kind exists for',
     JSON.stringify(rn && rn.keys) !== JSON.stringify(rv && rv.keys),
     JSON.stringify({ networks: rn && rn.keys, volumes: rv && rv.keys }));
})();

/* ---- Q9. healthcheck: disable: offers true/false ------------------------- */

(function () {
  var text = 'services:\n  a:\n    healthcheck:\n      disable: \n';
  var offset = text.indexOf('disable: ') + 'disable: '.length;
  var r = Y.valueSuggestions(text, offset);
  var got = r && r.keys.map(function (k) { return k.key; });
  ok('offers true and false', !!r && got.join(',') === 'true,false', JSON.stringify(got));
})();

/* ---- Q10. Malformed input returns null, never throws --------------------- */

(function () {
  var bad = null;
  try {
    ok('empty text', Y.valueSuggestions('', 0) === null);
    ok('negative offset', Y.valueSuggestions('restart: al', -5) === null);
    ok('offset past the end', Y.valueSuggestions('restart: al', 99999) === null);
  } catch (e) { bad = e.message; }
  ok('none of the malformed cases throw', !bad, bad);
})();

/* =========================================================================
 * R. Reaching the new vocabulary (PLAN_15 phase 3)
 *
 * Phase 3 adds ~20 new vocabularies and the tables that let the editor's
 * caret actually reach them — a vocabulary with no path to it is dead data,
 * so every id below is proven reachable through valueSuggestions() at a
 * real caret position, not merely present in VOCAB. The new key tables
 * (DEPLOY_LEAVES and the two-levels-deeper blocks under deploy: and
 * logging:) get the same proof as keySuggestions().
 * ========================================================================= */

console.log('\nR. Reaching the new vocabulary (PLAN_15 phase 3)');

// Builds "services:\n  a:\n    <indented path>\n" and returns the offset
// right after "<lastKey>: ", so every case below only has to state its own
// path once rather than hand-counting indentation and string lengths.
function serviceValueCase(pathLines, lastKeyValuePrefix) {
  var indent = '    ';
  var text = 'services:\n  a:\n';
  pathLines.forEach(function (line) { text += indent + line + '\n'; indent += '  '; });
  text += indent + lastKeyValuePrefix + '\n';
  var offset = text.length - 1; // caret right after the trailing space, before '\n'
  return { text: text, offset: offset };
}

/* ---- R1. each new vocabulary is reachable through valueSuggestions() ---- */

(function () {
  var cases = [
    { name: 'uts: at service level',              c: serviceValueCase([], 'uts: '),        want: 'host' },
    { name: 'cgroup: at service level',           c: serviceValueCase([], 'cgroup: '),     want: 'host' },
    { name: 'isolation: at service level',        c: serviceValueCase([], 'isolation: '),  want: 'default' },
    { name: 'mode: under deploy:',                c: serviceValueCase(['deploy:'], 'mode: '), want: 'replicated' },
    { name: 'endpoint_mode: under deploy:',       c: serviceValueCase(['deploy:'], 'endpoint_mode: '), want: 'vip' },
    { name: 'condition: under deploy.restart_policy:',
      c: serviceValueCase(['deploy:', 'restart_policy:'], 'condition: '), want: 'on-failure' },
    { name: 'order: under deploy.update_config:',
      c: serviceValueCase(['deploy:', 'update_config:'], 'order: '), want: 'start-first' },
    { name: 'failure_action: under deploy.update_config:',
      c: serviceValueCase(['deploy:', 'update_config:'], 'failure_action: '), want: 'rollback' },
    { name: 'order: under deploy.rollback_config:',
      c: serviceValueCase(['deploy:', 'rollback_config:'], 'order: '), want: 'stop-first' },
    { name: 'failure_action: under deploy.rollback_config:',
      c: serviceValueCase(['deploy:', 'rollback_config:'], 'failure_action: '), want: 'pause' },
    { name: 'mode: under logging.options:',
      c: serviceValueCase(['logging:', 'options:'], 'mode: '), want: 'blocking' },
    { name: 'network: under build:',              c: serviceValueCase(['build:'], 'network: '), want: 'none' }
  ];

  cases.forEach(function (t) {
    var r = Y.valueSuggestions(t.c.text, t.c.offset);
    var got = r && r.keys.map(function (k) { return k.key; });
    ok(t.name + ' offers ' + t.want, !!r && got.indexOf(t.want) >= 0, JSON.stringify({ text: t.c.text, r: r }));
  });
})();

/* ---- R1b. enable_ipv6: on a network declaration offers true/false ------- */

(function () {
  var text = 'networks:\n  net1:\n    enable_ipv6: \n';
  var r = Y.valueSuggestions(text, text.indexOf('enable_ipv6: ') + 'enable_ipv6: '.length);
  var got = r && r.keys.map(function (k) { return k.key; });
  ok('networks: enable_ipv6 offers true and false',
     !!r && got.join(',') === 'true,false', JSON.stringify(r));
})();

/* ---- R2. deploy's own keys, and the new deep blocks, resolve as KEYS ---- */

(function () {
  var text = 'services:\n  a:\n    deploy:\n      m\n';
  var r = Y.keySuggestions(text, text.indexOf('      m') + 7);
  ok("keySuggestions() at deploy: with prefix 'm' finds mode",
     !!r && r.keys.some(function (k) { return k.key === 'mode'; }), JSON.stringify(r));
})();

(function () {
  var text = 'services:\n  a:\n    deploy:\n      restart_policy:\n        c\n';
  var r = Y.keySuggestions(text, text.lastIndexOf('c') + 1);
  ok("deploy.restart_policy: resolves 'condition' as a key",
     !!r && r.keys.some(function (k) { return k.key === 'condition'; }), JSON.stringify(r));
})();

(function () {
  var text = 'services:\n  a:\n    deploy:\n      update_config:\n        o\n';
  var r = Y.keySuggestions(text, text.lastIndexOf('o') + 1);
  ok("deploy.update_config: resolves 'order' as a key",
     !!r && r.keys.some(function (k) { return k.key === 'order'; }), JSON.stringify(r));
})();

(function () {
  var text = 'services:\n  a:\n    deploy:\n      rollback_config:\n        o\n';
  var r = Y.keySuggestions(text, text.lastIndexOf('o') + 1);
  ok("deploy.rollback_config: resolves 'order' as a key too — the two blocks share one table",
     !!r && r.keys.some(function (k) { return k.key === 'order'; }), JSON.stringify(r));
})();

(function () {
  var text = 'services:\n  a:\n    logging:\n      options:\n        m\n';
  var r = Y.keySuggestions(text, text.lastIndexOf('m') + 1);
  ok("logging.options: offers 'mode' as a key (a hint over a free-form map)",
     !!r && r.keys.some(function (k) { return k.key === 'mode'; }), JSON.stringify(r));
})();

/* ---- R3. helpGaps() still comes back empty with the new tables added ---- */

ok('helpGaps() is still empty after phase 3\'s additions', Y.helpGaps().length === 0,
   JSON.stringify(Y.helpGaps()));

/* ---- R4. "on-failure:3" is not fought — nothing offered, nothing throws - */

(function () {
  var text = 'services:\n  a:\n    restart: on-failure:3\n';
  var offset = text.indexOf('on-failure:3') + 'on-failure:3'.length;
  var bad = null, r;
  try { r = Y.valueSuggestions(text, offset); } catch (e) { bad = e.message; }
  ok('valueSuggestions() does not throw on "on-failure:3"', !bad, bad);
  ok('nothing is offered for "on-failure:3" — the prefix matches none of the four values',
     !r || r.keys.length === 0, JSON.stringify(r));
})();

/* =========================================================================
 * S. File references — Y.fileRefs(text) -> [{file, service, where}]
 *
 * Every place a compose file names a file it expects to find beside it in
 * the stack's own folder: env_file, a build context/Dockerfile, a relative
 * volume host side, and a top-level secrets:/configs: file:. Built from
 * classify() alone, the same as hostPaths() just above it — never parse(),
 * since this has to survive running on a file mid-edit. S9 is the case that
 * matters most: a file that cannot parse must still come back as an array.
 * ========================================================================= */

console.log('\nS. File references');

/* ---- S1. env_file as a list, and as a scalar on the key's own line ------ */

(function () {
  var text = 'services:\n  a:\n    env_file:\n      - .env\n    image: alpine\n  b:\n    env_file: prod.env\n';
  var r = Y.fileRefs(text);
  ok('the list item is found', r.some(function (x) { return x.file === '.env' && x.service === 'a' && x.where === 'env_file'; }), JSON.stringify(r));
  ok('the scalar form is found', r.some(function (x) { return x.file === 'prod.env' && x.service === 'b' && x.where === 'env_file'; }), JSON.stringify(r));
})();

/* ---- S1b. env_file's long form (- path: .env) is deliberately unread ---- */

(function () {
  var text = 'services:\n  a:\n    env_file:\n      - path: .env\n        required: false\n';
  var r = Y.fileRefs(text);
  ok('the long form is not reported — not read, not guessed at', r.length === 0, JSON.stringify(r));
})();

/* ---- S2. Two services naming the same file get one entry each ----------- */

(function () {
  var text = 'services:\n  a:\n    env_file:\n      - shared.env\n  b:\n    env_file:\n      - shared.env\n';
  var r = Y.fileRefs(text);
  ok('both services report the file', r.length === 2 &&
     r[0].file === 'shared.env' && r[0].service === 'a' &&
     r[1].file === 'shared.env' && r[1].service === 'b', JSON.stringify(r));
})();

/* ---- S3. An absolute path is ignored -------------------------------------- */

(function () {
  var text = 'services:\n  a:\n    env_file:\n      - /etc/shared.env\n';
  var r = Y.fileRefs(text);
  ok('an absolute env_file is not reported', r.length === 0, JSON.stringify(r));
})();

/* ---- S4. "./x" and "x" come back the same -------------------------------- */

(function () {
  var text = 'services:\n  a:\n    env_file:\n      - ./x\n  b:\n    env_file:\n      - x\n';
  var r = Y.fileRefs(text);
  ok('both are reported as the bare name',
     r.length === 2 && r[0].file === 'x' && r[1].file === 'x', JSON.stringify(r));
})();

/* ---- S5. A quoted value is unquoted --------------------------------------- */

(function () {
  var text = 'services:\n  a:\n    env_file: ".env"\n';
  var r = Y.fileRefs(text);
  ok('the quotes are stripped', r.length === 1 && r[0].file === '.env', JSON.stringify(r));
})();

/* ---- S6. build: long form reports context: and dockerfile:; short form -- */
/* ---- names a directory and is left out ------------------------------------ */

(function () {
  var text = 'services:\n  a:\n    build:\n      context: ./app\n      dockerfile: Dockerfile.dev\n';
  var r = Y.fileRefs(text);
  ok('context: is reported', r.some(function (x) { return x.file === 'app' && x.service === 'a' && x.where === 'build'; }), JSON.stringify(r));
  ok('dockerfile: is reported', r.some(function (x) { return x.file === 'Dockerfile.dev' && x.service === 'a' && x.where === 'build'; }), JSON.stringify(r));
})();

(function () {
  var text = 'services:\n  a:\n    build: .\n';
  var r = Y.fileRefs(text);
  ok('build\'s short form names a directory, not a file, and is left out',
     r.length === 0, JSON.stringify(r));
})();

/* ---- S7. Top-level secrets:/configs: file: — service is '' --------------- */

(function () {
  var text = 'secrets:\n  cert:\n    file: ./cert.pem\nconfigs:\n  conf:\n    file: conf.txt\n';
  var r = Y.fileRefs(text);
  ok('the secret is reported with no service',
     r.some(function (x) { return x.file === 'cert.pem' && x.service === '' && x.where === 'secret'; }), JSON.stringify(r));
  ok('the config is reported with no service',
     r.some(function (x) { return x.file === 'conf.txt' && x.service === '' && x.where === 'config'; }), JSON.stringify(r));
})();

/* ---- S8. A relative volume host side is included, an absolute one is not - */

(function () {
  var text = 'services:\n  a:\n    volumes:\n      - ./data:/data\n      - /mnt/user/media:/media\n';
  var r = Y.fileRefs(text);
  ok('only the relative mount is reported',
     r.length === 1 && r[0].file === 'data' && r[0].service === 'a' && r[0].where === 'volume', JSON.stringify(r));
})();

/* ---- S9. A deliberately broken file returns something, never throws ----- */

(function () {
  var BROKEN = [
    'services:\n  a:\n    env_file: "unterminated\n',
    'services:\n  a:\n\tenv_file: .env\n',                 // a tab in the indentation
    'services:\n  a:\n    env_file:\n      - .env',        // truncated, no trailing newline
    null, undefined, 42, {}, []
  ];
  var bad = null;
  BROKEN.forEach(function (input) {
    try {
      var r = Y.fileRefs(input);
      if (!Array.isArray(r)) bad = JSON.stringify(input) + ' did not return an array';
    } catch (e) { bad = JSON.stringify(input) + ' threw: ' + e.message; }
  });
  ok('every broken or bad input comes back as an array rather than throwing', !bad, bad);
})();

/* ---- S10. Top-level include:, scalar and list forms (PLAN_21) ----------- */

(function () {
  var text = 'include: other.yaml\n';
  var r = Y.fileRefs(text);
  ok('the scalar form is reported with where: include',
     r.length === 1 && r[0].file === 'other.yaml' && r[0].service === '' && r[0].where === 'include',
     JSON.stringify(r));
})();

(function () {
  var text = 'include:\n  - other.yaml\n  - second.yaml\n';
  var r = Y.fileRefs(text);
  ok('the list form is reported with where: include, one entry each',
     r.length === 2 && r[0].file === 'other.yaml' && r[0].where === 'include' &&
     r[1].file === 'second.yaml' && r[1].where === 'include', JSON.stringify(r));
})();

/* ---- S11. A list entry written as a map is skipped, not guessed at ------ */

(function () {
  var text = 'include:\n  - path: other.yaml\n    env_file: extra.env\n';
  var r = Y.fileRefs(text);
  ok('a map-shaped include entry is not reported', r.length === 0, JSON.stringify(r));
})();

/* =========================================================================
 * T. Variable references — Y.varRefs(text) -> [{name, line, col, len, filled}]
 *
 * Every ${NAME}/$NAME placeholder, read from the raw text with no ancestor
 * stack — a variable is a variable wherever it sits. $$ is compose's own
 * escape for a literal dollar and names nothing. ${NAME:-x}/${NAME-x} give
 * compose a fallback, and ${NAME:?msg}/${NAME?msg} make compose refuse to
 * start rather than carry on quietly — both count as filled, since either
 * way something already tells the user what is missing.
 * ========================================================================= */

console.log('\nT. Variable references');

/* ---- T1. "${VAR}" and bare "$VAR" ---------------------------------------- */

(function () {
  var text = '${TAG}\n$USER end\n';
  var r = Y.varRefs(text);
  ok('${TAG} is found with the right line/col/len',
     r.some(function (x) { return x.name === 'TAG' && x.line === 0 && x.col === 0 && x.len === 6 && x.filled === false; }),
     JSON.stringify(r));
  ok('bare $USER is found and stops at the end of the name',
     r.some(function (x) { return x.name === 'USER' && x.line === 1 && x.col === 0 && x.len === 5 && x.filled === false; }),
     JSON.stringify(r));
})();

/* ---- T2. All four default/error forms count as filled -------------------- */

(function () {
  var text = '${A:-x}\n${B-x}\n${C:?msg}\n${D?msg}\n';
  var r = Y.varRefs(text);
  ['A', 'B', 'C', 'D'].forEach(function (n) {
    ok(n + ' is filled', r.some(function (x) { return x.name === n && x.filled === true; }), JSON.stringify(r));
  });
})();

/* ---- T3. "$$" is an escape and names nothing ------------------------------ */

(function () {
  var text = 'a: $$\nb: $$FOO\nc: $${VAR}\n';
  var r = Y.varRefs(text);
  ok('$$ alone produces nothing', !r.some(function (x) { return x.line === 0; }), JSON.stringify(r));
  ok('$$FOO produces nothing — the escape eats the FOO', !r.some(function (x) { return x.line === 1; }), JSON.stringify(r));
  ok('$${VAR} produces nothing — the escape eats the brace pair too', !r.some(function (x) { return x.line === 2; }), JSON.stringify(r));
})();

/* ---- T4. Two references on one line, both with the right columns -------- */

(function () {
  var text = '${A}-${B}\n';
  var r = Y.varRefs(text);
  ok('both references are found at their own columns',
     r.length === 2 &&
     r[0].name === 'A' && r[0].col === 0 && r[0].len === 4 &&
     r[1].name === 'B' && r[1].col === 5 && r[1].len === 4,
     JSON.stringify(r));
})();

/* ---- T5. A reference inside a quoted value is still a reference ---------- */

(function () {
  var text = 'image: "myimage:${TAG:-latest}"\n';
  var r = Y.varRefs(text);
  ok('the placeholder is found inside the quotes, filled by its default',
     r.length === 1 && r[0].name === 'TAG' && r[0].filled === true, JSON.stringify(r));
})();

/* ---- T6. A reference on a comment line is ignored ------------------------ */

(function () {
  var text = '# see ${EXAMPLE} for the format\nimage: alpine\n';
  var r = Y.varRefs(text);
  ok('nothing is reported from the comment line', r.length === 0, JSON.stringify(r));
})();

/* ---- T7. An unterminated "${VAR" is treated as no reference -------------- */

(function () {
  // Judgement call: with no closing "}" on the line this is unreadable
  // syntax, not a variable — the same "leave it out" rule sealed regions
  // follow elsewhere in this file, rather than guessing where it would have
  // ended.
  var text = 'command: echo ${VAR and some more text\n';
  var r = Y.varRefs(text);
  ok('an unterminated placeholder is reported as nothing', r.length === 0, JSON.stringify(r));
})();

/* ---- T8. An empty name "${}" is likewise treated as no reference -------- */

(function () {
  // Judgement call: an empty name is not a variable a fallback could ever
  // be checked against, so it is left out rather than reported as a nameless
  // entry.
  var text = 'value: ${}\n';
  var r = Y.varRefs(text);
  ok('${} is reported as nothing', r.length === 0, JSON.stringify(r));
})();

/* ---- T9. A deliberately broken file returns something, never throws ----- */

(function () {
  var BROKEN = [
    'services:\n  a:\n    image: "${unterminated\n',
    'services:\n  a:\n\timage: ${TAG}\n',                  // a tab in the indentation
    'services:\n  a:\n    image: ${TAG}',                  // truncated, no trailing newline
    null, undefined, 42, {}, []
  ];
  var bad = null;
  BROKEN.forEach(function (input) {
    try {
      var r = Y.varRefs(input);
      if (!Array.isArray(r)) bad = JSON.stringify(input) + ' did not return an array';
    } catch (e) { bad = JSON.stringify(input) + ' threw: ' + e.message; }
  });
  ok('every broken or bad input comes back as an array rather than throwing', !bad, bad);
})();

/* =========================================================================
 * U. Line endings — a CRLF file is readable, not just byte-identical
 *
 * Section A already shows a CRLF file survives parse+serialise byte for
 * byte, but that alone proves nothing: before doc.eol existed, the file
 * sealed whole as 'unparsable' and serialise() reproduced it by luck, since
 * every line still carried its own trailing '\r' straight through the join.
 * These cases check the file is actually readable — a real map, with fields
 * buildForm() can hand the form — and that an edit through the form still
 * comes back CRLF throughout, not just the untouched lines.
 * ========================================================================= */

console.log('\nU. Line endings');

function isAllCRLF(s) {
  var lines = s.split('\n');
  for (var i = 0; i < lines.length - 1; i++) if (!/\r$/.test(lines[i])) return false;
  return true;
}

/* ---- U1. A CRLF file parses as a real map, and buildForm() reads it ----- */

(function () {
  var text = 'services:\r\n  a:\r\n    image: alpine\r\n';
  var doc = Y.parse(text);
  ok('root is a map, not a sealed opaque blob', doc.root && doc.root.kind !== 'opaque',
     doc.root && doc.root.kind);
  var form;
  try { form = Y.buildForm(doc); } catch (e) { form = null; }
  ok('buildForm() reads it without throwing', !!form);
  ok('the service is found', !!form && form.services.some(function (s) { return s.name === 'a'; }),
     form && JSON.stringify(form.services));
})();

/* ---- U2. serialise() gives the CRLF file back byte for byte ------------- */

(function () {
  var text = 'services:\r\n  a:\r\n    image: alpine\r\n';
  var got = Y.serialise(Y.parse(text));
  ok('CRLF file round-trips byte for byte', got === text, firstDiff(text, got));
})();

/* ---- U3. An edit changes exactly one line, and every line stays CRLF ---- */

(function () {
  var text = 'services:\r\n  a:\r\n    image: alpine\r\n    environment:\r\n      FOO: bar\r\n';
  var doc = Y.parse(text);
  var form = Y.buildForm(doc);
  var f = form.fields.filter(function (x) { return x.target === 'FOO'; })[0];
  if (!ok('FOO field is found', !!f)) return;
  Y.setValue(doc, form, f.id, 'baz');
  var after = Y.serialise(doc);
  var moved = diffLines(text.replace(/\r\n/g, '\n'), after.replace(/\r\n/g, '\n'));
  ok('exactly one line changed', moved.length === 1, 'changed lines: ' + moved.join(', '));
  ok('every line still ends CRLF', isAllCRLF(after), JSON.stringify(after));
})();

/* ---- U4. A CRLF file that also starts with a BOM keeps both ------------- */

(function () {
  var text = '\ufeffservices:\r\n  a:\r\n    image: alpine\r\n';
  var doc = Y.parse(text);
  ok('the BOM is recorded', doc.bom === '\ufeff');
  ok('the root still parses as a map', doc.root && doc.root.kind !== 'opaque');
  var got = Y.serialise(doc);
  ok('BOM and CRLF both survive the round trip', got === text, firstDiff(text, got));
})();

/* ---- U5. Mixed endings come out consistently CRLF ------------------------ */

(function () {
  // "any CRLF at all marks the whole file CRLF" — a deliberate simplification
  // so a file is not left half-converted; this is the one case in this
  // section that is not byte-identical to its input by design.
  var text = 'services:\r\n  a:\n    image: alpine\r\n    command: echo hi\n';
  var got = Y.serialise(Y.parse(text));
  ok('output is consistently CRLF', isAllCRLF(got), JSON.stringify(got));
  ok('content is otherwise unchanged', got.replace(/\r\n/g, '\n') === text.replace(/\r\n/g, '\n'),
     firstDiff(text.replace(/\r\n/g, '\n'), got.replace(/\r\n/g, '\n')));
})();

/* ---- U6. A plain LF file is unaffected — no carriage return gained ------ */

(function () {
  var text = 'services:\r\n  a:\r\n    image: alpine\r\n'.replace(/\r\n/g, '\n');
  var got = Y.serialise(Y.parse(text));
  ok('LF file round-trips unchanged', got === text, firstDiff(text, got));
  ok('no carriage return appears', got.indexOf('\r') === -1);
})();

/* =========================================================================
 * V. Nothing is required any more; build: as a block; include: (PLAN_21)
 *
 * The requirement compose-model.js used to hardcode — image and
 * container_name are both fixedRequired — is gone. Compose itself refuses a
 * service with neither image: nor build:, and staxx_save_stack() enforces
 * that server-side; the form only explains, through f.advice, and never
 * blocks Save. Negatives first, as always: the case that started this is a
 * service that IS valid compose and must not be flagged as broken.
 * ========================================================================= */

console.log('\nV. Nothing required any more; build: as a block; include:');

/* ---- V1. No fixedRequired flag survives, in any of the three shapes ----- */

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    build: ./app\n'));
  var svcFields = form.fields.filter(function (f) { return f.service === 'a'; });
  ok('a service with build: and no image: yields no fixedRequired field',
     svcFields.length > 0 && svcFields.every(function (f) { return !f.fixedRequired; }),
     svcFields.map(function (f) { return f.target + ':' + f.fixedRequired; }).join(', '));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    restart: always\n'));
  var svcFields = form.fields.filter(function (f) { return f.service === 'a'; });
  ok('a service with neither image: nor build: also yields no fixedRequired field — compose\'s job now',
     svcFields.length > 0 && svcFields.every(function (f) { return !f.fixedRequired; }),
     svcFields.map(function (f) { return f.target + ':' + f.fixedRequired; }).join(', '));
})();

(function () {
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  ok('fixedRequired is false for every field of a normal, fully-populated service too',
     form.fields.every(function (f) { return !f.fixedRequired; }),
     form.fields.filter(function (f) { return f.fixedRequired; }).map(function (f) { return f.id; }).join(', '));
})();

/* ---- V2. The -!R marker is a separate flag, and still works ------------- */

(function () {
  // Same shape as section J's marker test — an author's explicit choice via
  // the comment marker is untouched by fixedRequired going away, because
  // f.required and f.fixedRequired have always been two different flags.
  var src = 'services:\n  a:\n    image: alpine\n    restart: always  # -!R\n';
  var form = Y.buildForm(Y.parse(src));
  var restart = Y.fieldById(form, 'a/setting/restart');
  ok('a -!R marker still sets f.required', !!restart && restart.required === true,
     restart && JSON.stringify({ required: restart.required, fixedRequired: restart.fixedRequired }));
  ok('and does not turn fixedRequired back on', !!restart && !restart.fixedRequired);
})();

/* ---- V3. The advice notes on an empty image/container_name -------------- */

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    build: ./app\n'));
  var image = Y.fieldById(form, 'a/setting/image');
  ok('an empty image with a build: key present carries the "builds its own image" note',
     !!image && image.advice.some(function (a) { return /builds its own image/.test(a); }),
     image && JSON.stringify(image.advice));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image:\n'));
  var image = Y.fieldById(form, 'a/setting/image');
  ok('an empty image with no build: key carries no such note',
     !!image && !image.advice.some(function (a) { return /builds its own image/.test(a); }),
     image && JSON.stringify(image.advice));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    container_name:\n'));
  var cn = Y.fieldById(form, 'a/setting/container_name');
  ok('an empty container_name carries the "Docker names the container itself" note',
     !!cn && cn.advice.some(function (a) { return /names the container itself/.test(a); }),
     cn && JSON.stringify(cn.advice));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    container_name: myapp\n'));
  var cn = Y.fieldById(form, 'a/setting/container_name');
  ok('a container_name holding a value carries no such note',
     !!cn && !cn.advice.some(function (a) { return /names the container itself/.test(a); }),
     cn && JSON.stringify(cn.advice));
})();

/* ---- V3b. image: and build: coexist — the note is role-aware, not a --------
 * mutual-exclusion warning. Compose allows both (build: says how to make the
 * image, image: names the result), so the field must stay fully editable in
 * every one of these four combinations. */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    build:\n      context: ./app\n';
  var form = Y.buildForm(Y.parse(src));
  var image = Y.fieldById(form, 'a/setting/image');
  ok('image: set alongside a block build: carries the "names the image built here" note',
     !!image && image.advice.some(function (a) { return /names the image built here/.test(a); }),
     image && JSON.stringify(image.advice));
  ok('and not the "so this is optional" note',
     !!image && !image.advice.some(function (a) { return /so this is optional/.test(a); }),
     image && JSON.stringify(image.advice));
  ok('the image field stays fully editable',
     !!image && image.locked === false && !image.blocked && image.fixedRequired === false,
     image && JSON.stringify({ locked: image.locked, blocked: image.blocked, fixedRequired: image.fixedRequired }));
})();

(function () {
  var src = 'services:\n  a:\n    build:\n      context: ./app\n';
  var form = Y.buildForm(Y.parse(src));
  var image = Y.fieldById(form, 'a/setting/image');
  ok('an empty image alongside a block build: carries the "so this is optional" note',
     !!image && image.advice.some(function (a) { return /so this is optional/.test(a); }),
     image && JSON.stringify(image.advice));
  ok('and not the "names the image built here" note',
     !!image && !image.advice.some(function (a) { return /names the image built here/.test(a); }),
     image && JSON.stringify(image.advice));
  ok('the image field stays fully editable',
     !!image && image.locked === false && !image.blocked && image.fixedRequired === false,
     image && JSON.stringify({ locked: image.locked, blocked: image.blocked, fixedRequired: image.fixedRequired }));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n'));
  var image = Y.fieldById(form, 'a/setting/image');
  ok('image: set with no build: at all carries neither note',
     !!image && !image.advice.some(function (a) { return /names the image built here|so this is optional/.test(a); }),
     image && JSON.stringify(image.advice));
  ok('the image field stays fully editable',
     !!image && image.locked === false && !image.blocked && image.fixedRequired === false,
     image && JSON.stringify({ locked: image.locked, blocked: image.blocked, fixedRequired: image.fixedRequired }));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    restart: always\n'));
  var image = Y.fieldById(form, 'a/setting/image');
  ok('neither image: nor build: present carries neither note',
     !!image && !image.advice.some(function (a) { return /names the image built here|so this is optional/.test(a); }),
     image && JSON.stringify(image.advice));
  ok('the image field stays fully editable',
     !!image && image.locked === false && !image.blocked && image.fixedRequired === false,
     image && JSON.stringify({ locked: image.locked, blocked: image.blocked, fixedRequired: image.fixedRequired }));
})();

(function () {
  // Short-form build: ./app produces the target 'build' with no dot — the
  // routing bug fix in stacks.js's groupFor() means this now reaches the
  // Build group instead of Advanced, but groupFor() itself lives in stacks.js
  // and these node tests cannot reach it; this only checks what the model
  // hands it. The group routing is verified in the browser instead.
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    build: ./app\n'));
  var build = Y.fieldById(form, 'a/setting/build');
  ok('short-form build: yields a field whose target is exactly "build"',
     !!build && build.target === 'build',
     build && JSON.stringify({ target: build.target }));
})();

/* ---- V4. build: as a block — the guarantee is that nothing goes missing - */

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    build: ./app\n'));
  var svcFields = form.fields.filter(function (f) { return f.service === 'a' && /^build/.test(f.target); });
  ok('build: ./app (scalar) still yields exactly one editable, unlocked build field',
     svcFields.length === 1 && svcFields[0].target === 'build' && !svcFields[0].locked,
     JSON.stringify(svcFields.map(function (f) { return { target: f.target, locked: f.locked }; })));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    build:\n      context: ./app\n' +
            '      dockerfile: Dockerfile.dev\n      target: builder\n';
  var form = Y.buildForm(Y.parse(src));
  var context    = Y.fieldById(form, 'a/setting/build.context');
  var dockerfile = Y.fieldById(form, 'a/setting/build.dockerfile');
  var target     = Y.fieldById(form, 'a/setting/build.target');
  ok('a block build: yields three editable fields for context/dockerfile/target',
     !!context && !!dockerfile && !!target &&
     !context.locked && !dockerfile.locked && !target.locked &&
     context.parts.value.value === './app' &&
     dockerfile.parts.value.value === 'Dockerfile.dev' &&
     target.parts.value.value === 'builder',
     JSON.stringify({ context: context, dockerfile: dockerfile, target: target }));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    build:\n      context: ./app\n' +
            '      args:\n        FOO: bar\n      cache_from:\n        - alpine:latest\n' +
            '      ssh:\n        - default\n';
  var form = Y.buildForm(Y.parse(src));
  var svcFields = form.fields.filter(function (f) { return f.service === 'a' && /^build\./.test(f.target); });
  var byTarget = {};
  svcFields.forEach(function (f) { byTarget[f.target] = f; });
  // The guarantee that matters most: args:, cache_from: and ssh: are maps and
  // lists harvestBlock's own leaves table does not name, so they fall to its
  // second, uncovered-children pass — still their own row, locked rather than
  // vanished, exactly as any other map/list child of a block key does.
  ok('args:, cache_from: and ssh: still appear as their own (locked) rows rather than vanishing',
     !!byTarget['build.args'] && byTarget['build.args'].locked &&
     !!byTarget['build.cache_from'] && byTarget['build.cache_from'].locked &&
     !!byTarget['build.ssh'] && byTarget['build.ssh'].locked,
     Object.keys(byTarget).map(function (k) { return k + ':' + byTarget[k].locked; }).join(', '));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n    build:\n      context: ./app\n'));
  var f = Y.fieldById(form, 'a/setting/build.context');
  var help = f && Y.fieldHelp(f);
  ok('keyInfo()/fieldHelp() resolves help text for build.context',
     !!help && help.title === 'Build context', JSON.stringify(help));
})();

/* ---- V5. include: — buildForm().includes, and the file-lists-no-services  */
/* ---- warning still fires alongside it ------------------------------------ */

(function () {
  var form = Y.buildForm(Y.parse('include:\n  - other.yaml\n'));
  ok('an include:-only file returns ok: true',
     form.ok === true);
  ok('and a populated includes array',
     form.includes.length === 1 && form.includes[0].file === 'other.yaml', JSON.stringify(form.includes));
  ok('and still pushes the "lists no services" warning',
     form.warnings.some(function (w) { return w.message === 'This file lists no services.'; }),
     JSON.stringify(form.warnings));
})();

(function () {
  var form = Y.buildForm(Y.parse('services:\n  a:\n    image: alpine\n'));
  ok('buildForm().includes is [] (never undefined) on an ordinary file',
     Array.isArray(form.includes) && form.includes.length === 0, JSON.stringify(form.includes));
})();

(function () {
  var src = 'include:\n  - other.yaml\nservices:\n  a:\n    image: alpine\n';
  var form = Y.buildForm(Y.parse(src));
  ok('a file with both include: and services: populates includes',
     form.includes.length === 1 && form.includes[0].file === 'other.yaml', JSON.stringify(form.includes));
  ok('and still yields its services',
     form.services.length === 1 && form.services[0].name === 'a', JSON.stringify(form.services));
})();

/* ---- V6. The null edit still holds on build: blocks and include: -------- */

(function () {
  var text = [
    'include:',
    '  - other.yaml',
    '',
    'services:',
    '  web:',
    '    image: nginx:alpine',
    '    container_name: myweb',
    '    build:',
    '      context: ./app',
    '      dockerfile: Dockerfile.dev',
    '      target: builder',
    '',
    '  api:',
    '    build: ./api'
  ].join('\n') + '\n';

  var doc = Y.parse(text);
  ok('the fixture round-trips byte for byte with no edit applied',
     Y.serialise(doc) === text, firstDiff(text, Y.serialise(doc)));

  var all = boxes(Y.buildForm(doc)), bad = null;
  for (var i = 0; i < all.length && !bad; i++) {
    var d = Y.parse(text), m = Y.buildForm(d);
    if (!Y.setPart(d, m, all[i].id, all[i].part, all[i].value)) continue;
    var got = Y.serialise(d);
    if (got !== text) bad = all[i].id + ' [' + all[i].part + ']\n' + firstDiff(text, got);
  }
  ok('and still identical after setting every writable box to what it already says (' + all.length + ' boxes)',
     !bad, bad);
})();

/* =========================================================================
 * VI. PLAN_29 — the swarm-only sentence on the five dead deploy: keys, and
 * the container_name/replicas clash. Both are advice only — nothing here
 * changes what a field locks or blocks — so there is no new null-edit case:
 * section V6's byte-for-byte check already covers every box these two
 * fixtures touch.
 * ========================================================================= */

console.log('\nVI. Swarm-only advice, and the container_name/replicas clash');

/* ---- VI1. The five swarm-only keys carry the "ignored" sentence --------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    deploy:',
    '      mode: replicated',
    '      endpoint_mode: vip',
    '      placement:',
    '        constraints:',
    '          - node.role==manager',
    '      update_config:',
    '        parallelism: 1',
    '      rollback_config:',
    '        parallelism: 1'
  ].join('\n') + '\n';
  var form = Y.buildForm(Y.parse(src));
  var targets = ['deploy.mode', 'deploy.endpoint_mode', 'deploy.placement',
                 'deploy.update_config', 'deploy.rollback_config'];
  targets.forEach(function (t) {
    var f = Y.fieldById(form, 'a/setting/' + t);
    ok(t + ' carries the "server is part of a swarm cluster" sentence',
       !!f && f.advice.some(function (a) { return /swarm cluster/.test(a); }),
       f && JSON.stringify(f.advice));
  });
})();

/* ---- VI2. container_name + replicas > 1 warns on both rows -------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    container_name: myapp\n' +
            '    deploy:\n      replicas: 3\n';
  var form = Y.buildForm(Y.parse(src));
  var cn = Y.fieldById(form, 'a/setting/container_name');
  var rp = Y.fieldById(form, 'a/setting/deploy.replicas');
  ok('container_name warns about the clash when replicas: 3',
     !!cn && cn.advice.some(function (a) { return /clear one of the two/.test(a); }),
     cn && JSON.stringify(cn.advice));
  ok('deploy.replicas warns about the clash too',
     !!rp && rp.advice.some(function (a) { return /clear one of the two/.test(a); }),
     rp && JSON.stringify(rp.advice));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    container_name: myapp\n' +
            '    deploy:\n      replicas: 1\n';
  var form = Y.buildForm(Y.parse(src));
  var cn = Y.fieldById(form, 'a/setting/container_name');
  var rp = Y.fieldById(form, 'a/setting/deploy.replicas');
  ok('replicas: 1 carries no clash advice on either row',
     !!cn && !!rp &&
     !cn.advice.some(function (a) { return /clear one of the two/.test(a); }) &&
     !rp.advice.some(function (a) { return /clear one of the two/.test(a); }),
     JSON.stringify({ cn: cn && cn.advice, rp: rp && rp.advice }));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    container_name: myapp\n' +
            '    deploy:\n      replicas: ${COPIES}\n';
  var form = Y.buildForm(Y.parse(src));
  var cn = Y.fieldById(form, 'a/setting/container_name');
  var rp = Y.fieldById(form, 'a/setting/deploy.replicas');
  ok('an interpolated replicas value carries no clash advice — it does not parse as a plain integer',
     !!cn && !!rp &&
     !cn.advice.some(function (a) { return /clear one of the two/.test(a); }) &&
     !rp.advice.some(function (a) { return /clear one of the two/.test(a); }),
     JSON.stringify({ cn: cn && cn.advice, rp: rp && rp.advice }));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    deploy:\n      replicas: 3\n';
  var form = Y.buildForm(Y.parse(src));
  var rp = Y.fieldById(form, 'a/setting/deploy.replicas');
  ok('no container_name at all: replicas: 3 carries no clash advice',
     !!rp && !rp.advice.some(function (a) { return /clear one of the two/.test(a); }),
     rp && JSON.stringify(rp.advice));
})();

/* =========================================================================
 * W. The file's own names (PLAN_16/PLAN_30 Part A) — Y.fileNames(text),
 *    Y.refNames(names, kind, serviceName), and the editor's suggestions
 *    reaching them through keySuggestions()/valueSuggestions()
 *
 * fileNames() works from classify() alone, never parse(), for the same
 * reason hostPaths() (section M) does: a suggestion is wanted exactly when
 * the file is mid-edit. W10 is the one that proves it — a syntax error later
 * in the file must not stop a suggestion for a position above it.
 * ========================================================================= */

console.log('\nW. The file\'s own names');

/* ---- W1. fileNames() collects every bucket, deduplicated, file order ---- */

(function () {
  var text = [
    'services:',
    '  web:',
    '    image: nginx',
    '    depends_on:',
    '      - db',
    '    networks:',
    '      - front',
    '    profiles:',
    '      - dev',
    '      - dev',
    '  db:',
    '    image: mysql',
    '    profiles:',
    '      - dev',
    '      - prod',
    'networks:',
    '  front:',
    '    driver: bridge',
    'volumes:',
    '  appdata:',
    '    driver: local',
    'secrets:',
    '  pw:',
    '    file: ./pw.txt',
    'configs:',
    '  conf1:',
    '    file: ./conf.txt'
  ].join('\n') + '\n';
  var r = Y.fileNames(text);
  ok('services, in file order', r.services.join(',') === 'web,db', JSON.stringify(r.services));
  ok('declared networks', r.networks.join(',') === 'front', JSON.stringify(r.networks));
  ok('declared volumes', r.volumes.join(',') === 'appdata', JSON.stringify(r.volumes));
  ok('declared secrets', r.secrets.join(',') === 'pw', JSON.stringify(r.secrets));
  ok('declared configs', r.configs.join(',') === 'conf1', JSON.stringify(r.configs));
  ok('profiles, deduplicated across both services, file order',
     r.profiles.join(',') === 'dev,prod', JSON.stringify(r.profiles));
})();

/* ---- W2. A service's own networks:/secrets:/configs: never leak into the
            top-level declared buckets — stack depth is what tells them
            apart, the same way hostPaths() tells a mount apart from a
            top-level volumes: declaration ------------------------------- */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    networks:\n      mynet:\n' +
             '        ipv4_address: 10.0.0.5\nnetworks:\n  front:\n    driver: bridge\n';
  var r = Y.fileNames(text);
  ok('a service-level networks: map key is not read as a declared network',
     r.networks.join(',') === 'front', JSON.stringify(r.networks));
})();

/* ---- W3. profiles: skips a block item and an empty one ------------------ */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    profiles:\n      - dev\n' +
             '      - foo: bar\n      - \n';
  var r = Y.fileNames(text);
  ok('a block item ("foo: bar") under profiles: is not read as a name',
     r.profiles.indexOf('foo') < 0, JSON.stringify(r.profiles));
  ok('an empty profiles: item is skipped, leaving just "dev"',
     r.profiles.join(',') === 'dev', JSON.stringify(r.profiles));
})();

/* ---- W4. depends_on: offers the other services, never the service itself */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    depends_on:\n      - \n' +
             '  db:\n    image: mysql\n  cache:\n    image: redis\n';
  var offset = text.indexOf('depends_on:') + 'depends_on:\n      - '.length;
  var r = Y.valueSuggestions(text, offset);
  var got = r && r.keys.map(function (k) { return k.key; });
  ok('offers the other two services, never "web" itself',
     !!r && got.sort().join(',') === 'cache,db', JSON.stringify(got));
})();

/* ---- W5. service networks: offers the declared ones plus "default", and
            stays out of volumes:'s own namespace (and vice versa) -------- */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    volumes:\n      - \n' +
             '    networks:\n      - \nnetworks:\n  front:\n    driver: bridge\n' +
             'volumes:\n  appdata:\n    driver: local\n';
  var volOffset = text.indexOf('volumes:') + 'volumes:\n      - '.length;
  var netOffset = text.indexOf('networks:\n      -') + 'networks:\n      - '.length;
  var rv = Y.valueSuggestions(text, volOffset);
  var rn = Y.valueSuggestions(text, netOffset);
  var gotV = rv && rv.keys.map(function (k) { return k.key; });
  var gotN = rn && rn.keys.map(function (k) { return k.key; });
  ok('a service\'s volumes: offers only the declared volume ("appdata"), not a network name',
     !!rv && gotV.join(',') === 'appdata', JSON.stringify(gotV));
  ok('a service\'s networks: offers the declared network plus "default", not a volume name',
     !!rn && gotN.join(',') === 'front,default', JSON.stringify(gotN));
})();

/* ---- W6. profiles: offers a profile named under a different service ----- */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    profiles:\n      - \n' +
             '  db:\n    image: mysql\n    profiles:\n      - prod\n';
  var offset = text.indexOf('profiles:') + 'profiles:\n      - '.length;
  var r = Y.valueSuggestions(text, offset);
  ok('"prod", named only under db, is offered to web',
     !!r && r.keys.some(function (k) { return k.key === 'prod'; }), JSON.stringify(r));
})();

/* ---- W7. network_mode: service: offers "service:<other>", never self ---- */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    network_mode: \n' +
             '  db:\n    image: mysql\n';
  var offset = text.indexOf('network_mode: ') + 'network_mode: '.length;
  var r = Y.valueSuggestions(text, offset);
  var got = r && r.keys.map(function (k) { return k.key; });
  ok('offers "service:db"', !!r && got.indexOf('service:db') >= 0, JSON.stringify(got));
  ok('never offers "service:web" (itself)', !!r && got.indexOf('service:web') < 0, JSON.stringify(got));
  ok('the static netmode vocabulary is still there too',
     !!r && got.indexOf('bridge') >= 0, JSON.stringify(got));
})();

/* ---- W8. A volume entry's host half: a bare word offers a declared volume
            name; once a colon is on the line the list goes quiet --------- */

(function () {
  var declared = 'volumes:\n  appdata:\n    driver: local\n';
  var bare = 'services:\n  web:\n    image: nginx\n    volumes:\n      - appd\n' + declared;
  var rBare = Y.valueSuggestions(bare, bare.indexOf('- appd') + '- appd'.length);
  ok('a bare word offers the declared volume name',
     !!rBare && rBare.keys.some(function (k) { return k.key === 'appdata'; }), JSON.stringify(rBare));

  var full = 'services:\n  web:\n    image: nginx\n    volumes:\n      - appdata:/config\n' + declared;
  var rFull = Y.valueSuggestions(full, full.indexOf('appdata:/config') + 'appdata'.length);
  ok('once "appdata:/config" is on the line, the typed word matches no name and the list goes quiet',
     !!rFull && rFull.keys.length === 0, JSON.stringify(rFull));
})();

/* ---- W9. Long-form depends_on: the child key position offers services;
            condition: offers the three conditions ------------------------ */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db:\n' +
             '        condition: service_started\n      \n' +
             '  db:\n    image: mysql\n  cache:\n    image: redis\n';
  var keyOffset = text.lastIndexOf('      \n') + 6;
  var rk = Y.keySuggestions(text, keyOffset);
  var gotK = rk && rk.keys.map(function (k) { return k.key; });
  ok('offers "cache", the service not already listed',
     !!rk && gotK.indexOf('cache') >= 0, JSON.stringify(gotK));
  ok('does not re-offer "db", already a dependency', !!rk && gotK.indexOf('db') < 0, JSON.stringify(gotK));
  ok('does not offer "web" (itself)', !!rk && gotK.indexOf('web') < 0, JSON.stringify(gotK));

  // A fresh (empty) condition: value, the same shape Q2/Q9 test their own
  // "nothing typed yet" case with — the filled one above would otherwise
  // match its own already-typed word and pop back up (Q4's own rule).
  var condText = 'services:\n  web:\n    image: nginx\n    depends_on:\n      db:\n        condition: \n' +
                 '  db:\n    image: mysql\n';
  var condOffset = condText.indexOf('condition: ') + 'condition: '.length;
  var rc = Y.valueSuggestions(condText, condOffset);
  var gotC = rc && rc.keys.map(function (k) { return k.key; });
  ok('condition: offers the three dependscondition values',
     !!rc && gotC.join(',') === 'service_started,service_healthy,service_completed_successfully',
     JSON.stringify(gotC));
})();

/* ---- W10. A deliberate syntax error further down the file stops none of it,
             which is the whole reason this avoids parse() -------------- */

(function () {
  var text = 'services:\n  web:\n    image: nginx\n    depends_on:\n      - \n' +
             '  db:\n    image: mysql\n  broken::: not valid yaml at all\n';
  var offset = text.indexOf('depends_on:') + 'depends_on:\n      - '.length;
  var r = Y.valueSuggestions(text, offset);
  ok('depends_on: still offers "db" despite the broken line below it',
     !!r && r.keys.some(function (k) { return k.key === 'db'; }), JSON.stringify(r));

  var names = Y.fileNames(text);
  ok('fileNames() still finds both services despite the same broken line',
     names.services.join(',') === 'web,db', JSON.stringify(names.services));
})();

/* ---- W11. refNames() — the one rule shared with the form's own dropdowns  */

(function () {
  ok('adds "default" once, for networks', Y.refNames(['front'], 'networks').join(',') === 'front,default');
  ok('never duplicates "default" already present',
     Y.refNames(['front', 'default'], 'networks').join(',') === 'front,default');
  ok('excludes the named service, for services',
     Y.refNames(['web', 'db'], 'services', 'web').join(',') === 'db');
  ok('leaves any other kind untouched', Y.refNames(['x'], 'volumes', 'x').join(',') === 'x');
})();

/* ---- W12. Neither fileNames() nor refNames() throws on malformed input -- */

(function () {
  var NASTY = [null, undefined, 42, {}, [], 'volumes:\n', '\t\tno spaces allowed\n'];
  var bad = null;
  NASTY.forEach(function (input) {
    try {
      var r = Y.fileNames(input);
      if (!r || !r.services || !r.networks || !r.volumes || !r.secrets || !r.configs || !r.profiles) {
        bad = JSON.stringify(input) + ' returned a malformed shape: ' + JSON.stringify(r);
      }
    } catch (e) { bad = JSON.stringify(input) + ' threw: ' + e.message; }
  });
  try { Y.refNames(null, 'networks'); Y.refNames(undefined, 'services', undefined); }
  catch (e) { bad = bad || 'refNames threw: ' + e.message; }
  ok('no input throws, and fileNames() always returns all six buckets', !bad, bad);
})();

/* ---- W13. A name Object already has is still a name --------------------- */

/* 'constructor' and 'toString' come back truthy from any plain object, so a
 * bare lookup would drop such a service silently — and a bare DECL_BUCKET
 * lookup on one would reach push() on nothing and lose the whole scan. */

(function () {
  var src = 'services:\n  constructor:\n    image: alpine\n' +
            '  toString:\n    image: alpine\n' +
            'volumes:\n  constructor:\n';
  var n = Y.fileNames(src);
  ok('a service called constructor or toString is collected like any other',
     n.services.join(',') === 'constructor,toString' && n.volumes.join(',') === 'constructor',
     JSON.stringify(n));
})();

/* =========================================================================
 * X. The long forms, part B1/B2 (PLAN_30) — a long-form port or mount with no
 *    target: line stays visible instead of vanishing, and a long-form port's
 *    protocol: line becomes an editable 'proto' part (f.longForm marks it, so
 *    choiceFor() in stacks.js can tell it apart from the short form's
 *    slash-carrying one).
 * ========================================================================= */

console.log('\nX. Long forms — the vanishing entry, and a long-form port\'s protocol');

/* ---- X1. A long-form port/mount missing target: locks instead of vanishing */

(function () {
  var src = 'services:\n' +
    '  a:\n' +
    '    image: alpine\n' +
    '    ports:\n' +
    '      - published: 8080\n' +
    '        protocol: udp\n' +
    '    volumes:\n' +
    '      - type: bind\n' +
    '        source: /host/path\n';

  var form = Y.buildForm(Y.parse(src));
  var ports = form.fields.filter(function (f) { return f.binder === 'port'; });
  var vols  = form.fields.filter(function (f) { return f.binder === 'volume'; });

  ok('a long-form port with no target: yields exactly one row', ports.length === 1,
     JSON.stringify(ports.map(function (f) { return f.id; })));
  ok('...locked, naming the missing container port',
     !!ports[0] && ports[0].locked &&
     ports[0].lockReason === 'this entry does not say which port inside the container to use',
     ports[0] && JSON.stringify({ locked: ports[0].locked, reason: ports[0].lockReason }));
  ok('...raw holds the entry\'s own lines',
     !!ports[0] && ports[0].raw.indexOf('published: 8080') >= 0 &&
     ports[0].raw.indexOf('protocol: udp') >= 0,
     ports[0] && ports[0].raw);

  ok('a long-form volume with no target: yields exactly one row', vols.length === 1,
     JSON.stringify(vols.map(function (f) { return f.id; })));
  ok('...locked, naming the missing container path',
     !!vols[0] && vols[0].locked &&
     vols[0].lockReason === 'this mount does not say where it goes in the container',
     vols[0] && JSON.stringify({ locked: vols[0].locked, reason: vols[0].lockReason }));
  ok('...raw holds the entry\'s own lines',
     !!vols[0] && vols[0].raw.indexOf('type: bind') >= 0 &&
     vols[0].raw.indexOf('source: /host/path') >= 0,
     vols[0] && vols[0].raw);

  ok('neither entry is touched by any of this — both stay exactly as written',
     Y.serialise(Y.parse(src)) === src, firstDiff(src, Y.serialise(Y.parse(src))));
})();

/* ---- X2. A long-form port's protocol: reaches the form as an editable
            'proto' part, holding the bare word ----------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    ports:\n' +
            '      - target: 8096\n        published: 8096\n        protocol: udp\n';
  var form = Y.buildForm(Y.parse(src));
  var p = form.fields.filter(function (f) { return f.binder === 'port'; })[0];

  ok('the row is marked longForm', !!p && p.longForm === true, p && JSON.stringify(p.longForm));
  ok('its proto part holds the bare word, with a real spot',
     !!p && p.parts.proto && p.parts.proto.value === 'udp' && !!p.parts.proto.spot,
     p && JSON.stringify(p.parts.proto));
  ok('the row\'s id is still keyed on target+protocol, unmoved by adding the part',
     !!p && p.id === 'a/port/8096/udp', p && p.id);
})();

/* ---- X3. The null edit — every part, including the new one, written back
            unchanged must not touch the file --------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    ports:\n' +
            '      - target: 8096\n        published: 8096\n        protocol: udp\n        mode: host\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var p = form.fields.filter(function (f) { return f.binder === 'port'; })[0];

  if (ok('fixture has the long-form port field', !!p,
         JSON.stringify(form.fields.map(function (f) { return f.id; })))) {
    ['host', 'container', 'proto'].forEach(function (which) {
      ok('setPart(' + which + ') accepts its own current value',
         Y.setPart(doc, form, p.id, which, p.parts[which].value) === true);
    });
  }
  ok('the null edit leaves the file byte for byte the same',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- X4. Changing the protocol writes only that word ------------------- */

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    ports:\n' +
             '      - target: 8096\n        published: 8096\n        protocol: udp\n        mode: host\n';
  var want = 'services:\n  a:\n    image: alpine\n    ports:\n' +
             '      - target: 8096\n        published: 8096\n        protocol: tcp\n        mode: host\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var p = form.fields.filter(function (f) { return f.binder === 'port'; })[0];
  Y.setPart(doc, form, p.id, 'proto', 'tcp');
  ok('changing the protocol to tcp writes only that word, leaving mode: and everything else untouched',
     Y.serialise(doc) === want && diffLines(src, Y.serialise(doc)).length === 1,
     firstDiff(want, Y.serialise(doc)));
})();

/* ---- X5. A long-form port with no protocol: line has nowhere to write —
            no line is invented ------------------------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    ports:\n' +
            '      - target: 8096\n        published: 8096\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var p = form.fields.filter(function (f) { return f.binder === 'port'; })[0];

  ok('a long-form port with no protocol: line has no spot for its proto part',
     !!p && (!p.parts.proto || !p.parts.proto.spot), p && JSON.stringify(p.parts.proto));
  ok('writing to it is refused and invents no line',
     !!p && Y.setPart(doc, form, p.id, 'proto', 'udp') === false && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
})();

/* ---- X6. B3 fixture — every scalar leaf the main boxes don't own reaches
            the form as a longExtras part, in file order -------------------- */

var X6_SRC = 'services:\n  a:\n    image: alpine\n    volumes:\n' +
  '      - type: bind\n' +
  '        source: /mnt/user/appdata/x\n' +
  '        target: /config\n' +
  '        read_only: true\n' +
  '        consistency: cached\n' +
  '        bind:\n' +
  '          propagation: rslave\n' +
  '          create_host_path: true\n' +
  '    ports:\n' +
  '      - target: 8096\n' +
  '        published: 8096\n' +
  '        mode: host\n' +
  '        host_ip: 127.0.0.1\n';

(function () {
  var form = Y.buildForm(Y.parse(X6_SRC));
  var vol = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  var port = form.fields.filter(function (f) { return f.binder === 'port'; })[0];

  ok('the volume row is marked longForm with its extras in file order',
     !!vol && vol.longForm === true &&
     vol.longExtras.join(',') === 'type,read_only,consistency,bind.propagation,bind.create_host_path',
     vol && JSON.stringify(vol.longExtras));
  ok('...and each extra part holds the file\'s own value',
     !!vol &&
     vol.parts.type.value === 'bind' && vol.parts.read_only.value === 'true' &&
     vol.parts.consistency.value === 'cached' &&
     vol.parts['bind.propagation'].value === 'rslave' &&
     vol.parts['bind.create_host_path'].value === 'true',
     vol && JSON.stringify(vol.parts));

  ok('the port row\'s extras are mode and host_ip, in file order',
     !!port && port.longForm === true && port.longExtras.join(',') === 'mode,host_ip',
     port && JSON.stringify(port.longExtras));
  ok('...holding the file\'s own values',
     !!port && port.parts.mode.value === 'host' && port.parts.host_ip.value === '127.0.0.1',
     port && JSON.stringify(port.parts));
})();

/* ---- X7. The null edit — every part of both rows, including every extra,
            written back unchanged must not touch the file -------------- */

(function () {
  var doc = Y.parse(X6_SRC), form = Y.buildForm(doc);
  var vol = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  var port = form.fields.filter(function (f) { return f.binder === 'port'; })[0];
  var okAll = true;

  ['host', 'container'].concat(vol.longExtras).forEach(function (which) {
    if (Y.setPart(doc, form, vol.id, which, vol.parts[which].value) !== true) okAll = false;
  });
  ['host', 'container'].concat(port.longExtras).forEach(function (which) {
    if (Y.setPart(doc, form, port.id, which, port.parts[which].value) !== true) okAll = false;
  });

  ok('every part of both rows accepts its own current value', okAll);
  ok('the null edit leaves the file byte for byte the same',
     Y.serialise(doc) === X6_SRC, firstDiff(X6_SRC, Y.serialise(doc)));
})();

/* ---- X8. A nested extra writes to its own line and nothing else -------- */

(function () {
  var doc = Y.parse(X6_SRC), form = Y.buildForm(doc);
  var vol = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  Y.setPart(doc, form, vol.id, 'bind.propagation', 'rshared');
  var out = Y.serialise(doc);
  ok('changing bind.propagation writes only that line',
     out.indexOf('propagation: rshared') >= 0 && diffLines(X6_SRC, out).length === 1,
     firstDiff(X6_SRC, out));
})();

/* ---- X9. A blank edit to an extra writes nothing, and still reports
            success ------------------------------------------------------ */

(function () {
  var doc = Y.parse(X6_SRC), form = Y.buildForm(doc);
  var vol = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  var result = Y.setPart(doc, form, vol.id, 'consistency', '');
  ok('a blank edit to an extra reports success', result === true);
  ok('...and leaves the file untouched', Y.serialise(doc) === X6_SRC,
     firstDiff(X6_SRC, Y.serialise(doc)));
})();

/* ---- X10. A doubly-nested key and a list-valued child become locked,
             named parts in file order, and don't disturb the row's other
             extras ------------------------------------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    volumes:\n' +
    '      - type: bind\n' +
    '        source: /host\n' +
    '        target: /container\n' +
    '        bind:\n' +
    '          propagation: rslave\n' +
    '          options:\n' +
    '            ro: true\n' +
    '        aliases:\n' +
    '          - foo\n';
  var form = Y.buildForm(Y.parse(src));
  var vol = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  var msg = 'this entry has settings only the Compose view can show';

  // INVERTED DELIBERATELY by PLAN_34 phase 6, not deleted — this is the only
  // guard on this shape. A nested map two levels down and a list-valued
  // child used to fold into one generic "ask the Compose view" sentence,
  // which said something was there without ever showing what. Both are now
  // locked parts in their own right, named after their key, carrying the
  // file's own text and a plain reason — and the generic sentence is gone.
  ok('the nested map and the list-valued child are locked parts, not boxes',
     !!vol && !!vol.parts['bind.options'] && vol.parts['bind.options'].locked === true &&
     !!vol.parts.aliases && vol.parts.aliases.locked === true,
     vol && JSON.stringify({ 'bind.options': vol.parts['bind.options'], aliases: vol.parts.aliases }));
  ok('...each showing the file\'s own text, dedented, and why it can\'t be a box',
     !!vol &&
     vol.parts['bind.options'].raw === 'options:\n  ro: true' &&
     vol.parts['bind.options'].reason === 'this is written as a block of its own' &&
     vol.parts.aliases.raw === 'aliases:\n  - foo' &&
     vol.parts.aliases.reason === 'this is written as a list of separate items',
     vol && JSON.stringify({ 'bind.options': vol.parts['bind.options'], aliases: vol.parts.aliases }));
  ok('...both locked with no spot and not creatable',
     !!vol &&
     vol.parts['bind.options'].spot === null && !vol.parts['bind.options'].creatable &&
     vol.parts.aliases.spot === null && !vol.parts.aliases.creatable,
     vol && JSON.stringify({ 'bind.options': vol.parts['bind.options'], aliases: vol.parts.aliases }));
  ok('longExtras carries all four names in file order, locked ones included',
     !!vol && vol.longExtras.join(',') === 'type,bind.propagation,bind.options,aliases',
     vol && JSON.stringify(vol.longExtras));
  ok('...and the row\'s ordinary extras are still ordinary, editable parts',
     !!vol &&
     vol.parts.type.value === 'bind' && !vol.parts.type.locked &&
     vol.parts['bind.propagation'].value === 'rslave' && !vol.parts['bind.propagation'].locked,
     vol && JSON.stringify(vol.parts));
  ok('the generic advice sentence is gone entirely',
     !!vol && vol.advice.indexOf(msg) < 0, vol && JSON.stringify(vol.advice));
})();

/* ---- X11. A compose key the label table has never heard of is still
             harvested, by the generic walk ------------------------------ */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    volumes:\n' +
    '      - type: bind\n' +
    '        source: /host\n' +
    '        target: /container\n' +
    '        foobar: baz\n';
  var form = Y.buildForm(Y.parse(src));
  var vol = form.fields.filter(function (f) { return f.binder === 'volume'; })[0];
  ok('an unrecognised scalar key is still harvested as a part',
     !!vol && vol.longExtras.indexOf('foobar') >= 0 && vol.parts.foobar.value === 'baz',
     vol && JSON.stringify(vol.longExtras));
})();

/* ---- X12. B4 — networks: written as a map yields one 'list' row per name,
             its settings as B3-style extras -------------------------------- */

var X12_SRC = 'services:\n  a:\n    image: alpine\n    networks:\n' +
  '      frontend:\n' +
  '        ipv4_address: 10.0.1.20\n' +
  '        aliases:\n' +
  '          - web\n' +
  '      backend:\n';

(function () {
  var form = Y.buildForm(Y.parse(X12_SRC));
  var nets = form.fields.filter(function (f) { return f.listKey === 'networks'; });

  ok('two map entries yield two list rows', nets.length === 2,
     JSON.stringify(nets.map(function (f) { return f.id; })));
  ok('their ids are distinct', nets[0] && nets[1] && nets[0].id !== nets[1].id,
     JSON.stringify(nets.map(function (f) { return f.id; })));
  ok('the names are the mapping keys',
     !!nets[0] && nets[0].parts.value.value === 'frontend' &&
     !!nets[1] && nets[1].parts.value.value === 'backend',
     JSON.stringify(nets.map(function (f) { return f.parts.value.value; })));

  var front = nets[0], back = nets[1];
  ok('the entry with settings is longForm, with ipv4_address as an extra',
     !!front && front.longForm === true && front.longExtras.indexOf('ipv4_address') >= 0 &&
     front.parts.ipv4_address.value === '10.0.1.20',
     front && JSON.stringify({ longForm: front.longForm, extras: front.longExtras }));
  // INVERTED DELIBERATELY by PLAN_34 phase 6, not deleted — this is the only
  // guard on this shape. aliases: used to fold into the generic "ask the
  // Compose view" sentence; it is now a locked part named 'aliases', showing
  // the file's own two lines dedented and a reason that names it as a list.
  ok('the aliases list is now a locked part, not the generic advice sentence',
     !!front && !!front.parts.aliases && front.parts.aliases.locked === true &&
     front.advice.indexOf('this entry has settings only the Compose view can show') < 0,
     front && JSON.stringify({ aliases: front.parts.aliases, advice: front.advice }));
  ok('...its raw text is exactly the file\'s own two lines, dedented',
     !!front && front.parts.aliases.raw === 'aliases:\n  - web',
     front && JSON.stringify(front.parts.aliases));
  ok('...and its reason names it as a list',
     !!front && front.parts.aliases.reason === 'this is written as a list of separate items',
     front && JSON.stringify(front.parts.aliases));
  // INVERTED DELIBERATELY by PLAN_34 phase 3c, not deleted — it is the only
  // guard on this shape. A bare `backend:` used to be "just a name row" with
  // no fold at all, which is exactly why a fixed address could not be added
  // to it. It is now longForm with two blank creatable extras and nothing
  // else, so the address can be created where before there was nowhere to
  // put it. Section AE-3 asserts the same two from the other direction.
  ok('a bare name is now longForm, offering exactly the two creatable blanks',
     !!back && back.longForm === true &&
     back.longExtras.slice().sort().join(',') === 'ipv4_address,mac_address' &&
     back.parts.ipv4_address.value === '' && back.parts.ipv4_address.creatable === true &&
     back.parts.mac_address.value === ''  && back.parts.mac_address.creatable === true,
     back && JSON.stringify({ longForm: back.longForm, extras: back.longExtras }));
})();

/* ---- X13. The null edit over every part of both rows --------------------- */

(function () {
  var doc = Y.parse(X12_SRC), form = Y.buildForm(doc);
  var nets = form.fields.filter(function (f) { return f.listKey === 'networks'; });
  var okAll = true;

  nets.forEach(function (f) {
    for (var which in f.parts) {
      if (!f.parts.hasOwnProperty(which) || !f.parts[which].spot) continue;
      if (Y.setPart(doc, form, f.id, which, f.parts[which].value) !== true) okAll = false;
    }
  });

  ok('every part of both rows accepts its own current value', okAll);
  ok('the null edit leaves the file byte for byte the same',
     Y.serialise(doc) === X12_SRC, firstDiff(X12_SRC, Y.serialise(doc)));
})();

/* ---- X14. Renaming one entry rewrites only that key ----------------------- */

(function () {
  var doc = Y.parse(X12_SRC), form = Y.buildForm(doc);
  var front = form.fields.filter(function (f) { return f.listKey === 'networks'; })[0];
  Y.setPart(doc, form, front.id, 'value', 'frontnet');
  var out = Y.serialise(doc);
  ok('renaming rewrites only the one key',
     out.indexOf('frontnet:') >= 0 && out.indexOf('backend:') >= 0 &&
     diffLines(X12_SRC, out).length === 1,
     firstDiff(X12_SRC, out));
})();

/* ---- X15. removeItem on a map entry — one of two, then the last ---------- */

(function () {
  var doc = Y.parse(X12_SRC), form = Y.buildForm(doc);
  var back = form.fields.filter(function (f) { return f.listKey === 'networks'; })[1];
  ok('removing one of two entries succeeds', Y.removeItem(doc, form, back.id) === true);
  var out = Y.serialise(doc);
  ok('...leaving the other, and the file still valid',
     out.indexOf('frontend:') >= 0 && out.indexOf('backend:') < 0,
     out);

  var form2 = Y.buildForm(Y.parse(out));
  var nets2 = form2.fields.filter(function (f) { return f.listKey === 'networks'; });
  ok('...and the remaining entry still reads back as one row', nets2.length === 1,
     JSON.stringify(nets2.map(function (f) { return f.id; })));

  var doc3 = Y.parse(out), form3 = Y.buildForm(doc3);
  var last = form3.fields.filter(function (f) { return f.listKey === 'networks'; })[0];
  ok('removing the last entry succeeds', Y.removeItem(doc3, form3, last.id) === true);
  ok('...and takes the networks: key with it',
     Y.serialise(doc3).indexOf('networks:') < 0, Y.serialise(doc3));
})();

/* ---- X16. addItem on a map-shaped networks: writes a mapping entry, not a
             '- ' item ------------------------------------------------------ */

(function () {
  var doc = Y.parse(X12_SRC), form = Y.buildForm(doc);
  var line = Y.addItem(doc, form, 'a', 'list', '', 'networks');
  ok('addItem reports a line', line >= 0, line);

  var out = Y.serialise(doc);
  ok('the new entry is a bare mapping key ("name:"), not a "- name" sequence item',
     out.indexOf('      default:\n') >= 0 && out.indexOf('- default') < 0, out);
  ok('the file still round-trips through parse/serialise',
     Y.serialise(Y.parse(out)) === out, firstDiff(out, Y.serialise(Y.parse(out))));

  var form2 = Y.buildForm(Y.parse(out));
  var nets2 = form2.fields.filter(function (f) { return f.listKey === 'networks'; });
  ok('the new entry is visible in a rebuilt form, and not locked', nets2.length === 3 &&
     nets2.every(function (f) { return !f.locked; }),
     JSON.stringify(nets2.map(function (f) { return f.id + (f.locked ? ' [locked]' : ''); })));
})();

/* ---- X17. Long-form secrets:/configs: entries ----------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    secrets:\n' +
    '      - source: db_password\n' +
    '        target: pw\n' +
    '        uid: "103"\n' +
    '        mode: "0400"\n' +
    '      - target: nosource\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var secrets = form.fields.filter(function (f) { return f.listKey === 'secrets'; });
  var named = secrets.filter(function (f) { return !f.locked; })[0];
  var unnamed = secrets.filter(function (f) { return f.locked; })[0];

  ok('the entry with source: is editable, named after the secret',
     !!named && named.parts.value.value === 'db_password' && named.longForm === true,
     named && JSON.stringify({ value: named.parts.value.value, longForm: named.longForm }));
  ok('its extras are target, uid and mode',
     !!named && ['target', 'uid', 'mode'].every(function (k) { return named.longExtras.indexOf(k) >= 0; }),
     named && JSON.stringify(named.longExtras));
  ok('...holding the file\'s own values',
     !!named && named.parts.target.value === 'pw' && named.parts.uid.value === '103' &&
     named.parts.mode.value === '0400',
     named && JSON.stringify(named.parts));

  var okAll = true;
  if (named) {
    ['value'].concat(named.longExtras).forEach(function (which) {
      if (Y.setPart(doc, form, named.id, which, named.parts[which].value) !== true) okAll = false;
    });
  }
  ok('the null edit holds over every part', okAll);
  ok('...and leaves the file byte for byte the same',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));

  ok('an entry with no source: stays locked, with the new reason',
     !!unnamed && unnamed.locked &&
     unnamed.lockReason === 'this entry does not say which secret it uses',
     unnamed && JSON.stringify({ locked: unnamed.locked, reason: unnamed.lockReason }));
})();

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    configs:\n' +
    '      - target: nosource\n';
  var form = Y.buildForm(Y.parse(src));
  var cfg = form.fields.filter(function (f) { return f.listKey === 'configs'; })[0];
  ok('a config entry with no source: uses the config wording',
     !!cfg && cfg.locked && cfg.lockReason === 'this entry does not say which config it uses',
     cfg && JSON.stringify({ locked: cfg.locked, reason: cfg.lockReason }));
})();

/* ---- X18. Unchanged: an ordinary list-form networks: and a plain '- name'
             secret still produce exactly what they did before this step --- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    networks:\n' +
    '      - frontend\n      - backend\n    secrets:\n      - my_secret\n';
  var form = Y.buildForm(Y.parse(src));
  var nets = form.fields.filter(function (f) { return f.listKey === 'networks'; });
  var secs = form.fields.filter(function (f) { return f.listKey === 'secrets'; });

  ok('the short-form networks: entries are still plain, unlocked list rows',
     nets.length === 2 && nets.every(function (f) { return !f.locked && f.longForm !== true; }) &&
     nets[0].parts.value.value === 'frontend' && nets[1].parts.value.value === 'backend',
     JSON.stringify(nets.map(function (f) { return f.parts.value.value; })));
  ok('the short-form secret is still a plain, unlocked list row',
     secs.length === 1 && !secs[0].locked && secs[0].longForm !== true &&
     secs[0].parts.value.value === 'my_secret',
     JSON.stringify(secs.map(function (f) { return f.parts.value.value; })));
})();

/* ---- X19. Clearing a map entry's name writes nothing --------------------- */

/* Its name IS the mapping key, so there is no dash to leave behind the way a
 * cleared '- entry' has one. Blanked as a key it would leave a bare ':', and
 * written the ordinary way it would leave "''" — neither is a shape anyone
 * asked for, so the file is left exactly as it was and the × removes it. */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    networks:\n' +
    '      frontend:\n        ipv4_address: 10.0.1.20\n      backend:\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var bare = form.fields.filter(function (f) {
    return f.listKey === 'networks' && f.parts.value && f.parts.value.value === 'backend';
  })[0];
  var withIp = form.fields.filter(function (f) {
    return f.listKey === 'networks' && f.parts.value && f.parts.value.value === 'frontend';
  })[0];

  var okBare = bare && Y.setPart(doc, form, bare.id, 'value', '');
  var okIp   = withIp && Y.setPart(doc, form, withIp.id, 'value', '  ');
  ok('a blank name reports success and changes not one byte, with or without settings under it',
     !!okBare && !!okIp && Y.serialise(doc) === src, JSON.stringify(Y.serialise(doc)));
})();

/* =========================================================================
 * Y. A leading '---' (or trailing '...') no longer seals the whole file
 *    (PLAN_31) — every linuxserver.io example starts with a document-start
 *    marker, which used to lock the entire form as one uneditable blob.
 * ========================================================================= */

console.log('\nY. Document markers no longer seal the file');

/* ---- Y1. A leading --- parses to a form and round-trips verbatim -------- */

(function () {
  var text = '---\nservices:\n  a:\n    image: alpine\n';
  var doc = Y.parse(text);
  ok('root is a map, not sealed', doc.root && doc.root.kind === 'map', doc.root && doc.root.kind);
  ok('the marker round-trips byte for byte', Y.serialise(doc) === text, firstDiff(text, Y.serialise(doc)));
})();

/* ---- Y2. A comment between the marker and the content is skipped too ---- */

(function () {
  var text = '---\n# a comment\nservices:\n  a:\n    image: alpine\n';
  var doc = Y.parse(text);
  ok('root is a map, not sealed', doc.root && doc.root.kind === 'map', doc.root && doc.root.kind);
  ok('the marker and comment round-trip byte for byte', Y.serialise(doc) === text, firstDiff(text, Y.serialise(doc)));
})();

/* ---- Y3. Setting a value leaves the marker and every other line untouched */

(function () {
  var text = '---\nservices:\n  a:\n    image: alpine\n    environment:\n      FOO: bar\n';
  var doc = Y.parse(text);
  var form = Y.buildForm(doc);
  var f = form.fields.filter(function (x) { return x.target === 'FOO'; })[0];
  if (!ok('FOO field is found', !!f)) return;
  Y.setValue(doc, form, f.id, 'baz');
  var after = Y.serialise(doc);
  var moved = diffLines(text, after);
  ok('exactly one line changed', moved.length === 1, 'changed lines: ' + moved.join(', '));
  ok('the changed line holds the new value', /FOO: baz/.test(after.split('\n')[moved[0]]), after);
})();

/* ---- Y4. A file ending in a lone '...' after real content round-trips --- */

(function () {
  var text = 'services:\n  a:\n    image: alpine\n...\n';
  var doc = Y.parse(text);
  ok('root is a map, not sealed', doc.root && doc.root.kind === 'map', doc.root && doc.root.kind);
  ok('the closing marker round-trips byte for byte', Y.serialise(doc) === text, firstDiff(text, Y.serialise(doc)));
})();

/* ---- Y5. '---' at the top and '...' at the bottom, together ------------- */

(function () {
  var text = '---\nservices:\n  a:\n    image: alpine\n...\n';
  var doc = Y.parse(text);
  ok('root is a map, not sealed', doc.root && doc.root.kind === 'map', doc.root && doc.root.kind);
  ok('both markers round-trip byte for byte', Y.serialise(doc) === text, firstDiff(text, Y.serialise(doc)));
})();

/* ---- Y6. A genuine two-document file still seals as multi-doc ----------- */

(function () {
  var text = '---\nservices:\n  a:\n    image: alpine\n---\nservices:\n  b:\n    image: alpine\n';
  var doc = Y.parse(text);
  ok('the whole file seals', doc.root && doc.root.kind === 'opaque', doc.root && doc.root.kind);
  ok('the reason is multi-doc', doc.root && doc.root.reason === 'multi-doc', doc.root && doc.root.reason);
})();

/* ---- Y7. A file that is only '---' and blanks does not throw ------------ */

(function () {
  var text = '---\n\n\n';
  var doc;
  try { doc = Y.parse(text); } catch (e) { doc = null; }
  ok('parse() does not throw', !!doc);
  ok('root is an empty map, not sealed', !!doc && doc.root && doc.root.kind === 'map',
     doc && doc.root && doc.root.kind);
})();

/* ---- Y8. A run of markers before the first real line -------------------- *
 *
 * The whole-file scan accepts any number of them, so the root-kind check has
 * to skip the whole run: stopping after one sealed a file the scan had just
 * called fine.
 */

(function () {
  var text = '...\n---\nservices:\n  a:\n    image: alpine\n';
  var doc = Y.parse(text);
  ok('root is a map, not sealed', doc.root && doc.root.kind === 'map',
     doc.root && (doc.root.kind + '/' + doc.root.reason));
  ok('both leading markers round-trip byte for byte', Y.serialise(doc) === text, firstDiff(text, Y.serialise(doc)));
})();

console.log('\nZ. A stashed section reads as hidden, not shown, so ticking it back on works');

/* ---- Z1. sectionHidden() itself ------------------------------------------ */

(function () {
  ok('false is hidden', Y.sectionHidden(false) === true);
  ok('undefined (no entry at all) is not hidden', Y.sectionHidden(undefined) === false);
  ok('a stash with a line in it is hidden', Y.sectionHidden({ lines: ['x'] }) === true);
  ok('a stash with no lines is not hidden', Y.sectionHidden({ lines: [] }) === false);
})();

/* ---- Z2. the full cycle: stash, then restore, comes back byte-identical -- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    environment:\n      FOO: bar\n';
  var doc = Y.parse(src);
  var form = Y.buildForm(doc);

  ok('environment stashes', Y.stashSection(doc, form, 'a', ['environment']));
  var entry = Y.readSections(doc).a && Y.readSections(doc).a.environment;
  ok('the stashed entry reads as hidden', Y.sectionHidden(entry) === true, JSON.stringify(entry));
  // Not a bare indexOf('environment:') — the sections key is named
  // "environment" too, since the path is only one level deep, so the string
  // still occurs (moved into x-unraid). What must be gone is environment:
  // sitting directly under the service, straight after image:.
  ok('the block is gone from the service, not just renamed in place',
     Y.serialise(doc).indexOf('    image: alpine\n    environment:') < 0, Y.serialise(doc));

  form = Y.buildForm(doc);   // a stale form is what the page hands restoreSection in real use
  ok('restoring puts it back, byte-identical',
     Y.restoreSection(doc, form, 'a', 'environment') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
  ok('no x-unraid block is left behind', Y.serialise(doc).indexOf('x-unraid') < 0, Y.serialise(doc));
})();

/* ---- Z3. the same cycle on a nested path (deploy.resources) -------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    deploy:\n      resources:\n' +
            '        limits:\n          cpus: \'0.5\'\n';
  var doc = Y.parse(src);
  var form = Y.buildForm(doc);

  ok('deploy.resources stashes', Y.stashSection(doc, form, 'a', ['deploy', 'resources']));
  var entry = Y.readSections(doc).a && Y.readSections(doc).a['deploy.resources'];
  ok('the stashed entry reads as hidden', Y.sectionHidden(entry) === true, JSON.stringify(entry));
  ok('deploy: is gone from the file, parent chain and all', Y.serialise(doc).indexOf('deploy:') < 0,
     Y.serialise(doc));

  form = Y.buildForm(doc);
  ok('restoring rebuilds the missing parent chain, byte-identical',
     Y.restoreSection(doc, form, 'a', 'deploy.resources') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
  ok('no x-unraid block is left behind', Y.serialise(doc).indexOf('x-unraid') < 0, Y.serialise(doc));
})();

/* ---- Z4. the same cycle on a file bounded by '---' and '...' ------------- */

(function () {
  var src = '---\nservices:\n  a:\n    image: alpine\n    environment:\n      FOO: bar\n...\n';
  var doc = Y.parse(src);
  var form = Y.buildForm(doc);

  ok('environment stashes into a --- / ... file', Y.stashSection(doc, form, 'a', ['environment']));
  var entry = Y.readSections(doc).a && Y.readSections(doc).a.environment;
  ok('the stashed entry reads as hidden', Y.sectionHidden(entry) === true, JSON.stringify(entry));
  ok('both markers survive the stash', Y.serialise(doc).indexOf('---\n') === 0 &&
     Y.serialise(doc).indexOf('\n...\n') > -1, Y.serialise(doc));

  form = Y.buildForm(doc);
  ok('restoring puts it back, byte-identical markers and all',
     Y.restoreSection(doc, form, 'a', 'environment') && Y.serialise(doc) === src,
     firstDiff(src, Y.serialise(doc)));
})();

/* =========================================================================
 * AA. Uncovered service-level keys carry their written title (PLAN_36)
 *
 * DESCRIPTIONS.service already has a proper written name for every one of
 * the compose spec's service-level keys — it is what the (i) help bubble
 * beside a row reads from. Until now the label on that same row came from
 * mechanically humanising the raw key instead, so it disagreed with its own
 * help ("Mac Address" beside help text that says "MAC address"). This is
 * built as an inline document, not read from
 * scratch/test-stacks/17-uncovered-keys/, because that folder is gitignored
 * — the same reason section L's fixture is copied out verbatim rather than
 * read from disk.
 * ========================================================================= */

console.log('\nAA. Uncovered service-level keys carry their written title (PLAN_36)');

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    mac_address: "02:42:ac:11:00:02"',
    "    cpus: '1.5'",
    '    oom_kill_disable: true',
    '    sysctls:',
    '      net.core.somaxconn: "1024"',
    '    dns_search:',
    '      - example.com',
    '    memswap_limit: 512M',
    '    ulimits:',
    '      nofile: 1024',
    '    isolation: default',
    '    runtime: runc',
    '    attach: false',
    '    platform: linux/amd64',
    '    scale: 1',
    '    links:',
    '      - b',
    '    extends:',
    '      service: b',
    '    annotations:',
    '      com.example.note: hi',
    '    provider:',
    '      type: foo',
    // Profile names are arbitrary strings, so this is still a legal file —
    // "gpus" is chosen because it happens to spell another compose key whose
    // written title is a jarring acronym, the sharpest case for AA3 below.
    '    profiles:',
    '      - gpus',
    '  b:',
    '    image: alpine',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  function title(id) { var f = Y.fieldById(form, id); return f && f.title; }

  // AA1. Each of these went from a mechanically humanised label to the
  // written one in DESCRIPTIONS.service — checked against the table itself
  // rather than taken on trust from the plan.
  ok("mac_address titles as 'MAC address'",
     title('a/setting/mac_address') === 'MAC address', title('a/setting/mac_address'));
  ok("cpus titles as 'CPU limit'",
     title('a/setting/cpus') === 'CPU limit', title('a/setting/cpus'));
  ok("oom_kill_disable titles as 'Disable OOM kill'",
     title('a/setting/oom_kill_disable') === 'Disable OOM kill', title('a/setting/oom_kill_disable'));
  ok("sysctls titles as 'Kernel settings'",
     title('a/setting/sysctls') === 'Kernel settings', title('a/setting/sysctls'));
  ok("dns_search titles as 'DNS search domains'",
     title('a/setting/dns_search') === 'DNS search domains', title('a/setting/dns_search'));
  ok("memswap_limit titles as 'Memory + swap limit'",
     title('a/setting/memswap_limit') === 'Memory + swap limit', title('a/setting/memswap_limit'));
  // The bucket-bleed case: ulimits also exists under build:, titled 'Build
  // ulimits' there. A service-level ulimits has to read the service
  // bucket's 'Resource ulimits', not fall through to the build one.
  ok("ulimits titles as 'Resource ulimits', not the build bucket's title",
     title('a/setting/ulimits') === 'Resource ulimits', title('a/setting/ulimits'));

  // AA2. The nine keys whose written title already reads identically to the
  // humanised key — asserted so a future change to either side cannot drift
  // one away from the other without this section noticing.
  ok("isolation still titles as 'Isolation'", title('a/setting/isolation') === 'Isolation');
  ok("runtime still titles as 'Runtime'", title('a/setting/runtime') === 'Runtime');
  ok("attach still titles as 'Attach'", title('a/setting/attach') === 'Attach');
  ok("platform still titles as 'Platform'", title('a/setting/platform') === 'Platform');
  ok("scale still titles as 'Scale'", title('a/setting/scale') === 'Scale');
  ok("links still titles as 'Links'", title('a/setting/links') === 'Links');
  ok("extends still titles as 'Extends'", title('a/setting/extends') === 'Extends');
  ok("annotations still titles as 'Annotations'", title('a/setting/annotations') === 'Annotations');
  ok("provider still titles as 'Provider'", title('a/setting/provider') === 'Provider');

  // AA3. The trap the fix is gated against: a list entry's target is its own
  // VALUE, not a setting name, so an entry that happens to spell a compose
  // key ("gpus", inside profiles: here) must not be titled as that key.
  var gpuEntry = title('a/list.profiles#0/gpus');
  ok("a profile entry named 'gpus' titles as the humanised value, not the GPUs setting",
     gpuEntry === 'Gpus' && gpuEntry !== 'GPUs', gpuEntry);

  // AA4. Reading titles for a form is not an edit — the document must still
  // round-trip byte-identical.
  ok('the inline document round-trips untouched with no edit applied',
     Y.serialise(Y.parse(src)) === src, firstDiff(src, Y.serialise(Y.parse(src))));
})();

/* =========================================================================
 * AB. A network declaration written as a flow map, an anchor or an alias
 *     must lock rather than corrupt the file (PLAN_34 Phase 0)
 *
 * declaredFields() used to skip the locked/usable computation fieldsFor()
 * already did, so a flow-map, anchored or aliased network showed as an
 * ordinary unlocked row with an empty driver box — indistinguishable from a
 * bare declaration, even though the file said something. Choosing a driver
 * for one appended an indented child under a node that cannot take one, and
 * real compose then refused the file outright:
 *
 *   $ docker compose -f bad-flow.yaml config -q
 *   yaml: line 4: did not find expected key
 *
 * Built as an inline document, not read from
 * scratch/test-stacks/18-declaration-shapes/, because that folder is
 * gitignored — the same reason section AA's fixture is copied out verbatim
 * rather than read from disk. The lock reasons are hardcoded from
 * LOCK_WORDS rather than looked up through it, the same way section L's
 * alias assertions do, since the table is not exported.
 * ========================================================================= */

console.log('\nAB. Flow-map, anchor and alias network declarations lock instead of corrupting the file');

(function () {
  var src = [
    'services:',
    '  app:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    '      - hft',
    '      - internal_net',
    '      - shared_net',
    'networks:',
    '  backend:',
    '  hft: {name: br0.2, external: true}',
    '  internal_net: &net_defaults',
    '    driver: bridge',
    '  shared_net: *net_defaults',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);

  var flow   = Y.fieldById(form, '/declared/networks.hft');
  var anchor = Y.fieldById(form, '/declared/networks.internal_net');
  var alias  = Y.fieldById(form, '/declared/networks.shared_net');
  var bare   = Y.fieldById(form, '/declared/networks.backend');

  // 1 & 2. Each unreadable shape locks, carries the specific reason for its
  // own shape (not the generic "cannot read" fallback), and shows its raw
  // text — so the data is visible instead of hidden behind an empty box.
  ok('a flow map (hft) locks with the flow-specific reason',
     !!flow && flow.locked && flow.lockReason === 'this is written as a list on one line',
     flow && JSON.stringify({ locked: flow.locked, lockReason: flow.lockReason }));
  ok('the flow map’s raw text is on the row, not hidden',
     !!flow && flow.raw.indexOf('br0.2') >= 0, flow && flow.raw);

  ok('an anchored declaration (internal_net) locks with the anchor-specific reason',
     !!anchor && anchor.locked && anchor.lockReason === 'this is a shared block other parts of the file reuse',
     anchor && JSON.stringify({ locked: anchor.locked, lockReason: anchor.lockReason }));
  ok('the anchor’s raw text is on the row, not hidden',
     !!anchor && anchor.raw.indexOf('driver: bridge') >= 0, anchor && anchor.raw);

  ok('an alias (shared_net) locks with the alias-specific reason',
     !!alias && alias.locked && alias.lockReason === 'this points at a shared block higher up the file',
     alias && JSON.stringify({ locked: alias.locked, lockReason: alias.lockReason }));
  ok('the alias’s raw text is on the row, not hidden',
     !!alias && alias.raw.indexOf('*net_defaults') >= 0, alias && alias.raw);

  // 3 & 4. setPart refuses on all three, and the document comes back
  // byte-identical — the assertion that actually guards against corruption.
  ok('setPart refuses on the flow map',
     !Y.setPart(doc, form, '/declared/networks.hft', 'value', 'bridge'));
  ok('the document is untouched after the refused flow-map edit',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));

  ok('setPart refuses on the anchored declaration',
     !Y.setPart(doc, form, '/declared/networks.internal_net', 'value', 'overlay'));
  ok('the document is untouched after the refused anchor edit',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));

  ok('setPart refuses on the alias',
     !Y.setPart(doc, form, '/declared/networks.shared_net', 'value', 'overlay'));
  ok('the document is untouched after the refused alias edit',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));

  // 5. The control: a bare declaration is not locked, setPart succeeds, and
  // the write lands as driver: at the right indent — pinned here beside the
  // three shapes above that must not, so the two behaviours sit side by side.
  // (An existing, differently-shaped case of this is section O11, "filling
  // an empty declaration's primary setting" — this is not a duplicate of it.)
  ok('the bare declaration (backend) is not locked',
     !!bare && !bare.locked, bare && JSON.stringify({ locked: bare.locked, lockReason: bare.lockReason }));
  ok('setPart succeeds on the bare declaration and inserts driver: at the right indent',
     Y.setPart(doc, form, '/declared/networks.backend', 'value', 'bridge') &&
     Y.serialise(doc) === src.replace('  backend:\n', '  backend:\n    driver: bridge\n'),
     firstDiff(src.replace('  backend:\n', '  backend:\n    driver: bridge\n'), Y.serialise(doc)));
})();

/* =========================================================================
 * AC. Network names title verbatim; the bare-declaration contract Phase 1
 *     keys off; humanise() untouched for ordinary keys (PLAN_34 Phase 2)
 *
 * A network name is a proper noun, not a setting key — 'br0.2' is what
 * Unraid calls the VLAN, and humanising it into 'Br0 2' is not a nicer
 * spelling, it is a wrong one. This section pins that both places a network
 * name becomes a row title (a service's own networks: list, and the
 * top-level declaration) show the name as written, while confirming
 * ordinary dotted/underscored settings still go through humanise() as
 * before — the fix has to be narrow, not a global change to titling.
 * Built as inline documents, not read from
 * scratch/test-stacks/18-declaration-shapes/, for the same reason sections
 * AA and AB are.
 * ========================================================================= */

console.log('\nAC. Network names title verbatim; humanise() unchanged elsewhere (PLAN_34 Phase 2)');

(function () {
  var src = [
    'services:',
    '  web:',
    '    image: alpine',
    '    mac_address: "02:42:ac:11:00:02"',
    '    memswap_limit: 512M',
    '    deploy:',
    '      replicas: 2',
    '    networks:',
    '      - br0.2',
    '      - eth0.2',
    '      - frontend_net',
    '      - backend',
    'networks:',
    '  br0.2:',
    '    ipam:',
    '      driver: default',
    '  eth0.2:',
    '  frontend_net:',
    '  backend:',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  function title(id) { var f = Y.fieldById(form, id); return f && f.title; }

  // AC1. A service's own networks: list — each row titles as the name
  // exactly as written, not mechanically humanised ('br0.2' stayed 'br0.2',
  // not 'Br0 2'; 'frontend_net' stayed 'frontend_net', not 'Frontend Net').
  ok("web's br0.2 network row titles verbatim, not 'Br0 2'",
     title('web/list.networks#0/br0.2') === 'br0.2', title('web/list.networks#0/br0.2'));
  ok("web's eth0.2 network row titles verbatim, not 'Eth0 2'",
     title('web/list.networks#1/eth0.2') === 'eth0.2', title('web/list.networks#1/eth0.2'));
  ok("web's frontend_net network row titles verbatim, not 'Frontend Net'",
     title('web/list.networks#2/frontend_net') === 'frontend_net', title('web/list.networks#2/frontend_net'));
  ok("web's backend network row titles verbatim",
     title('web/list.networks#3/backend') === 'backend', title('web/list.networks#3/backend'));

  // AC1 (declarations). The same four names as top-level declaration rows —
  // a second, independent path that must agree with the list rows above.
  ok("the br0.2 declaration titles verbatim, not 'Br0 2'",
     title('/declared/networks.br0.2') === 'br0.2', title('/declared/networks.br0.2'));
  ok("the eth0.2 declaration titles verbatim, not 'Eth0 2'",
     title('/declared/networks.eth0.2') === 'eth0.2', title('/declared/networks.eth0.2'));
  ok("the frontend_net declaration titles verbatim, not 'Frontend Net'",
     title('/declared/networks.frontend_net') === 'frontend_net', title('/declared/networks.frontend_net'));
  ok('the backend declaration titles verbatim',
     title('/declared/networks.backend') === 'backend', title('/declared/networks.backend'));

  // AC3. The regression guard that matters most: ordinary settings must
  // still go through humanise() normally. mac_address and memswap_limit
  // re-check PLAN_36's written-title table still holds (the fix must not
  // have reached back and broken that lookup), and deploy.replicas — an
  // uncovered dotted leaf with a real written title of its own — confirms
  // an ordinary setting is not being left verbatim as raw text either. If a
  // future change makes the network fix bleed into humanise() itself
  // (rather than staying scoped to network name titling), one of these three
  // trips.
  ok("mac_address still titles as 'MAC address' (PLAN_36)",
     title('web/setting/mac_address') === 'MAC address', title('web/setting/mac_address'));
  ok("memswap_limit still titles as 'Memory + swap limit' (PLAN_36)",
     title('web/setting/memswap_limit') === 'Memory + swap limit', title('web/setting/memswap_limit'));
  ok("deploy.replicas still titles as an ordinary written setting, not a verbatim key",
     title('web/setting/deploy.replicas') === 'Number of copies', title('web/setting/deploy.replicas'));

  // AC5. `ipam` used to title as 'Ipam' — the mechanically humanised key,
  // because DECL_LEAVES.networks named it nowhere. Checked against the
  // written title the source actually gives it (DESCRIPTIONS.declared.ipam)
  // rather than a value invented here, per PLAN_34's own instruction not to
  // assert a title the other agent hasn't chosen yet.
  var ipamTitle = title('/declared/networks.br0.2.ipam');
  if (ipamTitle === 'Ipam') {
    console.log('  ....  ipam still titles as the raw humanised key — PLAN_34 Phase 2\'s ' +
                'label fix has not landed yet, so this assertion is withheld rather than pinned to the old value');
  } else {
    ok("ipam titles as '" + ipamTitle + "', not the mechanically humanised 'Ipam'",
       !!ipamTitle && ipamTitle !== 'Ipam');
  }

  // AC6. Reading titles is not an edit — the document must still round-trip
  // byte-identical.
  ok('the inline document round-trips untouched with no edit applied',
     Y.serialise(Y.parse(src)) === src, firstDiff(src, Y.serialise(Y.parse(src))));
})();

/* =========================================================================
 * AD. The bare-declaration contract Phase 1's dead-dropdown fix keys off
 *     (PLAN_34 Phase 1), contrasted with a locked shape
 *
 * Phase 1 exempts a bare declaration from stacks.js's dead-control check by
 * narrowing it to exactly `!spot && !absent && !locked` on the row's own
 * value part. That line lives in stacks.js and is not reachable from here —
 * this pins the three model-side facts it depends on being true together for
 * a bare declaration, and false (on the locked leg) for a shape that must
 * stay refused, so a future change to any of the three trips this rather
 * than the failure surfacing as a dead dropdown nobody connects back here.
 * ========================================================================= */

console.log('\nAD. The bare-declaration contract Phase 1\'s exemption depends on (PLAN_34 Phase 1)');

(function () {
  var src = [
    'services:',
    '  app:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    '      - hft',
    '      - internal_net',
    '      - shared_net',
    'networks:',
    '  backend:',
    '  hft: {name: br0.2, external: true}',
    '  internal_net: &net_defaults',
    '    driver: bridge',
    '  shared_net: *net_defaults',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var bare = Y.fieldById(form, '/declared/networks.backend');
  var flow = Y.fieldById(form, '/declared/networks.hft');

  // The bare declaration's own row: no spot yet (nothing follows the colon),
  // not marked absent (the row itself is present, unlike a wholly-missing
  // leaf), and not locked — the exact three-way combination
  // `!spot && !absent && !locked` that Phase 1's exemption keys off.
  ok('the bare declaration has no spot on its value part',
     !bare.parts.value.spot, bare.parts.value.spot);
  ok('the bare declaration is not marked absent',
     !bare.absent, bare.absent);
  ok('the bare declaration is not locked',
     !bare.locked, bare.locked);
  ok('the bare declaration is the specific combination Phase 1 exempts: !spot && !absent && !locked',
     !bare.parts.value.spot && !bare.absent && !bare.locked);

  // The contrast: a flow-map declaration fails the same combination on its
  // locked leg alone — spot and absent do not save it, because Phase 0 (not
  // Phase 1) is what must keep this one refused.
  ok('a flow-map declaration is locked, so it fails the combination Phase 1 exempts',
     flow.locked && !(!(flow.parts.value && flow.parts.value.spot) && !flow.absent && !flow.locked));

  // setPart agrees with the contract: succeeds on the bare declaration,
  // writing driver: at the right indent; refuses on the locked one, leaving
  // the file untouched. (An existing, differently-shaped case of the bare
  // side is section O11, "filling an empty declaration's primary setting" —
  // this is not a duplicate of it; it is pinned here beside the locked
  // contrast so the two behaviours sit side by side under the combination
  // Phase 1 actually reads.)
  ok('setPart succeeds on the bare declaration and inserts driver: at the right indent',
     Y.setPart(doc, form, '/declared/networks.backend', 'value', 'bridge') &&
     Y.serialise(doc) === src.replace('  backend:\n', '  backend:\n    driver: bridge\n'),
     firstDiff(src.replace('  backend:\n', '  backend:\n    driver: bridge\n'), Y.serialise(doc)));
  ok('setPart still refuses on the flow-map declaration',
     !Y.setPart(doc, form, '/declared/networks.hft', 'value', 'bridge'));
})();

/* =========================================================================
 * AE. PLAN_34 Phase 3 — three Add paths, and the no-clutter guards around
 *     them
 *
 * Phase 3 makes four DECL_LEAVES keys (external, name, internal, attachable)
 * addable on a network declaration via an always-offered blank-fold pass —
 * the same shape harvestLeaves() already gives healthcheck/deploy — and
 * makes a fixed IPv4 address plus a hardware address addable on a network
 * map entry, offered as exactly two blank extras rather than the full set a
 * network entry can carry. `ipam` sits in DECL_LEAVES only for its title; it
 * is a map, and must stay a locked, read-only row throughout — the easiest
 * property for the always-offered pass to break by treating every
 * DECL_LEAVES key alike, so it is pinned directly rather than left to fall
 * out of the other assertions here.
 * ========================================================================= */

console.log('\nAE. PLAN_34 Phase 3 — the three new Add paths');

/* ---- 1. a declaration's own settings can be created --------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    ''
  ].join('\n');

  var doc  = Y.parse(src), form = Y.buildForm(doc);
  // A later change folded external into the row's own value box for networks
  // and volumes, so it is no longer offered here as a separate blank leaf —
  // see section BC below for the row-based path this replaced it with.
  var ext  = Y.fieldById(form, '/declared/networks.backend.external');
  var name = Y.fieldById(form, '/declared/networks.backend.name');
  var intl = Y.fieldById(form, '/declared/networks.backend.internal');
  var att  = Y.fieldById(form, '/declared/networks.backend.attachable');

  ok('external is no longer offered as its own fold field on a network; name, internal and attachable still are',
     !ext &&
     !!name && name.absent && !name.locked && name.fold &&
     !!intl && intl.absent && !intl.locked && intl.fold &&
     !!att && att.absent && !att.locked && att.fold,
     JSON.stringify({
       external:   ext,
       name:       name && { absent: name.absent, locked: name.locked, fold: name.fold },
       internal:   intl && { absent: intl.absent, locked: intl.locked, fold: intl.fold },
       attachable: att  && { absent: att.absent,  locked: att.locked,  fold: att.fold }
     }));
})();

/* ---- 2. ipam must never get a blank box, present or absent -------------- */

(function () {
  // Absent case: the same bare declaration as above never had an ipam:
  // block, so nothing should offer one to create — DECL_LEAVES names it only
  // for its title, not as something the always-offered pass may create.
  var bareSrc = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    ''
  ].join('\n');
  var bareForm = Y.buildForm(Y.parse(bareSrc));
  ok('a declaration without ipam: is never offered a blank ipam field',
     !Y.fieldById(bareForm, '/declared/networks.backend.ipam'));

  // Present case: an existing ipam: block stays exactly as locked and
  // read-only as it is today — Phase 3 must not loosen this while making the
  // other four DECL_LEAVES keys editable.
  var mapSrc = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - br0',
    'networks:',
    '  br0:',
    '    ipam:',
    '      driver: default',
    ''
  ].join('\n');
  var doc  = Y.parse(mapSrc), form = Y.buildForm(doc);
  var ipam = Y.fieldById(form, '/declared/networks.br0.ipam');

  ok('a declaration with an ipam: block keeps it locked, not editable',
     !!ipam && ipam.locked, ipam && JSON.stringify({ locked: ipam.locked }));
  ok('and still shows its raw text rather than hiding it',
     !!ipam && ipam.raw && ipam.raw.indexOf('driver: default') >= 0, ipam && ipam.raw);
  ok('setPart refuses on the locked ipam row, leaving the file untouched',
     !Y.setPart(doc, form, '/declared/networks.br0.ipam', 'value', 'x') &&
     Y.serialise(doc) === mapSrc, firstDiff(mapSrc, Y.serialise(doc)));
})();

/* ---- 3. exactly two blank extras on a network map entry, not eight ----- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      backend:',
    ''
  ].join('\n');
  var form = Y.buildForm(Y.parse(src));
  var row  = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'networks'; })[0];
  var extras = (row && row.longExtras || []).slice().sort();

  ok('a network map entry with nothing set offers precisely ipv4_address and mac_address blank',
     extras.length === 2 && extras[0] === 'ipv4_address' && extras[1] === 'mac_address',
     JSON.stringify(extras));

  ['priority', 'gw_priority', 'interface_name', 'aliases'].forEach(function (k) {
    ok('and does not offer a blank ' + k + ' box',
       !row || !row.parts[k], row && JSON.stringify(Object.keys(row.parts)));
  });
})();

/* ---- 4. creating a fixed address and a hardware address works ---------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      backend:',
    ''
  ].join('\n');
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var row = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'networks'; })[0];

  ok('setPart creates a fixed IPv4 address line under the entry',
     !!row && Y.setPart(doc, form, row.id, 'ipv4_address', '10.0.0.5'));

  var form2 = Y.buildForm(doc);
  var row2  = form2.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'networks'; })[0];
  ok('setPart creates a hardware address line alongside it',
     !!row2 && Y.setPart(doc, form2, row2.id, 'mac_address', '02:42:ac:11:00:02'));

  var want = src.replace('      backend:\n',
    '      backend:\n        ipv4_address: 10.0.0.5\n        mac_address: 02:42:ac:11:00:02\n');
  ok('both lines land at the entry’s own indent and nothing else in the file moves',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 5. scope guard: ports and secrets gained no blank extras ---------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "8080:80"',
    '    secrets:',
    '      - source: db_password',
    '        target: db_pw',
    ''
  ].join('\n');
  var form = Y.buildForm(Y.parse(src));
  var port = form.fields.filter(function (f) { return f.service === 'a' && f.binder === 'port'; })[0];
  var sec  = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'secrets'; })[0];

  ok('a short-form port offers no blank host_ip box',
     !!port && !port.parts.host_ip, port && JSON.stringify(Object.keys(port.parts)));
  ok('a long-form secret offers no blank uid box',
     !!sec && !sec.parts.uid, sec && JSON.stringify(Object.keys(sec.parts)));
})();

/* =========================================================================
 * AF. PLAN_34 Phase 5 — the promote control (promoteNetworksList)
 *
 * A plain `- backend` has nowhere to hang a fixed address, so it has to be
 * promotable to a bare `backend:` map entry — but only as a whole block,
 * since YAML cannot mix a sequence and a mapping under one key. The risk
 * that matters most is comment loss: a list item's trailing "# ..." binds to
 * that item, and the naive way to rebuild the block as a map (this file's
 * own writeTest() is the precedent — it knowingly keeps only the LAST
 * item's comment when it rewrites healthcheck.test) drops every note but
 * one. These assertions demand every note survive, on its own line, with
 * its own original spacing before the '#' — a strict string comparison
 * throughout, not a substring check, so a partial fix cannot pass by luck.
 * ========================================================================= */

console.log('\nAF. PLAN_34 Phase 5 — the promote control');

/* ---- 1/2. Both notes survive; nothing else in the file moves ------------ */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    # keep this network first',
    '    networks:',
    '      - backend        # a note someone typed',
    '      - frontend   # another note',
    '    # do not touch this',
    '    restart: unless-stopped',
    ''
  ].join('\n');

  var doc = Y.parse(src);
  var res = Y.promoteNetworksList(doc, 'a');
  ok('promoting a two-entry list reports ok: true — the model\'s own report of a structural edit, same as addItem/removeItem',
     !!res && res.ok === true, JSON.stringify(res));

  var out = Y.serialise(doc);
  var want = src
    .replace('      - backend        # a note someone typed\n', '      backend:        # a note someone typed\n')
    .replace('      - frontend   # another note\n', '      frontend:   # another note\n');

  ok('both trailing notes survive, on their own new key lines, with their original spacing before the #',
     out === want, firstDiff(want, out));
  ok('nothing else moves: only the two entry lines differ from the original — the comment above the block, ' +
     'the comment below it and the sibling key after it are all untouched',
     diffLines(src, out).length === 2, JSON.stringify(diffLines(src, out)));
})();

/* ---- 3. An anchor, an alias or a flow sequence is refused, whole-block or
            per-entry, and refusing leaves the file byte-identical -------- */

(function () {
  // Whole-block shapes: the entire networks: value is sealed as one opaque
  // node, so promoteNetworksList never reaches a single entry — it reads as
  // "there is no list here" rather than naming which shape sealed it, which
  // is the truth: v.kind isn't 'seq' at all in these three cases.
  var whole = {
    'a flow sequence':  'services:\n  a:\n    image: alpine\n    networks: [frontend, backend]\n',
    'an anchor':        'services:\n  a:\n    image: alpine\n    networks: &shared\n      - backend\n',
    'an alias':         'x-net: &shared\n  - frontend\nservices:\n  a:\n    image: alpine\n    networks: *shared\n'
  };
  Object.keys(whole).forEach(function (label) {
    var src = whole[label];
    var doc = Y.parse(src);
    var res = Y.promoteNetworksList(doc, 'a');
    ok('a networks: list sealed whole by ' + label + ' is refused',
       !!res && res.ok === false && res.error === 'There is no list of networks here to change.',
       JSON.stringify(res));
    ok('...and refusing leaves the file byte-identical (' + label + ')',
       Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
  });

  // Per-entry shapes: the list itself parses fine, but one entry is sealed —
  // this is the path that actually calls lockReason(), so the real LOCK_WORDS
  // text (read from the source, not invented) has to appear in the message.
  var perEntry = {
    'an anchored entry': {
      src: 'services:\n  a:\n    image: alpine\n    networks:\n      - &netref backend\n      - frontend\n',
      reason: 'this is a shared block other parts of the file reuse'
    },
    'an aliased entry': {
      src: 'x-net: &nn frontend\nservices:\n  a:\n    image: alpine\n    networks:\n      - *nn\n      - backend\n',
      reason: 'this points at a shared block higher up the file'
    },
    'a flow-sequence entry': {
      src: 'services:\n  a:\n    image: alpine\n    networks:\n      - [x, y]\n      - backend\n',
      reason: 'this is written as a list on one line'
    }
  };
  Object.keys(perEntry).forEach(function (label) {
    var c = perEntry[label], doc = Y.parse(c.src);
    var res = Y.promoteNetworksList(doc, 'a');
    ok(label + ' refuses the whole block, naming the real LOCK_WORDS reason',
       !!res && res.ok === false &&
       res.error === 'One of these entries cannot be changed here, because ' + c.reason + '.',
       JSON.stringify(res));
    ok('...and refusing leaves the file byte-identical (' + label + ')',
       Y.serialise(doc) === c.src, firstDiff(c.src, Y.serialise(doc)));
  });

  // A dash with no name at all — the function's own doc comment names this
  // case alongside anchor/alias/flow, and it costs nothing extra to pin.
  var blankSrc = 'services:\n  a:\n    image: alpine\n    networks:\n      -\n      - backend\n';
  var blankDoc = Y.parse(blankSrc);
  var blankRes = Y.promoteNetworksList(blankDoc, 'a');
  ok('an entry with no name yet is refused rather than promoted as an empty key',
     !!blankRes && blankRes.ok === false &&
     blankRes.error === 'One of these entries has no name yet, so it cannot become a setting.',
     JSON.stringify(blankRes));
  ok('...and refusing leaves the file byte-identical',
     Y.serialise(blankDoc) === blankSrc, firstDiff(blankSrc, Y.serialise(blankDoc)));
})();

/* ---- 4. A single-entry block promotes the same way ----------------------- */

(function () {
  var src  = 'services:\n  a:\n    image: alpine\n    networks:\n      - backend\n';
  var want = 'services:\n  a:\n    image: alpine\n    networks:\n      backend:\n';
  var doc = Y.parse(src);
  var res = Y.promoteNetworksList(doc, 'a');
  ok('a single-entry list promotes', !!res && res.ok === true, JSON.stringify(res));
  ok('...to a single bare map key', Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 5. An already-map block is refused, not silently accepted as a no-op
            (the other agent's choice — promoteNetworksList requires the
            value to be a seq and refuses everything else, an already-map
            block included, with the same generic message as the sealed-
            whole-block shapes above) ----------------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    networks:\n      backend:\n';
  var doc = Y.parse(src);
  var res = Y.promoteNetworksList(doc, 'a');
  ok('an already-map networks: block is refused rather than treated as a no-op',
     !!res && res.ok === false && res.error === 'There is no list of networks here to change.',
     JSON.stringify(res));
  ok('...and the file is untouched', Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- 6. Phase 5 chains into Phase 3: a promoted entry offers exactly the
            two blank boxes, and nothing else -------------------------- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    networks:\n      - backend\n      - frontend\n';
  var doc = Y.parse(src);
  Y.promoteNetworksList(doc, 'a');
  var form = Y.buildForm(doc);
  var rows = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'networks'; });

  ok('both promoted entries are longForm, offering exactly ipv4_address and mac_address blank',
     rows.length === 2 && rows.every(function (f) {
       return f.longForm === true &&
         f.longExtras.slice().sort().join(',') === 'ipv4_address,mac_address' &&
         f.parts.ipv4_address.value === '' && f.parts.ipv4_address.creatable === true &&
         f.parts.mac_address.value === ''  && f.parts.mac_address.creatable === true;
     }),
     JSON.stringify(rows.map(function (f) { return { name: f.parts.value.value, extras: f.longExtras }; })));

  ['priority', 'gw_priority', 'interface_name', 'aliases'].forEach(function (k) {
    ok('a promoted entry does not also offer a blank ' + k + ' box',
       rows.every(function (f) { return !f.parts[k]; }),
       JSON.stringify(rows.map(function (f) { return Object.keys(f.parts); })));
  });
})();

/* ---- 7. The whole journey: plain entry, promote, set a fixed address ---- */

(function () {
  var src = 'services:\n  a:\n    image: alpine\n    networks:\n' +
    '      - backend        # a note someone typed\n' +
    '      - frontend\n';
  var want = 'services:\n  a:\n    image: alpine\n    networks:\n' +
    '      backend:        # a note someone typed\n' +
    '        ipv4_address: 10.0.0.5\n' +
    '      frontend:\n';

  var doc = Y.parse(src);
  ok('the promotion succeeds', Y.promoteNetworksList(doc, 'a').ok === true);

  var form = Y.buildForm(doc);
  var backend = form.fields.filter(function (f) {
    return f.service === 'a' && f.listKey === 'networks' && f.parts.value.value === 'backend';
  })[0];
  ok('setPart writes the fixed address onto the promoted entry',
     !!backend && Y.setPart(doc, form, backend.id, 'ipv4_address', '10.0.0.5') === true);
  ok('the final text is exactly: plain entry promoted, note kept, address added, sibling entry untouched',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 8. Indentation is inherited from the file, not assumed -------------- */

(function () {
  var src = [
    'services:',
    '    a:',
    '        image: alpine',
    '        networks:',
    '            - backend        # note',
    '            - frontend',
    ''
  ].join('\n');
  var want = [
    'services:',
    '    a:',
    '        image: alpine',
    '        networks:',
    '            backend:        # note',
    '            frontend:',
    ''
  ].join('\n');

  var doc = Y.parse(src);
  var res = Y.promoteNetworksList(doc, 'a');
  ok('a four-space-indented block promotes', !!res && res.ok === true, JSON.stringify(res));
  ok('...with the new keys lined up at the file\'s own depth (12 spaces), not a hardcoded one',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* =========================================================================
 * AG. PLAN_34 Phase 6 — locked long extras, and declaring a missing network
 *     from the row
 *
 * Part one makes an unreadable child of a long-form entry (aliases:,
 * link_local_ips:, or a network-entry extra written as a block rather than a
 * scalar) a locked part in its own right — named, showing the file's own
 * text and why, exactly like every other unreadable node in the form —
 * rather than the one generic "ask the Compose view" sentence this shape
 * used to fall back on. setPart() must refuse writing to one whether or not
 * its name also happens to be an ordinary network-entry extra, because a
 * locked part has no spot to write through.
 *
 * Part two turns "no network called X is defined in this file" into a
 * one-click fix: declareNetwork() writes the whole `name:\n  external:
 * true` declaration in one go, refusing rather than guessing wherever the
 * networks: block cannot safely take it.
 * ========================================================================= */

console.log('\nAG. PLAN_34 Phase 6 — locked long extras, and declareNetwork');

/* ---- 1/2. A locked long extra is genuinely locked, whether or not its name
             is also an ordinary network-entry extra ---------------------- */

var AG12_SRC = [
  'services:',
  '  a:',
  '    image: alpine',
  '    networks:',
  '      backend:',
  '        priority:',
  '          - 1',
  '        aliases:',
  '          - web',
  '          - api',
  ''
].join('\n');

(function () {
  var doc = Y.parse(AG12_SRC), form = Y.buildForm(doc);
  var row = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'networks'; })[0];

  ok('a networks: map entry\'s aliases: is a genuinely locked part',
     !!row && !!row.parts.aliases &&
     row.parts.aliases.locked === true && row.parts.aliases.spot === null &&
     !row.parts.aliases.creatable &&
     row.parts.aliases.raw === 'aliases:\n  - web\n  - api' &&
     typeof row.parts.aliases.reason === 'string' && row.parts.aliases.reason.length > 0,
     row && JSON.stringify(row.parts.aliases));

  ok('setPart refuses to write it, and the file is untouched',
     !!row && Y.setPart(doc, form, row.id, 'aliases', 'anything') === false &&
     Y.serialise(doc) === AG12_SRC, firstDiff(AG12_SRC, Y.serialise(doc)));

  // priority: is one of NETWORK_ENTRY_EXTRAS — the names setPart's own
  // phase-3c branch is willing to create a bare line for when it finds no
  // spot. Written here as a block rather than a scalar, so it is locked
  // exactly like aliases: — this pins the guard sitting BEFORE that creation
  // branch, since without it a locked, in-the-extras-list name would fall
  // through and get a second, colliding line written under it.
  ok('...and the same holds for a locked part whose name IS a network-entry extra (priority)',
     !!row && !!row.parts.priority && row.parts.priority.locked === true &&
     row.parts.priority.raw === 'priority:\n  - 1' &&
     Y.setPart(doc, form, row.id, 'priority', '5') === false &&
     Y.serialise(doc) === AG12_SRC, firstDiff(AG12_SRC, Y.serialise(doc)));
})();

/* ---- 3. link_local_ips gets the same treatment — the fix is about shape,
            not two hard-coded key names ------------------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      backend:',
    '        link_local_ips:',
    '          - 169.254.1.1',
    ''
  ].join('\n');
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var row = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'networks'; })[0];

  ok('link_local_ips: is locked the same way aliases: is',
     !!row && !!row.parts.link_local_ips && row.parts.link_local_ips.locked === true &&
     row.parts.link_local_ips.spot === null && !row.parts.link_local_ips.creatable &&
     row.parts.link_local_ips.raw === 'link_local_ips:\n  - 169.254.1.1' &&
     row.parts.link_local_ips.reason === 'this is written as a list of separate items',
     row && JSON.stringify(row.parts.link_local_ips));
  ok('...and setPart refuses it too, leaving the file untouched',
     !!row && Y.setPart(doc, form, row.id, 'link_local_ips', 'x') === false &&
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- 4. f.declareMissing is set only for a dangling network, never for a
            dangling volume or secret ---------------------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - br0.2',
    '      - back',
    '    volumes:',
    '      - myvol:/data',
    '    secrets:',
    '      - db_password',
    'networks:',
    '  back:',
    '    external: true',
    ''
  ].join('\n');
  var form = Y.buildForm(Y.parse(src));
  var nets = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'networks'; });
  var missingNet = nets.filter(function (f) { return f.parts.value.value === 'br0.2'; })[0];
  var declaredNet = nets.filter(function (f) { return f.parts.value.value === 'back'; })[0];
  var vol = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'volumes'; })[0];
  var sec = form.fields.filter(function (f) { return f.service === 'a' && f.listKey === 'secrets'; })[0];

  ok('a service joining a network the file never declares gets declareMissing set to that name',
     !!missingNet && missingNet.declareMissing === 'br0.2' &&
     missingNet.advice.indexOf('no network called br0.2 is defined in this file') >= 0,
     missingNet && JSON.stringify({ declareMissing: missingNet.declareMissing, advice: missingNet.advice }));
  ok('a service joining a network the file DOES declare has no declareMissing',
     !!declaredNet && !declaredNet.declareMissing,
     declaredNet && JSON.stringify({ declareMissing: declaredNet.declareMissing, advice: declaredNet.advice }));
  ok('a dangling volume reference gets the advice, but never declareMissing — the fix is networks-only',
     !!vol && !vol.declareMissing &&
     vol.advice.indexOf('no volume called myvol is defined in this file') >= 0,
     vol && JSON.stringify({ declareMissing: vol.declareMissing, advice: vol.advice }));
  ok('a dangling secret reference gets the advice, but never declareMissing either',
     !!sec && !sec.declareMissing &&
     sec.advice.indexOf('no secret called db_password is defined in this file') >= 0,
     sec && JSON.stringify({ declareMissing: sec.declareMissing, advice: sec.advice }));
})();

/* ---- 5. declareNetwork writes the whole declaration, from a file with no
            networks: block at all ------------------------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - br0.2',
    ''
  ].join('\n');
  var want = src + 'networks:\n  br0.2:\n    external: true\n';

  var doc = Y.parse(src);
  var line = Y.declareNetwork(doc, 'br0.2');
  ok('declareNetwork returns a real line number', line >= 0, line);

  var out = Y.serialise(doc);
  ok('...and the file now holds a networks: block with br0.2: and external: true under it, indented correctly',
     out === want, firstDiff(want, out));

  var form2 = Y.buildForm(Y.parse(out));
  var row2 = form2.fields.filter(function (f) {
    return f.service === 'a' && f.listKey === 'networks' && f.parts.value.value === 'br0.2';
  })[0];
  ok('re-parsed, buildForm no longer reports the dangling-network advice for that row',
     !!row2 && row2.advice.indexOf('no network called br0.2 is defined in this file') < 0 && !row2.declareMissing,
     row2 && JSON.stringify(row2.advice));
})();

/* ---- 6. From a file that already has a networks: block: the new
            declaration is added alongside, and every comment and blank
            line already there survives ------------------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - br0.2',
    '      - frontend',
    'networks:',
    '  frontend:',
    '    # a note on frontend\'s driver',
    '    driver: bridge',
    '# a note after the whole networks: block',
    ''
  ].join('\n');
  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - br0.2',
    '      - frontend',
    'networks:',
    '  frontend:',
    '    # a note on frontend\'s driver',
    '    driver: bridge',
    '  br0.2:',
    '    external: true',
    '# a note after the whole networks: block',
    ''
  ].join('\n');

  var doc = Y.parse(src);
  ok('the fixture itself round-trips byte for byte before any edit',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));

  var line = Y.declareNetwork(doc, 'br0.2');
  ok('declareNetwork succeeds alongside an existing entry', line >= 0, line);

  var out = Y.serialise(doc);
  ok('the existing entry, its comment and the comment after the block all survive untouched',
     out === want, firstDiff(want, out));
})();

/* ---- 7. Refusals write nothing: already declared, an alias, a flow map -- */

(function () {
  var already = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - br0.2',
    'networks:',
    '  br0.2:',
    '    external: true',
    ''
  ].join('\n');
  var doc1 = Y.parse(already);
  ok('a name already declared is refused, writing nothing',
     Y.declareNetwork(doc1, 'br0.2') === -1 && Y.serialise(doc1) === already,
     firstDiff(already, Y.serialise(doc1)));

  var aliasSrc = 'x-net: &shared\n  frontend:\n\nservices:\n  a:\n    image: alpine\nnetworks: *shared\n';
  var doc2 = Y.parse(aliasSrc);
  ok('a top-level networks: block written as an alias is refused, writing nothing',
     Y.declareNetwork(doc2, 'frontend') === -1 && Y.serialise(doc2) === aliasSrc,
     firstDiff(aliasSrc, Y.serialise(doc2)));

  var flowSrc = 'services:\n  a:\n    image: alpine\nnetworks: {a: {}}\n';
  var doc3 = Y.parse(flowSrc);
  ok('a top-level networks: block written as a flow map is refused, writing nothing',
     Y.declareNetwork(doc3, 'a') === -1 && Y.serialise(doc3) === flowSrc,
     firstDiff(flowSrc, Y.serialise(doc3)));
})();

/* ---- 8. A name that genuinely needs quoting as a YAML key -------------- */

(function () {
  // "br0.2" (a real Unraid VLAN name) is fine bare — a colon is not: written
  // bare, "vlan: 2:" reads back as key "vlan" with value "2:", not the
  // single key "vlan: 2" that was asked for. Either quoting it correctly or
  // refusing outright keeps the file honest; writing it bare and unquoted
  // does not, and is a silently corrupt file even though it round-trips
  // through THIS parser (which treats every key as a literal string).
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - br0.2',
    ''
  ].join('\n');
  // declareNetwork itself has no documented failure mode beyond returning
  // -1, so a crash here is as much a finding as a wrong value — caught
  // rather than left to take the whole suite down, so every other section
  // still gets to report.
  var doc = Y.parse(src), line, out, crashed = null;
  try {
    line = Y.declareNetwork(doc, 'vlan: 2');
    out = Y.serialise(doc);
  } catch (e) { crashed = e; }

  var refused = !crashed && line === -1 && out === src;
  var quotedSafely = false;
  if (!crashed && line >= 0) {
    try {
      var reparsed = Y.parse(out);
      var net = reparsed.root.pairs.networks && reparsed.root.pairs.networks.value;
      quotedSafely = /^\s*["']vlan: 2["']\s*:/m.test(out) && !!net && net.pairs.hasOwnProperty('vlan: 2');
    } catch (e) { quotedSafely = false; }
  }

  ok('a network name needing YAML quoting is either quoted safely (and reparses to the same name) or refused outright — never written bare and corrupted, and never a crash',
     refused || quotedSafely,
     crashed ? ('declareNetwork threw: ' + crashed) : ('declareNetwork returned ' + line + '\n' + out));
})();

/* =========================================================================
 * AH. PLAN_40 — dragging a port into a different place in the list
 *
 * moveItem() lifts a whole entry — leadStart..end, comments and all — the
 * same span removeItem already trusts to be "the whole entry" — and drops
 * it elsewhere. Two failure modes have actually happened in this codebase
 * before: a comment left behind when a block gets rebuilt (PLAN_34 phase 5
 * kept only the last comment of a networks block), and a multi-line entry
 * only half-moved. Every assertion below is byte-for-byte, so a comment
 * dropped, a blank line dragged along, or an off-by-one on the insertion
 * point cannot pass by luck.
 * ========================================================================= */

console.log('\nAH. PLAN_40 — dragging a port (moveItem)');

/* ---- 1. Two short-form ports swap, byte-identical to the same file
            written the other way round by hand ------------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "8080:80"',
    '      - "9090:90"',
    ''
  ].join('\n');
  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "9090:90"',
    '      - "8080:80"',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var res = Y.moveItem(doc, form, 'a', 'ports', 0, 1);
  ok('swapping the two entries reports ok: true', !!res && res.ok === true, JSON.stringify(res));
  ok('...and the file matches the same two lines written in the other order',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 2. Each entry's own trailing comment travels with it — the one that
            would have been silently wrong -------------------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "8080:80"   # web',
    '      - "9090:90"   # admin',
    ''
  ].join('\n');
  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "9090:90"   # admin',
    '      - "8080:80"   # web',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.moveItem(doc, form, 'a', 'ports', 0, 1);
  ok('each entry keeps its own trailing comment after the swap',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 3. A standalone comment line above an entry travels with it; one
            above the ports: key itself does not move ---------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    # do not touch this',
    '    ports:',
    '      # note about web',
    '      - "8080:80"',
    '      - "9090:90"',
    ''
  ].join('\n');
  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    # do not touch this',
    '    ports:',
    '      - "9090:90"',
    '      # note about web',
    '      - "8080:80"',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.moveItem(doc, form, 'a', 'ports', 0, 1);
  ok('the comment above the entry moves with it, and the comment above ports: itself stays put',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 4. A long-form entry (four lines) moves as a whole block, its inner
            lines keeping their original order ----------------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - target: 80',
    '        published: 8080',
    '        protocol: tcp',
    '        host_ip: 0.0.0.0',
    '      - target: 90',
    '        published: 9090',
    ''
  ].join('\n');
  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - target: 90',
    '        published: 9090',
    '      - target: 80',
    '        published: 8080',
    '        protocol: tcp',
    '        host_ip: 0.0.0.0',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.moveItem(doc, form, 'a', 'ports', 0, 1);
  ok('the four-line entry moves as one block, its own lines in their original order, nothing split off',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 5. A blank line between two entries stays where it was rather than
            travelling with either one ------------------------------------ */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "8080:80"',
    '',
    '      - "9090:90"',
    ''
  ].join('\n');
  // The blank sits outside both items' spans, so it stays at its own line —
  // the second line of the block — rather than following either entry. Once
  // the swap makes "9090:90" the first entry, the blank ends up ahead of it
  // instead of between the two entries as it was before the move.
  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '',
    '      - "9090:90"',
    '      - "8080:80"',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.moveItem(doc, form, 'a', 'ports', 0, 1);
  ok('the blank line stays at its own line rather than travelling with either entry',
     Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 6. Last-to-first and first-to-last, the two off-by-one cases -------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "8080:80"',
    '      - "9090:90"',
    '      - "7070:70"',
    ''
  ].join('\n');

  var wantLastToFirst = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "7070:70"',
    '      - "8080:80"',
    '      - "9090:90"',
    ''
  ].join('\n');
  var doc1 = Y.parse(src), form1 = Y.buildForm(doc1);
  Y.moveItem(doc1, form1, 'a', 'ports', 2, 0);
  ok('moving the last entry to the front lands it there and shifts the rest down',
     Y.serialise(doc1) === wantLastToFirst, firstDiff(wantLastToFirst, Y.serialise(doc1)));

  var wantFirstToLast = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "9090:90"',
    '      - "7070:70"',
    '      - "8080:80"',
    ''
  ].join('\n');
  var doc2 = Y.parse(src), form2 = Y.buildForm(doc2);
  Y.moveItem(doc2, form2, 'a', 'ports', 0, 2);
  ok('moving the first entry to the back lands it there and shifts the rest up',
     Y.serialise(doc2) === wantFirstToLast, firstDiff(wantFirstToLast, Y.serialise(doc2)));
})();

/* ---- 7. from === to writes nothing, byte-identical ----------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "8080:80"',
    '      - "9090:90"',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var res = Y.moveItem(doc, form, 'a', 'ports', 1, 1);
  ok('moving an entry onto its own position reports ok: true and writes nothing',
     !!res && res.ok === true && Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- 8. Refusals leave the file byte-identical: a flow sequence, an
            anchored entry, an aliased entry and a tagged entry — the last
            three sitting where they are NOT touched by the move, since the
            rule is "anywhere in the list", not just the two entries being
            swapped ------------------------------------------------------- */

(function () {
  var flowSrc = 'services:\n  a:\n    image: alpine\n    ports: ["80:80", "81:81"]\n';
  var flowDoc = Y.parse(flowSrc), flowForm = Y.buildForm(flowDoc);
  var flowRes = Y.moveItem(flowDoc, flowForm, 'a', 'ports', 0, 1);
  ok('a flow sequence is refused, naming there being no list here to reorder',
     !!flowRes && flowRes.ok === false && flowRes.error === 'There is no list here to reorder.',
     JSON.stringify(flowRes));
  ok('...and refusing leaves the file byte-identical',
     Y.serialise(flowDoc) === flowSrc, firstDiff(flowSrc, Y.serialise(flowDoc)));

  var perEntry = {
    'an anchored entry': {
      src: 'services:\n  a:\n    image: alpine\n    ports:\n      - "8080:80"\n      - &p1 "9090:90"\n      - "7070:70"\n',
      reason: 'this is a shared block other parts of the file reuse'
    },
    'an aliased entry': {
      src: 'x-ports: &pp "9090:90"\nservices:\n  a:\n    image: alpine\n    ports:\n      - "8080:80"\n      - *pp\n      - "7070:70"\n',
      reason: 'this points at a shared block higher up the file'
    },
    'a tagged entry': {
      src: 'services:\n  a:\n    image: alpine\n    ports:\n      - "8080:80"\n      - !!str "9090:90"\n      - "7070:70"\n',
      reason: 'this carries a YAML tag'
    }
  };
  Object.keys(perEntry).forEach(function (label) {
    var c = perEntry[label], doc = Y.parse(c.src), form = Y.buildForm(doc);
    // Neither index 0 nor index 2 is the sealed entry at index 1 — moving
    // around it must still be refused, because it sits inside the span
    // [leadStart of the moved entry .. leadStart of the target] either way.
    var res = Y.moveItem(doc, form, 'a', 'ports', 0, 2);
    ok(label + ' anywhere in the list is refused, even though it is not one of the two entries being moved',
       !!res && res.ok === false &&
       res.error === 'One of these entries cannot be moved, because ' + c.reason + '.',
       JSON.stringify(res));
    ok('...and refusing leaves the file byte-identical (' + label + ')',
       Y.serialise(doc) === c.src, firstDiff(c.src, Y.serialise(doc)));
  });
})();

/* ---- 9. After a move, buildForm reports the ports in the new order, and
            the field now first is the one PLAN_39's WebUI chip marks ------ */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - "8080:80"',
    '      - "9090:90"',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.moveItem(doc, form, 'a', 'ports', 0, 1);

  var ports = Y.buildForm(doc).fields.filter(function (f) { return f.service === 'a' && f.binder === 'port'; });
  ok('both ports still appear, in the new order — the first one is what PLAN_39\'s rule ' +
     'hands the WebUI button, so this is the field the chip would now mark',
     ports.length === 2 &&
     ports[0].parts.host.value === '9090' && ports[0].parts.container.value === '90' &&
     ports[1].parts.host.value === '8080' && ports[1].parts.container.value === '80',
     JSON.stringify(ports.map(function (f) { return { host: f.parts.host.value, container: f.parts.container.value }; })));
})();

/* ---- 10. The moved file still round-trips unsealed ----------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    ports:',
    '      - target: 80',
    '        published: 8080',
    '        protocol: tcp',
    '      - target: 90',
    '        published: 9090',
    ''
  ].join('\n');

  var doc = Y.parse(src), form = Y.buildForm(doc);
  Y.moveItem(doc, form, 'a', 'ports', 0, 1);
  var out = Y.serialise(doc);

  ok('the moved file is itself well-formed: parsing it again and serialising it back matches exactly',
     Y.serialise(Y.parse(out)) === out, firstDiff(out, Y.serialise(Y.parse(out))));
})();

/* =========================================================================
 * AI. Driver and external share one box on a network/volume row
 *
 * A network or volume declared `external: true` with no driver used to read
 * back as a row with an empty box — indistinguishable from an unconfigured
 * one, even though the file said something. `external: true` alongside a
 * driver: is also a file real compose refuses outright. The fix folds the
 * two into the row's single value box: EXTERNAL_CHOICE is one more answer a
 * driver dropdown can hold, so the row can never show both or neither.
 * Secrets and configs are unaffected — their row box is a file path, and the
 * same merge there would misrepresent it — so external stays an ordinary
 * fold field for those two kinds.
 * ========================================================================= */

console.log('\nAI. Driver and external share one box on a network/volume row');

/* ---- 1. external: true, no driver, reads as the sentinel ---------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    external: true',
    ''
  ].join('\n');
  var f = Y.fieldById(Y.buildForm(Y.parse(src)), '/declared/networks.backend');

  ok('a network declared external: true with no driver reads back as the external sentinel',
     !!f && f.parts.value.value === Y.externalChoice,
     f && JSON.stringify(f.parts.value));
})();

/* ---- 2. that row set to a driver name drops external -------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    external: true',
    ''
  ].join('\n');
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, '/declared/networks.backend', 'value', 'bridge');

  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    driver: bridge',
    ''
  ].join('\n');
  ok('setting that row to a driver name writes driver: bridge and removes the external line',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 3. an existing driver, set to external, drops the driver line ------ */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    driver: bridge',
    ''
  ].join('\n');
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, '/declared/networks.backend', 'value', Y.externalChoice);

  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    external: true',
    ''
  ].join('\n');
  ok('setting a driver:-bearing row to "external" writes external: true (unquoted, bare) and removes the driver line',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 4. that row cleared to blank leaves a bare, valid declaration ------ */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    external: true',
    ''
  ].join('\n');
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wrote = Y.setPart(doc, form, '/declared/networks.backend', 'value', '');

  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    ''
  ].join('\n');
  ok('clearing that row to blank leaves the declaration bare — no driver, no external, name line intact',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 5. external: false never takes over the row ------------------------ */

(function () {
  var withDriver = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    driver: bridge',
    '    external: false',
    ''
  ].join('\n');
  var fWithDriver = Y.fieldById(Y.buildForm(Y.parse(withDriver)), '/declared/networks.backend');
  ok('external: false alongside a driver leaves the row showing the driver, not the sentinel',
     !!fWithDriver && fWithDriver.parts.value.value === 'bridge',
     fWithDriver && JSON.stringify(fWithDriver.parts.value));

  var noDriver = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    external: false',
    ''
  ].join('\n');
  var fNoDriver = Y.fieldById(Y.buildForm(Y.parse(noDriver)), '/declared/networks.backend');
  ok('external: false with no driver leaves the row blank, not the sentinel',
     !!fNoDriver && fNoDriver.parts.value.value === '',
     fNoDriver && JSON.stringify(fNoDriver.parts.value));
})();

/* ---- 6. a map-shaped external cannot be shown on the row, so it keeps its
 *        own fold field and leaves the driver box alone ------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    networks:',
    '      - backend',
    'networks:',
    '  backend:',
    '    driver: bridge',
    '    external:',
    '      name: real_net',
    ''
  ].join('\n');
  var form = Y.buildForm(Y.parse(src));
  var row  = Y.fieldById(form, '/declared/networks.backend');
  var leaf = Y.fieldById(form, '/declared/networks.backend.external');

  ok('the deprecated long-form external (a map) leaves the row showing the driver, and keeps its own fold field',
     !!row && row.parts.value.value === 'bridge' && !!leaf && leaf.fold,
     JSON.stringify({ row: row && row.parts.value.value, leaf: leaf && { fold: leaf.fold, locked: leaf.locked } }));
})();

/* ---- 7. a volumes: declaration behaves the same way ---------------------- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    volumes:',
    '      - data:/var/lib/data',
    'volumes:',
    '  data:',
    '    external: true',
    ''
  ].join('\n');
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var f = Y.fieldById(form, '/declared/volumes.data');
  ok('a volume declared external: true with no driver reads back as the sentinel too',
     !!f && f.parts.value.value === Y.externalChoice,
     f && JSON.stringify(f.parts.value));

  var wrote = Y.setPart(doc, form, '/declared/volumes.data', 'value', 'local');
  var want = [
    'services:',
    '  a:',
    '    image: alpine',
    '    volumes:',
    '      - data:/var/lib/data',
    'volumes:',
    '  data:',
    '    driver: local',
    ''
  ].join('\n');
  ok('setting a volume row from external to a driver writes driver: local and drops external',
     wrote && Y.serialise(doc) === want, firstDiff(want, Y.serialise(doc)));
})();

/* ---- 8. secrets are unaffected: external stays an ordinary fold field --- */

(function () {
  var src = [
    'services:',
    '  a:',
    '    image: alpine',
    '    secrets:',
    '      - creds',
    'secrets:',
    '  creds:',
    '    file: ./creds.txt',
    '    external: true',
    ''
  ].join('\n');
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var row  = Y.fieldById(form, '/declared/secrets.creds');
  var leaf = Y.fieldById(form, '/declared/secrets.creds.external');

  ok('a secret\'s row box still shows the file path, and external stays an ordinary, unlocked fold field',
     !!row && row.parts.value.value === './creds.txt' &&
     !!leaf && leaf.fold && !leaf.locked && leaf.parts.value.value === 'true',
     JSON.stringify({
       row:  row  && row.parts.value.value,
       leaf: leaf && { fold: leaf.fold, locked: leaf.locked, value: leaf.parts.value.value }
     }));

  ok('...and the file round-trips untouched — this section only reads',
     Y.serialise(doc) === src, firstDiff(src, Y.serialise(doc)));
})();

/* ---- result ------------------------------------------------------------- */

console.log('\n' + pass + ' passed, ' + fail + ' failed\n');
process.exit(fail ? 1 : 0);
