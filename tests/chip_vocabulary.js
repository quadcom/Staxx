/* StaXX — the chip vocabulary agrees with itself.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/chip_vocabulary.js
 *
 * A chip's meaning is written down three times: the PHP lookup that draws
 * the first page load, the CHIP_LOOK table stacks.js uses for every refresh
 * after that, and the stylesheet that turns a class into a colour and a
 * mark into a glyph. Nothing checks that the three agree, and every way
 * they can drift is silent — a chip that looks one way on load and another
 * after a refresh, or a mark that renders as no glyph at all, both look
 * like a valid chip on screen. PLAN_167 fixed the vocabulary (five colours,
 * one meaning each); this is what keeps it fixed.
 *
 * A text scan, not a parser, so it is deliberately conservative — a pattern
 * that stops matching because the code was restructured is a FAILURE here,
 * never a silent pass, because the whole point is to notice drift the eye
 * would miss.
 */

'use strict';

var fs = require('fs');

var PHP_FILE  = 'src/staxx/usr/local/emhttp/plugins/staxx/include/StacksTable.php';
var JS_FILE   = 'src/staxx/usr/local/emhttp/plugins/staxx/javascript/stacks.js';
var CSS_FILE  = 'src/staxx/usr/local/emhttp/plugins/staxx/sheets/staxx.css';

// The approved palette, ruled in PLAN_167 §3 — one meaning per colour.
var PALETTE = {
  '#3fb950': 'green',
  '#8b949e': 'grey',
  '#58a6ff': 'blue',
  '#f0a020': 'amber',
  '#f85149': 'red'
};

var fails = [];
var warnings = [];

function fail(msg) { fails.push(msg); }

function lineAt(src, index) {
  return src.slice(0, index).split('\n').length;
}

function readFile(path) {
  try {
    return fs.readFileSync(path, 'utf8');
  } catch (e) {
    fail('cannot read ' + path + ' — ' + e.message);
    return null;
  }
}

// ---------------------------------------------------------------------
// Check 1 — the PHP $cls/$mark arrays and the JS CHIP_LOOK table agree.
// ---------------------------------------------------------------------

