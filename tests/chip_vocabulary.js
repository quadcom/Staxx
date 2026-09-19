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

// ---------------------------------------------------------------------
// Check 4 — the legend (PLAN_168) is a window onto the SAME chips the
// grid draws, never hand-written lookalikes, and the red "broken" colour
// is never paired again with the 'question' mark PLAN_168 decision C
// retired.
//
// This is a text scan, so it checks what a text scan honestly can:
//
//   - every class and every mark a legend row (LEGEND_STATE, LEGEND_UPDATE,
//     LEGEND_TOPBAR in stacks.js) uses is also found, as a literal, in the
//     page's own chip-drawing code (StacksTable.php / stacks.js) OUTSIDE
//     the legend's own three arrays — proof the legend did not invent a
//     class or a mark of its own.
//   - every such class and mark the page's own code draws is also present
//     somewhere in the legend — proof nothing is shown on a row or in the
//     top bar that the legend leaves out.
//   - the 'question' mark belongs to exactly one meaning across the whole
//     vocabulary, and that meaning's class is never one this suite can
//     read off the stylesheet as red.
//
// What this does NOT cover, because a text scan cannot: whether a class
// and a mark drawn together on one real row are the SAME pairing the
// legend shows for that class — only that both independently appear
// somewhere real. Genuinely checking the pairing would need the DOM the
// code builds at runtime, not this file's text. A chip that reused an
// already-legended class with a new, undocumented mark could slip past
// this suite.
// ---------------------------------------------------------------------

// A hover-only addition to an existing chip's own meaning — "any of the
// above with a danger border" (PLAN_168 §4) or a size tweak for the small
// per-service pending chip — never a chip of its own, so it is excluded
// from the "every page class is in the legend" comparison below, where it
// would otherwise demand a legend row that could only ever repeat one
// already there.
var MODIFIER_CLASSES = {
  'staxx-updatepill--noted': true,
  'staxx-pendingchip--service': true
};

// Cuts the three legend arrays out of the JS source before it is scanned
// for "what does the page itself draw" — without this, every legend class
// and mark would trivially find itself, and Check 4 would prove nothing.
function stripLegendArrays(src) {
  return ['LEGEND_STATE', 'LEGEND_UPDATE', 'LEGEND_TOPBAR'].reduce(function (s, name) {
    return s.replace(new RegExp('var\\s+' + name + '\\s*=\\s*\\[[\\s\\S]*?\\n\\s*\\];'), '');
  }, src);
}

// Pulls the rows out of one LEGEND_* array. Each row is a flat object (no
// row nests braces inside itself) in the fixed field order tag/cls/mark —
// see the array literals themselves — so a row is read as everything
// between "cls:" and "mark:" and between "mark:" and "text:", not parsed
// as JavaScript. A row whose shape has changed is a FAILURE, not a skip.
function readLegendRows(src, varName) {
  var re = new RegExp('var\\s+' + varName + '\\s*=\\s*\\[([\\s\\S]*?)\\n\\s*\\];', 'm');
  var m = re.exec(src);
  if (!m) {
    fail(JS_FILE + ': the "' + varName + ' = [...]" legend table was not found — has it moved or been renamed?');
    return null;
  }
  var rows = [];
  var rowRe = /\{[^{}]*\}/g, rm;
  while ((rm = rowRe.exec(m[1]))) {
    var text = rm[0];
    var clsM  = /cls:\s*([^\n]*?),\s*mark:/.exec(text);
    var markM = /mark:\s*([^\n]*?),\s*text:/.exec(text);
    if (!clsM || !markM) {
      fail(JS_FILE + ': a row in "' + varName + '" is not in the expected "cls: ..., mark: ..., text: ..." shape — has the legend row shape changed?');
      continue;
    }
    rows.push({ varName: varName, clsExpr: clsM[1].trim(), markExpr: markM[1].trim() });
  }
  if (!rows.length) {
    fail(JS_FILE + ': "' + varName + '" was found but no rows could be read out of it.');
    return null;
  }
  return rows;
}

