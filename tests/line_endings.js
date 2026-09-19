/* StaXX — every file that reaches the server uses LF.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/line_endings.js
 *
 * Development happens on Windows; everything here runs on Linux. A carriage
 * return does not fail loudly, it fails silently: Unraid's PageBuilder splits
 * a .page file on the literal "\n---\n", so a CRLF copy never separates its
 * header from its body and the page is dropped with one line in the syslog;
 * a shell script with CRLF dies on "\r: command not found".
 *
 * .gitattributes forces LF into the REPOSITORY, which is why a clone is
 * always right and why this went unnoticed for so long. It says nothing about
 * the working copy, and the working copy is what pkg_build.sh packages and
 * what the deploy copies to the server. On 2026-09-18 every .js, .css and
 * .php file on disk here held CRLF while the repository held LF, so the
 * server had been receiving CRLF for as long as anyone had deployed from
 * this machine. JavaScript, CSS and PHP tolerate it; the two file types that
 * do not were, by luck, already LF.
 *
 * So this checks the FILES ON DISK, deliberately, not what git would commit.
 */

'use strict';

var fs = require('fs');
var path = require('path');

// Everything that reaches a Linux host, plus the developer-facing text that
// sits beside it. Binary fixtures are listed in .gitattributes and skipped
// here by extension rather than by sniffing.
var TEXT = ['.js', '.css', '.php', '.page', '.plg', '.sh', '.cfg', '.md',
            '.json', '.yml', '.yaml', '.py', '.html', '.svg', '.xml', '.txt'];
// Matched at the REPOSITORY ROOT only, never by name further down: the plugin
// itself lives under src/staxx/usr/local/emhttp/, so skipping every directory
// called "local" wherever it appears silently excludes the whole of the thing
// being checked. That is exactly what the first version of this file did.
var SKIP_ROOT = ['.git', 'node_modules', 'plans', 'summaries', 'local', '.preview', 'build'];

var offenders = [];
var checked = 0;

function walk(dir, atRoot) {
  fs.readdirSync(dir, { withFileTypes: true }).forEach(function (entry) {
    var full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (atRoot && SKIP_ROOT.indexOf(entry.name) >= 0) return;
      walk(full, false);
      return;
    }
    if (TEXT.indexOf(path.extname(entry.name).toLowerCase()) < 0) return;
    checked++;
    var buf = fs.readFileSync(full);
    if (buf.indexOf(13) >= 0) offenders.push(path.relative('.', full));
  });
}

walk('.', true);

console.log('checked ' + checked + ' text files');

if (offenders.length) {
  console.log('\nCARRIAGE RETURNS FOUND in ' + offenders.length + ' file(s):\n');
  offenders.slice(0, 40).forEach(function (f) { console.log('  ' + f); });
  if (offenders.length > 40) console.log('  …and ' + (offenders.length - 40) + ' more');
  console.log('\nFix them in place, then keep them fixed:');
  console.log('  git config core.autocrlf false        # this repository, once');
  console.log('  node tests/line_endings.js --fix      # rewrite the offenders as LF');
  process.exitCode = 1;
} else {
  console.log('LF everywhere');
}

// --fix rewrites the offenders in place. Kept in the same file as the check so
// the instruction printed above cannot drift from the thing that does it.
if (process.argv.indexOf('--fix') >= 0 && offenders.length) {
  offenders.forEach(function (f) {
    var buf = fs.readFileSync(f);
    fs.writeFileSync(f, Buffer.from(buf.toString('binary').replace(/\r\n/g, '\n').replace(/\r/g, '\n'), 'binary'));
  });
  console.log('\nrewrote ' + offenders.length + ' file(s) as LF');
  process.exitCode = 0;
}
