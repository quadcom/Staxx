/* StaXX — generates the guide's contents list from the pages themselves (PLAN_99).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tools/build-guide-index.js
 *
 * No framework, no npm, no network. Two branches that each add a guide page
 * used to collide on the hand-written list in the guide's README; each page
 * now carries its own one-line marker and the list is rebuilt from those, so
 * adding a page touches one file instead of two.
 *
 * A page without a marker is a hard error rather than a page quietly left out
 * of the contents — being dropped from the guide is the exact failure this
 * exists to prevent, and it is invisible if it is allowed to pass.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var GUIDE_DIR = path.join(__dirname, '..', 'docs', 'guide');
var INDEX_FILE = 'README.md';
var START = '<!-- pages:start -->';
var END = '<!-- pages:end -->';

/* The width the guide's prose is already wrapped to; matching it is what keeps
 * a regenerated list byte-identical to the hand-written one it replaces. */
var WIDTH = 100;
var INDENT = '  ';

/* The optional third field names the page's parent for its breadcrumb trail. */
var MARKER = /^<!--\s*index:\s*([^|]*?)\s*\|\s*(.*?)\s*(?:\|\s*parent:\s*(\S+?\.md))?\s*-->\s*$/;
var TRAIL_START = '[StaXX guide](' + INDEX_FILE + ')';
var SEP = ' › ';
var HEADING = /^#\s+(.*?)\s*$/;

/* Reads one page's title, sort key and summary. The title comes from the H1
 * and never from the marker, so a page's heading and its line in the contents
 * cannot drift apart. */
function readPage(guideDir, file) {
  var lines = fs.readFileSync(path.join(guideDir, file), 'utf8').split('\n');
  var title = '';
  var order = null;
  var summary = '';
  var parent = null;
  var i;

  for (i = 0; i < lines.length; i++) {
    var head = title === '' ? HEADING.exec(lines[i]) : null;
    if (head) { title = head[1]; continue; }
    var mark = MARKER.exec(lines[i]);
    if (mark) {
      order = Number(mark[1]);
      summary = mark[2];
      parent = mark[3] || null;
      break;
    }
  }

  if (order === null) {
    throw new Error(file + ' has no "<!-- index: N | summary -->" line, so it would be '
      + 'left out of the guide contents. Add one just below its heading.');
  }
  if (!isFinite(order)) {
    throw new Error(file + ' has an index marker whose sort number is not a number.');
  }
  if (title === '') {
    throw new Error(file + ' has no "# Heading" line to take its title from.');
  }
  if (summary === '') {
    throw new Error(file + ' has an index marker with no summary after the "|".');
  }

  return { file: file, order: order, title: title, link: file, summary: summary,
    parent: parent };
}

function collectPages(guideDir) {
  var dir = guideDir || GUIDE_DIR;
  var files = fs.readdirSync(dir).filter(function (f) {
    return /\.md$/.test(f) && f !== INDEX_FILE;
  });
  var pages = files.map(function (f) { return readPage(dir, f); });
  pages.sort(function (a, b) {
    if (a.order !== b.order) { return a.order - b.order; }
    return a.file < b.file ? -1 : (a.file > b.file ? 1 : 0);
  });
  return pages;
}

function wrap(text) {
  var words = text.split(/\s+/);
  var lines = [];
  var line = words.shift() || '';
  words.forEach(function (word) {
    var indent = lines.length === 0 ? '' : INDENT;
    if ((indent + line + ' ' + word).length > WIDTH) {
      lines.push(line);
      line = word;
    } else {
      line = line + ' ' + word;
    }
  });
  lines.push(line);
  return lines.map(function (l, n) { return n === 0 ? l : INDENT + l; }).join('\n');
}

function renderBullets(pages) {
  return pages.map(function (p) {
    return wrap('- **[' + p.title + '](' + p.link + ')** — ' + p.summary);
  }).join('\n');
}

function readIndex(guideDir) {
  return fs.readFileSync(path.join(guideDir || GUIDE_DIR, INDEX_FILE), 'utf8');
}

