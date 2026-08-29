/* StaXX — the user guide's own coverage report (PLAN_99 workstream 4).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/guide_coverage.js
 *
 * No framework, no npm, no network — the same shape as words.js and
 * stash_guard.js: one line per finding, and a summary at the end.
 *
 * IT MUST ALWAYS EXIT 0. THIS IS NOT A BUG. DO NOT "FIX" IT.
 *
 * Adrian chose a warning rather than a blocker deliberately: a guide gap is
 * never a reason to stop a release going out. `.github/workflows/release.yml`
 * globs and runs every tests/*.js, so the one thing this file must never do is
 * fail — a non-zero exit here would block every release over a missing bullet.
 * If a gap ever needs to be enforced, enforce it somewhere that is allowed to
 * fail, not by changing the exit code of this file.
 *
 * The index builder lives in tools/, is written separately, and is required
 * rather than reimplemented — two readings of the same markers would drift
 * apart, and then the check would disagree with the thing it is checking.
 */

'use strict';

var fs   = require('fs');
var path = require('path');

var ROOT      = path.join(__dirname, '..');
var GUIDE_DIR = path.join(ROOT, 'docs', 'guide');
var INDEX     = path.join(GUIDE_DIR, 'README.md');
var PLANS_DIR = path.join(ROOT, 'completed-plans');

var START = '<!-- pages:start -->';
var END   = '<!-- pages:end -->';

var problems = 0;   // things somebody should fix
var gaps     = 0;   // things knowingly not written yet
var unstated = 0;   // live plans that have not said which page covers them

function problem(text) { problems++; console.log('  PROBLEM  ' + text); }
function note(text)    { console.log('  ok       ' + text); }

function read(file) {
  try { return fs.readFileSync(file, 'utf8'); } catch (e) { return null; }
}

// Every comparison here is of generated text against hand-saved text, and the
// two disagree over trailing whitespace far more often than over content.
function normalise(text) {
  return String(text == null ? '' : text)
    .replace(/\r\n/g, '\n')
    .split('\n')
    .map(function (line) { return line.replace(/\s+$/, ''); })
    .join('\n')
    .replace(/^\n+|\n+$/g, '');
}

function guidePages() {
  var names;
  try { names = fs.readdirSync(GUIDE_DIR); } catch (e) { return []; }
  return names.filter(function (n) {
    return /\.md$/i.test(n) && n.toLowerCase() !== 'readme.md';
  }).sort();
}

/* The builder is a sibling piece of the same plan and may not be present yet.
 * A missing tool is a finding, not a crash — this file has to survive being
 * run against a half-landed branch. */
var builder = null, builderError = null;
try {
  builder = require('../tools/build-guide-index.js');
} catch (e) {
  // First line only: node appends a whole require stack, which turns one
  // finding into six lines of scrollback in the build log.
  builderError = String(e && e.message ? e.message : e).split('\n')[0];
}

var indexText = read(INDEX);
var pages     = guidePages();

/* =========================================================================
 * 1. Is the index in sync with the pages that exist?
 * ====================================================================== */

console.log('\n1. Index in sync');

