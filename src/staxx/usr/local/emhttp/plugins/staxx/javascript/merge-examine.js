/* StaXX — the reading pass behind merging several stacks into one.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * PLAN_155 ("the rebuild"): a merge no longer folds N stacks into one of
 * them acting as host. It reads N stacks, all equal, and works out
 * everything the wizard's steps 3 to 5 need to say about writing a brand
 * new third stack from them. This file writes nothing, opens nothing, and
 * reads nothing off disk itself — every fact it needs arrives already
 * parsed, exactly as db-images.js and health-offer.js take their own facts
 * pre-gathered rather than going looking for them. The wizard turns these
 * findings into screens; this file only decides what there is to decide.
 *
 * Same dual shape as health-offer.js and db-images.js: a plain browser
 * global under `window.StaxxMergeExamine`, and a `module.exports` so
 * tests/merge_examine.js can `require()` it directly under Node.
 *
 * INPUT SHAPE — a "source descriptor", one per stack being merged, all
 * equal (there is no host). compose-model.js's own parse tree and its
 * `form` are both built for interactive single-file editing and neither is
 * a convenient shape to compare several files against each other, so this
 * module takes a smaller, already-digested shape instead; the caller
 * (wizard code, or a test) builds it — merge-write.js's own
 * descriptorFromText() is the production adapter. A source descriptor:
 *
 *   {
 *     name: 'DEV-TESTING/demo-db',  // the stack's full rel, exactly as the wizard/server know it —
 *                                   // NOT necessarily a leaf; see leaf() and projectNameOf() below
 *                                   // for how the LEAF (or an explicit compose `name:`) is derived
 *                                   // from this wherever text is actually written
 *     text: 'services:\n  ...',     // OPTIONAL — this source's own raw compose text. Supplying it is
 *                                   // what lets examine() report which exact LINES a finding will
 *                                   // change (see docCacheFor() below); without it, findings still
 *                                   // work, they just carry lines: [] throughout
 *     depth: 0 | 1,                 // 0 loose, 1 in a folder — where it sits TODAY. Read only as a
 *                                   // fallback when `rel` (below) is absent — see that field's own
 *                                   // comment, and PLAN_155 C4.
 *     rel: 'DEV-TESTING/demo-db',   // OPTIONAL — same value as `name` above, carried under its own
 *                                   // name because a "../" path is resolved by FOLDER, not by depth:
 *                                   // two folders at the same depth are still two different places
 *                                   // (PLAN_155 C4). Omitted, a "../" path falls back to the old
 *                                   // depth-difference count instead.
 *     files: [...],                 // the merge-files reply's own `files` array —
 *                                   // {path,size,mode,dir,link,target,outside,keyLike,referenced}
 *     filesLarge: null | { path },  // that same reply's own `large`
 *     runningFrom: { configFiles: [...], envFile: '' },  // OPTIONAL — the merge-files reply's own
 *                                   // `runningFrom` (PLAN_155 C18): what this stack's running
 *                                   // containers were actually started from, straight from
 *                                   // compose's own labels. Omitted (or a stack with nothing
 *                                   // running) is the same as the empty shape shown here — nothing
 *                                   // is flagged in that case.
 *     env: { lines: [...] } | null, // the stack's own .env, already parsed
 *     compose: {
 *       name: 'explicit-name' | null,   // the compose file's own top-level `name:`, if it set one —
 *                                       // this is what a real Docker project is named by, ahead of the
 *                                       // folder leaf, so it is what a carried volume's real name is built from
 *       services: {
 *         <serviceName>: {
 *           image, container_name,
 *           ports: [{ host, container, protocol }],   // host '' = not published
 *           volumes: [{ type: 'named'|'bind'|'anonymous', source, target }],
 *           environment: { NAME: 'value' },
 *           env_file: ['relative/path', ...],
 *           profiles: ['name', ...],           // empty when the service always starts
 *           networks: ['name', ...],
 *           network_mode: 'host' | 'container:x' | ... | undefined,
 *           depends_on: ['service', ...],
 *           build: { context } | string | undefined,
 *           extends: { file } | undefined,
 *           x_unraid: {}                               // service-level block, carried whole
 *         }
 *       },
 *       volumes: { name: { external: bool, def: {} } }, // top-level declared blocks
 *       networks: { name: { def: {} } },
 *       configs:  { name: { def: {} } },
 *       secrets:  { name: { def: {} } },
 *       stack_x_unraid: {}                              // top-level (stack-level) x-unraid block
 *     }
 *   }
 *
 * An `.env` "lines" array holds, in file order:
 *   { type: 'comment', text }
 *   { type: 'blank' }
 *   { type: 'setting', name, value, comment }           // comment is the trailing '# ...', or ''
 *
 * opts: { date, thisServer, newDepth (0|1 — where the NEW stack will live),
 *         newRel (the new stack's own full rel — same pairing as a source's
 *         `rel` above, and read the same way: present, it resolves a "../"
 *         path by folder; absent, `newDepth` is the fallback) }.
 *
 * OUTPUT SHAPE — examine() returns { findings, plan }.
 *
 *   findings: one entry per thing worth saying, in the order the wizard's
 *   steps read them (refusals first, then decisions, then wiring, then the
 *   "checked and fine" list). Each finding is:
 *
 *   {
 *     kind: 'storage-carry' | 'file-clash' | 'key-copied' | 'outside-link' |
 *           'hidden-config' | 'unreferenced' | 'large-folder' | 'build-image' |
 *           'depth-path' | 'settings-join' | 'container-name-clash' |
 *           'port-clash' | 'shorthand-clash' | 'address-rewire' |
 *           'port-unneeded' | 'left-alone' | 'clean',
 *     severity: 'refusal' | 'decision' | 'automatic' | 'wiring' | 'info' |
 *               'warning' | 'clean',
 *     stack: 'DEV-TESTING/demo-db' | null,   // the source's own FULL REL, exactly as the caller
 *                                            // passed it in `sources[].name` — this is the one field
 *                                            // (with `files[].from` in merge-write.js) that keeps the
 *                                            // rel; everything else derives the LEAF from it (see
 *                                            // leaf() below) before writing any text or building any
 *                                            // Docker identifier, or a folder segment leaks into one
 *     key: 'kind|stack|index',       // stable id — findingKey()'s own scheme
 *     facts: { ...plain-English facts, never markup... },
 *     choices: [ { id, label, recommended: true|false }, ... ],  // only when there is one to make
 *     lines: [ { stack, line } ]     // 0-based line numbers, in that SOURCE's own ORIGINAL text
 *                                    // (its `.text`, when the caller supplied one — see docCacheFor()),
 *                                    // that this finding will change — [] when nothing existing
 *                                    // changes (an addition, a companion/informational finding, or a
 *                                    // caller that built a descriptor with no raw text to search)
 *   }
 *
 *   plan: the rename maps the write pass needs, read straight back out of
 *   the findings above rather than recomputed a second time —
 *   { serviceRenames, containerNameRenames, declRenames }, each keyed
 *   "source/name" -> the resolved final name (containerNameRenames values
 *   are { from, to }).
 */

