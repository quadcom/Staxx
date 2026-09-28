/* StaXX — one Python start for every schema check a suite queues, instead of
 * one per document.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   var SC = require('./lib/schema_check.js');
 *   SC.validate(text, function (v) { ok(label, v.ok, JSON.stringify(v.errors)); });
 *   SC.flush();   // starts Python once for everything queued, then calls each callback
 *
 * Not a suite: tests/run-local.js skips this folder.
 */

'use strict';

var path = require('path');
var childProcess = require('child_process');

var SCHEMA_PATH = path.join(__dirname, '..', '..', 'schema', 'x-unraid.schema.json');

var queue = [];   // {text, cb}

function validate(text, cb) {
  queue.push({ text: text, cb: cb });
}

function flush() {
  if (!queue.length) return;
  var script = [
    'import sys, json, yaml',
    'from jsonschema import Draft202012Validator',
    'v = Draft202012Validator(json.load(open(' + JSON.stringify(SCHEMA_PATH) + ', encoding="utf-8")))',
    'out = []',
    'for text in json.loads(sys.stdin.buffer.read().decode("utf-8")):',
    '    try:',
    '        doc = yaml.safe_load(text)',
    '        errors = [str(e.message) + " at /" + "/".join(map(str, e.path)) for e in v.iter_errors(doc)]',
    '    except Exception as e:',
    '        errors = ["could not read the document: " + str(e)]',
    '    out.append({"ok": not errors, "errors": errors})',
    'print(json.dumps(out))'
  ].join('\n');

  var texts = queue.map(function (q) { return q.text; });
  var res = childProcess.spawnSync('python', ['-c', script], { input: JSON.stringify(texts), encoding: 'utf8' });

  var results;
  if (res.status !== 0) {
    results = null;
  } else {
    try { results = JSON.parse(res.stdout); } catch (e) { results = null; }
  }

  var todo = queue;
  queue = [];
  todo.forEach(function (q, i) {
    if (results) {
      q.cb(results[i]);
    } else {
      q.cb({ ok: false, errors: [res.stderr || res.stdout || 'python failed'] });
    }
  });
}

module.exports = { validate: validate, flush: flush };