(function () {
  if (indexText === null) {
    problem('the guide index is missing entirely — expected docs/guide/README.md');
    return;
  }

  // A page with no index comment cannot be listed by the builder, so it would
  // silently vanish from the index rather than showing up as a wrong bullet.
  var unmarked = pages.filter(function (name) {
    var body = read(path.join(GUIDE_DIR, name));
    return body === null || !/<!--\s*index:/i.test(body);
  });
  if (unmarked.length) {
    unmarked.forEach(function (name) {
      problem(name + ' has no "<!-- index: ... -->" line, so the index cannot list it');
    });
  } else if (pages.length) {
    note('all ' + pages.length + ' guide pages carry their index comment');
  }

  var from = indexText.indexOf(START);
  var to   = indexText.indexOf(END);
  if (from < 0 || to < 0 || to < from) {
    problem('the guide index has no ' + START + ' / ' + END + ' markers, so the '
            + 'bullet list is still hand-maintained and cannot be checked');
    return;
  }

  var current = normalise(indexText.slice(from + START.length, to));

  // Which pages the index currently points at, and which exist on disk. This
  // half works whether or not the builder is present, and it is the half that
  // names a specific page, so it runs first.
  var listed = {};
  var link = /\]\(\s*([^)\s#]+)/g, m;
  while ((m = link.exec(current)) !== null) {
    listed[path.basename(m[1])] = true;
  }

  pages.forEach(function (name) {
    if (!listed[name]) problem(name + ' exists but is not listed in the index');
  });
  Object.keys(listed).forEach(function (name) {
    if (pages.indexOf(name) < 0) {
      problem('the index lists ' + name + ', which is not a page in docs/guide/');
    }
  });

  if (!builder) {
    problem('the index builder (tools/build-guide-index.js) could not be loaded, so '
            + 'the index cannot be regenerated and compared — ' + builderError);
    return;
  }
  if (typeof builder.collectPages !== 'function' || typeof builder.renderBullets !== 'function') {
    problem('the index builder does not export collectPages() and renderBullets(), '
            + 'so the index cannot be regenerated and compared');
    return;
  }

  var expected;
  try {
    expected = normalise(builder.renderBullets(builder.collectPages(GUIDE_DIR)));
  } catch (e) {
    problem('the index builder threw while regenerating the bullet list — '
            + (e && e.message ? e.message : String(e)));
    return;
  }

  if (expected === current) {
    note('the bullet list matches what the builder would write');
  } else {
    problem('the bullet list has been hand-edited away from what the builder would '
            + 'write — run the index builder to put it back');
  }
})();

/* =========================================================================
 * 2. Do the links resolve?
 *
 * Covers links between guide pages, the ../glossary.md line every page closes
 * with, and image references such as ../images/guide/foo.png. No page uses an
 * image today, but PLAN_99 workstream 7 adds them, and a picture that was
 * renamed or never committed should be named here rather than showing up as a
 * broken image on the published site.
 * ====================================================================== */

console.log('\n2. Links resolve');

(function () {
  var all = pages.slice();
  if (indexText !== null) all.push('README.md');
  all.sort();

  var checked = 0, broken = 0;

  all.forEach(function (name) {
    var body = read(path.join(GUIDE_DIR, name));
    if (body === null) return;

    // Inline links and image references alike; an optional "title" is allowed
    // after the target because markdown permits it.
    var re = /!?\[[^\]\n]*\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/g, m;
    while ((m = re.exec(body)) !== null) {
      var target = m[1];

      // Somewhere else entirely, or a jump within this same page.
      if (/^[a-z][a-z0-9+.-]*:/i.test(target)) continue;
      if (target.charAt(0) === '#') continue;

      target = target.split('#')[0];
      if (!target) continue;

      checked++;
      var resolved = path.resolve(GUIDE_DIR, target);
      if (!fs.existsSync(resolved)) {
        broken++;
        problem(name + ' → ' + m[1]);
      }
    }
  });

  if (!broken) note(checked + ' relative links and image references all resolve');
})();

/* =========================================================================
 * 3. What the guide itself admits is missing.
 * ====================================================================== */

console.log('\n3. Known gaps');

(function () {
  if (indexText === null) return;

  var head = indexText.match(/^##\s+Not written yet\s*$/mi);
  if (!head) {
    problem('the guide index has no "Not written yet" section, so undocumented '
            + 'features are recorded nowhere');
    return;
  }

  var rest  = indexText.slice(head.index + head[0].length);
  var next  = rest.search(/^##\s/m);
  var para  = (next < 0 ? rest : rest.slice(0, next)).trim();

  if (!para) { note('nothing is listed as unwritten'); return; }

  console.log('           ' + para.replace(/\s*\n\s*/g, ' '));

  /* The paragraph is prose, so counting is a best effort: the items follow the
   * last colon, separated by commas. Getting the count slightly wrong here is
   * harmless — the paragraph itself is printed above, which is the point. */
  var tail = para.lastIndexOf(':') >= 0 ? para.slice(para.lastIndexOf(':') + 1) : para;
  var items = tail.split(/,\s*(?:and\s+)?/).map(function (s) {
    return s.replace(/\s+/g, ' ').replace(/\.$/, '').trim();
  }).filter(function (s) { return s.length > 0; });

  gaps += items.length || 1;
})();

/* =========================================================================
 * 4. Plans that never said which guide page covers them.
 *
 * The convention is one line in a plan file reading either
 *   Guide page: some-slug.md
 * or
 *   Guide page: none — internal
 *
 * The 32 plans already in completed-plans/ predate it and are deliberately not
 * retrofitted, so listing them by name every build would be noise nobody reads
 * and would bury a real finding. They are counted in one line instead. Only
 * the live plans in the repository root are named, because those are the ones
 * somebody is still in a position to fix.
 * ====================================================================== */

console.log('\n4. Plans with no stated guide page');

(function () {
  var HAS = /^\s*Guide page:\s*\S/mi;

  function plansIn(dir) {
    var names;
    try { names = fs.readdirSync(dir); } catch (e) { return []; }
    return names.filter(function (n) { return /^PLAN_.*\.md$/i.test(n); }).sort();
  }

  var older = plansIn(PLANS_DIR).filter(function (n) {
    return !HAS.test(read(path.join(PLANS_DIR, n)) || '');
  });
  if (older.length) {
    console.log('           ' + older.length + ' completed plans predate the Guide page '
                + 'convention (not listed)');
  }

  var live = plansIn(ROOT);
  var missing = live.filter(function (n) {
    return !HAS.test(read(path.join(ROOT, n)) || '');
  });

  /* Counted apart from the problems above rather than added to them: today
   * every live plan predates the convention, and thirteen of these would bury
   * a single broken link, which is the finding that actually matters. */
  unstated += missing.length;
  missing.forEach(function (n) {
    console.log('  unstated ' + n + ' has no "Guide page:" line');
  });

  if (!missing.length) {
    note('all ' + live.length + ' live plans state a guide page');
  }
})();

/* ===================================================================== */

console.log('\nguide coverage: ' + problems + ' problem' + (problems === 1 ? '' : 's')
            + ', ' + gaps + ' known gap' + (gaps === 1 ? '' : 's')
            + (unstated ? ', ' + unstated + ' live plan' + (unstated === 1 ? '' : 's')
                          + ' not yet stating a guide page' : '') + '\n');

// Always 0. See the note at the top of this file before changing this line.
process.exit(0);