// Pulls a flat 'key' => 'value' PHP array out of the text between the
// square brackets following "$name = [". Each entry is on its own line
// in both source arrays, so a line-by-line scan is enough.
function readPhpAssoc(src, varName, file) {
  var re = new RegExp('\\$' + varName + '\\s*=\\s*\\[([\\s\\S]*?)\\]', 'm');
  var m = re.exec(src);
  if (!m) {
    fail(file + ': the "$' + varName + ' = [...]" lookup was not found — has it moved or been renamed?');
    return null;
  }
  var out = {};
  var entry = /'([^']+)'\s*=>\s*'([^']+)'/g, em;
  while ((em = entry.exec(m[1]))) out[em[1]] = em[2];
  if (!Object.keys(out).length) {
    fail(file + ': "$' + varName + '" was found but no \'meaning\' => \'value\' entries could be read out of it.');
    return null;
  }
  return out;
}

// Pulls meaning -> {cls, mark} out of stacks.js's CHIP_LOOK object.
function readChipLook(src, file) {
  var re = /CHIP_LOOK\s*=\s*\{([\s\S]*?)\};/m;
  var m = re.exec(src);
  if (!m) {
    fail(file + ': the "CHIP_LOOK = {...}" table was not found — has it moved or been renamed?');
    return null;
  }
  var out = {};
  var entry = /([A-Za-z_$][\w$]*)\s*:\s*\{\s*cls:\s*'([^']+)'\s*,\s*mark:\s*'([^']+)'\s*\}/g, em;
  while ((em = entry.exec(m[1]))) out[em[1]] = { cls: em[2], mark: em[3] };
  if (!Object.keys(out).length) {
    fail(file + ': "CHIP_LOOK" was found but no meaning entries could be read out of it.');
    return null;
  }
  return out;
}

function checkTablesAgree() {
  var phpSrc = readFile(PHP_FILE);
  var jsSrc  = readFile(JS_FILE);
  if (phpSrc === null || jsSrc === null) return;

  var phpCls  = readPhpAssoc(phpSrc, 'cls', PHP_FILE);
  var phpMark = readPhpAssoc(phpSrc, 'mark', PHP_FILE);
  var jsLook  = readChipLook(jsSrc, JS_FILE);
  if (!phpCls || !phpMark || !jsLook) return;

  var phpMeanings = {};
  Object.keys(phpCls).forEach(function (k) { phpMeanings[k] = true; });
  Object.keys(phpMark).forEach(function (k) { phpMeanings[k] = true; });
  var allMeanings = {};
  Object.keys(phpMeanings).forEach(function (k) { allMeanings[k] = true; });
  Object.keys(jsLook).forEach(function (k) { allMeanings[k] = true; });

  Object.keys(allMeanings).sort().forEach(function (meaning) {
    var inPhp = Object.prototype.hasOwnProperty.call(phpCls, meaning) &&
                Object.prototype.hasOwnProperty.call(phpMark, meaning);
    var inJs  = Object.prototype.hasOwnProperty.call(jsLook, meaning);

    if (inPhp && !inJs) {
      fail('meaning "' + meaning + '" is in the PHP lookup (' + PHP_FILE + ') but missing from CHIP_LOOK in ' + JS_FILE + '.');
      return;
    }
    if (inJs && !inPhp) {
      fail('meaning "' + meaning + '" is in CHIP_LOOK (' + JS_FILE + ') but missing from the PHP lookup in ' + PHP_FILE + '.');
      return;
    }

    var pCls = phpCls[meaning], pMark = phpMark[meaning];
    var jCls = jsLook[meaning].cls, jMark = jsLook[meaning].mark;
    if (pCls !== jCls) {
      fail('meaning "' + meaning + '": class disagrees — PHP says "' + pCls + '", CHIP_LOOK says "' + jCls + '".');
    }
    if (pMark !== jMark) {
      fail('meaning "' + meaning + '": mark disagrees — PHP says "' + pMark + '", CHIP_LOOK says "' + jMark + '".');
    }
  });
}

// ---------------------------------------------------------------------
// Check 2 — every --chip colour is one of the five approved hexes.
// ---------------------------------------------------------------------

function checkPalette() {
  var src = readFile(CSS_FILE);
  if (src === null) return;

  var re = /--chip\s*:\s*(#[0-9a-fA-F]{6})/g, m, count = 0;
  while ((m = re.exec(src))) {
    count++;
    var hex = m[1].toLowerCase();
    if (!PALETTE[hex]) {
      fail(CSS_FILE + ':' + lineAt(src, m.index) + ': "--chip: ' + m[1] +
           '" is not one of the five approved colours.');
    }
  }
  if (!count) {
    fail(CSS_FILE + ': no "--chip: #rrggbb" declarations were found at all — has the variable been renamed?');
  }
}

// ---------------------------------------------------------------------
// Check 3 — every mark used has a glyph, and every glyph is used.
// ---------------------------------------------------------------------

// Only a literal quoted mark counts as "used" — StacksTable.php also
// writes 'data-mark="'.$mark.'"' with an interpolated variable, which this
// pattern will not match, and rightly so: that occurrence is already
// covered by the lookup table it reads $mark from.
function collectLiteralMarks(src, out) {
  var re = /data-mark="([a-z]+)"/g, m;
  while ((m = re.exec(src))) out[m[1]] = true;
}

function checkMarksHaveGlyphs() {
  var phpSrc = readFile(PHP_FILE);
  var jsSrc  = readFile(JS_FILE);
  var cssSrc = readFile(CSS_FILE);
  if (phpSrc === null || jsSrc === null || cssSrc === null) return;

  var used = {};
  collectLiteralMarks(phpSrc, used);
  collectLiteralMarks(jsSrc, used);

  // The lookup tables themselves — a mark value can be assigned into a pill
  // without ever appearing as a literal data-mark="..." string elsewhere.
  var phpMark = readPhpAssoc(phpSrc, 'mark', PHP_FILE);
  if (phpMark) Object.keys(phpMark).forEach(function (k) { used[phpMark[k]] = true; });
  var jsLook = readChipLook(jsSrc, JS_FILE);
  if (jsLook) Object.keys(jsLook).forEach(function (k) { used[jsLook[k].mark] = true; });

  var defined = {};
  var re = /\.staxx-chipmark\[data-mark="([a-z]+)"\]/g, m, count = 0;
  while ((m = re.exec(cssSrc))) { defined[m[1]] = true; count++; }
  if (!count) {
    fail(CSS_FILE + ': no ".staxx-chipmark[data-mark=\"...\"]" rules were found at all — has the selector changed shape?');
    return;
  }

  Object.keys(used).sort().forEach(function (mark) {
    if (!defined[mark]) {
      fail('mark "' + mark + '" is used but ' + CSS_FILE + ' defines no glyph for it.');
    }
  });

  Object.keys(defined).sort().forEach(function (mark) {
    if (!used[mark]) {
      warnings.push('mark "' + mark + '" has a glyph in ' + CSS_FILE + ' but is never used — a tidy-up, not a bug.');
    }
  });
}

checkTablesAgree();
checkPalette();
checkMarksHaveGlyphs();

warnings.forEach(function (w) { console.log('  warn  ' + w); });

if (fails.length) {
  fails.forEach(function (f) { console.log('  FAIL  ' + f); });
  console.log('\n' + fails.length + ' chip vocabulary fault(s)\n');
  process.exit(1);
}

console.log('  ok    the chip vocabulary agrees with itself\n');
process.exit(0);
