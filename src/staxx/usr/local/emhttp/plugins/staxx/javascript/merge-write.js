/* StaXX — turning a decided merge into real file text (PLAN_148 phase 4).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * merge-examine.js works out WHAT a merge must do; this file is the other
 * half — turning that into the actual bytes, by SPLICING text rather than
 * re-serialising it. Rule 2 in CLAUDE.md is the whole reason this is a
 * separate pass: compose-model.js's own writers are built to edit one
 * already-open file one field at a time, and re-parsing two files into a
 * tree and printing a new one back out would quietly drop whatever the
 * author wrote that the tree does not model (comment placement, blank-line
 * rhythm, quoting style). So instead:
 *
 *   - the host's own file is taken over completely unchanged, and every
 *     folded-in stack's own service blocks are lifted out of ITS file
 *     verbatim — comments, anchors, blank lines and all — and appended;
 *   - the only edits made to that verbatim text are the ones the merge
 *     itself decided on (a rename, a rewritten address), applied as a
 *     targeted line/substring replacement, never a reprint of the block;
 *   - compose-model.js's own tested writers (renameService, renameDeclared,
 *     splice) do every edit that touches more than one line at once — a
 *     rename has to follow every reference to the old name — so this file
 *     never re-implements what that one already gets right.
 *
 * A service's own raw span (its lead comment, its own lines, and the gap up
 * to the next entry) is found the same way compose-model.js's own tidy()
 * pass finds one — buildSpans() there is not exported, because tidy() also
 * needs the refusal machinery around REORDERING a scope, which this has no
 * need of: appending each incoming stack's services after the host's own,
 * in the order they were ticked, never crosses an anchor behind its own
 * alias, so nothing here needs to detect that and refuse. computeBlocks()
 * below is the same idea (a key's own lead comment plus its trailing gap
 * travels as one block) kept to the narrower job this actually has.
 *
 * Same dual shape as merge-examine.js: `window.StaxxMergeWrite` in the
 * browser, `module.exports` under Node.
 */

