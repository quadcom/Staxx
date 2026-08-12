/* Stack Manager — the compose file model.
 * Copyright 2026, Stack Manager contributors. GPL-2.0.
 *
 * Reads a compose file well enough to draw a form from it, and edits it well
 * enough to write one back without spoiling it. Plain browser JavaScript, no
 * libraries — Unraid ships no YAML parser for PHP either, so this is the only
 * place the work can happen.
 *
 * THE ONE IDEA THIS FILE IS BUILT ON
 *
 *   The document IS its array of source lines. Every node is a pair of indices
 *   into that array. Serialising is lines.join('\n'). There is no code path
 *   that rebuilds the file from the tree.
 *
 * That is what makes the project's second rule — never destroy a hand-authored
 * file — true by construction rather than by care. Comments, blank lines, key
 * order, quoting and indentation are not "preserved"; they are simply never
 * touched, because nothing ever regenerates the text they live in. An edit
 * replaces a span of characters inside one line and leaves the rest of that
 * line, including any comment after it, exactly where it was.
 *
 * WHAT IT REFUSES TO DO
 *
 * This is not a YAML parser and must not be mistaken for one. Anything it
 * cannot confidently rewrite — an anchor, an alias, a merge key, a block
 * scalar, a flow collection, a quoted string with escapes in it — is sealed as
 * an opaque region: recorded, never edited, and reported to the form so that
 * one field renders locked while its neighbours stay editable. Sealing is a
 * property of a VALUE and is never inherited by its siblings, which is what
 * keeps a single anchor in a long file from costing the whole form.
 *
 * Separate from stacks.js on purpose. That file is one large IIFE where a
 * single typo silently kills every behaviour on the page; this is the highest-
 * churn code in the plugin, and keeping it outside that blast radius means a
 * bad edit here costs the editor and leaves the table, menus and stats working.
 * It is also the only way to reach any of this from a Node test, since nothing
 * inside that IIFE is reachable from outside it.
 */