(function () {
  'use strict';

  var CM = (typeof require === 'function') ? require('./compose-model.js') : (typeof window !== 'undefined' ? window.StaxxYaml : null);

  /* =====================================================================
   * Small shared helpers
   * ===================================================================== */

  // A source is identified everywhere by its full rel ("DEV-TESTING/demo-db")
  // so the `stack` field on a finding, and `files[].from`, can always be
  // handed straight back to the server. But Docker's own compose PROJECT
  // name is never the rel — it is the folder's own LEAF, unless the compose
  // file sets an explicit top-level `name:` (see projectNameOf() below). Any
  // text this file WRITES OR SUGGESTS — a comment, a rename suffix, a real
  // volume name, a change's own title or reason — has to use the leaf (or
  // the explicit name), never the rel, or a folder segment leaks into a
  // Docker identifier or a stack's own real storage name. This was a real
  // bug, caught 2026-09-15 probing a same-folder demo pair: a carried
  // volume came out named "DEV-TESTING/demo-db_dbdata", which is not the
  // volume Docker actually has — it would have started the database empty.
  function leaf(name) {
    var parts = String(name).split('/');
    return parts[parts.length - 1];
  }

  function suffix(name) {
    // The stack-name suffix a rename appends — DB_PASSWORD -> DB_PASSWORD_DEMO_DB,
    // demo-db -> DEMO_DB for an env name, or plain "demo-db" for a service.
    // Always fed a LEAF (or an explicit project name) by its callers below,
    // never a rel — see leaf()'s own comment.
    return String(name).toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  }

  function stableStringify(v) {
    if (v === null || typeof v !== 'object') return JSON.stringify(v);
    if (Array.isArray(v)) return '[' + v.map(stableStringify).join(',') + ']';
    var keys = Object.keys(v).sort();
    return '{' + keys.map(function (k) { return JSON.stringify(k) + ':' + stableStringify(v[k]); }).join(',') + '}';
  }

  function sameDef(a, b) {
    return stableStringify(a || {}) === stableStringify(b || {});
  }

  function isIpLike(host) {
    return /^\d{1,3}(\.\d{1,3}){3}$/.test(host);
  }

  // Matches "host:port", optionally wrapped in a URL scheme — the shape a
  // database address written to reach another container over the LAN
  // actually takes. Captures the two parts; nothing here decides whether
  // the host part is really this server, only that it looks address-shaped.
  var ADDR_RE = /(?:^|[^\d.])((?:\d{1,3}\.){3}\d{1,3}|[A-Za-z][\w.-]*)[:](\d{2,5})(?:[^\d]|$)/;

  function servicesOf(descriptor) {
    return (descriptor && descriptor.compose && descriptor.compose.services) || {};
  }

  function declOf(descriptor, kind) {
    return (descriptor && descriptor.compose && descriptor.compose[kind]) || {};
  }

  function projectNameOf(descriptor) {
    return (descriptor.compose && descriptor.compose.name) || leaf(descriptor.name);
  }

  function findingKey(kind, stack, idx) {
    return kind + '|' + (stack || '') + '|' + idx;
  }

  /* =====================================================================
   * Locating the exact lines a finding will change, in that SOURCE's own
   * original text — for the wizard's source-pane marks and its
   * click-to-pair scroll. Read-only: nothing here edits anything, so it can
   * afford to be a much lighter re-implementation of the structural finding
   * merge-write.js's own edit functions already do properly. A source with
   * no `.text` (a hand-built descriptor, as most of this file's own tests
   * use) simply gets no lines — never a thrown error and never a guess.
   * ===================================================================== */

  function lineKind(line) {
    var m = /^([ \t]*)#/.exec(line);
    if (m) return { kind: 'comment', indent: m[1].length };
    if (/^[ \t]*$/.test(line)) return { kind: 'blank', indent: 0 };
    return { kind: 'other', indent: /^([ \t]*)/.exec(line)[1].length };
  }

  function servicesMapOf(doc) {
    var svc = doc.root && doc.root.kind === 'map' ? doc.root.pairs['services'] : null;
    return svc && svc.value && svc.value.kind === 'map' ? svc.value : null;
  }

  function declMapOf(doc, kind) {
    var d = doc.root && doc.root.kind === 'map' ? doc.root.pairs[kind] : null;
    return d && d.value && d.value.kind === 'map' ? d.value : null;
  }

  function findKeyChildRange(doc, mapStart, mapEnd, keyRegex) {
    for (var i = mapStart; i < mapEnd; i++) {
      var m = keyRegex.exec(doc.lines[i]);
      if (!m) continue;
      var indent = m[1].length;
      var j = i + 1;
      while (j < mapEnd) {
        var lm = /^(\s*)\S/.exec(doc.lines[j]);
        if (lm && lm[1].length <= indent) break;
        j++;
      }
      return { start: i + 1, end: j };
    }
    return null;
  }

  function findPortsRange(doc, serviceKey) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return null;
    return findKeyChildRange(doc, p.start, p.end, /^(\s*)ports:\s*(#.*)?$/);
  }

  function parsePortListLine(line) {
    var m = /^(\s*-\s*)(['"]?)([^'"]*)\2\s*$/.exec(line);
    if (!m) return null;
    return { prefix: m[1], quote: m[2], body: m[3] };
  }

  function parsePortBody(body) {
    var proto = '', main = body;
    var pm = /(\/(?:tcp|udp))$/.exec(body);
    if (pm) { proto = pm[1]; main = body.slice(0, -pm[1].length); }
    var parts = main.split(':');
    if (parts.length >= 2) return { host: parts[0], container: parts.slice(1).join(':'), proto: proto };
    return { host: '', container: main, proto: proto };
  }

  // A per-examine() cache of parsed docs, keyed by source name — several
  // finding kinds for the same source each want their own lookup, and
  // CM.parse() is not free enough to redo per finding.
  function docCacheFor(sources) {
    var cache = {};
    sources.forEach(function (s) {
      if (s && s.text && CM) { try { cache[s.name] = CM.parse(s.text); } catch (e) { /* left undefined */ } }
    });
    return cache;
  }

  function locateServiceKeyLine(doc, svcName) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    return p ? p.start : -1;
  }

  function locateContainerNameLine(doc, svcName, oldName) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p) return -1;
    for (var i = p.start; i < p.end; i++) {
      var m = /^\s*container_name:\s*(.*)$/.exec(doc.lines[i]);
      if (!m) continue;
      var cm = /^([^#]*?)(\s*#.*)?$/.exec(m[1]);
      var val = cm[1].replace(/^['"]|['"]$/g, '').trim();
      if (val === oldName) return i;
    }
    return -1;
  }

  function locateDeclKeyLine(doc, kind, name) {
    var declMap = declMapOf(doc, kind);
    var p = declMap && declMap.pairs[name];
    return p ? p.start : -1;
  }

  function locateVolumeMountLines(doc, volName) {
    var svcMap = servicesMapOf(doc);
    if (!svcMap) return [];
    var out = [];
    svcMap.keys.forEach(function (svcName) {
      var p = svcMap.pairs[svcName];
      for (var i = p.start; i < p.end; i++) {
        if (new RegExp('^\\s*-\\s*["\']?' + volName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ':').test(doc.lines[i])) out.push(i);
      }
    });
    return out;
  }

  function locatePortLine(doc, svcName, hostPort) {
    var range = findPortsRange(doc, svcName);
    if (!range) return -1;
    for (var i = range.start; i < range.end; i++) {
      var parsed = parsePortListLine(doc.lines[i]);
      if (!parsed) continue;
      var pb = parsePortBody(parsed.body);
      if (pb.host === String(hostPort)) return i;
    }
    return -1;
  }

  function locateEnvLine(doc, svcName, envVar, valueText) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p) return -1;
    for (var i = p.start; i < p.end; i++) {
      var line = doc.lines[i];
      if (line.indexOf(envVar) >= 0 && line.indexOf(valueText) >= 0) return i;
    }
    return -1;
  }

  function locatePathLine(doc, path) {
    for (var i = 0; i < doc.lines.length; i++) if (doc.lines[i].indexOf(path) >= 0) return i;
    return -1;
  }

  function linesEntry(stack, line) {
    return line >= 0 ? [{ stack: stack, line: line }] : [];
  }

  /* =====================================================================
   * Storage Docker manages — carried outright by real name, never renamed
   * and never copied. See PLAN_155's "Docker-managed storage is repointed,
   * never copied".
   * ===================================================================== */

  function findStorageFindings(sources, docCache) {
    var out = [];
    var mergedKeyTaken = {};   // the KEY the merged file's volumes: block will use — only this can clash
                               // between two sources; the real Docker name never can, since it always
                               // carries its own source's project name.
    sources.forEach(function (s) {
      var volDecl = declOf(s, 'volumes');
      var used = {};
      Object.keys(servicesOf(s)).forEach(function (svcName) {
        (servicesOf(s)[svcName].volumes || []).forEach(function (v) {
          if (v.type === 'named') used[v.source] = true;
        });
      });

      Object.keys(used).forEach(function (volName) {
        var decl = volDecl[volName];
        if (decl && decl.external) return;   // already points at a fixed real volume — nothing changes identity

        var mergedKey = volName;
        if (mergedKeyTaken[mergedKey]) mergedKey = volName + '_' + suffix(leaf(s.name));
        mergedKeyTaken[mergedKey] = true;

        // A plain carry is an ADDITION (a name: child gets written in) —
        // nothing existing changes. But when the MERGED KEY had to be
        // disambiguated, every place this source's own file names the
        // volume under its original key IS being rewritten, and that is
        // worth marking in the source pane.
        var lines = [];
        var doc = docCache[s.name];
        if (mergedKey !== volName && doc) {
          var declLine = locateDeclKeyLine(doc, 'volumes', volName);
          if (declLine >= 0) lines.push({ stack: s.name, line: declLine });
          locateVolumeMountLines(doc, volName).forEach(function (i) { lines.push({ stack: s.name, line: i }); });
        }

        out.push({
          kind: 'storage-carry',
          severity: 'decision',
          stack: s.name,
          facts: { volume: volName, mergedKey: mergedKey, realName: projectNameOf(s) + '_' + volName },
          choices: [
            { id: 'carry', recommended: true },
            { id: 'start-empty', recommended: false }
          ],
          lines: lines
        });
      });
    });
    return out;
  }

  /* =====================================================================
   * Hidden config (PLAN_155 C18) — a stack's RUNNING containers were
   * started from files the wizard never reads. The wizard only ever folds
   * a stack's own compose file plus the one override compose auto-loads
   * beside it (the names below); an extra `-f` file, an override under any
   * other name, or a `--env-file` that is not the stack's own `.env`
   * carries settings that would silently vanish in the merge, so the
   * stack is refused outright rather than merged incompletely. Checked by
   * basename only — `runningFrom` supplies whatever the running container
   * was actually told to use, and a name outside this list is never one
   * the wizard itself would have picked up.
   * ===================================================================== */

  var STANDARD_COMPOSE_NAMES = {
    'compose.yaml': 1, 'compose.yml': 1, 'docker-compose.yaml': 1, 'docker-compose.yml': 1,
    'compose.override.yaml': 1, 'compose.override.yml': 1,
    'docker-compose.override.yaml': 1, 'docker-compose.override.yml': 1
  };

  function basenameOf(p) {
    var parts = String(p || '').split(/[\/\\]/);
    return parts[parts.length - 1];
  }

  function findHiddenConfigFindings(sources) {
    var out = [];
    (sources || []).forEach(function (s) {
      var rf = s.runningFrom || { configFiles: [], envFile: '' };
      var hiddenFiles = (rf.configFiles || []).filter(function (p) {
        return !STANDARD_COMPOSE_NAMES[basenameOf(p)];
      });
      var hiddenEnv = (rf.envFile && basenameOf(rf.envFile) !== '.env') ? rf.envFile : '';
      if (!hiddenFiles.length && !hiddenEnv) return;
      out.push({
        kind: 'hidden-config', severity: 'refusal', stack: s.name,
        facts: { files: hiddenFiles, envFile: hiddenEnv }, lines: []
      });
    });
    return out;
  }

  /* =====================================================================
   * Companion files — everything beside the compose file. Each source's
   * `files` array is the merge-files reply's own list; see this file's own
   * header for its shape.
   * ===================================================================== */

  function findCompanionFindings(sources, docCache) {
    var out = [];
    var byPath = {};   // path -> [{ stack, entry }]

    sources.forEach(function (s) {
      (s.files || []).forEach(function (entry) {
        if (entry.outside) {
          // Never copied — a relative symlink resolving outside the stack
          // folder points somewhere the merge has no business reaching.
          out.push({
            kind: 'outside-link', severity: 'refusal', stack: s.name,
            facts: { path: entry.path, target: entry.target }, lines: []
          });
          return;
        }

        // An image file sitting directly in .staxx/ is never a clash
        // candidate (PLAN_155 C17, "icons are not a question") — every
        // carried service that names one gets its own copy under its own
        // name regardless, a decision merge-write.js's planIconCopies()
        // makes on its own; a same-name clash between two SOURCES' icon
        // files is not a question anybody needs asked.
        if (/^\.staxx\/[^\/]+$/.test(entry.path)) return;

        (byPath[entry.path] = byPath[entry.path] || []).push({ stack: s.name, entry: entry });

        if (entry.keyLike) {
          out.push({
            kind: 'key-copied', severity: 'info', stack: s.name,
            facts: { path: entry.path, note: 'the copy will exist in two places' }, lines: []
          });
        }
        if (!entry.dir && entry.referenced === false) {
          out.push({
            kind: 'unreferenced', severity: 'decision', stack: s.name,
            facts: { path: entry.path },
            choices: [{ id: 'copy', recommended: true }, { id: 'leave-behind', recommended: false }],
            lines: []
          });
        }
      });

      if (s.filesLarge) {
        out.push({
          kind: 'large-folder', severity: 'warning', stack: s.name,
          facts: { path: s.filesLarge.path }, lines: []
        });
      }

      Object.keys(servicesOf(s)).forEach(function (svcName) {
        if (servicesOf(s)[svcName].build) {
          out.push({
            kind: 'build-image', severity: 'info', stack: s.name,
            facts: { service: svcName }, lines: []
          });
        }
      });
    });

    Object.keys(byPath).forEach(function (path) {
      var entries = byPath[path];
      if (entries.length < 2) return;   // only ≥2 sources bringing the same name is a clash at all

      var isDir = entries[0].entry.dir;
      var stem = path, ext = '';
      if (!isDir) {
        var m = /^(.*?)(\.[^./]*)?$/.exec(path);
        stem = m[1]; ext = m[2] || '';
      }

      // The lines in EACH source's own text that reference this path — a
      // bind mount, an env_file entry — so the wizard can mark them, since
      // a rename decision rewrites every one of them (see merge-write.js's
      // rewriteFileReferences()).
      var lines = [];
      entries.forEach(function (e) {
        var doc = docCache[e.stack];
        if (!doc) return;
        var i = locatePathLine(doc, path);
        if (i >= 0) lines.push({ stack: e.stack, line: i });
      });

      out.push({
        kind: 'file-clash', severity: 'decision', stack: null,
        facts: {
          path: path, isDir: isDir,
          sources: entries.map(function (e) { return e.stack; }),
          // The default 'rename' answer, one per source that brought it —
          // "keep-one" is the alternative that drops every renamed copy but
          // the first source's own. The suffix is the source's own LEAF,
          // never its full rel — this becomes a real filename.
          renameTo: entries.map(function (e) {
            var suf = leaf(e.stack);
            return { stack: e.stack, to: isDir ? (stem + '-' + suf) : (stem + '-' + suf + ext) };
          })
        },
        choices: [
          { id: 'rename', recommended: true },
          { id: 'keep-one', recommended: false },
          { id: 'leave-behind', recommended: false }
        ],
        lines: lines
      });
    });

    return out;
  }

  /* =====================================================================
   * Relative paths that need re-pointing because the new stack sits at a
   * different depth than the source did — bind mounts, env_file, a
   * top-level secrets:/configs: entry's own file:, build.context and
   * extends.file. A path starting "./" never needs anything: the file
   * travels with it. Only "../" paths (reaching outside the folder) care
   * where the folder itself now sits.
   * ===================================================================== */

  function adjustUpPath(relPath, delta) {
    if (!delta) return relPath;
    var m = /^(\.\.\/)+/.exec(relPath);
    if (!m) return relPath;
    var upCount = m[0].length / 3;
    var rest = relPath.slice(m[0].length);
    var newUp = upCount + delta;
    if (newUp <= 0) return './' + rest;
    return new Array(newUp + 1).join('../') + rest;
  }

  // PLAN_155 C4: the ONE place a "../" path is re-pointed for the new
  // stack's own folder — the depth-path finding's `newPath` and the writer
  // both read this same answer (the writer never recomputes it; see
  // merge-write.js's own depth-path change block, which only applies
  // `f.facts.newPath`), so the preview and the written file cannot disagree.
  //
  // Depth alone is the wrong measure: two folders at the same depth are
  // still two different places. So when both the source's own folder
  // (`sourceRel`) and the new stack's (`newRel`) are known, the path is
  // resolved properly — walked up out of the source's folder to a path
  // under the store, then walked back down into the new stack's folder —
  // and `delta` (the old depth-difference count) is used only when a path
  // climbs out of the store altogether (more ".." than the source has
  // folders to give), because nothing below the store's own root can be
  // known from here, or when either rel is missing, which keeps every
  // caller that only ever supplied `depth` working exactly as before.
  function resolveDepthPath(oldPath, sourceRel, newRel, delta) {
    var m = /^(\.\.\/)+/.exec(oldPath);
    if (!m) return oldPath;
    var upCount = m[0].length / 3;
    var rest = oldPath.slice(m[0].length);
    if (typeof sourceRel === 'string' && typeof newRel === 'string') {
      var srcSegs = sourceRel.split('/');
      if (upCount <= srcSegs.length) {
        var targetSegs = srcSegs.slice(0, srcSegs.length - upCount).concat(rest ? rest.split('/') : []);
        var dstSegs = newRel.split('/');
        var i = 0;
        while (i < dstSegs.length && i < targetSegs.length - 1 && dstSegs[i] === targetSegs[i]) i++;
        var ups = dstSegs.length - i;
        var downs = targetSegs.slice(i);
        return ups <= 0 ? './' + downs.join('/') : new Array(ups + 1).join('../') + downs.join('/');
      }
      // Climbs above the store root — falls through to the depth-only guess.
    }
    return adjustUpPath(oldPath, delta);
  }

  function findDepthPathFindings(sources, opts, docCache) {
    var out = [];
    var newDepth = (opts && typeof opts.newDepth === 'number') ? opts.newDepth : 0;
    var newRel = (opts && typeof opts.newRel === 'string') ? opts.newRel : null;

    function consider(s, field, service, oldPath) {
      if (!/^\.\.\//.test(oldPath)) return;   // "./..." needs nothing; only "../..." can be affected
      var delta = newDepth - (typeof s.depth === 'number' ? s.depth : 0);
      var newPath = resolveDepthPath(oldPath, s.rel, newRel, delta);
      if (newPath === oldPath) return;        // nothing moved
      var doc = docCache[s.name];
      var lines = doc ? linesEntry(s.name, locatePathLine(doc, oldPath)) : [];
      out.push({
        kind: 'depth-path', severity: 'decision', stack: s.name,
        facts: { service: service || null, field: field, oldPath: oldPath, newPath: newPath },
        choices: [{ id: 'rewrite', recommended: true }, { id: 'leave', recommended: false }],
        lines: lines
      });
    }

    sources.forEach(function (s) {
      Object.keys(servicesOf(s)).forEach(function (svcName) {
        var svc = servicesOf(s)[svcName];
        (svc.volumes || []).forEach(function (v) {
          if (v.type === 'bind') consider(s, 'volumes', svcName, v.source);
        });
        (svc.env_file || []).forEach(function (f) { consider(s, 'env_file', svcName, f); });
        if (svc.build) {
          var ctx = typeof svc.build === 'string' ? svc.build : svc.build.context;
          if (ctx) consider(s, 'build.context', svcName, ctx);
        }
        if (svc.extends && svc.extends.file) consider(s, 'extends.file', svcName, svc.extends.file);
      });

      ['secrets', 'configs'].forEach(function (kind) {
        var decl = declOf(s, kind);
        Object.keys(decl).forEach(function (name) {
          var file = decl[name] && decl[name].def && decl[name].def.file;
          if (file) consider(s, kind + '[].file', null, file);
        });
      });
    });

    return out;
  }

  /* =====================================================================
   * The joined settings list (.env) — symmetric across every source. A name
   * is compared only against the FIRST source that set it; every later
   * source repeating the same value is folded away, and every later source
   * disagreeing is renamed with its own suffix — see PLAN_155's "settings
   * join" section.
   * ===================================================================== */

  function findSettingsJoin(sources, opts) {
    var withEnv = sources.filter(function (s) { return s.env && s.env.lines && s.env.lines.length; });
    if (!withEnv.length) return null;

    var date = (opts && opts.date) || new Date().toISOString().slice(0, 10);
    var firstSeen = {};   // name -> { value, stack }
    var sameValue = [], renamed = [], joined = [];

    withEnv.forEach(function (s) {
      joined.push({ type: 'comment', text: '# From ' + leaf(s.name), stack: s.name });
      s.env.lines.forEach(function (line) {
        if (line.type !== 'setting') { joined.push(Object.assign({ stack: s.name }, line)); return; }

        if (!(line.name in firstSeen)) {
          firstSeen[line.name] = { value: line.value, stack: s.name };
          joined.push(Object.assign({ stack: s.name }, line));
          return;
        }

        if (firstSeen[line.name].value === line.value) {
          // value is carried too — a "keep both" override writes this
          // source's own line back in rather than dropping it, and since
          // dedupe only ever fires when the values already match, the
          // value the wizard would write is right here, not worth a second
          // lookup back into this source's own .env for.
          sameValue.push({ name: line.name, stack: s.name, value: line.value });
          // `dedupe`/`name` mark this placeholder so merge-write.js can
          // find it by identity rather than parsing its own wording back
          // out of `text` — that text is only ever shown at all when a
          // "keep both" decision never turns this entry back into a real
          // setting line (see merge-write.js's own comment on why the
          // default now removes it from the written file instead).
          joined.push({
            type: 'comment', stack: s.name, dedupe: true, name: line.name,
            text: '# ' + line.name + ' already set above, same value — not repeated'
          });
          return;
        }

        // Renamed against the FIRST source's own value, never chained
        // through whichever source happened to be renamed most recently —
        // firstStack is who keeps the plain name, so it is who the reason
        // shown beside this line has to name (see PLAN_155's own worked
        // example: "demo-db's stays DB_PASSWORD").
        var renamedName = line.name + '_' + suffix(leaf(s.name));
        renamed.push({ from: line.name, to: renamedName, stack: s.name, firstStack: firstSeen[line.name].stack });
        joined.push({
          type: 'setting', stack: s.name, name: renamedName, value: line.value,
          comment: (line.comment ? line.comment + ' — ' : '') +
            'renamed: ' + leaf(s.name) + ' also sets ' + line.name + ', to a different value'
        });
      });
    });

    return {
      kind: 'settings-join', severity: 'decision', stack: null,
      facts: { date: date, sameValueNames: sameValue, renamed: renamed, joinedLines: joined },
      choices: [{ id: 'accept-join', recommended: true }, { id: 'stop-here', recommended: false }],
      lines: []
    };
  }

  /* =====================================================================
   * Name clashes: containers/services, published ports, and the top-level
   * volumes:/networks:/configs:/secrets: shorthand entries. All follow the
   * same shape: a "taken" set starts empty, then each source (in the order
   * it was ticked) is checked against it and its own names are added in
   * turn — so a clash between the SECOND and THIRD source is still caught,
   * with nothing here privileging any one of them as a host.
   * ===================================================================== */

  // The image's own short name — the last path segment before any tag,
  // lower-cased, anything outside [a-z0-9_.-] dropped. Used as the better
  // default rename for a clashing service (PLAN_155 C17): "nginx:1.27-alpine"
  // -> "nginx", "ghcr.io/foo/adminer:4" -> "adminer", "redis/redis-stack-
  // server" (no tag at all) -> "redis-stack-server". Returns '' for an image
  // with nothing usable (empty, or a build-only service with no image:).
  function imageShortName(image) {
    if (!image) return '';
    var noDigest = String(image).split('@')[0];
    var slash = noDigest.lastIndexOf('/');
    var afterSlash = slash >= 0 ? noDigest.slice(slash + 1) : noDigest;
    var colon = afterSlash.indexOf(':');
    var namePart = colon >= 0 ? afterSlash.slice(0, colon) : afterSlash;
    return namePart.toLowerCase().replace(/[^a-z0-9_.-]/g, '');
  }

  function findServiceNameClashes(sources, docCache) {
    var out = [];
    var taken = {};   // final service name -> the image that already owns it
    sources.forEach(function (s) {
      var svcs = servicesOf(s);
      Object.keys(svcs).forEach(function (name) {
        var finalName = name;
        var clashed = !!taken[name];
        var viaImage = '';
        if (clashed) {
          // The suffix is the fallback, not the best answer (Adrian,
          // 2026-09-16): when the two clashing services run different
          // images, the image's own short name reads better than a name
          // borrowed from the SOURCE, provided it is free and is not simply
          // the other clashing service's own name (which would not tell
          // them apart either). Same image on both sides can't be told
          // apart by it, so the suffix stands.
          var myImage = svcs[name].image || '';
          var otherImage = taken[name].image || '';
          var shortName = (myImage && myImage !== otherImage) ? imageShortName(myImage) : '';
          if (shortName && shortName !== name && !taken[shortName]) {
            finalName = shortName;
            viaImage = shortName;
          } else {
            finalName = name + '_' + leaf(s.name);
          }
        }
        taken[finalName] = { image: svcs[name].image || '' };
        if (clashed) {
          var doc = docCache[s.name];
          out.push({
            kind: 'container-name-clash', severity: 'automatic', stack: s.name,
            facts: { field: 'service', from: name, to: finalName, imageShortName: viaImage },
            lines: doc ? linesEntry(s.name, locateServiceKeyLine(doc, name)) : []
          });
        }
      });
    });
    return out;
  }

  function findContainerNameClashes(sources, docCache) {
    var out = [];
    var taken = {};
    sources.forEach(function (s) {
      Object.keys(servicesOf(s)).forEach(function (svcName) {
        var svc = servicesOf(s)[svcName];
        if (!svc.container_name) return;
        var finalName = svc.container_name;
        var clashed = !!taken[finalName];
        if (clashed) finalName = svc.container_name + '_' + leaf(s.name);
        taken[finalName] = true;
        if (clashed) {
          var doc = docCache[s.name];
          out.push({
            kind: 'container-name-clash', severity: 'automatic', stack: s.name,
            facts: { field: 'container_name', from: svc.container_name, to: finalName, service: svcName },
            lines: doc ? linesEntry(s.name, locateContainerNameLine(doc, svcName, svc.container_name)) : []
          });
        }
      });
    });
    return out;
  }

  // The lowest free port at or above 20000 — high enough that it is never
  // one a person chose on purpose, so a service landing there always reads
  // as "StaXX moved this", never as a coincidence. `usedPorts` is a plain
  // set the caller keeps extending (see findPortClashes() below), so two
  // clashes in the same merge are never handed the same free port twice.
  function pickFreePort(usedPorts) {
    var n = 20000;
    while (usedPorts[String(n)]) n++;
    return n;
  }

  function allPublishedPorts(sources) {
    var used = {};
    sources.forEach(function (s) {
      Object.keys(servicesOf(s)).forEach(function (svcName) {
        (servicesOf(s)[svcName].ports || []).forEach(function (p) {
          if (p.host) used[String(p.host)] = true;
        });
      });
    });
    return used;
  }

  // Two sources publishing the same host port — only one can. The FIRST to
  // publish it is left alone by default and the one found second is the one
  // the popover offers to move, UNLESS exactly one side is behind a
  // `profiles:` key (PLAN_156 F16, applied here rather than left open, and
  // C10's own rule): a profiled service is one nobody starts by default, so
  // it yields regardless of which was picked first. `lines` carries BOTH
  // sides' own line (mover first, held second) — the held side's is kept
  // on the finding even though merge-write.js's port-clash handling (since
  // Adrian's 2026-09-16 decision, Approved/Decline only) never moves that
  // side any more, the same two-line convention address-rewire's split
  // rewrite already uses, just naming two different stacks instead of two
  // lines in one.
  function findPortClashes(sources, docCache) {
    var out = [];
    var taken = {};
    var usedPorts = allPublishedPorts(sources);
    sources.forEach(function (s) {
      Object.keys(servicesOf(s)).forEach(function (svcName) {
        var profiled = (servicesOf(s)[svcName].profiles || []).length > 0;
        (servicesOf(s)[svcName].ports || []).forEach(function (p) {
          if (!p.host) return;
          var held = taken[p.host];
          if (held) {
            // Default mover is whichever was picked SECOND; a profiles:
            // service yields to a plain one no matter the order.
            var moverIsLater = true;
            if (held.profiled !== profiled) moverIsLater = profiled;

            var moverStack = moverIsLater ? s.name : held.stack;
            var moverSvc = moverIsLater ? svcName : held.svc;
            var stayStack = moverIsLater ? held.stack : s.name;
            var staySvc = moverIsLater ? held.svc : svcName;

            var freePort = pickFreePort(usedPorts);
            usedPorts[String(freePort)] = true;

            var moverDoc = docCache[moverStack];
            var stayDoc = docCache[stayStack];
            var lines = [];
            if (moverDoc) lines = lines.concat(linesEntry(moverStack, locatePortLine(moverDoc, moverSvc, p.host)));
            if (stayDoc) lines = lines.concat(linesEntry(stayStack, locatePortLine(stayDoc, staySvc, p.host)));

            out.push({
              kind: 'port-clash', severity: 'decision', stack: moverStack,
              facts: {
                service: moverSvc, port: p.host, freePort: freePort,
                heldBy: staySvc, heldByStack: stayStack
              },
              // The recommended choice's own id IS the free port number —
              // merge-write.js's decisionValue() falls back to whichever
              // choice is recommended when nothing was clicked, and its
              // port-clash handling expects that fallback to already BE a
              // port to move to, not a label needing a further lookup.
              // Adrian's decision (2026-09-16): Approved/Decline like every
              // other card, no "swap which side moves" and no "stop
              // publishing" — declining leaves both ports as written, and
              // the new stack simply will not start until a person changes
              // one of them.
              choices: [{ id: freePort, recommended: true }, { id: 'leave', recommended: false }],
              lines: lines
            });
          } else {
            taken[p.host] = { stack: s.name, svc: svcName, profiled: profiled };
          }
        });
      });
    });
    return out;
  }

  var DECL_KINDS = ['volumes', 'networks', 'configs', 'secrets'];

  function findShorthandClashes(sources, docCache) {
    var out = [];
    DECL_KINDS.forEach(function (kind) {
      var canonical = {};
      sources.forEach(function (s) {
        Object.keys(declOf(s, kind)).forEach(function (name) {
          var def = declOf(s, kind)[name].def;
          if (name in canonical) {
            if (!sameDef(canonical[name], def)) {
              var finalName = name + '_' + leaf(s.name);
              canonical[finalName] = def;
              var doc = docCache[s.name];
              out.push({
                kind: 'shorthand-clash', severity: 'automatic', stack: s.name,
                facts: { declKind: kind, from: name, to: finalName },
                lines: doc ? linesEntry(s.name, locateDeclKeyLine(doc, kind, name)) : []
              });
            }
            // same definition — kept once, nothing renamed, no finding needed
          } else {
            canonical[name] = def;
          }
        });
      });
    });
    return out;
  }

  /* =====================================================================
   * Wiring: an address that can become a service name, and a published
   * port nothing needs any more — symmetric across every source.
   * ===================================================================== */

  function publishedPortsOf(sources) {
    var byPort = {};
    sources.forEach(function (d) {
      Object.keys(servicesOf(d)).forEach(function (svcName) {
        (servicesOf(d)[svcName].ports || []).forEach(function (p) {
          if (p.host) byPort[p.host] = { stack: d.name, service: svcName, container: p.container };
        });
      });
    });
    return byPort;
  }

  function looksAddressy(host) {
    return isIpLike(host) || /\./.test(host);
  }

  function looksHostLike(v) {
    return /^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/.test(String(v));
  }

  var HOST_SUFFIX_RE = /^(?:(.+)_)?HOST$/i;
  var PORT_SUFFIX_RE = /^(?:(.+)_)?PORT$/i;

  function suffixPrefix(re, name) {
    var m = re.exec(name);
    return m ? (m[1] || '').toUpperCase() : null;
  }

  // C2 (PLAN_156's dry run) — a value written as "${VAR}" tells nothing on
  // its own; `environmentResolved` (descriptorFromText()'s own doing) is
  // that value resolved against the source's OWN .env. Every decision below
  // reads the RESOLVED value; every line search and rewrite still uses the
  // AS-WRITTEN one, since that is the text actually sitting in the file.
  function resolvedValueOf(svc, varName) {
    var res = svc.environmentResolved;
    if (res && Object.prototype.hasOwnProperty.call(res, varName)) return res[varName].value;
    return svc.environment ? svc.environment[varName] : undefined;
  }

  function findSplitWiringForService(d, svcName, svc, ports, thisServer, rewired, findings, docCache) {
    var env = svc.environment || {};
    var hostGroups = {}, portGroups = {};
    Object.keys(env).forEach(function (name) {
      var hp = suffixPrefix(HOST_SUFFIX_RE, name);
      if (hp !== null) (hostGroups[hp] = hostGroups[hp] || []).push(name);
      var pp = suffixPrefix(PORT_SUFFIX_RE, name);
      if (pp !== null) (portGroups[pp] = portGroups[pp] || []).push(name);
    });

    function leftAlone(hostVar, note) {
      findings.push({
        kind: 'left-alone', severity: 'wiring', stack: d.name,
        facts: { service: svcName, envVar: hostVar, value: resolvedValueOf(svc, hostVar), note: note }, lines: []
      });
    }

    Object.keys(hostGroups).forEach(function (prefix) {
      var hostVars = hostGroups[prefix];
      var portVars = portGroups[prefix] || [];

      if (hostVars.length > 1 || portVars.length > 1) {
        hostVars.forEach(function (hv) {
          leftAlone(hv, 'shares its prefix with more than one host or port setting — too ambiguous to pair, left as written');
        });
        return;
      }
      if (portVars.length === 0) {
        leftAlone(hostVars[0], 'names a server but has no matching port setting to confirm it against — left as written');
        return;
      }

      var hostVar = hostVars[0], portVar = portVars[0];
      var hostVal = String(resolvedValueOf(svc, hostVar)), portVal = String(resolvedValueOf(svc, portVar));

      if (!/^\d{2,5}$/.test(portVal)) {
        leftAlone(hostVar, 'names a port nothing in this merge publishes — left as written');
        return;
      }
      var target = ports[portVal];
      var selfRef = target && target.stack === d.name && target.service === svcName;
      if (!target || selfRef) {
        leftAlone(hostVar, 'names a port nothing in this merge publishes — left as written');
        return;
      }
      if (!looksHostLike(hostVal)) {
        leftAlone(hostVar, 'does not look like a server address — left as written');
        return;
      }

      var known = thisServer.length ? thisServer.indexOf(hostVal) >= 0 : true;
      if (!known) {
        leftAlone(hostVar, 'looks like an address, but is not confidently this server — left as written');
        return;
      }

      // The line search and the eventual rewrite both need the text AS
      // WRITTEN — "${DB_HOST}", say — never the resolved address, since
      // that is what actually sits in the compose line.
      var hostWritten = String(env[hostVar]), portWritten = String(env[portVar]);

      var doc = docCache[d.name];
      var lines = [];
      if (doc) {
        lines = linesEntry(d.name, locateEnvLine(doc, svcName, hostVar, hostWritten))
          .concat(linesEntry(d.name, locateEnvLine(doc, svcName, portVar, portWritten)));
      }
      findings.push({
        kind: 'address-rewire', severity: 'wiring', stack: d.name,
        facts: {
          service: svcName, split: true, hostVar: hostVar, portVar: portVar,
          fromHost: hostWritten, fromPort: portWritten,
          fromHostResolved: hostVal, fromPortResolved: portVal,
          envVar: hostVar + ' / ' + portVar, from: hostVal + ':' + portVal,
          toStack: target.stack, toService: target.service, toPort: target.container,
          matchedOn: thisServer.length ? 'server-list' : 'port-evidence'
        },
        choices: [{ id: 'rewire', recommended: true, ticked: true }], lines: lines
      });

      var key = target.stack + '/' + target.service + '/' + portVal;
      if (!rewired[key]) {
        rewired[key] = true;
        var targetDoc = docCache[target.stack];
        findings.push({
          kind: 'port-unneeded', severity: 'wiring', stack: target.stack,
          facts: { service: target.service, port: portVal },
          // Recommended and ticked by default, the same as address-rewire —
          // it is the port THIS rewire just made unnecessary, not a general
          // "maybe something else needs it" guess.
          choices: [{ id: 'stop-publishing', recommended: true, ticked: true }],
          lines: targetDoc ? linesEntry(target.stack, locatePortLine(targetDoc, target.service, portVal)) : []
        });
      }
    });
  }

  function findWiringFindings(sources, opts, docCache) {
    var ports = publishedPortsOf(sources);
    var thisServer = (opts && opts.thisServer) || [];
    var rewired = {};
    var findings = [];

    sources.forEach(function (d) {
      Object.keys(servicesOf(d)).forEach(function (svcName) {
        var svc = servicesOf(d)[svcName];

        findSplitWiringForService(d, svcName, svc, ports, thisServer, rewired, findings, docCache);

        var env = svc.environment || {};
        Object.keys(env).forEach(function (varName) {
          var written = String(env[varName]);
          var value = String(resolvedValueOf(svc, varName));
          var m = ADDR_RE.exec(value);
          if (!m) return;

          var addrHost = m[1], port = m[2];
          if (!looksAddressy(addrHost)) return;

          var known = thisServer.length ? thisServer.indexOf(addrHost) >= 0 : isIpLike(addrHost);
          var target = ports[port];

          if (known && target && !(target.stack === d.name && target.service === svcName)) {
            var doc = docCache[d.name];
            findings.push({
              kind: 'address-rewire', severity: 'wiring', stack: d.name,
              facts: {
                service: svcName, envVar: varName, from: written, fromResolved: value,
                toStack: target.stack, toService: target.service, toPort: target.container
              },
              choices: [{ id: 'rewire', recommended: true, ticked: true }],
              lines: doc ? linesEntry(d.name, locateEnvLine(doc, svcName, varName, written)) : []
            });
            var key = target.stack + '/' + target.service + '/' + port;
            if (!rewired[key]) {
              rewired[key] = true;
              var targetDoc = docCache[target.stack];
              findings.push({
                kind: 'port-unneeded', severity: 'wiring', stack: target.stack,
                facts: { service: target.service, port: port },
                choices: [{ id: 'stop-publishing', recommended: true, ticked: true }],
                lines: targetDoc ? linesEntry(target.stack, locatePortLine(targetDoc, target.service, port)) : []
              });
            }
          } else {
            findings.push({
              kind: 'left-alone', severity: 'wiring', stack: d.name,
              facts: {
                service: svcName, envVar: varName, value: value,
                note: known
                  ? 'names a port nothing in this merge publishes — left as written'
                  : 'looks like an address, but is not confidently this server — left as written'
              },
              lines: []
            });
          }
        });
      });
    });

    return findings;
  }

  /* =====================================================================
   * The "checked and fine" list — one entry per category examined that
   * turned up no clash, so silence never reads as "it did not look".
   * ===================================================================== */

  function findCleanEntries(findings) {
    var byKind = {};
    findings.forEach(function (f) { (byKind[f.kind] = byKind[f.kind] || []).push(f); });

    var categories = [
      { kind: 'container-name-clash', label: 'container names' },
      { kind: 'port-clash', label: 'published ports' },
      { kind: 'shorthand-clash', label: 'networks, volumes, configs and secrets' },
      { kind: 'file-clash', label: 'folders on the array' }
    ];

    var out = [];
    categories.forEach(function (cat) {
      var relevant = (byKind[cat.kind] || []).filter(function (f) { return f.severity !== 'clean'; });
      if (relevant.length) return;
      out.push({ kind: 'clean', severity: 'clean', stack: null, facts: { category: cat.label }, lines: [] });
    });
    return out;
  }

  /* =====================================================================
   * Entry point
   * ===================================================================== */

  function examine(sources, opts) {
    sources = sources || [];
    opts = opts || {};

    var docCache = docCacheFor(sources);
    var findings = [];

    // Refusals first — nothing past a refusal is written, so it is said first.
    findings = findings.concat(findHiddenConfigFindings(sources));

    findCompanionFindings(sources, docCache).forEach(function (f) {
      if (f.severity === 'refusal') findings.push(f);
    });

    findings = findings.concat(findStorageFindings(sources, docCache));

    findCompanionFindings(sources, docCache).forEach(function (f) {
      if (f.severity !== 'refusal') findings.push(f);
    });

    findings = findings.concat(findDepthPathFindings(sources, opts, docCache));

    var join = findSettingsJoin(sources, opts);
    if (join) findings.push(join);

    findings = findings.concat(findServiceNameClashes(sources, docCache));
    findings = findings.concat(findContainerNameClashes(sources, docCache));
    findings = findings.concat(findPortClashes(sources, docCache));
    findings = findings.concat(findShorthandClashes(sources, docCache));

    findings = findings.concat(findWiringFindings(sources, opts, docCache));

    findings = findings.concat(findCleanEntries(findings));

    // Stamp every finding with its stable key now the order is final.
    findings.forEach(function (f, idx) { f.key = findingKey(f.kind, f.stack, idx); });

    // The rename maps the write pass needs — read straight back out of the
    // findings just built, so nothing here is decided twice.
    var plan = { serviceRenames: {}, containerNameRenames: {}, declRenames: {} };
    findings.forEach(function (f) {
      if (f.kind === 'container-name-clash' && f.facts.field === 'service') {
        plan.serviceRenames[f.stack + '/' + f.facts.from] = f.facts.to;
      }
      if (f.kind === 'container-name-clash' && f.facts.field === 'container_name') {
        plan.containerNameRenames[f.stack + '/' + f.facts.service] = { from: f.facts.from, to: f.facts.to };
      }
      if (f.kind === 'shorthand-clash') {
        plan.declRenames[f.stack + '/' + f.facts.declKind + '/' + f.facts.from] = f.facts.to;
      }
    });

    return { findings: findings, plan: plan };
  }

  var API = {
    examine: examine,
    // Exposed for tests and for merge-write.js — pure helpers with their
    // own edge cases worth proving directly rather than only through
    // examine()'s combined output.
    suffix: suffix,
    leaf: leaf,
    adjustUpPath: adjustUpPath,
    resolveDepthPath: resolveDepthPath,
    findingKey: findingKey,
    // PLAN_155 C10: the dry run picks its own port-clash decision by hand,
    // and it must land on the exact same number examine() would have
    // suggested — sharing the helper is what makes that provable rather
    // than merely likely.
    pickFreePort: pickFreePort,
    allPublishedPorts: allPublishedPorts,
    // Exposed so the step 2 summary card can flag a hidden-config refusal
    // for one stack the moment it is read, without waiting on a full
    // examine() over every picked source.
    findHiddenConfigFindings: findHiddenConfigFindings
  };

  if (typeof window !== 'undefined') window.StaxxMergeExamine = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
