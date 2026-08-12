/* Stack Manager — round-trip tests for the compose model.
 * Copyright 2026, Stack Manager contributors. GPL-2.0.
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

var Y = require('../src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js');

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
  Y.setPart(doc, form, b.id, b.part, 'STACKMANTESTVALUE');
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

/* ---- result ------------------------------------------------------------- */

console.log('\n' + pass + ' passed, ' + fail + ' failed\n');
process.exit(fail ? 1 : 0);