// A row's cls/mark field is either a plain string or a reference into
// CHIP_LOOK ('staxx-updatepill ' + CHIP_LOOK.update.cls), joined with '+'
// exactly the way the JS itself builds it — resolved here the same way,
// against the jsLook table Check 1 already knows how to read.
function resolveLegendExpr(expr, jsLook, varName, field) {
  var parts = expr.split('+').map(function (s) { return s.trim(); });
  var out = '', ok = true;
  parts.forEach(function (p) {
    var lit = /^'([^']*)'$/.exec(p);
    var ref = new RegExp('^CHIP_LOOK\\.([A-Za-z_$][\\w$]*)\\.' + field + '$').exec(p);
    if (lit) {
      out += lit[1];
    } else if (ref) {
      if (!jsLook[ref[1]]) {
        fail(JS_FILE + ': "' + varName + '" references CHIP_LOOK.' + ref[1] + '.' + field + ', which is not a meaning CHIP_LOOK defines.');
        ok = false;
      } else {
        out += jsLook[ref[1]][field];
      }
    } else {
      fail(JS_FILE + ': "' + varName + '" has a ' + field + ' expression this suite cannot read: "' + p + '" (expected a plain string or CHIP_LOOK.<meaning>.' + field + ').');
      ok = false;
    }
  });
  return ok ? out : null;
}

function collectVocabClasses(src, out) {
  var re = /staxx-pill--[a-z]+|staxx-updatepill--[a-z]+|staxx-pendingchip(?:--[a-z]+)?/g, m;
  while ((m = re.exec(src))) out[m[0]] = true;
}