(function () {
  'use strict';

  /* =====================================================================
   * Line classification
   * ===================================================================== */

  // A key, in the three spellings compose files actually use. The key itself
  // may not contain a colon, so the match stops at the first one and
  // everything after it is the value — which is how a value like
  // "https://example.org/x" survives having colons of its own.
  var KEY_RE = /^(?:"((?:[^"\\]|\\.)*)"|'((?:[^']|'')*)'|([^:#\s][^:]*?))[ \t]*:(?:[ \t]+(.*)|[ \t]*)$/;

  // Openers that mean something other than "a plain scalar starts here".
  var SPECIAL_START = /^[&*!\[\]{}?@`%]/;

  function classify(line, at) {
    var indent = 0;
    while (indent < line.length && line.charAt(indent) === ' ') indent++;

    var rest = line.slice(indent);
    var out = { line: at, indent: indent, kind: 'other' };

    // A '\r' left by a CRLF file makes an otherwise blank line non-empty. It
    // stays in the text — normalising line endings is the server's job — so
    // trim before deciding.
    if (rest.replace(/[\s﻿]+/g, '') === '') { out.kind = 'blank'; return out; }
    if (rest.charAt(0) === '#') { out.kind = 'comment'; return out; }

    if (rest.charAt(0) === '-' && (rest.length === 1 || rest.charAt(1) === ' ')) {
      out.kind = 'seq';
      var c = indent + 1;
      while (c < line.length && line.charAt(c) === ' ') c++;
      out.contentCol = c;
      // The content of a '- ' line is classified in its own right, so
      // "- target: 15108" is understood as a mapping that happens to start
      // beside the dash.
      out.sub = c < line.length ? classifyAt(line, at, c) : null;
      return out;
    }

    var m = KEY_RE.exec(rest);
    if (m && !(m[3] !== undefined && SPECIAL_START.test(m[3]))) {
      out.kind = 'key';
      out.keyRaw = m[1] !== undefined ? '"' + m[1] + '"'
                 : m[2] !== undefined ? "'" + m[2] + "'"
                 : m[3];
      out.key = m[1] !== undefined ? m[1]
              : m[2] !== undefined ? m[2].replace(/''/g, "'")
              : m[3];
      // Where the value starts, if there is one on this line.
      var after = indent + out.keyRaw.length;
      while (after < line.length && line.charAt(after) !== ':') after++;
      after++;                                                  // past the colon
      while (after < line.length && (line.charAt(after) === ' ' || line.charAt(after) === '\t')) after++;
      // A '#' where the value would start is a comment, so the key has no
      // value and opens a nested block instead. Reading "db:  # the database"
      // as a key *with* a value silently swallowed every line indented under
      // it — the service parsed away to nothing and a rename could not see
      // the references inside it.
      var hasValue = m[4] !== undefined && m[4] !== '' && m[4].charAt(0) !== '#';
      out.valueCol = hasValue ? after : -1;
      return out;
    }

    return out;
  }

  // Classify the tail of a line as though `col` were its start, keeping the
  // real line number and real column offsets.
  function classifyAt(line, at, col) {
    var sub = classify(line.slice(col), at);
    if (sub.valueCol !== undefined && sub.valueCol >= 0) sub.valueCol += col;
    if (sub.contentCol !== undefined) sub.contentCol += col;
    sub.indent = col;
    return sub;
  }

  /* =====================================================================
   * Scalars
   * ===================================================================== */

  // Splits a plain scalar from the comment that may follow it. A '#' only
  // starts a comment when a space precedes it, which is what lets a value like
  // "colour#3" stay whole.
  function splitComment(text) {
    var at = -1;
    for (var i = 1; i < text.length; i++) {
      if (text.charAt(i) === '#' && (text.charAt(i - 1) === ' ' || text.charAt(i - 1) === '\t')) { at = i; break; }
    }
    if (at < 0) return { value: text, comment: '' };
    var body = text.slice(0, at);
    var keep = body.replace(/[ \t]+$/, '');
    return { value: keep, comment: text.slice(keep.length) };
  }

  function decodeQuoted(raw) {
    if (raw.charAt(0) === '"') return raw.slice(1, -1).replace(/\\(.)/g, '$1');
    if (raw.charAt(0) === "'") return raw.slice(1, -1).replace(/''/g, "'");
    return raw;
  }

  // Would this text mean something other than itself if written unquoted?
  //
  // Deliberately narrow. Quoting something that did not need it is not a
  // harmless tidy-up: it turns 128mb into '128mb' and a PEM header into a
  // quoted string, and either way the diff shows a line the user never
  // touched. So only the cases that genuinely change meaning are listed.
  function needsQuoting(v) {
    if (v === '') return true;
    if (/^\s|\s$/.test(v)) return true;
    // '-' and '?' only open something when a space follows. Inside a word —
    // -----BEGIN, well-known, 3-4 — they are ordinary text.
    if (/^[-?:](\s|$)/.test(v)) return true;
    if (/^[,\[\]{}#&*!|>'"%@`]/.test(v)) return true;
    if (/:\s/.test(v) || /\s#/.test(v)) return true;
    // A number written plainly is read as a number, which is what someone
    // typing a number into a number field means. Only the words that read as
    // something other than themselves are forced into quotes.
    if (/^(true|false|yes|no|on|off|null|~)$/i.test(v)) return true;
    return false;
  }

  // Renders a value back into source text.
  //
  // The rule that matters most here is that quoting is never REMOVED. A file
  // saying PUID: "99" means the ninety-nine to be a string; rewriting it as
  // PUID: 98 hands compose a number instead and changes the file's meaning
  // while looking like a harmless tidy-up.
  //
  // Returns null when the value cannot be written in a form this parser could
  // read back. Refusing beats writing something we would then have to seal.
  function emitScalar(value, style, asKey) {
    value = String(value);

    var hasD = value.indexOf('"') >= 0;
    var hasS = value.indexOf("'") >= 0;
    if (hasD && hasS) return null;

    if (hasD) return "'" + value.replace(/'/g, "''") + "'";
    if (style === 'single') return "'" + value.replace(/'/g, "''") + "'";
    if (style === 'double') return '"' + value + '"';
    // A key is read only as far as its first colon, so a colon inside one has
    // to be quoted even though the same text is perfectly safe as a value.
    if (needsQuoting(value) || (asKey && value.indexOf(':') >= 0)) {
      return hasS ? '"' + value + '"' : "'" + value.replace(/'/g, "''") + "'";
    }
    return value;
  }

  /* =====================================================================
   * The parser
   * ===================================================================== */

  function seal(ctx, start, end, reason) {
    var node = {
      kind: 'opaque',
      start: start,
      end: end,
      reason: reason,
      raw: ctx.lines.slice(start, end).join('\n')
    };
    ctx.sealed.push({ start: start, end: end, reason: reason });
    return node;
  }

  // How far a construct we are not going to touch reaches: this line, plus
  // every following line that is blank or indented deeper than its owner.
  // Trailing blanks are excluded, because a blank line separates siblings and
  // belongs to neither.
  function blockEnd(ctx, from, ownerIndent) {
    var last = from, i = from + 1;
    while (i < ctx.lines.length) {
      var c = ctx.cls[i];
      if (c.kind === 'blank') { i++; continue; }
      if (c.indent <= ownerIndent) break;
      last = i;
      i++;
    }
    return last + 1;
  }

  // Reads one value that begins at `col` on line `at`.
  function scanValue(ctx, at, col, ownerIndent, ownerKey) {
    var line = ctx.lines[at];
    var rest = line.slice(col);
    var head = rest.charAt(0);

    if (ownerKey === '<<') return seal(ctx, at, blockEnd(ctx, at, ownerIndent), 'merge');
    if (head === '|' || head === '>') return seal(ctx, at, blockEnd(ctx, at, ownerIndent), 'block-scalar');
    if (head === '&') return seal(ctx, at, blockEnd(ctx, at, ownerIndent), 'anchor');
    if (head === '*') return seal(ctx, at, blockEnd(ctx, at, ownerIndent), 'alias');
    if (head === '!') return seal(ctx, at, blockEnd(ctx, at, ownerIndent), 'tag');
    if (head === '[' || head === '{') return seal(ctx, at, blockEnd(ctx, at, ownerIndent), 'flow');

    if (head === '"' || head === "'") {
      var end = -1, i;
      if (head === '"') {
        for (i = 1; i < rest.length; i++) {
          if (rest.charAt(i) === '\\') { i++; continue; }
          if (rest.charAt(i) === '"') { end = i; break; }
        }
      } else {
        for (i = 1; i < rest.length; i++) {
          if (rest.charAt(i) === "'") {
            if (rest.charAt(i + 1) === "'") { i++; continue; }
            end = i; break;
          }
        }
      }
      if (end < 0) return seal(ctx, at, blockEnd(ctx, at, ownerIndent), 'multiline-scalar');

      var raw = rest.slice(0, end + 1);
      // An escape table is long and getting one entry wrong corrupts a value
      // silently, so a double-quoted string carrying any backslash is left
      // alone rather than half-understood.
      if (head === '"' && raw.indexOf('\\') >= 0) {
        return seal(ctx, at, at + 1, 'escape');
      }

      var tail = rest.slice(end + 1);
      if (tail.replace(/^[ \t]+/, '').charAt(0) !== '#' && tail.trim() !== '') {
        return seal(ctx, at, at + 1, 'unparsable');
      }

      return {
        kind: 'scalar', start: at, end: at + 1, line: at, col: col,
        raw: raw, value: decodeQuoted(raw),
        style: head === '"' ? 'double' : 'single',
        comment: tail, commentCol: col + end + 1
      };
    }

    var split = splitComment(rest);
    return {
      kind: 'scalar', start: at, end: at + 1, line: at, col: col,
      raw: split.value, value: split.value, style: 'plain',
      comment: split.comment, commentCol: col + split.value.length
    };
  }

  function significant(ctx, i) {
    while (i < ctx.lines.length && (ctx.cls[i].kind === 'blank' || ctx.cls[i].kind === 'comment')) i++;
    return i;
  }

  // The first line of the run of comments sitting directly above `at`. A blank
  // line stops the walk: a comment separated from a key by a blank line is
  // commenting on the section, not on that key, and must not be dragged along
  // when the key is removed.
  function leadOf(ctx, at, indent) {
    var i = at - 1;
    while (i >= 0 && ctx.cls[i].kind === 'comment' && ctx.cls[i].indent >= indent) i--;
    return i + 1;
  }

  // The key-shaped view of line `i` at column `col`, or null. A sequence item
  // may carry its first key beside the dash, which is the second case here.
  function keyAt(ctx, i, col, allowSub) {
    var c = ctx.cls[i];
    if (c.kind === 'key' && c.indent === col) return c;
    if (allowSub && c.kind === 'seq' && c.contentCol === col && c.sub && c.sub.kind === 'key') return c.sub;
    return null;
  }

  function parsePair(ctx, at, indent, k) {
    var pair = {
      kind: 'pair', key: k.key, keyRaw: k.keyRaw, indent: indent,
      start: at, end: at + 1, leadStart: leadOf(ctx, at, indent),
      value: null, comment: ''
    };

    if (k.valueCol >= 0) {
      pair.value = scanValue(ctx, at, k.valueCol, indent, k.key);
      pair.end = pair.value.end;
      if (pair.value.kind === 'scalar') pair.comment = pair.value.comment;
      return { node: pair, next: pair.end };
    }

    // Nothing after the colon, so the value is whatever follows.
    var j = significant(ctx, at + 1);
    if (j < ctx.lines.length) {
      var c = ctx.cls[j];
      // A sequence may sit at its key's own indent as well as deeper. Both are
      // legal, both appear in real files, and only the deeper one is obvious.
      if (c.kind === 'seq' && c.indent >= indent) {
        var s = parseSeq(ctx, j, c.indent);
        pair.value = s.node; pair.end = s.node.end;
        return { node: pair, next: s.next };
      }
      if (c.kind === 'key' && c.indent > indent) {
        var m = parseMap(ctx, j, c.indent, false);
        pair.value = m.node; pair.end = m.node.end;
        return { node: pair, next: m.next };
      }
      if (c.indent > indent) {
        // Something deeper that is neither a key nor an item.
        var to = blockEnd(ctx, at, indent);
        pair.value = seal(ctx, at + 1, to, 'unparsable');
        pair.end = to;
        return { node: pair, next: to };
      }
    }
    return { node: pair, next: at + 1 };     // a key with nothing under it
  }

  function parseMap(ctx, at, indent, allowSub) {
    var map = { kind: 'map', indent: indent, start: at, end: at + 1, keys: [], pairs: {} };
    var i = at, first = true;

    while (i < ctx.lines.length) {
      var j = significant(ctx, i);
      if (j >= ctx.lines.length) break;

      // No separate indent guard. keyAt already insists on the exact column,
      // and a guard on cls[j].indent would reject the first line of a mapping
      // that starts beside a dash — where the line's own indent is the dash's,
      // not the key's.
      var k = keyAt(ctx, j, indent, first && allowSub);
      if (!k) break;

      var p = parsePair(ctx, j, indent, k);
      if (map.pairs[p.node.key] === undefined) {
        map.keys.push(p.node.key);
        map.pairs[p.node.key] = p.node;
      } else {
        ctx.warnings.push({
          line: j,
          message: 'The key "' + p.node.key + '" appears more than once here. The first one is used.'
        });
      }
      map.end = p.node.end;
      // Never go backwards, and never stand still. This costs one comparison
      // and rules out a hung browser tab, which is the one failure mode of a
      // hand-written parser that a user cannot recover from.
      i = p.next > j ? p.next : j + 1;
      first = false;
    }
    return { node: map, next: i };
  }

  function parseItem(ctx, at, indent) {
    var c = ctx.cls[at];
    var item = {
      kind: 'item', indent: indent, contentCol: c.contentCol,
      start: at, end: at + 1, leadStart: leadOf(ctx, at, indent),
      value: null, comment: ''
    };

    if (!c.sub) {                       // a bare '-' with nothing after it
      item.value = null;
      return { node: item, next: at + 1 };
    }

    if (c.sub.kind === 'key') {
      var m = parseMap(ctx, at, c.contentCol, true);
      item.value = m.node; item.end = m.node.end;
      return { node: item, next: m.next };
    }

    item.value = scanValue(ctx, at, c.contentCol, indent, null);
    item.end = item.value.end;
    if (item.value.kind === 'scalar') item.comment = item.value.comment;
    return { node: item, next: item.end };
  }

  function parseSeq(ctx, at, indent) {
    var seq = { kind: 'seq', indent: indent, start: at, end: at + 1, items: [] };
    var i = at;

    while (i < ctx.lines.length) {
      var j = significant(ctx, i);
      if (j >= ctx.lines.length) break;
      var c = ctx.cls[j];
      if (c.kind !== 'seq' || c.indent !== indent) break;

      var it = parseItem(ctx, j, indent);
      seq.items.push(it.node);
      seq.end = it.node.end;
      i = it.next > j ? it.next : j + 1;      // same progress guard as parseMap
    }
    return { node: seq, next: i };
  }

  function parse(text) {
    text = String(text == null ? '' : text);

    var bom = '';
    if (text.charAt(0) === '﻿') { bom = '﻿'; text = text.slice(1); }

    var lines = text.split('\n');
    var starts = [], off = bom.length, i;
    for (i = 0; i < lines.length; i++) { starts.push(off); off += lines[i].length + 1; }

    var doc = {
      kind: 'doc', bom: bom, lines: lines, lineStart: starts,
      root: null, sealed: [], warnings: []
    };

    var ctx = { lines: lines, cls: [], sealed: doc.sealed, warnings: doc.warnings };
    for (i = 0; i < lines.length; i++) ctx.cls.push(classify(lines[i], i));

    // Three things seal the whole file rather than one value, because each of
    // them changes what every other line in it means.
    var whole = '';
    for (i = 0; i < lines.length && !whole; i++) {
      var lead = /^[ \t]*/.exec(lines[i])[0];
      if (lead.indexOf('\t') >= 0) whole = 'tab-indent';
      else if (/^%(YAML|TAG)\b/.test(lines[i])) whole = 'directive';
    }
    if (!whole) {
      var seenReal = false;
      for (i = 0; i < lines.length; i++) {
        var k = ctx.cls[i];
        if (k.kind === 'blank' || k.kind === 'comment') continue;
        if (/^(---|\.\.\.)\s*$/.test(lines[i])) {
          if (seenReal) { whole = 'multi-doc'; break; }
          continue;                                   // a leading --- is fine
        }
        seenReal = true;
      }
    }
    if (whole) {
      doc.root = seal(ctx, 0, lines.length, whole);
      return doc;
    }

    var j = significant(ctx, 0);
    if (j >= lines.length) {
      doc.root = { kind: 'map', indent: 0, start: 0, end: 0, keys: [], pairs: {} };
      return doc;
    }
    if (ctx.cls[j].kind !== 'key') {
      doc.root = seal(ctx, j, lines.length, 'unparsable');
      return doc;
    }
    doc.root = parseMap(ctx, j, ctx.cls[j].indent, false).node;
    return doc;
  }

  function serialise(doc) {
    return doc.bom + doc.lines.join('\n');
  }

  /* =====================================================================
   * Editing
   * ===================================================================== */

  // Every mutation goes through here, and every mutation ends in a full
  // re-parse. Files are a few hundred lines and the parser is a handful of
  // single forward walks, so it costs nothing measurable — and it removes the
  // entire class of bug where an insertion shifts the lines below it and every
  // range recorded against them silently goes stale.
  //
  // The price is one rule callers must keep: never hold a node across an edit.
  // The form holds field ids, which are derived from the binder and the
  // container-side target, and so survive a re-parse unchanged.
  function splice(doc, at, remove, insert) {
    var args = [at, remove].concat(insert || []);
    Array.prototype.splice.apply(doc.lines, args);
    var next = parse(doc.bom + doc.lines.join('\n'));
    doc.lines = next.lines;
    doc.lineStart = next.lineStart;
    doc.root = next.root;
    doc.sealed = next.sealed;
    doc.warnings = next.warnings;
    return doc;
  }

  // Replaces one scalar in place. Everything after it on the line — the run of
  // spaces and any comment — rides along in the tail slice untouched, which is
  // why keeping comments through an edit needs no code of its own.
  function writeScalar(doc, where, decoded, style) {
    var raw = emitScalar(decoded, style, where.isKey);
    if (raw === null) return false;

    var line = doc.lines[where.line];
    doc.lines[where.line] = line.slice(0, where.col) + raw + line.slice(where.col + where.len);
    splice(doc, 0, 0, []);                    // re-parse, no line count change
    return true;
  }

  /* =====================================================================
   * Binding the file to form fields
   * ===================================================================== */

  var SETTINGS = ['image', 'restart', 'network_mode', 'command', 'entrypoint',
                  'user', 'hostname', 'privileged', 'shm_size'];

  var LOCK_WORDS = {
    'merge':            'this comes from a shared block higher up the file',
    'anchor':           'this is a shared block other parts of the file reuse',
    'alias':            'this points at a shared block higher up the file',
    'tag':              'this carries a YAML tag',
    'block-scalar':     'this is written across several lines',
    'flow':             'this is written as a list on one line',
    'escape':           'this text contains escape characters',
    'multiline-scalar': 'this text runs over more than one line',
    'unparsable':       'this is written in a way the form cannot read',
    'tab-indent':       'this file is indented with tabs',
    'directive':        'this file uses a YAML directive',
    'multi-doc':        'this file holds more than one document'
  };

  function lockReason(code) {
    return LOCK_WORDS[code] || 'this is written in a way the form cannot read';
  }

  // Container port, normalised so that "8096", 8096 and "8096/tcp" are one
  // thing. Everything is matched on the container side; the host side is what
  // someone edits, and keying on it would break the binding on every change.
  function portKey(v) {
    var s = String(v).trim();
    var bits = s.split('/');
    var proto = (bits[1] || 'tcp').toLowerCase();
    return bits[0] + '/' + proto;
  }

  function scalarSpot(node) {
    return { line: node.line, col: node.col, len: node.raw.length, style: node.style };
  }

  // Where a mapping key sits on its own line. A variable's NAME is as much a
  // field as its value is — without this, adding one would produce a row that
  // could never be called anything.
  function keySpot(pair) {
    var q = pair.keyRaw.charAt(0);
    return {
      line: pair.start, col: pair.indent, len: pair.keyRaw.length,
      style: q === '"' ? 'double' : q === "'" ? 'single' : 'plain',
      isKey: true
    };
  }

  // One editable box. Writing it back is spot := pre + value + post, so a part
  // can address half of a scalar that holds two things — which is exactly what
  // "8096:8097" is.
  function part(value, spot, pre, post) {
    return { value: value, spot: spot || null, pre: pre || '', post: post || '' };
  }

  // A short-form port: [ip:]host:container[/proto], or a bare container port.
  // Both halves live in one scalar, so each part rebuilds the whole string
  // around the half it owns.
  function splitPortShort(text, spot) {
    var proto = '', body = text;
    var slash = body.lastIndexOf('/');
    if (slash > 0 && /^[a-zA-Z]+$/.test(body.slice(slash + 1))) {
      proto = body.slice(slash);
      body  = body.slice(0, slash);
    }

    var bits = body.split(':');
    if (bits.length === 1) {
      // Only a container port, so compose picks the host port itself.
      return {
        key: portKey(bits[0] + proto),
        host: part('', null),
        container: part(bits[0], spot, '', proto),
        hostNote: 'compose picks the host port for this one'
      };
    }

    var container = bits[bits.length - 1];
    var host      = bits[bits.length - 2];
    var lead      = bits.slice(0, bits.length - 2).join(':');
    var leadIn    = lead ? lead + ':' : '';

    return {
      key: portKey(container + proto),
      host:      part(host, spot, leadIn, ':' + container + proto),
      container: part(container, spot, leadIn + host + ':', proto)
    };
  }

  // A short-form volume or device: host:container[:mode], or a single path.
  function splitPathShort(text, spot) {
    var bits = text.split(':');
    if (bits.length === 1) {
      // An anonymous volume — docker manages the storage, so there is no host
      // path to show or change.
      return {
        key: bits[0],
        host: part('', null),
        container: part(bits[0], spot),
        hostNote: 'docker manages the storage for this one'
      };
    }

    var mode = '';
    if (bits.length > 2 && /^[a-zA-Z,]+$/.test(bits[bits.length - 1]) &&
        bits[bits.length - 1].charAt(0) !== '/') {
      mode = ':' + bits.pop();
    }
    var container = bits[bits.length - 1];
    var host      = bits.slice(0, bits.length - 1).join(':');

    return {
      key: container,
      mode: mode.slice(1),
      host:      part(host, spot, '', ':' + container + mode),
      container: part(container, spot, host + ':', mode)
    };
  }

  // One editable thing found in a service.
  function target(binder, key, opts) {
    var c = opts.comment || { secret: false, required: false, note: '' };
    return {
      binder: binder,
      target: key,
      parts: opts.parts || {},
      mode: opts.mode || '',
      range: opts.range,
      note: c.note,
      secret: c.secret,
      required: c.required,
      commentSpot: opts.commentSpot || null,
      raw: opts.raw || '',
      locked: !!opts.locked,
      lockReason: opts.lockReason || ''
    };
  }

  function lockedTarget(binder, key, range, reason, raw) {
    return target(binder, key, { range: range, locked: true, lockReason: reason, raw: raw || '' });
  }

  function harvestList(out, binder, pair, splitter, lines) {
    var v = pair.value;
    if (!v) return;
    if (v.kind === 'opaque') {
      out.push(lockedTarget(binder, '@' + pair.key,
        { start: pair.leadStart, end: pair.end }, lockReason(v.reason), v.raw));
      return;
    }
    if (v.kind !== 'seq') return;

    for (var i = 0; i < v.items.length; i++) {
      var it = v.items[i];
      var range = { start: it.leadStart, end: it.end };

      if (!it.value || it.value.kind === 'opaque') {
        out.push(lockedTarget(binder, '@' + pair.key + '#' + i, range,
          lockReason(it.value ? it.value.reason : 'unparsable'),
          it.value ? it.value.raw : ''));
        continue;
      }

      if (it.value.kind === 'map') { harvestLongForm(out, binder, it, range, lines); continue; }
      if (it.value.kind !== 'scalar') continue;

      var s = splitter(it.value.value, scalarSpot(it.value));
      out.push(target(binder, s.key, {
        parts: { host: s.host, container: s.container },
        mode: s.mode,
        range: range,
        comment: readComment(it.value.comment),
        commentSpot: commentSpot(it.value, lines),
        lockReason: s.hostNote || ''
      }));
    }
  }

  // The spelled-out forms: {target, published, protocol} for a port,
  // {type, source, target} for a volume. Here the two halves are separate
  // scalars on separate lines, so each part addresses its own.
  function harvestLongForm(out, binder, item, range, lines) {
    var map = item.value;
    var pick = function (k) {
      var p = map.pairs[k];
      return p && p.value && p.value.kind === 'scalar' ? p.value : null;
    };

    var tgt = pick('target');
    if (!tgt) return;

    // The note belongs to the entry as a whole, so it rides on the first line.
    var note = { comment: readComment(tgt.comment), spot: commentSpot(tgt, lines) };

    if (binder === 'port') {
      var pub = pick('published'), proto = pick('protocol');
      out.push(target('port', portKey(tgt.value + (proto ? '/' + proto.value : '')), {
        parts: {
          host:      part(pub ? pub.value : '', pub ? scalarSpot(pub) : null),
          container: part(tgt.value, scalarSpot(tgt))
        },
        range: range, comment: note.comment, commentSpot: note.spot,
        lockReason: pub ? '' : 'no host port is set here'
      }));
      return;
    }

    var src = pick('source'), ro = pick('read_only');
    out.push(target(binder, tgt.value, {
      parts: {
        host:      part(src ? src.value : '', src ? scalarSpot(src) : null),
        container: part(tgt.value, scalarSpot(tgt))
      },
      mode: ro && /^(true|yes)$/i.test(ro.value) ? 'ro' : '',
      range: range, comment: note.comment, commentSpot: note.spot,
      lockReason: src ? '' : 'this mount has no source path to edit'
    }));
  }

  // environment and labels, which compose accepts as either a mapping or a
  // list of NAME=value strings.
  function harvestPairs(out, binder, pair, lines) {
    var v = pair.value;
    if (!v) return;

    if (v.kind === 'opaque') {
      out.push(lockedTarget(binder, '@' + pair.key,
        { start: pair.leadStart, end: pair.end }, lockReason(v.reason), v.raw));
      return;
    }

    var i;
    if (v.kind === 'map') {
      for (i = 0; i < v.keys.length; i++) {
        var p = v.pairs[v.keys[i]];
        var range = { start: p.leadStart, end: p.end };
        if (!p.value || p.value.kind !== 'scalar') {
          out.push(lockedTarget(binder, p.key, range,
            lockReason(p.value ? p.value.reason : 'unparsable'),
            p.value ? p.value.raw : ''));
          continue;
        }
        out.push(target(binder, p.key, {
          parts: {
            name:  part(p.key, keySpot(p)),
            value: part(p.value.value, scalarSpot(p.value))
          },
          range: range,
          comment: readComment(p.value.comment),
          commentSpot: commentSpot(p.value, lines)
        }));
      }
      return;
    }

    if (v.kind !== 'seq') return;
    for (i = 0; i < v.items.length; i++) {
      var it = v.items[i];
      var r = { start: it.leadStart, end: it.end };
      if (!it.value || it.value.kind !== 'scalar') {
        out.push(lockedTarget(binder, '@' + pair.key + '#' + i, r,
          lockReason(it.value ? it.value.reason : 'unparsable'),
          it.value ? it.value.raw : ''));
        continue;
      }
      var eq   = it.value.value.indexOf('=');
      var name = eq < 0 ? it.value.value : it.value.value.slice(0, eq);
      var val  = eq < 0 ? '' : it.value.value.slice(eq + 1);
      var spot = scalarSpot(it.value);

      // "- FOO" with no "=" is compose's "pass this one through from the
      // server's own environment". There is no value here to edit, and writing
      // one back as "FOO=" would change what the line means.
      out.push(target(binder, name, {
        parts: eq < 0
          ? { name: part(name, spot), value: part('', null) }
          : { name:  part(name, spot, '', '=' + val),
              value: part(val, spot, name + '=') },
        range: r,
        comment: readComment(it.value.comment),
        commentSpot: commentSpot(it.value, lines),
        lockReason: eq < 0 ? 'this one takes its value from the server’s environment' : ''
      }));
    }
  }

  function harvest(serviceMap, lines) {
    var out = [], i, p;

    var listKeys = { ports: 'port', volumes: 'volume', devices: 'device' };
    var pairKeys = { environment: 'env', labels: 'label' };

    for (i = 0; i < serviceMap.keys.length; i++) {
      var key = serviceMap.keys[i];
      p = serviceMap.pairs[key];

      if (listKeys[key]) {
        harvestList(out, listKeys[key], p, key === 'ports' ? splitPortShort : splitPathShort, lines);
        continue;
      }
      if (pairKeys[key]) { harvestPairs(out, pairKeys[key], p, lines); continue; }

      if (SETTINGS.indexOf(key) >= 0) {
        var range = { start: p.leadStart, end: p.end };
        if (p.value && p.value.kind === 'scalar') {
          out.push(target('setting', key, {
            parts: { value: part(p.value.value, scalarSpot(p.value)) },
            range: range,
            comment: readComment(p.value.comment),
            commentSpot: commentSpot(p.value, lines)
          }));
        } else {
          // A command spelled out as a list of arguments is a perfectly
          // ordinary thing to find, so say that rather than falling back on
          // "the form cannot read this", which sounds like the file is wrong.
          var why = !p.value ? 'this setting has no value'
                  : p.value.kind === 'seq' ? 'this is written as a list of separate items'
                  : p.value.kind === 'map' ? 'this is written as a block of its own'
                  : lockReason(p.value.reason);
          out.push(lockedTarget('setting', key, range, why,
            p.value ? (p.value.raw || '') : ''));
        }
      }
    }
    return out;
  }

  /* ---- metadata ---------------------------------------------------------- */

  function flatOf(map) {
    var out = {};
    if (!map || map.kind !== 'map') return out;
    for (var i = 0; i < map.keys.length; i++) {
      var p = map.pairs[map.keys[i]];
      if (!p.value) continue;
      if (p.value.kind === 'scalar') { out[p.key] = p.value.value; continue; }
      // A description written as a block is still worth showing, even though
      // it is never written back. A wrong guess here costs a rendering, not a
      // file.
      if (p.value.kind === 'opaque' && p.value.reason === 'block-scalar') {
        out[p.key] = p.value.raw.split('\n').slice(1)
                       .map(function (l) { return l.replace(/^\s{0,20}/, ''); })
                       .join('\n').replace(/^\n+|\n+$/g, '');
      }
    }
    return out;
  }

  /* ---- the comment on a line is that field's note ------------------------
   *
   * There is deliberately no per-field block in x-unraid. Restating a port
   * that is already declared six lines above, purely to hang a title on it, is
   * duplication that can only ever drift out of step with the thing it
   * describes. The file's own comment says what the value is for, which is
   * what a comment is already there to do — so the form shows it, and typing
   * in the form writes it back as that comment.
   *
   * Two markers ride at the END of it, after the prose, where they stay out of
   * the way of the sentence someone actually wrote:
   *
   *     ADMIN_PASSWORD: hunter2   # the login password -!S
   *     WEBUI_PORT: "8096"        # the page you open -!R
   *
   *   -!S  this value is a secret — hide it when Sanitise is on
   *   -!R  this value must not be left empty
   *
   * These are the only two judgements that cannot be worked out from the file
   * itself, which is why they are the only two written down. Nothing is ever
   * guessed from a variable's name.
   */

  var MARKERS = { S: 'secret', R: 'required' };
  var MARKER_RE = /[ \t]*-!([SR])$/;

  function readComment(raw) {
    var out = { secret: false, required: false, note: '' };
    if (!raw) return out;

    var hash = raw.indexOf('#');
    if (hash < 0) return out;

    var body = raw.slice(hash + 1).replace(/^[ \t]+/, '').replace(/[ \t]+$/, '');

    // Peeled off the end one at a time, so the order they were written in does
    // not matter and either may appear alone.
    var m;
    while ((m = MARKER_RE.exec(body))) {
      out[MARKERS[m[1]]] = true;
      body = body.slice(0, m.index);
    }

    out.note = body.replace(/[ \t]+$/, '');
    return out;
  }

  function emitComment(note, secret, required, pad) {
    var flags = (secret ? ' -!S' : '') + (required ? ' -!R' : '');
    if (!note && !flags) return '';
    return (pad || '  ') + '# ' + (String(note || '') + flags).replace(/^[ \t]+/, '');
  }

  // Where a line's comment sits, and where one would go if it had none.
  function commentSpot(node, lines) {
    if (!node || node.kind !== 'scalar') return null;
    var line = lines[node.line];
    var raw  = node.comment || '';
    var col  = raw ? node.commentCol : line.length;
    var pad  = raw ? /^[ \t]*/.exec(raw)[0] : '  ';
    return { line: node.line, col: col, len: line.length - col, pad: pad };
  }

  /* ---- inference --------------------------------------------------------- */

  function humanise(text) {
    var words = String(text).split(/[\/_\-.]+/).filter(Boolean).map(function (w) {
      if (/^[A-Z0-9]+$/.test(w) && w.length <= 5) return w;
      return w.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
              .split(/\s+/)
              .map(function (s) { return s.charAt(0).toUpperCase() + s.slice(1); })
              .join(' ');
    });
    return words.join(' ');
  }

  function inferTitle(t) {
    if (t.binder === 'port') return 'Port ' + t.target.split('/')[0];
    if (t.target.charAt(0) === '@') return humanise(t.target.slice(1).split('#')[0]);
    return humanise(t.target);
  }

  function inferType(t) {
    if (t.binder === 'port') return 'port';
    if (t.binder === 'volume' || t.binder === 'device') return 'path';
    if (t.binder === 'setting' && t.target === 'privileged') return 'boolean';
    var v = t.parts.value ? t.parts.value.value : '';
    if (/^(true|false)$/i.test(v)) return 'boolean';
    if (/^-?\d+$/.test(v)) return 'number';
    return 'text';
  }

  /* ---- putting a service's fields together ------------------------------- */

  // Fields come out in the order the file has them, and everything about them
  // beyond the value is either worked out from the file or read from the
  // comment beside it. There is nothing to match up, so there is nothing to
  // fall out of step.
  function fieldsFor(serviceName, serviceMap, lines) {
    var targets = harvest(serviceMap, lines);
    var fields = [];

    for (var i = 0; i < targets.length; i++) {
      var t = targets[i];
      var hasHost = !!(t.parts.host && t.parts.host.spot);
      // A writable name counts. Without it a "- FOO" pass-through row, whose
      // only editable box IS its name, would render locked.
      var single  = !!(t.parts.value && t.parts.value.spot) ||
                    !!(t.parts.name  && t.parts.name.spot);
      var usable  = hasHost || single || !!(t.parts.container && t.parts.container.spot);

      fields.push({
        id: serviceName + '/' + t.binder + '/' + t.target,
        service: serviceName,
        binder: t.binder,
        target: t.target,
        title: inferTitle(t),
        type: inferType(t),
        mode: t.mode,
        parts: t.parts,
        note: t.note,
        sensitive: t.secret,
        required: t.required,
        commentSpot: t.commentSpot,
        raw: t.raw,
        range: t.range,
        locked: t.locked || !usable,
        lockReason: t.lockReason ||
                    (!usable ? 'this has no value the form can edit' : '')
      });
    }
    return fields;
  }

  function buildForm(doc) {
    var out = { stack: {}, services: [], fields: [], warnings: [], sealed: doc.sealed, ok: true };

    if (!doc.root || doc.root.kind !== 'map') {
      out.ok = false;
      out.warnings.push({
        line: 0,
        message: 'This file is written in a way the form cannot read (' +
                 lockReason(doc.root ? doc.root.reason : 'unparsable') +
                 '). Use the Compose view to edit it.'
      });
      return out;
    }

    var xs = doc.root.pairs['x-unraid'];
    out.stack = xs && xs.value ? flatOf(xs.value) : {};

    var svc = doc.root.pairs['services'];
    if (!svc || !svc.value || svc.value.kind !== 'map') {
      out.warnings.push({ line: 0, message: 'This file lists no services.' });
      return out;
    }

    for (var i = 0; i < svc.value.keys.length; i++) {
      var name = svc.value.keys[i];
      var p = svc.value.pairs[name];
      if (!p.value || p.value.kind !== 'map') {
        out.services.push({
          name: name, display: 'basic',
          range: { start: p.leadStart, end: p.end },
          shared: false, count: 0, readable: false,
          note: 'This service is written in a way the form cannot read (' +
                lockReason(p.value ? p.value.reason : 'unparsable') + ').'
        });
        continue;
      }

      var meta = p.value.pairs['x-unraid'];
      var mx = meta && meta.value ? flatOf(meta.value) : {};

      // A service built on an anchor honestly has fewer fields than it looks
      // like it should, because the shared block is sealed. Saying so turns a
      // confusing gap into an explanation.
      var shared = false;
      for (var k = 0; k < p.value.keys.length; k++) {
        var vv = p.value.pairs[p.value.keys[k]].value;
        if (vv && vv.kind === 'opaque' && (vv.reason === 'merge' || vv.reason === 'alias')) shared = true;
      }

      var fields = fieldsFor(name, p.value, doc.lines);
      out.fields = out.fields.concat(fields);
      out.services.push({
        name: name,
        overview: mx.overview || '',
        icon: mx.icon || '',
        webui: mx.webui || '',
        display: mx.display === 'advanced' ? 'advanced' : 'basic',
        range: { start: p.leadStart, end: p.end },
        shared: shared,
        count: fields.length,
        readable: true,
        note: shared
          ? 'Some of this service’s settings come from a shared block higher up ' +
            'the file and can’t be edited here.'
          : ''
      });
    }

    out.index = buildIndex(out);
    return out;
  }

  /* =====================================================================
   * Mapping between the two panes
   * ===================================================================== */

  // Each field owns exactly one span of the file — which it did not, while
  // x-unraid restated compose keys and a field lived in two places at once.
  function buildIndex(form) {
    var idx = [];
    for (var i = 0; i < form.fields.length; i++) {
      var f = form.fields[i];
      if (f.range) idx.push({ start: f.range.start, end: f.range.end, id: f.id });
    }
    idx.sort(function (a, b) {
      return a.start - b.start || (a.end - a.start) - (b.end - b.start);
    });
    return idx;
  }

  function lineAtOffset(doc, offset) {
    var lo = 0, hi = doc.lineStart.length - 1;
    while (lo < hi) {
      var mid = (lo + hi + 1) >> 1;
      if (doc.lineStart[mid] <= offset) lo = mid; else hi = mid - 1;
    }
    return lo;
  }

  // The smallest span containing the line wins, so a field inside a service
  // beats the service itself.
  function fieldAtLine(form, line) {
    var best = null, bestSpan = Infinity;
    for (var i = 0; i < form.index.length; i++) {
      var e = form.index[i];
      if (e.start > line) break;
      if (line >= e.end) continue;
      var span = e.end - e.start;
      if (span <= bestSpan) { bestSpan = span; best = e.id; }
    }
    return best;
  }

  function serviceAtLine(form, line) {
    for (var i = 0; i < form.services.length; i++) {
      var s = form.services[i];
      if (s.range && line >= s.range.start && line < s.range.end) return s.name;
    }
    return null;
  }

  function fieldById(form, id) {
    for (var i = 0; i < form.fields.length; i++) {
      if (form.fields[i].id === id) return form.fields[i];
    }
    return null;
  }

  // Writes one box back into the document. `which` is 'value' for a plain
  // setting, or 'host' / 'container' for the two halves of a port or a mount.
  // Returns false when the box cannot be written — a refusal, not a failure,
  // and the form says so rather than guessing.
  function setPart(doc, form, id, which, value) {
    var f = fieldById(form, id);
    if (!f || f.locked) return false;
    var p = f.parts[which];
    if (!p || !p.spot) return false;
    return writeScalar(doc, p.spot, p.pre + value + p.post, p.spot.style);
  }

  // Kept for the common single-box case, and for the tests.
  function setValue(doc, form, id, value) {
    return setPart(doc, form, id, 'value', value);
  }

  // Writes the note beside a value along with both markers. Clearing all three
  // removes the comment altogether rather than leaving a bare '#' behind.
  function setComment(doc, form, id, note, secret, required) {
    var f = fieldById(form, id);
    if (!f || !f.commentSpot) return false;

    var at   = f.commentSpot;
    var line = doc.lines[at.line];
    var head = line.slice(0, at.col).replace(/[ \t]+$/, '');
    doc.lines[at.line] = head +
      emitComment(String(note || '').trim(), !!secret, !!required, at.pad);
    splice(doc, 0, 0, []);
    return true;
  }

  /* =====================================================================
   * Adding and removing entries
   * =====================================================================
   *
   * The rule that shapes all of this: removing the LAST entry of a list has to
   * remove the list's key too, because a bare "ports:" is null and compose
   * rejects the file. Which means the control that adds one back cannot live
   * on the list — it would disappear along with it. It lives on the service,
   * and addItem() below writes the key and its first entry together when the
   * key is not there.
   */

  var LIST_KEY = {
    port: 'ports', volume: 'volumes', device: 'devices',
    env: 'environment', label: 'labels'
  };

  function pad(n) {
    return n > 0 ? new Array(n + 1).join(' ') : '';
  }

  /** The pair holding one service's block, or null if it cannot be read. */
  function serviceMapOf(doc, name) {
    if (!doc.root || doc.root.kind !== 'map') return null;
    var svc = doc.root.pairs['services'];
    if (!svc || !svc.value || svc.value.kind !== 'map') return null;
    var p = svc.value.pairs[name];
    return p && p.value && p.value.kind === 'map' ? p : null;
  }

  // A name not already in use, so that two adds in a row cannot produce two
  // fields sharing one id.
  function freeName(taken, base, joiner) {
    if (taken.indexOf(base) < 0) return base;
    for (var n = 2; n < 200; n++) {
      if (taken.indexOf(base + (joiner || '') + n) < 0) return base + (joiner || '') + n;
    }
    return base + (joiner || '') + '-new';
  }

  // `value` is an entry the caller already knows it wants — the device picker
  // supplying a real device rather than accepting the placeholder below. It is
  // used verbatim, because inventing a unique variant of a chosen device would
  // produce a path to hardware that does not exist. Whether it duplicates
  // something already in the file is the caller's question, since only the
  // caller knows what to say about it.
  function newEntry(binder, taken, service, shape, value) {
    if (typeof value === 'string' && value !== '') return value;

    if (binder === 'port') {
      var n = 8080;
      while (taken.indexOf(n + '/tcp') >= 0) n++;
      // Always quoted. Unquoted, YAML 1.1 reads 22:22 as a sexagesimal number
      // and compose is handed 1342 — the classic way a hand-written compose
      // file goes wrong, and not one to reintroduce from this side.
      return '"' + n + ':' + n + '"';
    }
    if (binder === 'volume') {
      return '/mnt/user/appdata/' + service + ':' + freeName(taken, '/data');
    }
    if (binder === 'device') {
      var d = freeName(taken, '/dev/dri');
      return d + ':' + d;
    }
    // A plain word rather than "". Quoting is never removed once it is there,
    // so an empty double-quoted placeholder would leave every variable added
    // through the form quoted for the rest of the file's life.
    var name = freeName(taken, binder === 'label' ? 'com.example.label' : 'NEW_VARIABLE', '_');
    return name + (shape === 'map' ? ': value' : '=value');
  }

  /**
   * Add one entry to a service's list, creating the list if it is not there.
   * Returns the line the new entry landed on, or -1 if it was refused.
   *
   * `value` is optional and, when given, is written instead of a placeholder.
   */
  function addItem(doc, form, service, binder, value) {
    var key = LIST_KEY[binder];
    if (!key) return -1;

    var svc = serviceMapOf(doc, service);
    if (!svc) return -1;

    var taken = [], i;
    for (i = 0; i < form.fields.length; i++) {
      if (form.fields[i].service === service && form.fields[i].binder === binder) {
        taken.push(form.fields[i].target);
      }
    }

    var pair = svc.value.pairs[key];
    var v    = pair ? pair.value : null;

    // Anything we could not read, we do not write into either.
    if (v && (v.kind === 'opaque' || v.kind === 'scalar')) return -1;

    if (v && v.kind === 'seq') {
      // Copy the gap after the dash from the last item, so a file written as
      // "-   /data" stays written that way.
      var gap = ' ';
      if (v.items.length) {
        var last = v.items[v.items.length - 1];
        gap = pad(Math.max(1, last.contentCol - last.indent - 1));
      }
      splice(doc, v.end, 0,
             [pad(v.indent) + '-' + gap + newEntry(binder, taken, service, 'seq', value)]);
      return v.end;
    }

    if (v && v.kind === 'map') {
      splice(doc, v.end, 0, [pad(v.indent) + newEntry(binder, taken, service, 'map', value)]);
      return v.end;
    }

    if (pair) {                       // "ports:" with nothing under it
      var at = pair.end;
      splice(doc, at, 0,
             [pad(pair.indent + 2) + '- ' + newEntry(binder, taken, service, 'seq', value)]);
      return at;
    }

    // The key is not there at all, so write it and its first entry together —
    // the file is never left holding a key with nothing under it.
    //
    // Positioned after the last key that is NOT x-unraid. That block sits last
    // in every file in the corpus, and a new volumes: landing underneath the
    // metadata would read as part of it.
    var indent = svc.value.indent;
    var after  = null;
    for (i = 0; i < svc.value.keys.length; i++) {
      if (svc.value.keys[i] === 'x-unraid') continue;
      after = svc.value.pairs[svc.value.keys[i]];
    }

    var to    = after ? after.end : svc.value.start + 1;
    var lines = [];

    // Match the file's own habit: if the key above this one is separated from
    // its neighbour by a blank line, the new block gets one too.
    if (after && after.leadStart > 0 && doc.lines[after.leadStart - 1] !== undefined &&
        doc.lines[after.leadStart - 1].trim() === '') {
      lines.push('');
    }

    lines.push(pad(indent) + key + ':');
    lines.push(binder === 'env' || binder === 'label'
      ? pad(indent + 2) + newEntry(binder, taken, service, 'map', value)
      : pad(indent + 2) + '- ' + newEntry(binder, taken, service, 'seq', value));

    splice(doc, to, 0, lines);
    return to + lines.length - 1;
  }

  /**
   * Remove one entry, and the list's key with it when it was the only one.
   * Returns false when the entry is sealed and cannot safely be touched.
   */
  function removeItem(doc, form, id) {
    var f = fieldById(form, id);
    if (!f || !f.range) return false;

    var key = LIST_KEY[f.binder];
    if (!key) return false;                     // a plain setting is not a list

    var svc = serviceMapOf(doc, f.service);
    if (!svc) return false;

    var pair = svc.value.pairs[key];
    if (!pair || !pair.value || pair.value.kind === 'opaque') return false;

    var v = pair.value;
    var count = v.kind === 'seq' ? v.items.length
              : v.kind === 'map' ? v.keys.length : 0;
    if (!count) return false;

    // The last entry takes its key with it. Not tidiness: "ports:" with
    // nothing under it is null, and compose refuses to read the file.
    if (count === 1) {
      var from = pair.leadStart, to = pair.end;

      // Taking a whole block out of a file that separates its blocks with
      // blank lines leaves two blanks where there was one — or a stray blank
      // at the end when the block was last. Collapse them, but only when there
      // is genuinely one on each side, so a blank the user put there on
      // purpose is never the one that goes.
      if (from > 0 &&
          (doc.lines[from - 1] || '').trim() === '' &&
          (doc.lines[to] || '').trim() === '') {
        from--;
      }

      splice(doc, from, to - from, []);
      return true;
    }

    // range.start is the entry's leadStart, so it takes its own description
    // comment along rather than orphaning it above the next entry.
    splice(doc, f.range.start, f.range.end - f.range.start, []);
    return true;
  }

  /* =====================================================================
   * Renaming a service
   * =====================================================================
   *
   * A service's name is the key under `services:`, so the rename itself is
   * one writeScalar on that key. The rest of this is finding every other
   * place compose lets one service name another — depends_on, links,
   * volumes_from, network_mode and an in-file extends — and rewriting only
   * the part of each that is the name, leaving any alias, mode or condition
   * beside it untouched. Anything sealed (an anchor, alias or flow list) is
   * left alone, same as everywhere else in this file: guessing inside an
   * opaque region is how a working file gets corrupted.
   */

  var SERVICE_NAME_RE = /^[a-zA-Z0-9._-]+$/;
  var LINK_LIKE_KEYS = ['links', 'volumes_from'];

  // Appends a {spot, decoded} edit for every reference to oldName found
  // among the five keys a rename must follow, inside one service's map.
  function collectServiceRefs(edits, svcMap, oldName, newName) {
    var dep = svcMap.pairs['depends_on'];
    if (dep && dep.value) {
      if (dep.value.kind === 'seq') {
        for (var i = 0; i < dep.value.items.length; i++) {
          var v = dep.value.items[i].value;
          if (v && v.kind === 'scalar' && v.value === oldName) {
            edits.push({ spot: scalarSpot(v), decoded: newName });
          }
        }
      } else if (dep.value.kind === 'map' && dep.value.pairs[oldName]) {
        // The map form means the service name is a KEY ("db:" with
        // "condition:" under it), not a value.
        edits.push({ spot: keySpot(dep.value.pairs[oldName]), decoded: newName });
      }
    }

    // links and volumes_from share a shape: a bare name, or "name:alias" /
    // "name:mode" — only the part before the colon is the service name.
    for (var lk = 0; lk < LINK_LIKE_KEYS.length; lk++) {
      var p = svcMap.pairs[LINK_LIKE_KEYS[lk]];
      if (!p || !p.value || p.value.kind !== 'seq') continue;
      for (var j = 0; j < p.value.items.length; j++) {
        var iv = p.value.items[j].value;
        if (!iv || iv.kind !== 'scalar') continue;
        var colon = iv.value.indexOf(':');
        var name = colon < 0 ? iv.value : iv.value.slice(0, colon);
        if (name === oldName) {
          edits.push({ spot: scalarSpot(iv), decoded: newName + (colon < 0 ? '' : iv.value.slice(colon)) });
        }
      }
    }

    var nm = svcMap.pairs['network_mode'];
    if (nm && nm.value && nm.value.kind === 'scalar' &&
        nm.value.value.indexOf('service:') === 0 && nm.value.value.slice(8) === oldName) {
      edits.push({ spot: scalarSpot(nm.value), decoded: 'service:' + newName });
    }

    // An in-file extends: a "service:" reference with no "file:" beside it.
    // One naming "file:" is reaching into another compose file, which this
    // rename cannot follow and must not guess at.
    var ext = svcMap.pairs['extends'];
    if (ext && ext.value && ext.value.kind === 'map' && !ext.value.pairs['file']) {
      var es = ext.value.pairs['service'];
      if (es && es.value && es.value.kind === 'scalar' && es.value.value === oldName) {
        edits.push({ spot: scalarSpot(es.value), decoded: newName });
      }
    }
  }

  // Rewrites every collected spot in one pass. Every construct a rename
  // touches is one per line in practice, but grouping by line and working
  // right-to-left within it is what keeps that true even if it ever is not —
  // rewriting a scalar changes the line's length, which would shift every
  // column after it if applied left-to-right against stale positions.
  function applyRenameWrites(doc, writes) {
    var byLine = {}, i;
    for (i = 0; i < writes.length; i++) {
      var w = writes[i];
      (byLine[w.spot.line] = byLine[w.spot.line] || []).push(w);
    }
    for (var line in byLine) {
      var list = byLine[line];
      list.sort(function (a, b) { return b.spot.col - a.spot.col; });
      var text = doc.lines[line];
      for (i = 0; i < list.length; i++) {
        var s = list[i].spot;
        text = text.slice(0, s.col) + list[i].raw + text.slice(s.col + s.len);
      }
      doc.lines[line] = text;
    }
    splice(doc, 0, 0, []);          // one re-parse for the whole batch
  }

  /**
   * Renames a service and every depends_on / links / volumes_from /
   * network_mode / in-file-extends reference to it.
   *
   * -> { ok: true,  refs: <references rewritten, not counting the key> }
   * -> { ok: false, error: '<what to do next>' }
   *
   * Mutates doc in place on success. On refusal doc is untouched, because
   * every check below runs — and every replacement is computed — before the
   * first byte is written.
   */
  function renameService(doc, oldName, newName) {
    if (!newName || !SERVICE_NAME_RE.test(newName)) {
      return { ok: false, error: 'Service names may only contain letters, numbers, dots, underscores and hyphens — choose a different name.' };
    }
    if (!doc.root || doc.root.kind !== 'map') {
      return { ok: false, error: 'This file cannot be read as compose, so nothing can be renamed.' };
    }
    var svc = doc.root.pairs['services'];
    if (!svc || !svc.value || svc.value.kind !== 'map') {
      return { ok: false, error: 'This file lists no services, so there is nothing to rename.' };
    }
    var topPair = svc.value.pairs[oldName];
    if (!topPair) {
      return { ok: false, error: 'There is no service called "' + oldName + '" in this file, so it cannot be renamed.' };
    }
    // A rename to its own name is a no-op success — checked before the
    // collision test below, which would otherwise refuse it for colliding
    // with itself.
    if (newName === oldName) return { ok: true, refs: 0 };
    if (svc.value.pairs[newName]) {
      return { ok: false, error: 'A service called "' + newName + '" already exists — choose another name.' };
    }

    var topSpot = keySpot(topPair);
    var topRaw = emitScalar(newName, topSpot.style, true);
    if (topRaw === null) {
      return { ok: false, error: 'That name cannot be written into this file, so nothing has been changed.' };
    }

    var edits = [], i;
    for (i = 0; i < svc.value.keys.length; i++) {
      var p = svc.value.pairs[svc.value.keys[i]];
      if (p.value && p.value.kind === 'map') collectServiceRefs(edits, p.value, oldName, newName);
    }

    var writes = [{ spot: topSpot, raw: topRaw }];
    for (i = 0; i < edits.length; i++) {
      var raw = emitScalar(edits[i].decoded, edits[i].spot.style, false);
      if (raw === null) {
        return { ok: false, error: 'One of the references to "' + oldName + '" cannot be rewritten safely, so nothing has been changed.' };
      }
      writes.push({ spot: edits[i].spot, raw: raw });
    }

    applyRenameWrites(doc, writes);
    return { ok: true, refs: edits.length };
  }

  /* =====================================================================
   * Exports
   * ===================================================================== */

  var API = {
    parse: parse,
    serialise: serialise,
    buildForm: buildForm,
    setPart: setPart,
    renameService: renameService,
    setValue: setValue,
    setComment: setComment,
    addItem: addItem,
    removeItem: removeItem,
    fieldById: fieldById,
    fieldAtLine: fieldAtLine,
    serviceAtLine: serviceAtLine,
    lineAtOffset: lineAtOffset,
    emitScalar: emitScalar,
    splice: splice
  };

  if (typeof window !== 'undefined') window.StackmanYaml = API;
  // Node has no window. The round-trip harness under tests/ requires this file
  // directly, and that harness is the only automated test this layer can have.
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
