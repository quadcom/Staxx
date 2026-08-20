/* StaXX — names assigned but never declared.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/js_undeclared.js
 *
 * Both browser files are one 'use strict' IIFE, where assigning to a name
 * nothing declared throws a ReferenceError instead of quietly making a global.
 * One such line inside a function every page render calls — reparse(), say —
 * kills every behaviour on the page and leaves no trace but a silent console.
 *
 * `node --check` cannot see it: the file parses perfectly, and the error only
 * exists at run time. There is no browser on this machine to find it the honest
 * way, so it is found here by reading the source instead.
 *
 * A text scan, not a parser, so it is deliberately conservative — it looks only
 * at assignments in statement position and stays quiet about anything subtler.
 */

'use strict';

var fs = require('fs');

var FILES = [
  'src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js',
  'src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js',
  'src/staxx/usr/local/emhttp/plugins/staxx/javascript/manage.js'
];

var KEYWORD = /^(?:if|for|while|do|else|return|case|switch|try|catch|typeof|new|delete|void|in|of|instanceof|null|true|false|this|function|var|let|const)$/;

function declaredIn(src) {
  var names = {};

  // A declarator list can hold several names and can wrap over lines, as in
  // "var best = null,\n    bestSpan = Infinity;" — so the whole statement is
  // read up to its semicolon and split, rather than taking the first name.
  var decl = /\b(?:var|let|const)\b([\s\S]*?);/g, m;
  while ((m = decl.exec(src))) {
    m[1].split(',').forEach(function (part) {
      var name = /^\s*([A-Za-z_$][\w$]*)/.exec(part);
      if (name) names[name[1]] = true;
    });
  }

  // Function names, their parameters, and catch bindings.
  var fn = /\bfunction\s*([A-Za-z_$][\w$]*)?\s*\(([^)]*)\)/g;
  while ((m = fn.exec(src))) {
    if (m[1]) names[m[1]] = true;
    m[2].split(',').forEach(function (p) {
      p = p.trim();
      if (p) names[p] = true;
    });
  }

  var cat = /\bcatch\s*\(\s*([A-Za-z_$][\w$]*)/g;
  while ((m = cat.exec(src))) names[m[1]] = true;

  return names;
}

var bad = 0;

FILES.forEach(function (file) {
  var src   = fs.readFileSync(file, 'utf8');
  var known = declaredIn(src);
  var found = {};

  // Statement position only: start of file, or after a line break or a brace,
  // semicolon or closing paren. "=" but not "==", "===", ">=" and friends.
  var assign = /(?:^|[;{}\)]|\n)\s*([A-Za-z_$][\w$]*)\s*=(?!=)/g, m;
  while ((m = assign.exec(src))) {
    var name = m[1];
    if (known[name] || KEYWORD.test(name)) continue;
    var line = src.slice(0, m.index).split('\n').length;
    (found[name] = found[name] || []).push(line);
  }

  var names = Object.keys(found);
  if (!names.length) { console.log('  ok    ' + file); return; }

  names.forEach(function (name) {
    bad++;
    console.log('  FAIL  ' + file);
    console.log('        "' + name + '" is assigned at line ' + found[name].join(', ') +
                ' and declared nowhere.');
  });
});

console.log('\n' + (bad ? bad + ' undeclared name(s)' : 'no undeclared names') + '\n');
process.exit(bad ? 1 : 0);