/* Splices the generated block between the two markers and leaves every other
 * line of the file alone — the opening paragraph, the gaps list and the
 * glossary line are hand-written and stay that way. */
function spliceIndex(current, bullets) {
  var a = current.indexOf(START);
  var b = current.indexOf(END);
  if (a === -1 || b === -1 || b < a) {
    throw new Error(INDEX_FILE + ' has no "' + START + '" / "' + END + '" pair to write between.');
  }
  return current.slice(0, a + START.length) + '\n\n' + bullets + '\n\n' + current.slice(b);
}

function writeIndex(guideDir, bullets) {
  var dir = guideDir || GUIDE_DIR;
  var current = readIndex(dir);
  var next = spliceIndex(current, bullets);
  if (next === current) { return false; }
  fs.writeFileSync(path.join(dir, INDEX_FILE), next);
  return true;
}

/* The breadcrumb line each page should carry: the guide's front page, every
 * ancestor by its own heading, then the page's own heading unlinked. A missing
 * parent or a loop is an error naming the page, never a silently short trail. */
function expectedTrails(pages) {
  var byFile = {};
  var trails = {};
  pages.forEach(function (p) { byFile[p.file] = p; });
  pages.forEach(function (p) {
    var parts = [p.title];
    var seen = {};
    var cur = p;
    seen[p.file] = true;
    while (cur.parent !== null) {
      var up = byFile[cur.parent];
      if (!up) { throw new Error(p.file + ' names parent ' + cur.parent + ', which is not a guide page.'); }
      if (seen[up.file]) { throw new Error(p.file + ' has a parent loop through ' + up.file + '.'); }
      seen[up.file] = true;
      parts.unshift('[' + up.title + '](' + up.link + ')');
      cur = up;
    }
    parts.unshift(TRAIL_START);
    trails[p.file] = parts.join(SEP);
  });
  return trails;
}

/* Puts the trail one blank line below the index marker, replacing the line
 * already there if it starts the same way and touching nothing else. */
function applyTrail(text, trail) {
  var lines = text.split('\n');
  var m = -1;
  var i;
  for (i = 0; i < lines.length; i++) {
    if (MARKER.test(lines[i])) { m = i; break; }
  }
  if (m === -1) { return text; }
  var j = m + 1;
  while (j < lines.length && lines[j].trim() === '') { j++; }
  if (j < lines.length && lines[j].indexOf(TRAIL_START) === 0) {
    lines.splice(m + 1, j - m, '', trail);
  } else {
    lines.splice(m + 1, j - m - 1, '', trail, '');
  }
  return lines.join('\n');
}

/* Writes every page's trail; returns how many files changed. */
function writeTrails(guideDir, pages) {
  var dir = guideDir || GUIDE_DIR;
  var trails = expectedTrails(pages);
  var n = 0;
  pages.forEach(function (p) {
    var file = path.join(dir, p.file);
    var current = fs.readFileSync(file, 'utf8');
    var next = applyTrail(current, trails[p.file]);
    if (next !== current) { fs.writeFileSync(file, next); n++; }
  });
  return n;
}

module.exports = {
  expectedTrails: expectedTrails,
  writeTrails: writeTrails,
  collectPages: collectPages,
  renderBullets: renderBullets,
  readIndex: readIndex,
  writeIndex: writeIndex,
  spliceIndex: spliceIndex
};

if (require.main === module) {
  try {
    var pages = collectPages(GUIDE_DIR);
    pages.forEach(function (p) {
      console.log('  ' + String(p.order) + '  ' + p.file + '  —  ' + p.title);
    });
    var changed = writeIndex(GUIDE_DIR, renderBullets(pages));
    console.log('\n' + pages.length + ' page(s); docs/guide/' + INDEX_FILE
      + (changed ? ' rewritten.' : ' already up to date.'));
    console.log(writeTrails(GUIDE_DIR, pages) + ' page trail(s) written.');
  } catch (e) {
    console.error('\n' + e.message + '\n');
    process.exit(1);
  }
}
