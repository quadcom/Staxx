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

/* =========================================================================
 * J. The always-present Container settings
 *
 * image, container_name, restart and network_mode are always in the model,
 * whether or not the file has them, so refreshRanges() can index fields by
 * array position without a redraw. See harvest() and setPart() for the
 * absent-slot handling this exercises.
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
  // move when an absent slot gains a line. Sixteen, not four: the four fixed
  // Container fields, plus twelve blank health-check/resource-limit leaves —
  // harvestLeaves() (PLAN_8 phase 2) offers those whether or not the file has
  // healthcheck:/deploy: at all, and healthcheck.test counts as two of them
  // (PLAN_8 phase 4 — the mode and the command, see harvestHealthTest()).
  var src = 'services:\n  a:\n    image: alpine\n';
  var doc = Y.parse(src), form = Y.buildForm(doc);
  var svcFields = form.fields.filter(function (f) { return f.service === 'a'; });

  ok('a service with no other settings yields four fixed fields plus twelve blank leaves',
     svcFields.length === 16 &&
     svcFields.slice(0, 4).every(function (f) { return f.fixed; }) &&
     svcFields.slice(4).every(function (f) { return f.absent && f.path; }),
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
               secrets: ['db_password'], configs: [], services: ['web', 'db'] };
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

/* ---- 3. web accounts for all nine of its keys, plus network_mode ------- */

(function () {
  // networks: is two editable list fields rather than one locked block, and
  // harvestLeaves() (PLAN_8 phase 2) now offers every healthcheck/deploy leaf
  // whether the file has it or not — seven healthcheck leaves (test counts as
  // two — the mode and the command, PLAN_8 phase 4) plus all four deploy
  // ones, as a fixed pass right after the four Container fields, same as
  // those. The two healthcheck leaves this file does not set (start_interval,
  // disable) still appear, blank. web's own test: is a flow list
  // (["CMD", "curl", ...]) which readTest() reads with confidence, so it
  // surfaces right there with its siblings rather than later as a locked
  // catch-all field. web's depends_on is long form (PLAN_8 phase 5) — one
  // field for "db" plus its restart/required fold, in place of the single
  // locked block earlier phases left it as. So the count is twenty-three, not
  // the ten keys the original file has at the top of web:. Pinning f.id
  // rather than binder/target is deliberate — a list field's id carries its
  // list key and index (web/list.networks#0/frontend_net), which is what
  // stops the same name colliding across two different list keys (see the
  // ids-cannot-collide case below).
  var form = Y.buildForm(Y.parse(FIXTURE_10_ADVANCED));
  var web  = form.fields.filter(function (f) { return f.service === 'web'; });
  var got  = web.map(function (f) { return f.id; });
  var want = [
    'web/setting/image',
    'web/setting/container_name',
    'web/setting/restart',
    'web/setting/network_mode',
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
    'web/port/80/tcp',
    'web/env/NGINX_PORT',
    'web/list.networks#0/frontend_net',
    'web/list.networks#1/backend_net',
    'web/depends/depends_on.db',
    'web/depends/depends_on.db.restart',
    'web/depends/depends_on.db.required'
  ];
  ok('web yields exactly these twenty-three fields, in file order',
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

/* ---- 5. network_mode is blocked, not locked, once networks: is present - */

(function () {
  var text = FIXTURE_10_ADVANCED;
  var doc  = Y.parse(text), form = Y.buildForm(doc);
  var nm   = Y.fieldById(form, 'web/setting/network_mode');

  ok('web\u2019s network_mode is blocked rather than locked',
     !!nm && nm.blocked === true && nm.locked === false,
     nm && JSON.stringify({ blocked: nm.blocked, locked: nm.locked }));
  ok('and carries the advice that networks: is how this is done instead',
     !!nm && nm.advice.indexOf('this service joins the networks listed below instead') >= 0,
     nm && JSON.stringify(nm.advice));
  ok('setPart on a blocked field is refused',
     Y.setPart(doc, form, 'web/setting/network_mode', 'value', 'bridge') === false);
  ok('and the refusal writes nothing', Y.serialise(doc) === text);
})();

/* ---- 6. no networks: key still gives a normal absent network_mode slot - */

(function () {
  var src  = 'services:\n  c:\n    image: alpine\n';
  var doc  = Y.parse(src), form = Y.buildForm(doc);
  var nm   = Y.fieldById(form, 'c/setting/network_mode');

  ok('a service with no networks: key has an absent, unblocked network_mode slot',
     !!nm && nm.absent === true && !nm.blocked,
     nm && JSON.stringify({ absent: nm.absent, blocked: nm.blocked }));
  ok('setPart on it succeeds', Y.setPart(doc, form, 'c/setting/network_mode', 'value', 'bridge'));
  ok('and writes network_mode: bridge at the service\u2019s own indent',
     Y.serialise(doc) === 'services:\n  c:\n    image: alpine\n    network_mode: bridge\n',
     JSON.stringify(Y.serialise(doc)));
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
  var src = 'x-shared: &defaults\n' +
            '  restart: unless-stopped\n' +
            '\n' +
            'services:\n' +
            '  a:\n' +
            '    <<: *defaults\n' +
            '    working_dir: /app\n' +
            '    logging:\n' +
            '      driver: json-file\n' +
            '    x-unraid:\n' +
            '      name: Thing\n';

  var doc = Y.parse(src), form = Y.buildForm(doc);
  var wd  = Y.fieldById(form, 'a/setting/working_dir');
  var log = Y.fieldById(form, 'a/setting/logging');

  ok('working_dir falls to the catch-all as an editable field, not a locked one',
     !!wd && !wd.locked, wd && JSON.stringify(wd));
  Y.setPart(doc, form, 'a/setting/working_dir', 'value', '/srv');
  ok('and setting it rewrites only that one line',
     diffLines(src, Y.serialise(doc)).length === 1, diffLines(src, Y.serialise(doc)).join(', '));

  ok('a logging: block falls to the catch-all as a locked field',
     !!log && log.locked && log.lockReason === 'this is written as a block of its own',
     log && JSON.stringify(log));

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
  ok('and network_mode reverts to an ordinary absent, unblocked slot',
     !!nm && nm.absent === true && nm.blocked === false,
     nm && JSON.stringify({ absent: nm.absent, blocked: nm.blocked }));
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

  var CASES = [['dns', '1.1.1.1'], ['cap_add', 'NET_ADMIN'], ['env_file', './app.env']];
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
     !!repl && repl.locked === false && repl.title === 'Replicas' && repl.type === 'number',
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
  var want = [
    { target: 'networks.backend_net',          declKind: 'networks', fold: false },
    { target: 'networks.backend_net.internal', declKind: 'networks', fold: true  },
    { target: 'networks.frontend_net',         declKind: 'networks', fold: false },
    { target: 'secrets.db_password',           declKind: 'secrets',  fold: false },
    { target: 'volumes.db_data',               declKind: 'volumes',  fold: false }
  ];
  ok('the fixture yields exactly these five declared fields, with the right declKind',
     JSON.stringify(got) === JSON.stringify(want), JSON.stringify(got));
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

/* ---- result ------------------------------------------------------------- */

console.log('\n' + pass + ' passed, ' + fail + ' failed\n');
process.exit(fail ? 1 : 0);