function checkLegendVocabulary() {
  var phpSrc = readFile(PHP_FILE);
  var jsSrc  = readFile(JS_FILE);
  var cssSrc = readFile(CSS_FILE);
  if (phpSrc === null || jsSrc === null || cssSrc === null) return;

  var jsLook = readChipLook(jsSrc, JS_FILE);
  if (!jsLook) return;

  var stateRows  = readLegendRows(jsSrc, 'LEGEND_STATE');
  var updateRows = readLegendRows(jsSrc, 'LEGEND_UPDATE');
  var topbarRows = readLegendRows(jsSrc, 'LEGEND_TOPBAR');
  if (!stateRows || !updateRows || !topbarRows) return;

  // Which classes count as red — read off the stylesheet itself, the same
  // hex Check 2's PALETTE approves, rather than a second hard-coded list of
  // class names that could drift from the CSS on its own.
  var redClasses = {};
  var blockRe = /([^{}]+)\{([^{}]*)\}/g, bm;
  while ((bm = blockRe.exec(cssSrc))) {
    if (!/--chip:\s*#f85149\b/i.test(bm[2])) continue;
    var clsRe = /\.([\w-]+)/g, cm;
    while ((cm = clsRe.exec(bm[1]))) redClasses[cm[1]] = true;
  }
  if (!Object.keys(redClasses).length) {
    fail(CSS_FILE + ': no rule declaring "--chip: #f85149" was found — the red/question check has nothing to compare against.');
    return;
  }

  var legendClasses = {}, legendMarks = {};

  stateRows.concat(updateRows, topbarRows).forEach(function (row) {
    var cls  = resolveLegendExpr(row.clsExpr, jsLook, row.varName, 'cls');
    var mark = resolveLegendExpr(row.markExpr, jsLook, row.varName, 'mark');
    if (cls === null || mark === null) return;

    cls.split(/\s+/).filter(Boolean).forEach(function (token) {
      if (token !== 'staxx-pill' && token !== 'staxx-updatepill') legendClasses[token] = true;
    });
    if (mark) legendMarks[mark] = true;

    if (mark === 'question') {
      cls.split(/\s+/).forEach(function (token) {
        if (redClasses[token]) {
          fail('a legend row in ' + row.varName + ' pairs the red class "' + token +
               '" with the "question" mark — PLAN_168 decision C removed the red question chip; it must not come back.');
        }
      });
    }
  });

  var jsStripped = stripLegendArrays(jsSrc);
  var pageClasses = {}, pageMarks = {};
  collectVocabClasses(phpSrc, pageClasses);
  collectVocabClasses(jsStripped, pageClasses);
  collectLiteralMarks(phpSrc, pageMarks);
  collectLiteralMarks(jsStripped, pageMarks);

  // The lookup tables' own values count as "drawn on the page" too, same as
  // Check 3's reasoning: a class or mark assigned through $cls/$mark or
  // CHIP_LOOK never has to appear a second time as a literal to be real.
  var phpCls = readPhpAssoc(phpSrc, 'cls', PHP_FILE);
  if (phpCls) Object.keys(phpCls).forEach(function (k) { pageClasses[phpCls[k]] = true; });
  Object.keys(jsLook).forEach(function (k) {
    pageClasses[jsLook[k].cls] = true;
    if (jsLook[k].mark) pageMarks[jsLook[k].mark] = true;
  });

  Object.keys(legendClasses).sort().forEach(function (cls) {
    if (!pageClasses[cls]) {
      fail('the legend draws a chip using class "' + cls + '", which this suite cannot find anywhere in the page\'s own chip-drawing code — drift, or a typo.');
    }
  });
  Object.keys(legendMarks).sort().forEach(function (mark) {
    if (!pageMarks[mark]) {
      fail('the legend draws a chip marked "' + mark + '", which this suite cannot find anywhere in the page\'s own chip-drawing code — drift, or a typo.');
    }
  });
  Object.keys(pageClasses).sort().forEach(function (cls) {
    if (MODIFIER_CLASSES[cls]) return;
    if (!legendClasses[cls]) {
      fail('class "' + cls + '" is drawn somewhere on the page but no legend row uses it — the legend is missing a chip.');
    }
  });
  Object.keys(pageMarks).sort().forEach(function (mark) {
    if (!legendMarks[mark]) {
      fail('mark "' + mark + '" is drawn somewhere on the page but no legend row uses it — the legend is missing a chip.');
    }
  });

  // The direct decision-C check: 'question' has to mean exactly one thing
  // across the whole vocabulary (blue, meaning "notfound" — the image or
  // its tag is gone from the registry). A second meaning claiming the mark,
  // red or otherwise, is exactly how the retired chip would sneak back in
  // under a new name.
  var phpMark = readPhpAssoc(phpSrc, 'mark', PHP_FILE);
  var questionMeanings = [];
  if (phpMark) {
    Object.keys(phpMark).forEach(function (k) { if (phpMark[k] === 'question') questionMeanings.push(k); });
  }
  Object.keys(jsLook).forEach(function (k) {
    if (jsLook[k].mark === 'question' && questionMeanings.indexOf(k) < 0) questionMeanings.push(k);
  });
  if (questionMeanings.length !== 1 || questionMeanings[0] !== 'notfound') {
    fail('the "question" mark is used by meaning(s) [' + questionMeanings.join(', ') +
         '] — PLAN_168 decision C says it means exactly one thing ("notfound": blue, the image or its tag ' +
         'is gone from the registry). It has either drifted to a new meaning or gone missing.');
  } else if (phpCls && redClasses[phpCls['notfound']]) {
    fail('meaning "notfound" (the "question" mark) uses class "' + phpCls['notfound'] +
         '", which this suite reads off the stylesheet as RED — PLAN_168 decision C retired the red question chip.');
  }
}

checkTablesAgree();
checkPalette();
checkMarksHaveGlyphs();
checkLegendVocabulary();

warnings.forEach(function (w) { console.log('  warn  ' + w); });

if (fails.length) {
  fails.forEach(function (f) { console.log('  FAIL  ' + f); });
  console.log('\n' + fails.length + ' chip vocabulary fault(s)\n');
  process.exit(1);
}

console.log('  ok    the chip vocabulary agrees with itself\n');
process.exit(0);
