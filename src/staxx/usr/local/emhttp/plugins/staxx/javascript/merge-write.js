/* StaXX — turning a decided merge into real file text (PLAN_155 "the rebuild").
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * merge-examine.js works out WHAT a merge must do; this file is the other
 * half — turning that into the actual bytes of a brand new THIRD stack,
 * written from N equal sources (there is no host any more; see PLAN_155's
 * "change of direction"). Rule 2 in CLAUDE.md is the whole reason this is a
 * separate pass: compose-model.js's own writers are built to edit one
 * already-open file one field at a time, and re-parsing every source into a
 * tree and printing a new one back out would quietly drop whatever an
 * author wrote that the tree does not model (comment placement, blank-line
 * rhythm, quoting style, anchors). So instead:
 *
 *   - every source's own compose text is parsed and edited IN PLACE — a
 *     rename, a rewired address, a re-pointed relative path — using
 *     compose-model.js's own tested writers wherever the edit touches more
 *     than one line at once (a rename has to follow every reference to the
 *     old name), and a narrow, targeted line rewrite everywhere else;
 *   - once a source's own edits are settled, its service blocks — and its
 *     declared volumes:/networks:/configs:/secrets: entries — are lifted
 *     out VERBATIM (comments, anchors, blank lines and all) and spliced
 *     into a freshly built skeleton, each block introduced by its own
 *     "# From <source>" comment;
 *   - nothing here re-serialises a whole file. The skeleton itself (the
 *     opening comment, the top-level keys) is the only text this module
 *     writes from scratch, and it is boilerplate that never existed before.
 *
 * A service's own raw span (its lead comment, its own lines, and the gap up
 * to the next entry) is found the same way compose-model.js's own tidy()
 * pass finds one — buildSpans() there is not exported, because tidy() also
 * needs the refusal machinery around REORDERING a scope, which this has no
 * need of: nothing here reorders anything inside a single source's own
 * file, it only appends whole blocks from several sources one after
 * another. computeBlocks() below is the same idea (a key's own lead
 * comment plus its trailing gap travels as one block) kept to the narrower
 * job this actually has.
 *
 * Same dual shape as merge-examine.js: `window.StaxxMergeWrite` in the
 * browser, `module.exports` under Node.
 */

