/* StaXX — turns a Docker Hub or local image reference into a compose
 * file. Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * Two sources feed this: the image's own README on Docker Hub (a fenced
 * `yaml` block is often a working example already) and the image's own
 * declared ports/volumes (from the registry config blob or a local
 * `docker image inspect`). Pure string/data in, string out — no DOM, no
 * network — so this runs equally well in the browser and under Node for
 * tests/image_import.js.
 *
 * The output must survive compose-model.js's parse()/serialise() unchanged
 * and unsealed, same contract as ca-convert.js — see that file's own header
 * comment for why "safe" only ever means "won't be misread by that parser".
 */

(function () {
  'use strict';

  var CA = (typeof window !== 'undefined' && window.StaxxCA) ||
    (typeof require === 'function' ? require('./ca-convert.js') : null);
  var CM = (typeof window !== 'undefined' && window.StaxxYaml) ||
    (typeof require === 'function' ? require('./compose-model.js') : null);

  /* =====================================================================
   * Small local helpers — no CA/compose-model equivalent exists for these
   * ===================================================================== */

  function cleanText(s) {
    return String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
  }

  // Same check as stacks.js's own caUrl() — written again here rather than
  // reached into stacks.js, since this file must stay independent of it.
  function checkUrl(u) {
    return (typeof u === 'string' && /^https?:\/\//i.test(u)) ? u : '';
  }

  // The reference's own repository path, canonicalised the same way on both
  // sides of every comparison in this file: digest and tag stripped (already
  // done by repositoryPath()), then an implicit "library/" filled in when
  // there is no namespace, then lowercased. repositoryPath() itself stays
  // untouched — other callers rely on it exactly as it is — so the
  // "library/" step lives here instead.
  function canonicalRepo(ref) {
    var p = CA.repositoryPath(ref);
    if (p.indexOf('/') === -1) p = 'library/' + p;
    return p.toLowerCase();
  }

  // The stack name: the reference's own last path segment (registry host and
  // tag/digest already stripped by repositoryPath()), run through
  // normaliseName() so it satisfies staxx_valid_name().
  function deriveStackName(ref) {
    var p = CA.repositoryPath(ref);
    var segs = p.split('/');
    return CA.normaliseName(segs[segs.length - 1]);
  }

  // Docker Hub's own page for an image — "nginx" (no slash) is a single-
  // segment official image, served at /_/name rather than /r/ns/name.
  // Mirrors stacks.js's caHubUrl(), rewritten here since this file must not
  // depend on stacks.js.
  //
  // '' when the reference does not live on Docker Hub at all. repositoryPath()
  // drops a registry host, so without this check a private registry's image
  // would be handed a Hub address that answers nothing — a dead link written
  // into the file, which is worse than no link. The two mirrors allowed here
  // are the same two Defines.php will remap before it asks Hub anything, and
  // only for linuxserver, which is the only namespace they mirror.
  function hubPageUrl(ref) {
    var s = String(ref == null ? '' : ref);
    var slash = s.indexOf('/');
    var host = slash === -1 ? '' : s.slice(0, slash);
    if (host !== '' && (host.indexOf('.') >= 0 || host.indexOf(':') >= 0) &&
        !((host === 'lscr.io' || host === 'ghcr.io') && s.slice(slash + 1).indexOf('linuxserver/') === 0)) {
      return '';
    }
    var p = CA.repositoryPath(ref);
    // "library/nginx" and "nginx" are the same official image, and Hub serves
    // it only at /_/nginx — /r/library/nginx answers nothing. The long form is
    // what a search result or a README example often writes, so dropping the
    // prefix here is what keeps the link alive rather than merely tidy.
    if (p.indexOf('library/') === 0) p = p.slice('library/'.length);
    var slash = p.indexOf('/');
    if (slash === -1) return 'https://hub.docker.com/_/' + encodeURIComponent(p);
    var segs = p.split('/'), i;
    for (i = 0; i < segs.length; i++) segs[i] = encodeURIComponent(segs[i]);
    return 'https://hub.docker.com/r/' + segs.join('/');
  }

  /* =====================================================================
   * x-unraid metadata — the same on every route
   * ===================================================================== */

  // No `icon` key: an absent icon is matched from the image name
  // automatically (Icons.php's own candidate search), which already happens
  // and beats a guess made here from label text nobody has checked.
  function buildStackMeta(image, facts) {
    facts = facts || {};
    var labels = (facts.labels && typeof facts.labels === 'object') ? facts.labels : {};

    function L(k) {
      var v = labels[k];
      return (typeof v === 'string' && v !== '') ? v : '';
    }

    var overview = cleanText(L('org.opencontainers.image.description') ||
      (typeof facts.description === 'string' ? facts.description : ''));
    var readmeUrl = checkUrl(L('org.opencontainers.image.documentation')) || hubPageUrl(image);
    var project = checkUrl(L('org.opencontainers.image.source')) || checkUrl(L('org.opencontainers.image.url'));
    var author = L('org.opencontainers.image.authors');

    var lines = ['  version: 1'];
    if (overview) {
      lines.push('  overview: |');
      CA.wrapText(overview, 78).forEach(function (l) { lines.push('    ' + l); });
    }
    if (readmeUrl) lines.push('  readme: ' + CA.scalarOut(readmeUrl));
    if (project) lines.push('  project: ' + CA.scalarOut(project));
    if (author) lines.push('  author: ' + CA.scalarOut(author));
    return lines;
  }

  /* =====================================================================
   * The shared header comment and file assembly (routes 1 and 2 only —
   * route 3's bare skeleton is its own four lines, unchanged)
   * ===================================================================== */

  function assemble(sourceLine, warnings, notes, stackMeta, bodyLines) {
    var lines = [];
    lines.push(sourceLine);
    lines.push('#');
    lines.push('# This is an ordinary compose file — delete every x-unraid block below and');
    lines.push('# it still runs with a plain `docker compose up`. Check the ports and paths');
    lines.push('# before starting it; some may have been filled in with placeholder defaults.');
    if (warnings.length) {
      lines.push('#');
      lines.push('# Could not be translated automatically:');
      lines = lines.concat(CA.warningCommentLines(warnings));
    }
    if (notes.length) {
      lines.push('#');
      lines.push('# Filled in for you — check these before starting:');
      lines = lines.concat(CA.warningCommentLines(notes));
    }
    lines.push('');
    lines.push('x-unraid:');
    lines = lines.concat(stackMeta);
    lines.push('');
    lines = lines.concat(bodyLines);
    return lines.join('\n') + '\n';
  }

  /* =====================================================================
   * Route 3 — neither a usable README example nor usable image facts
   * ===================================================================== */

  // Byte-for-byte what stacks.js's own caSkeleton() writes today — the bare
  // file was always an acceptable answer and still is.
  function bareResult(image, source, name, wantConfig) {
    var note = source === 'hub'
      ? '  # Added from a Docker Hub search — just the image, nothing else.'
      : '  # Added from an image already on this server — just the image, nothing else.';
    var yaml = [
      'services:', '', note,
      '  # Ports, paths and variables are not set; add whatever this container needs.',
      '  ' + name + ':',
      '    image: ' + image,
      '    restart: unless-stopped',
      ''
    ].join('\n');
    return { name: name, yaml: yaml, warnings: [], notes: [], route: 'bare', wantConfig: !!wantConfig };
  }

  /* =====================================================================
   * Route 2 — the image's own ports/volumes, nothing from its environment
   *
   * Environment values are the image's build-time internals (PATH, HOME,
   * S6_VERBOSITY, PG_MAJOR, …); writing them pins a snapshot that breaks the
   * day the image changes them, and the variables genuinely required are not
   * in there at all (postgres will not start without POSTGRES_PASSWORD,
   * which never appears in its own config). The server does not send them
   * to this file, and nothing here invents one.
   * ===================================================================== */

  function buildConfigRoute(image, source, facts, opts, name, stackMeta) {
    var ports = [];
    (facts.ports || []).forEach(function (p) {
      var s = String(p);
      var slash = s.indexOf('/');
      var num = slash === -1 ? s : s.slice(0, slash);
      var proto = slash === -1 ? 'tcp' : s.slice(slash + 1).toLowerCase();
      ports.push('      - ' + CA.dq(num + ':' + num + (proto === 'udp' ? '/udp' : '')));
    });

    var appdata = (typeof opts.appdata === 'string' && opts.appdata !== '')
      ? opts.appdata.replace(/\/*$/, '/') : '';
    var volumes = [];
    (facts.volumes || []).forEach(function (v) {
      var containerPath = String(v);
      var hostPath = appdata ? (appdata + name + containerPath) : containerPath;
      volumes.push('      - ' + CA.scalarOut(hostPath + ':' + containerPath));
    });

    var svc = ['services:', '  ' + name + ':', '    image: ' + image, '    restart: unless-stopped'];
    if (ports.length) { svc.push('    ports:'); svc = svc.concat(ports); }
    if (volumes.length) { svc.push('    volumes:'); svc = svc.concat(volumes); }

    var notes = ['These ports and paths came from the image itself and are a starting point: ' +
      'a port already in use on this server has to be changed, and the paths are a guess at ' +
      'where the data should live.'];
    var warnings = [];

    var sourceLine = source === 'local'
      ? "# Built from " + image + "'s own settings, already on this server."
      : "# Built from " + image + "'s own settings on Docker Hub.";

    return {
      name: name,
      yaml: assemble(sourceLine, warnings, notes, stackMeta, svc),
      warnings: warnings, notes: notes, route: 'config', wantConfig: false
    };
  }

  /* =====================================================================
   * Route 1 — the documentation's own example
   * ===================================================================== */

  // The first fenced block tagged yaml/yml — markdown fences are a run of
  // three or more backticks, optionally followed by a language tag, closed
  // by a run of at least as many backticks alone on its line. Any other tag
  // (bash, console, dockerfile, text, none) is skipped outright.
  function findYamlBlock(readme) {
    var lines = String(readme == null ? '' : readme).split(/\r\n|\r|\n/);
    var i = 0;
    while (i < lines.length) {
      var open = /^(`{3,})\s*(\S*)\s*$/.exec(lines[i]);
      if (!open) { i++; continue; }
      var fenceLen = open[1].length;
      var lang = open[2].toLowerCase();
      var j = i + 1, close = -1;
      while (j < lines.length) {
        var cm = /^(`{3,})\s*$/.exec(lines[j]);
        if (cm && cm[1].length >= fenceLen) { close = j; break; }
        j++;
      }
      if (close === -1) { i++; continue; }               // unterminated fence: keep scanning past it
      if (lang === 'yaml' || lang === 'yml') return lines.slice(i + 1, close);
      i = close + 1;
    }
    return null;
  }

  // Strips a leading "---" and trailing "..." (cosmetics of somebody else's
  // file — compose-model.js's parser tolerates them fine now, so this is
  // tidiness for a freshly-written file, not a workaround for anything),
  // leading/trailing blank lines, and a common leading indent.
  function trimBlock(lines) {
    var i = 0, j = lines.length;
    while (i < j && /^\s*$/.test(lines[i])) i++;
    while (j > i && /^\s*$/.test(lines[j - 1])) j--;
    if (i < j && /^---\s*$/.test(lines[i])) {
      i++;
      while (i < j && /^\s*$/.test(lines[i])) i++;
    }
    if (j > i && /^\.\.\.\s*$/.test(lines[j - 1])) {
      j--;
      while (j > i && /^\s*$/.test(lines[j - 1])) j--;
    }
    var body = lines.slice(i, j);
    var minIndent = null;
    body.forEach(function (l) {
      if (/^\s*$/.test(l)) return;
      var lead = /^[ \t]*/.exec(l)[0].length;
      if (minIndent === null || lead < minIndent) minIndent = lead;
    });
    if (minIndent) {
      body = body.map(function (l) {
        return l.length >= minIndent ? l.slice(minIndent) : l.replace(/^[ \t]+/, '');
      });
    }
    return body;
  }

  function readmeServicesMap(doc) {
    var pair = doc.root && doc.root.pairs ? doc.root.pairs['services'] : null;
    return (pair && pair.value && pair.value.kind === 'map') ? pair.value : null;
  }

  // Accept a block only if at least one of its services' image has the same
  // canonical repository path as the reference being imported — a block
  // that never mentions the image is somebody's unrelated example.
  function blockMatchesImage(doc, image) {
    var svcMap = readmeServicesMap(doc);
    if (!svcMap) return false;
    var target = canonicalRepo(image);
    for (var i = 0; i < svcMap.keys.length; i++) {
      var svcPair = svcMap.pairs[svcMap.keys[i]];
      if (!svcPair || !svcPair.value || svcPair.value.kind !== 'map') continue;
      var imgPair = svcPair.value.pairs['image'];
      if (!imgPair || !imgPair.value || imgPair.value.kind !== 'scalar') continue;
      if (canonicalRepo(imgPair.value.value) === target) return true;
    }
    return false;
  }

  // A line's trailing " #comment" is kept out of the way while a correction
  // inspects/rewrites the value in front of it, then stitched back on.
  function splitComment(s) {
    var idx = s.indexOf(' #');
    return idx === -1 ? { body: s, suffix: '' } : { body: s.slice(0, idx), suffix: s.slice(idx) };
  }

  function unquote(v) {
    if (v.length >= 2) {
      var c = v.charAt(0);
      if ((c === '"' || c === "'") && v.charAt(v.length - 1) === c) return { value: v.slice(1, -1), quote: c };
    }
    return { value: v, quote: '' };
  }

  function requote(v, quote) { return quote ? quote + v + quote : v; }

  var ENV_LIST_RE = /^(\s*-\s*)([A-Za-z_][\w.-]*)=(.*)$/;
  var ENV_MAP_RE = /^(\s*)([A-Za-z_][\w.-]*):\s?(.*)$/;

  function matchEnvAssignment(body) {
    var m = ENV_LIST_RE.exec(body);
    if (m) return { prefix: m[1], key: m[2], rawValue: m[3], isList: true };
    m = ENV_MAP_RE.exec(body);
    if (m) return { prefix: m[1], key: m[2], rawValue: m[3], isList: false };
    return null;
  }

  // PUID/PGID 1000 -> 99/100, and TZ Etc/UTC -> this server's own zone. Only
  // these three keys, only at those exact found values — a file that already
  // says PUID=99 gets no note, and one that says PUID=1001 is somebody's
  // deliberate choice and is left alone.
  function correctPuidPgidTz(line, opts) {
    var sc = splitComment(line);
    var m = matchEnvAssignment(sc.body);
    if (!m) return { changed: false, line: line };
    var upKey = m.key.toUpperCase();
    if (upKey !== 'PUID' && upKey !== 'PGID' && upKey !== 'TZ') return { changed: false, line: line };
    var uq = unquote(m.rawValue.replace(/\s+$/, ''));
    var newVal = null, kind = null;
    if ((upKey === 'PUID' || upKey === 'PGID') && uq.value === '1000') {
      newVal = upKey === 'PUID' ? '99' : '100';
      kind = upKey.toLowerCase();
    } else if (upKey === 'TZ' && uq.value === 'Etc/UTC' && opts.timezone) {
      newVal = opts.timezone;
      kind = 'tz';
    }
    if (newVal === null) return { changed: false, line: line };
    var sep = m.isList ? '=' : ': ';
    return { changed: true, kind: kind, line: m.prefix + m.key + sep + requote(newVal, uq.quote) + sc.suffix };
  }

  // A host path starting "/path/to/" (the linuxserver documentation
  // convention) has that prefix swapped for the real appdata root. This
  // makes the path real, not right — the note said alongside it is what
  // tells a reader that a media library still needs moving onto a share.
  function correctPathLine(line, opts) {
    if (!opts.appdata || line.indexOf('/path/to/') === -1) return { changed: false, line: line };
    var sc = splitComment(line);
    var oldPaths = sc.body.match(/\/path\/to\/[^\s:'"#]*/g) || [];
    if (!oldPaths.length) return { changed: false, line: line };
    var newBody = sc.body.split('/path/to/').join(opts.appdata);
    var newPaths = oldPaths.map(function (p) { return p.replace('/path/to/', opts.appdata); });
    return { changed: true, line: newBody + sc.suffix, oldPaths: oldPaths, newPaths: newPaths };
  }

  // A password/secret/token/key left at a documentation placeholder is never
  // written over — nothing safe to put there exists — only warned about.
  //
  // Matched as a substring, not against the whole value: wordpress's own example
  // says `examplepass`, and an exact-value list quietly let that through, which
  // is the one thing this check exists to prevent. A real password containing
  // "changeme" is not a case worth protecting, and the cost of being wrong here
  // is a sentence telling someone to check a password — not an edit.
  var SECRET_KEY_RE = /PASS|SECRET|TOKEN|KEY/i;
  var SECRET_MARKERS = ['example', 'changeme', 'yourpass', 'password', 'secret', 'letmein'];

  function detectSecretPlaceholder(line) {
    var sc = splitComment(line);
    var m = matchEnvAssignment(sc.body);
    if (!m || !SECRET_KEY_RE.test(m.key)) return null;
    var v = unquote(m.rawValue.replace(/\s+$/, '')).value.toLowerCase();

    // Empty and not marked optional means the documentation expects a value
    // here and the container will not work without one — worth saying, since
    // an empty line looks finished. An `#optional` one is left in peace.
    if (v === '') return /#\s*optional/i.test(sc.suffix) ? null : { key: m.key, why: 'empty' };

    var i;
    for (i = 0; i < SECRET_MARKERS.length; i++) {
      if (v.indexOf(SECRET_MARKERS[i]) >= 0) return { key: m.key, why: 'placeholder' };
    }
    return null;
  }

  function hasSecretKey(list, key) {
    var i;
    for (i = 0; i < list.length; i++) if (list[i].key === key) return true;
    return false;
  }

  function puidPgidNote(keys) {
    var parts = [];
    if (keys.indexOf('PUID') >= 0) parts.push('PUID to 99');
    if (keys.indexOf('PGID') >= 0) parts.push('PGID to 100');
    return 'Changed ' + parts.join(' and ') + ' — Unraid runs containers as user 99 and group ' +
      '100, and a container running as user 1000 cannot write to your shares.';
  }

  function pathNote(oldPaths, newPaths) {
    return 'Changed the placeholder path' + (oldPaths.length > 1 ? 's ' : ' ') + oldPaths.join(', ') +
      ' to sit under your appdata (' + newPaths.join(', ') + '). This makes the path real, not ' +
      'right — a media library belongs on one of your shares, not appdata, so change these before ' +
      'starting the stack.';
  }

  // Applies every correction as a narrow line edit on the block's own text —
  // never touching anything the table above does not name. Returns the
  // corrected lines plus whatever the corrections have to say for
  // themselves; correctBlock() itself never re-parses — that guard is the
  // caller's job, once, on the whole block.
  function correctBlock(lines, opts) {
    var out = lines.slice();
    var changedAny = false;
    var puidPgidKeys = [], tzChanged = false, oldPaths = [], newPaths = [], secretKeys = [];

    for (var i = 0; i < out.length; i++) {
      var secret = detectSecretPlaceholder(out[i]);
      if (secret && !hasSecretKey(secretKeys, secret.key)) secretKeys.push(secret);

      var envRes = correctPuidPgidTz(out[i], opts);
      if (envRes.changed) {
        out[i] = envRes.line;
        changedAny = true;
        if (envRes.kind === 'puid' && puidPgidKeys.indexOf('PUID') === -1) puidPgidKeys.push('PUID');
        if (envRes.kind === 'pgid' && puidPgidKeys.indexOf('PGID') === -1) puidPgidKeys.push('PGID');
        if (envRes.kind === 'tz') tzChanged = true;
        continue;                             // a line is either an env assignment or a path, never both
      }

      var pathRes = correctPathLine(out[i], opts);
      if (pathRes.changed) {
        out[i] = pathRes.line;
        changedAny = true;
        oldPaths = oldPaths.concat(pathRes.oldPaths);
        newPaths = newPaths.concat(pathRes.newPaths);
      }
    }

    var notes = [];
    if (puidPgidKeys.length) notes.push(puidPgidNote(puidPgidKeys));
    if (tzChanged) notes.push('Set TZ to ' + opts.timezone + ", this server's own timezone.");
    if (oldPaths.length) notes.push(pathNote(oldPaths, newPaths));

    var warnings = secretKeys.map(function (s) {
      return s.why === 'empty'
        ? 'The setting "' + s.key + '" has no value, and the documentation does not mark it ' +
          'optional. Give it one before starting the stack.'
        : 'The setting "' + s.key + '" is left at the documentation\'s placeholder value and must ' +
          'be changed before the stack is started.';
    });

    return { lines: out, changedAny: changedAny, notes: notes, warnings: warnings };
  }

  function tryReadmeRoute(image, readmeText, opts, name, stackMeta) {
    if (!readmeText || typeof readmeText !== 'string') return null;
    var rawBlock = findYamlBlock(readmeText);
    if (!rawBlock) return null;
    var trimmed = trimBlock(rawBlock);
    if (!trimmed.length) return null;

    // A sealed *value* is not a reason to reject the block. Flow sequences
    // (`capabilities: [gpu]`, `command: ["sleep","infinity"]`), anchors and
    // block scalars all seal, and all three are ordinary in a README example
    // — the form shows each as one locked row and the file still round-trips
    // byte for byte, which is what sealing is for. Only a block whose whole
    // root cannot be read, or that declares no services, is somebody else's
    // unusable text; rejecting the rest would throw away a good example in
    // favour of the far thinner ports-and-volumes fallback.
    var doc1;
    try { doc1 = CM.parse(trimmed.join('\n') + '\n'); } catch (e) { return null; }
    if (!doc1.root || doc1.root.kind !== 'map') return null;
    if (!readmeServicesMap(doc1)) return null;
    if (!blockMatchesImage(doc1, image)) return null;

    var corrected = correctBlock(trimmed, opts);
    var finalLines = trimmed;
    var notes = corrected.notes;
    var warnings = corrected.warnings;

    if (corrected.changedAny) {
      var doc2 = null;
      try { doc2 = CM.parse(corrected.lines.join('\n') + '\n'); } catch (e) { /* falls through to guard-fail below */ }
      // Compared against what the block already sealed, not against zero: the
      // example may legitimately hold a flow sequence or an anchor, and the
      // question here is only whether a correction made things worse.
      if (doc2 && doc2.root && doc2.root.kind === 'map' && doc2.sealed.length <= doc1.sealed.length) {
        finalLines = corrected.lines;
      } else {
        // A correction produced a file the form could not read — throw every
        // correction away rather than hand back something quietly broken.
        notes = ['A correction to this documentation example would have made the file unreadable, ' +
          'so it was left exactly as the documentation wrote it.'];
      }
    }

    var svcMap = readmeServicesMap(doc1);
    var names = svcMap ? svcMap.keys.slice() : [];
    if (names.length > 1) {
      notes = notes.concat(['This documentation example sets up more than one service together — ' +
        names.join(', ') + ' — and all of them are added; delete whichever you do not want, in the editor.']);
    }

    var sourceLine = "# Copied from " + image + "'s own documentation on Docker Hub.";
    return {
      name: name,
      yaml: assemble(sourceLine, warnings, notes, stackMeta, finalLines),
      warnings: warnings, notes: notes, route: 'readme', wantConfig: false
    };
  }

  /* =====================================================================
   * Entry point
   * ===================================================================== */

  function build(image, source, facts, opts) {
    image = String(image == null ? '' : image);
    facts = facts || {};
    opts = opts || {};
    var name = deriveStackName(image);

    if (facts.off) return bareResult(image, source, name, false);

    var stackMeta = buildStackMeta(image, facts);

    var r1 = tryReadmeRoute(image, facts.readme, opts, name, stackMeta);
    if (r1) return r1;

    var hasPorts = Array.isArray(facts.ports) && facts.ports.length > 0;
    var hasVolumes = Array.isArray(facts.volumes) && facts.volumes.length > 0;
    if (hasPorts || hasVolumes) return buildConfigRoute(image, source, facts, opts, name, stackMeta);

    // Neither route produced anything, so the image's own config is worth
    // asking for — but only if it was never fetched. The server sends these
    // fields absent when it did not look, and present-but-empty when it looked
    // and the image declares nothing; asking again on an empty answer would
    // pay for four requests to be told the same thing twice.
    var asked = Array.isArray(facts.ports) || Array.isArray(facts.volumes);
    return bareResult(image, source, name, !asked);
  }

  /* =====================================================================
   * Export — dual target exactly like ca-convert.js/compose-model.js
   * ===================================================================== */

  var API = { build: build };

  if (typeof window !== 'undefined') window.StaxxImage = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
