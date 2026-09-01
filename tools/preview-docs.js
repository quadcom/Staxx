/* StaXX — renders the readme and the user guide exactly as GitHub would, locally.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tools/preview-docs.js          all of it, then open the index
 *   node tools/preview-docs.js README.md docs/guide/marks.md
 *
 * Written so a page can be looked at and approved before it is pushed, rather
 * than pushed in order to find out how it looks. Nothing here touches git and
 * nothing leaves the machine except the markdown itself.
 *
 * It does NOT approximate GitHub's formatting — it asks GitHub to do the
 * rendering, through the same markdown endpoint the site itself uses, so alert
 * boxes, task lists and tables come back exactly as they will appear. That is
 * the whole reason for the network call: a local markdown library gets the
 * common cases right and the interesting ones wrong, which is precisely
 * backwards for something whose job is to catch a surprise before it ships.
 *
 * Needs the GitHub CLI, already signed in — no token is read or stored here.
 *
 * Two rewrites happen to the HTML that comes back, both so the preview behaves
 * like the real thing rather than a wall of broken links:
 *
 *   Images  — copied in beside the pages and pointed at with a relative path,
 *             which is what makes the output a self-contained folder. That is
 *             the whole reason it can be handed to a web server elsewhere and
 *             still show its pictures.
 *   Links   — a link to another markdown file becomes a link to that file's own
 *             preview, so the guide can be clicked through page to page the way
 *             a reader would. A link to a page that was not rendered this run is
 *             left alone rather than pointed somewhere that does not exist.
 *
 * Output goes to .preview/, which .gitignore excludes: generated HTML has no
 * business in the repository, and a stale copy of it would be worse than none.
 * `--out <dir>` writes it somewhere else instead — a folder that can be handed
 * straight to nginx, which is how the copy on the server is fed.
 */

'use strict';

var fs = require('fs');
var path = require('path');
var os = require('os');
var execFileSync = require('child_process').execFileSync;

var ROOT = path.resolve(__dirname, '..');

/* Where the rendered pages land. `--out <dir>` puts them somewhere else — the
 * output is self-contained, so it can be handed straight to a web server
 * rather than only read here. */
var outArg = process.argv.indexOf('--out');
var OUT = outArg > 0 && process.argv[outArg + 1]
  ? path.resolve(process.argv[outArg + 1])
  : path.join(ROOT, '.preview');

// Where the stylesheet is cached. Fetched once and reused, so an ordinary run
// makes exactly one network call per page and none for the styling.
var CSS_FILE = path.join(OUT, 'github-markdown.css');
var CSS_URL = 'https://cdn.jsdelivr.net/npm/github-markdown-css@5/github-markdown.css';

/* The set previewed when no arguments are given: everything a person reads,
 * and nothing a person does not. The thinking documents and the plan files are
 * deliberately absent — they are not written to be looked at. */
function defaultFiles() {
  var files = ['README.md', 'CHANGELOG.md', 'docs/README.md'];
  var guide = path.join(ROOT, 'docs', 'guide');
  fs.readdirSync(guide)
    .filter(function (f) { return f.endsWith('.md'); })
    .sort()
    .forEach(function (f) { files.push('docs/guide/' + f); });
  files.push('docs/glossary.md');
  return files.filter(function (f) { return fs.existsSync(path.join(ROOT, f)); });
}

/* One markdown file, rendered by GitHub itself.
 *
 * The text goes in on stdin rather than as an argument: the readme is far past
 * any platform's command-line length limit, and the failure that produces is a
 * truncated page rather than an error, which would be a preview quietly lying
 * about what it was showing.
 *
 * `context` is what makes a bare issue reference render the way it does on the
 * repository's own pages. `mode=gfm` is what turns an alert block into an alert
 * box rather than a plain quotation. */