(function () {
  'use strict';

  var CM = (typeof require === 'function') ? require('./compose-model.js') : (typeof window !== 'undefined' ? window.StaxxYaml : null);
  var ME = (typeof require === 'function') ? require('./merge-examine.js') : (typeof window !== 'undefined' ? window.StaxxMergeExamine : null);

  /* =====================================================================
   * Small line-level helpers — deliberately simpler than compose-model.js's
   * own classify(): this never reorders or refuses, only walks a lead/trail
   * comment run around a span already known to be a whole top-level entry,
   * so the edge cases classify() carries for scalars-mid-parse do not apply.
   * ===================================================================== */

  function lineKind(line) {
    var m = /^([ \t]*)#/.exec(line);
    if (m) return { kind: 'comment', indent: m[1].length };
    if (/^[ \t]*$/.test(line)) return { kind: 'blank', indent: 0 };
    return { kind: 'other', indent: /^([ \t]*)/.exec(line)[1].length };
  }

  function leadStart(lines, at, indent) {
    var i = at - 1;
    while (i >= 0) {
      var c = lineKind(lines[i]);
      if (c.kind !== 'comment' || c.indent !== indent) break;
      i--;
    }
    return i + 1;
  }

  // A map's direct children as whole blocks: a key's own lead comment (same
  // indent, directly above), its own lines, and the gap up to the next
  // key's OWN lead-inclusive start (so a blank line or a trailing note
  // between two entries travels with the one before it, never lost and
  // never duplicated). Mirrors buildSpans()/layoutScope()'s block-slicing in
  // compose-model.js, without any of the reorder/refusal machinery this has
  // no need of.
  function computeBlocks(doc, mapNode) {
    var keys = mapNode.keys, spans = [];
    for (var i = 0; i < keys.length; i++) {
      var p = mapNode.pairs[keys[i]];
      spans.push({ key: keys[i], indent: p.indent, start: leadStart(doc.lines, p.start, p.indent), contentEnd: p.end });
    }
    for (i = 0; i < spans.length; i++) {
      var limit = i + 1 < spans.length ? spans[i + 1].start : mapNode.end;
      var j = spans[i].contentEnd, last = j;
      while (j < limit) {
        var c = lineKind(doc.lines[j]);
        if (c.kind === 'blank') { j++; continue; }
        if (c.kind === 'comment' && c.indent > spans[i].indent) { j++; last = j; continue; }
        break;
      }
      spans[i].contentEnd = last;
    }
    var order = [], blocks = {}, contentEnds = {};
    for (i = 0; i < spans.length; i++) {
      var to = i + 1 < spans.length ? spans[i + 1].start : mapNode.end;
      order.push(spans[i].key);
      blocks[spans[i].key] = doc.lines.slice(spans[i].start, to);
      // Offset of the block's own content end within the slice above — the
      // right place to inject an addition ahead of the gap that follows it.
      contentEnds[spans[i].key] = spans[i].contentEnd - spans[i].start;
    }
    return { order: order, blocks: blocks, contentEnds: contentEnds };
  }

  function servicesMapOf(doc) {
    var svc = doc.root && doc.root.kind === 'map' ? doc.root.pairs['services'] : null;
    return svc && svc.value && svc.value.kind === 'map' ? svc.value : null;
  }

  function declMapOf(doc, kind) {
    var d = doc.root && doc.root.kind === 'map' ? doc.root.pairs[kind] : null;
    return d && d.value && d.value.kind === 'map' ? d.value : null;
  }

  /* =====================================================================
   * Targeted, narrow edits on a doc's raw lines — a rewrite, never a
   * reprint. Each is handed the exact old/new text the examiner already
   * worked out, and only ever touches the one line it names.
   * ===================================================================== */

  // Replaces a scalar "key: value  # comment" line's value, keeping
  // everything else — indentation, the key, quoting style of what comes
  // after, any trailing comment — untouched.
  function rewriteScalarValue(line, newValue) {
    var m = /^(\s*[^:\s][^:]*:\s*)(.*)$/.exec(line);
    if (!m) return line;
    var rest = m[2];
    var cm = /^([^#]*?)(\s*#.*)?$/.exec(rest);
    var comment = cm[2] || '';
    var quote = /^['"]/.test(rest) ? rest.charAt(0) : '';
    return m[1] + quote + newValue + quote + comment;
  }

  function rewriteContainerName(doc, serviceKey, oldName, newName) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return false;
    for (var i = p.start; i < p.end; i++) {
      var m = /^\s*container_name:\s*(.*)$/.exec(doc.lines[i]);
      if (!m) continue;
      var cm = /^([^#]*?)(\s*#.*)?$/.exec(m[1]);
      var val = cm[1].replace(/^['"]|['"]$/g, '').trim();
      if (val !== oldName) continue;
      doc.lines[i] = rewriteScalarValue(doc.lines[i], newName);
      return true;
    }
    return false;
  }

  // Substring replace inside whichever line names both the env var and the
  // old address — safe because the examiner already matched that exact
  // "host:port" text on that exact variable, so there is nothing to
  // re-derive here, only to apply.
  function rewriteEnvAddress(doc, serviceKey, envVar, oldAddr, newAddr) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return false;
    for (var i = p.start; i < p.end; i++) {
      var line = doc.lines[i];
      if (line.indexOf(envVar) === -1 || line.indexOf(oldAddr) === -1) continue;
      doc.lines[i] = line.split(oldAddr).join(newAddr);
      return true;
    }
    return false;
  }

  /* =====================================================================
   * A published port — either changed to a free one or dropped entirely,
   * both decisions from step 3's port-clash and step 4's port-unneeded
   * findings. Scoped to the "ports:" key's own child lines (not just any
   * "- " item in the service) so a bind-mount or a volumes: entry sharing
   * the same shape is never mistaken for one.
   * ===================================================================== */

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

  // "8091:3306", quoted or not — never a bind-address-qualified form, which
  // nothing else in this file parses either (see splitPortEntry() above).
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

  // Changes the published host port on one ports: entry, keeping the
  // container side, the protocol suffix and the quoting style exactly as
  // the author wrote them.
  function rewritePortHost(doc, serviceKey, oldHostPort, newHostPort) {
    var range = findPortsRange(doc, serviceKey);
    if (!range) return false;
    for (var i = range.start; i < range.end; i++) {
      var parsed = parsePortListLine(doc.lines[i]);
      if (!parsed) continue;
      var pb = parsePortBody(parsed.body);
      if (pb.host !== String(oldHostPort)) continue;
      doc.lines[i] = parsed.prefix + parsed.quote + newHostPort + ':' + pb.container + pb.proto + parsed.quote;
      return true;
    }
    return false;
  }

  // Drops the whole ports: entry — not a rewrite to a bare container-port
  // form, which compose still publishes on a random host port. The
  // container's own port needs nothing declared here to stay reachable
  // from the other services now sharing its network.
  function removePortPublish(doc, serviceKey, hostPort) {
    var range = findPortsRange(doc, serviceKey);
    if (!range) return false;
    for (var i = range.start; i < range.end; i++) {
      var parsed = parsePortListLine(doc.lines[i]);
      if (!parsed) continue;
      var pb = parsePortBody(parsed.body);
      if (pb.host !== String(hostPort)) continue;
      CM.splice(doc, i, 1, []);
      return true;
    }
    return false;
  }

  /* =====================================================================
   * Carrying a folded-in stack's own stack-level icon/description/links
   * onto its own service, per PLAN_148's "What the merge does to the file".
   * Only these three ever move — the rest of a stack-level x-unraid block
   * (category, author, update policy…) is not a per-container idea and is
   * left on the incoming stack's own file, untouched, since that file is
   * never rewritten, only read.
   * ===================================================================== */

  var IDENTITY_KEYS = ['icon', 'description', 'links'];

  function emitScalarSimple(value) {
    if (Array.isArray(value)) return null;   // 'links' as a list needs its own block form — see emitIdentityLines
    var s = String(value);
    if (s === '' || /^\s|\s$/.test(s) || /[:#{}\[\],&*!|>'"%@`]/.test(s) ||
        /^(true|false|null|yes|no|on|off|~)$/i.test(s) || /^-?\d+(\.\d+)?$/.test(s)) {
      return '"' + s.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
    }
    return s;
  }

  // The lines to add under a service's own x-unraid:, at childIndent spaces
  // each — a plain scalar for icon/description, and a short block for links
  // (an array of {label,url} or plain strings — whatever the stack-level
  // block already held, carried across in the same shape).
  function emitIdentityLines(stackXUnraid, childIndent) {
    var pad = new Array(childIndent + 1).join(' ');
    var pad2 = new Array(childIndent + 3).join(' ');
    var out = [];
    IDENTITY_KEYS.forEach(function (key) {
      var v = stackXUnraid[key];
      if (v === undefined || v === null || v === '') return;
      if (key === 'links' && Array.isArray(v)) {
        if (!v.length) return;
        out.push(pad + 'links:');
        v.forEach(function (entry) {
          if (entry && typeof entry === 'object') {
            out.push(pad2 + '- label: ' + emitScalarSimple(entry.label || ''));
            out.push(pad2 + '  url: ' + emitScalarSimple(entry.url || ''));
          } else {
            out.push(pad2 + '- ' + emitScalarSimple(entry));
          }
        });
        return;
      }
      var scalar = emitScalarSimple(v);
      if (scalar !== null) out.push(pad + key + ': ' + scalar);
    });
    return out;
  }

  // Inserts the identity lines into one service's already-extracted block —
  // ahead of the x-unraid: line's own existing children if the service
  // already carries one, so nothing existing is disturbed; a brand new
  // x-unraid: section otherwise, placed just before the block's trailing
  // gap so it reads as part of the service, not as a stray note under it.
  function injectIdentity(block, contentEndOffset, keyRelIndex, keyIndent, stackXUnraid) {
    var hasAny = IDENTITY_KEYS.some(function (k) {
      var v = stackXUnraid[k];
      return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length);
    });
    if (!hasAny) return block;

    // The indent one level under the service's own keys (image:, ports:…).
    var childIndent = keyIndent + 2;
    for (var i = keyRelIndex + 1; i < contentEndOffset; i++) {
      var k = lineKind(block[i]);
      if (k.kind === 'other') { childIndent = k.indent; break; }
    }

    var xuLineIdx = -1;
    for (i = keyRelIndex + 1; i < contentEndOffset; i++) {
      if (new RegExp('^\\s{' + childIndent + '}x-unraid:\\s*$').test(block[i])) { xuLineIdx = i; break; }
    }

    var added = emitIdentityLines(stackXUnraid, childIndent + 2);
    if (!added.length) return block;

    if (xuLineIdx >= 0) {
      return block.slice(0, xuLineIdx + 1).concat(added, block.slice(xuLineIdx + 1));
    }
    var pad = new Array(childIndent + 1).join(' ');
    return block.slice(0, contentEndOffset).concat([pad + 'x-unraid:'], added, block.slice(contentEndOffset));
  }

  /* =====================================================================
   * Building a descriptor (merge-examine.js's input shape) from raw text —
   * the wizard's own version of the adapter tests/merge_examine.js keeps
   * for its fixtures. Kept here, not duplicated a third time, since the
   * write pass needs one anyway to call examine()/find the same renames it
   * is about to apply.
   * ===================================================================== */

  function toPlain(node) {
    if (!node) return undefined;
    if (node.kind === 'scalar') return node.value;
    if (node.kind === 'seq') return node.items.map(function (it) { return toPlain(it.value); });
    if (node.kind === 'map') {
      var o = {};
      node.keys.forEach(function (k) { o[k] = toPlain(node.pairs[k].value); });
      return o;
    }
    return undefined;
  }

  function asStringArray(v) {
    if (v == null) return [];
    return Array.isArray(v) ? v.slice() : [v];
  }

  function splitPortEntry(entry) {
    var text = String(entry), proto = 'tcp';
    var pm = /\/(tcp|udp)$/.exec(text);
    if (pm) { proto = pm[1]; text = text.slice(0, -pm[0].length); }
    var parts = text.split(':');
    if (parts.length >= 2) return { host: parts[0], container: parts[1], protocol: proto };
    return { host: '', container: parts[0], protocol: proto };
  }

  function readEnvText(text) {
    if (text == null) return null;
    var lines = String(text).split(/\r?\n/);
    if (lines.length && lines[lines.length - 1] === '') lines.pop();
    var out = [];
    lines.forEach(function (raw) {
      if (raw.trim() === '') { out.push({ type: 'blank' }); return; }
      if (/^\s*#/.test(raw)) { out.push({ type: 'comment', text: raw }); return; }
      var eq = raw.indexOf('=');
      if (eq < 0) { out.push({ type: 'comment', text: raw }); return; }
      out.push({ type: 'setting', name: raw.slice(0, eq), value: raw.slice(eq + 1), comment: '' });
    });
    return { lines: out };
  }

  // name: the stack's LEAF name — its directory name, and the same string
  // Docker stamps as the compose project name — never its full path under
  // the store root. This is the identity a merged file's own volume names
  // and rename suffixes are built from (see merge-examine.js's suffix() and
  // its storage-volume finding), so a path here would leak straight into
  // that text; the caller keeps the full path for its own bookkeeping
  // (which folder to read/write) separately. composeText: raw compose file
  // text. envText: raw .env text, or null when there is none. files:
  // top-level names beside the compose file (companion files/folders),
  // excluding the compose file(s) themselves and ".env".
  function descriptorFromText(name, composeText, envText, files) {
    var doc = CM.parse(composeText);
    var plain = toPlain(doc.root) || {};

    var services = {};
    Object.keys(plain.services || {}).forEach(function (svcName) {
      var raw = plain.services[svcName] || {};
      var volumes = asStringArray(raw.volumes).map(function (entry) {
        if (typeof entry !== 'string') return null;
        var parts = entry.split(':');
        var source = parts[0], target = parts[1] || '';
        var type = (source.charAt(0) === '.' || source.charAt(0) === '/') ? 'bind' : 'named';
        return { type: type, source: source, target: target };
      }).filter(Boolean);

      var environment = {};
      if (Array.isArray(raw.environment)) {
        raw.environment.forEach(function (kv) {
          var eq = String(kv).indexOf('=');
          if (eq >= 0) environment[kv.slice(0, eq)] = kv.slice(eq + 1);
        });
      } else if (raw.environment && typeof raw.environment === 'object') {
        environment = raw.environment;
      }

      services[svcName] = {
        image: raw.image || '',
        container_name: raw.container_name || undefined,
        ports: asStringArray(raw.ports).map(splitPortEntry),
        volumes: volumes,
        environment: environment,
        env_file: asStringArray(raw.env_file),
        networks: Array.isArray(raw.networks) ? raw.networks.slice() : Object.keys(raw.networks || {}),
        depends_on: Array.isArray(raw.depends_on) ? raw.depends_on.slice() : Object.keys(raw.depends_on || {}),
        x_unraid: raw['x-unraid'] || {}
      };
    });

    function declBlock(key) {
      var out = {};
      Object.keys(plain[key] || {}).forEach(function (n) {
        var def = plain[key][n] || {};
        out[n] = { external: !!(def && def.external), def: def };
      });
      return out;
    }

    return {
      name: name,
      files: files || [],
      env: readEnvText(envText),
      compose: {
        services: services,
        volumes: declBlock('volumes'),
        networks: declBlock('networks'),
        configs: declBlock('configs'),
        secrets: declBlock('secrets'),
        stack_x_unraid: plain['x-unraid'] || {}
      }
    };
  }

  /* =====================================================================
   * Decisions — turning a wizard answer (or its absence) into the choice
   * to actually apply. Keyed exactly as the wizard keys mergeState.decisions
   * (stacks.js's own mergeFindingKey()): "<kind>|<stack or ''>|<index into
   * this call's own findings array>". A caller that passes no decisions at
   * all, or leaves one finding out, gets that finding's RECOMMENDED choice —
   * so nothing here can regress the no-decisions case merge-examine.js's own
   * "recommended" flag already existed to describe.
   * ===================================================================== */

  function findingKey(f, idx) {
    return f.kind + '|' + (f.stack || '') + '|' + idx;
  }

  function recommendedChoiceId(f) {
    var rec = null;
    (f.choices || []).forEach(function (c) { if (c.recommended) rec = c.id; });
    return rec;
  }

  // A 'decision' finding's raw stored value defaults to its recommended
  // choice id; a 'wiring' finding's tickbox defaults to its one choice's own
  // `ticked` flag (true for a rewire, false for a port nothing needs any
  // more) — the wizard already renders exactly that default, so an
  // unanswered tickbox and this must agree.
  function decisionValue(decisions, f, idx) {
    var stored = decisions[findingKey(f, idx)];
    if (f.severity === 'wiring') {
      var c = f.choices && f.choices[0];
      var defaultTick = c ? !!c.ticked : false;
      return stored === undefined ? defaultTick : !!stored;
    }
    return stored === undefined ? recommendedChoiceId(f) : stored;
  }

  // port-clash's one non-boolean, non-plain-id answer: a free port picked by
  // the wizard. Accepted as a bare number/numeric string, or as
  // { id: 'free-port', port: N } for a wizard that sends the fuller shape —
  // either way, 'free-port' with no number attached is left unresolved
  // rather than inventing a port nobody chose.
  function freePortFrom(stored) {
    if (stored && typeof stored === 'object') return stored.port ? Number(stored.port) : null;
    if (typeof stored === 'number') return stored;
    if (typeof stored === 'string' && /^\d+$/.test(stored)) return Number(stored);
    return null;
  }

  /* =====================================================================
   * The write itself
   * ===================================================================== */

  // host / each of incomingList: { name, text, envText, files } — name is
  // the stack's LEAF (see descriptorFromText() above), not its path; the
  // caller (stacks.js's mergeRebuild()) keeps the path itself for reading
  // and writing, and only ever hands this file the leaf. opts:
  // { date, thisServer, decisions }. decisions is a plain object, keyed as
  // findingKey() above describes, valued as mergeState.decisions in
  // stacks.js already stores it: a choice id (or, for port-clash, a free
  // port number) for a 'decision' finding, true/false for a 'wiring' one.
  // Everything the examiner would refuse on (a second settings file, an
  // unreadable file, a review lock, the store) is the caller's job to have
  // already checked — see Merge.php, which is where those live because they
  // need the filesystem, not just text.
  //
  // -> { composeText, envText, historyNote, findings, refusals }
  //    refusals: every severity:'refusal' finding examine() itself raised
  //    (a file-clash), PLUS any 'decision' finding whose answer was
  //    "stop here" — composeText/envText are still built in that case, so
  //    the caller sees the "what would happen" list without having to run
  //    the maths twice, but must not write it.
  function buildMergedText(host, incomingList, opts) {
    opts = opts || {};
    var date = opts.date || new Date().toISOString().slice(0, 10);
    var decisions = opts.decisions || {};

    var hostDesc = descriptorFromText(host.name, host.text, host.envText, host.files);
    var incDescs = incomingList.map(function (s) {
      return descriptorFromText(s.name, s.text, s.envText, s.files);
    });

    var exam = ME.examine(hostDesc, incDescs, opts);
    var refusals = [];
    exam.findings.forEach(function (f, idx) {
      if (f.severity === 'refusal') { refusals.push(f); return; }
      if ((f.kind === 'storage-volume' || f.kind === 'settings-join') &&
          decisionValue(decisions, f, idx) === 'stop-here') {
        refusals.push(f);
      }
    });

    // Which service key (per incoming stack) an automatic clash renamed to,
    // and the same for container_name and for each declared-block kind —
    // read straight back out of the examiner's own findings, so this never
    // recomputes a decision merge-examine.js has already made.
    var svcRename = {}, cnRename = {}, declRename = {};
    exam.findings.forEach(function (f) {
      if (f.kind === 'container-name-clash' && f.facts.field === 'service') {
        svcRename[f.stack + '/' + f.facts.from] = f.facts.to;
      }
      if (f.kind === 'container-name-clash' && f.facts.field === 'container_name') {
        cnRename[f.stack + '/' + f.facts.service] = { from: f.facts.from, to: f.facts.to };
      }
      if (f.kind === 'shorthand-clash') {
        declRename[f.stack + '/' + f.facts.declKind + '/' + f.facts.from] = f.facts.to;
      }
    });

    var hostDoc = CM.parse(host.text);

    var incDocs = incomingList.map(function (s, idx) {
      var doc = CM.parse(s.text);
      var incDesc = incDescs[idx];

      // 1. Service-key renames — compose-model.js's own writer, so every
      // reference inside this file (depends_on, section stashes) follows.
      Object.keys(svcRename).forEach(function (k) {
        if (k.indexOf(s.name + '/') !== 0) return;
        var from = k.slice(s.name.length + 1), to = svcRename[k];
        CM.renameService(doc, from, to);
      });

      // 2. container_name renames — narrower than a service key, and
      // compose-model.js has no batch writer for it since nothing else in
      // a file ever refers to a container_name; a direct, targeted line
      // replace is the whole job.
      Object.keys(cnRename).forEach(function (k) {
        if (k.indexOf(s.name + '/') !== 0) return;
        var origSvc = k.slice(s.name.length + 1);
        var finalSvc = svcRename[s.name + '/' + origSvc] || origSvc;
        rewriteContainerName(doc, finalSvc, cnRename[k].from, cnRename[k].to);
      });

      // 3. Declared name renames (networks:/volumes:/configs:/secrets:) —
      // compose-model.js's own writer again, for the same reason as (1).
      Object.keys(declRename).forEach(function (k) {
        var parts = k.split('/');
        if (parts[0] !== s.name) return;
        CM.renameDeclared(doc, parts[1], parts[2], declRename[k]);
      });

      // 4. Wiring — an address rewritten to reach the arriving container by
      // name. Ticked by default (rewire's one choice carries
      // recommended:true, ticked:true); unticking it leaves the value
      // exactly as the author wrote it. Only ever a substring inside one
      // line the examiner already located exactly — two such substitutions,
      // one per line, when the finding came from a separate *_HOST/*_PORT
      // pair rather than one combined value.
      exam.findings.forEach(function (f, idx) {
        if (f.kind !== 'address-rewire' || f.stack !== s.name) return;
        if (!decisionValue(decisions, f, idx)) return;
        var finalSvc = svcRename[s.name + '/' + f.facts.service] || f.facts.service;
        if (f.facts.split) {
          rewriteEnvAddress(doc, finalSvc, f.facts.hostVar, f.facts.fromHost, f.facts.toService);
          rewriteEnvAddress(doc, finalSvc, f.facts.portVar, f.facts.fromPort, f.facts.toPort);
          return;
        }
        rewriteEnvAddress(doc, finalSvc, f.facts.envVar, f.facts.from, f.facts.toService + ':' + f.facts.toPort);
      });

      // 5. Port clash — a specific free port the wizard supplied rewrites
      // the incoming service's own published port; "stop publishing"
      // removes the publication and keeps the container's own port
      // untouched; no decision, or 'free-port' with no number attached,
      // leaves it exactly as written — inventing a port nobody chose is
      // not this file's call to make.
      exam.findings.forEach(function (f, idx) {
        if (f.kind !== 'port-clash' || f.stack !== s.name) return;
        var decision = decisionValue(decisions, f, idx);
        var finalSvc = svcRename[s.name + '/' + f.facts.service] || f.facts.service;
        if (decision === 'stop-publishing') {
          removePortPublish(doc, finalSvc, f.facts.port);
          return;
        }
        var newPort = freePortFrom(decision);
        if (newPort) rewritePortHost(doc, finalSvc, f.facts.port, newPort);
      });

      // 6. A published port nothing needs any more, offered unticked by
      // default so the default is to leave it open. Ticked, it removes the
      // publication and keeps the container's own port.
      exam.findings.forEach(function (f, idx) {
        if (f.kind !== 'port-unneeded' || f.stack !== s.name) return;
        if (!decisionValue(decisions, f, idx)) return;
        var finalSvc = svcRename[s.name + '/' + f.facts.service] || f.facts.service;
        removePortPublish(doc, finalSvc, f.facts.port);
      });

      return { name: s.name, doc: doc, desc: incDesc };
    });

    // Wiring findings that land on the HOST's own service (the other, and
    // more common, direction in the worked example: the arriving container
    // is the one with the address, but the host could just as well hold
    // it). This is a deliberate, stated exception to "the host's text is
    // unchanged" — the confirm step is what tells a person this container
    // will be rebuilt, and it is only ever this one targeted line.
    exam.findings.forEach(function (f, idx) {
      if (f.kind !== 'address-rewire' || f.stack !== host.name) return;
      if (!decisionValue(decisions, f, idx)) return;
      if (f.facts.split) {
        rewriteEnvAddress(hostDoc, f.facts.service, f.facts.hostVar, f.facts.fromHost, f.facts.toService);
        rewriteEnvAddress(hostDoc, f.facts.service, f.facts.portVar, f.facts.fromPort, f.facts.toPort);
        return;
      }
      rewriteEnvAddress(hostDoc, f.facts.service, f.facts.envVar, f.facts.from, f.facts.toService + ':' + f.facts.toPort);
    });

    // A port-unneeded finding can just as well land on the HOST's own
    // published port — same tick, same default, same effect, just applied
    // to hostDoc instead of one of the incoming docs above.
    exam.findings.forEach(function (f, idx) {
      if (f.kind !== 'port-unneeded' || f.stack !== host.name) return;
      if (!decisionValue(decisions, f, idx)) return;
      removePortPublish(hostDoc, f.facts.service, f.facts.port);
    });

    /* --- splice every incoming service in, each behind its own comment --- */

    var svcMapHost = servicesMapOf(hostDoc);
    var insertAt = svcMapHost ? svcMapHost.end : hostDoc.lines.length;
    var toInsert = [];

    incDocs.forEach(function (inc) {
      var svcMap = servicesMapOf(inc.doc);
      if (!svcMap) return;
      var blocks = computeBlocks(inc.doc, svcMap);

      blocks.order.forEach(function (finalKey) {
        var p = svcMap.pairs[finalKey];
        var block = blocks.blocks[finalKey];
        var contentEnd = blocks.contentEnds[finalKey];
        var keyRelIndex = p.start - leadStart(inc.doc.lines, p.start, p.indent);

        var stackXU = inc.desc.compose.stack_x_unraid || {};
        block = injectIdentity(block, contentEnd, keyRelIndex, p.indent, stackXU);

        // Indented to the service's own column, not column 0 — a lead
        // comment only reads as belonging to a key when it sits at that
        // key's exact indent (see leadStart() above, and compose-model.js's
        // own tidyLeadStart(), which both enforce the same rule); an
        // unindented note above an indented key is a stray line neither
        // recognises as this service's own.
        toInsert.push(new Array(p.indent + 1).join(' ') + '# folded in from ' + inc.name + ', ' + date);
        toInsert = toInsert.concat(block);
      });
    });

    if (toInsert.length) {
      // A blank line ahead of the first arrival keeps it visually apart
      // from the host's own last service — cosmetic, never load-bearing,
      // so it is only added when the host's own last line is not already
      // blank.
      if (insertAt > 0 && !/^\s*$/.test(hostDoc.lines[insertAt - 1])) toInsert = [''].concat(toInsert);
      CM.splice(hostDoc, insertAt, 0, toInsert);
    }

    /* --- union the top-level declared blocks: volumes/networks/configs/secrets --- */

    var DECL_KINDS = ['volumes', 'networks', 'configs', 'secrets'];
    DECL_KINDS.forEach(function (kind) {
      var already = {};
      Object.keys(hostDesc.compose[kind] || {}).forEach(function (n) { already[n] = true; });

      // Where a synthesised entry has to sit — the host's OWN indent for this
      // section when one already exists (an addition lands as a further
      // sibling inside it, not at column 0), or the 2-space convention every
      // other synthesised section here already assumes when the section is
      // being created fresh below. A block lifted verbatim from an incoming
      // file carries its own indent already and needs none of this.
      var declMapHost = declMapOf(hostDoc, kind);
      var keyIndent = declMapHost ? declMapHost.indent : 2;
      var childIndent = keyIndent + 2;
      var keyPad = new Array(keyIndent + 1).join(' ');
      var childPad = new Array(childIndent + 1).join(' ');

      // A volume step 3 flagged as changing identity gets the "keep using
      // what already exists" stub below instead of its own plain
      // declaration, UNLESS the answer was "start with empty storage" — in
      // which case the incoming file's own un-external "dbdata: {}" block
      // is exactly what is wanted, carried across like any other union
      // entry, so Docker prefixes the fresh storage with the HOST's name
      // (facts.newName) on its own. Keyed by the decision actually made
      // ('keep-existing' the default, or 'start-empty'), not a bare flag —
      // carrying BOTH across would leave two conflicting entries.
      var flaggedVolumes = {};
      if (kind === 'volumes') {
        exam.findings.forEach(function (f, idx) {
          if (f.kind === 'storage-volume') flaggedVolumes[f.facts.volume] = decisionValue(decisions, f, idx);
        });
      }

      var additions = [];
      incDocs.forEach(function (inc) {
        var declMap = declMapOf(inc.doc, kind);
        if (!declMap) return;
        var blocks = computeBlocks(inc.doc, declMap);
        var incDeclared = inc.desc.compose[kind] || {};

        blocks.order.forEach(function (renamedKey) {
          // computeBlocks() reads the renamed doc, so its own keys already
          // carry a rename applied above; the descriptor's ORIGINAL name is
          // what union-membership was decided against in examine(), so the
          // two are matched back up through declRename.
          var origKey = renamedKey;
          Object.keys(declRename).forEach(function (rk) {
            var parts = rk.split('/');
            if (parts[0] === inc.name && parts[1] === kind && declRename[rk] === renamedKey) origKey = parts[2];
          });
          if (!incDeclared[origKey]) return;   // shouldn't happen, but never invent a block
          // The stub below replaces this for every answer except
          // "start with empty storage", where this plain block IS the
          // wanted answer.
          if (flaggedVolumes[origKey] && flaggedVolumes[origKey] !== 'start-empty') return;

          if (already[renamedKey]) return;     // identical to the host's own — kept once, examine() already checked
          already[renamedKey] = true;
          additions = additions.concat(blocks.blocks[renamedKey]);
        });
      });

      // The default "keep using the existing storage" answer for a volume
      // flagged as changing identity (step 3's storage question) — an
      // external: true / name: <old> stub, synthesised text rather than
      // lifted from either file, since nothing on disk already says this.
      // Skipped for "start with empty storage", which was already carried
      // across as a plain block above.
      if (kind === 'volumes') {
        exam.findings.forEach(function (f) {
          if (f.kind !== 'storage-volume') return;
          if (already[f.facts.volume]) return;
          if (flaggedVolumes[f.facts.volume] === 'start-empty') return;
          already[f.facts.volume] = true;
          additions.push(keyPad + f.facts.volume + ':', childPad + 'external: true', childPad + 'name: ' + f.facts.oldName);
        });
      }

      if (!additions.length) return;

      // additions already carries each entry at its own original top-level
      // indent — lifted straight out of the incoming doc at whatever depth
      // that file used, or synthesised above at the host's own depth — so
      // nothing here re-indents it.
      if (declMapHost) {
        CM.splice(hostDoc, declMapHost.end, 0, additions);
      } else {
        // Trim any blank lines the file already ended on before adding the
        // new section's own single separating blank — otherwise the gap a
        // service span carries after it (see computeBlocks()'s own comment)
        // and the file's own trailing blank stack up into a widening run of
        // nothing, section after section.
        var end = hostDoc.lines.length;
        while (end > 0 && /^\s*$/.test(hostDoc.lines[end - 1])) end--;
        CM.splice(hostDoc, end, hostDoc.lines.length - end, [''].concat([kind + ':'], additions));
      }
    });

    /* --- the joined .env text, if any incoming stack carried one --- */

    var envText = host.envText != null ? host.envText : null;
    var joined = null;
    incDescs.forEach(function (incDesc) {
      var j = null;
      exam.findings.forEach(function (f) {
        if (f.kind === 'settings-join' && f.stack === incDesc.name) j = f;
      });
      if (j) joined = j.facts.joinedLines;
    });
    if (joined) {
      envText = joined.map(function (l) {
        if (l.type === 'blank') return '';
        if (l.type === 'comment') return l.text;
        // findSettingsJoin() writes an explanatory note into `comment` with
        // no leading '#' of its own (it is meant to travel as a "why", not
        // as ready-to-write text) — without one here it would run straight
        // into the value with nothing to mark it as a comment, silently
        // corrupting the setting it was meant to explain.
        return l.name + '=' + l.value + (l.comment ? '  # ' + l.comment : '');
      }).join('\n') + '\n';
    }

    return {
      composeText: hostDoc.bom + hostDoc.lines.join(hostDoc.eol || '\n'),
      envText: envText,
      historyNote: 'Merged in: ' + incomingList.map(function (s) { return s.name; }).join(', ') + ' (' + date + ')',
      findings: exam.findings,
      refusals: refusals
    };
  }

  var API = {
    descriptorFromText: descriptorFromText,
    buildMergedText: buildMergedText,
    // Exposed for tests — the block-splicing primitive on its own.
    computeBlocks: computeBlocks
  };

  if (typeof window !== 'undefined') window.StaxxMergeWrite = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