(function () {
  'use strict';

  var CM = (typeof require === 'function') ? require('./compose-model.js') : (typeof window !== 'undefined' ? window.StaxxYaml : null);
  var ME = (typeof require === 'function') ? require('./merge-examine.js') : (typeof window !== 'undefined' ? window.StaxxMergeExamine : null);
  // The one place a source's full rel gets reduced to its LEAF before it
  // is written into any text or built into any Docker identifier — see
  // merge-examine.js's own leaf() for why this matters (a rel folded into
  // a carried volume's real name would point at storage that does not
  // exist). `stack` fields and `files[].from` are the only things that
  // keep the rel, since the server needs it to find the folder again.
  var leaf = ME.leaf;

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

  // A source's own file header — every line before its first top-level key,
  // blank lines between included (PLAN_179 P1). This is deliberately NOT
  // leadStart() above: leadStart() walks a KEY's own lead comment, which
  // stops the moment it hits a blank line, because a blank line really does
  // separate one key's comment from the next one up. A file's header note
  // is not attached to any key at all — it is only ever directly above
  // whichever key happens to be first — so a blank line before that key
  // must not stop it being read. Nothing can legitimately sit between line
  // 0 and the first top-level key of a valid compose file except comments
  // and blanks, so the slice is exact.
  function fileHeaderLines(doc) {
    if (!doc.root || doc.root.kind !== 'map' || !doc.root.keys.length) return [];
    return doc.lines.slice(0, doc.root.pairs[doc.root.keys[0]].start);
  }

  // A block's own leading comment lines (as computeBlocks() already
  // isolated them — this never re-derives anything, only splits a block
  // computeBlocks() built into "the comment on top" and "everything else").
  // Needed because x-unraid's field-by-field carry (below) only ever reads
  // the FIELDS inside x-unraid, never its own outer lead comment, which is
  // otherwise only carried as a side effect of concatenating the WHOLE
  // block — something only the first-ever x-unraid source's branch does
  // (PLAN_179 P1: a later source whose x-unraid follows some other key,
  // "version:" say, lost this comment outright).
  function leadingCommentLinesOf(block) {
    var idx = 0;
    while (idx < block.length && lineKind(block[idx]).kind === 'comment') idx++;
    return block.slice(0, idx);
  }

  // A map's direct children as whole blocks: a key's own lead comment (same
  // indent, directly above), its own lines, and the gap up to the next
  // key's OWN lead-inclusive start (so a blank line or a trailing note
  // between two entries travels with the one before it, never lost and
  // never duplicated). `starts` gives each block's own first DOC line —
  // the write pass needs it to work out where an edit it already applied
  // (in doc-line terms) lands once the block is pasted into the merged
  // text.
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
    var order = [], blocks = {}, contentEnds = {}, starts = {};
    for (i = 0; i < spans.length; i++) {
      var to = i + 1 < spans.length ? spans[i + 1].start : mapNode.end;
      order.push(spans[i].key);
      blocks[spans[i].key] = doc.lines.slice(spans[i].start, to);
      contentEnds[spans[i].key] = spans[i].contentEnd - spans[i].start;
      starts[spans[i].key] = spans[i].start;
    }
    return { order: order, blocks: blocks, contentEnds: contentEnds, starts: starts };
  }

  // A block's own top-level key line, renamed in place — used where two
  // sources both carry a plain "data: {}"-shaped volume under the same
  // key, so the merged file's SECOND one needs its own key text changed
  // (declRename/CM.renameDeclared already handles a shorthand-clash's own
  // rename on the doc itself; this covers the storage-carry case, which
  // never touches the source doc because the merged key only has to be
  // unique within the assembled file, not within the source it came from).
  function renameBlockKeyLine(body, newKey) {
    var idx = 0;
    while (idx < body.length && lineKind(body[idx]).kind !== 'other') idx++;
    var m = /^(\s*)([^:\s][^:]*):(.*)$/.exec(body[idx]);
    if (m) body[idx] = m[1] + newKey + ':' + m[3];
    return idx;
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
   *
   * "The falsified-comment fault" (PLAN_155): when one of these rewrites a
   * VALUE line, and the line directly above it (same indent) is a comment,
   * that comment is struck rather than carried across describing something
   * that is no longer true. stripCommentAbove() is the one place that
   * happens, so every rewriter below shares it.
   *
   * PLAN_169 F15 — a lead comment is often more than one line ("A merge
   * that reaches this database... / ...must rewrite this to a / service
   * name instead." split across two), and striking only the one line
   * directly above the rewritten value left half a sentence behind,
   * describing nothing. Struck lines are found by walking upward while the
   * indent keeps matching (the same rule leadStart() above already applies
   * to a whole block's own lead comment), not just checking the one line
   * above, and every line in that run is removed together.
   *
   * PLAN_178 F2 — striking the whole run regardless of what it said lost
   * comments that stay true after the rewrite ("# Redis -!S" above a
   * REDIS_CON address, rewritten only to point at the new host, is still
   * a correct label for what the line holds). A comment is now struck only
   * when its text actually names the value being replaced — every caller
   * passes the exact old text it is about to overwrite, the same way it
   * already does for the rewrite itself. When the struck run carried a
   * sanitise marker (`-!S`, `-!R`, or any other `-!<letter>`; see
   * docs/x-unraid-schema.md "Notes and markers"), that marker is not lost
   * with the prose around it: it is written back as a comment line of its
   * own, same indent, directly above the rewritten line — otherwise a
   * value that was blanked in Sanitise mode would quietly stop being
   * blanked once its label was struck.
   * ===================================================================== */

  // A port is matched only as a whole number — "637" must not strike a
  // comment that merely contains "16370" — everything else (an address, a
  // host, a label name, a path) is a plain substring test.
  function commentNamesValue(text, value) {
    if (value === null || value === undefined || value === '') return false;
    value = String(value);
    if (/^\d+$/.test(value)) {
      return new RegExp('(^|[^0-9])' + value + '(?=[^0-9]|$)').test(text);
    }
    return text.indexOf(value) !== -1;
  }

  // Callers pass every shape of "the old text" a comment might name: the
  // whole old address, and — since a comment can just as easily call out
  // only the host or only the port ("# Redis" said nothing, but a fixture
  // could say "# port 6379") — its host and port halves too, when the value
  // looks like "host:port".
  function commentNamesAnyValue(text, values) {
    for (var i = 0; i < values.length; i++) {
      var v = values[i];
      if (v === null || v === undefined) continue;
      if (commentNamesValue(text, v)) return true;
      var hp = /^([^:\s]+):(\d+)$/.exec(String(v));
      if (hp && (commentNamesValue(text, hp[1]) || commentNamesValue(text, hp[2]))) return true;
    }
    return false;
  }

  // Every "-!<LETTER>" sanitise marker in a comment run, first-seen order,
  // each kept once — a run naming "-!S -!R" twice over two lines still
  // yields one marker line with both, not a repeat.
  function extractMarkers(text) {
    var re = /-![A-Z]\b/g, out = [], m;
    while ((m = re.exec(text))) { if (out.indexOf(m[0]) === -1) out.push(m[0]); }
    return out;
  }

  // Returns { lines, shift } on a strike (lines is the exact struck comment
  // text, for the source-pane highlight; shift is how many lines the doc
  // actually lost — one less than lines.length when a marker line was
  // written back in their place) or null when nothing was struck, either
  // because there was no comment above or because it named none of `values`.
  function stripCommentAbove(doc, lineIdx, values) {
    if (lineIdx <= 0) return null;
    var target = lineKind(doc.lines[lineIdx]);
    var above = lineKind(doc.lines[lineIdx - 1]);
    if (above.kind !== 'comment' || above.indent !== target.indent) return null;
    var start = leadStart(doc.lines, lineIdx, target.indent);
    var texts = doc.lines.slice(start, lineIdx);
    if (!commentNamesAnyValue(texts.join('\n'), values || [])) return null;
    var markers = extractMarkers(texts.join('\n'));
    var indent = /^[ \t]*/.exec(doc.lines[lineIdx - 1])[0];
    var replacement = markers.length ? [indent + '# ' + markers.join(' ')] : [];
    CM.splice(doc, start, texts.length, replacement);
    return { lines: texts, shift: texts.length - replacement.length };
  }

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

  // A service's own x-unraid.icon line, rewritten to its new per-service
  // file name (PLAN_155 C17 — "icons are not a question": every carried
  // service that points at a file inside its own source's .staxx/ gets that
  // file under its own name, whether or not anything actually clashed).
  // Scoped to the service's own line range so two services in the same
  // source that share one icon file each get their own line rewritten,
  // rather than a whole-doc search landing on the first one twice.
  function rewriteServiceIcon(doc, serviceKey, oldRef, newRef) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return null;
    for (var i = p.start; i < p.end; i++) {
      var m = /^\s*icon:\s*(.*)$/.exec(doc.lines[i]);
      if (!m) continue;
      var cm = /^([^#]*?)(\s*#.*)?$/.exec(m[1]);
      var val = cm[1].replace(/^['"]|['"]$/g, '').trim();
      if (val !== oldRef) continue;
      doc.lines[i] = rewriteScalarValue(doc.lines[i], newRef);
      return { line: i, text: doc.lines[i] };
    }
    return null;
  }

  // PLAN_155 F17 — a service's x-unraid.webui address named the port a
  // port-clash finding just moved it off; left alone, the merged stack's
  // "open web page" button would point at a port the container no longer
  // publishes. Modelled on rewriteServiceIcon() just above: scoped to the
  // one service's own line range, only the old port's own digits are
  // replaced (not the whole value), and only when they are followed by a
  // path, the end of the value, or a query string — so "8091" is never
  // mistaken for the leading digits of "80910" or similar.
  function rewriteServiceWebui(doc, serviceKey, oldPort, newPort) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return null;
    var re = new RegExp(':' + oldPort + '(?=[/?]|$)');
    for (var i = p.start; i < p.end; i++) {
      var m = /^\s*webui:\s*(.*)$/.exec(doc.lines[i]);
      if (!m) continue;
      var cm = /^([^#]*?)(\s*#.*)?$/.exec(m[1]);
      var val = cm[1].replace(/^['"]|['"]$/g, '').trim();
      if (!re.test(val)) continue;
      var newVal = val.replace(re, ':' + newPort);
      doc.lines[i] = rewriteScalarValue(doc.lines[i], newVal);
      return { line: i, text: doc.lines[i] };
    }
    return null;
  }

  // F2/F9/F11 — a service's own Traefik labels, a proxy name renamed
  // everywhere it appears: in every label KEY that names it directly
  // (`traefik.http.<section>.<oldName>.*`, whichever of "rule"/"service"/
  // "middlewares"/... follows), and — only when the renamed name is itself
  // a "services" or "middlewares" object, since only those are ever named
  // as someone ELSE's target — in any label VALUE that points at it (a
  // router's own `.service=<name>`/`.middlewares=<name>`). Text-based, not
  // YAML-shape-based, so it rewrites a label whether it was written as a
  // list item ("- traefik...=...") or a map entry ("traefik...: ..."), the
  // same way locateLabelLine() (merge-examine.js) finds it either way.
  // ${COMPOSE_PROJECT_NAME} is left exactly as written — only the literal
  // name segment beside it changes, matching PLAN_169's own decision.
  //
  // Strikes a falsified lead comment on every line it actually changes
  // (stripCommentAbove(), same rule every other rewrite in this file
  // follows) — since a strike can now remove more than one line (F15,
  // whichever comment lines make up the whole run above), `end` and the
  // loop cursor `i` are both stepped back by however many lines that strike
  // actually removed, to stay aligned with the shifted array.
  // Returns one { line, text, struckComment } per line actually changed;
  // struckComment is the array of struck lines' own text, or null.
  function rewriteServiceLabels(doc, serviceKey, section, oldName, newName) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return [];
    var out = [];
    var oldEsc = escapeRegExp(oldName);
    var keyRe = new RegExp('(traefik\\.http\\.' + section + '\\.)' + oldEsc + '(?=\\.)');
    var valueRe = new RegExp('(\\.(?:service|middlewares)[:=]\\s*["\']?)' + oldEsc + '(["\']?\\s*(?:#.*)?)$');
    var end = p.end;
    for (var i = p.start; i < end; i++) {
      var line = doc.lines[i];
      if (line.indexOf('traefik.http.') === -1) continue;
      var next = line.replace(keyRe, '$1' + newName);
      if ((section === 'services' || section === 'middlewares') && valueRe.test(next)) {
        next = next.replace(valueRe, '$1' + newName + '$2');
      }
      if (next === line) continue;
      var struck = stripCommentAbove(doc, i, [oldName]);
      var shift = struck ? struck.shift : 0;
      var idx = i - shift;
      doc.lines[idx] = next;
      out.push({ line: idx, text: next, struckComment: struck ? struck.lines : null });
      if (shift) { end -= shift; i -= shift; }
    }
    return out;
  }

  // The same search rewriteEnvAddressTracked() below uses, without editing
  // anything — a declined rewire needs to point its change record's marker
  // at the exact line that stays, not at a line it just rewrote.
  function findEnvAddressLine(doc, serviceKey, envVar, addr) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return null;
    for (var i = p.start; i < p.end; i++) {
      var line = doc.lines[i];
      if (line.indexOf(envVar) === -1 || line.indexOf(addr) === -1) continue;
      return i;
    }
    return null;
  }

  // Substring replace inside whichever line names both the env var and the
  // old address — safe because the examiner already matched that exact
  // "host:port" text on that exact variable, so there is nothing to
  // re-derive here, only to apply. Strikes a falsified lead comment first.
  // Returns { line, struckComment } on success, null when nothing matched.
  function rewriteEnvAddressTracked(doc, serviceKey, envVar, oldAddr, newAddr) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return null;
    for (var i = p.start; i < p.end; i++) {
      var line = doc.lines[i];
      if (line.indexOf(envVar) === -1 || line.indexOf(oldAddr) === -1) continue;
      var struck = stripCommentAbove(doc, i, [oldAddr]);
      var idx = i - (struck ? struck.shift : 0);
      doc.lines[idx] = doc.lines[idx].split(oldAddr).join(newAddr);
      return { line: idx, struckComment: struck ? struck.lines : null, text: doc.lines[idx] };
    }
    return null;
  }

  // PLAN_179 P2 — the line the network_mode rewrite below both finds and
  // (via rewriteNetworkModeTracked()) edits: a service's own network_mode:
  // scalar, matched by its exact current value (never a substring, unlike
  // an address — "container:foo" must not match "container:foobar").
  function findNetworkModeLine(doc, serviceKey, value) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return null;
    for (var i = p.start; i < p.end; i++) {
      var m = /^\s*network_mode:\s*(.*)$/.exec(doc.lines[i]);
      if (!m) continue;
      var cm = /^([^#]*?)(\s*#.*)?$/.exec(m[1]);
      var val = cm[1].replace(/^['"]|['"]$/g, '').trim();
      if (val === value) return i;
    }
    return null;
  }

  function rewriteNetworkModeTracked(doc, serviceKey, oldValue, newValue) {
    var lineIdx = findNetworkModeLine(doc, serviceKey, oldValue);
    if (lineIdx === null) return null;
    var struck = stripCommentAbove(doc, lineIdx, [oldValue]);
    var idx = lineIdx - (struck ? struck.shift : 0);
    doc.lines[idx] = rewriteScalarValue(doc.lines[idx], newValue);
    return { line: idx, struckComment: struck ? struck.lines : null, text: doc.lines[idx] };
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

  // Same range findKeyChildRange() already gives, plus the key LINE itself
  // (findKeyChildRange only ever returns its children) — removePortPublishTracked
  // needs it to rewrite "ports:" to "ports: []" when the last entry goes,
  // and to give the change record a line to point at either way.
  function findPortsRange(doc, serviceKey) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[serviceKey];
    if (!p) return null;
    for (var i = p.start; i < p.end; i++) {
      if (!/^(\s*)ports:\s*(#.*)?$/.test(doc.lines[i])) continue;
      var range = findKeyChildRange(doc, p.start, p.end, /^(\s*)ports:\s*(#.*)?$/);
      if (!range) return null;
      range.keyLine = i;
      return range;
    }
    return null;
  }

  function parsePortListLine(line) {
    var m = /^(\s*-\s*)(['"]?)([^'"]*)\2\s*$/.exec(line);
    if (!m) return null;
    return { prefix: m[1], quote: m[2], body: m[3] };
  }

  // F3 — a thin alias for merge-examine.js's own parsePortSpec(), so a
  // "ports:" list line is read the same way here (locating/rewriting one
  // entry) as merge-examine.js reads it (findPortClashes()) and as
  // splitPortEntry() below reads it (building a source descriptor). One
  // parser, kept in ME so it is never accidentally forked into two.
  function parsePortBody(body) { return ME.parsePortSpec(body); }

  // The bare lookup rewritePortHost() (below) uses — split out so a decline
  // can point a change record's marker at the SAME line without rewriting
  // it (a decline leaves the port exactly as the author wrote it).
  function findPortLine(doc, serviceKey, hostPort) {
    var range = findPortsRange(doc, serviceKey);
    if (!range) return null;
    for (var i = range.start; i < range.end; i++) {
      var parsed = parsePortListLine(doc.lines[i]);
      if (!parsed) continue;
      var pb = parsePortBody(parsed.body);
      if (pb.host === String(hostPort)) return i;
    }
    return null;
  }

  // Returns { line, text } (the SAME shape rewritePathOccurrenceTracked() and
  // friends use) so the port-clash change record below can be resolved by
  // marker text like every other rewrite, rather than by a bare boolean that
  // left this one kind of change with no line to point a mark at at all.
  function rewritePortHost(doc, serviceKey, oldHostPort, newHostPort) {
    var i = findPortLine(doc, serviceKey, oldHostPort);
    if (i === null) return null;
    var parsed = parsePortListLine(doc.lines[i]);
    var pb = parsePortBody(parsed.body);
    // F3 — an address prefix (an IPv4/hostname, or a bracketed IPv6
    // literal) travels with the entry unchanged; only the HOST port moves.
    doc.lines[i] = parsed.prefix + parsed.quote + (pb.address ? pb.address + ':' : '') +
      newHostPort + ':' + pb.container + pb.protocolSuffix + parsed.quote;
    return { line: i, text: doc.lines[i] };
  }

  // Drops the whole ports: entry — not a rewrite to a bare container-port
  // form, which compose still publishes on a random host port. Strikes a
  // falsified lead comment first, same as the address rewrite above.
  //
  // When the entry removed was the ONLY one, the "ports:" key itself is
  // rewritten to "ports: []" here — still a real line the marker-matching
  // in buildMergedText() can find in the finished text — but it is never
  // what actually reaches the written file: buildMergedText()'s own
  // removal pass (see its own comment) strikes that line back out once
  // every change has a resolved position, and the change record it built
  // from `emptied: true` carries the struck text as `removedText` for the
  // preview instead. "ports: []" only ever exists here as the marker this
  // rewrite leaves behind for that later pass to find and remove; it is
  // deliberately not left in the file, since a person who closed a port on
  // purpose would not recognise "published nothing" written out that way.
  //
  // Returns { struckComment, line, emptied } on success (line is this
  // doc's own "ports:" key line, AFTER the edit — stable either way, since
  // the key line always sits above whatever it introduces and removing
  // children below it never shifts its own index), or null when nothing
  // matched.
  function removePortPublishTracked(doc, serviceKey, hostPort) {
    var range = findPortsRange(doc, serviceKey);
    if (!range) return null;
    for (var i = range.start; i < range.end; i++) {
      var parsed = parsePortListLine(doc.lines[i]);
      if (!parsed) continue;
      var pb = parsePortBody(parsed.body);
      if (pb.host !== String(hostPort)) continue;

      var remaining = false;
      for (var k = range.start; k < range.end; k++) {
        if (k !== i && parsePortListLine(doc.lines[k])) { remaining = true; break; }
      }

      var struck = stripCommentAbove(doc, i, [hostPort]);
      var idx = i - (struck ? struck.shift : 0);
      CM.splice(doc, idx, 1, []);

      if (!remaining) {
        var pad = /^(\s*)/.exec(doc.lines[range.keyLine])[1];
        doc.lines[range.keyLine] = pad + 'ports: []';
      }
      return { struckComment: struck ? struck.lines : null, line: range.keyLine, emptied: !remaining };
    }
    return null;
  }

  function escapeRegExp(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  // A companion file's rename followed through into every reference to its
  // OLD relative path inside this same source's own service lines — fault
  // 1/3's other half: renaming the file on disk is no good if a bind mount
  // or an env_file entry is left naming what is no longer there. Handles
  // both the "./name" form (bind mounts) and the bare form env_file also
  // accepts, each only where it looks like a whole path token rather than
  // a substring of something else.
  function rewriteFileReferences(doc, oldPath, newPath) {
    var esc = escapeRegExp(oldPath);
    var reSlash = new RegExp('(\\./)' + esc + '(?=["\':\\s]|$)', 'g');
    var reBare = new RegExp('(^\\s*-\\s*["\']?|:\\s*["\']?)' + esc + '(?=["\':\\s]|$)', 'g');
    var changed = false;
    for (var i = 0; i < doc.lines.length; i++) {
      var line = doc.lines[i];
      var next = line.replace(reSlash, '$1' + newPath).replace(reBare, '$1' + newPath);
      if (next !== line) { doc.lines[i] = next; changed = true; }
    }
    return changed;
  }

  // A settings-join rename (automatic suffix, or a name the wizard's own
  // "choose a name" box gave) followed through into this same source's own
  // compose lines — a value renamed in the joined .env is no good to a
  // service that still reaches for it as "${OLD}", bare "$OLD", or one of
  // compose's default/error forms ("${OLD:-x}", "${OLD-x}", "${OLD:?x}",
  // "${OLD?x}", "${OLD:+x}", "${OLD+x}"). Found on the box (T5): a source
  // merged with a settings clash left ${TAG:-1.25} untouched while the
  // wizard's own rename went to TAG_B in the .env, so the service silently
  // read the OTHER source's TAG instead of its own default. Only the name
  // right after "${" is rewritten — the lookahead requires it be followed
  // by "}" or one of those operators without consuming it, so the
  // default/message text itself is left exactly as written. A lone
  // lookbehind on both forms skips "$${OLD}"/"$$OLD" — compose's own way of
  // writing a literal dollar sign, never a reference to rewrite.
  function rewriteEnvVarReferences(doc, oldName, newName) {
    var esc = escapeRegExp(oldName);
    var reBraced = new RegExp('(?<!\\$)\\$\\{' + esc + '(?=[}:?+-])', 'g');
    var reBare = new RegExp('(?<!\\$)\\$' + esc + '(?![A-Za-z0-9_])', 'g');
    var changed = false;
    for (var i = 0; i < doc.lines.length; i++) {
      var line = doc.lines[i];
      var next = line.replace(reBraced, '${' + newName).replace(reBare, '$' + newName);
      if (next !== line) { doc.lines[i] = next; changed = true; }
    }
    return changed;
  }

  // A relative path occurrence — a bind mount, an env_file entry, a
  // build.context, an extends.file, or a top-level secrets:/configs:
  // file: — rewritten to keep pointing at the same real place once the new
  // stack sits at a different depth. Scoped to the FIRST line containing
  // the exact old path text, which is safe because the examiner already
  // matched that exact string; ambiguity between two identical relative
  // paths in the same file is rare enough, and harmless enough (both would
  // want the same rewrite), not to be worth a stricter search here.
  function rewritePathOccurrenceTracked(doc, oldPath, newPath) {
    for (var i = 0; i < doc.lines.length; i++) {
      if (doc.lines[i].indexOf(oldPath) === -1) continue;
      var struck = stripCommentAbove(doc, i, [oldPath]);
      var idx = i - (struck ? struck.shift : 0);
      doc.lines[idx] = doc.lines[idx].split(oldPath).join(newPath);
      return { line: idx, struckComment: struck ? struck.lines : null, text: doc.lines[idx] };
    }
    return null;
  }

  /* =====================================================================
   * A stack has no icon or description of its own — only a service does
   * (see CLAUDE.md). So a merge never synthesises a stack-level x-unraid
   * block: nothing here reads or writes one. Each service's own x-unraid
   * block — including its own icon — is carried verbatim by the per-block
   * copy further down, same as every other line in that service.
   * ===================================================================== */

  /* =====================================================================
   * Companion files — turning the merge-files replies and the wizard's own
   * decisions into the actual copy list, and refusing to propose a `to`
   * that would silently pair with the new stack's own compose file (fault
   * 1: an override file coming alive — there is no such thing as an inert
   * one, since any of the four names Compose recognises is live wherever
   * it sits, PLAN_155 C5).
   * ===================================================================== */

  var DANGEROUS_COMPOSE_NAMES = {
    'compose.yaml': 1, 'compose.yml': 1, 'docker-compose.yaml': 1, 'docker-compose.yml': 1,
    'compose.override.yaml': 1, 'compose.override.yml': 1,
    'docker-compose.override.yaml': 1, 'docker-compose.override.yml': 1
  };

  function guardDangerousName(to, sourceLeaf) {
    if (!to) return to;
    var parts = to.split('/');
    var base = parts.pop();
    if (!DANGEROUS_COMPOSE_NAMES[base]) return to;
    parts.push(base + '.from-' + sourceLeaf);
    return parts.join('/');
  }

  // A stack-level x-unraid key named the way a person reads it on a "not carried" card; the raw key
  // ("imported", "overview") means nothing to someone who never opened the file. An unknown key is
  // shown as written, in quotes.
  var XU_LABELS = {
    overview: 'description', imported: 'record of where it came from', category: 'category',
    project: 'project link', support: 'support link', readme: 'readme link', author: 'author',
    sections: 'folded sections', update: 'update settings', links: 'list of linked services'
  };
  function xuLabel(sk) { return XU_LABELS.hasOwnProperty(sk) ? XU_LABELS[sk] : '"' + sk + '"'; }

  function decisionValue(decisions, f) {
    var stored = decisions[f.key];
    if (f.severity === 'wiring') {
      var c = f.choices && f.choices[0];
      var defaultTick = c ? !!c.ticked : false;
      return stored === undefined ? defaultTick : !!stored;
    }
    var rec = null;
    (f.choices || []).forEach(function (c) { if (c.recommended) rec = c.id; });
    return stored === undefined ? rec : stored;
  }

  // The line in the SOURCE's own original text a finding's own `lines`
  // array names — the source-pane mark's other half, alongside `line` (the
  // position in the merged/env text). `idx` picks which of `lines` when a
  // finding names more than one (a split _HOST/_PORT pair carries two);
  // defaults to the first, which is the one the change record's own
  // `marker`/anchor is built from above.
  function sourceLineFor(f, idx) {
    var entry = f.lines && f.lines[idx || 0];
    return entry ? entry.line : null;
  }

  // A mandatory rename — two sources both used the same service, container
  // or declared name, so one of them is disambiguated automatically; there
  // is no choice to tick, unlike a wiring finding. But CM.renameService()
  // and CM.renameDeclared() each rewrite the key line PLUS every reference
  // to it in one call (a depends_on:, a volume mount, a networks: entry),
  // and CLAUDE.md rule 2 does not carve out an exception for an edit that
  // was forced rather than chosen: every line it actually touched gets its
  // own record. Neither writer ever inserts or removes a line, only
  // rewrites one in place, so a plain positional diff finds exactly which
  // lines changed — there is no need to re-derive what compose-model.js's
  // own reference-collector already worked out.
  function pushAutoRenameChanges(changes, f, before, after, title, reason) {
    var part = 0;
    for (var i = 0; i < after.length; i++) {
      if (before[i] === after[i]) continue;
      changes.push({
        key: f.key, part: part++, stack: f.stack, sourceLine: i, marker: after[i],
        title: title, reason: reason, struckComment: null,
        // "Every change must be answered" (PLAN_155 C17) needs a *Decline*
        // button on everything painted — except here: two sources' same
        // name really would collide in the merged file, so there is no
        // "leave it as it was" to offer. The browser hides the button when
        // it sees this rather than offering one that can only refuse.
        cannotLeave: true
      });
    }
  }

  // C2's own wording: when a placeholder resolved to something other than
  // its own text (the settings file actually set it), the reason says both
  // — "Was ${DB_HOST}, which the settings file set to 192.0.2.88" —
  // rather than naming an address nobody wrote. A literal value (no .env
  // involved, `written` and `resolved` are the same text) reads exactly as
  // it did before this fix.
  function describeWas(written, resolved) {
    return written === resolved ? 'Was ' + written : 'Was ' + written + ', which the settings file set to ' + resolved;
  }

  function freePortFrom(stored) {
    if (stored && typeof stored === 'object') return stored.port ? Number(stored.port) : null;
    if (typeof stored === 'number') return stored;
    if (typeof stored === 'string' && /^\d+$/.test(stored)) return Number(stored);
    return null;
  }

  // Every carried service whose x-unraid.icon names a file sitting directly
  // inside ITS OWN source's .staxx/ (never a clash candidate — see
  // findCompanionFindings() in merge-examine.js) gets that file copied
  // under its own name in the merged stack: "icon-<service>.<ext>", using
  // the service's name IN THE MERGED FILE, after any clash rename, since
  // that is the name the icon has to keep matching from here on. A service
  // declaring no icon gets nothing; two services sharing one source file
  // each get their own copy (PLAN_155 C17 — "icons are not a question").
  //
  // PLAN_169 F16 — a source can declare an icon that was never actually
  // shipped with it (the file deleted, or — as in the merge-walk-six
  // fixture, kept on purpose as trap 14 — never there in the first place);
  // planning a copy for it anyway meant step 4 showed it as "renamed for
  // its service" right up until the last click, where Merge.php's own
  // refusal ("... is not a file ... can offer to this merge") stopped the
  // whole merge with nothing written. This only ever plans a copy for a
  // file the source's OWN file listing (`d.files`, the same list planFiles()
  // below reads) actually holds; a missing one is appended to `missing`
  // instead (when the caller passes an array to collect them) and its
  // icon: line is never touched by the rewrite pass further down, so it is
  // carried exactly as written.
  function planIconCopies(descs, plan, missing) {
    var out = [];
    (descs || []).forEach(function (d) {
      var services = (d.compose && d.compose.services) || {};
      var files = d.files || [];
      Object.keys(services).forEach(function (svcName) {
        var icon = services[svcName].x_unraid && services[svcName].x_unraid.icon;
        if (!icon) return;
        var m = /^(?:\.\/)?\.staxx\/([^\/]+)$/.exec(String(icon).trim());
        if (!m) return;   // not a file inside this source's own .staxx/
        var path = '.staxx/' + m[1];
        var finalSvc = plan.serviceRenames[d.name + '/' + svcName] || svcName;
        var present = files.some(function (e) { return !e.dir && !e.outside && e.path === path; });
        if (!present) {
          if (missing) missing.push({ source: d.name, service: svcName, finalService: finalSvc, ref: String(icon).trim() });
          return;
        }
        var ext = (/\.([^./]+)$/.exec(m[1]) || [null, ''])[1];
        out.push({
          source: d.name, path: path, service: svcName, finalService: finalSvc,
          oldRef: String(icon).trim(),
          newRef: './.staxx/icon-' + finalSvc + (ext ? '.' + ext : '')
        });
      });
    });
    return out;
  }

  function planFiles(exam, decisions, iconCopies) {
    var perSourceTo = {};   // "source|path" -> final `to`, null meaning leave behind
    var clashesByPath = {};
    exam.findings.forEach(function (f) {
      if (f.kind !== 'file-clash') return;
      clashesByPath[f.facts.path] = f;
    });

    // Icon files handled by planIconCopies() above are copied explicitly,
    // one entry per referencing service — never by the generic per-file
    // logic below, which only ever keeps one copy under one name.
    var iconHandled = {};   // "source|path" -> true
    (iconCopies || []).forEach(function (ic) { iconHandled[ic.source + '|' + ic.path] = true; });

    var files = [];
    exam.sourcesByName = exam.sourcesByName || {};

    (exam.sources || []).forEach(function (s) {
      var entries = s.files || [];
      var toByPath = {};   // this source's own path -> final `to` (null = left behind)

      entries.forEach(function (entry) {
        if (entry.outside) { toByPath[entry.path] = null; return; }   // never copied
        if (iconHandled[s.name + '|' + entry.path]) return;   // handled below instead

        var to = entry.path;
        var clash = clashesByPath[entry.path];
        if (clash) {
          var decision = decisionValue(decisions, clash);
          if (decision === 'leave-behind') {
            to = null;
          } else if (decision === 'keep-one') {
            to = (clash.facts.sources[0] === s.name) ? entry.path : null;
          } else {
            var renamed = clash.facts.renameTo.filter(function (r) { return r.stack === s.name; })[0];
            to = renamed ? renamed.to : entry.path;
          }
        } else {
          // Not clashing — only an unreferenced non-dir file gets its own
          // decision; everything else is copied under its own name.
          var unref = exam.findings.filter(function (f) {
            return f.kind === 'unreferenced' && f.stack === s.name && f.facts.path === entry.path;
          })[0];
          if (unref && decisionValue(decisions, unref) === 'leave-behind') to = null;
        }

        if (to !== null) to = guardDangerousName(to, leaf(s.name));
        toByPath[entry.path] = to;
      });

      // The plan names files only — a folder is implied by its own
      // children's `to` paths, and the server creates it, so a folder entry
      // here would either duplicate a child sent under a different name (a
      // rename) or resurrect one the person chose to leave behind
      // (PLAN_155 C13). The one exception is a folder left with no files at
      // all once decisions are applied — empty in the source, or every
      // child left behind — which is sent as its own explicit entry so a
      // bind-mounted empty folder still exists in the new stack.
      entries.forEach(function (entry) {
        if (entry.outside) return;
        if (iconHandled[s.name + '|' + entry.path]) return;   // its own explicit entries are added below
        if (entry.dir) {
          var prefix = entry.path + '/';
          var hasKeptChild = entries.some(function (e) {
            return !e.dir && e.path.indexOf(prefix) === 0 && toByPath[e.path] !== null;
          });
          if (hasKeptChild) return;   // implied by the child(ren) it still has
        }
        files.push({ from: s.name, path: entry.path, to: toByPath[entry.path] });
      });
    });

    // Icons are not a question: one explicit copy per referencing service,
    // however many there are, added last so they never compete with the
    // generic per-file decisions above.
    (iconCopies || []).forEach(function (ic) {
      files.push({ from: ic.source, path: ic.path, to: ic.newRef.replace(/^\.\//, '') });
    });

    return files;
  }

  /* =====================================================================
   * Building a descriptor (merge-examine.js's input shape) from raw text.
   * `files` is the merge-files reply's own `files` array (not a bare list
   * of names) — see this file's own header and merge-examine.js's own for
   * the shape. `extra` carries the two facts a raw file cannot tell you by
   * itself: `filesLarge` (that same reply's `large`), `depth` (0 loose,
   * 1 in a folder — where this source sits TODAY, needed for the
   * depth-path findings; defaults to 0 when the caller does not know or
   * care, which only matters when the new stack's own depth differs), and
   * `rel` (PLAN_155 C4 — this source's own full rel, e.g.
   * "DEV-TESTING/t155-web"; carried so a "../" path is resolved by FOLDER
   * rather than by depth — two folders at the same depth are still two
   * different places. Omitted, merge-examine.js falls back to `depth`.)
   * ===================================================================== */

  // A bracket/brace-style value ("profiles: [\"tools\"]", "command: [a, b]")
  // parses in compose-model.js to an opaque 'flow' node with no `.value` at
  // all — toPlain() used to fall through to `undefined` for it, which is
  // what let a dormant service's profiles: ["tools"] read as "no profiles",
  // i.e. always running (Adrian's real merge, 2026-09-17: a live web port
  // was moved instead of the actually-dormant service behind it). Lists reuse
  // compose-model.js's own parseFlowList (one splitter, not two); a flow MAP
  // has no such helper there, so it gets its own tiny comma/colon split here.
  function parseFlow(raw) {
    var idx = raw.indexOf(':');
    var text = idx < 0 ? raw : raw.slice(idx + 1);
    // Strip a trailing "# comment" that sits outside any quoted section.
    var q = null, cut = -1;
    for (var i = 0; i < text.length; i++) {
      var c = text.charAt(i);
      if (q) { if (c === q) q = null; continue; }
      if (c === '"' || c === "'") { q = c; continue; }
      if (c === '#') { cut = i; break; }
    }
    if (cut >= 0) text = text.slice(0, cut);
    text = text.replace(/^\s+|\s+$/g, '');

    if (text.charAt(0) === '[') {
      var list = CM.parseFlowList((idx < 0 ? 'x: ' : raw.slice(0, idx + 1)) + text);
      return list || [];
    }
    if (text.charAt(0) === '{' && text.charAt(text.length - 1) === '}') {
      var inner = text.slice(1, -1);
      var parts = inner.split(',');
      var o = {};
      parts.forEach(function (part) {
        var t = part.replace(/^\s+|\s+$/g, '');
        if (!t) return;
        var ci = t.indexOf(':');
        var k = (ci < 0 ? t : t.slice(0, ci)).replace(/^\s+|\s+$/g, '').replace(/^['"]|['"]$/g, '');
        var v = (ci < 0 ? '' : t.slice(ci + 1)).replace(/^\s+|\s+$/g, '').replace(/^['"]|['"]$/g, '');
        o[k] = v;
      });
      return o;
    }
    return text;
  }

  function toPlain(node) {
    if (!node) return undefined;
    if (node.kind === 'scalar') return node.value;
    if (node.kind === 'seq') return node.items.map(function (it) { return toPlain(it.value); });
    if (node.kind === 'map') {
      var o = {};
      node.keys.forEach(function (k) { o[k] = toPlain(node.pairs[k].value); });
      return o;
    }
    if (node.kind === 'opaque' && node.reason === 'flow') return parseFlow(node.raw);
    return undefined;
  }

  function asStringArray(v) {
    if (v == null) return [];
    return Array.isArray(v) ? v.slice() : [v];
  }

  // F3 — a source descriptor's own `ports:` entries are read by the exact
  // same parser findPortClashes() (merge-examine.js) uses to READ a port
  // clash, so the two can never disagree about what a "ports:" entry means.
  function splitPortEntry(entry) { return ME.parsePortSpec(entry); }

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

  // C2 (PLAN_156's dry run) — a compose value written as "${VAR}" is a
  // placeholder for whatever the source's OWN .env sets, and the wiring
  // passes need the address that is actually reached, not the literal
  // placeholder text. Supports "${VAR}", "${VAR:-default}" (default when
  // unset OR empty), "${VAR-default}" (default only when unset), and a bare
  // "$VAR"; "$$" is a literal dollar. An unset name with no default
  // resolves to '' — the same as compose itself. `via` collects every .env
  // name actually referenced, for anything downstream that wants to say
  // where a resolved value came from.
  function resolveEnvValue(raw, envMap, via) {
    var out = '', i = 0, text = String(raw);
    while (i < text.length) {
      if (text.charAt(i) === '$' && text.charAt(i + 1) === '$') { out += '$'; i += 2; continue; }
      if (text.charAt(i) === '$' && text.charAt(i + 1) === '{') {
        var end = text.indexOf('}', i + 2);
        if (end === -1) { out += text.slice(i); break; }
        var inner = text.slice(i + 2, end);
        var m = /^([A-Za-z_][A-Za-z0-9_]*)(:-|-)([\s\S]*)$/.exec(inner);
        var name = m ? m[1] : inner;
        var emptyFallsBack = m ? m[2] === ':-' : false;
        var def = m ? m[3] : '';
        var has = Object.prototype.hasOwnProperty.call(envMap, name);
        via.push(name);
        out += !has ? def : (emptyFallsBack && envMap[name] === '' ? def : envMap[name]);
        i = end + 1;
        continue;
      }
      if (text.charAt(i) === '$') {
        var bm = /^[A-Za-z_][A-Za-z0-9_]*/.exec(text.slice(i + 1));
        if (bm) {
          via.push(bm[0]);
          out += Object.prototype.hasOwnProperty.call(envMap, bm[0]) ? envMap[bm[0]] : '';
          i += 1 + bm[0].length;
          continue;
        }
      }
      out += text.charAt(i); i++;
    }
    return out;
  }

  /* =====================================================================
   * Applying a paired override file onto its main file's own document
   * (PLAN_155 C3) — compose's own override rules, and nothing more,
   * applied with the same narrow, line-preserving edits the rest of this
   * module uses (CLAUDE.md rule 2): every touch below either rewrites one
   * existing line in place or CM.splice()s in whole new lines: nothing
   * here re-parses the result into a plain object and prints it back out.
   *
   * These lists match PLAN_155 C3's own — appended, duplicates dropped;
   * `environment`/`labels` merge by key, the override's value winning,
   * whichever shape (list or map) the BASE already used kept; everything
   * else scalar-replaced.
   * ===================================================================== */

  var OVERRIDE_APPEND_LISTS = [
    'ports', 'expose', 'volumes', 'env_file', 'secrets', 'configs',
    'extra_hosts', 'dns', 'dns_search', 'tmpfs', 'cap_add', 'cap_drop',
    'devices', 'security_opt', 'sysctls', 'profiles', 'depends_on', 'networks'
  ];
  var OVERRIDE_MERGE_MAPS = ['environment', 'labels'];
  var OVERRIDE_DECL_KINDS = ['volumes', 'networks', 'configs', 'secrets'];

  function overrideScalarText(value) {
    if (Array.isArray(value)) return '[' + value.map(function (v) { return JSON.stringify(String(v)); }).join(', ') + ']';
    if (value && typeof value === 'object') return JSON.stringify(value);
    return String(value);
  }

  function overrideKVEntries(value) {
    var out = [];
    if (Array.isArray(value)) {
      value.forEach(function (kv) {
        var eq = String(kv).indexOf('=');
        if (eq >= 0) out.push([kv.slice(0, eq), kv.slice(eq + 1)]);
      });
    } else if (value && typeof value === 'object') {
      Object.keys(value).forEach(function (k) { out.push([k, value[k] == null ? '' : String(value[k])]); });
    }
    return out;
  }

  // Every record this pass produces shares this shape and wording — the
  // wizard needs no further reason than "this came from the override",
  // since that IS the reason: the pair behaves this way together today.
  function overrideChangeRecord(stackRel, sourceLine, markerText, overrideLeaf) {
    return {
      key: 'override|' + stackRel + '|' + sourceLine, stack: stackRel, sourceLine: sourceLine,
      marker: markerText, title: 'From the override file',
      reason: overrideLeaf + ' sets this beside the main file, so it is applied here and the ' +
        'merged stack keeps behaving as the pair did.',
      // "Leaving" one of these would mean the merged file behaves
      // differently than the real source pair already did — the override
      // is not optional today, so there is nothing to decline back to.
      cannotLeave: true
    };
  }

  // A list key the override adds to — appended, duplicates (exact text,
  // after formatting) dropped. Creates the key itself when the base has
  // none, as the service's own last key.
  function overrideAppendList(doc, svcName, key, values, stackRel, overrideLeaf, changes) {
    if (!values.length) return;
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p) return;

    var keyRe = new RegExp('^(\\s*)' + key + ':\\s*(#.*)?$');
    var range = findKeyChildRange(doc, p.start, p.end, keyRe);

    if (!range) {
      var keyIndent = new Array(p.indent + 3).join(' ');
      var itemIndent = new Array(p.indent + 5).join(' ');
      var lines = [keyIndent + key + ':'];
      values.forEach(function (v) { lines.push(itemIndent + '- ' + v); });
      var firstItemLine = p.end + 1;
      CM.splice(doc, p.end, 0, lines);
      values.forEach(function (v, idx) {
        changes.push(overrideChangeRecord(stackRel, firstItemLine + idx, itemIndent + '- ' + v, overrideLeaf));
      });
      return;
    }

    var existing = [];
    var itemIndent2 = null;
    for (var i = range.start; i < range.end; i++) {
      var m = /^(\s*)-\s*(.*)$/.exec(doc.lines[i]);
      if (!m) continue;
      existing.push(m[2].trim());
      if (itemIndent2 === null) itemIndent2 = m[1];
    }
    if (itemIndent2 === null) itemIndent2 = new Array(p.indent + 5).join(' ');

    var toAdd = values.filter(function (v) { return existing.indexOf(String(v)) === -1; });
    if (!toAdd.length) return;
    var newLines = toAdd.map(function (v) { return itemIndent2 + '- ' + v; });
    var at = range.end;
    CM.splice(doc, at, 0, newLines);
    toAdd.forEach(function (v, idx) {
      changes.push(overrideChangeRecord(stackRel, at + idx, itemIndent2 + '- ' + v, overrideLeaf));
    });
  }

  // `environment`/`labels` — merged by key, the override's value winning,
  // in whichever shape (list "- K=V" or map "K: V") the base already used;
  // a key the base lacks is appended in that same shape.
  function overrideMergeMap(doc, svcName, key, entries, stackRel, overrideLeaf, changes) {
    if (!entries.length) return;
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p) return;

    var keyRe = new RegExp('^(\\s*)' + key + ':\\s*(#.*)?$');
    var range = findKeyChildRange(doc, p.start, p.end, keyRe);

    if (!range) {
      var keyIndent = new Array(p.indent + 3).join(' ');
      var childIndent = new Array(p.indent + 5).join(' ');
      var lines = [keyIndent + key + ':'];
      entries.forEach(function (e) { lines.push(childIndent + e[0] + ': ' + e[1]); });
      var firstLine = p.end + 1;
      CM.splice(doc, p.end, 0, lines);
      entries.forEach(function (e, idx) {
        changes.push(overrideChangeRecord(stackRel, firstLine + idx, childIndent + e[0] + ': ' + e[1], overrideLeaf));
      });
      return;
    }

    var isList = null, byName = {}, lastChildLine = range.start - 1, itemIndent = null;
    for (var i = range.start; i < range.end; i++) {
      if (lineKind(doc.lines[i]).kind !== 'other') continue;
      if (isList === null) isList = /^\s*-\s*/.test(doc.lines[i]);
      lastChildLine = i;
      if (isList) {
        var lm = /^(\s*)-\s*(['"]?)([^'"=]+)=/.exec(doc.lines[i]);
        if (lm) { byName[lm[3]] = i; if (itemIndent === null) itemIndent = lm[1]; }
      } else {
        var mm = /^(\s*)([^:\s][^:]*):\s*(.*)$/.exec(doc.lines[i]);
        if (mm) { byName[mm[2].trim()] = i; if (itemIndent === null) itemIndent = mm[1]; }
      }
    }
    if (isList === null) isList = false;
    if (itemIndent === null) itemIndent = new Array(p.indent + 5).join(' ');

    var toAppend = [];
    entries.forEach(function (e) {
      var eName = e[0], eValue = e[1];
      if (Object.prototype.hasOwnProperty.call(byName, eName)) {
        var lineIdx = byName[eName];
        var oldLine = doc.lines[lineIdx];
        var newLine = isList ? (itemIndent + '- ' + eName + '=' + eValue) : rewriteScalarValue(oldLine, eValue);
        if (newLine !== oldLine) {
          doc.lines[lineIdx] = newLine;
          changes.push(overrideChangeRecord(stackRel, lineIdx, newLine, overrideLeaf));
        }
      } else {
        toAppend.push(isList ? (itemIndent + '- ' + eName + '=' + eValue) : (itemIndent + eName + ': ' + eValue));
      }
    });

    if (toAppend.length) {
      var at = lastChildLine + 1;
      CM.splice(doc, at, 0, toAppend);
      toAppend.forEach(function (line, idx) {
        changes.push(overrideChangeRecord(stackRel, at + idx, line, overrideLeaf));
      });
    }
  }

  /* =====================================================================
   * PLAN_155 C15 — a rewired connection must land on a shared network.
   * Across several stacks each keeping its own default network beside a
   * named one, a merge can rewrite an address to a name docker cannot
   * resolve, because the two ends were never on the same network to begin
   * with (F26, third walk). Shared with the address-rewire pass below and
   * with the dependency lines step 5 draws — both call joinSharedNetwork()
   * once they know which two services must be able to reach each other.
   *
   * The plan's own rule is "never remove a network from anything", and a
   * service with no networks: key at all is implicitly on the project's
   * default network — so writing networks: [<joined>] alone for such a
   * service would silently drop it off default too, breaking every OTHER
   * connection it had through that implicit membership. appendNetworkJoin()
   * writes default out explicitly alongside the join whenever it is the one
   * creating the key from nothing, for exactly that reason.
   * ===================================================================== */

  // A service's own network set, per the plan's own rule: an absent
  // `networks:` key (or one written as an empty list) means the project's
  // default network, never "reaches nothing". Reads a SOURCE DESCRIPTOR
  // (descriptorFromText()'s own already-parsed-to-plain shape) — used only
  // for B, the rewire's target, which usually lives in a different
  // source's doc than the one this pass is editing.
  function networkSetOf(svc) {
    if (!svc) return ['default'];
    return (svc.networks && svc.networks.length) ? svc.networks : ['default'];
  }

  // The same network set and network_mode flag, read straight off a
  // parsed doc's own AST instead of a descriptor — for A, the consumer
  // whose file this pass is actually editing: a shorthand-clash rename or
  // an earlier join in this same pass has already changed its live
  // `networks:` list by the time this runs, and a descriptor taken before
  // any of that would say something the file no longer does. Exported so
  // merge-suggest.js's depends_on writer (step 5, drawn on the one
  // already-merged doc, where both ends are in the SAME document) can ask
  // the identical question of either side.
  function serviceNetworkInfo(doc, svcName) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p || !p.value || p.value.kind !== 'map') return { networks: [], networkMode: false };
    var netPair = p.value.pairs['networks'];
    var networks = [];
    if (netPair && netPair.value) {
      if (netPair.value.kind === 'seq') {
        networks = netPair.value.items.map(function (it) {
          return (it.value && it.value.kind === 'scalar') ? String(it.value.value) : null;
        }).filter(Boolean);
      } else if (netPair.value.kind === 'map') {
        networks = (netPair.value.keys || []).slice();
      }
    }
    return { networks: networks, networkMode: !!p.value.pairs['network_mode'] };
  }

  // Appends one bare name to a service's networks: list, in whichever
  // shape (plain list or map) it already has, creating the key as the
  // service's own last key when it has none yet — the same narrow,
  // line-preserving shape overrideAppendList() above uses for exactly the
  // same reason (CLAUDE.md rule 2: nothing here re-prints the file).
  // Returns the line the JOINED name itself landed on.
  function appendNetworkJoin(doc, svcName, netName) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p) return -1;

    var keyRe = /^(\s*)networks:\s*(#.*)?$/;
    var range = findKeyChildRange(doc, p.start, p.end, keyRe);

    if (!range) {
      var keyIndent = new Array(p.indent + 3).join(' ');
      var itemIndent = new Array(p.indent + 5).join(' ');
      var at = p.end;
      // No networks: key at all means this service was on the project's
      // implicit default network (PLAN_155 C15's own rule). Writing
      // networks: [netName] alone would silently detach it from default —
      // a real removal, whatever "never remove a network" meant to rule
      // out — so default is written out explicitly alongside the join.
      CM.splice(doc, at, 0, [keyIndent + 'networks:', itemIndent + '- default', itemIndent + '- ' + netName]);
      return at + 2;
    }

    // networks: is the one key that can be written as either a plain list
    // ("- name") or a map ("name:", one entry per attached network) — see
    // promoteNetworksList()'s own comment in compose-model.js. Shape is
    // read off the first real child line still standing, exactly the way
    // overrideMergeMap() above tells environment/labels apart.
    var isMap = null, itemIndent2 = null;
    for (var i = range.start; i < range.end; i++) {
      if (lineKind(doc.lines[i]).kind !== 'other') continue;
      if (/^\s*-\s*/.test(doc.lines[i])) { isMap = false; itemIndent2 = /^(\s*)-/.exec(doc.lines[i])[1]; break; }
      var mm = /^(\s*)[^:\s][^:]*:/.exec(doc.lines[i]);
      if (mm) { isMap = true; itemIndent2 = mm[1]; break; }
    }
    if (isMap === null) { isMap = false; itemIndent2 = new Array(p.indent + 5).join(' '); }

    var newLine = itemIndent2 + (isMap ? (netName + ':') : ('- ' + netName));
    var at2 = range.end;
    CM.splice(doc, at2, 0, [newLine]);
    return at2;
  }

  // Called once per rewire (an address-rewire's own service pair) after
  // the rewrite itself has already been applied. `aDoc`/`aSvcName` is the
  // CONSUMER's own doc — the only one this ever writes into, since B's own
  // file is not what failed to resolve B by name; A's own set is read
  // live (serviceNetworkInfo), so a rename or an earlier join this same
  // pass already made is what gets checked, not a stale snapshot — which
  // also makes a second rewire needing the same network a no-op rather
  // than a duplicate line. Returns the change record to push, or null
  // when the two are already on a shared network (the ordinary case).
  function joinSharedNetwork(aDoc, aSvcName, bSvc, bSvcName, stackRel, sourceLine) {
    var aInfo = serviceNetworkInfo(aDoc, aSvcName);
    var aSet = aInfo.networks.length ? aInfo.networks : ['default'];
    var bSet = networkSetOf(bSvc);
    var shared = aSet.some(function (n) { return bSet.indexOf(n) >= 0; });
    if (shared) return null;

    // Compose refuses a service written with both network_mode: and
    // networks: — there is nowhere here to add a network to, and the
    // rewire is left exactly as written; the wizard still needs to know
    // why nothing changed.
    if (aInfo.networkMode) {
      return {
        key: 'network-join|' + stackRel + '|' + sourceLine, stack: stackRel, sourceLine: sourceLine,
        marker: null, title: 'Left on its own network',
        reason: aSvcName + ' uses network_mode, which cannot be combined with a networks: list — ' +
          'the address was rewritten, but reaching ' + bSvcName + ' by name may still fail.',
        struckComment: null
      };
    }

    var joinName = bSet[0];
    var at = appendNetworkJoin(aDoc, aSvcName, joinName);
    if (at < 0) return null;
    return {
      key: 'network-join|' + stackRel + '|' + sourceLine, stack: stackRel, sourceLine: at,
      marker: aDoc.lines[at], title: 'Joined the `' + joinName + '` network',
      reason: bSvcName + ' is only on `' + joinName + '`, so without this ' + aSvcName + ' could not reach it by name.',
      struckComment: null
    };
  }

  // merge-suggest.js's own version of the same rule (PLAN_155 C15), for
  // the depends_on pairs step 5 draws — both ends already live in the ONE
  // merged doc by then, so there is no descriptor to read B from; both
  // sides go through serviceNetworkInfo(). Returns true when a network
  // was actually joined, so a caller with no change-record mechanism of
  // its own (merge-suggest.js's apply() only ever returns added lines) can
  // still tell whether anything was written. No network_mode wording here
  // for the same reason — apply() has nowhere to show a reason; the
  // dependency line is still written regardless, exactly as address-
  // rewire leaves the rewrite itself in place.
  function joinNetworkIfNeeded(doc, aSvcName, bSvcName) {
    var aInfo = serviceNetworkInfo(doc, aSvcName);
    var bInfo = serviceNetworkInfo(doc, bSvcName);
    var aSet = aInfo.networks.length ? aInfo.networks : ['default'];
    var bSet = bInfo.networks.length ? bInfo.networks : ['default'];
    if (aSet.some(function (n) { return bSet.indexOf(n) >= 0; })) return false;
    if (aInfo.networkMode) return false;
    return appendNetworkJoin(doc, aSvcName, bSet[0]) >= 0;
  }

  // `command`, `entrypoint`, `image`, `build` and every other scalar or
  // unknown key — replaced outright, or added as the service's own last
  // key when the base did not have it at all.
  function overrideReplaceScalar(doc, svcName, key, valueText, stackRel, overrideLeaf, changes) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p) return;

    var keyRe = new RegExp('^\\s*' + key + ':\\s*(.*)$');
    for (var i = p.start; i < p.end; i++) {
      if (!keyRe.test(doc.lines[i])) continue;
      var newLine = rewriteScalarValue(doc.lines[i], valueText);
      if (newLine !== doc.lines[i]) {
        doc.lines[i] = newLine;
        changes.push(overrideChangeRecord(stackRel, i, newLine, overrideLeaf));
      }
      return;
    }

    var keyIndent = new Array(p.indent + 3).join(' ');
    var newLine2 = keyIndent + key + ': ' + valueText;
    CM.splice(doc, p.end, 0, [newLine2]);
    changes.push(overrideChangeRecord(stackRel, p.end, newLine2, overrideLeaf));
  }

  // A service the override declares and the base does not — lifted out of
  // the override's own doc VERBATIM (comments, anchors and all), the same
  // rule buildMergedText() itself follows for every source block.
  function appendWholeService(doc, overrideDoc, svcName, stackRel, overrideLeaf, changes) {
    var svcMap = servicesMapOf(doc);
    var oSvcMap = servicesMapOf(overrideDoc);
    if (!svcMap || !oSvcMap || !oSvcMap.pairs[svcName]) return;
    var blocks = computeBlocks(overrideDoc, oSvcMap);
    var block = blocks.blocks[svcName];
    if (!block || !block.length) return;
    var at = svcMap.end;
    CM.splice(doc, at, 0, block);
    changes.push(overrideChangeRecord(stackRel, at, block[0], overrideLeaf));
  }

  // A top-level declared entry (volumes:/networks:/configs:/secrets:) the
  // override adds and the base lacks — added whole, verbatim. One the base
  // already has is left as the base wrote it: the two files agreeing on
  // the same declared name is the ordinary case, and reconciling a genuine
  // disagreement between them is C1's "two sources define the same
  // top-level key" rule, not this pass's job.
  function appendWholeDeclared(doc, overrideDoc, kind, name, stackRel, overrideLeaf, changes) {
    var declMap = declMapOf(doc, kind);
    var oDeclMap = declMapOf(overrideDoc, kind);
    if (!oDeclMap || !oDeclMap.pairs[name]) return;
    if (declMap && declMap.pairs[name]) return;   // already declared — leave it
    var blocks = computeBlocks(overrideDoc, oDeclMap);
    var block = blocks.blocks[name];
    if (!block || !block.length) return;

    var at;
    if (declMap) {
      at = declMap.end;
    } else {
      // No volumes:/networks:/… key at all yet in the base — add it as a
      // fresh top-level key at the end of the file, same as every other
      // "the base doesn't have this at all" branch above.
      var lines = [kind + ':'].concat(block.map(function (l) { return '  ' + l; }));
      at = doc.lines.length;
      CM.splice(doc, at, 0, lines);
      changes.push(overrideChangeRecord(stackRel, at + 1, lines[1], overrideLeaf));
      return;
    }
    CM.splice(doc, at, 0, block);
    changes.push(overrideChangeRecord(stackRel, at, block[0], overrideLeaf));
  }

  /**
   * Applies a paired override's own text onto the main file's already-
   * parsed text, per PLAN_155 C3's rules, and returns the applied text
   * plus one change record per line it added or changed. Called once, by
   * descriptorFromText() below, before anything else reads the source —
   * the applied text becomes that source's own `text` from that point on,
   * so every later pass (findings, the merged write, the source pane)
   * sees the pair as the one file compose itself treats them as.
   */
  function applyOverrideText(baseText, overrideText, stackRel, overrideLeaf) {
    var doc = CM.parse(baseText);
    var overrideDoc = CM.parse(overrideText);
    var overridePlain = toPlain(overrideDoc.root) || {};
    var changes = [];

    var overrideServices = overridePlain.services || {};
    Object.keys(overrideServices).forEach(function (svcName) {
      var svcMap = servicesMapOf(doc);
      if (!svcMap || !svcMap.pairs[svcName]) {
        appendWholeService(doc, overrideDoc, svcName, stackRel, overrideLeaf, changes);
        return;
      }
      var raw = overrideServices[svcName] || {};
      Object.keys(raw).forEach(function (key) {
        var value = raw[key];
        if (OVERRIDE_APPEND_LISTS.indexOf(key) !== -1) {
          var values = (Array.isArray(value) ? value : (value == null ? [] : [value])).map(overrideScalarText);
          overrideAppendList(doc, svcName, key, values, stackRel, overrideLeaf, changes);
        } else if (OVERRIDE_MERGE_MAPS.indexOf(key) !== -1) {
          overrideMergeMap(doc, svcName, key, overrideKVEntries(value), stackRel, overrideLeaf, changes);
        } else {
          overrideReplaceScalar(doc, svcName, key, overrideScalarText(value), stackRel, overrideLeaf, changes);
        }
      });
    });

    OVERRIDE_DECL_KINDS.forEach(function (kind) {
      Object.keys(overridePlain[kind] || {}).forEach(function (name) {
        appendWholeDeclared(doc, overrideDoc, kind, name, stackRel, overrideLeaf, changes);
      });
    });

    // Every record's sourceLine was noted as its line went in, and a LATER
    // insert higher up the same service (the override's environment: entry
    // after its ports: entry, in the override's own key order) pushed it
    // down without the note following — so the mark sat on "ports:" while
    // the merged mark sat on the port line beneath (Adrian, built walk
    // 2026-09-16). Resolved once here, against the finished text: each
    // record's marker IS its line, searched forward from where it was
    // noted (inserts only ever push a line down), then anywhere as a last
    // resort. The key carries the line, so it is rebuilt with it.
    changes.forEach(function (c) {
      var at = -1;
      for (var i = c.sourceLine; i < doc.lines.length; i++) { if (doc.lines[i] === c.marker) { at = i; break; } }
      if (at < 0) at = doc.lines.indexOf(c.marker);
      if (at >= 0 && at !== c.sourceLine) { c.sourceLine = at; c.key = 'override|' + stackRel + '|' + at; }
    });

    return { text: doc.bom + doc.lines.join(doc.eol || '\n'), changes: changes };
  }

  function descriptorFromText(name, composeText, envText, files, extra) {
    extra = extra || {};
    var overrideChanges = [];
    // PLAN_155 C3: a paired override is applied onto the main file's own
    // text FIRST, before anything else here reads it, so every later pass
    // — findings, the merged write, the source pane — sees the pair as the
    // one file compose itself treats them as. The applied text becomes
    // this descriptor's own `composeText` from this point on.
    if (typeof extra.overrideText === 'string') {
      var applied = applyOverrideText(composeText, extra.overrideText, name, leaf(name));
      composeText = applied.text;
      overrideChanges = applied.changes;
    }
    var doc = CM.parse(composeText);
    var plain = toPlain(doc.root) || {};

    // This source's own .env, read once — the settings file the wiring
    // passes resolve a "${VAR}" placeholder against (C2). Last assignment
    // wins, matching compose's own rule for a repeated name.
    var envParsed = readEnvText(envText);
    var envMap = {};
    (envParsed ? envParsed.lines : []).forEach(function (l) {
      if (l.type === 'setting') envMap[l.name] = l.value;
    });

    var services = {};
    Object.keys(plain.services || {}).forEach(function (svcName) {
      var raw = plain.services[svcName] || {};
      // PLAN_169 F18 — a mount written in the long form (`type:`/`source:`/
      // `target:`, a map rather than a single "host:container" string) used
      // to come back null here and vanish from this list entirely, so
      // findStorageFindings() (merge-examine.js) never saw it as using a
      // declared volume: the volume read as unused, was never carried and
      // never clash-renamed, and a service mounting it long-form was left
      // pointing at whichever OTHER source's volume the merge kept under
      // that same key. Read both shapes into the same {type, source,
      // target, ro} the short form already produces. Only `type: volume`
      // names a declared volume the way this examines things; `type: bind`
      // is a host path (kept apart the same way a short "./x:/y" mount is);
      // anything else (`tmpfs`, `npipe`, `cluster`) has no declared block
      // to carry or rename, so it is dropped, same as before.
      var volumes = asStringArray(raw.volumes).map(function (entry) {
        if (typeof entry === 'string') {
          var parts = entry.split(':');
          var source = parts[0], target = parts[1] || '';
          var type = (source.charAt(0) === '.' || source.charAt(0) === '/') ? 'bind' : 'named';
          return { type: type, source: source, target: target, ro: parts[2] === 'ro' };
        }
        if (entry && typeof entry === 'object') {
          if (entry.type === 'volume') {
            return { type: 'named', source: entry.source || '', target: entry.target || '', ro: !!entry.read_only };
          }
          if (entry.type === 'bind') {
            return { type: 'bind', source: entry.source || '', target: entry.target || '', ro: !!entry.read_only };
          }
        }
        return null;
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

      // C2 — each value as the author wrote it, resolved once against this
      // source's own .env (see resolveEnvValue()'s own comment). The two
      // wiring passes read `environmentResolved`; `environment` above stays
      // the as-written text, which is what a rewrite has to find and match
      // in the compose line itself.
      var environmentResolved = {};
      Object.keys(environment).forEach(function (varName) {
        var via = [];
        environmentResolved[varName] = { value: resolveEnvValue(environment[varName], envMap, via), via: via };
      });

      // F2/F9/F11 — flattened to a plain key->value map regardless of
      // whether the author wrote labels: as a list ("key=value" entries) or
      // a map (key: value directly) — findLabelClashes() (merge-examine.js)
      // only ever needs to ask "what is this label's own value", never how
      // it was shaped.
      var labels = {};
      if (Array.isArray(raw.labels)) {
        raw.labels.forEach(function (kv) {
          var eq = String(kv).indexOf('=');
          if (eq >= 0) labels[kv.slice(0, eq)] = kv.slice(eq + 1);
        });
      } else if (raw.labels && typeof raw.labels === 'object') {
        labels = raw.labels;
      }

      services[svcName] = {
        image: raw.image || '',
        container_name: raw.container_name || undefined,
        restart: raw.restart || '',
        ports: asStringArray(raw.ports).map(splitPortEntry),
        volumes: volumes,
        environment: environment,
        environmentResolved: environmentResolved,
        env_file: asStringArray(raw.env_file),
        labels: labels,
        // Read only so a port-clash finding can tell whether a service is
        // ever started by default (PLAN_155 C10/F16) — nothing here writes
        // profiles: back out, so no rename or rewrite touches it.
        profiles: asStringArray(raw.profiles),
        networks: Array.isArray(raw.networks) ? raw.networks.slice() : Object.keys(raw.networks || {}),
        // PLAN_155 C15 — a service pinned to network_mode has no networks:
        // list of its own to join anything onto; read here so the address-
        // rewire pass can tell that case apart and leave the rewire alone.
        network_mode: raw.network_mode || undefined,
        depends_on: Array.isArray(raw.depends_on) ? raw.depends_on.slice() : Object.keys(raw.depends_on || {}),
        build: raw.build,
        extends: raw.extends,
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
      // Kept so examine() can locate the exact lines a finding will change
      // (see its own docCacheFor()) — read-only there, never re-parsed
      // into a second source of truth for anything this file itself edits.
      text: composeText,
      // The override's own change records (PLAN_155 C3), empty when this
      // source has none — buildMergedText() folds these into its own
      // `changes` array, the same way every other per-source record is.
      overrideChanges: overrideChanges,
      depth: typeof extra.depth === 'number' ? extra.depth : 0,
      // PLAN_155 C4 — left unset (not defaulted to `name`) when the caller
      // does not supply it, so a caller that only ever knew `depth` keeps
      // falling back to it exactly as before; see resolveDepthPath()'s own
      // comment in merge-examine.js.
      rel: typeof extra.rel === 'string' ? extra.rel : null,
      files: files || [],
      filesLarge: extra.filesLarge || null,
      // PLAN_155 C18 — what this source's running containers were actually
      // started from, straight from the merge-files reply; examine()'s
      // hidden-config finding is what turns a mismatch here into a refusal.
      runningFrom: extra.runningFrom || { configFiles: [], envFile: '' },
      env: envParsed,
      compose: {
        name: plain.name || null,
        services: services,
        volumes: declBlock('volumes'),
        networks: declBlock('networks'),
        configs: declBlock('configs'),
        secrets: declBlock('secrets'),
        // F4 — the top-level include: list, read only so
        // findDepthPathFindings() can re-point a "../" path in it exactly
        // like extends.file; nothing here ever edits this array back out,
        // since the general top-level-key carry (buildMergedText()'s own
        // "C1" pass) already copies the whole `include:` block verbatim.
        include: Array.isArray(plain.include) ? plain.include : []
        // No stack_x_unraid — a stack has no icon or description of its
        // own, only a service does, so nothing here ever reads a top-level
        // x-unraid block back out of a parsed source.
      }
    };
  }

  /* =====================================================================
   * Joining names for the opening comment — "a", "a and b", "a, b and c".
   * ===================================================================== */

  function joinNames(names) {
    if (names.length === 1) return names[0];
    if (names.length === 2) return names[0] + ' and ' + names[1];
    return names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
  }

  /* =====================================================================
   * The write itself
   * ===================================================================== */

  // sources: [{ name, text, envText, files, depth, rel, overrideChanges }, ...]
  // — `name` is the stack's own full rel (see descriptorFromText() above,
  // and leaf()'s own comment for how the LEAF used in written text is
  // derived from it); `files` is that source's OWN companion list when the
  // caller already has it (falls back to opts.files[name] below, which is
  // the shape the browser-to-server contract actually sends: a map keyed
  // by source name). `overrideChanges` (PLAN_155 C3) is the change-record
  // list descriptorFromText() built when it applied that source's paired
  // override onto `text` — omitted or empty for a source with none. `rel`
  // (PLAN_155 C4) is usually the same value as `name` — see
  // descriptorFromText()'s own comment for why it is carried separately.
  //
  // opts: { date, thisServer, newDepth, decisions, envNames, files }
  //   `name` doubles as the new stack's own rel, passed to examine() as
  //   `newRel` (PLAN_155 C4) — nothing else here needs its own rel field.
  //   files: { sourceName: { files: [...], large: null|{path},
  //     runningFrom: {configFiles,envFile} } } — the merge-files reply for
  //     each source, keyed by name; runningFrom (PLAN_155 C18) is what
  //     feeds examine()'s hidden-config refusal.
  //   envNames: { changeKey: 'TYPED_NAME' } — the free-text box behind a
  //     settings-join rename's "choose-name" decision (changeKey is that
  //     rename's own change-record key, "<finding key>|rename|<i>"); read
  //     only when decisions[changeKey] === 'choose-name', and only when it
  //     is a legal identifier (^[A-Za-z_][A-Za-z0-9_]*$) — anything missing
  //     or unusable falls back to the automatic suffix silently. The
  //     settings-join finding's OTHER per-entry decision, "keep-both" on a
  //     dedupe entry, needs no second map: it just writes the second
  //     source's own line back in under its own name.
  //
  // -> { text, env, files: [{from,path,to}],
  //      changes: [{key,stack,line,sourceLine,title,reason,struckComment,
  //                 part,file,removed,removedText}],
  //        `part` is only present when a single decision touches more than
  //        one line (a split host/port rewire, an automatic rename's own
  //        key line plus every reference) — those records share one `key`
  //        so the wizard can mark and approve them together, `part`
  //        telling them apart ('host'/'port', or a 0-based index).
  //        `file` is only present ('env') for a change against the joined
  //        .env text; everything else is against the merged compose text.
  //        `removed` marks a line the WRITTEN file no longer carries at
  //        all (an emptied ports: key, a deduped .env setting) — `line` is
  //        then where a struck ghost row belongs (the index of whatever
  //        now follows it), and `removedText` is the exact line that
  //        would have stayed had this not been removed.
  //      newProject (the NEW stack's own leaf) }
  // Every `stack` field anywhere in the result — on a change, a finding, a
  // files[] entry's `from` — is the source's full rel, exactly as passed
  // in; every piece of WRITTEN text (the opening comment, "# From <x>",
  // a carried volume's real name, a rename suffix, a change's own title or
  // reason) uses that source's LEAF instead — see leaf()'s own comment.
  function buildMergedText(sources, opts) {
    opts = opts || {};
    var date = opts.date || new Date().toISOString().slice(0, 10);
    var decisions = opts.decisions || {};
    var filesReplies = opts.files || {};
    var newDepth = typeof opts.newDepth === 'number' ? opts.newDepth
      : (opts.name && opts.name.indexOf('/') >= 0 ? 1 : 0);

    // PLAN_169 F8 — the wizard already refuses to carry an unreadable
    // source past picking (see stacks.js, mergeLoadStack()/
    // mergeUpdateStep2Live()), but this is the one place every route into
    // a merge — the wizard, a probe, a future caller — passes through, so
    // it is refused again here rather than trusted to have been checked
    // already. CM.parse()'s own warnings mean it read what it could and
    // left the rest of the file alone; merging that silently would build a
    // file missing whatever line broke, with nothing said. `text: null` is
    // the caller-facing half of the refusal — nothing downstream can
    // mistake this for a normal result and finish writing it out.
    var unreadable = sources.map(function (s) {
      var warnings = CM.parse(s.text).warnings || [];
      return warnings.length ? { stack: s.name, line: warnings[0].line } : null;
    }).filter(Boolean);
    if (unreadable.length) {
      return {
        text: null, env: null, files: [], missingIcons: [], changes: [], newProject: null, findings: [],
        refusals: unreadable.map(function (u) {
          return {
            kind: 'unreadable-source', severity: 'refusal', stack: u.stack,
            facts: { line: u.line },
            message: leaf(u.stack) + ' has a line StaXX cannot read (line ' + (u.line + 1) +
              '). Fix it in the editor, then pick it again.',
            lines: []
          };
        })
      };
    }

    var descs = sources.map(function (s) {
      var reply = filesReplies[s.name] || {};
      return descriptorFromText(s.name, s.text, s.envText,
        reply.files || s.files || [], {
          filesLarge: reply.large || s.filesLarge || null, depth: s.depth, rel: s.rel,
          runningFrom: reply.runningFrom || s.runningFrom
        });
    });
    // PLAN_155 C15 — a rewire's target lives in whichever source declared
    // it, not necessarily the one being edited; this is the lookup the
    // network-join pass beside the address-rewire pass uses to read B's
    // own networks: without re-parsing anything.
    var descByName = {};
    descs.forEach(function (d) { descByName[d.name] = d; });

    // PLAN_155 C4: opts.name IS the new stack's own rel (see this
    // function's own header comment) — passed through as `newRel` so
    // findDepthPathFindings() can resolve a "../" path by folder.
    var exam = ME.examine(descs, { date: date, thisServer: opts.thisServer || [], newDepth: newDepth, newRel: opts.name });
    exam.sources = descs;

    var refusals = exam.findings.filter(function (f) {
      if (f.severity === 'refusal') return true;
      if (f.kind === 'settings-join' && decisionValue(decisions, f) === 'stop-here') return true;
      return false;
    });

    var plan = exam.plan;
    var changes = [];

    // PLAN_160 A: every address actually rewired below, by FINAL service
    // names — {fromService, envVar, toService} — turned into a confirmed
    // x-unraid.links entry once the whole merged text exists, so the
    // editor's own crosslinks detector never re-asks a question this wizard
    // already answered. Collected here rather than written as each rewire
    // happens because the target service may belong to a DIFFERENT source
    // than the one being edited at that point, so it does not exist yet in
    // any single doc — only in the finished, spliced-together text.
    var wiredLinks = [];

    // PLAN_155 C3: each source's own override-application records, built
    // once when its descriptor was first read — descs above is a SECOND,
    // fresh parse that never re-applies the override (extra.overrideText
    // is not passed to it), so these come from `sources` itself, not descs.
    // Folded in unresolved, the same as every other per-source record: the
    // per-block loop below finds each one's `marker` text and fills in
    // `line` once that source's spliced block exists in finalLines.
    sources.forEach(function (s) { (s.overrideChanges || []).forEach(function (c) { changes.push(c); }); });

    // A storage-carry finding whose merged file needs a DIFFERENT key than
    // the source declared (two sources both calling their storage "data",
    // say) is a rename too — applied the same way as a shorthand clash, via
    // CM.renameDeclared() on the source's OWN doc, so every service that
    // mounts it under the old name follows the rename rather than the
    // merged file quietly declaring two "data:" blocks that collide.
    // PLAN_155 C7: a source is identified everywhere by its rel — which, for
    // a real merge, IS a store path ("DEV-TESTING/t155-db") and so already
    // contains a "/". Building this map's key by string concatenation
    // ("<name>/volumes/<vol>") and later pulling it apart with split('/')
    // silently misfired the moment a name had one: the split produced more
    // than three parts, the identity check below never matched, and
    // renameDeclared() was never called on that source's doc at all — the
    // merged volumes: block still came out right (it is rebuilt separately,
    // by mergedKey, further down) while every service that mounted the old
    // name kept mounting it, live on Adrian's box. Fixed by never splitting
    // the key: match it by prefix instead, and keep the "kept" stack's own
    // leaf on hand (below) so the change records can name it.
    var volumeCarryRenames = {};
    var volumeCarryFindings = {};
    var keptLeafByVolume = {};   // volName -> leaf of the source that keeps it unrenamed
    exam.findings.forEach(function (f) {
      if (f.kind !== 'storage-carry') return;
      if (f.facts.mergedKey === f.facts.volume) { keptLeafByVolume[f.facts.volume] = leaf(f.stack); return; }
      var k = f.stack + '/volumes/' + f.facts.volume;
      volumeCarryRenames[k] = f.facts.mergedKey;
      volumeCarryFindings[k] = f;
    });

    // Every carried service's own icon, if it points inside its source's
    // .staxx/ — computed from `plan` (so a clash rename is already known)
    // ahead of both the file plan and the doc edits, same reasoning as the
    // storage-carry map above.
    var missingIcons = [];
    var iconCopies = planIconCopies(descs, plan, missingIcons);

    // The companion-file copy plan, decided ahead of the doc edits below —
    // fault 3's "rename it" answer is no good to anyone if the service
    // lines that used to point at the old name are left pointing at a file
    // that no longer exists under it.
    var filesOut = planFiles(exam, decisions, iconCopies);

    /* --- parse and edit every source's own doc --- */

    var docs = sources.map(function (s, idx) {
      var doc = CM.parse(s.text);
      // PLAN_155 F7: a second, untouched parse of this source's own text —
      // never edited by anything below. The wizard's source pane shows
      // this same text (the override applied, nothing else), so a change
      // record that is pinned to a SOURCE line (never carried into the
      // merged file — a stack's own left-behind x-unraid: block, an
      // anchor or top-level key rename) has to locate that line here, not
      // in `doc`. `doc` gets edited in place below (services renamed,
      // ports removed, paths rewritten…), and a removed line shifts every
      // line after it — so a position read off the edited doc can name a
      // line the source pane does not show at all, which is a record with
      // nowhere to be shown: the exact fault behind the merged heading's
      // count not matching the marks a person could find, seen live
      // 2026-09-15.
      var origDoc = CM.parse(s.text);
      var desc = descs[idx];

      // Icon files are rewritten by their own scoped pass just below (one
      // service's line at a time — see rewriteServiceIcon()'s own comment
      // for why a whole-doc text replace is wrong the moment two services
      // in one source share a single icon.png), so they are skipped here.
      var iconPathsHere = {};
      iconCopies.forEach(function (ic) { if (ic.source === s.name) iconPathsHere[ic.path] = true; });

      var renamedRefs = false;
      filesOut.forEach(function (fe) {
        if (fe.from !== s.name || !fe.to || fe.to === fe.path || iconPathsHere[fe.path]) return;
        if (rewriteFileReferences(doc, fe.path, fe.to)) renamedRefs = true;
      });
      if (renamedRefs) CM.splice(doc, 0, 0, []);   // no line-count change — just refreshes doc.root

      // Two sources both used the same service name — resolved for them,
      // no ticking involved. renameService() rewrites the service's own
      // key line and every same-doc reference (depends_on:, an x-unraid
      // section stash) in one call; pushAutoRenameChanges() records each
      // line it actually touched, not only the key.
      exam.findings.forEach(function (f) {
        if (f.kind !== 'container-name-clash' || f.stack !== s.name || f.facts.field !== 'service') return;
        var before = doc.lines.slice();
        CM.renameService(doc, f.facts.from, f.facts.to);
        // A rename picked from the image's own short name (both sources'
        // services really are different things) reads better said that
        // way; the plain suffix fallback keeps its older, more general
        // wording, since there nothing else distinguishes the two.
        var reason = f.facts.imageShortName
          ? ('Both sources have a service called "' + f.facts.from + '"; this one is the ' +
             f.facts.imageShortName + ' container, so it is offered as "' + f.facts.to + '".')
          : ('Two source stacks both had a service called "' + f.facts.from + '" — this one is now "' + f.facts.to + '".');
        pushAutoRenameChanges(changes, f, before, doc.lines, 'Renamed to keep it distinct', reason);
      });

      // The icon rewrite itself — one line at a time, scoped to the
      // referencing service's own block, using its name IN THE MERGED FILE
      // (after the rename above). Silent: PLAN_155 C17 is explicit that
      // this is "not a question" — no card, no change record, just a line
      // in step 4's own file list (drawn from planIconCopies(), not here).
      iconCopies.forEach(function (ic) {
        if (ic.source !== s.name) return;
        rewriteServiceIcon(doc, ic.finalService, ic.oldRef, ic.newRef);
      });

      // Two sources both gave a container the same fixed name.
      // rewriteContainerName() only ever rewrites the one container_name:
      // line — there is nowhere else in a compose file that names a
      // container by it — but it still goes through the same recording
      // helper rather than a one-off push, so a future reference this file
      // learns to follow is covered without anyone having to remember to
      // come back here.
      exam.findings.forEach(function (f) {
        if (f.kind !== 'container-name-clash' || f.stack !== s.name || f.facts.field !== 'container_name') return;
        var finalSvc = plan.serviceRenames[s.name + '/' + f.facts.service] || f.facts.service;
        var before = doc.lines.slice();
        rewriteContainerName(doc, finalSvc, f.facts.from, f.facts.to);
        pushAutoRenameChanges(changes, f, before, doc.lines, 'Renamed to keep it distinct',
          'Two source stacks both named a container "' + f.facts.from + '" — this one is now "' + f.facts.to + '".');
      });

      // Two sources both declared a network/volume/config/secret under the
      // same shorthand name for different things. renameDeclared() rewrites
      // the declaration's own key line and every service that mounts,
      // attaches or references it by that name.
      exam.findings.forEach(function (f) {
        if (f.kind !== 'shorthand-clash' || f.stack !== s.name) return;
        var before = doc.lines.slice();
        CM.renameDeclared(doc, f.facts.declKind, f.facts.from, f.facts.to);
        pushAutoRenameChanges(changes, f, before, doc.lines, 'Renamed to keep it distinct',
          'Two source stacks both had a ' + f.facts.declKind.replace(/s$/, '') + ' called "' + f.facts.from +
          '", for different things — this one is now "' + f.facts.to + '".');
      });

      // F2/F9/F11 — two sources both named a Traefik router/service/
      // middleware the same thing (Traefik reads labels box-wide, so a name
      // has to be unique across the whole new stack). rewriteServiceLabels()
      // does its own line-by-line edit (never a doc.lines diff, unlike the
      // renames above) so it can strike a falsified lead comment on exactly
      // the line it touches — F11's own struck-comment requirement, the same
      // mechanism every other rewrite here uses (stripCommentAbove()).
      //
      // F13 — the finding now names every namespace ("routers", "services",
      // ...) the clashing name was used in for this one service, since a
      // router and its matching service commonly share one literal name and
      // grouping them separately raised one identical-looking card per
      // namespace. Rewriting each section in turn still produces its own
      // change records (each namespace's own labels are on their own
      // lines), but they all share the one finding's key, title and reason —
      // one card, whichever namespaces it touched.
      exam.findings.forEach(function (f) {
        if (f.kind !== 'label-clash' || f.stack !== s.name) return;
        var finalSvc = plan.serviceRenames[s.name + '/' + f.facts.service] || f.facts.service;
        var part = 0;
        (f.facts.sections || []).forEach(function (section) {
          var results = rewriteServiceLabels(doc, finalSvc, section, f.facts.from, f.facts.to);
          results.forEach(function (r) {
            changes.push({
              key: f.key, part: part++, stack: s.name, sourceLine: sourceLineFor(f), marker: r.text,
              title: 'Two services use the proxy name ' + f.facts.from,
              reason: 'Renamed to ' + f.facts.to + ' in ' + finalSvc + ' so both keep working.',
              struckComment: r.struckComment || null, cannotLeave: true
            });
          });
        });
      });

      // A storage-carry finding whose merged file needs a different key
      // than the source declared — see this function's own comment above
      // on volumeCarryRenames. f.lines already names every line this
      // touches (the declaration's own key, plus every mount), gathered by
      // the examiner itself, so there is no diff to take here. Matched by
      // PREFIX, never split('/') — see volumeCarryRenames's own comment for
      // why a plain split silently dropped this whole rename, first-picked
      // or not, whenever a source's name held a "/".
      var volPrefix = s.name + '/volumes/';
      Object.keys(volumeCarryRenames).forEach(function (k) {
        if (k.indexOf(volPrefix) !== 0) return;
        var volName = k.slice(volPrefix.length);
        // A volume declared but never mounted by any service is not
        // present in this doc's own volumes: block at all when nothing
        // used it named-and-non-external — renameDeclared() is a no-op
        // then, which is fine; the finding only ever fires for a volume a
        // service actually mounts.
        CM.renameDeclared(doc, 'volumes', volName, volumeCarryRenames[k]);
        var f = volumeCarryFindings[k];
        var otherLeaf = keptLeafByVolume[f.facts.volume] || 'another stack';
        (f.lines || []).forEach(function (le, i) {
          changes.push({
            key: f.key, part: i, stack: s.name, sourceLine: le.line, marker: doc.lines[le.line],
            title: i === 0 ? ('Still ' + leaf(s.name) + '’s own storage') : ('Points at ' + leaf(s.name) + '’s own storage'),
            reason: i === 0
              ? ('The label inside this file changed so the sources cannot collide; `name:` keeps it on the volume ' +
                 leaf(s.name) + ' was already using, so nothing is copied or emptied.')
              : ('Was `' + f.facts.volume + '`, which in the new stack is ' + otherLeaf + '’s.'),
            struckComment: null, cannotLeave: true   // two sources' storage under one key would collide
          });
        });
      });

      // Wiring — an address rewritten to reach the arriving container by
      // name. Ticked by default; unticking leaves the value exactly as the
      // author wrote it. A split rewire (DB_HOST and DB_PORT, say) touches
      // TWO lines, so it gets two change records — one per line — sharing
      // this finding's own `key` plus a `part` ('host'/'port') so the
      // wizard can mark and approve both from one popover. The earlier
      // shape wrote both lines but recorded only the host line, so the
      // port line changed with no mark at all — a breach of "never change
      // a file without saying so" (CLAUDE.md rule 2).
      // PLAN_155 C15 — once the address itself is rewritten, the two ends
      // must actually share a network or the new name resolves nowhere
      // (F26). One join per finding, not per line: a split rewire touches
      // two env vars for the same pair of services, so joining twice would
      // either duplicate the network entry or double the change record.
      // B's own descriptor is read from descByName by the finding's own
      // toStack (PLAN_155 C15 added this field alongside toService/toPort
      // for exactly this lookup) rather than assumed to be `desc` itself,
      // since a rewire's target is usually a DIFFERENT source.
      function joinForRewire(f, finalSvc, sourceLineForJoin) {
        var bDesc = descByName[f.facts.toStack];
        var bSvcData = bDesc && bDesc.compose.services[f.facts.toService];
        if (!bSvcData) return;
        var bFinalSvc = plan.serviceRenames[f.facts.toStack + '/' + f.facts.toService] || f.facts.toService;
        var joinChange = joinSharedNetwork(doc, finalSvc, bSvcData, bFinalSvc, s.name, sourceLineForJoin);
        if (joinChange) changes.push(joinChange);
      }

      // PLAN_160 A: the pair's own final names — computed the same way
      // joinForRewire's bFinalSvc is, but kept independent of it so a
      // record is still queued even where the two ends already share a
      // network and joinSharedNetwork() finds nothing to do.
      function finalToServiceName(f) {
        return plan.serviceRenames[f.facts.toStack + '/' + f.facts.toService] || f.facts.toService;
      }

      exam.findings.forEach(function (f) {
        if (f.kind !== 'address-rewire' || f.stack !== s.name) return;
        var finalSvc = plan.serviceRenames[s.name + '/' + f.facts.service] || f.facts.service;

        if (!decisionValue(decisions, f)) {
          // Declined — the line(s) stay exactly as the author wrote them;
          // still marked, so the count and the ring stay honest and the
          // decision can be reversed (CLAUDE.md rule 2).
          if (f.facts.split) {
            var declHostLine = findEnvAddressLine(doc, finalSvc, f.facts.hostVar, f.facts.fromHost);
            if (declHostLine !== null) {
              changes.push({
                key: f.key, part: 'host', declined: true, stack: s.name, sourceLine: sourceLineFor(f, 0), marker: doc.lines[declHostLine],
                title: 'Now reaches ' + f.facts.toService + ' inside the stack',
                reason: 'Left as written: still ' + f.facts.fromHost + '; approving would point it at ' + f.facts.toService + ' instead.',
                struckComment: null
              });
            }
            var declPortLine = findEnvAddressLine(doc, finalSvc, f.facts.portVar, f.facts.fromPort);
            if (declPortLine !== null) {
              changes.push({
                key: f.key, part: 'port', declined: true, stack: s.name, sourceLine: sourceLineFor(f, 1), marker: doc.lines[declPortLine],
                title: 'Now uses ' + f.facts.toService + '’s own port',
                reason: 'Left as written: still ' + f.facts.fromPort + '; approving would switch it to ' + f.facts.toPort + '.',
                struckComment: null
              });
            }
          } else {
            var declLine = findEnvAddressLine(doc, finalSvc, f.facts.envVar, f.facts.from);
            if (declLine !== null) {
              changes.push({
                key: f.key, declined: true, stack: s.name, sourceLine: sourceLineFor(f), marker: doc.lines[declLine],
                title: 'Now reaches ' + f.facts.toService + ' inside the stack',
                reason: 'Left as written: still ' + f.facts.from + '; approving would point it at ' + f.facts.toService + ' instead.',
                struckComment: null
              });
            }
          }
          return;
        }

        if (f.facts.split) {
          var rHost = rewriteEnvAddressTracked(doc, finalSvc, f.facts.hostVar, f.facts.fromHost, f.facts.toService);
          if (rHost) {
            changes.push({
              key: f.key, part: 'host', stack: s.name, sourceLine: sourceLineFor(f, 0), marker: rHost.text,
              title: 'Now reaches ' + f.facts.toService + ' inside the stack',
              reason: describeWas(f.facts.fromHost, f.facts.fromHostResolved) + ', out on the network.',
              struckComment: rHost.struckComment
            });
          }
          var rPort = rewriteEnvAddressTracked(doc, finalSvc, f.facts.portVar, f.facts.fromPort, f.facts.toPort);
          if (rPort) {
            changes.push({
              key: f.key, part: 'port', stack: s.name, sourceLine: sourceLineFor(f, 1), marker: rPort.text,
              title: 'Now uses ' + f.facts.toService + '’s own port',
              reason: describeWas(f.facts.fromPort, f.facts.fromPortResolved) + ', the port published on the network; inside the stack ' +
                f.facts.toService + ' listens on ' + f.facts.toPort + '.',
              struckComment: rPort.struckComment
            });
          }
          if (rHost || rPort) joinForRewire(f, finalSvc, sourceLineFor(f, 0));
          if (rHost) wiredLinks.push({ fromService: finalSvc, envVar: f.facts.hostVar, toService: finalToServiceName(f) });
          if (rPort) wiredLinks.push({ fromService: finalSvc, envVar: f.facts.portVar, toService: finalToServiceName(f) });
        } else {
          // matchAddr is the "host:port" fragment alone, not the whole value
          // f.facts.from carries for display — a database URI's user,
          // password, scheme and database name sit either side of it and
          // must survive the merge (CLAUDE.md rule 2).
          var result = rewriteEnvAddressTracked(doc, finalSvc, f.facts.envVar, f.facts.matchAddr, f.facts.toService + ':' + f.facts.toPort);
          if (result) {
            changes.push({
              key: f.key, stack: s.name, sourceLine: sourceLineFor(f), marker: result.text,
              title: 'Now reaches ' + f.facts.toService + ' inside the stack',
              reason: describeWas(f.facts.from, f.facts.fromResolved) + ', out on the network.',
              struckComment: result.struckComment
            });
            joinForRewire(f, finalSvc, sourceLineFor(f));
            wiredLinks.push({ fromService: finalSvc, envVar: f.facts.envVar, toService: finalToServiceName(f) });
          }
        }
      });

      // PLAN_179 P2 — a sidecar's own network_mode: "container:<name>" now
      // names a service sharing this same stack; rewritten to "service:
      // <that service's final key>" so Compose starts it after that
      // service rather than never (Compose only follows a "service:"
      // reference, never a bare container name it does not itself manage).
      // A wiring finding, ticked by default like every other rewire here;
      // a decline leaves the line exactly as written.
      exam.findings.forEach(function (f) {
        if (f.kind !== 'network-mode-join' || f.stack !== s.name) return;
        var finalSvc = plan.serviceRenames[s.name + '/' + f.facts.service] || f.facts.service;
        var finalTarget = plan.serviceRenames[f.facts.toStack + '/' + f.facts.toService] || f.facts.toService;
        var oldValue = 'container:' + f.facts.fromContainer;

        if (!decisionValue(decisions, f)) {
          var declLine = findNetworkModeLine(doc, finalSvc, oldValue);
          if (declLine !== null) {
            changes.push({
              key: f.key, declined: true, stack: s.name, sourceLine: sourceLineFor(f), marker: doc.lines[declLine],
              title: 'Now joins ' + finalTarget + '’s network inside the stack',
              reason: 'Left as written: still container:' + f.facts.fromContainer +
                '; approving would switch it to service:' + finalTarget + ' instead.',
              struckComment: null
            });
          }
          return;
        }

        var result = rewriteNetworkModeTracked(doc, finalSvc, oldValue, 'service:' + finalTarget);
        if (result) {
          changes.push({
            key: f.key, stack: s.name, sourceLine: sourceLineFor(f), marker: result.text,
            title: 'Now joins ' + finalTarget + '’s network inside the stack',
            reason: 'Was container:' + f.facts.fromContainer + '; now starts after ' + finalTarget + ' instead.',
            struckComment: result.struckComment
          });
        }
      });

      // Port clash (PLAN_155 C10) — the finding's recommended choice IS the
      // free port examine() already picked, so an untouched decision moves
      // that service's port with no further click needed (CLAUDE.md rule 2:
      // the merged file must never carry an unrecorded clash). Adrian's
      // decision (2026-09-16): Approved/Decline like every other card — no
      // "swap which side moves" any more, and no "stop publishing" either.
      // Declining leaves BOTH services' ports exactly as written; the new
      // stack then simply will not start until a person changes one of
      // them, which is his call to make, not StaXX's. Every branch pushes a
      // change record; the earlier shape rewrote the line with no record at
      // all, so the merged pane carried a silent change (F19).
      exam.findings.forEach(function (f) {
        if (f.kind !== 'port-clash' || f.stack !== s.name) return;
        var decision = decisionValue(decisions, f);
        var finalSvc = plan.serviceRenames[s.name + '/' + f.facts.service] || f.facts.service;

        if (decision === 'leave') {
          var lineIdx = findPortLine(doc, finalSvc, f.facts.port);
          if (lineIdx === null) return;
          changes.push({
            key: f.key, declined: true, stack: s.name, sourceLine: sourceLineFor(f, 0), marker: doc.lines[lineIdx],
            title: 'Two services publish port ' + f.facts.port,
            reason: f.facts.heldBy + ' and ' + f.facts.service + ' both publish ' + f.facts.port +
              '; left as written, so the new stack will not start until one of them changes.',
            struckComment: null
          });
          return;
        }

        var newPort = freePortFrom(decision);
        if (!newPort) return;
        var result = rewritePortHost(doc, finalSvc, f.facts.port, newPort);
        if (result) {
          changes.push({
            key: f.key, stack: s.name, sourceLine: sourceLineFor(f, 0), marker: result.text,
            title: 'Moved off a clashing port',
            reason: f.facts.heldBy + ' also publishes ' + f.facts.port + '; only one can, so this moved to ' + newPort + '.',
            struckComment: null
          });

          // F17 — the same service's own "open web page" address, if it
          // named the port that just moved. Shares the finding's key (a
          // second part of the one decision, like an address-rewire's
          // host/port pair) so the merged heading's own count is unaffected
          // — mergePaintedChangeKeys() (stacks.js) counts distinct KEYS, and
          // this key is already counted by the port move above.
          var webResult = rewriteServiceWebui(doc, finalSvc, f.facts.port, newPort);
          if (webResult) {
            changes.push({
              key: f.key, part: 'webui', stack: s.name, sourceLine: webResult.line, marker: webResult.text,
              title: 'Web address follows the moved port',
              reason: 'It named port ' + f.facts.port + ', which this service no longer publishes; now ' + newPort + '.',
              struckComment: null
            });
          }
        }
      });

      // A published port a REWIRE just made unneeded INSIDE this merge —
      // recommended and ticked OFF by default (PLAN_170: keeping a port
      // published costs nothing; stopping it can break something outside
      // the merge that StaXX cannot see). The card asks rather than
      // asserts, and answers as much of it as opts.portUsers already knows
      // — see merge-examine.js's own comment on this finding for what
      // `callers` and `host` carry, and PLAN_170's build decisions
      // for the wording below, which is verbatim.
      exam.findings.forEach(function (f) {
        if (f.kind !== 'port-unneeded' || f.stack !== s.name) return;
        var finalSvc = plan.serviceRenames[s.name + '/' + f.facts.service] || f.facts.service;

        var title = 'Is anything outside this merge using ' + f.facts.service + ' on port ' + f.facts.port + '?';
        var reason = 'Inside the new stack, ' + joinNames(f.facts.callers || [f.facts.service]) +
          ' now reaches it by name, so it does not need this published port. Anything else that ' +
          'connects from outside still does: another stack, a tool on your network, a script.';

        // opts.portUsers[f.key] is absent until the wizard's own scan has
        // answered — no evidence line at all then, the card still reads
        // correctly without one. Present as [] means the scan ran and found
        // nothing; present with names means it found stacks still using it.
        var users = opts.portUsers ? opts.portUsers[f.key] : undefined;
        if (users !== undefined) {
          reason += users.length
            ? ' ' + joinNames(users) + ' also connect' + (users.length === 1 ? 's' : '') +
              ' to this port. Stop publishing it and ' + (users.length === 1 ? 'that stack breaks.' : 'those stacks break.')
            : ' No other stack on this server connects to this address. StaXX cannot see anything off this server.';
        }

        if (!decisionValue(decisions, f)) {
          var declPortLine = findPortLine(doc, finalSvc, f.facts.port);
          if (declPortLine === null) return;
          changes.push({
            key: f.key, declined: true, stack: s.name, sourceLine: sourceLineFor(f), marker: doc.lines[declPortLine],
            title: title,
            reason: reason + ' Kept published. Decline to stop publishing it.',
            struckComment: null
          });
          return;
        }

        var result = removePortPublishTracked(doc, finalSvc, f.facts.port);
        if (result) {
          // The "ports:" key line itself (or, with the last entry gone,
          // its own rewritten "ports: []") — a real line to point at
          // either way, found by the ordinary marker search below rather
          // than the old anchorService fallback, which only ever landed on
          // the block's first line and never actually meant anything.
          // `emptied` (the last entry went, so the whole key is dropped
          // from the written file — see removePortPublishTracked's own
          // comment) is what tells buildMergedText()'s later removal pass
          // to actually strike this line, not merely mark it changed.
          var rec = {
            key: f.key, stack: s.name, sourceLine: sourceLineFor(f), marker: doc.lines[result.line],
            title: title,
            reason: reason + ' Stops being published.',
            struckComment: result.struckComment || null
          };
          if (result.emptied) {
            rec.removed = true;
            rec.reason = 'This line is removed from the written file. ' + rec.reason;
          }
          changes.push(rec);
        }
      });

      // Depth-path — a relative path re-pointed because the new stack sits
      // at a different depth than this source did.
      exam.findings.forEach(function (f) {
        if (f.kind !== 'depth-path' || f.stack !== s.name) return;

        if (decisionValue(decisions, f) === 'leave') {
          for (var pi = 0; pi < doc.lines.length; pi++) {
            if (doc.lines[pi].indexOf(f.facts.oldPath) === -1) continue;
            changes.push({
              key: f.key, declined: true, stack: s.name, sourceLine: sourceLineFor(f), marker: doc.lines[pi],
              title: 'Path adjusted for the new stack’s folder',
              reason: 'Left as written, so from the new stack’s folder ' + f.facts.oldPath + ' no longer reaches the file. Approving writes ' + f.facts.newPath + ' — the same file, where it already is.',
              struckComment: null
            });
            break;
          }
          return;
        }

        var result = rewritePathOccurrenceTracked(doc, f.facts.oldPath, f.facts.newPath);
        if (result) {
          changes.push({
            key: f.key, stack: s.name, sourceLine: sourceLineFor(f), marker: result.text,
            title: 'Path adjusted for the new stack’s folder',
            reason: 'Was ' + f.facts.oldPath + ', now ' + f.facts.newPath + ' — the same file, where it already is; only the route to it from the new stack’s folder changed. Nothing is moved.',
            struckComment: result.struckComment || null
          });
        }
      });

      return { name: s.name, doc: doc, origDoc: origDoc, desc: desc };
    });

    /* --- the joined .env text, if any source carried one --- */

    var envFinding = exam.findings.filter(function (f) { return f.kind === 'settings-join'; })[0];
    var envText = null;
    if (envFinding && decisionValue(decisions, envFinding) !== 'stop-here') {
      // Step 4's own two per-entry overrides, keyed exactly as its change
      // records are: "<the settings-join finding's key>|rename|<i>" and
      // "...|dedupe|<i>". Both are read straight out of `decisions`, not
      // through decisionValue() — these are not findings with their own
      // `.choices`, just a second answer against a name the wizard already
      // showed a button for.
      var envNames = opts.envNames || {};
      var ENV_NAME_RE = /^[A-Za-z_][A-Za-z0-9_]*$/;

      // The line in a source's OWN .env text a renamed/deduped entry came
      // from — env.lines is built one array entry per raw line (see
      // readEnvText() above), so its own index IS that source line number.
      function sourceEnvLine(stackName, settingName) {
        var d = descs.filter(function (x) { return x.name === stackName; })[0];
        var lines = (d && d.env && d.env.lines) || [];
        for (var i = 0; i < lines.length; i++) {
          if (lines[i].type === 'setting' && lines[i].name === settingName) return i;
        }
        return null;
      }

      // Pass 1: fold the two per-entry decisions into joinedLines itself —
      // a rename just changes the name a setting is written under, and
      // "keep both" turns a dedupe placeholder back into a real setting
      // line. Nothing here is measured against a final position yet;
      // that has to wait for pass 2 below, because a dedupe entry NOT
      // kept is about to disappear from the written file entirely (see
      // this file's own header and CLAUDE.md rule 2), and every line
      // after it shifts up by one as a result.
      envFinding.facts.renamed.forEach(function (r, i) {
        var changeKey = envFinding.key + '|rename|' + i;

        // "choose a name": the typed name wins once it is a legal
        // identifier; anything missing or unusable falls back to the
        // automatic "<NAME>_<LEAF>" suffix silently, exactly as if no
        // decision had been recorded at all.
        var finalName = r.to, overridden = false;
        if (decisions[changeKey] === 'choose-name') {
          var typed = envNames[changeKey];
          if (typeof typed === 'string' && ENV_NAME_RE.test(typed)) { finalName = typed; overridden = true; }
        }

        var entry = envFinding.facts.joinedLines.filter(function (l) {
          return l.type === 'setting' && l.stack === r.stack && l.name === r.to;
        })[0];
        if (entry && finalName !== r.to) entry.name = finalName;

        // The rename followed through into whatever in THIS source's own
        // compose file still reaches for the old name by interpolation —
        // true of the automatic suffix just as much as a typed name, since
        // either way the .env no longer defines the old one.
        var sd = docs.filter(function (d) { return d.name === r.stack; })[0];
        if (sd) rewriteEnvVarReferences(sd.doc, r.from, finalName);

        r.changeKey = changeKey; r.finalName = finalName; r.overridden = overridden;
      });

      envFinding.facts.sameValueNames.forEach(function (sv, i) {
        var changeKey = envFinding.key + '|dedupe|' + i;
        sv.changeKey = changeKey;

        // "keep both": the second source's own line is written back in,
        // under its OWN name (never renamed — the two values already
        // agree, so there is nothing to disambiguate), in place of the
        // dedupe placeholder that would otherwise have dropped it. No
        // change record follows — nothing was struck, so there is nothing
        // left to explain away.
        if (decisions[changeKey] === 'keep-both') {
          var entry = envFinding.facts.joinedLines.filter(function (l) {
            return l.dedupe && l.stack === sv.stack && l.name === sv.name;
          })[0];
          if (entry) { entry.type = 'setting'; entry.name = sv.name; entry.value = sv.value; entry.comment = ''; delete entry.dedupe; }
        }
      });

      // Pass 2: build the actual .env text and, in the same walk, record
      // the FINAL line index every joined entry lands on (`_finalLine`) —
      // a dedupe placeholder still marked `dedupe` here means "keep both"
      // was never chosen, so it is omitted from the written file rather
      // than left behind as a "# already set above" comment, and its
      // would-be position is remembered instead so the change record
      // below can tell the wizard exactly where to draw the struck ghost
      // row. Measuring positions by actually building the output, rather
      // than indexing into joinedLines itself, is what keeps this correct
      // once a line can be dropped from it.
      var envLines = [];
      envFinding.facts.joinedLines.forEach(function (l) {
        if (l.type === 'blank') { l._finalLine = envLines.length; envLines.push(''); return; }
        if (l.type === 'comment') {
          if (l.dedupe) { l._finalLine = envLines.length; return; }   // removed, not written
          l._finalLine = envLines.length; envLines.push(l.text); return;
        }
        l._finalLine = envLines.length;
        envLines.push(l.name + '=' + l.value + (l.comment ? '  # ' + l.comment : ''));
      });
      envText = envLines.join('\n') + '\n';

      envFinding.facts.renamed.forEach(function (r) {
        var entry = envFinding.facts.joinedLines.filter(function (l) {
          return l.type === 'setting' && l.stack === r.stack && l.name === r.finalName;
        })[0];
        changes.push({
          key: r.changeKey, stack: r.stack, file: 'env', line: entry ? entry._finalLine : null,
          sourceLine: sourceEnvLine(r.stack, r.from),
          title: 'Renamed — each stack meant a different setting',
          // firstStack is who keeps the plain name (see merge-examine.js's
          // own comment on why it must never be the renamed source itself).
          // A chosen name is worth naming too, since it is no longer the
          // one the reason's own "_LEAF" pattern would suggest.
          reason: r.overridden
            ? leaf(r.firstStack) + '’s stays ' + r.from + '; this one is now ' + r.finalName + '.'
            : leaf(r.firstStack) + '’s stays ' + r.from + '.',
          struckComment: null
        });
      });

      envFinding.facts.sameValueNames.forEach(function (sv) {
        if (decisions[sv.changeKey] === 'keep-both') return;   // written back in, nothing to explain away
        var entry = envFinding.facts.joinedLines.filter(function (l) {
          return l.dedupe && l.stack === sv.stack && l.name === sv.name;
        })[0];
        changes.push({
          key: sv.changeKey, stack: sv.stack, file: 'env', line: entry ? entry._finalLine : null,
          sourceLine: sourceEnvLine(sv.stack, sv.name),
          removed: true, removedText: sv.name + '=' + sv.value,
          title: 'Already set above, so not repeated',
          reason: 'Both stacks said the same thing.', struckComment: null
        });
      });

      // `_finalLine` was only ever pass 2's own scratch space, same
      // reasoning as the `marker`/`resolved` cleanup below for the compose
      // side.
      envFinding.facts.joinedLines.forEach(function (l) { delete l._finalLine; });
    }

    /* --- assemble the merged text --- */

    var finalLines = [];
    finalLines.push('# Made by joining ' + joinNames(sources.map(function (s) { return leaf(s.name); })) + ', ' + date + '.');
    finalLines.push('');

    // C1 (PLAN_156's dry run, corrected 2026-09-15) — every top-level key
    // that is not one of the five compose blocks travels too, or an anchor
    // declared under one of them (a stack's own "x-logging: &logging", say)
    // is carried with no anchor at all and every alias to it left pointing
    // at nothing — Rule 2 broken outright, and silently. `version:` and
    // `name:` are dropped outright; a stack's own `x-unraid:` (icon,
    // description, category, links, ...) is a per-STACK thing built FIELD
    // BY FIELD (PLAN_160 B): the first source's own copy is the base, and
    // every later source fills in whichever of its own keys the base
    // lacks — nothing a source wrote is dropped just for arriving second.
    // Every other top-level key is carried whole, and a clash between two
    // sources is settled the same way a declared network/volume clash
    // already is (identical text once, an x- key renamed, anything else
    // refused rather than silently guessed at).
    var KNOWN_FIVE = { services: 1, volumes: 1, networks: 1, configs: 1, secrets: 1 };
    var topAdditions = [];
    var carriedTopText = {};   // key -> { text, leaf } of whichever source's copy was kept
    var anchorOwners = {};     // anchor name -> leaf of the source that first defined it
    var versionDropped = false;
    var firstXUnraidLeaf = null;
    var xuFieldText = {};      // x-unraid sub-key -> the text already carried for it
    var xuInsertPos = null;    // index in topAdditions where a later source's own field is spliced in

    function scanAnchorNames(text) {
      var names = [], re = /&([A-Za-z_][\w.-]*)/g, m;
      while ((m = re.exec(text))) names.push(m[1]);
      return names;
    }

    // Renames an anchor and every alias to it, anywhere in this source's
    // OWN doc — an alias can sit inside a service spliced out of this same
    // doc further down (t155-web's two services both write
    // "logging: *logging"), so the rename has to happen before anything is
    // lifted out of it. Returns the definition line's own index (for the
    // change record), or -1 if the anchor was never actually found (should
    // not happen, since scanAnchorNames() just found it in this same doc).
    function renameAnchorInDoc(doc, oldName, newName) {
      var esc = escapeRegExp(oldName);
      var reDef = new RegExp('&' + esc + '\\b');
      var reDefG = new RegExp('&' + esc + '\\b', 'g');
      var reAliasG = new RegExp('\\*' + esc + '\\b', 'g');
      var anchorLine = -1;
      for (var i = 0; i < doc.lines.length; i++) {
        var line = doc.lines[i];
        var isDef = reDef.test(line);
        var next = line.replace(reDefG, '&' + newName).replace(reAliasG, '*' + newName);
        if (next !== line) {
          doc.lines[i] = next;
          if (isDef && anchorLine === -1) anchorLine = i;
        }
      }
      return anchorLine;
    }

    // Renames a block's own key line IN THE DOC ITSELF (never a copy) at an
    // absolute line range — the anchor rename above already mutates doc.lines
    // directly, so a key rename has to do the same, or whichever ran last
    // would silently undo the other's own text.
    function renameTopKeyLineInDoc(doc, blockStart, blockEnd, newKey) {
      var idx = blockStart;
      while (idx < blockEnd && lineKind(doc.lines[idx]).kind !== 'other') idx++;
      var m = /^(\s*)([^:\s][^:]*):(.*)$/.exec(doc.lines[idx]);
      if (m) doc.lines[idx] = m[1] + newKey + ':' + m[3];
    }

    // An anchor's own change record is pushed before it is known whether
    // THIS key survives untouched, gets its own key renamed too (both can
    // happen to the same block), or is refused outright — so its `marker`
    // is only ever read back out of the doc once every mutation for this
    // source is done, not set at push time.
    var pendingAnchorMarkers = [];

    // PLAN_155 F7: an anchor's definition line, located in a source's own
    // UNTOUCHED text — see origDoc's own comment above for why a position
    // read off the edited `doc` is not safe to hand back as a sourceLine.
    function findAnchorDefLine(lines, name) {
      var re = new RegExp('&' + escapeRegExp(name) + '\\b');
      for (var i = 0; i < lines.length; i++) if (re.test(lines[i])) return i;
      return -1;
    }

    docs.forEach(function (sd) {
      var doc = sd.doc;
      if (!doc.root || doc.root.kind !== 'map') return;

      // Pass 1: rename any anchor this source's OWN other-key blocks share
      // with an earlier source, before anything is captured for real — the
      // text carried below, and the one the identical-text check compares,
      // has to be the renamed copy, not the stale one.
      var scanBlocks = computeBlocks(doc, doc.root);
      scanBlocks.order.forEach(function (key) {
        if (KNOWN_FIVE[key] || key === 'version' || key === 'name' || key === 'x-unraid') return;
        scanAnchorNames(scanBlocks.blocks[key].join('\n')).forEach(function (name) {
          if (!(name in anchorOwners)) { anchorOwners[name] = leaf(sd.name); return; }
          if (anchorOwners[name] === leaf(sd.name)) return;
          var newName = name + '_' + leaf(sd.name);
          var atLine = renameAnchorInDoc(doc, name, newName);
          if (atLine >= 0) {
            var anchorChange = {
              key: 'top-anchor|' + sd.name + '|' + name, topLevel: true, stack: sd.name,
              sourceLine: findAnchorDefLine(sd.origDoc.lines, name),
              title: 'Renamed to keep it distinct',
              reason: 'Two source stacks both had an anchor called "' + name + '" — this one is now "' + newName + '".',
              struckComment: null, cannotLeave: true   // two anchors of the same name would collide
            };
            changes.push(anchorChange);
            pendingAnchorMarkers.push({ doc: doc, atLine: atLine, change: anchorChange });
          }
        });
      });

      // Pass 2: the real carry, key-clash rules from C1 — re-read the
      // blocks now any anchor rename above has actually happened. `blocks`
      // (this edited doc) is what supplies the CONTENT carried into the
      // merged file; `origBlocks` (the untouched parse) is what supplies
      // every sourceLine — see origDoc's own comment, above, for why the
      // two must not be conflated.
      var blocks = computeBlocks(doc, doc.root);
      var origBlocks = computeBlocks(sd.origDoc, sd.origDoc.root);

      // PLAN_179 P1 — this source's own file header, carried once above
      // whichever key turns out to be first, whatever key that is. A
      // "services:" header is left to the services pass further down
      // (the one place this text can already be attached correctly);
      // everywhere else, computeBlocks() above may already have picked
      // the header up as the first key's own lead comment (true only when
      // no blank line separates them — see leadStart()'s own comment), so
      // it is stripped back off that block first to avoid carrying it
      // twice.
      var srcFirstKey = doc.root.keys.length ? doc.root.keys[0] : null;
      var srcHeader = (srcFirstKey && srcFirstKey !== 'services') ? fileHeaderLines(doc) : [];
      if (srcHeader.length && blocks.blocks[srcFirstKey]) {
        var hb = blocks.blocks[srcFirstKey];
        if (hb.length >= srcHeader.length && hb.slice(0, srcHeader.length).join('\n') === srcHeader.join('\n')) {
          blocks.blocks[srcFirstKey] = hb.slice(srcHeader.length);
        }
      }

      blocks.order.forEach(function (key) {
        if (key === srcFirstKey && srcHeader.length) topAdditions = topAdditions.concat(srcHeader);
        if (KNOWN_FIVE[key]) return;

        if (key === 'version') {
          // Every change must be answered (PLAN_155 C17): unlike a
          // mandatory rename, dropping `version:` CAN be left as it was —
          // "leave" just means keep whichever source's copy asks for it
          // first, same one-record-only guard either way.
          if (decisions['top-version'] === 'leave') {
            if (versionDropped) return;
            versionDropped = true;
            topAdditions = topAdditions.concat(blocks.blocks[key]);
            var verLines = blocks.blocks[key];
            var verLineIdx = 0;
            while (verLineIdx < verLines.length && lineKind(verLines[verLineIdx]).kind !== 'other') verLineIdx++;
            changes.push({
              key: 'top-version', declined: true, topLevel: true, stack: sd.name, sourceLine: origBlocks.starts[key], marker: verLines[verLineIdx],
              title: 'The `version:` line is not carried',
              reason: 'Left as written: kept here. Approving would drop it instead — compose ignores it and warns about it, ' +
                'so a file StaXX writes fresh would not start with a warning.',
              struckComment: null
            });
            return;
          }
          // Compose ignores it and warns about it, so a file StaXX writes
          // fresh does not start with a warning — one record no matter how
          // many sources happened to carry a version: line of their own.
          if (!versionDropped) {
            versionDropped = true;
            changes.push({
              // line: null — the line is dropped, so there is nothing in the
              // merged file to point at; a merged line of 0 used to paint the
              // file's own opening comment as the change (Adrian's walk,
              // 2026-09-16: "things just are not aligned").
              key: 'top-version', topLevel: true, stack: sd.name, sourceLine: origBlocks.starts[key], line: null,
              title: 'The `version:` line is not carried',
              reason: 'Compose ignores it and warns about it; a file StaXX writes fresh does not start with a warning.',
              struckComment: null
            });
          }
          return;
        }
        if (key === 'name') return;   // the new stack's project name is its folder, dropped silently

        if (key === 'x-unraid') {
          // PLAN_160 B: field by field, not first-wins. The first source's
          // whole block is the base, carried exactly as before; every later
          // source is read key by key (description, category, links, ...)
          // and only a key the base LACKS gets added, marked with its own
          // "# From <leaf>" — a key both sides carry identically needs no
          // record, and one they carry differently is the same answerable
          // change as before. Decline no longer writes a renamed
          // "x-unraid-<leaf>" key into the file — dead weight the compose
          // spec ignores and StaXX never reads — the file keeps the base's
          // value either way, and the declined text travels only on the
          // change record itself, for the merge's own summary.
          var xuPair = doc.root.pairs['x-unraid'];
          var xuMap = xuPair && xuPair.value && xuPair.value.kind === 'map' ? xuPair.value : null;
          var subBlocks = xuMap ? computeBlocks(doc, xuMap) : null;
          var origXuPair = sd.origDoc.root && sd.origDoc.root.kind === 'map' ? sd.origDoc.root.pairs['x-unraid'] : null;
          var origXuMap = origXuPair && origXuPair.value && origXuPair.value.kind === 'map' ? origXuPair.value : null;
          var origSubBlocks = origXuMap ? computeBlocks(sd.origDoc, origXuMap) : null;

          if (firstXUnraidLeaf === null) {
            firstXUnraidLeaf = leaf(sd.name);
            topAdditions.push('# From ' + firstXUnraidLeaf);
            topAdditions = topAdditions.concat(blocks.blocks[key]);
            xuInsertPos = topAdditions.length;
            if (subBlocks) {
              subBlocks.order.forEach(function (sk) { xuFieldText[sk] = subBlocks.blocks[sk].join('\n'); });
            }
            return;
          }

          // PLAN_179 P1 — this source's own comment directly above its
          // "x-unraid:" line, carried once here since the field-by-field
          // splice below never reads it (only the FIELDS inside x-unraid).
          // Skipped when x-unraid is this source's own first key: the
          // generic file-header carry (this function's own docs.forEach,
          // above) already carried it there, blank line or not.
          if (srcFirstKey !== 'x-unraid') {
            var xuOwnLead = leadingCommentLinesOf(blocks.blocks[key]);
            if (xuOwnLead.length) topAdditions = topAdditions.concat(xuOwnLead);
          }

          if (!subBlocks) return;   // this source's own x-unraid carried nothing structured

          subBlocks.order.forEach(function (sk) {
            var skLines = subBlocks.blocks[sk];
            var skText = skLines.join('\n');
            var fieldKey = 'top-xunraid|' + sd.name + '|' + sk;
            var fieldSourceLine = (origSubBlocks && origSubBlocks.starts[sk] !== undefined)
              ? origSubBlocks.starts[sk] : origBlocks.starts[key];

            if (xuFieldText.hasOwnProperty(sk)) {
              if (xuFieldText[sk] === skText) return;   // identical — nothing new to say

              if (decisions[fieldKey] === 'leave') {
                changes.push({
                  key: fieldKey, declined: true, topLevel: true, stack: sd.name, sourceLine: fieldSourceLine, line: null,
                  title: 'This stack’s own ' + xuLabel(sk) + ' is not carried',
                  reason: 'Left as written: kept in the merge summary, not the file. A merged stack has one ' + xuLabel(sk) +
                    ', and ' + firstXUnraidLeaf + '’s is kept.',
                  declinedValue: skText, struckComment: null
                });
              } else {
                changes.push({
                  key: fieldKey, topLevel: true, stack: sd.name, sourceLine: fieldSourceLine, line: null,
                  title: 'This stack’s own ' + xuLabel(sk) + ' is not carried',
                  reason: 'A merged stack has one ' + xuLabel(sk) + ', and ' + firstXUnraidLeaf + '’s is kept. This one is left out.',
                  struckComment: null
                });
              }
              return;
            }

            // The base lacked this key outright — it is added, not fought
            // over, so it is spliced straight into the shared block rather
            // than answered as a decision.
            xuFieldText[sk] = skText;
            var skLineIdx = 0;
            while (skLineIdx < skLines.length && lineKind(skLines[skLineIdx]).kind !== 'other') skLineIdx++;
            var addLines = ['  # From ' + leaf(sd.name)].concat(skLines);
            topAdditions.splice.apply(topAdditions, [xuInsertPos, 0].concat(addLines));
            xuInsertPos += addLines.length;
            changes.push({
              key: fieldKey, topLevel: true, stack: sd.name, sourceLine: fieldSourceLine, marker: skLines[skLineIdx],
              title: 'Carried from ' + leaf(sd.name),
              reason: 'The base stack had no "' + sk + '" of its own; this is ' + leaf(sd.name) + '’s.',
              struckComment: null
            });
          });
          return;
        }

        var text = blocks.blocks[key].join('\n');
        var already = carriedTopText[key];
        if (already) {
          if (already.text === text) return;   // identical — carried once already
          if (/^x-/.test(key)) {
            var renamedKey = key + '-' + leaf(sd.name);
            var blockStart = blocks.starts[key], blockEnd = blockStart + blocks.blocks[key].length;
            renameTopKeyLineInDoc(doc, blockStart, blockEnd, renamedKey);
            var renamedBody = doc.lines.slice(blockStart, blockEnd);
            topAdditions.push('# From ' + leaf(sd.name));
            topAdditions = topAdditions.concat(renamedBody);
            changes.push({
              key: 'top-key|' + sd.name + '|' + key, topLevel: true, stack: sd.name, sourceLine: origBlocks.starts[key], marker: renamedBody[0],
              title: 'Renamed to keep it distinct',
              reason: 'Two source stacks both had a top-level "' + key + '", for different things — this one is now "' + renamedKey + '".',
              struckComment: null, cannotLeave: true   // two identical top-level keys would collide
            });
            return;
          }
          // A non-"x-" unknown key clashing is not something StaXX can
          // safely guess how to join, so the merge is refused rather than
          // silently picking one side.
          refusals.push({
            kind: 'top-level-key-clash', severity: 'refusal', stack: null,
            facts: { key: key, a: already.leaf, b: leaf(sd.name) },
            message: 'Both ' + already.leaf + ' and ' + leaf(sd.name) + ' define `' + key + '` at the top of their files and they differ; ' +
              'StaXX does not know how to join them. Make them the same, or remove one, and merge again.',
            lines: []
          });
          return;
        }

        carriedTopText[key] = { text: text, leaf: leaf(sd.name) };
        topAdditions.push('# From ' + leaf(sd.name));
        topAdditions = topAdditions.concat(blocks.blocks[key]);
      });
    });

    // Every per-source mutation is done now — safe to read back each
    // anchor's own FINAL text (an x- key clash can rename the very same
    // line a moment after the anchor rename touched it).
    pendingAnchorMarkers.forEach(function (p) { p.change.marker = p.doc.lines[p.atLine]; });

    var topStartFinal = finalLines.length;
    if (topAdditions.length) { finalLines = finalLines.concat(topAdditions); finalLines.push(''); }
    var topEndFinal = finalLines.length;
    changes.forEach(function (c) {
      if (!c.topLevel || c.line !== undefined) return;
      for (var i = topStartFinal; i < topEndFinal; i++) {
        if (finalLines[i] === c.marker) { c.line = i; c.resolved = true; break; }
      }
    });

    finalLines.push('services:');

    docs.forEach(function (sd) {
      var svcMap = servicesMapOf(sd.doc);
      if (!svcMap) return;
      var blocks = computeBlocks(sd.doc, svcMap);

      // A comment on no key at all is never inside any key's own span, so
      // computeBlocks() — which only ever walks a KEY's lead comment and
      // trailing gap — cannot see it, and it was silently dropped (PLAN_169
      // F5; CLAUDE.md rule 2). Two shapes of it exist: a file-header
      // comment sitting above this source's own `services:` line, and a
      // comment closing the source's last service — written one indent
      // deeper than any key, which compose-model.js therefore treats as
      // lying OUTSIDE the services: map's own span rather than as part of
      // it. Both are located here, once per source, and carried by hand.
      //
      // PLAN_179 P1 gave the header half of this one mechanism, shared with
      // the topAdditions pass above: fileHeaderLines() reads every line
      // before the file's own first top-level key, blank lines included,
      // so it is carried here only when "services:" truly IS that first
      // key — otherwise it already belongs to whichever earlier key (x-
      // unraid, version, ...) sits above it, and the topAdditions pass
      // already carried it there.
      var servicesPair = sd.doc.root.pairs['services'];
      var headerLines = sd.doc.root.keys[0] === 'services' ? fileHeaderLines(sd.doc) : [];
      var closingLines = [];
      if (servicesPair) {
        var rootKeys = sd.doc.root.keys;
        var svcIdx = rootKeys.indexOf('services');
        // No next top-level key: the file's own last line is the limit —
        // doc.root.end stops at the same place svcMap.end does (both
        // exclude this same orphaned comment), so it cannot be used here.
        var nextStart = sd.doc.lines.length;
        if (svcIdx >= 0 && svcIdx + 1 < rootKeys.length) {
          var nextPair = sd.doc.root.pairs[rootKeys[svcIdx + 1]];
          nextStart = leadStart(sd.doc.lines, nextPair.start, nextPair.indent);
        }
        closingLines = sd.doc.lines.slice(servicesPair.end, nextStart);
      }

      blocks.order.forEach(function (key, ki) {
        var block = blocks.blocks[key];
        var pIndent = svcMap.pairs[key].indent;
        var pad = new Array(pIndent + 1).join(' ');

        var blockStartFinal = finalLines.length;
        if (ki === 0 && headerLines.length) finalLines = finalLines.concat(headerLines);
        finalLines.push(pad + '# From ' + leaf(sd.name));
        finalLines = finalLines.concat(block);
        if (ki === blocks.order.length - 1 && closingLines.length) finalLines = finalLines.concat(closingLines);
        var blockEndFinal = finalLines.length;

        // Resolve any pending change against this exact block's own
        // finished text, by looking for the exact line the edit produced —
        // every change-producing edit leaves a real, distinctive line
        // behind (a rewritten value, or "ports: []" once the last entry is
        // gone), so there is always something here to find.
        changes.forEach(function (c) {
          if (c.line !== undefined) return;   // already resolved (the env-file changes above)
          if (c.stack !== sd.name) return;
          if (c.resolved) return;
          for (var i = blockStartFinal; i < blockEndFinal; i++) {
            if (finalLines[i] === c.marker) { c.line = i; c.resolved = true; break; }
          }
        });
      });
    });

    finalLines.push('');

    /* --- union the top-level declared blocks: volumes/networks/configs/secrets --- */

    var DECL_KINDS = ['volumes', 'networks', 'configs', 'secrets'];
    var storageByVol = {};   // "source/volume" -> the storage-carry finding
    exam.findings.forEach(function (f) {
      if (f.kind === 'storage-carry') storageByVol[f.stack + '/' + f.facts.volume] = f;
    });

    var declBlockStartFinal = finalLines.length;
    DECL_KINDS.forEach(function (kind) {
      var already = {};
      var additions = [];

      docs.forEach(function (sd) {
        var declMap = declMapOf(sd.doc, kind);
        if (!declMap) return;
        var blocks = computeBlocks(sd.doc, declMap);
        var incDeclared = sd.desc.compose[kind] || {};

        // Matched by PREFIX, never split('/') — see volumeCarryRenames's own
        // comment (this function, above) for why splitting a key built by
        // concatenation breaks the moment a source's own name (a store path
        // in a real merge) already holds a "/".
        var kindPrefix = sd.name + '/' + kind + '/';
        blocks.order.forEach(function (renamedKey) {
          var origKey = renamedKey;
          Object.keys(plan.declRenames).forEach(function (rk) {
            if (rk.indexOf(kindPrefix) === 0 && plan.declRenames[rk] === renamedKey) origKey = rk.slice(kindPrefix.length);
          });
          Object.keys(volumeCarryRenames).forEach(function (rk) {
            if (rk.indexOf(kindPrefix) === 0 && volumeCarryRenames[rk] === renamedKey) origKey = rk.slice(kindPrefix.length);
          });
          if (!incDeclared[origKey]) return;

          var mergedKey = renamedKey;
          var carry = kind === 'volumes' ? storageByVol[sd.name + '/' + origKey] : null;
          if (carry) {
            mergedKey = carry.facts.mergedKey;
            var decision = decisionValue(decisions, carry);
            if (decision === 'start-empty') {
              if (already[mergedKey]) return;
              already[mergedKey] = true;
              additions.push('  # From ' + leaf(sd.name));
              var emptyBody = blocks.blocks[renamedKey].slice();
              if (mergedKey !== origKey) renameBlockKeyLine(emptyBody, mergedKey);
              additions = additions.concat(emptyBody);
              return;
            }
            // 'carry' (the default): the source's own block, plus the two
            // required comment lines and the real name: override — see
            // PLAN_155's own YAML example.
            if (already[mergedKey]) return;
            already[mergedKey] = true;
            additions.push('  # From ' + leaf(sd.name));
            var body = blocks.blocks[renamedKey].slice();
            if (mergedKey !== origKey) renameBlockKeyLine(body, mergedKey);
            // The declared block's own key line is body[0] when it carries
            // no lead comment of its own — computeBlocks() includes any
            // lead comment as leading lines, so the key line itself is
            // whichever is NOT a comment/blank.
            var keyLineIdx = 0;
            while (keyLineIdx < body.length && lineKind(body[keyLineIdx]).kind !== 'other') keyLineIdx++;
            // The key's own line may carry an inline value ("dbdata: {}") —
            // that has to become a bare "dbdata:" before anything can be
            // nested under it, or the result is not valid YAML (a flow
            // value already closes the mapping entry on that line).
            var keyLine = body[keyLineIdx];
            var km = /^(\s*)([^:\s][^:]*):\s*(.*)$/.exec(keyLine);
            if (km && km[3].replace(/\s*#.*$/, '').trim() !== '') {
              body[keyLineIdx] = km[1] + km[2] + ':';
            }
            additions = additions.concat(body.slice(0, keyLineIdx + 1));
            additions.push('    # ' + leaf(sd.name) + '’s own storage, under the name Docker already knows it by.');
            additions.push('    # Only the label above is new; the data is untouched.');
            additions.push('    name: ' + carry.facts.realName);
            additions = additions.concat(body.slice(keyLineIdx + 1));
            return;
          }

          if (already[mergedKey]) return;
          already[mergedKey] = true;
          additions.push('  # From ' + leaf(sd.name));
          additions = additions.concat(blocks.blocks[renamedKey]);
        });
      });

      if (!additions.length) return;
      finalLines.push(kind + ':');
      finalLines = finalLines.concat(additions);
      finalLines.push('');
    });

    // A storage-carry's own DECLARATION line (its "Still <leaf>’s own storage"
    // change, pushed far above, before this volumes:/networks:/… block
    // even existed) is the one change kind never resolved by the
    // per-service marker search further up — it does not live in any
    // service's own lines, only here. Left unresolved, it never got a mark
    // in the merged pane at all (F7, PLAN_156 second walk): the heading
    // counted it as one of the changes, but nothing on screen ever showed
    // it. One pass over the whole range these four blocks just wrote,
    // after all of them (never inside the loop above) — a per-kind pass
    // would still be searching WHILE later kinds' own additions are being
    // built, and could match a line that has not been written yet.
    changes.forEach(function (c) {
      if (typeof c.line === 'number') return;
      for (var i = declBlockStartFinal; i < finalLines.length; i++) {
        if (finalLines[i] === c.marker) { c.line = i; break; }
      }
    });

    // A change marked `removed` still has its stand-in text sitting in
    // finalLines right now (the same "ports: []" the marker search above
    // just matched) — struck out of the actual written file here, in one
    // pass, once every change has a resolved position, rather than as each
    // one is found (a block still being assembled has no idea what index a
    // LATER block's own lines will land at, so removing one immediately
    // would leave every not-yet-resolved change pointing at the wrong
    // line). `removedText` is exactly the line that would have stayed had
    // this feature not been built — CLAUDE.md rule 2 requires it to be
    // said, not merely dropped — and `line` keeps meaning "insert a struck
    // ghost row before this index", since whatever line follows slides up
    // to occupy the position being vacated.
    changes.forEach(function (c) {
      if (!c.removed || c.file === 'env' || typeof c.line !== 'number') return;
      c.removedText = finalLines[c.line];
      finalLines.splice(c.line, 1);
      changes.forEach(function (other) {
        if (other !== c && other.file !== 'env' && typeof other.line === 'number' && other.line > c.line) other.line--;
      });
    });

    // PLAN_160 A: the confirmed link record for every address actually
    // rewired above, written now that finalLines holds every service (real
    // depends_on included) — the one point where compose-model's own
    // detectLinks() can tell a genuinely new pair from one a source already
    // declared. Same writer the editor's own crosslinks panel uses
    // (setLinkState), so the two can never disagree; a pair it refuses (an
    // existing depends_on already names it) is skipped silently, since the
    // editor would never ask about that pair either. setLinkState only ever
    // INSERTS lines (a fresh x-unraid.links entry, or the whole block where
    // none existed), so the shift below is exact: every change already
    // resolved to a line at or after the insertion point moves down by
    // however many lines were added.
    if (wiredLinks.length) {
      // No '+ \n' here: CM.parse just splits on '\n', so appending one would
      // hand back an extra trailing '' line that finalLines never had —
      // inflating insertedCount by one and shifting every change below the
      // insert one line too far (PLAN_178 F4).
      var linkDoc = CM.parse(finalLines.join('\n'));
      var declaredByPair = {};
      CM.detectLinks(CM.buildForm(linkDoc)).forEach(function (c) {
        if (c.kind !== 'reference') return;
        declaredByPair[c.between[0].service + '|' + (c.between[0].environment || '') + '|' + c.between[1].service] = c.certainty;
      });
      var seenWired = {};
      wiredLinks.forEach(function (w) {
        var lkey = w.fromService + '|' + w.envVar + '|' + w.toService;
        if (seenWired[lkey]) return;
        seenWired[lkey] = true;
        var between = [{ service: w.fromService, environment: w.envVar }, { service: w.toService }];
        CM.setLinkState(linkDoc, 'reference', declaredByPair[lkey] || 'inferred', between, 'confirmed');
      });
      var newLines = linkDoc.lines;
      if (newLines.length !== finalLines.length) {
        var insertStart = 0;
        while (insertStart < finalLines.length && finalLines[insertStart] === newLines[insertStart]) insertStart++;
        var insertedCount = newLines.length - finalLines.length;
        changes.forEach(function (c) { if (typeof c.line === 'number' && c.line >= insertStart) c.line += insertedCount; });
        finalLines = newLines;
      }
    }

    // Trim any trailing run of blank lines down to none — the file already
    // ends its last section with its own separating blank above.
    while (finalLines.length && /^\s*$/.test(finalLines[finalLines.length - 1])) finalLines.pop();

    var text = finalLines.join('\n') + '\n';

    // sourceLine is kept — it is the whole point of fix 5, the source-pane
    // mark's other half. `marker` is kept too (PLAN_178 F4's own audit
    // guard reads it back to confirm `line` still names the right text —
    // nothing in stacks.js reads it, so carrying it costs nothing); only
    // `resolved`/`topLevel`, meaningful solely to the search loops just
    // above, go.
    changes.forEach(function (c) { delete c.resolved; delete c.topLevel; });

    return {
      text: text,
      env: envText,
      files: filesOut,
      missingIcons: missingIcons,
      changes: changes,
      // The new stack's own LEAF — never its full rel, and never left for
      // the caller to re-derive from opts.name by hand.
      newProject: opts.name ? leaf(opts.name) : null,
      findings: exam.findings,
      refusals: refusals
    };
  }

  /* =====================================================================
   * The retirement rewrite, done in the browser (PLAN_155's own
   * "browser-to-server contract" section): every service in a retired
   * source's own file gets the "retired" profile, and one comment above
   * services: says why. Applying it twice is a no-op — the exact header
   * line, checked verbatim, is what makes that provable rather than
   * assumed.
   * ===================================================================== */

  function addRetiredProfile(doc, svcName) {
    var svcMap = servicesMapOf(doc);
    var p = svcMap && svcMap.pairs[svcName];
    if (!p) return;

    for (var i = p.start; i < p.end; i++) {
      var m = /^(\s*)profiles:\s*(\[.*\])?\s*(#.*)?$/.exec(doc.lines[i]);
      if (!m) continue;

      if (m[2]) {
        var listText = m[2];
        if (/(^|[\[,]\s*)['"]?retired['"]?\s*(,|\])/.test(listText)) return;
        var inner = listText.slice(1, -1).trim();
        var newInner = inner.length ? inner + ', "retired"' : '"retired"';
        doc.lines[i] = m[1] + 'profiles: [' + newInner + ']' + (m[3] ? ' ' + m[3] : '');
        return;
      }

      var indent = m[1].length, j = i + 1, already = false, lastItemLine = i;
      while (j < p.end) {
        var lm = /^(\s*)-\s*(.*)$/.exec(doc.lines[j]);
        if (!lm || lm[1].length <= indent) break;
        if (/^['"]?retired['"]?\s*$/.test(lm[2].replace(/\s*#.*$/, '').trim())) already = true;
        lastItemLine = j;
        j++;
      }
      if (already) return;
      var childPad = new Array(indent + 3).join(' ');
      CM.splice(doc, lastItemLine + 1, 0, [childPad + '- retired']);
      return;
    }

    // No profiles: key at all — add one as the service's own last key.
    var childPad2 = new Array(p.indent + 3).join(' ');
    CM.splice(doc, p.end, 0, [childPad2 + 'profiles: ["retired"]']);
  }

  function retireText(text, newName, date) {
    var doc = CM.parse(text);
    var svcPair = doc.root && doc.root.kind === 'map' ? doc.root.pairs['services'] : null;
    if (!svcPair) return text;

    var header = '# Retired into ' + newName + ', ' + date +
      ' — every service carries the "retired" profile so plain docker compose up starts nothing.';
    if (doc.lines[svcPair.start - 1] === header) return text;   // already retired with this exact stamp

    var names = servicesMapOf(doc).keys.slice();
    names.forEach(function (svcName) { addRetiredProfile(doc, svcName); });

    var atLine = doc.root.pairs['services'].start;
    CM.splice(doc, atLine, 0, [header]);

    return doc.bom + doc.lines.join(doc.eol || '\n');
  }

  var API = {
    descriptorFromText: descriptorFromText,
    buildMergedText: buildMergedText,
    retireText: retireText,
    // Exposed for tests — the block-splicing primitive on its own.
    computeBlocks: computeBlocks,
    // PLAN_155 C15 — merge-suggest.js's depends_on writer calls these two
    // for the exact same "must share a network" rule, on the one merged
    // doc where both ends already live together.
    serviceNetworkInfo: serviceNetworkInfo,
    joinNetworkIfNeeded: joinNetworkIfNeeded
  };

  if (typeof window !== 'undefined') window.StaxxMergeWrite = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