function render(markdown) {
  return execFileSync('gh', [
    'api', '--method', 'POST', 'markdown',
    '-f', 'mode=gfm',
    '-f', 'context=quadcom/Staxx',
    '--field', 'text=@-'
  ], { input: markdown, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
}

/* The preview file a source file maps to. Flattened — docs/guide/marks.md
 * becomes docs-guide-marks.html — because a flat folder means the relative
 * paths inside the HTML are all rewritten to absolute ones anyway, and one
 * directory is easier to clear out than a tree. */
function previewName(rel) {
  return rel.replace(/[\\/]/g, '-').replace(/\.md$/i, '') + '.html';
}

/* Every working-copy file some page referred to, gathered while rewriting and
 * copied into the output afterwards. Copying rather than pointing at the
 * original is what makes the output a self-contained folder — which is the
 * whole reason it can be handed to a web server somewhere else and still show
 * its pictures. */
var assets = {};

/* A file in the working copy, as a URL relative to the output folder. Not
 * file:/// : a page served over http cannot load a file:// image, and the
 * browser here refuses file:// URLs outright, so a preview served any other
 * way would be one nobody could check. */
function repoUrl(abs) {
  var rel = path.relative(ROOT, abs).replace(/\\/g, '/');
  if (rel.indexOf('..') === 0) return null;      // outside the working copy — leave it alone
  assets[rel] = abs;
  return 'repo/' + rel.split('/').map(encodeURIComponent).join('/');
}

/* Point every relative image and link at something that actually resolves from
 * the preview folder. `rel` is the source file's own path, because a relative
 * reference is relative to the file that wrote it, not to where it is shown. */
function rewrite(html, rel, rendered) {
  var dir = path.dirname(path.join(ROOT, rel));

  html = html.replace(/(<img\b[^>]*?\bsrc=")([^"]+)(")/gi, function (all, a, src, b) {
    if (/^(https?:|data:|file:|#)/i.test(src)) return all;
    var u = repoUrl(path.resolve(dir, src));
    return u ? a + u + b : all;
  });

  html = html.replace(/(<a\b[^>]*?\bhref=")([^"]+)(")/gi, function (all, a, href, b) {
    if (/^(https?:|mailto:|#)/i.test(href)) return all;

    var hash = '';
    var hashAt = href.indexOf('#');
    if (hashAt >= 0) { hash = href.slice(hashAt); href = href.slice(0, hashAt); }
    if (!href) return all;

    var target = path.relative(ROOT, path.resolve(dir, href)).replace(/\\/g, '/');

    // Another page in this run — link to its preview.
    if (rendered.indexOf(target) >= 0) return a + previewName(target) + hash + b;

    // A markdown page NOT in this run: leave it exactly as it was. Pointing it
    // at a file that was never written would look like a broken document
    // rather than a page simply not being part of the preview.
    if (/\.md$/i.test(target)) return all;

    // Anything else on disk — a picture linked rather than embedded, say.
    var abs = path.resolve(ROOT, target);
    if (!fs.existsSync(abs)) return all;
    var u = repoUrl(abs);
    return u ? a + u + hash + b : all;
  });

  return html;
}

function page(title, body, css, nav) {
  return '<!doctype html>\n<html lang="en"><head><meta charset="utf-8">\n'
    + '<title>' + title + '</title>\n'
    + '<style>\n' + css + '\n'
    // The stylesheet expects this wrapper and a width to sit in; without them
    // every line runs the full width of the window, which is not what anybody
    // is being asked to approve.
    + '.markdown-body{box-sizing:border-box;min-width:200px;max-width:980px;margin:0 auto;padding:2rem}\n'
    // The stylesheet paints .markdown-body but leaves the page behind it
    // alone, so in dark mode the article sits on a white surround and the
    // strip of nav above it stays white too. Painting the body to match keeps
    // the frame from looking like part of the page being approved.
    + 'body{margin:0;background:#fff;color:#1f2328}\n'
    + '@media (prefers-color-scheme:dark){body{background:#0d1117;color:#e6edf3}}\n'
    + '.preview-nav{max-width:980px;margin:0 auto;padding:1rem 2rem;font:13px/1.5 system-ui,sans-serif;'
    + 'opacity:.75;border-bottom:1px solid rgba(128,128,128,.3)}\n'
    + '.preview-nav a{margin-right:1rem;color:inherit}\n'
    + '</style></head>\n<body>\n'
    + (nav ? '<div class="preview-nav">' + nav + '</div>\n' : '')
    + '<article class="markdown-body">\n' + body + '\n</article>\n</body></html>\n';
}

function main() {
  var argv = process.argv.slice(2);
  var args = argv.filter(function (a, i) {
    return a.charAt(0) !== '-' && argv[i - 1] !== '--out';
  });
  var files = args.length ? args.map(function (f) {
    return path.relative(ROOT, path.resolve(f)).replace(/\\/g, '/');
  }) : defaultFiles();

  var missing = files.filter(function (f) { return !fs.existsSync(path.join(ROOT, f)); });
  if (missing.length) {
    console.error('Not found: ' + missing.join(', '));
    process.exit(1);
  }

  fs.mkdirSync(OUT, { recursive: true });

  if (!fs.existsSync(CSS_FILE)) {
    process.stdout.write('fetching GitHub\'s stylesheet once... ');
    execFileSync('curl', ['-fsSL', '-o', CSS_FILE, CSS_URL], { stdio: 'inherit' });
    console.log('done');
  }
  var css = fs.readFileSync(CSS_FILE, 'utf8');

  var nav = '<strong>Preview</strong> &nbsp; <a href="index.html">all pages</a>';

  files.forEach(function (rel) {
    process.stdout.write('  ' + rel + ' ... ');
    var html = rewrite(render(fs.readFileSync(path.join(ROOT, rel), 'utf8')), rel, files);
    fs.writeFileSync(path.join(OUT, previewName(rel)), page(rel, html, css, nav), 'utf8');
    console.log('ok');
  });

  // The copy that makes the folder self-contained. Done after rendering
  // rather than during, because the same picture is usually referred to by
  // more than one page and a set means it is copied once.
  var names = Object.keys(assets);
  names.forEach(function (rel) {
    var dest = path.join(OUT, 'repo', rel);
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.copyFileSync(assets[rel], dest);
  });
  if (names.length) console.log('  ' + names.length + ' picture(s) copied alongside.');

  var list = files.map(function (rel) {
    return '<li><a href="' + previewName(rel) + '">' + rel + '</a></li>';
  }).join('\n');
  fs.writeFileSync(
    path.join(OUT, 'index.html'),
    page('StaXX docs preview',
      '<h1>Rendered by GitHub, shown locally</h1>\n'
      + '<p>Exactly what these pages will look like once pushed. Nothing here is on GitHub.</p>\n'
      + '<ul>\n' + list + '\n</ul>', css, ''),
    'utf8'
  );

  console.log('\n' + files.length + ' page(s) written.');
  if (process.argv.indexOf('--no-serve') < 0) serve();
}

/* A static server over the preview folder, with the working copy mounted at
 * /repo/ so the pictures load. This is the whole of the tool's runtime: no
 * framework, no dependency, and it only ever reads.
 *
 * Bound to the loopback address rather than every interface. What this shows
 * is unpushed drafts, which have no business being reachable from the rest of
 * the network. */
function serve() {
  var http = require('http');
  var PORT = 8099;
  var TYPES = {
    '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.png': 'image/png',
    '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif',
    '.svg': 'image/svg+xml', '.webp': 'image/webp'
  };

  http.createServer(function (req, res) {
    var url = decodeURIComponent(req.url.split('?')[0]);
    if (url === '/') url = '/index.html';

    // One root, because the output folder already holds its own pictures —
    // which is the same reason it can be handed to nginx somewhere else.
    //
    // Resolve first, then refuse anything that climbed out of that folder.
    // Every path here is written by this tool, so this guards a malformed
    // request rather than a mistake above it.
    var file = path.resolve(OUT, '.' + url);
    if (file.indexOf(OUT) !== 0 || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      return res.end('Not found: ' + url);
    }

    res.writeHead(200, {
      'Content-Type': TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream',
      // Never cached — the point of this is re-rendering and pressing refresh.
      'Cache-Control': 'no-store'
    });
    fs.createReadStream(file).pipe(res);
  }).listen(PORT, '127.0.0.1', function () {
    console.log('\n  http://localhost:' + PORT
      + '\n\nLeave this running, re-render in another window, and press refresh. Ctrl-C to stop.');
  });
}

main();
