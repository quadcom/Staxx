/* StaXX — the compose file model.
 * Copyright 2026, StaXX contributors. GPL-2.0.
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
    // A colon at the END of a scalar is a key indicator just as much as one
    // followed by a space: "8080:" written plainly is a mapping, not the text
    // "8080:". Without the $ here, clearing a port's container box wrote a
    // mapping into the ports list, the entry stopped being a port at all, and
    // its row vanished from the form while the line stayed in the file.
    if (/:(\s|$)/.test(v) || /\s#/.test(v)) return true;
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

    // Every form here writes one line, so a value carrying a line break has no
    // representation among them — written anyway it splits into a line the
    // parser would read as something else entirely. This is the refusal the
    // contract above promises; every caller already handles it by leaving the
    // file alone and saying so.
    if (/[\r\n]/.test(value)) return null;

    // Single-quoted YAML has no escape sequences at all, so a backslash survives
    // literally. Double-quoted does, and an unescaped \ either makes the file
    // unreadable (\d) or silently changes the value (\b, \t).
    if (value.indexOf('\\') >= 0) return "'" + value.replace(/'/g, "''") + "'";

    var hasD = value.indexOf('"') >= 0;
    var hasS = value.indexOf("'") >= 0;

    // A value holding both is still safely written single-quoted: YAML's
    // single-quote form needs no escaping for a literal '"', and the '' below
    // escapes any literal "'" the same as it always does. A stashed section's
    // JSON — which always has "s from its own keys, and often has 's from a
    // hand-written comment or command — is exactly this case.
    if (hasD) return "'" + value.replace(/'/g, "''") + "'";
    // Asked for only where the caller knows the value is a real boolean (see
    // setPart) — never inferred from the text, which is exactly what
    // needsQuoting() is right to refuse to do.
    if (style === 'bare') return value;
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

    // A '\r' left in a line stops KEY_RE matching "key:\r" and seals the whole
    // document as unparsable, though `docker compose up` reads it perfectly.
    // Stripped here and restored in serialise(). Any CRLF at all marks the
    // whole file CRLF, so a file with mixed endings comes out consistent
    // rather than half-converted. Note that lineStart below counts one
    // character per break, so an offset handed in from elsewhere has to be
    // one into the stripped text — which is what a textarea gives, since the
    // browser normalises its value the same way.
    var eol = text.indexOf('\r\n') >= 0 ? '\r\n' : '\n';
    if (eol === '\r\n') text = text.replace(/\r\n/g, '\n');

    var lines = text.split('\n');
    var starts = [], off = bom.length, i;
    for (i = 0; i < lines.length; i++) { starts.push(off); off += lines[i].length + 1; }

    var doc = {
      kind: 'doc', bom: bom, eol: eol, lines: lines, lineStart: starts,
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
          if (seenReal) {
            // A marker after real content only starts a second document if
            // more real content actually follows it. A lone trailing '...'
            // that just closes the file, with nothing but blanks/comments
            // (or further markers) after it, is one document.
            var after = i + 1;
            while (after < lines.length) {
              var ak = ctx.cls[after];
              if (ak.kind === 'blank' || ak.kind === 'comment') { after++; continue; }
              if (/^(---|\.\.\.)\s*$/.test(lines[after])) { after++; continue; }
              break;
            }
            if (after < lines.length) { whole = 'multi-doc'; break; }
            continue;
          }
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
    // A lone document marker at the top (or a leading '...') doesn't decide
    // the root kind itself — skip it exactly as a leading comment already is,
    // and look at what follows. doc.lines is untouched either way, so every
    // offset the parser records stays line-based and serialise() reproduces
    // the marker verbatim; skipping it here only changes where root parsing
    // starts, not what gets written back. A loop rather than one skip because
    // the scan above accepts any run of markers before the first real line,
    // and one that stopped after a single marker would seal a file that scan
    // had just called fine.
    while (j < lines.length && /^(---|\.\.\.)\s*$/.test(lines[j])) {
      j = significant(ctx, j + 1);
    }
    if (j >= lines.length) {
      doc.root = { kind: 'map', indent: 0, start: 0, end: 0, keys: [], pairs: {} };
      return doc;
    }
    if (ctx.cls[j].kind !== 'key') {
      doc.root = seal(ctx, j, lines.length, 'unparsable');
      return doc;
    }
    var rootParse = parseMap(ctx, j, ctx.cls[j].indent, false);
    doc.root = rootParse.node;

    // parseMap breaks silently at the first line it cannot make sense of, by
    // design — see the comment above it — so nothing says the parse stopped
    // short of the file's end unless this checks for it. significant() is the
    // same "ignore a trailing blank or comment" walk parseMap itself already
    // uses, so a file that merely ends in a comment is not flagged as cut off.
    // A trailing '...' or '---' is exactly as harmless here as it was for the
    // leading-marker skip above (doc.lines is never touched either way) — so
    // it is skipped the same way, alternating with significant() in case a
    // blank or comment line sits between two markers.
    var tail = significant(ctx, rootParse.next);
    while (tail < lines.length && /^(---|\.\.\.)\s*$/.test(lines[tail])) {
      tail = significant(ctx, tail + 1);
    }
    if (tail < lines.length) {
      seal(ctx, rootParse.next, lines.length, 'unparsable');
      ctx.warnings.push({
        line: tail,
        message: 'Line ' + (tail + 1) + ' could not be read, so the rest of the file is being left ' +
                 'alone. This is usually caused by a line indented differently from the ones around it.'
      });
      doc.unreadTail = true;
    }
    return doc;
  }

  function serialise(doc) {
    return doc.bom + doc.lines.join(doc.eol || '\n');
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
  // How many entries splice() keeps of what it did. Diagnosis only needs the
  // most recent handful of structural writes; an editing session can run for
  // hours, so the journal is capped rather than left to grow with it.
  var SPLICE_JOURNAL_MAX = 50;

  function splice(doc, at, remove, insert) {
    var args = [at, remove].concat(insert || []);
    Array.prototype.splice.apply(doc.lines, args);
    // Not a check — just a record of what the operation actually did, kept
    // for diagnosis (PLAN_66 §4). An index, a count removed and the lines
    // inserted, no more: nothing here is compared against anything yet.
    (doc.spliceLog = doc.spliceLog || []).push({ at: at, removed: remove, inserted: (insert || []).length });
    if (doc.spliceLog.length > SPLICE_JOURNAL_MAX) doc.spliceLog.shift();
    // Re-parsed joined with plain '\n' — the lines hold no carriage returns
    // after parse() strips them, so this never touches doc.eol; only lines,
    // lineStart, root, sealed and warnings are copied back below.
    var next = parse(doc.bom + doc.lines.join('\n'));
    doc.lines = next.lines;
    doc.lineStart = next.lineStart;
    doc.root = next.root;
    doc.sealed = next.sealed;
    doc.warnings = next.warnings;
    doc.unreadTail = next.unreadTail;
    return doc;
  }

  // A write that inserts or removes structure has to refuse once any part of
  // the file failed to parse — the unread region could hold anything,
  // including the very key the write is about to add a second copy of. An
  // in-place edit on a line the parser did read is unaffected; see PLAN_60a.
  function hasUnreadTail(doc) {
    return !!(doc && doc.unreadTail);
  }

  // True once the text at a spot's line/col/len no longer matches the text
  // the spot was built from — the exact way PLAN_64 Phase B's bug happened:
  // declaring a network re-parsed the document, which shifted every service
  // line up by one, and the write that followed used the old line number and
  // landed mid-word. `spot.text` is missing only for a spot with nothing to
  // compare (a zero-width insertion point) or one built before this guard
  // existed; either way there is nothing to catch it against, so it passes.
  function spotStale(doc, spot) {
    if (!spot || spot.text === undefined) return false;
    var line = doc.lines[spot.line];
    var stale = line === undefined ||
                line.slice(spot.col, spot.col + spot.len) !== spot.text;
    // Recorded on the document, because every writer here reports failure as a
    // bare false and the caller cannot otherwise tell "this value will not fit
    // in a compose file" from "the position went stale". Those two need
    // OPPOSITE advice — the first says edit it by hand, the second says try
    // again — so a caller that cannot tell them apart has to give one of them
    // wrong. Cleared on every check so it never describes an older failure.
    doc.staleWrite = stale;
    return stale;
  }

  // Replaces one scalar in place. Everything after it on the line — the run of
  // spaces and any comment — rides along in the tail slice untouched, which is
  // why keeping comments through an edit needs no code of its own.
  function writeScalar(doc, where, decoded, style) {
    var raw = emitScalar(decoded, style, where.isKey);
    if (raw === null) return false;
    // Precondition, not postcondition: fail before doc.lines is touched, so
    // there is nothing to revert. See spotStale() and PLAN_66.
    if (spotStale(doc, where)) return false;

    var line = doc.lines[where.line];
    doc.lines[where.line] = line.slice(0, where.col) + raw + line.slice(where.col + where.len);
    splice(doc, 0, 0, []);                    // re-parse, no line count change
    return true;
  }

  /* =====================================================================
   * Binding the file to form fields
   * ===================================================================== */

  // The whole vocabulary the form knows, one entry per compose key: what
  // shape its value takes, what to call it, which declared-name namespace
  // (see buildForm's `declared`) a list entry's value is a reference into.
  // Everything harvest(), inferTitle() and inferType() need is read from
  // here, so adding a key is one line instead of edits in four places.
  var KEYS = {
    image:          { shape: 'scalar', always: 1 },
    container_name: { shape: 'scalar', always: 1 },
    restart:        { shape: 'scalar', always: 1 },
    // No `always: 1` — PLAN_34 phase 4 took network_mode out of the form
    // deliberately (Adrian's own files never use it). A file that already
    // sets one still reads as an ordinary editable Advanced row, in file
    // order, same as any other key with no control of its own.
    network_mode:   { shape: 'scalar' },

    ports:          { shape: 'list',  entry: 'port'   },
    volumes:        { shape: 'list',  entry: 'volume', from: 'volumes' },
    devices:        { shape: 'list',  entry: 'device' },
    environment:    { shape: 'pairs' },
    labels:         { shape: 'pairs' },

    networks:       { shape: 'list',  entry: 'plain', from: 'networks' },
    secrets:        { shape: 'list',  entry: 'plain', from: 'secrets'  },
    configs:        { shape: 'list',  entry: 'plain', from: 'configs'  },
    depends_on:     { shape: 'list',  entry: 'plain', from: 'services', title: 'Starts after' },
    profiles:       { shape: 'list',  entry: 'plain' },
    dns:            { shape: 'list',  entry: 'plain', title: 'DNS servers' },
    cap_add:        { shape: 'list',  entry: 'plain', title: 'Extra permissions' },
    cap_drop:       { shape: 'list',  entry: 'plain', title: 'Dropped permissions' },
    expose:         { shape: 'list',  entry: 'plain', title: 'Internal ports', type: 'port' },
    env_file:       { shape: 'list',  entry: 'plain', title: 'Variable files', tool: 'browse' },

    healthcheck:    { shape: 'block', title: 'Health check' },
    deploy:         { shape: 'block', title: 'Resource limits' },
    logging:        { shape: 'block', title: 'Logging' },
    // No `always: 1` — unlike the Container group's three keys, an absent
    // build: is not offered as a blank block on every service, only shown
    // when the file already has one.
    build:          { shape: 'block', title: 'Build' },

    command:        { shape: 'scalar' },
    entrypoint:     { shape: 'scalar' },
    user:           { shape: 'scalar' },
    hostname:       { shape: 'scalar' },
    privileged:     { shape: 'scalar', type: 'boolean' },
    shm_size:       { shape: 'scalar' },
    working_dir:    { shape: 'scalar', title: 'Working folder' },
    mem_limit:      { shape: 'scalar', title: 'Memory limit' },
    pull_policy:    { shape: 'scalar', title: 'When to pull the image' },
    stop_signal:    { shape: 'scalar', title: 'Stop signal' },
    ipc:            { shape: 'scalar', title: 'IPC mode' },
    pid:            { shape: 'scalar', title: 'Process namespace' },
    read_only:      { shape: 'scalar', type: 'boolean', title: 'Read-only filesystem' },
    init:           { shape: 'scalar', type: 'boolean' },
    tty:            { shape: 'scalar', type: 'boolean' },
    stdin_open:     { shape: 'scalar', type: 'boolean', title: 'Keep input open' },
    // Explicit type, no title, for these two and none of the other ~49
    // uncovered keys: an unknown key's type is guessed from its value, which
    // works for true/false and fails for yes/no — the explicit type is also
    // what stops the value being written back quoted. The title comes from
    // DESCRIPTIONS via inferTitle() now, so giving one here would be a
    // second source of truth for the same words.
    oom_kill_disable: { shape: 'scalar', type: 'boolean' },
    attach:           { shape: 'scalar', type: 'boolean' }
  };

  // TOP_SPEC_KEYS / SERVICE_SPEC_KEYS — the valid keys the COMPOSE
  // SPECIFICATION itself accepts, at the top level and inside one service.
  // This is NOT the same list as KEYS above, and merging them would be a
  // bug: KEYS is the ~40 service keys the FORM currently draws a control
  // for, whereas compose accepts around 90. A real, valid setting the form
  // has no control for yet — sysctls, extra_hosts, ulimits — must never be
  // flagged as a typo just because KEYS does not mention it. KEYS grows
  // only when a new control is built; these two grow only when the compose
  // specification itself gains a key. Used only by lint() below.
  var TOP_SPEC_KEYS = ['services', 'networks', 'volumes', 'configs', 'secrets', 'name', 'include', 'version'];

  var SERVICE_SPEC_KEYS = [
    'annotations', 'attach', 'blkio_config', 'build', 'cap_add', 'cap_drop', 'cgroup',
    'cgroup_parent', 'command', 'configs', 'container_name', 'cpu_count', 'cpu_percent',
    'cpu_period', 'cpu_quota', 'cpu_rt_period', 'cpu_rt_runtime', 'cpu_shares', 'cpus', 'cpuset',
    'credential_spec', 'depends_on', 'deploy', 'develop', 'device_cgroup_rules', 'devices', 'dns',
    'dns_opt', 'dns_search', 'domainname', 'entrypoint', 'env_file', 'environment', 'expose',
    'extends', 'external_links', 'extra_hosts', 'gpus', 'group_add', 'healthcheck', 'hostname',
    'image', 'init', 'ipc', 'isolation', 'label_file', 'labels', 'links', 'logging', 'mac_address',
    'mem_limit', 'mem_reservation', 'mem_swappiness', 'memswap_limit', 'network_mode', 'networks',
    'oom_kill_disable', 'oom_score_adj', 'pid', 'pids_limit', 'platform', 'ports', 'post_start',
    'pre_stop', 'privileged', 'profiles', 'provider', 'pull_policy', 'pull_refresh_after',
    'read_only', 'restart', 'runtime', 'scale', 'secrets', 'security_opt', 'shm_size', 'stdin_open',
    'stop_grace_period', 'stop_signal', 'storage_opt', 'sysctls', 'tmpfs', 'tty', 'ulimits', 'user',
    'userns_mode', 'uts', 'volumes', 'volumes_from', 'working_dir'
  ];

  function specSetOf(list) {
    var out = {};
    for (var i = 0; i < list.length; i++) out[list[i]] = true;
    return out;
  }
  var TOP_SPEC_SET = specSetOf(TOP_SPEC_KEYS);
  var SERVICE_SPEC_SET = specSetOf(SERVICE_SPEC_KEYS);

  // Nested values the form can edit. The parser reaches these already and
  // writeScalar needs only a spot — the only thing missing was a field pointing
  // at one. An ABSENT leaf is never offered: creating a healthcheck block means
  // inserting nested keys, which nothing here can do (see PLAN_7.md).
  var LEAVES = {
    healthcheck: {
      test:           'The check itself',
      interval:       'Check every',
      timeout:        'Give up after',
      retries:        'Failures allowed',
      start_period:   'Grace period at start',
      start_interval: 'Checking every, while starting',
      disable:        'Disabled'
    },
    deploy: {
      'resources.limits.cpus':         'CPU limit',
      'resources.limits.memory':       'Memory limit',
      'resources.reservations.cpus':   'CPU reserved',
      'resources.reservations.memory': 'Memory reserved'
    },
    // options is a free-form map with no fixed shape, so it is left to the
    // Advanced catch-all rather than offered a leaf here.
    logging: {
      driver: 'Log driver'
    },

    // The everyday build: scalars only — BUILD_LEAVES (below) lists all 20
    // of build:'s own keys, but harvestLeaves() offers every LEAVES path on
    // every service whether or not the file has it, so a long list here would
    // be 17 empty boxes nobody asked for (PLAN_21 section 2). Anything else
    // written under build: (args, cache_from, ssh...) still gets its own row
    // from harvestBlock's uncovered-children pass, so nothing goes missing.
    build: {
      context:    'Build context',
      dockerfile: 'Dockerfile path',
      target:     'Build stage'
    }
  };

  // Measured on compose 2.40.3 outside a swarm: these five are parsed and then
  // ignored, while resources, replicas and restart_policy are honoured. A box
  // that looks like it works and does nothing is the failure this form exists
  // to avoid. Keyed by full target name so it cannot collide with another
  // block's child.
  var SWARM_ONLY = { 'deploy.placement': 1, 'deploy.update_config': 1,
                     'deploy.rollback_config': 1, 'deploy.endpoint_mode': 1, 'deploy.mode': 1 };

  // A declaration's settings the form knows what to do with — the same shape
  // as LEAVES above, pointed at harvestBlock via a table argument rather than
  // a second copy of the walk. DECL_PRIMARY is the one setting per kind that
  // stands for the whole declaration and gets its own box on the row, so it
  // is excluded from the fold rather than shown twice.
  var DECL_LEAVES = {
    networks: { driver: 'Driver', internal: 'Internal only, no outside access',
                external: 'Created outside this file', name: 'Real name in Docker',
                attachable: 'Attachable', ipam: 'IP address management' },
    volumes:  { driver: 'Driver', external: 'Created outside this file',
                name: 'Real name in Docker' },
    secrets:  { file: 'File on the server', environment: 'From a variable',
                external: 'Created outside this file' },
    configs:  { file: 'File on the server', external: 'Created outside this file' }
  };
  var DECL_PRIMARY = { networks: 'driver', volumes: 'driver', secrets: 'file', configs: 'file' };

  // A leading space is never a legal driver name, so this can never collide
  // with one the file actually names — the same guard VOL_FOLDER_SENTINEL
  // uses in stacks.js for the volume host box.
  var EXTERNAL_CHOICE = ' external';

  // networks and volumes only: their row box is a driver dropdown, and driver
  // and external are two answers to the same question. A secret's or config's
  // row box is a file path, so the same merge there would cost more than it
  // saves — they keep external as an ordinary folded leaf.
  var DECL_EXTERNAL_ROW = { networks: 1, volumes: 1 };

  // depends_on's long-form entry: a dependency name (a key, harvested via
  // keySpot() below — see collectServiceRefs(), which follows a rename the
  // same way) plus condition on the row itself, and these two folded away
  // below it, present-or-absent the same way LEAVES treats healthcheck/deploy.
  var DEPENDS_LEAVES = {
    restart:  'Restart this service too when the dependency restarts',
    required: 'This dependency must start successfully for this service to start'
  };

  function keySpec(k) { return KEYS[k] || null; }

  // Derived once at load rather than per service. ALWAYS_KEYS must stay in
  // table order — image, container_name, restart — because the Container
  // group's field count depends on it (see harvest()); network_mode was a
  // fourth entry here until PLAN_34 phase 4 took it out of the form
  // entirely. LIST_KEY
  // maps a binder (what a harvested field calls itself) back to the compose
  // key it lives under, for addItem()/removeItem(); env/label are pairs
  // shapes so the table carries no `entry` for them and they are added by
  // hand. FROM_WORD is only for phrasing the 1e dangling-reference note.
  var ALWAYS_KEYS = [];
  var LIST_KEY = { env: 'environment', label: 'labels' };
  var FROM_WORD = { networks: 'network', volumes: 'volume', secrets: 'secret',
                     configs: 'config', services: 'service' };
  for (var _k in KEYS) {
    if (!KEYS.hasOwnProperty(_k)) continue;
    if (KEYS[_k].always) ALWAYS_KEYS.push(_k);
    if (KEYS[_k].shape === 'list' && KEYS[_k].entry !== 'plain') LIST_KEY[KEYS[_k].entry] = _k;
  }

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
    // node.raw IS the text at [node.col, node.col + node.raw.length) on
    // node.line — that is where the parser read it from — so it doubles as
    // the "what was here" record a later write checks itself against.
    return { line: node.line, col: node.col, len: node.raw.length, style: node.style, text: node.raw };
  }

  // Where a mapping key sits on its own line. A variable's NAME is as much a
  // field as its value is — without this, adding one would produce a row that
  // could never be called anything.
  function keySpot(pair) {
    var q = pair.keyRaw.charAt(0);
    return {
      line: pair.start, col: pair.indent, len: pair.keyRaw.length,
      style: q === '"' ? 'double' : q === "'" ? 'single' : 'plain',
      isKey: true, text: pair.keyRaw
    };
  }

  // One editable box. Writing it back is spot := pre + value + post, so a part
  // can address half of a scalar that holds two things — which is exactly what
  // "8096:8097" is.
  //
  // `creatable`, when true, marks a part that has no spot yet but is still
  // meant to render as a writable box rather than a disabled one — the two
  // blank extras (fixed IPv4/hardware address) harvestNetworksMap() offers on
  // a network map entry (PLAN_34 phase 3c). It exists because boxHtml()'s own
  // dead/disabled check in stacks.js only recognises a whole FIELD as
  // creatable (f.absent, f.path) — this is one PART of an otherwise-present
  // field, so the field-level flags say nothing about it.
  function part(value, spot, pre, post, creatable) {
    return { value: value, spot: spot || null, pre: pre || '', post: post || '', creatable: !!creatable };
  }

  // A part with no line of its own to bind to — an unreadable child of a
  // long-form entry (a list such as aliases:, or a map nested two levels
  // down such as bind.options). A separate constructor rather than more
  // optional arguments on part(): every call site there passes positionally,
  // and this shape needs two fields (raw, reason) part() has no use for.
  function lockedPart(raw, reason) {
    return { value: '', spot: null, pre: '', post: '', creatable: false, locked: true, raw: raw, reason: reason };
  }

  // Split on ':' only outside a ${...}, because a variable's default value can
  // contain one — "${PORT:-8080}:80" is two fields, not three, and splitting it
  // naively hands the host box "-8081}" and destroys the expression on write.
  function splitOutsideVars(s) {
    var out = [], start = 0, depth = 0;
    for (var i = 0; i < s.length; i++) {
      if (s.charAt(i) === '$' && s.charAt(i + 1) === '{') { depth++; i++; continue; }
      if (s.charAt(i) === '}' && depth > 0) { depth--; continue; }
      if (s.charAt(i) === ':' && depth === 0) { out.push(s.slice(start, i)); start = i + 1; }
    }
    out.push(s.slice(start));
    return out;
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

    var bits = splitOutsideVars(body);
    if (bits.length === 1) {
      // Only a container port, so compose picks the host port itself. The
      // digits still have room for a protocol suffix after them even when
      // none is written yet, so proto gets a real spot rather than a dead one.
      var hostPart1 = part('', null);
      var containerPart1 = part(bits[0], spot, '', proto);
      // The value carries its own leading slash ('/udp' or '') so choosing
      // the empty option writes the separator away too — writeScalar needs
      // no special case for "no protocol chosen".
      var protoPart1 = part(proto, spot, bits[0], '');
      return {
        key: portKey(bits[0] + proto),
        host: hostPart1, container: containerPart1, proto: protoPart1,
        parts: { host: hostPart1, container: containerPart1, proto: protoPart1 },
        // Matched by its exact text in buildForm's network-kind pass, which
        // clears it on a host or macvlan service where it is untrue. Reword
        // it there too.
        hostNote: 'compose picks the host port for this one'
      };
    }

    var container = bits[bits.length - 1];
    var host      = bits[bits.length - 2];
    var lead      = bits.slice(0, bits.length - 2).join(':');
    var leadIn    = lead ? lead + ':' : '';

    var hostPart      = part(host, spot, leadIn, ':' + container + proto);
    var containerPart = part(container, spot, leadIn + host + ':', proto);
    // Same separator-carrying rule as the single-bit branch above.
    var protoPart      = part(proto, spot, leadIn + host + ':' + container, '');

    return {
      key: portKey(container + proto),
      host: hostPart, container: containerPart, proto: protoPart,
      parts: { host: hostPart, container: containerPart, proto: protoPart }
    };
  }

  // A short-form volume or device: host:container[:mode], or a single path.
  function splitPathShort(text, spot) {
    var bits = splitOutsideVars(text);
    if (bits.length === 1) {
      // An anonymous volume — docker manages the storage, so there is no host
      // path to show or change, and no mode slot either: a bare name has
      // nowhere to hang ':ro' without a host segment beside it. A null spot
      // renders the box dead rather than dropping it from the form.
      var hostPart1 = part('', null);
      var containerPart1 = part(bits[0], spot);
      var modePart1 = part('', null);
      return {
        key: bits[0],
        host: hostPart1, container: containerPart1,
        parts: { host: hostPart1, container: containerPart1, mode: modePart1 },
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

    var hostPart      = part(host, spot, '', ':' + container + mode);
    var containerPart = part(container, spot, host + ':', mode);
    // The mode part's value carries its own leading colon (':ro' or '') so
    // choosing the empty option writes the separator away too — writeScalar
    // needs no special case for "no mode chosen". Kept separate from the
    // `mode` string below, which stays stripped for callers that read it
    // directly (collectDeclaredRefs, and the 'ro' tag in stacks.js).
    var modePart      = part(mode, spot, host + ':' + container, '');

    return {
      key: container,
      mode: mode.slice(1),
      host: hostPart, container: containerPart,
      parts: { host: hostPart, container: containerPart, mode: modePart }
    };
  }

  // A plain list entry is one whole value, so it keys on itself. Unlike a port
  // or a mount there is no container half to bind to, which means renaming an
  // entry IS replacing it.
  //
  // An empty entry is bound like any other rather than refused. Refusing one
  // dropped its row from the form while the line stayed in the file, which
  // left it reachable only in the Compose view — an entry with no value is an
  // unfinished edit, and the way to finish or delete it is to be able to see
  // it.
  function splitPlain(text, spot) {
    var s = String(text).trim();
    return { key: s, parts: { value: part(s, spot) } };
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
      lockReason: opts.lockReason || '',
      absent: !!opts.absent,
      blocked: !!opts.blocked,
      advice: opts.advice || [],
      listKey: opts.listKey || '',
      // A plain list entry's position in the seq — undefined for anything
      // that isn't one. Only used to tell two identical-looking entries
      // apart when building a 'list' field's id (see fieldsFor).
      index: opts.index,
      // The full key path under the service for a LEAVES sub-value —
      // ['healthcheck', 'interval'] — null for everything else. This is what
      // lets setPart() reach addNested()/removeKey() instead of the flat,
      // one-key writes the rest of the form uses.
      path: opts.path || null,
      // 'mode' | 'command' | null. healthcheck.test is one file line shown as
      // two fields (see harvestHealthTest()), so setPart() needs to know
      // which half a field is before it can read the other half and write
      // them back together.
      testPart: opts.testPart || null
    };
  }

  function lockedTarget(binder, key, range, reason, raw, listKey) {
    return target(binder, key, { range: range, locked: true, lockReason: reason, raw: raw || '', listKey: listKey });
  }

  function harvestList(out, binder, pair, splitter, lines, listKey) {
    var v = pair.value;
    if (!v) return;
    if (v.kind === 'opaque') {
      out.push(lockedTarget(binder, '@' + pair.key,
        { start: pair.leadStart, end: pair.end }, lockReason(v.reason), v.raw, listKey));
      return;
    }
    if (v.kind !== 'seq') return;

    for (var i = 0; i < v.items.length; i++) {
      var it = v.items[i];
      var range = { start: it.leadStart, end: it.end };

      // A dash with nothing after it. newEntry() writes one for the keys whose
      // box is a suggestion list, where a placeholder would hide the very
      // suggestions the Add button was pressed to see — so it has to come back
      // as an empty box to type into, not as a locked row. The spot is
      // zero-width at the column just past the dash, so typing fills the line
      // in rather than replacing anything. A dash with no space after it has
      // nowhere to put a value and stays locked, as does an empty entry under
      // ports/volumes/devices, where the box is one half of a pair and an
      // empty half says nothing about which half is missing.
      if (binder === 'list' && !it.value && it.contentCol > it.indent + 1) {
        out.push(target(binder, '', {
          parts: { value: part('', { line: it.start, col: it.contentCol, len: 0, style: 'plain', text: '' }) },
          range: range, listKey: listKey, index: i
        }));
        continue;
      }

      if (!it.value || it.value.kind === 'opaque') {
        out.push(lockedTarget(binder, '@' + pair.key + '#' + i, range,
          lockReason(it.value ? it.value.reason : 'unparsable'),
          it.value ? it.value.raw : '', listKey));
        continue;
      }

      if (it.value.kind === 'map') {
        // A 'list' binder has no long form — {target:, ...} is a ports/volumes
        // thing — except secrets:/configs:, whose long form names itself with
        // a source: scalar the row can bind to exactly the way a short-form
        // entry's own value does, keeping its declared-name dropdown; target,
        // uid, gid and mode become B3-style extras. Nothing else sharing this
        // binder (networks, depends_on, profiles...) ever writes a seq item
        // this way, so everything else here still has no name to show it by.
        if (binder === 'list') {
          if (listKey === 'secrets' || listKey === 'configs') {
            var srcPair = it.value.pairs['source'];
            var srcNode = srcPair && srcPair.value && srcPair.value.kind === 'scalar' ? srcPair.value : null;
            if (srcNode) {
              var st = target(binder, srcNode.value, {
                parts: { value: part(srcNode.value, scalarSpot(srcNode)) },
                range: range,
                comment: readComment(srcNode.comment),
                commentSpot: commentSpot(srcNode, lines),
                listKey: listKey,
                index: i
              });
              st.longForm = true;
              harvestLongExtras(st, it.value, ['source'], lines);
              out.push(st);
              continue;
            }
            var span2 = lines.slice(it.start, it.end);
            var raw2 = span2.map(function (l) { return l.slice(it.indent); }).join('\n');
            out.push(lockedTarget(binder, '@' + pair.key + '#' + i, range,
              'this entry does not say which ' + (listKey === 'secrets' ? 'secret' : 'config') + ' it uses',
              raw2, listKey));
            continue;
          }
          var span = lines.slice(it.start, it.end);
          var raw = span.map(function (l) { return l.slice(it.indent); }).join('\n');
          out.push(lockedTarget(binder, '@' + pair.key + '#' + i, range,
            'this entry is written as a block of its own', raw, listKey));
          continue;
        }
        harvestLongForm(out, binder, it, range, lines, listKey, i);
        continue;
      }
      if (it.value.kind !== 'scalar') continue;

      var s = splitter(it.value.value, scalarSpot(it.value));
      if (!s) continue;
      out.push(target(binder, s.key, {
        parts: s.parts || { host: s.host, container: s.container },
        mode: s.mode,
        range: range,
        comment: readComment(it.value.comment),
        commentSpot: commentSpot(it.value, lines),
        lockReason: s.hostNote || '',
        listKey: listKey,
        index: i
      }));
    }
  }

  // The spelled-out forms: {target, published, protocol} for a port,
  // {type, source, target} for a volume. Here the two halves are separate
  // scalars on separate lines, so each part addresses its own.
  function harvestLongForm(out, binder, item, range, lines, listKey, index) {
    var map = item.value;
    var pick = function (k) {
      var p = map.pairs[k];
      return p && p.value && p.value.kind === 'scalar' ? p.value : null;
    };

    var tgt = pick('target');
    if (!tgt) {
      // No container-side target: line means Docker refuses the entry
      // outright, so this is invalid compose, not a form limitation — locked
      // rather than dropped, so the reader can see what the file actually
      // says here, the same treatment the map branch above already gives a
      // shape harvestLongForm cannot read at all.
      var span = lines.slice(item.start, item.end);
      var raw = span.map(function (l) { return l.slice(item.indent); }).join('\n');
      out.push(lockedTarget(binder, '@' + listKey + '#' + index, range,
        binder === 'port'
          ? 'this entry does not say which port inside the container to use'
          : 'this mount does not say where it goes in the container',
        raw, listKey));
      return;
    }

    // The note belongs to the entry as a whole, so it rides on the first line.
    var note = { comment: readComment(tgt.comment), spot: commentSpot(tgt, lines) };

    if (binder === 'port') {
      var pub = pick('published'), proto = pick('protocol');
      var t = target('port', portKey(tgt.value + (proto ? '/' + proto.value : '')), {
        parts: {
          host:      part(pub ? pub.value : '', pub ? scalarSpot(pub) : null),
          container: part(tgt.value, scalarSpot(tgt)),
          // Bare word on its own line, unlike the short form's slash-carrying
          // part below — no separator to strip or restore.
          proto:     part(proto ? proto.value : '', proto ? scalarSpot(proto) : null)
        },
        range: range, comment: note.comment, commentSpot: note.spot,
        // Matched by its exact text in buildForm's network-kind pass — see
        // the note beside the short-form wording above.
        lockReason: pub ? '' : 'no host port is set here',
        listKey: listKey,
        index: index
      });
      // Tells fieldsFor()/choiceFor() this row's proto part is the bare-word
      // long form, not the short form's slash-carrying one — see stacks.js's
      // choiceFor().
      t.longForm = true;
      harvestLongExtras(t, map, ['target', 'published', 'protocol'], lines);
      out.push(t);
      return;
    }

    var src = pick('source'), ro = pick('read_only');
    var vt = target(binder, tgt.value, {
      parts: {
        host:      part(src ? src.value : '', src ? scalarSpot(src) : null),
        container: part(tgt.value, scalarSpot(tgt))
      },
      mode: ro && /^(true|yes)$/i.test(ro.value) ? 'ro' : '',
      range: range, comment: note.comment, commentSpot: note.spot,
      lockReason: src ? '' : 'this mount has no source path to edit',
      listKey: listKey,
      index: index
    });
    vt.longForm = true;
    harvestLongExtras(vt, map, ['source', 'target'], lines);
    out.push(vt);
  }

  // Everything under a long-form port/mount item that the main boxes above
  // do not already own: a direct scalar child becomes a part named after its
  // key, and a direct map child (bind:, tmpfs:, volume:) is walked one level
  // further, becoming a 'parent.child' part — bind.propagation, tmpfs.size.
  // One level only. Anything else here (a list, something the parser sealed,
  // a map nested deeper than that) has no single line the form can bind to,
  // so it becomes a locked part instead — named, showing the file's own text
  // and why it cannot be edited here, in file order among the editable ones.
  function harvestLongExtras(t, map, owned, lines) {
    var extras = [];
    for (var i = 0; i < map.keys.length; i++) {
      var key = map.keys[i];
      if (owned.indexOf(key) >= 0) continue;
      var p = map.pairs[key];
      if (!p.value) continue;

      if (p.value.kind === 'scalar') {
        t.parts[key] = part(p.value.value, scalarSpot(p.value));
        extras.push(key);
        continue;
      }
      if (p.value.kind === 'map') {
        for (var j = 0; j < p.value.keys.length; j++) {
          var key2 = p.value.keys[j];
          var p2 = p.value.pairs[key2];
          if (p2.value && p2.value.kind === 'scalar') {
            var name = key + '.' + key2;
            t.parts[name] = part(p2.value.value, scalarSpot(p2.value));
            extras.push(name);
          } else {
            // A grandchild that is not a scalar — bind.options is the shape
            // this exists for. Locked rather than a generic notice, same as
            // the direct-child case below, so the reader sees what is there
            // instead of just being told something is.
            var name2 = key + '.' + key2;
            t.parts[name2] = lockedPart(unreadableRaw(p2, lines), unreadableWhy(p2));
            extras.push(name2);
          }
        }
        continue;
      }
      // A direct child that is neither a scalar nor a map — a list such as
      // aliases: or link_local_ips:. No single line to bind a box to, so the
      // row shows the file's own text and why, the same treatment an
      // unreadable SETTINGS key gets from settingTarget().
      t.parts[key] = lockedPart(unreadableRaw(p, lines), unreadableWhy(p));
      extras.push(key);
    }
    t.longExtras = extras;
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
          commentSpot: commentSpot(p.value, lines),
          index: i
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
        lockReason: eq < 0 ? 'this one takes its value from the server’s environment' : '',
        index: i
      }));
    }
  }

  // The two things an unreadable pair needs shown, split out of
  // settingTarget() so harvestLongExtras() can give an unreadable child of a
  // long-form entry the same locked-row treatment instead of a generic
  // "ask the Compose view" sentence (PLAN_34 phase 6).
  //
  // A command spelled out as a list of arguments is a perfectly ordinary
  // thing to find, so say that rather than falling back on "the form cannot
  // read this", which sounds like the file is wrong.
  function unreadableWhy(p) {
    return !p.value ? 'this setting has no value'
         : p.value.kind === 'seq' ? 'this is written as a list of separate items'
         : p.value.kind === 'map' ? 'this is written as a block of its own'
         : lockReason(p.value.reason);
  }

  // Only a sealed node carries `raw`. A seq or map was parsed properly and
  // has none, so a command written as a list would otherwise reach the form
  // as an empty code block. Either way the text shown is the file's own
  // lines less the key's own indent, so a command reads as a command rather
  // than as a fragment of a file — and so the two ways of writing one over
  // several lines, a list and a block scalar, look the same on screen.
  function unreadableRaw(p, lines) {
    var span = p.value && p.value.raw ? p.value.raw.split('\n')
                                      : lines.slice(p.start, p.end);
    return span.map(function (l) { return l.slice(p.indent); }).join('\n');
  }

  // One SETTINGS key that IS present in the file, as either an editable
  // target or a locked one. Shared by the ALWAYS loop and the file-order
  // loop below so the two can never drift apart.
  function settingTarget(p, key, lines) {
    var range = { start: p.leadStart, end: p.end };
    if (p.value && p.value.kind === 'scalar') {
      return target('setting', key, {
        parts: { value: part(p.value.value, scalarSpot(p.value)) },
        range: range,
        comment: readComment(p.value.comment),
        commentSpot: commentSpot(p.value, lines)
      });
    }
    return lockedTarget('setting', key, range, unreadableWhy(p), unreadableRaw(p, lines));
  }

  // healthcheck: and deploy: are 'block' shape — a map that has editable
  // leaves inside it. harvestLeaves() (below) now harvests every LEAVES path
  // up front, present or not, so this only has two jobs left: cover every
  // direct child LEAVES does not name (deploy.replicas), and — for
  // declaredFields()'s DECL_LEAVES call only — do the leaf walk itself,
  // since a declaration's driver/internal/etc. never gets the "always
  // offered" treatment harvestLeaves gives healthcheck/deploy. The parent
  // key itself emits nothing here, which is what stops it also reaching the
  // catch-all and rendering twice.
  // `table`/`lookupKey` let declaredFields() point this at DECL_LEAVES under
  // a lookup key ('networks') that differs from the target prefix it writes
  // ('networks.frontend_net'); the plain call (table/lookupKey both omitted)
  // still needs the same walk to know which direct children the default
  // LEAVES table already accounts for, but leaves emitting them to
  // harvestLeaves so a present leaf never appears as two fields.
  function harvestBlock(out, key, p, lines, table, lookupKey) {
    if (!p.value || p.value.kind !== 'map') { out.push(settingTarget(p, key, lines)); return; }

    var leaves = (table || LEAVES)[lookupKey || key] || {};
    var covered = [];

    for (var subPath in leaves) {
      if (!leaves.hasOwnProperty(subPath)) continue;
      var segs = subPath.split('.'), node = p.value, leafPair = null, ok = true;
      for (var i = 0; i < segs.length; i++) {
        if (!node || node.kind !== 'map') { ok = false; break; }
        leafPair = node.pairs[segs[i]];
        if (!leafPair) { ok = false; break; }
        node = leafPair.value;
      }

      // healthcheck.test is harvested by harvestHealthTest() (see
      // harvestLeaves()) whenever it can be confidently read, whatever shape
      // it is written in — not only when it happens to be a scalar. Marking
      // it covered here on the same terms stops this function's own
      // uncovered-children pass below from also emitting a third, locked
      // 'healthcheck.test' field alongside the two live ones.
      if (!table && key === 'healthcheck' && subPath === 'test') {
        if (ok && leafPair.value && readTest(leafPair)) covered.push(key + '.' + subPath);
        continue;
      }

      if (!ok || !leafPair.value || leafPair.value.kind !== 'scalar') continue;
      if (table) out.push(settingTarget(leafPair, key + '.' + subPath, lines));
      covered.push(key + '.' + subPath);
    }

    for (var c = 0; c < p.value.keys.length; c++) {
      var childName = p.value.keys[c];
      var childTarget = key + '.' + childName;
      var already = false;
      for (var j = 0; j < covered.length; j++) {
        if (covered[j] === childTarget || covered[j].indexOf(childTarget + '.') === 0) { already = true; break; }
      }
      if (!already) {
        var childField = settingTarget(p.value.pairs[childName], childTarget, lines);
        if (SWARM_ONLY.hasOwnProperty(childTarget)) {
          childField.advice.push('Docker ignores this unless the server is part of a swarm cluster, so setting it here has no effect');
        }
        out.push(childField);
      }
    }
  }

  // Every LEAVES path becomes exactly one field, whether or not the file has
  // it — the fixed-pass shape ALWAYS_KEYS above already uses, and for the
  // same reason: a service's field set (and so its tick-box groups, Phase 3)
  // must not change shape depending on what the file happens to contain.
  // A leaf actually in the file (a scalar) reads as an ordinary setting; one
  // genuinely missing gets a blank field carrying the `path` addNested()
  // needs to create it. A leaf that IS present but not a plain scalar — sealed,
  // a bare key with nothing under it, or a block of its own — gets neither:
  // saying nothing here leaves it to harvestBlock's second loop, which shows
  // it as its own (locked) field exactly as it did before this pass existed,
  // and avoids two fields ever claiming the same id.
  //
  // A block key that exists but is not a map at all (an anchor, alias, flow
  // map or scalar) is left alone completely — harvestBlock's own first line
  // shows it whole and read-only, and offering a "create" box into something
  // sealed would invite writing inside it, which nothing here can do safely.
  function harvestLeaves(out, serviceMap, lines) {
    for (var blockKey in LEAVES) {
      if (!LEAVES.hasOwnProperty(blockKey)) continue;
      var p = serviceMap.pairs[blockKey];
      var blockMap = p && p.value && p.value.kind === 'map' ? p.value : null;
      if (p && p.value && !blockMap) continue;

      var leaves = LEAVES[blockKey];
      for (var subPath in leaves) {
        if (!leaves.hasOwnProperty(subPath)) continue;
        var segs = subPath.split('.'), node = blockMap, leafPair = null, ok = !!blockMap;
        for (var i = 0; ok && i < segs.length; i++) {
          if (!node || node.kind !== 'map') { ok = false; break; }
          leafPair = node.pairs[segs[i]];
          if (!leafPair) { ok = false; break; }
          node = leafPair.value;
        }

        // healthcheck.test is one file line but two fields (the mode and the
        // command) — see harvestHealthTest() below — so it is pulled out
        // ahead of the generic one-leaf-one-field handling every other
        // LEAVES path gets.
        if (blockKey === 'healthcheck' && subPath === 'test') {
          harvestHealthTest(out, ok, leafPair, lines);
          continue;
        }

        var fullTarget = blockKey + '.' + subPath;
        var fullPath = [blockKey].concat(segs);

        if (ok && leafPair.value && leafPair.value.kind === 'scalar') {
          var t = settingTarget(leafPair, fullTarget, lines);
          t.path = fullPath;
          out.push(t);
        } else if (!ok) {
          out.push(target('setting', fullTarget, {
            parts: { value: part('', null) }, range: null, absent: true, path: fullPath
          }));
        }
      }
    }
  }

  /* ---- healthcheck.test: read as {mode, command}, written back as one line
   * ----------------------------------------------------------------------
   * A one-line flow list — ["CMD", "curl", "-f", "..."] — is the shape most
   * files use, and the parser seals it (scanValue, reason 'flow') because a
   * flow collection has its own quoting rules this parser does not attempt
   * to edit in place. PLAN_7.md section 2 weighs teaching the parser to open
   * a flow sequence against replacing the whole line on write, and lands on
   * the smaller of the two: readTest() below reads all four real shapes,
   * including the flow one off its own sealed .raw text, and writeTest()
   * (further down, beside the other structural writes) replaces the line
   * wholesale rather than editing inside it.
   */

  // What separates one piece of a healthcheck test line from the next.
  // Shared with commandSay()'s splitter in stacks.js — see splitQuoted()
  // below for why that lives here now rather than in two places.
  var SEP_WS    = /^\s/;          // argv on one line
  var SEP_COMMA = /^,/;           // a ["a", "b"] flow list

  // JSON's own backslash escapes, decoded inline by splitQuoted() below —
  // \u is deliberately not handled (real command text never needs it, and a
  // half-decoded \uXXXX is a smaller wrong than crashing on one).
  var JSON_ESCAPE = { '"': '"', '\\': '\\', '/': '/', n: '\n', t: '\t', r: '\r', b: '\b', f: '\f' };

  // The one quoted-token splitter every argv shape in this file (and
  // stacks.js's commandSay()) is built from. Walks the string treating ' and
  // " as opening and closing a quoted run, and only looks for a separator
  // outside one — so `-c "a && b"` is not chopped on its own spaces. Quote
  // characters are consumed, never kept, so tokens come back already
  // unwrapped. An unclosed quote means the text was never meant to be split
  // this way at all, so the whole thing is abandoned (null) rather than
  // guessed at.
  //
  // A double-quoted run also decodes backslash escapes as it goes — the only
  // way writeTest()'s own JSON.stringify'd elements (a command holding a
  // literal quote or backslash) can be read back at all. Single-quoted YAML
  // has no backslash escaping, so this only applies inside a double-quoted
  // run.
  //
  // Originally written in stacks.js for commandSay()'s plain-English gloss;
  // moved here because readTest()/writeTest() need the exact same splitter,
  // and stacks.js now calls this copy rather than keeping a second one.
  function splitQuoted(str, sep) {
    var tokens = [], buf = '', quote = '', i = 0, m;
    while (i < str.length) {
      var ch = str.charAt(i);
      if (quote) {
        if (quote === '"' && ch === '\\' && i + 1 < str.length) {
          var esc = str.charAt(i + 1);
          buf += JSON_ESCAPE[esc] !== undefined ? JSON_ESCAPE[esc] : esc;
          i += 2;
          continue;
        }
        if (ch === quote) quote = ''; else buf += ch;
        i++;
        continue;
      }
      if (ch === '"' || ch === "'") { quote = ch; i++; continue; }
      m = sep ? sep.exec(str.slice(i)) : null;
      if (m) {
        if (buf) { tokens.push(buf); buf = ''; }
        i += m[0].length;
        continue;
      }
      buf += ch;
      i++;
    }
    if (quote) return null;
    if (buf) tokens.push(buf);
    return tokens;
  }

  // Reads the argv out of a sealed flow list's own source text — "key: [...]"
  // as line one, same as a locked command/entrypoint's raw. Returns null
  // rather than the empty list when the text does not open with '[' at all,
  // so a caller never mistakes "could not find one" for "found an empty one".
  function parseFlowList(raw) {
    var idx = raw.indexOf(':');
    if (idx < 0) return null;
    var nl = raw.indexOf('\n');
    var lineOneTail = (nl < 0 ? raw.slice(idx + 1) : raw.slice(idx + 1, nl))
                        .replace(/^\s+|\s+$/g, '');
    if (lineOneTail.charAt(0) !== '[') return null;

    var fullTail = raw.slice(idx + 1);
    var open = fullTail.indexOf('['), close = fullTail.lastIndexOf(']');
    if (close < open) return null;

    var toks = splitQuoted(fullTail.slice(open + 1, close), SEP_COMMA);
    if (toks === null) return null;
    var out = [];
    for (var i = 0; i < toks.length; i++) {
      var t = toks[i].replace(/^\s+|\s+$/g, '');
      if (t) out.push(t);
    }
    return out.length ? out : null;
  }

  // The first element of a healthcheck argv is a mode word, not an argument —
  // shared by the flow and block-seq shapes, which only differ in how the
  // argv was found. CMD-SHELL takes exactly one argument (the whole shell
  // line); anything else there is not a shape this can confidently read.
  function argvToTest(argv) {
    if (!argv.length) return null;
    var head = argv[0].toUpperCase();
    if (head === 'NONE') return { mode: 'none', command: '' };
    if (head === 'CMD-SHELL') return argv.length === 2 ? { mode: 'shell', command: argv[1] } : null;
    if (head === 'CMD') return argv.length > 1 ? { mode: 'cmd', command: argv.slice(1).join(' ') } : null;
    return null;
  }

  // Reads healthcheck.test as { mode: 'shell'|'cmd'|'none', command: string },
  // across every shape a real compose file uses. Anything not confidently
  // read — an anchor, an alias, a multi-line scalar written oddly — returns
  // null, and the caller (harvestHealthTest) leaves it to the existing
  // locked/read-only path rather than guessing.
  function readTest(pair) {
    var v = pair && pair.value;
    if (!v) return null;

    if (v.kind === 'scalar') {
      // A bare string means CMD-SHELL by compose's own rule. An unquoted
      // NONE is the one exception — PLAN_8.md treats it the same as the
      // list form ["NONE"] — but a QUOTED "NONE" was written as a literal
      // string on purpose and is read as a (very odd) shell command instead.
      if (v.style === 'plain' && /^NONE$/i.test(v.value)) return { mode: 'none', command: '' };
      return { mode: 'shell', command: v.value };
    }

    if (v.kind === 'seq') {
      var argv = [];
      for (var i = 0; i < v.items.length; i++) {
        var it = v.items[i].value;
        if (!it || it.kind !== 'scalar') return null;
        argv.push(it.value);
      }
      return argvToTest(argv);
    }

    if (v.kind === 'opaque' && v.reason === 'flow') {
      var flow = parseFlowList(v.raw);
      return flow ? argvToTest(flow) : null;
    }

    return null;
  }

  // healthcheck.test's own two fields — "How the check runs" (the mode) and
  // "The check itself" (the command) — rather than one row, so both are
  // ordinary label/value/note rows and fieldHtml needs no new branch; CHOICES
  // (stacks.js) turns the mode one into a dropdown the same way any other
  // setting field's does.
  //
  // Both describe one physical line, so only one may own it for the things
  // that make no sense done twice. The command field gets the real
  // commentSpot (which resolves to a real spot only when the line is a plain
  // scalar — commentSpot() itself already returns null for anything else)
  // and the real range; boxHtml()/noteBoxHtml() already disable the note box
  // when commentSpot is null, so nothing new is needed there. The mode
  // field's commentSpot and range are always null — every other field's
  // range is unique, and giving both fields the same span would make the
  // Compose view's click-to-scroll ambiguous about which row it means.
  //
  // ok===true but readTest() returning null means there IS a test: line but
  // this cannot confidently read it — nothing is pushed here in that case,
  // and harvestBlock's own uncovered-children pass shows it whole and locked,
  // exactly as before this function existed.
  function harvestHealthTest(out, ok, leafPair, lines) {
    var read = ok ? readTest(leafPair) : null;
    if (ok && !read) return;

    var path  = ['healthcheck', 'test'];
    var range = ok ? { start: leafPair.leadStart, end: leafPair.end } : null;
    // Truthy but otherwise inert: the real edit happens in writeTest() via
    // testPart, never through this spot. It only has to (a) make the row
    // read as present the way every other in-file field does (see
    // fieldsFor()'s `single` check), and (b) point at a real line, so the
    // screenshot-sanitise pass — which reads spot.line/col if this field is
    // ever marked secret — cannot be handed a line that does not exist.
    var spot = ok ? { line: leafPair.start, col: 0, len: 0, style: 'plain', text: '' } : null;
    var isScalarComment = ok && leafPair.value && leafPair.value.kind === 'scalar';

    out.push(target('setting', 'healthcheck.test.mode', {
      parts: { value: part(read ? read.mode : '', spot) },
      range: null, absent: !ok, path: path, testPart: 'mode'
    }));
    out.push(target('setting', 'healthcheck.test.command', {
      parts: { value: part(read ? read.command : '', spot) },
      range: range, absent: !ok, path: path, testPart: 'command',
      comment: isScalarComment ? readComment(leafPair.value.comment) : undefined,
      commentSpot: ok ? commentSpot(leafPair.value, lines) : null
    }));
  }

  // Long-form depends_on: one field per dependency, each carrying its name
  // (a key, not a value) plus its condition, with restart/required folded
  // away below. PLAN_7.md is firm that this form and the short list form
  // (harvestList, above) are alternatives never to be converted between.
  //
  // A dependency written any way this cannot confidently open — the inline
  // flow form (db: {condition: ...}), or anything stranger — locks and stays
  // read-only, same as a sealed value everywhere else in this file.
  //
  // A name with nothing under it at all ("db:", value null) is legal-ish:
  // compose's own schema wants a condition, but nothing stops it being left
  // out. It is not special-cased — every lookup below already copes with
  // `depMap` being null, so it reads exactly like a map missing all three
  // settings, which is what it is.
  function harvestDependsLong(out, pair, lines) {
    var map = pair.value;
    for (var i = 0; i < map.keys.length; i++) {
      var name = map.keys[i];
      var depPair = map.pairs[name];
      var v = depPair.value;

      if (v && v.kind !== 'map') {
        var raw = v.raw || lines.slice(depPair.start, depPair.end)
                    .map(function (l) { return l.slice(depPair.indent); }).join('\n');
        out.push(lockedTarget('depends', 'depends_on.' + name,
          { start: depPair.leadStart, end: depPair.end },
          v.kind === 'opaque' ? lockReason(v.reason) : 'this is written in a way the form cannot read',
          raw));
        continue;
      }

      var depMap = v;
      var condPair = depMap ? depMap.pairs['condition'] : null;
      var condNode = condPair && condPair.value && condPair.value.kind === 'scalar' ? condPair.value : null;

      var row = target('depends', 'depends_on.' + name, {
        parts: {
          name:  part(name, keySpot(depPair)),
          value: condNode ? part(condNode.value, scalarSpot(condNode)) : part('', null)
        },
        range: { start: depPair.leadStart, end: depPair.end },
        comment: condNode ? readComment(condNode.comment) : undefined,
        commentSpot: condNode ? commentSpot(condNode, lines) : null,
        path: ['depends_on', name, 'condition']
      });
      // Namespace for the name part's dropdown (stacks.js's fromChoice()) —
      // set directly rather than through a listKey, since listKey also drives
      // the 1e dangling-reference check against parts.value, which here holds
      // the condition, not a reference at all (buildForm excludes binder
      // 'depends' from that check for exactly this reason).
      row.from = 'services';
      out.push(row);

      for (var fk in DEPENDS_LEAVES) {
        if (!DEPENDS_LEAVES.hasOwnProperty(fk)) continue;
        var fPair = depMap ? depMap.pairs[fk] : null;
        var fNode = fPair && fPair.value && fPair.value.kind === 'scalar' ? fPair.value : null;
        var fRow = target('depends', 'depends_on.' + name + '.' + fk, {
          parts: { value: fNode ? part(fNode.value, scalarSpot(fNode)) : part('', null) },
          range: fNode ? { start: fPair.leadStart, end: fPair.end } : null,
          comment: fNode ? readComment(fNode.comment) : undefined,
          commentSpot: fNode ? commentSpot(fNode, lines) : null,
          path: ['depends_on', name, fk],
          absent: !fNode
        });
        fRow.fold = true;
        out.push(fRow);
      }
    }
  }

  // networks: written as a map — one entry per network this service attaches
  // to, rather than a bare list of names. Same "list written as a map" shape
  // harvestDependsLong just handled, so it copies that shape: the name is the
  // mapping key (bound via keySpot() as parts.value, the row's one box, so
  // renaming it rewrites the key), and everything under it — the fixed IP a
  // map is usually written for, plus priority, aliases and the rest — is a
  // B3-style extra rather than a leaf table of its own, since there is no
  // fixed set of them worth naming individually.
  // The two extras worth adding from scratch on a network map entry (PLAN_34
  // phase 3c) — a fixed IPv4 address and a hardware address. Everything else
  // an entry can take (priority, aliases, driver_opts…) stays
  // editable-when-present via the ordinary harvestLongExtras() walk below;
  // offering all eight or so blank would be exactly the clutter the plan
  // rules out ("how many blank boxes the fold offers").
  var NETWORK_EXTRA_BLANKS = ['ipv4_address', 'mac_address'];

  // Every scalar-valued extra a network map entry can carry beyond its own
  // name — read by setPart()'s fourth creation case below to know which
  // missing part names are safe to insert as a plain "key: value" line.
  // aliases and link_local_ips are lists and driver_opts is a map, so none of
  // the three belongs here — inserting a bare scalar line under a key that
  // needs a list or a map would write something compose refuses to parse.
  // A superset of NETWORK_EXTRA_BLANKS: only two of these six are ever
  // offered blank in the fold, but all six become writable once a part
  // exists for them — "Free siblings" in the plan.
  var NETWORK_ENTRY_EXTRAS = ['ipv4_address', 'ipv6_address', 'mac_address',
                               'priority', 'gw_priority', 'interface_name'];

  function harvestNetworksMap(out, pair, lines) {
    var map = pair.value;
    for (var i = 0; i < map.keys.length; i++) {
      var name = map.keys[i];
      var netPair = map.pairs[name];
      var v = netPair.value;

      var row = target('list', name, {
        parts: { value: part(name, keySpot(netPair)) },
        range: { start: netPair.leadStart, end: netPair.end },
        listKey: 'networks',
        index: i
      });

      // Every entry is longForm now, even a bare "backend:" with nothing
      // under it — it still gets the two blank extras below, so the "more
      // settings" toggle (gated on f.longForm && f.longExtras.length in
      // stacks.js) has somewhere to hang them.
      row.longForm = true;
      if (v && v.kind === 'map') harvestLongExtras(row, v, [], lines);
      else row.longExtras = [];

      // Offer the fixed address and hardware address blank when the entry
      // does not already have a line for them — `creatable` is what keeps
      // boxHtml() in stacks.js from rendering the box disabled just because
      // it has no spot yet, the same way f.absent/f.path do for a whole field.
      for (var b = 0; b < NETWORK_EXTRA_BLANKS.length; b++) {
        var bk = NETWORK_EXTRA_BLANKS[b];
        if (row.parts[bk]) continue;
        row.parts[bk] = part('', null, '', '', true);
        row.longExtras.push(bk);
      }

      out.push(row);
    }
  }

  function harvest(serviceMap, lines) {
    var out = [], i, p;

    // The Container group's three rows come first, in a fixed order, whether
    // or not the file has them — so the field count for a service never
    // changes when one of them materialises (see fieldsFor()).
    for (i = 0; i < ALWAYS_KEYS.length; i++) {
      var akey = ALWAYS_KEYS[i];
      var spec = KEYS[akey];
      p = serviceMap.pairs[akey];

      var akeyTarget = p ? settingTarget(p, akey, lines)
                  : target('setting', akey, { parts: { value: part('', null) }, range: null, absent: true });

      // Neither of these is required (PLAN_21 section 1) — an empty image
      // only needs explaining when build: is why it's empty, and an empty
      // container_name is simply Docker's own default naming. image: and
      // build: are not mutually exclusive in compose, so when both are set
      // the note explains what the pair together means instead.
      var akeyVal = akeyTarget.parts.value ? akeyTarget.parts.value.value : null;
      if (akey === 'image' && serviceMap.pairs['build']) {
        if (!akeyVal) {
          akeyTarget.advice.push('this service builds its own image, so this is optional');
        } else {
          akeyTarget.advice.push('this names the image built here, rather than one to pull');
        }
      }
      if (akey === 'container_name' && !akeyVal) {
        akeyTarget.advice.push('leave this empty and Docker names the container itself, from the stack and service names');
      }

      out.push(akeyTarget);
    }

    // Web page port (PLAN_51) — fourth, straight after the three ALWAYS_KEYS
    // rows, so it lands in the Container group in the same fixed-order,
    // whether-or-not-the-file-has-it way those three do.
    out.push(harvestWebui(serviceMap, lines));

    harvestLeaves(out, serviceMap, lines);

    for (i = 0; i < serviceMap.keys.length; i++) {
      var key = serviceMap.keys[i];
      if (KEYS[key] && KEYS[key].always) continue;      // already emitted above
      p = serviceMap.pairs[key];
      var s = keySpec(key);

      if (s) {
        if (s.shape === 'list' && s.entry !== 'plain') {
          harvestList(out, s.entry, p, s.entry === 'port' ? splitPortShort : splitPathShort, lines, key);
          continue;
        }
        if (s.shape === 'list' && s.entry === 'plain') {
          if (p.value && p.value.kind === 'seq') { harvestList(out, 'list', p, splitPlain, lines, key); continue; }
          // depends_on and networks are the two 'plain list' keys that can
          // also be written as a map — depends_on one dependency per name
          // (its own condition/restart/required leaves), networks one
          // attachment per name (its own fixed-IP/priority leaves, see
          // harvestNetworksMap). secrets, configs and profiles never take
          // this form.
          if (key === 'depends_on' && p.value && p.value.kind === 'map') {
            harvestDependsLong(out, p, lines);
            continue;
          }
          if (key === 'networks' && p.value && p.value.kind === 'map') {
            harvestNetworksMap(out, p, lines);
            continue;
          }
          out.push(settingTarget(p, key, lines));   // the inline flow form, or anything else unreadable
          continue;
        }
        if (s.shape === 'pairs') {
          harvestPairs(out, key === 'environment' ? 'env' : 'label', p, lines);
          continue;
        }
        if (s.shape === 'scalar') { out.push(settingTarget(p, key, lines)); continue; }
        if (s.shape === 'block') { harvestBlock(out, key, p, lines); continue; }
      }

      // Compose has far more keys than the form has controls, and a key the
      // form says nothing about reads as a key the file does not have. Hand
      // every one left to settingTarget: a scalar becomes an editable
      // Advanced row, a block becomes a read-only one that shows itself and
      // says why.
      if (key === '<<' || key.slice(0, 2) === 'x-') continue;
      out.push(settingTarget(p, key, lines));
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
    return { line: node.line, col: col, len: line.length - col, pad: pad, text: line.slice(col) };
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
    // healthcheck.test's own two fields — checked ahead of the dotted-path
    // lookup below, which cannot find a title for '.mode'/'.command' since
    // LEAVES only names the whole leaf ('test'), not its two halves.
    if (t.testPart === 'mode') return 'How the check runs';
    if (t.testPart === 'command') return 'The check itself';
    // x-unraid.webui has a dot, so without this it would fall to the
    // dotted-path branch below, find no 'x-unraid' entry in LEAVES, and
    // humanise its way to 'Webui' (PLAN_51).
    if (t.target === 'x-unraid.webui') return 'Web page port';
    if (t.binder === 'setting' && KEYS[t.target] && KEYS[t.target].title) return KEYS[t.target].title;
    if (t.binder === 'port') return 'Port ' + t.target.split('/')[0];
    // depends_on's long form: 'depends_on.<name>' titles as the dependency's
    // own name, matching a short-form entry's title; the folded restart/
    // required settings beneath it ('depends_on.<name>.<leaf>') take their
    // title from DEPENDS_LEAVES instead.
    if (t.binder === 'depends') {
      var dsegs = t.target.split('.');
      return dsegs.length > 2 ? (DEPENDS_LEAVES[dsegs[2]] || humanise(dsegs[2])) : humanise(dsegs[1]);
    }
    if (t.target.charAt(0) === '@') return humanise(t.target.slice(1).split('#')[0]);
    // A still-empty list entry has no value to be named after — see
    // harvestList — so it borrows the name of the key it sits under.
    if (t.binder === 'list' && t.target === '') {
      return (KEYS[t.listKey] && KEYS[t.listKey].title) || humanise(t.listKey);
    }
    // A network's own name is a proper noun, not a phrase — humanise() would
    // split Unraid's VLAN names ("br0.2") into "Br0 2", which reads as a
    // different network than the one the file names. This is the service
    // row's own network entry, short form or long; the declaration row sets
    // its title the same way, separately, in declaredFields().
    if (t.binder === 'list' && t.listKey === 'networks') return t.target;
    // A nested leaf's target is dotted — 'healthcheck.interval' — so its
    // title lives in LEAVES rather than KEYS. A dotted target LEAVES does not
    // name (an uncovered child, e.g. deploy.mode) falls back to the heading
    // DESCRIPTIONS already carries for it — via dottedBucket(), the same
    // lookup fieldHelp() uses for the ⓘ bubble — so the row's label agrees
    // with its own help; only a target neither table names falls all the way
    // back to humanising the last segment.
    var dot = t.target.indexOf('.');
    if (t.binder === 'setting' && dot > 0) {
      var block = LEAVES[t.target.slice(0, dot)];
      var title = block && block[t.target.slice(dot + 1)];
      if (title) return title;
      var db = dottedBucket(t.target);
      var info = db && keyInfo(db.key, db.where);
      if (info) return info.title;
      var segs = t.target.split('.');
      return humanise(segs[segs.length - 1]);
    }
    // An undotted setting with no KEYS title still has a written name in
    // DESCRIPTIONS — the same table the ⓘ bubble beside this row reads from
    // — so the label agrees with its own help instead of showing the
    // mechanically humanised key. Extends the dotted branch above, which
    // already does this same lookup via dottedBucket(); this is that pattern
    // for undotted keys, not a new mechanism. Gated on binder === 'setting'
    // because a list entry's target is its own value, and a value that
    // happened to match a compose key name would otherwise be titled as
    // that key.
    if (t.binder === 'setting') {
      var si = keyInfo(t.target, 'service');
      if (si) return si.title;
    }
    return humanise(t.target);
  }

  // The booleans KEYS cannot name, because they are not top-level keys: a
  // leaf under healthcheck:, a dependency's required, a declaration's
  // external/internal/attachable. Without this they are judged by the value
  // they hold, which works while one is written and fails the moment it is
  // not — an absent field has no value to read, so it would fall to 'text',
  // render as a spelling test rather than a list, and write its boolean
  // quoted. Matters most for a DECL_LEAVES leaf harvestDeclLeaves() offers
  // blank (PLAN_34 phase 3b): driver and name are strings and ipam is never
  // offered blank at all, so external/internal/attachable are the only
  // declaration leaves that ever reach this with nothing to sniff a type from.
  function booleanTail(t) {
    var tail = String(t.target).split('.').pop();
    return (t.binder === 'setting'  && tail === 'disable')  ||
           (t.binder === 'depends'  && tail === 'required') ||
           (t.binder === 'declared' && (tail === 'external' || tail === 'internal' || tail === 'attachable'));
  }

  function inferType(t) {
    if (t.binder === 'port') return 'port';
    if (t.target === 'x-unraid.webui') return 'port';
    if (t.binder === 'volume' || t.binder === 'device') return 'path';
    if (t.binder === 'setting' && KEYS[t.target] && KEYS[t.target].type) return KEYS[t.target].type;
    if (t.binder === 'list') return (KEYS[t.listKey] && KEYS[t.listKey].type) || 'text';
    if (booleanTail(t)) return 'boolean';
    var v = t.parts.value ? t.parts.value.value : '';
    if (/^(true|false)$/i.test(v)) return 'boolean';
    if (/^-?\d+$/.test(v)) return 'number';
    return 'text';
  }

  // $$ is an escaped literal dollar, never a variable — strip pairs of it
  // before looking for a real ${...} or bare $VAR reference, so "$$LITERAL"
  // reads as plain text but "$$FOO ${BAR}" still carries the 1f advice.
  function interpolates(s) {
    var stripped = String(s).replace(/\$\$/g, '');
    return /\$\{|\$[A-Za-z_]/.test(stripped);
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
      // An absent Container slot has no spot yet, and a blocked one (see
      // network_mode's excludes check in harvest()) has none either — both
      // count as usable on their own rather than falling into `locked`.
      var usable  = hasHost || single || !!(t.parts.container && t.parts.container.spot) ||
                    t.absent || t.blocked;
      var fixed   = t.binder === 'setting' && ALWAYS_KEYS.indexOf(t.target) >= 0;

      // 1f: a value that reaches into a variable defined outside the file
      // (a .env entry, a shell export) stops being a plain string the moment
      // someone types over it — flag every part that carries one.
      var advice = t.advice.slice();
      for (var pk in t.parts) {
        if (t.parts.hasOwnProperty(pk) && t.parts[pk] && interpolates(t.parts[pk].value)) {
          advice.push('this value uses a variable defined outside the compose file — ' +
                       'typing over it replaces the variable with a fixed value');
          break;
        }
      }

      // A 'list' binder covers eight different compose keys, so its own value
      // is not enough to make an id unique — "networks: [web]" and
      // "depends_on: [web]" would otherwise both become "a/list/web". The same
      // problem hits port/volume/device/env/label, whose target is a container
      // port, container path or variable name — also not unique on its own.
      // The index is always appended when there is one, not just on a genuine
      // duplicate value — editing one entry to match another would otherwise
      // change an untouched sibling's id too (the edit-stability
      // docs/x-unraid-schema.md:317 asks for), and stacks.js looks a row up by
      // this exact id string in several places, so the shape must never come
      // in two forms.
      var listSpec = t.listKey && KEYS[t.listKey];
      var indexSuffix = typeof t.index === 'number' ? '#' + t.index : '';

      fields.push({
        id: serviceName + '/' + t.binder +
            (t.binder === 'list' ? '.' + t.listKey : '') + indexSuffix + '/' + t.target,
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
                    (!usable ? 'this has no value the form can edit' : ''),
        absent: t.absent,
        blocked: t.blocked,
        advice: advice,
        fixed: fixed,
        // Neither name is genuinely required: a service with build: and no
        // image: is valid compose, and nothing reads container_name's value
        // (see PLAN_21). Compose's own live check catches the case that
        // really is broken, so nothing here blocks Save any more — the
        // advice pushed in harvest() explains instead.
        fixedRequired: false,
        path: t.path || null,
        testPart: t.testPart || null,
        // Set only on the x-unraid.webui field (PLAN_51 follow-up) — true
        // when the address held a "[PORT:…]" marker, read by buildForm()'s
        // post-pass below to correct the displayed port once netKind is known.
        webuiToken: !!t.webuiToken,
        // A long-form port/volume entry (spelled-out target:/source: lines)
        // vs. the short "host:container" scalar — read by choiceFor() in
        // stacks.js, since the two forms need different handling for an
        // otherwise identical 'proto' part.
        longForm: !!t.longForm,
        // Extra part names harvestLongExtras() found beyond the main boxes,
        // in file order — read by fieldHtml()'s 'mapped' branch in stacks.js
        // to build the row's 'more settings' toggle.
        longExtras: t.longExtras || [],
        listKey: t.listKey || '',
        // Carried here rather than in stacks.js, so the KEYS table stays the
        // one place that knows a key's namespace and tool — the whole point
        // of consolidating it in Phase 1.
        groupTitle: listSpec ? (listSpec.title || humanise(t.listKey)) : '',
        // t.from wins when a target sets it directly — depends_on's long-form
        // row does (see harvestDependsLong), since its reference namespace
        // ('services') belongs on the name part, not the value part a listSpec
        // would otherwise hand it.
        from: t.from || (listSpec ? (listSpec.from || '') : ''),
        tool: listSpec ? (listSpec.tool || '') : '',
        // Set only by harvestDependsLong's restart/required rows — everything
        // else defaults to a falsy fold, same as before this existed.
        fold: !!t.fold
      });
    }
    return fields;
  }

  // Mirrors harvestLeaves() for a declaration's own settings (PLAN_34 phase
  // 3b): every DECL_LEAVES key the file lacks becomes a blank, writable fold
  // field instead of simply not appearing, so external/name/internal/
  // attachable can be created rather than only ever edited when the file
  // already has them. Two keys are deliberately left out —
  // driver (DECL_PRIMARY) is the row's own value box, not a fold entry, and
  // ipam is a map rather than a scalar, so a blank writable box for it would
  // misrepresent what a bare `ipam:` could hold; it stays the locked,
  // read-only row it already is. `path` is a single segment (a declaration's
  // leaves sit one level deep, unlike healthcheck's dotted ones), and is what
  // setPart() reads to route the eventual write through addDeclNested().
  //
  // `blockMap` is null for a bare declaration ("backend:" with nothing after
  // it) — still a complete, valid declaration, so every leaf below driver is
  // offered blank exactly as it would be for one already holding settings.
  function harvestDeclLeaves(out, kind, name, blockMap) {
    var leaves = DECL_LEAVES[kind] || {};
    var primaryKey = DECL_PRIMARY[kind];
    var pairs = blockMap && blockMap.kind === 'map' ? blockMap.pairs : {};
    for (var leafKey in leaves) {
      if (!leaves.hasOwnProperty(leafKey)) continue;
      if (leafKey === primaryKey || leafKey === 'ipam') continue;
      // Networks/volumes: this only ever runs for an ABSENT key (present ones
      // are skipped just below, already covered by harvestBlock's walk), and
      // an absent external has nothing to take over — the row's own box
      // already offers it via EXTERNAL_CHOICE — so skipping it here
      // unconditionally cannot hide a setting the fold alone would show.
      if (leafKey === 'external' && DECL_EXTERNAL_ROW[kind]) continue;
      if (pairs[leafKey]) continue;   // present — harvestBlock's walk already covers it
      out.push(target('declared', kind + '.' + name + '.' + leafKey, {
        parts: { value: part('', null) }, range: null, absent: true, path: [leafKey]
      }));
    }
  }

  /* ---- the file's own declarations: networks, volumes, secrets, configs -- */

  // A top-level declaration is a name with a map of settings under it — the
  // same shape fieldsFor() builds for a service, but there is no service to
  // hang it off, so this is a small parallel path rather than a detour
  // through fieldsFor (which is tied to one service's field ids, fixed
  // Container keys and list-key suffixes that a declaration has none of).
  //
  // Range choice: the row's range is deliberately only the declaration's OWN
  // name line ({leadStart, start+1}), not the whole pair down to `p.end` —
  // the fold's children live on the lines below and need their own ranges,
  // and buildIndex()/fieldAtLine() map one line to exactly one field, so the
  // row and its fold must never both claim the same span.
  function declaredFields(doc, lines) {
    var fields = [];
    var kinds = ['networks', 'volumes', 'secrets', 'configs'];

    for (var ki = 0; ki < kinds.length; ki++) {
      var kind = kinds[ki];
      var block = doc.root.pairs[kind];
      if (!block || !block.value || block.value.kind !== 'map') continue;

      for (var ni = 0; ni < block.value.keys.length; ni++) {
        var name = block.value.keys[ni];
        var pair = block.value.pairs[name];
        var primaryKey  = DECL_PRIMARY[kind];
        var primaryPair = pair.value && pair.value.kind === 'map' ? pair.value.pairs[primaryKey] : null;
        var primaryNode = primaryPair && primaryPair.value && primaryPair.value.kind === 'scalar'
                           ? primaryPair.value : null;

        // Networks/volumes: the row's one box takes external over too, but
        // only when it can actually represent it — a scalar. The deprecated
        // long form (`external: {name: foo}`) parses to a map and is left
        // exactly as it behaves today: folded leaf, driver box on the row.
        var externalPair = DECL_EXTERNAL_ROW[kind] && pair.value && pair.value.kind === 'map'
                            ? pair.value.pairs['external'] : null;
        var externalNode = externalPair && externalPair.value && externalPair.value.kind === 'scalar'
                            ? externalPair.value : null;

        // The row itself. A present value that is not a map — a flow map, an
        // anchor, an alias, a tag — parses to an 'opaque' node, the same shape
        // settingTarget() and harvestList() already lock via lockReason(); this
        // was the one path that never called it, so `hft: {name: br0.2,
        // external: true}` rendered as an ordinary row with an empty box —
        // indistinguishable from a bare `backend:`, while the file said
        // something. Lock it and show its own raw text instead. A null value
        // (a bare declaration with nothing after the colon) is untouched by
        // this: that is the normal, still-editable case Phase 1 depends on.
        var declOpaque = pair.value && pair.value.kind !== 'map';
        var rowTarget = declOpaque
          ? lockedTarget('declared', kind + '.' + name,
              { start: pair.leadStart, end: pair.start + 1 },
              lockReason(pair.value.reason), pair.value.raw)
          : target('declared', kind + '.' + name, {
              parts: {
                name: part(name, keySpot(pair)),
                // A scalar external overrides what the box shows, but never
                // where it points — the spot stays the driver's (or null,
                // when there is none), since setPart()'s declaration branch
                // resolves this case by key, not by writing through a spot.
                value: externalNode
                  ? part(/^(true|yes|on)$/i.test(String(externalNode.value).trim()) ? EXTERNAL_CHOICE
                           : (primaryNode ? primaryNode.value : ''),
                         primaryNode ? scalarSpot(primaryNode) : null)
                  : (primaryNode ? part(primaryNode.value, scalarSpot(primaryNode)) : part('', null))
              },
              range: { start: pair.leadStart, end: pair.start + 1 },
              comment: primaryNode ? readComment(primaryNode.comment) : undefined,
              commentSpot: primaryNode ? commentSpot(primaryNode, lines) : null
            });
        // lockedTarget() carries no name part, and a locked row does not render
        // the declaration's name — fieldHtml's locked branch short-circuits the
        // declared one, so nothing draws the name or its rename pencil. The ×
        // still draws though (showKill covers every declared row, locked or
        // not), and its handler reads f.parts.name.value unguarded to know
        // which declaration to remove. Without a name part, removing a locked
        // declaration throws and takes the whole page with it.
        if (declOpaque) rowTarget.parts.name = part(name, keySpot(pair));

        fields.push({
          id: '/declared/' + rowTarget.target,
          service: '', binder: rowTarget.binder, target: rowTarget.target,
          // A network's name is a proper noun (see inferTitle's own copy of
          // this reasoning for the service-row side of the same name) —
          // Unraid's VLAN names all carry a dot, so humanising this one
          // splits "br0.2" into "Br0 2" on every declaration row there is.
          title: kind === 'networks' ? name : humanise(name), type: 'text', mode: rowTarget.mode,
          parts: rowTarget.parts, note: rowTarget.note,
          sensitive: rowTarget.secret, required: rowTarget.required,
          commentSpot: rowTarget.commentSpot, raw: rowTarget.raw, range: rowTarget.range,
          locked: rowTarget.locked, lockReason: rowTarget.lockReason,
          absent: rowTarget.absent, blocked: rowTarget.blocked, advice: rowTarget.advice,
          fixed: false, fixedRequired: false, listKey: '',
          groupTitle: '', from: '', tool: '',
          declKind: kind, fold: false
        });

        // Everything else on the declaration, in the fold. Same shape as
        // healthcheck:, so harvestBlock does the walk — just pointed at
        // DECL_LEAVES under the kind, rather than LEAVES under the target.
        // An opaque value (flow map, anchor, alias) has no fold at all — Fix
        // 1 already locks the row entirely. A null value (a bare declaration
        // with nothing after the colon) is different: it is still a fully
        // editable, valid declaration, so harvestDeclLeaves() below must run
        // for it too — only harvestBlock's present-children walk needs an
        // actual map to walk.
        if (declOpaque) continue;

        var titleTable = DECL_LEAVES[kind] || {};
        var raw = [];
        if (pair.value && pair.value.kind === 'map') {
          harvestBlock(raw, kind + '.' + name, pair, lines, DECL_LEAVES, kind);
        }
        harvestDeclLeaves(raw, kind, name, pair.value);

        for (var ri = 0; ri < raw.length; ri++) {
          var t = raw[ri];
          var childKey = t.target.slice((kind + '.' + name + '.').length);
          if (childKey === primaryKey) continue;   // already the row's own value box
          // A scalar external is folded into that same box (as EXTERNAL_CHOICE)
          // whether true or false — externalNode is only set when it could be,
          // so this cannot skip the map/anchor/alias form the row can't show.
          if (childKey === 'external' && externalNode) continue;

          fields.push({
            id: '/declared/' + t.target,
            service: '', binder: 'declared', target: t.target,
            title: titleTable[childKey] || humanise(childKey),
            type: inferType(t), mode: t.mode, parts: t.parts, note: t.note,
            sensitive: t.secret, required: t.required, commentSpot: t.commentSpot,
            raw: t.raw, range: t.range, locked: t.locked, lockReason: t.lockReason,
            absent: t.absent, blocked: t.blocked, advice: t.advice,
            fixed: false, fixedRequired: false, listKey: '',
            groupTitle: '', from: '', tool: '',
            // path/declName are read by setPart() to route a create through
            // addDeclNested() — only ever set on a field harvestDeclLeaves()
            // built, since an already-present leaf writes back through its
            // own spot the ordinary way.
            path: t.path || null, declName: name,
            declKind: kind, fold: true
          });
        }
      }
    }
    return fields;
  }

  // The driver behind one network name, for working out a service's network
  // kind (see the buildForm post-pass below). A network this file declares
  // itself always wins — its own driver line is the truth regardless of what
  // this server happens to be running today. EXTERNAL_CHOICE means the
  // declaration only says "this exists elsewhere", so it tells us nothing and
  // falls through to what the server actually reports. Returns null, not '',
  // when nothing resolves it at all, so the caller can tell "definitely not
  // macvlan/ipvlan" from "no idea".
  function resolveNetDriver(out, name, netDrivers) {
    // A declared block's own fields carry no .service, so they all sit under
    // the '' key of fieldsByService — a much shorter list to scan than every
    // field in the file, which is what this used to walk once per attached
    // network per service.
    var declFields = (out.fieldsByService && out.fieldsByService['']) || out.fields;
    for (var i = 0; i < declFields.length; i++) {
      var f = declFields[i];
      if (f.binder === 'declared' && f.declKind === 'networks' && f.target === 'networks.' + name) {
        var v = f.parts.value ? f.parts.value.value : '';
        if (v !== EXTERNAL_CHOICE) return v || '';
        break;
      }
    }
    if (netDrivers && netDrivers.hasOwnProperty(name)) return netDrivers[name];
    return null;
  }

  // The "[PORT:nnn]" marker a webui address can carry in place of a literal
  // port, e.g. the "80" in "http://[IP]:[PORT:80]/". This is the SAME
  // expression ca-convert.js's reorderPortsForWebUI() uses to read the same
  // marker — kept identical by comment rather than shared code, since each
  // file runs in its own IIFE scope. Held as one constant rather than
  // written inline twice, so webuiPortToken() and splitWebuiPort() below
  // (PLAN_51) can never drift apart.
  var WEBUI_PORT_TOKEN_RE = /\[PORT:([^\]]*)\]/i;

  // The token's own value as text, e.g. "80" out of "[PORT:80]". Returns ''
  // rather than a number: every caller only ever compares this against other
  // port keys as text.
  function webuiPortToken(webui) {
    var m = WEBUI_PORT_TOKEN_RE.exec(String(webui || ''));
    return m ? m[1].trim() : '';
  }

  // Splits a webui address into the port substring and what surrounds it, so
  // the Web page port field (PLAN_51) can rewrite just the port and leave the
  // scheme, host and any path untouched — the same pre/value/post shape
  // splitPortShort() uses for a port row's own host/container halves. Every
  // shape seen in a real file:
  //
  //   http://[IP]:[PORT:8181]/     -> pre "http://[IP]:"        value "8181" post "/"
  //   http://[IP]:80/              -> pre "http://[IP]:"        value "80"   post "/"
  //   http://192.168.200.88:5000   -> pre "http://192.168.200.88:" value "5000" post ""
  //   http://[IP]/admin            -> pre "http://[IP]:"        value ""     post "/admin"
  //
  // The whole "[PORT:…]" token counts as the port when one is there — writing
  // over it is what turns a guessed address into a literal one, for good.
  // Failing that, the port is whatever sits after the LAST colon inside the
  // authority (after the scheme's own "://" and before any path) — a scheme
  // colon or one inside a hostname never qualifies, since neither is where a
  // port could be written. No colon at all means no port yet: pre ends with
  // one ready for a value, and post is everything from the authority's end
  // onward (the path, if any).
  function splitWebuiPort(text) {
    var s = String(text || '');
    var scheme = /^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//.exec(s);
    var afterScheme = scheme ? scheme[0].length : 0;
    var slash = s.indexOf('/', afterScheme);
    var authorityEnd = slash < 0 ? s.length : slash;

    // webuiPortToken() itself only answers "what is the value", not "where is
    // it" — this needs both, so the token is located again here rather than
    // returned from there. Located ahead of the plain-colon branch below:
    // "[PORT:80]" holds a colon of its own that is not the one to split on.
    var tokenValue = webuiPortToken(s);
    if (tokenValue !== '') {
      var token = WEBUI_PORT_TOKEN_RE.exec(s);
      if (token.index >= afterScheme && token.index < authorityEnd) {
        return { pre: s.slice(0, token.index), value: tokenValue,
                  post: s.slice(token.index + token[0].length), token: true };
      }
    }

    var colon = s.lastIndexOf(':', authorityEnd - 1);
    if (colon >= afterScheme) {
      return { pre: s.slice(0, colon + 1), value: s.slice(colon + 1, authorityEnd),
                post: s.slice(authorityEnd), token: false };
    }

    return { pre: s.slice(0, authorityEnd) + ':', value: '', post: s.slice(authorityEnd), token: false };
  }

  // The one thing inside x-unraid the form can edit (PLAN_51) — a single
  // named exception, not a general x-unraid renderer. The port lives inside
  // the web address as a substring, so it uses the same part() shape a port
  // row's own halves do: one part rebuilds the whole scalar around the piece
  // it owns.
  //
  // No `path` on this target, on purpose — setPart()'s f.path branches
  // delete the whole key when the box is cleared and create a bare scalar
  // when it is filled in, and both are wrong for a single port inside an
  // address. setPart() recognises this field by its target string instead
  // and handles both the write and the from-nothing create itself.
  function harvestWebui(serviceMap, lines) {
    var xu = serviceMap.pairs['x-unraid'];
    var xmap = xu && xu.value && xu.value.kind === 'map' ? xu.value : null;
    var wu = xmap ? xmap.pairs['webui'] : null;
    var scalar = wu && wu.value && wu.value.kind === 'scalar' ? wu.value : null;

    if (!scalar) {
      // No x-unraid block, no webui key, or one written in a shape this
      // parser cannot read as plain text — renders as an empty, writable
      // box, the same as an absent Container row (`absent: true` is what
      // fieldsFor() reads to count a spotless part as usable rather than
      // locked; `creatable` on the part itself is boxHtml()'s own,
      // independent read of the same fact). Writing it has to build the
      // whole address from scratch, not a bare scalar, so setPart() routes
      // this field by its target string rather than through the ordinary
      // absent-Container path that would otherwise do that.
      return target('setting', 'x-unraid.webui', {
        parts: { value: part('', null, '', '', true) },
        absent: true
      });
    }

    var bits = splitWebuiPort(scalar.value);
    var wt = target('setting', 'x-unraid.webui', {
      parts: { value: part(bits.value, scalarSpot(scalar), bits.pre, bits.post) },
      range: { start: wu.leadStart, end: wu.end },
      comment: readComment(scalar.comment),
      commentSpot: commentSpot(scalar, lines)
    });
    // Recorded here rather than re-parsed later: buildForm()'s post-pass needs
    // to know whether the displayed number came from a "[PORT:…]" marker
    // (measured-unreliable — see the pass below) or is a literal the address
    // already carried, and the displayed value alone cannot tell those apart.
    wt.webuiToken = !!bits.token;
    return wt;
  }

  // netDrivers: name -> driver for this server's own docker networks, from
  // stacks.js's netDrivers() — null/omitted means "not answered yet", which
  // this treats exactly like "no networks", so every one of the ~25 existing
  // callers (and tests/yaml_roundtrip.js) that omit it keep seeing bridge
  // everywhere, unchanged.
  function buildForm(doc, netDrivers) {
    // `declared` is seeded here rather than where it is filled in, because both
    // early returns below happen first — a caller reading declared.networks on
    // an unreadable file must find an empty list, not undefined.
    var out = { stack: {}, services: [], fields: [], warnings: [], sealed: doc.sealed, ok: true,
                declared: { networks: [], volumes: [], secrets: [], configs: [], services: [] },
                includes: [] };

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

    // Top-level include: — read before the no-services return below, since an
    // include-only file (PLAN_20's measured case) is exactly what that branch
    // covers. A list entry written as a map ("- path: other.yaml") is skipped
    // rather than guessed at, same as fileRefs() treats the same shape.
    var incPair = doc.root.pairs['include'];
    if (incPair && incPair.value) {
      if (incPair.value.kind === 'scalar') {
        out.includes.push({ file: incPair.value.value, line: incPair.value.line });
      } else if (incPair.value.kind === 'seq') {
        for (var ii = 0; ii < incPair.value.items.length; ii++) {
          var incItem = incPair.value.items[ii].value;
          if (incItem && incItem.kind === 'scalar') {
            out.includes.push({ file: incItem.value, line: incItem.line });
          }
        }
      }
    }

    var svc = doc.root.pairs['services'];
    if (!svc || !svc.value || svc.value.kind !== 'map') {
      out.warnings.push({ line: 0, message: 'This file lists no services.' });
      return out;
    }

    // The top-level blocks are a namespace of names declared once and
    // referenced by services — read one level deep, no recursion. A name
    // with a null value (declared, nothing under it) still counts, because
    // .keys lists it regardless of what its value turned out to be.
    out.declared.services = svc.value.keys.slice();
    var declKinds = ['networks', 'volumes', 'secrets', 'configs'];
    for (var dk = 0; dk < declKinds.length; dk++) {
      var dp = doc.root.pairs[declKinds[dk]];
      if (dp && dp.value && dp.value.kind === 'map') out.declared[declKinds[dk]] = dp.value.keys.slice();
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

    // The Stack section: the file's own networks/volumes/secrets/configs,
    // appended after every service's fields. Order in the array does not
    // matter — data-row is an index into it — so appending leaves every
    // existing field's index untouched.
    out.fields = out.fields.concat(declaredFields(doc, doc.lines));

    // Two lookups built once the field list is final (nothing below this
    // point adds a field, only edits ones already here) — everything past
    // here that used to re-scan out.fields per service or per row now reads
    // off these instead. Keyed by names straight out of the compose file, so
    // Object.create(null) rather than {} — a service or network called
    // "constructor" must not read back as a field belonging to Object.prototype.
    var fieldsByService = Object.create(null);
    for (var mi = 0; mi < out.fields.length; mi++) {
      var mf0 = out.fields[mi];
      var msvc = mf0.service || '';
      if (!fieldsByService[msvc]) fieldsByService[msvc] = [];
      fieldsByService[msvc].push(mf0);
    }
    out.fieldsByService = fieldsByService;
    out.fieldIndex = Object.create(null);
    for (var mj = 0; mj < out.fields.length; mj++) out.fieldIndex[out.fields[mj].id] = out.fields[mj];

    // 1e: a field that names a network, volume, secret or service the file
    // never declares is an error compose only reports at start — flag it
    // here instead. Read off f.from, which fieldsFor already worked out from
    // KEYS[...].from, so a volume's own reference and a Phase 2 plain-entry
    // reference (networks, secrets, configs, depends_on) are found the same
    // way rather than needing a second lookup through LIST_KEY, which cannot
    // resolve a 'list' binder back to the one key a given field lives under.
    for (var fi = 0; fi < out.fields.length; fi++) {
      var f = out.fields[fi];
      // A depends_on long-form row also carries f.from (see harvestDependsLong)
      // so its name part gets the same services dropdown every other from
      // field offers, but its OWN parts.value is the condition, not a
      // reference — checking it here would flag "service_healthy" as an
      // undeclared service. Excluded rather than taught a second reading of
      // parts.value, since nothing else with a `from` has this split.
      if (!f.from || f.binder === 'depends') continue;

      var val = f.parts.host ? f.parts.host.value : (f.parts.value ? f.parts.value.value : '');
      if (!val || val.indexOf('${') >= 0) continue;
      if (f.parts.host && val.indexOf('/') >= 0) continue;      // a path, not a named reference
      if (f.from === 'networks' && val === 'default') continue;

      if (out.declared[f.from].indexOf(val) < 0) {
        f.advice.push('no ' + FROM_WORD[f.from] + ' called ' + val + ' is defined in this file');
        // Networks only: an Unraid network (br0.2 and the like) is the
        // overwhelming case here, and declaring it is one predictable line
        // (see declareNetwork()) — volumes/secrets/configs/services have no
        // such single obvious fix, so they keep the plain sentence.
        if (f.from === 'networks') f.declareMissing = val;
      }
    }

    // PLAN_49: the kind of network each service is actually on — host,
    // something addressed on its own (macvlan/ipvlan), or bridge. Read ONLY
    // by the port-row sentences just below and by newEntry()'s Add button;
    // never by boxHtml()'s greying, which stays driven purely by whether the
    // file has somewhere to write a value. Bridge is what every unresolved
    // case becomes, on purpose — guessing "other" from missing information
    // would show the wrong sentence on every stack for the moment before the
    // network list arrives.
    var netKindOf = {};
    for (var ski = 0; ski < out.services.length; ski++) {
      var skName = out.services[ski].name;
      var skKind = 'bridge';
      var skFields = fieldsByService[skName] || [];

      var modeVal = '';
      for (var mfi = 0; mfi < skFields.length; mfi++) {
        var mf = skFields[mfi];
        if (mf.binder === 'setting' && mf.target === 'network_mode') {
          modeVal = mf.parts.value ? String(mf.parts.value.value).trim() : '';
          break;
        }
      }

      if (modeVal === 'host' || modeVal.indexOf('container:') === 0 || modeVal.indexOf('service:') === 0) {
        skKind = 'host';
      } else {
        for (var nfi = 0; nfi < skFields.length; nfi++) {
          var nf = skFields[nfi];
          if (nf.binder !== 'list' || nf.listKey !== 'networks') continue;
          var attachedName = nf.parts.value ? nf.parts.value.value : '';
          if (!attachedName) continue;
          var attachedDriver = resolveNetDriver(out, attachedName, netDrivers);
          if (attachedDriver === 'macvlan' || attachedDriver === 'ipvlan') { skKind = 'other'; break; }
        }
      }

      netKindOf[skName] = skKind;
      out.services[ski].netKind = skKind;
    }

    // The sentence under a port row on a host/macvlan/ipvlan service. Written
    // as advice, not lockReason: advice is rebuilt by refreshRanges() on every
    // commit, so a live edit to network_mode updates the sentence at once,
    // where a lockReason (stamped once here) would go stale until the next
    // full reparse. The old lockReason describes a bridge container and is
    // simply wrong on these two kinds, so it is cleared here too — otherwise
    // the row would show its false line AND the true one together.
    for (var pfi = 0; pfi < out.fields.length; pfi++) {
      var pf = out.fields[pfi];
      if (pf.binder !== 'port') continue;
      pf.netKind = netKindOf[pf.service] || 'bridge';
      if (pf.netKind !== 'host' && pf.netKind !== 'other') continue;

      if (pf.lockReason === 'compose picks the host port for this one' ||
          pf.lockReason === 'no host port is set here') {
        pf.lockReason = '';
      }

      var pfHost = pf.parts.host ? pf.parts.host.value : '';
      var pfContainer = pf.parts.container ? pf.parts.container.value : '';

      if (pfHost && pfContainer) {
        pf.advice.push(pf.netKind === 'host'
          ? 'This container shares the server\'s network, so the outer number here is ignored — the port inside the container is the one the web button opens.'
          : 'This container has its own address, so the outer number here is ignored — the port inside the container is the one the web button opens.');
      } else {
        pf.advice.push(pf.netKind === 'host'
          ? 'This container shares the server\'s network, so the port inside the container is the port on the server.'
          : 'This container has its own address, so only the port inside the container matters.');
      }
    }

    // The Web page port field (PLAN_51) started out just showing whatever a
    // "[PORT:nnn]" marker said, but that number is the one thing measured as
    // unreliable on real files — the server ignores it outright and works the
    // port out from the service's own ports: list instead (see
    // staxx_webui_url()'s doc comment). Showing nnn as-is would let a save
    // write it in literally and silently turn a working link into a broken
    // one, so once netKind is known (just above) the field is corrected to
    // the number the button opens right now: the published (outside) half of
    // the FIRST ports: entry on a bridge service, the target (inside) half on
    // host/macvlan/ipvlan, and the marker's own number whenever there is no
    // half to read it from — no ports: at all, or a one-sided entry with
    // nothing on the side that kind needs. A literal address (webuiToken
    // false) is left exactly as harvestWebui() built it.
    for (var wsi = 0; wsi < out.services.length; wsi++) {
      var wsName = out.services[wsi].name;
      var webuiField = null, firstPortField = null;
      for (var wfi = 0; wfi < out.fields.length; wfi++) {
        var wf = out.fields[wfi];
        if (wf.service !== wsName) continue;
        if (!webuiField && wf.binder === 'setting' && wf.target === 'x-unraid.webui') webuiField = wf;
        if (!firstPortField && wf.binder === 'port' && wf.listKey === 'ports') firstPortField = wf;
      }
      if (!webuiField || !webuiField.webuiToken || !webuiField.parts.value) continue;

      var wsKind = netKindOf[wsName] || 'bridge';
      if (firstPortField) {
        var fpHost = firstPortField.parts.host ? firstPortField.parts.host.value : '';
        var fpContainer = firstPortField.parts.container ? firstPortField.parts.container.value : '';
        if (wsKind === 'bridge') {
          if (fpHost) webuiField.parts.value.value = fpHost;
        } else if (fpContainer) {
          webuiField.parts.value.value = fpContainer;
        }
      }
      // else: no ports: entry at all — leave the marker's own number showing,
      // already what parts.value.value holds.
    }

    // service_healthy only ever comes true if the named service really has a
    // healthcheck: — PLAN_7.md calls this the mistake a hand-edited file gets
    // wrong most often, so say so on the row rather than let someone pick a
    // condition that can never be met. Checked once every service's fields
    // are in out.fields, since it has to look at a DIFFERENT service's own
    // healthcheck leaves — harvestLeaves() offers all seven whether or not
    // the file has them, so "has one" means "at least one is not absent".
    for (var di = 0; di < out.fields.length; di++) {
      var df = out.fields[di];
      if (df.binder !== 'depends' || df.fold) continue;
      var depCond = df.parts.value ? df.parts.value.value : '';
      var depName = df.parts.name ? df.parts.name.value : '';
      if (depCond !== 'service_healthy' || !depName) continue;

      var hasCheck = false;
      var depFields = fieldsByService[depName] || [];
      for (var hi = 0; hi < depFields.length && !hasCheck; hi++) {
        if (depFields[hi].target.indexOf('healthcheck.') === 0 && !depFields[hi].absent) hasCheck = true;
      }
      if (!hasCheck) {
        df.advice.push('"' + depName + '" has no health check, so this will never come true — ' +
                        'add one to "' + depName + '", or choose a different condition here');
      }
    }

    // Docker refuses a fixed container name together with more than one
    // replica of the same service outright — flag both rows rather than let
    // the combination fail only on save. Only fires on a plain integer: a
    // replicas value written as '${COPIES}' or anything else that doesn't
    // parse already gets the interpolation note, and guessing past that would
    // be wrong as often as right.
    for (var ci = 0; ci < out.fields.length; ci++) {
      var cf = out.fields[ci];
      if (cf.target !== 'container_name') continue;
      var nameVal = cf.parts.value ? cf.parts.value.value : '';
      if (!nameVal) continue;

      var rf = null;
      var cfFields = fieldsByService[cf.service] || [];
      for (var rj = 0; rj < cfFields.length; rj++) {
        if (cfFields[rj].target === 'deploy.replicas') { rf = cfFields[rj]; break; }
      }
      if (!rf) continue;

      var repVal = rf.parts.value ? String(rf.parts.value.value).trim() : '';
      if (!/^\d+$/.test(repVal) || parseInt(repVal, 10) <= 1) continue;

      cf.advice.push('Docker refuses this together with more than one copy below — clear one of the two');
      rf.advice.push('Docker refuses more than one copy together with a fixed container name — clear one of the two');
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
    // buildForm() always stamps fieldIndex once its fields are final; the
    // scan is only a fallback for a form object built some other way.
    if (form.fieldIndex) return form.fieldIndex[id] || null;
    for (var i = 0; i < form.fields.length; i++) {
      if (form.fields[i].id === id) return form.fields[i];
    }
    return null;
  }

  // Writes one box back into the document. `which` is 'value' for a plain
  // setting, or 'host' / 'container' for the two halves of a port or a mount.
  // Returns false when the box cannot be written — a refusal, not a failure,
  // and the form says so rather than guessing.
  // healthcheck.test's sibling — whichever of the mode/command pair `f` is
  // not — so setPart() can read the half that did not just change.
  function siblingTestField(form, f) {
    var want = f.testPart === 'mode' ? 'command' : 'mode';
    for (var i = 0; i < form.fields.length; i++) {
      var g = form.fields[i];
      if (g.service === f.service && g.testPart === want) return g;
    }
    return null;
  }

  function setPart(doc, form, id, which, value) {
    var f = fieldById(form, id);
    if (!f || f.locked) return false;
    var p = f.parts[which];
    // A locked part (an unreadable child of a long-form entry) has no spot,
    // so without this check it would fall into the phase-3c creation branch
    // below for any name that happens to be in NETWORK_ENTRY_EXTRAS and write
    // a second copy of a key the file already has.
    if (p && p.locked) return false;

    // The fourth creation case (PLAN_34 phase 3c): a known scalar extra a
    // network map entry's own map does not have a line for yet. `p` is
    // either absent altogether (an extra nothing pre-populated — priority,
    // interface_name…) or a blank `creatable` part harvestNetworksMap()
    // offered (ipv4_address, mac_address) — either way there is no spot to
    // write through, so this is checked ahead of the `if (!p) return false;`
    // below rather than folded into it. Scoped to `networks` map entries
    // only for now, matching the plan's own scope guard — ports, volumes,
    // secrets and configs entries still refuse an unwritten extra.
    if ((!p || !p.spot) && f.binder === 'list' && f.listKey === 'networks' && f.longForm &&
        NETWORK_ENTRY_EXTRAS.indexOf(which) >= 0) {
      if (!String(value).trim()) return true;
      var netEntry = networkEntryPair(doc, f.service, f.target);
      if (!netEntry) return false;
      return insertChild(doc, netEntry, which, value, null, false) >= 0;
    }
    if (!p) return false;

    // The web-address port (PLAN_51) — the one field that reaches inside
    // x-unraid. Named explicitly rather than routed through the path-based
    // branches below: f.path is deliberately left unset (see harvestWebui()),
    // since both of those branches are wrong here — one deletes the whole
    // key when the box is cleared, the other creates a bare scalar when it
    // is filled in, and this field must do neither. A spot already there is
    // rewritten in place with a real address around it (a bare colon with no
    // digits after it counts as clearing the port, not deleting the line); no
    // spot means there is no webui: line yet, so filling it in has to build
    // one from scratch instead.
    if (f.target === 'x-unraid.webui' && which === 'value') {
      if (p.spot) {
        if (String(value) === String(p.value)) return true;
        return writeScalar(doc, p.spot, p.pre + value + p.post, p.spot.style);
      }
      if (!String(value).trim()) return true;
      // [IP] is what lets the plugin resolve a macvlan container's own
      // address rather than the server's — see staxx_webui_url() (PHP).
      return addNested(doc, form, f.service, ['x-unraid', 'webui'], 'http://[IP]:' + value + '/') >= 0;
    }

    // healthcheck.test is one file line shown as two fields (see
    // harvestHealthTest() in compose-model.js), so editing either one has to
    // write both together — read live off the sibling rather than assumed,
    // since the sibling may itself be mid-edit. Checked ahead of the f.path
    // branch below: both test fields carry a path too (for fieldsFor()'s
    // bookkeeping), but must never reach addNested()/removeKey()'s flat,
    // one-key writes, which know nothing about the two-field split.
    //
    // Switching MODE is always written, even with a blank command — a
    // deliberate pick must never be a silent no-op, because nothing would
    // ever prompt a retry: the dropdown would show the new choice forever
    // while the file kept the old one, and every later edit to the command
    // box would go on being combined with the mode the file still has
    // (see PLAN_14.md). A blank COMMAND edit is the one case that keeps the
    // existing blank-writes-nothing leniency, because there the line already
    // says something sensible and typing-then-clearing should not disturb it.
    //
    // "No check" has no room for a command at all, so choosing it lets the
    // typed line go — but throwing it away outright is hostile if the switch
    // was a mis-click. It is kept on `doc` itself, not written into the file
    // (nothing else persists an in-progress edit nobody asked to save), and
    // handed back the moment the mode returns to one that can hold it again.
    if (f.testPart) {
      if (which !== 'value') return false;
      var sib = siblingTestField(form, f);
      if (!sib) return false;
      var modeField = f.testPart === 'mode' ? f : sib;
      var wasNone = !modeField.absent && modeField.parts.value.value === 'none';
      var mode    = f.testPart === 'mode'    ? value : (modeField.absent ? 'shell' : modeField.parts.value.value);
      var command = f.testPart === 'command' ? value : sib.parts.value.value;

      if (f.testPart === 'mode' && mode === 'none' && String(command).trim()) {
        doc.healthCmdStash = doc.healthCmdStash || {};
        doc.healthCmdStash[f.service] = command;
      } else if (f.testPart === 'mode' && wasNone && mode !== 'none' && !String(command).trim() &&
                 doc.healthCmdStash && doc.healthCmdStash[f.service]) {
        command = doc.healthCmdStash[f.service];
      }

      if (f.testPart === 'command' && (mode === 'shell' || mode === 'cmd') && !String(command).trim()) return true;
      return writeTest(doc, form, f.service, mode, command);
    }

    // A LEAVES sub-path — healthcheck.interval, deploy.resources.limits.cpus
    // — is a broken file when blank: compose rejects "interval: ''". So
    // clearing one that already has a line removes the line (and any parent
    // it leaves empty) instead of writing an empty value, via the same walk
    // Phase 3's whole-block removal uses.
    if (f.path && which === 'value' && p.spot && !String(value).trim()) {
      return removeKey(doc, form, f.service, f.path);
    }

    // Networks/volumes: the row's one box answers "driver, or created
    // outside this file" — the two can never both be written, so a swap
    // between them is a key change, not a text edit, and has to be handled
    // before the ordinary paths below (the in-place overwrite included,
    // since a file may already have a driver line whose spot this would
    // otherwise just be rewritten through). Guarded to fire only on an
    // actual swap — a plain rename of one driver to another never touches
    // this and keeps its line, comment and quoting via the overwrite below.
    if (f.binder === 'declared' && !f.path && which === 'value' && DECL_EXTERNAL_ROW[f.declKind] &&
        (value === EXTERNAL_CHOICE || p.value === EXTERNAL_CHOICE)) {
      if (String(value) === String(p.value)) return true;

      var declName = f.target.slice(f.declKind.length + 1);
      var primaryKey0 = DECL_PRIMARY[f.declKind];
      var declPair0 = declPairOf(doc, f.declKind, declName);
      if (!declPair0) return false;
      if (declPair0.value && declPair0.value.kind !== 'map') return false;

      // Drop both candidate lines unconditionally, whichever the file
      // actually had — the only way to guarantee the new one never lands as
      // a duplicate key beside a stale driver or external line.
      removeDeclKey(doc, f.declKind, declName, [primaryKey0]);
      removeDeclKey(doc, f.declKind, declName, ['external']);

      if (value === EXTERNAL_CHOICE) {
        return insertChild(doc, declPairOf(doc, f.declKind, declName), 'external', 'true', null, true) >= 0;
      }
      if (!String(value).trim()) return true;   // both gone is already a bare, valid declaration
      return insertChild(doc, declPairOf(doc, f.declKind, declName), primaryKey0, value) >= 0;
    }

    if (!p.spot) {
      // A declaration with nothing under it yet — "frontend_net:" and no
      // driver line — needs a nested insert rather than addSetting's
      // service-level one. A blank value leaves it exactly as it was: a
      // bare declaration is already a complete, valid one (an ordinary
      // bridge network), so there is nothing to write.
      if (f.binder === 'declared' && !f.path && which === 'value') {
        if (!String(value).trim()) return true;
        var block = doc.root.pairs[f.declKind];
        var declPair = block && block.value && block.value.kind === 'map'
                        ? block.value.pairs[f.target.slice(f.declKind.length + 1)] : null;
        var primaryKey = DECL_PRIMARY[f.declKind];
        if (!declPair || !primaryKey) return false;
        // insertChild() appends an indented child under declPair's own value
        // node, which only has somewhere to put one when that node is a map
        // (settings already exist) or absent (still a bare declaration). A
        // flow map, anchor or alias parses to an 'opaque' node that cannot
        // take a child — appending one there is what produced a file real
        // compose refused to parse at all, not merely one it read oddly.
        // Fix 1 already locks the row so this control is never offered, but
        // refuse here too rather than trust that no later path ever reaches
        // this with an unlocked field — belt and braces for one bug.
        if (declPair.value && declPair.value.kind !== 'map') return false;
        return insertChild(doc, declPair, primaryKey, value) >= 0;
      }
      // A declaration's own absent leaf (external, name, internal,
      // attachable — PLAN_34 phase 3b) needs addDeclNested() rather than
      // addNested(): a declaration has no enclosing service, and
      // declaredFields() builds these fields with `service: ''`, so
      // addNested's serviceMapOf(doc, '') lookup would just fail. Checked
      // ahead of the generic f.path branch below for exactly that reason —
      // both carry a path, but only one of them has a service to resolve.
      if (f.binder === 'declared' && f.path && which === 'value') {
        if (!String(value).trim()) return true;
        return addDeclNested(doc, form, f.declKind, f.declName, f.path, value, f.type === 'boolean') >= 0;
      }
      // A LEAVES sub-path with no line yet. Its target is dotted
      // ("healthcheck.interval"), which is not itself a compose key, so it
      // needs addNested's walk rather than addSetting's flat insert — but
      // the blank-writes-nothing rule below is the same one, for the same
      // reason.
      if (f.path && which === 'value') {
        if (!String(value).trim()) return true;
        // Same bare choice as the overwrite path below: a freshly-created
        // healthcheck.disable line must be a real boolean, not a quoted one.
        return addNested(doc, form, f.service, f.path, value, f.type === 'boolean') >= 0;
      }
      // An absent Container slot has no line yet. A blank must write nothing
      // — typing into an empty box and moving on must not plant a bare
      // "restart:" in the file just because the box was focused. Anything
      // else creates the line, key and value together, via addSetting.
      if (!f.absent || which !== 'value') return false;
      if (!String(value).trim()) return true;
      return addSetting(doc, form, f.service, f.target, value, f.type === 'boolean') >= 0;
    }
    // Writing a value back unchanged must never touch the file. Beyond being
    // wasteful it is a correctness rule: emitScalar quotes a bare true or false to
    // stop a string being misread as a boolean, so re-emitting an unquoted `true`
    // would rewrite the line it came from. Nothing here needs to decide what the
    // value means when the answer is "leave it exactly as it was".
    if (String(value) === String(p.value)) return true;
    // A long-form entry's parts are each their own line, and compose refuses
    // an empty one exactly as it refuses "interval: ''" — but there is no
    // removeKey() for a line inside a numbered list item, only for a key
    // under a service, so a blank edit here can only write nothing and
    // report success. Deleting the line itself is the Compose view's job.
    if (f.longForm && !String(value).trim()) return true;
    // A list entry cleared to nothing leaves its dash with nothing after it —
    // the shape the Add button already writes — rather than "- ''", which is a
    // real empty string and reads back as an entry that has a value. One shape
    // for "nothing here yet" is what lets stashSection recognise one. The body
    // is writeScalar's, without the emitScalar step: emitScalar('') is "''",
    // which is exactly what must not be written here.
    //
    // A map entry (networks: written as a map, see harvestNetworksMap) binds
    // its name through the same 'value' part, but there is no dash to leave
    // behind: the spot covers the key text only, so blanking it that way
    // leaves a bare ":", and writing it the ordinary way leaves "''". An
    // entry with no name is not a shape worth having either, so a blank name
    // writes nothing — removing the entry is what the × is for.
    if (f.binder === 'list' && which === 'value' && !String(value).trim()) {
      if (p.spot.isKey) return true;
      if (spotStale(doc, p.spot)) return false;   // see writeScalar / PLAN_66
      var bare = doc.lines[p.spot.line];
      doc.lines[p.spot.line] = bare.slice(0, p.spot.col) + bare.slice(p.spot.col + p.spot.len);
      splice(doc, 0, 0, []);                  // re-parse, no line count change
      return true;
    }
    // A field the form marks as a real boolean writes true/false bare, not
    // quoted — needsQuoting() is right to quote the string "true", but a
    // dropdown choosing the boolean true means the boolean, not the word.
    // Only turns on for an already-plain line: a file that wrote
    // privileged: "true" meant a string, and quoting is never removed.
    var style = p.spot.style;
    if (f.type === 'boolean' && p.spot.style === 'plain' && /^(true|false)$/.test(String(value))) style = 'bare';
    return writeScalar(doc, p.spot, p.pre + value + p.post, style);
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
    var at = f.commentSpot;
    if (spotStale(doc, at)) return false;       // see writeScalar / PLAN_66

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

  /** The pair holding one `networks:` map entry under a service — the mapping
   * key harvestNetworksMap() reads as the entry's own name — or null if it
   * cannot be found. setPart()'s fourth creation case (PLAN_34 phase 3c)
   * uses this to insert a missing scalar extra (ipv4_address, mac_address…)
   * under the entry's own map. */
  function networkEntryPair(doc, service, name) {
    var svc = serviceMapOf(doc, service);
    var nw = svc && svc.value.pairs['networks'];
    return nw && nw.value && nw.value.kind === 'map' ? (nw.value.pairs[name] || null) : null;
  }

  /**
   * Turns a service's `networks:` from a plain list of names into a map of
   * them — one bare "name:" per entry, nothing else changed — so a name
   * written as "- backend" gains a line to hang a fixed address or a hardware
   * address on (PLAN_34 phase 5; harvestNetworksMap() above is what then
   * offers those two blank boxes on it).
   *
   * Whole-block by design: YAML cannot mix a sequence and a mapping under one
   * key, so promoting one name promotes every name in this service's list.
   *
   * Refuses, leaving the file untouched, the moment any entry is something
   * this cannot safely turn into a bare key: an anchor, an alias, a merge
   * key, a flow sequence, anything else the parser sealed, an entry written
   * as its own block ("- name: value"), or a dash with no name at all.
   * Partial promotion of a block is worse than none.
   *
   * Rebuilt line by line rather than as one new block, so a trailing "# ..."
   * comment on an entry's own line rides onto its new key line, and any
   * blank line or standalone comment between entries is copied through
   * untouched — the naive version of this (see writeTest()'s own note on the
   * one comment it knowingly drops) loses exactly this.
   *
   * -> { ok: true }
   * -> { ok: false, error: '<what to do next>' }
   */
  function promoteNetworksList(doc, service) {
    var svc = serviceMapOf(doc, service);
    var pair = svc && svc.value.pairs['networks'];
    var v = pair ? pair.value : null;
    if (!v || v.kind !== 'seq') {
      return { ok: false, error: 'There is no list of networks here to change.' };
    }

    var i;
    for (i = 0; i < v.items.length; i++) {
      var chk = v.items[i].value;
      if (!chk) {
        return { ok: false, error: 'One of these entries has no name yet, so it cannot become a setting.' };
      }
      if (chk.kind === 'opaque') {
        return { ok: false, error: 'One of these entries cannot be changed here, because ' + lockReason(chk.reason) + '.' };
      }
      if (chk.kind !== 'scalar') {
        return { ok: false, error: 'One of these entries is written as a block of its own, so it cannot be turned into a setting.' };
      }
    }

    // Every entry is a plain name, so rewrite each one's own line in place
    // and copy every other line in the span verbatim.
    var lines = [];
    for (i = v.start; i < v.end; i++) {
      var owner = null;
      for (var j = 0; j < v.items.length; j++) {
        if (i >= v.items[j].start && i < v.items[j].end) { owner = v.items[j]; break; }
      }
      lines.push(owner ? pad(v.indent) + owner.value.raw + ':' + (owner.value.comment || '') : doc.lines[i]);
    }

    splice(doc, v.start, v.end - v.start, lines);
    return { ok: true };
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
  function newEntry(binder, taken, service, shape, value, listKey, declared, netKind) {
    if (typeof value === 'string' && value !== '') return value;

    if (binder === 'port') {
      // Always quoted. Unquoted, YAML 1.1 reads 22:22 as a sexagesimal number
      // and compose is handed 1342 — the classic way a hand-written compose
      // file goes wrong, and not one to reintroduce from this side.
      //
      // On a CONFIRMED host/macvlan/ipvlan service (PLAN_49) there is nowhere
      // for a mapping to go — nothing is mapped on those networks — so a
      // single container-side number is written instead of a two-sided
      // mapping; the number itself is plain 8080 counted up, same as the
      // two-sided branch below (PLAN_51 dropped the guess this used to make
      // from the file's own web address). Gated on a confirmed kind only:
      // while the network list has not answered yet, every service reads as
      // 'bridge' (see buildForm's post-pass) and this keeps writing today's
      // two-sided shape, byte-for-byte unchanged.
      var n = 8080;
      while (taken.indexOf(n + '/tcp') >= 0) n++;
      if (netKind === 'host' || netKind === 'other') return '"' + n + '"';
      return '"' + n + ':' + n + '"';
    }
    if (binder === 'volume') {
      return '/mnt/user/appdata/' + service + ':' + freeName(taken, '/data');
    }
    if (binder === 'device') {
      var d = freeName(taken, '/dev/dri');
      return d + ':' + d;
    }
    if (binder === 'list') {
      // A map-shaped list — only networks: ever takes this form under a
      // service — needs a "name:" line, since a bare word is not a valid
      // mapping entry; addItem's own v.kind === 'map' branch splices this
      // return value straight in with no key/dash of its own.
      var mapSuffix = shape === 'map' ? ':' : '';

      // These three render as a suggestion box — a text box with a dropdown
      // attached (see choiceFor() in stacks.js) — and a browser only offers
      // the suggestions that match what is already in the box. A placeholder
      // there hides the very list the Add button was pressed to see, so they
      // start empty instead. addService() writes a blank `image:` for the same
      // reason. Everything else here is a plain box, where a placeholder shows
      // the shape of the thing wanted and costs nothing.
      if (listKey === 'cap_add' || listKey === 'cap_drop' || listKey === 'profiles') return '' + mapSuffix;

      // A key with a declared-name namespace (networks, secrets, configs,
      // depends_on) offers the first name not already on this service, so
      // the add button hands over something that is already valid rather
      // than a word the user must remember to replace. Everything else is a
      // fixed, obviously-a-placeholder literal.
      var spec = KEYS[listKey] || {};
      if (spec.from && declared) {
        for (var d2 = 0; d2 < declared.length; d2++) {
          if (taken.indexOf(declared[d2]) < 0) return declared[d2] + mapSuffix;
        }
      }
      var LIST_PLACEHOLDER = {
        networks: 'default', dns: '1.1.1.1', expose: '8080', env_file: './app.env',
        depends_on: 'other-service', secrets: 'my_secret', configs: 'my_config'
      };
      var base = LIST_PLACEHOLDER[listKey] || 'value';

      // expose is a bare port number and dns an IP address, so a text suffix
      // ("8080-2", "1.1.1.1-2") would look like a valid value while not being
      // one — increment the number/last octet instead, same as the port
      // binder above.
      if (listKey === 'expose') {
        var pn = parseInt(base, 10);
        while (taken.indexOf(String(pn)) >= 0) pn++;
        return String(pn) + mapSuffix;
      }
      if (listKey === 'dns') {
        var octets = base.split('.'), last = parseInt(octets[3], 10), candidate;
        do {
          candidate = octets.slice(0, 3).join('.') + '.' + last;
          last++;
        } while (taken.indexOf(candidate) >= 0);
        return candidate + mapSuffix;
      }
      // Everything else here is an obvious placeholder already, and the new
      // box's text is selected for overtyping immediately (structuralEdit in
      // stacks.js), so a suffix is enough.
      return freeName(taken, base, '-') + mapSuffix;
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
   * `listKey` is the compose key to append to — needed for a 'list' binder,
   * which covers eight keys and so cannot be looked up from the binder alone.
   */
  function addItem(doc, form, service, binder, value, listKey) {
    var key = listKey || LIST_KEY[binder];
    if (!key) return -1;

    var svc = serviceMapOf(doc, service);
    if (!svc) return -1;

    var spec = KEYS[key] || {};
    var declared = spec.from ? form.declared[spec.from] : null;

    // Read off the model rather than adding a new parameter to every DOM-side
    // caller — buildForm() already worked this out and stamped it on the
    // service (PLAN_49). Only newEntry()'s port branch looks at it.
    var netKind = 'bridge';
    for (var si = 0; si < form.services.length; si++) {
      if (form.services[si].name === service) {
        netKind = form.services[si].netKind || 'bridge';
        break;
      }
    }

    var taken = [], i;
    for (i = 0; i < form.fields.length; i++) {
      var ff = form.fields[i];
      // A 'list' binder covers several keys at once, so "taken" must be
      // scoped to this key too — otherwise a name already used under
      // depends_on would wrongly block the same name being offered under
      // networks.
      if (ff.service === service && ff.binder === binder && (binder !== 'list' || ff.listKey === key)) {
        taken.push(ff.target);
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
             [pad(v.indent) + '-' + gap + newEntry(binder, taken, service, 'seq', value, key, declared, netKind)]);
      return v.end;
    }

    if (v && v.kind === 'map') {
      splice(doc, v.end, 0, [pad(v.indent) + newEntry(binder, taken, service, 'map', value, key, declared, netKind)]);
      return v.end;
    }

    if (pair) {                       // "ports:" with nothing under it
      var at = pair.end;
      splice(doc, at, 0,
             [pad(pair.indent + 2) + '- ' + newEntry(binder, taken, service, 'seq', value, key, declared, netKind)]);
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
      ? pad(indent + 2) + newEntry(binder, taken, service, 'map', value, key, declared, netKind)
      : pad(indent + 2) + '- ' + newEntry(binder, taken, service, 'seq', value, key, declared, netKind));

    splice(doc, to, 0, lines);
    return to + lines.length - 1;
  }

  // Writes one `key: value` line as a child of `pair`, at whatever column
  // that parent's existing children already sit at (two in from the parent
  // when it has none yet).
  // Shared rather than copied because a declaration's driver, a service's
  // setting and (later) a healthcheck's timings are the same write at three
  // different depths — see PLAN_7.md.
  //
  // `value` of null/undefined writes a bare "key:" — a complete declaration
  // on its own (an ordinary bridge network) — rather than refusing for want
  // of something to emit. `before` names a sibling key the new line must
  // land ahead of; addSetting uses it to keep a settings block's x-unraid
  // tail last. Works whether `pair` already has a map under it or nothing at
  // all, which is what lets addDeclared reuse this for a brand-new block.
  //
  // `bare`, when true, writes true/false unquoted rather than through the
  // usual plain-style quoting rules — see setPart's own bare choice, which
  // this mirrors for a value that has no existing line to inherit style from.
  //
  // Returns the inserted line number, or -1 when emitScalar refuses the value.
  function insertChild(doc, pair, key, value, before, bare) {
    if (hasUnreadTail(doc)) return -1;

    var raw = null;
    if (value !== undefined && value !== null) {
      raw = emitScalar(value, bare ? 'bare' : 'plain', false);
      if (raw === null) return -1;
    }

    var map = pair.value && pair.value.kind === 'map' ? pair.value : null;
    // The parent's own children decide the column. A file that nests by four must
    // not have a two-space step forced into it — the line lands at a depth that
    // belongs to nothing and compose refuses the file.
    var indent = map ? map.indent : pair.indent + 2;

    var after = null, i;
    if (map) {
      for (i = 0; i < map.keys.length; i++) {
        if (map.keys[i] === before) continue;
        after = map.pairs[map.keys[i]];
      }
    }

    var to    = after ? after.end : (map ? map.start : pair.end);
    var lines = [];

    // Match the file's own habit: if the key above this one is separated from
    // its neighbour by a blank line, the new line gets one too.
    if (after && after.leadStart > 0 && doc.lines[after.leadStart - 1] !== undefined &&
        doc.lines[after.leadStart - 1].trim() === '') {
      lines.push('');
    }

    lines.push(pad(indent) + key + (raw === null ? ':' : ': ' + raw));

    splice(doc, to, 0, lines);
    return to + lines.length - 1;
  }

  /**
   * Add a plain setting (one of ALWAYS) that has no line in the file yet.
   * There is no "taken" name check here, unlike addItem — a setting key is
   * unique by definition, so no placeholder needs to dodge a collision.
   *
   * Returns the inserted line number, or -1 when the value cannot be written
   * or the service cannot be read.
   */
  function addSetting(doc, form, service, key, value, bare) {
    var svc = serviceMapOf(doc, service);
    if (!svc) return -1;
    return insertChild(doc, svc, key, value, 'x-unraid', bare);
  }

  /**
   * Walks `path` from `getPair()`, creating each missing level as a bare key
   * via insertChild, and returns the pair sitting at the end of it — freshly
   * created if it did not exist, found as-is if it did. An empty `path`
   * returns getPair() itself, unchecked, the same as a zero-length walk.
   *
   * splice() (called by insertChild) fully re-parses the document, so every
   * pair held before it runs is stale the instant it returns — which is why
   * a level just created is not reused: the whole walk restarts from
   * getPair() so every position is read fresh. `getPair` is a function
   * rather than a pair for exactly this reason: addNested re-derives its
   * start from serviceMapOf, and the x-unraid section stash re-derives its
   * from the document root, and both need that lookup repeated after a
   * restart, not just the first time.
   *
   * One insert per missing level and no more — insertChild reports where it
   * wrote, not whether the re-parse then read it back as the child it was
   * meant to be, and in a file whose own indentation is inconsistent it is
   * not, so the level still looks missing on the next pass. A snapshot is
   * taken up front, so any of that — a sealed/opaque/scalar level, an insert
   * that does not read back, or running out of tries — restores the file to
   * exactly what it was and returns null rather than leaving the levels it
   * did manage to insert behind. Every caller inherits the rollback this way
   * instead of having to remember its own.
   */
  function ensurePath(doc, getPair, path, top) {
    var before = doc.lines.slice();
    function refuse() {
      doc.lines = before;
      splice(doc, 0, 0, []);
      return null;
    }

    for (var tries = path.length; ; tries--) {
      if (hasUnreadTail(doc)) return refuse();

      var pair = getPair();
      if (!pair) return refuse();

      var i;
      for (i = 0; i < path.length; i++) {
        var map = pair.value && pair.value.kind === 'map' ? pair.value : null;
        if (pair.value && !map) return refuse();
        var next = map ? map.pairs[path[i]] : null;
        if (!next) break;
        pair = next;
      }
      if (i === path.length) return pair;

      if (tries <= 0) return refuse();
      if (insertChild(doc, pair, path[i], null, i === 0 ? top : null) < 0) return refuse();
      // Loop restarts the walk from getPair() — see the docblock above for why.
    }
  }

  /**
   * Writes a value nested any number of levels under a service — the
   * primitive PLAN_7.md names as the shared blocker for turning on an empty
   * healthcheck/deploy group. ensurePath creates every level up to the
   * leaf's own parent; the leaf itself is written here, since only the
   * caller knows whether it is a plain value or (Phase 8) a section stash's
   * raw JSON line.
   *
   * Returns -1, rather than guessing, whenever a level along the way is
   * sealed, opaque, or a scalar — inserting into something the parser could
   * not read is how a working file gets corrupted. ensurePath rolls back its
   * own partial work, but the leaf write below is one more step it does not
   * cover — a snapshot here restores the levels ensurePath *did* create if
   * that last write is what fails.
   */
  function addNested(doc, form, service, path, value, bare) {
    var before = doc.lines.slice();
    var pair = ensurePath(doc, function () { return serviceMapOf(doc, service); },
                          path.slice(0, -1), 'x-unraid');
    // The loop inside ensurePath only checks a level before descending past
    // it, so the leaf's immediate parent — reached only when path.length is
    // 1, or by falling out of the walk otherwise — needs the same check
    // every intermediate level gets: a scalar, anchor or flow value here is
    // exactly as unsafe to write a child into as one higher up the path.
    if (!pair || (pair.value && pair.value.kind !== 'map')) return -1;
    var at = insertChild(doc, pair, path[path.length - 1], value,
                         path.length === 1 ? 'x-unraid' : null, bare);
    if (at < 0) { doc.lines = before; splice(doc, 0, 0, []); }
    return at;
  }

  /** The pair holding one top-level declaration (networks.foo, volumes.bar…),
   * or null if it cannot be read. serviceMapOf's sibling one level shallower
   * — a declaration has no services: layer above it — for addDeclNested's
   * root-resolver closure below.
   */
  function declPairOf(doc, kind, name) {
    if (!doc.root || doc.root.kind !== 'map') return null;
    var block = doc.root.pairs[kind];
    if (!block || !block.value || block.value.kind !== 'map') return null;
    return block.value.pairs[name] || null;
  }

  /**
   * addNested's sibling for a top-level declaration rather than a service —
   * PLAN_34 phase 3's declaration-scoped child insert. Everything else is
   * identical: ensurePath and insertChild take a root-resolver closure and a
   * pair respectively, so addNested's own hardcoding of serviceMapOf is the
   * only thing that made it service-only, and this is the same walk pointed
   * at declPairOf instead. There is no x-unraid tail to keep last at this
   * level (a declaration carries no metadata block of its own), so `top` is
   * omitted where addNested passes one.
   *
   * Returns -1, rather than guessing, whenever a level along the way is
   * sealed, opaque, or a scalar — inserting into something the parser could
   * not read is how a working file gets corrupted (the same guard Phase 0
   * put in setPart's own declaration branch, for the same reason). Same
   * snapshot as addNested, and for the same reason: the leaf write below is
   * one step ensurePath's own rollback does not reach.
   */
  function addDeclNested(doc, form, kind, name, path, value, bare) {
    var before = doc.lines.slice();
    var pair = ensurePath(doc, function () { return declPairOf(doc, kind, name); },
                          path.slice(0, -1));
    if (!pair || (pair.value && pair.value.kind !== 'map')) return -1;
    var at = insertChild(doc, pair, path[path.length - 1], value, null, bare);
    if (at < 0) { doc.lines = before; splice(doc, 0, 0, []); }
    return at;
  }

  // Builds the canonical flow-list text for healthcheck.test — the one shape
  // writeTest() ever writes, whatever shape the file used before. Every
  // element is JSON-encoded rather than run through emitScalar: emitScalar's
  // quoting rules are for a plain compose value, not for one argv element
  // inside a flow list, and JSON.stringify gets a double quote, a backslash
  // or a '#' inside the command right by construction. Returns null for a
  // 'cmd' mode whose command has nothing splittable in it, so writeTest never
  // writes an empty CMD list.
  function buildTestBody(mode, command) {
    var els;
    if (mode === 'none') els = ['NONE'];
    else if (mode === 'shell') els = ['CMD-SHELL', String(command)];
    else if (mode === 'cmd') {
      var argv = splitQuoted(String(command), SEP_WS);
      if (!argv || !argv.length) return null;
      els = ['CMD'].concat(argv);
    } else return null;

    return '[' + els.map(function (e) { return JSON.stringify(e); }).join(', ') + ']';
  }

  // The trailing "# ..." on an existing test: line, whatever shape it is
  // written in, so writeTest() can carry it over onto the replacement line.
  // A plain scalar and a block seq's last item both parsed their comment
  // already; a sealed flow node has not, so its own raw text (which is the
  // whole "test: [...]" line) is searched the same way splitComment() finds
  // any other trailing comment.
  function testTrailingComment(pair) {
    var v = pair.value;
    if (!v) return '';
    if (v.kind === 'scalar') return v.comment || '';
    if (v.kind === 'seq' && v.items.length) return v.items[v.items.length - 1].comment || '';
    if (typeof v.raw === 'string' && v.raw) {
      return splitComment(v.raw.split('\n').pop()).comment || '';
    }
    return '';
  }

  // Replaces healthcheck.test wholesale with the canonical flow form —
  // ["CMD-SHELL", "…"], ["CMD", …], or ["NONE"] — creating healthcheck: and/or
  // test: first when neither exists yet. This is the one write in the whole
  // model that knowingly reformats a line the file's author wrote rather than
  // editing only the span someone typed into (see the section comment above
  // readTest()) — the honest cost is losing that one line's own spacing and
  // quote style, which PLAN_7.md judges smaller than teaching the parser to
  // open a flow sequence safely.
  //
  // Returns false when healthcheck: is sealed (an anchor, alias or flow map)
  // or the pair cannot be located even after trying to create it.
  function writeTest(doc, form, service, mode, command) {
    var body = buildTestBody(mode, command);
    if (body === null) return false;

    var svc = serviceMapOf(doc, service);
    if (!svc) return false;
    var hc = svc.value.pairs['healthcheck'];
    if (hc && hc.value && hc.value.kind !== 'map') return false;

    var testPair = hc && hc.value ? hc.value.pairs['test'] : null;
    var comment = testPair ? testTrailingComment(testPair) : '';

    if (!testPair) {
      // A bare "test:" placeholder, via the same nested-insert addNested
      // already uses for every other absent leaf — creating healthcheck:
      // with it when neither exists yet. splice() re-parses, so the pair has
      // to be re-read from doc rather than reused once this returns.
      if (addNested(doc, form, service, ['healthcheck', 'test'], null) < 0) return false;
      svc = serviceMapOf(doc, service);
      hc = svc && svc.value ? svc.value.pairs['healthcheck'] : null;
      testPair = hc && hc.value ? hc.value.pairs['test'] : null;
      if (!testPair) return false;
    }

    var line = pad(testPair.indent) + 'test: ' + body + comment;
    splice(doc, testPair.start, testPair.end - testPair.start, [line]);
    return true;
  }

  // Removes the span [from, to), first collapsing a blank line on each side
  // down to one — taking a whole block out of a file that separates its
  // blocks with blank lines otherwise leaves two blanks where there was one,
  // or a stray blank at the end when the block was last. Only collapses when
  // there is genuinely a blank on both sides, so one the user put there on
  // purpose is never the one that goes. Shared by removeItem, removeDeclared
  // and removeKey, which all remove a whole block the same way.
  function spliceBlock(doc, from, to) {
    if (from > 0 &&
        (doc.lines[from - 1] || '').trim() === '' &&
        (doc.lines[to] || '').trim() === '') {
      from--;
    }
    splice(doc, from, to - from, []);
  }

  /**
   * Remove one entry, and the list's key with it when it was the only one.
   * Returns false when the entry is sealed and cannot safely be touched.
   */
  function removeItem(doc, form, id) {
    var f = fieldById(form, id);
    if (!f || !f.range) return false;

    // A long-form dependency's name is a map key under depends_on:, not a
    // list entry, so it goes through removeKey instead of the list-key walk
    // below — which already collapses depends_on: itself away when this was
    // the only dependency left, the same rule every other whole-block removal
    // in this file follows.
    if (f.binder === 'depends') {
      return removeKey(doc, form, f.service, ['depends_on', f.target.slice('depends_on.'.length)]);
    }

    var key = f.listKey || LIST_KEY[f.binder];
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
      spliceBlock(doc, pair.leadStart, pair.end);
      return true;
    }

    // range.start is the entry's leadStart, so it takes its own description
    // comment along rather than orphaning it above the next entry.
    splice(doc, f.range.start, f.range.end - f.range.start, []);
    return true;
  }

  /**
   * Move one entry inside a service's list to a new position — the model
   * side of dragging a port row. `from` and `to` are 0-based indices into
   * the sequence; the entry currently at `from` ends up at index `to` of the
   * resulting list, the same "remove then insert-at-to" convention
   * Array.prototype.splice itself uses for a move.
   *
   * Moves `leadStart..end` as one span, the same one removeItem already
   * trusts to be the whole entry: leadStart has walked back over any
   * standalone comment sitting directly above the item, and a trailing "#…"
   * on the item's own line is inside its own last line, so both travel with
   * it rather than being orphaned. A blank line between entries sits outside
   * every item's span, so it is never part of what moves and the file's own
   * spacing is left exactly where the user put it.
   *
   * Refuses, changing nothing, the moment anything sealed sits anywhere in
   * the list — an anchor, an alias, a merge key, a flow list, a block
   * scalar, anything else the parser could not confidently read — not only
   * in the entry being moved. An anchor and the alias pointing at it can
   * both live in the same list, and reordering around that pair is exactly
   * the case a move that only checked the two entries touched would get
   * wrong. Same all-or-nothing posture as promoteNetworksList.
   *
   * `from === to`, or either index out of range, is a no-op success that
   * writes nothing — there is nothing here worth refusing.
   *
   * -> { ok: true }
   * -> { ok: false, error: '<what to do next>' }
   */
  function moveItem(doc, form, service, listKey, from, to) {
    var svc = serviceMapOf(doc, service);
    var pair = svc && svc.value.pairs[listKey];
    var v = pair ? pair.value : null;
    if (!v || v.kind !== 'seq') {
      return { ok: false, error: 'There is no list here to reorder.' };
    }

    var n = v.items.length;
    if (from === to || from < 0 || from >= n || to < 0 || to >= n) {
      return { ok: true };
    }

    for (var i = 0; i < doc.sealed.length; i++) {
      var s = doc.sealed[i];
      if (s.start < v.end && s.end > v.start) {
        return { ok: false, error: 'One of these entries cannot be moved, because ' + lockReason(s.reason) + '.' };
      }
    }

    var moving = v.items[from];
    var block = doc.lines.slice(moving.leadStart, moving.end);

    splice(doc, moving.leadStart, moving.end - moving.leadStart, []);

    // The removal shifted every line at or after the moved block up by the
    // block's own length, so the target has to be re-read fresh off the
    // re-parsed document rather than reused — reusing the old leadStart is
    // the classic off-by-one this kind of move gets wrong when the entry
    // travels downwards.
    svc = serviceMapOf(doc, service);
    pair = svc.value.pairs[listKey];
    v = pair.value;

    var rest = v.items;
    var insertAt = to < rest.length ? rest[to].leadStart : v.end;

    splice(doc, insertAt, 0, block);
    return { ok: true };
  }

  /**
   * Declares a name under the top-level <kind>: block — networks, volumes,
   * secrets or configs — creating the block (plus this, its first child) at
   * the end of the document when it is not there yet, since that is where
   * compose files conventionally put these: after services:.
   *
   * Returns the inserted line, or -1 when the block is written in a way this
   * parser cannot add to (an alias, a flow map).
   */
  function addDeclared(doc, kind, name) {
    if (!doc.root || doc.root.kind !== 'map') return -1;

    var block = doc.root.pairs[kind];
    if (block) {
      if (block.value && block.value.kind !== 'map') return -1;
      var existing = block.value ? block.value.keys : [];
      return insertChild(doc, block, freeName(existing, name), null);
    }

    // No block yet: insert it via the same primitive, treating the document
    // root as a pair's value with an imaginary indent of -2 so the new
    // top-level key lands at indent 0 — then insert its first child the same
    // way, one level deeper. The re-parse inside the first call means
    // doc.root.pairs[kind] must be re-read afterwards rather than reused.
    var rootPair = { indent: -2, value: doc.root, end: doc.root.end };
    if (insertChild(doc, rootPair, kind, null, 'x-unraid') < 0) return -1;
    return insertChild(doc, doc.root.pairs[kind], name, null);
  }

  /**
   * Declares a whole network the file references but never defines — the fix
   * behind the "Add it to this file" button next to an undeclared network's
   * advice (see the declareMissing flag set alongside that advice). Written
   * as `external: true` rather than a bare key: on Unraid the network being
   * joined (br0.2 and the like) is one Unraid itself already made, so the
   * file has to say "this already exists, do not create it" — a bare key
   * tells compose to create its own network under that name instead, which
   * is a second, different network with a mangled name, not the one the
   * service actually joins.
   *
   * Refuses (returns -1, writing nothing) when the networks: block is
   * written in a way this parser cannot add to, or when `name` is already
   * declared — checked explicitly rather than left to addDeclared's own
   * freeName(), which would silently declare "name-2" and leave the original
   * reference still dangling.
   *
   * The name comes off whatever the service's own row says, so it is checked
   * against the same shape every other declaration name is: insertChild
   * writes a key verbatim with no quoting, so a name carrying a colon would
   * be written as two keys and the re-parse would not find the entry back.
   *
   * Returns the declaration key's own line number.
   */
  function declareNetwork(doc, name) {
    if (!name || !SERVICE_NAME_RE.test(name)) return -1;
    if (!doc.root || doc.root.kind !== 'map') return -1;

    var block = doc.root.pairs['networks'];
    if (block && block.value) {
      if (block.value.kind !== 'map') return -1;
      if (block.value.keys.indexOf(name) >= 0) return -1;
    }

    var line = addDeclared(doc, 'networks', name);
    if (line < 0) return -1;

    // addDeclared's insert re-parses the document, so a pair read before
    // that call is stale (addDeclared's own comment says the same about
    // doc.root.pairs[kind]) — re-read the fresh one to hang external: off.
    // A bare "name:" is already valid compose (compose creates it with
    // defaults), so a failure here is a bug rather than a user error, and
    // the file stays loadable either way — return the declaration's own
    // line regardless of whether this second write succeeds.
    var netPair = doc.root.pairs['networks'].value.pairs[name];
    if (netPair) insertChild(doc, netPair, 'external', 'true', null, true);
    return line;
  }

  /**
   * PLAN_64 phase C: switches a service from joining networks to a
   * network_mode (host, none, or sharing another service/container) in one
   * move — compose refuses a service holding both keys, so this never
   * leaves one written without the other. Whatever the service's own
   * networks: block held is kept, verbatim and whole, under the service's
   * own x-unraid.network_stash — not read as values and rebuilt, the same
   * "capture the raw lines" posture stashSection already takes, because a
   * fixed IP or a hand-written comment on one entry has nowhere else to
   * live if this rewrote the list itself. restoreNetworkStash below is the
   * way back.
   *
   * Refuses, changing nothing, when networks: is written in a way this
   * parser cannot safely lift out whole (sealed, opaque — an anchor, a flow
   * list) or when the x-unraid block cannot be added to; either refusal is
   * reported rather than guessed past, the same posture every other
   * structural writer in this file takes.
   *
   * `form` is accepted for symmetry with every other writer here but never
   * read — every position below comes from a fresh serviceMapOf(doc, ...),
   * which is what keeps this safe to call without rebuilding the form in
   * between (see PLAN_66's account of the bug this avoids).
   *
   * -> { ok: true }
   * -> { ok: false, error: '<what to do next>' }
   */
  function setNetworkMode(doc, form, service, mode) {
    var svc = serviceMapOf(doc, service);
    if (!svc) return { ok: false, error: 'That service could not be found — reopen the stack and try again.' };

    var pair = svc.value.pairs['networks'];
    var stash = null;
    if (pair) {
      if (!pair.value || pair.value.kind === 'opaque') {
        return { ok: false, error: 'That list is written in a way the form cannot move — remove it in the Compose view instead.' };
      }
      // The key it sat after, so restoring puts it back where it was rather
      // than at the end of the service. Ordering is part of what a write-back
      // has to preserve, and a set-aside whose whole purpose is to lose
      // nothing must not quietly reorder someone's file instead. '' means it
      // was the service's first key.
      var at = svc.value.keys.indexOf('networks');
      stash = { after: at > 0 ? svc.value.keys[at - 1] : '',
                lines: doc.lines.slice(pair.leadStart, pair.end) };
    }

    if (stash) {
      if (addNested(doc, form, service, ['x-unraid', 'network_stash'], JSON.stringify(stash)) < 0) {
        return { ok: false, error: 'The x-unraid block in this file is written in a way the form cannot add to — set this up in the Compose view instead.' };
      }
      if (!removeKey(doc, form, service, ['networks'])) {
        // The stash write above already landed — take it back rather than
        // leave a copy sitting beside the block it was meant to replace.
        removeKey(doc, form, service, ['x-unraid', 'network_stash']);
        return { ok: false, error: 'That list is written in a way the form cannot move — remove it in the Compose view instead.' };
      }
    }

    svc = serviceMapOf(doc, service);            // stale after either write above
    var modePair = svc.value.pairs['network_mode'];
    if (modePair && modePair.value && modePair.value.kind === 'scalar') {
      if (!writeScalar(doc, scalarSpot(modePair.value), mode, 'plain')) {
        return { ok: false, error: 'That value cannot be written as it stands — edit it in the Compose view instead.' };
      }
    } else if (modePair) {
      return { ok: false, error: 'network_mode is written in a way the form cannot change — edit it in the Compose view instead.' };
    } else if (insertChild(doc, svc, 'network_mode', mode, 'x-unraid') < 0) {
      return { ok: false, error: 'That could not be written — edit it in the Compose view instead.' };
    }

    return { ok: true };
  }

  /**
   * Reads back what setNetworkMode above stashed for one service, validated
   * the same way restoreNetworkStash validates before actually writing —
   * shared rather than copied so the note stacks.js shows beneath a
   * network_mode row can never claim a stash restoreNetworkStash would
   * then refuse. Returns the raw lines (JSON.parse't, not otherwise read),
   * or null when there is nothing there or what is there does not parse as
   * this function wrote it.
   */
  function readNetworkStash(doc, service) {
    var svc = serviceMapOf(doc, service);
    var xu = svc && svc.value.pairs['x-unraid'];
    var raw = xu && xu.value && xu.value.kind === 'map' ? xu.value.pairs['network_stash'] : null;
    if (!raw || !raw.value || raw.value.kind !== 'scalar') return null;
    try {
      var parsed = JSON.parse(raw.value.value);
      // { after: '<key it followed>', lines: [...] }. `after` may legitimately
      // be '' (it was the first key), so only its type is checked. Anything
      // else at all — including the bare array an earlier build of this wrote
      // — is treated as not ours and dropped, because this block is as
      // hand-editable as the rest of the file.
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) &&
          typeof parsed.after === 'string' &&
          Array.isArray(parsed.lines) && parsed.lines.length &&
          parsed.lines.every(function (l) { return typeof l === 'string'; })) {
        return parsed;
      }
    } catch (e) { /* not our JSON */ }
    return null;
  }

  /**
   * setNetworkMode's way back: drops network_mode and puts back whatever
   * networks: block was set aside, verbatim, exactly as it was captured.
   * "Restoring validates what it finds" (PLAN_64 section 6) — a stash that
   * is not a JSON array of strings, hand-edited into nonsense or simply
   * gone, is dropped rather than guessed at, and the caller is told nothing
   * was restored rather than have this write something that looks plausible
   * but is not what was actually there.
   *
   * The stash is dropped whether or not it turns out restorable — a hand
   * edit since it was taken is not a reason to leave a second, unreadable
   * copy sitting in the file — but only after network_mode itself is
   * confirmed removable, so a refusal there never leaves the stash gone
   * with nothing put back for it.
   *
   * -> { ok: true, restored: true|false }
   * -> { ok: false, error: '<what to do next>' }
   */
  function restoreNetworkStash(doc, form, service) {
    var svc = serviceMapOf(doc, service);
    if (!svc) return { ok: false, error: 'That service could not be found — reopen the stack and try again.' };

    var lines = readNetworkStash(doc, service);
    var hadStash = !!svc.value.pairs['x-unraid'] &&
                   svc.value.pairs['x-unraid'].value.kind === 'map' &&
                   !!svc.value.pairs['x-unraid'].value.pairs['network_stash'];

    if (!removeKey(doc, form, service, ['network_mode'])) {
      return { ok: false, error: 'network_mode is written in a way the form cannot remove — remove it in the Compose view instead.' };
    }

    if (hadStash && !removeKey(doc, form, service, ['x-unraid', 'network_stash'])) {
      return { ok: false, error: 'The x-unraid block in this file is written in a way the form cannot change — edit it in the Compose view instead.' };
    }

    if (!lines) return { ok: true, restored: false };

    // Back where it was, using the key the stash recorded it followed — so a
    // set-aside and a restore leave the file in the order it was written in.
    // Two honest fallbacks: '' means it was the service's first key, and a
    // named key that has since been deleted by hand lands it just ahead of
    // the x-unraid tail, where every other freshly written service key goes.
    svc = serviceMapOf(doc, service);
    var at = null;
    if (lines.after !== '' && svc.value.pairs[lines.after]) {
      at = svc.value.pairs[lines.after].end;
    } else if (lines.after === '') {
      at = svc.value.start + 1;
    } else {
      var last = null;
      for (var i = 0; i < svc.value.keys.length; i++) {
        if (svc.value.keys[i] === 'x-unraid') continue;
        last = svc.value.pairs[svc.value.keys[i]];
      }
      at = last ? last.end : svc.value.start + 1;
    }
    splice(doc, at, 0, lines.lines);
    return { ok: true, restored: true };
  }

  /**
   * Adds a new service under `services:`, after the last existing one, seeded
   * with `image:` (blank) and `restart: unless-stopped` — two lines, never
   * one, the same rule addItem() follows when it creates a list key's first
   * entry alongside it: a bare "my-app:" with nothing under it is null, and
   * compose rejects the file.
   *
   * Unlike addDeclared, there is no "no block yet" case: a stack is a compose
   * file with at least one service already (see the stack model in
   * CLAUDE.md), so there is no conventional place to invent `services:` from
   * nothing, and this refuses instead of guessing one.
   *
   * Refuses (returns -1, writing nothing) for a name already taken, an
   * invalid name, a sealed `services:` map, or no `services:` key at all.
   *
   * Returns the inserted line, or -1.
   */
  function addService(doc, form, name) {
    if (!name || !SERVICE_NAME_RE.test(name)) return -1;
    if (!doc.root || doc.root.kind !== 'map') return -1;
    // Bypasses insertChild (it places the new service itself, not a child of
    // one), so the unread-tail refusal has to be repeated here — a name that
    // may already exist inside the unread region is every name once a region
    // is unread, which is exactly what closes the duplicate-service route.
    if (hasUnreadTail(doc)) return -1;

    var svc = doc.root.pairs['services'];
    if (!svc || !svc.value || svc.value.kind !== 'map') return -1;
    if (svc.value.pairs[name]) return -1;

    // Positioned after the last existing service, the same placement addItem
    // uses for a list's first entry.
    var indent = svc.value.indent;
    var after = null, i;
    for (i = 0; i < svc.value.keys.length; i++) {
      after = svc.value.pairs[svc.value.keys[i]];
    }

    var to = after ? after.end : svc.value.start + 1;
    var lines = [];

    // Match the file's own habit: if the service above this one is separated
    // from its neighbour by a blank line, the new one gets one too.
    if (after && after.leadStart > 0 && doc.lines[after.leadStart - 1] !== undefined &&
        doc.lines[after.leadStart - 1].trim() === '') {
      lines.push('');
    }

    // Nest the new service's settings the way the file already nests its
    // own, rather than assuming two spaces. A file written four deep would
    // otherwise get one service indented unlike every other, which is a
    // hand-authored habit broken for no reason.
    var childIndent = (after && after.value && after.value.kind === 'map')
                        ? after.value.indent : indent + 2;

    lines.push(pad(indent) + name + ':');
    lines.push(pad(childIndent) + 'image:');
    lines.push(pad(childIndent) + 'restart: unless-stopped');

    splice(doc, to, 0, lines);
    return to + lines.length - 1;
  }

  /**
   * Removes one declared name, and the whole <kind>: block with it when it
   * was the last one — a bare "networks:" is null and compose rejects it,
   * the same rule removeItem follows for a service's list key.
   *
   * Does not refuse when a service still references the name: PLAN_4.md's
   * dangling-reference advice already covers that, and compose reports it
   * too, so refusing here would block a legitimate two-step edit.
   */
  function removeDeclared(doc, kind, name) {
    if (!doc.root || doc.root.kind !== 'map') return false;
    var block = doc.root.pairs[kind];
    if (!block || !block.value || block.value.kind !== 'map') return false;
    var pair = block.value.pairs[name];
    if (!pair) return false;

    if (block.value.keys.length === 1) {
      spliceBlock(doc, block.leadStart, block.end);
      return true;
    }

    // leadStart takes the name's description comment, if any, along with it.
    splice(doc, pair.leadStart, pair.end - pair.leadStart, []);
    return true;
  }

  /**
   * Walks `path` from `getPair()` without creating anything, returning
   * {chain, leaf} — chain[0] is getPair()'s own pair, chain[i] the pair
   * holding path[0..i-1], leaf the pair at path's end — or null when
   * anything along the way is missing, sealed, opaque, or not a map.
   * Shared by removeKey (service-relative) and the x-unraid section stash
   * (root-relative, via a real x-unraid pair rather than a service one).
   */
  function walkToLeaf(getPair, path) {
    var pair = getPair();
    if (!pair) return null;

    var chain = [pair], i;
    for (i = 0; i < path.length - 1; i++) {
      if (!pair.value || pair.value.kind !== 'map') return null;
      pair = pair.value.pairs[path[i]];
      if (!pair) return null;
      chain.push(pair);
    }
    if (!pair.value || pair.value.kind !== 'map') return null;

    var leaf = pair.value.pairs[path[path.length - 1]];
    return leaf ? { chain: chain, leaf: leaf } : null;
  }

  // The outermost ancestor in `chain` that removing `leaf` would leave with
  // nothing else under it — walking upward while each level's only
  // remaining child is the one below it. `floor` is the lowest chain index
  // allowed to collapse: removeKey must never remove the service itself
  // (floor 1, chain[0]), while the section stash has nothing above x-unraid
  // that needs protecting (floor 0).
  function outermostEmptied(chain, leaf, floor) {
    var outermost = leaf;
    for (var i = chain.length - 1; i >= floor; i--) {
      if (chain[i].value.keys.length !== 1) break;
      outermost = chain[i];
    }
    return outermost;
  }

  /**
   * Removes the pair at `path` under a service, and any parent the removal
   * leaves with nothing else under it — a bare "healthcheck:" (or a mid-path
   * "deploy: resources:") is null, and compose refuses the file, the same
   * rule removeItem/removeDeclared already follow for a list key or a
   * declared name. Shared by setPart's blank-an-existing-leaf case and (Phase
   * 3) the tick-box's whole-block removal, so both walk the file the same way.
   *
   * Returns false when there is nothing at `path`, or a level along the way
   * is sealed, opaque, or not a map.
   */
  function removeKey(doc, form, service, path) {
    var found = walkToLeaf(function () { return serviceMapOf(doc, service); }, path);
    if (!found) return false;

    var outermost = outermostEmptied(found.chain, found.leaf, 1);
    spliceBlock(doc, outermost.leadStart, outermost.end);
    return true;
  }

  /**
   * removeKey's twin for a top-level declaration rather than a service —
   * setPart()'s declaration branch uses this to drop whichever of
   * driver/external is losing when the row's one box swaps between them.
   * Rooted at declPairOf() instead of serviceMapOf(); floor 1 is what stops
   * the declaration's own name line being removed along with its last
   * remaining key, the same protection floor 1 gives the service pair in
   * removeKey above — chain[0] here is the declaration pair itself.
   *
   * Returns false when there is nothing at `path`, or a level along the way
   * is sealed, opaque, or not a map.
   */
  function removeDeclKey(doc, kind, name, path) {
    var found = walkToLeaf(function () { return declPairOf(doc, kind, name); }, path);
    if (!found) return false;

    var outermost = outermostEmptied(found.chain, found.leaf, 1);
    spliceBlock(doc, outermost.leadStart, outermost.end);
    return true;
  }

  /**
   * Reads x-unraid.sections into {service: {key: false | {after, lines}}} —
   * the map a section's tick state, and a stashed block, both live in. A
   * malformed entry — not the boolean false, and not JSON for an object
   * with a null-or-string `after` and an array-of-strings `lines` — is
   * skipped rather than thrown on: this runs on every render of every file,
   * including one somebody has hand-edited.
   */
  function readSections(doc) {
    var out = {};
    if (!doc.root || doc.root.kind !== 'map') return out;
    var xu = doc.root.pairs['x-unraid'];
    var block = xu && xu.value && xu.value.kind === 'map' ? xu.value.pairs['sections'] : null;
    if (!block || !block.value || block.value.kind !== 'map') return out;

    for (var s = 0; s < block.value.keys.length; s++) {
      var svcName = block.value.keys[s];
      var svcMap = block.value.pairs[svcName].value;
      if (!svcMap || svcMap.kind !== 'map') continue;

      var entry = {};
      for (var k = 0; k < svcMap.keys.length; k++) {
        var key = svcMap.keys[k];
        var v = svcMap.pairs[key].value;
        if (!v || v.kind !== 'scalar') continue;

        if (v.style === 'plain' && v.value === 'false') { entry[key] = false; continue; }
        try {
          var parsed = JSON.parse(v.value);
          if (parsed && typeof parsed === 'object' &&
              (parsed.after === null || typeof parsed.after === 'string') &&
              Array.isArray(parsed.lines) &&
              parsed.lines.every(function (l) { return typeof l === 'string'; })) {
            // gap and blank say where the block sat relative to the key above
            // it; both are optional, and both default to "flush against it",
            // which is what an entry written before they existed meant. A
            // hand-edited nonsense value falls back the same way rather than
            // moving the block somewhere arbitrary.
            var gap = typeof parsed.gap === 'number' && parsed.gap >= 0 ? Math.floor(parsed.gap) : 0;
            entry[key] = { after: parsed.after, lines: parsed.lines,
                           gap: gap, blank: parsed.blank === true };
          }
        } catch (e) { /* not our JSON — ignore rather than throw */ }
      }
      if (Object.keys(entry).length) out[svcName] = entry;
    }
    return out;
  }

  /**
   * Whether a readSections() entry means the section is switched off: the
   * bare `false`, or a stash object actually holding lines. There is no
   * stored shape that means "shown" — a shown section has no entry at all,
   * because the compose block itself is the record. This lives beside
   * readSections() rather than in stacks.js because the meaning of the
   * stored shape belongs with the code that writes it; a caller testing the
   * shape by hand is what let a truthy stash be mistaken for "on", making a
   * tick box's restore path unreachable. An object with an empty `lines`
   * deliberately reads as not hidden, so a malformed or hand-edited entry
   * cannot hide a section permanently — the same forgiving direction
   * readSections() already takes with its "skip rather than throw" comment.
   */
  function sectionHidden(entry) {
    if (entry === false) return true;
    return !!(entry && entry.lines && entry.lines.length);
  }

  // The one place that writes or clears one x-unraid.sections.<service>
  // entry, used by setSectionState (a true/false/null tick state) and
  // stashSection (the captured-lines object) alike. `value` is JSON.stringify'd
  // unless it is the literal boolean false, which is written bare so it reads
  // as a deliberate "hidden" flag rather than another JSON string. An
  // existing entry is overwritten in place rather than appended again, which
  // would leave two lines for the same key and a "first one wins" warning.
  function writeSectionEntry(doc, service, key, value) {
    // ensurePath now rolls back its own partial work, but this function still
    // writes the leaf itself afterwards (writeScalar or insertChild below),
    // which is work ensurePath does not cover — a failure there must undo the
    // levels ensurePath *did* manage to create, so this snapshot stays.
    // splice re-parses from the restored array, which is all that reverting
    // takes.
    var before = doc.lines.slice();
    function refuse() {
      doc.lines = before;
      splice(doc, 0, 0, []);
      return false;
    }

    var pair = ensurePath(doc, function () {
      return doc.root && doc.root.kind === 'map'
             ? { indent: -2, value: doc.root, end: doc.root.end } : null;
    }, ['x-unraid', 'sections', service], 'x-unraid');
    if (!pair || (pair.value && pair.value.kind !== 'map')) return refuse();

    var map = pair.value && pair.value.kind === 'map' ? pair.value : null;
    var existing = map ? map.pairs[key] : null;
    var raw = value === false ? false : JSON.stringify(value);

    if (existing && existing.value && existing.value.kind === 'scalar') {
      var done = raw === false ? writeScalar(doc, scalarSpot(existing.value), 'false', 'bare')
                               : writeScalar(doc, scalarSpot(existing.value), raw, 'plain');
      return done || refuse();
    }
    return insertChild(doc, pair, key, raw, null, value === false) >= 0 || refuse();
  }

  // Removes one x-unraid.sections.<service>.<key> entry, and any level of
  // the map it leaves empty — sections, and x-unraid itself, are exactly as
  // unwelcome empty as any other block this file writes. Missing already is
  // not a failure: setSectionState's null state means "no opinion", and
  // that is already true when there is nothing recorded.
  function removeSectionEntry(doc, service, key) {
    var found = walkToLeaf(function () {
      return doc.root && doc.root.kind === 'map' ? doc.root.pairs['x-unraid'] : null;
    }, ['sections', service, key]);
    if (!found) return true;

    var outermost = outermostEmptied(found.chain, found.leaf, 0);
    spliceBlock(doc, outermost.leadStart, outermost.end);
    return true;
  }

  /**
   * The single place that touches an x-unraid.sections entry's on/off state.
   * `state` is true (ticked on, nothing stashed yet — {"after":null,"lines":[]}),
   * false (ticked off deliberately, nothing to restore), or null (no
   * opinion — the entry is removed outright). stashSection and
   * restoreSection use this rather than writing the map themselves.
   */
  function setSectionState(doc, form, service, key, state) {
    if (state === null) return removeSectionEntry(doc, service, key);
    return writeSectionEntry(doc, service, key, state === true ? { after: null, lines: [] } : false);
  }

  // Where a restored line goes: right after `afterKey`'s own span, as the
  // map's first child when afterKey is null, or at the end of the map when
  // afterKey named a key that is no longer there — a hand-edit since the
  // stash was taken is not a reason to fail. Shared by restoreSection's two
  // cases (the immediate parent survived; it did not, and is rebuilt).
  function spliceTarget(map, ownerPair, afterKey) {
    if (afterKey === null) return map ? map.start : ownerPair.end;
    var afterPair = map ? map.pairs[afterKey] : null;
    if (afterPair) return afterPair.end;
    return map && map.keys.length ? map.pairs[map.keys[map.keys.length - 1]].end
                                   : (map ? map.start : ownerPair.end);
  }

  /**
   * Moves a service's section — `path` is its compose path, e.g.
   * ['healthcheck'], ['deploy','resources'] or ['ports'] — out of the file
   * proper and into x-unraid.sections.<service>.<path.join('.')> as one
   * JSON line, verbatim: the lines are carried across as they stand, never
   * read into values and rebuilt. The write happens before the removal, and
   * is undone if the removal then fails, so a refusal never destroys a block
   * without recording where it went.
   *
   * `after` records the position of whichever ancestor removeKey (below)
   * will actually delete — the leaf itself when its own parent survives
   * (has other children), or the parent when it does not, and so on upward.
   * That is the same ancestor outermostEmptied finds, so restoreSection can
   * always place what it rebuilds using the one stored name, whether that
   * turns out to be the leaf's own old neighbour or the service's.
   *
   * Returns false when the block cannot be found, or is written in a way
   * this parser cannot safely lift out (sealed, opaque, not a map/scalar).
   */
  function stashSection(doc, form, service, path) {
    var found = walkToLeaf(function () { return serviceMapOf(doc, service); }, path);
    if (!found) return false;

    var outermost = outermostEmptied(found.chain, found.leaf, 1);
    var fullChain = found.chain.concat([found.leaf]);
    var idx = fullChain.indexOf(outermost);           // >= 1: index 0 (the service) is protected
    var parentMap = fullChain[idx - 1].value;
    var pos = parentMap.keys.indexOf(path[idx - 1]);
    var after = pos > 0 ? parentMap.keys[pos - 1] : null;

    // Where the removed block sat, measured from the end of the key above it
    // rather than from an absolute line number, which the removal itself
    // invalidates. Without this a block separated from its neighbour by a
    // blank line comes back on the wrong side of that blank.
    var anchorEnd = after ? parentMap.pairs[after].end : parentMap.start;
    var gap = outermost.leadStart - anchorEnd;

    // spliceBlock (see removeKey) drops the blank line ABOVE a block when
    // there is one below it too, so that removing a block from between two
    // others does not leave a double blank behind. Mirror that test here:
    // the blank is part of what is about to be removed, so it is part of what
    // has to come back, and restoreSection puts it back by prepending one.
    var blank = outermost.leadStart > 0 &&
                (doc.lines[outermost.leadStart - 1] || '').trim() === '' &&
                (doc.lines[outermost.end] || '').trim() === '';

    // Strip the leaf's own base indent — measured off its first line that
    // actually has one, since a blank line would report an indent of nothing
    // and leave the whole block un-stripped — so the relative indent inside
    // the block survives and only the absolute level is lost. restoreSection
    // adds back whatever the target needs when the block returns.
    var leaf = found.leaf;
    var raw = doc.lines.slice(leaf.leadStart, leaf.end);
    var base = 0;
    for (var bi = 0; bi < raw.length; bi++) {
      if (raw[bi].trim() === '') continue;
      base = raw[bi].length - raw[bi].replace(/^ */, '').length;
      break;
    }
    var lines = raw.map(function (l) { return l.trim() === '' ? '' : l.slice(base); });

    // An entry with nothing after its dash is an unfinished edit rather than
    // something anyone wrote — compose refuses one outright — so it is not
    // carried across. Dropping them can leave the key standing on its own,
    // which is nothing worth keeping at all: that records as plainly off, and
    // is right in both directions — an accurate marker for a section that is
    // off by default, and exactly what "switched off" means for one that is on
    // by default. A block that had no blanks in it is stored as it stands, so
    // a hand-written key with nothing under it still comes back.
    var kept = lines.filter(function (l) { return !/^ *- *$/.test(l); });
    var nothingLeft = kept.length !== lines.length && kept.length < 2;

    var key = path.join('.');
    if (!writeSectionEntry(doc, service, key,
                           nothingLeft ? false
                                       : { after: after, gap: gap, blank: blank, lines: kept })) return false;

    if (!removeKey(doc, form, service, path)) {
      // Should never happen — the same path was just walked successfully
      // above — but undo the write rather than leave a stash entry behind
      // for a block that is still live in the file.
      setSectionState(doc, form, service, key, null);
      return false;
    }
    return true;
  }

  /**
   * Splices a stashed block's lines back into a service. Whether the leaf's
   * immediate parent still exists decides how: if it does, `after` was
   * recorded relative to it (stashSection found nothing to collapse) and
   * the leaf's own lines splice straight in; if it does not — removeKey
   * took it along with the leaf, its only child — `after` was recorded
   * relative to the service instead, and the whole missing chain (each
   * absent level, then the leaf) is rebuilt as one unit and spliced in
   * together, since every level between the service and the leaf was, by
   * construction, an only child too.
   *
   * Returns true whether or not there was anything to splice — a `false`
   * entry, a missing one, or one holding no lines all mean "nothing to
   * restore", not a failure. Returns false only when the service itself
   * cannot be read, or a surviving parent turns out sealed or not a map.
   */
  function restoreSection(doc, form, service, key) {
    // The two splice() calls below place the restored lines directly, rather
    // than through insertChild, so the unread-tail refusal has to be
    // repeated here too.
    if (hasUnreadTail(doc)) return false;

    var sections = readSections(doc);
    var entry = sections[service] ? sections[service][key] : undefined;
    if (!entry || !entry.lines || !entry.lines.length) {
      return setSectionState(doc, form, service, key, null);
    }

    var path = key.split('.');
    var svc = serviceMapOf(doc, service);
    if (!svc) return false;

    var parent = svc, exists = true, i;
    for (i = 0; i < path.length - 1 && exists; i++) {
      var m = parent.value && parent.value.kind === 'map' ? parent.value : null;
      if (parent.value && !m) return false;
      var next = m ? m.pairs[path[i]] : null;
      if (!next) { exists = false; break; }
      parent = next;
    }

    // Back to the exact line the removal took it from: the key above it, plus
    // however far past that key's end it sat, less the blank line spliceBlock
    // collapsed on the way out (prepended below, so the block and its blank
    // go back as the one span they were removed as). An entry written before
    // this was recorded reads as no gap and no blank, which is what the
    // common case — a block flush against its neighbour — means anyway.
    var gap = entry.gap || 0;
    var lead = entry.blank ? [''] : [];

    var lines;
    if (exists) {
      var map = parent.value && parent.value.kind === 'map' ? parent.value : null;
      if (parent.value && !map) return false;
      var indent = map ? map.indent : parent.indent + 2;
      lines = lead.concat(entry.lines.map(function (l) { return l === '' ? '' : pad(indent) + l; }));
      splice(doc, spliceTarget(map, parent, entry.after) + gap - lead.length, 0, lines);
    } else {
      // The file's own nesting step, not two spaces: a file indented by four
      // rebuilds "deploy:" then "resources:" four apart, or the restored block
      // sits at an indent the rest of the file never uses. The stashed lines
      // already carry their own relative indent, so only the levels rebuilt
      // here need this.
      var step = svc.value.indent - svc.indent;
      if (step < 1) step = 2;
      var svcIndent = svc.value.indent;
      var built = [];
      for (i = 0; i < path.length - 1; i++) {
        built.push(pad(svcIndent) + path[i] + ':');
        svcIndent += step;
      }
      var finalIndent = svcIndent;
      entry.lines.forEach(function (l) { built.push(l === '' ? '' : pad(finalIndent) + l); });
      built = lead.concat(built);
      splice(doc, spliceTarget(svc.value, svc, entry.after) + gap - lead.length, 0, built);
    }

    return setSectionState(doc, form, service, key, null);
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
    // Checked as one batch before any line is touched: a rename is one
    // action, so a stale spot anywhere in it must leave the whole file
    // alone rather than land the rest and skip only the bad one.
    for (i = 0; i < writes.length; i++) {
      if (spotStale(doc, writes[i].spot)) return false;
    }
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
    return true;
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

    // A ticked-off section's stash is keyed by service name too, one level
    // up under x-unraid.sections — the same long-form map-key rename
    // collectServiceRefs already gives depends_on's own long form, above.
    var xu = doc.root.pairs['x-unraid'];
    var sections = xu && xu.value && xu.value.kind === 'map' ? xu.value.pairs['sections'] : null;
    if (sections && sections.value && sections.value.kind === 'map' && sections.value.pairs[oldName]) {
      edits.push({ spot: keySpot(sections.value.pairs[oldName]), decoded: newName });
    }

    var writes = [{ spot: topSpot, raw: topRaw }];
    for (i = 0; i < edits.length; i++) {
      var raw = emitScalar(edits[i].decoded, edits[i].spot.style, false);
      if (raw === null) {
        return { ok: false, error: 'One of the references to "' + oldName + '" cannot be rewritten safely, so nothing has been changed.' };
      }
      writes.push({ spot: edits[i].spot, raw: raw });
    }

    if (!applyRenameWrites(doc, writes)) {
      return { ok: false, error: 'The file changed underneath this rename, so nothing has been written — try again.' };
    }
    return { ok: true, refs: edits.length };
  }

  /* =====================================================================
   * Renaming a declaration
   * ===================================================================== */

  // Every spot a rename or removal of a declared name must account for, as
  // {spot, pre, post} — decoded := pre + newName + post, so the caller can
  // reuse the same list for any newName without re-scanning the file.
  // Modelled on collectServiceRefs, one level up: a top-level namespace
  // instead of the services: one, so what counts as "a reference" differs
  // per kind rather than being one shared shape.
  function collectDeclaredRefs(doc, kind, name) {
    var edits = [];
    var block = doc.root.pairs[kind];
    var pair = block && block.value && block.value.kind === 'map' ? block.value.pairs[name] : null;
    if (pair) edits.push({ spot: keySpot(pair) });

    var svc = doc.root.pairs['services'];
    if (!svc || !svc.value || svc.value.kind !== 'map') return edits;

    for (var si = 0; si < svc.value.keys.length; si++) {
      var sp = svc.value.pairs[svc.value.keys[si]];
      if (!sp.value || sp.value.kind !== 'map') continue;

      var lp = sp.value.pairs[kind];
      if (!lp || !lp.value) continue;

      // A service's networks: is either this sequence of names or, for the
      // long form that carries a fixed IPv4/hardware address, a map keyed
      // by network name — that key is a reference too.
      if (kind === 'networks' && lp.value.kind === 'map') {
        for (var ni = 0; ni < lp.value.keys.length; ni++) {
          var nk = lp.value.keys[ni];
          if (nk === name) edits.push({ spot: keySpot(lp.value.pairs[nk]) });
        }
        continue;
      }

      if (lp.value.kind !== 'seq') continue;

      for (var i = 0; i < lp.value.items.length; i++) {
        var iv = lp.value.items[i].value;
        if (!iv) continue;

        if (iv.kind === 'scalar') {
          // A volume's short form is host:container[:mode] — only the host
          // half, half of the scalar, is the reference; the container path
          // beside it must survive untouched.
          if (kind === 'volumes') {
            var s = splitPathShort(iv.value, scalarSpot(iv));
            if (s.host.spot && s.host.value === name) {
              edits.push({ spot: s.host.spot, pre: s.host.pre, post: s.host.post });
            }
          } else if (iv.value === name) {
            edits.push({ spot: scalarSpot(iv) });
          }
          continue;
        }

        // Networks' long form is a map keyed by name, handled above before
        // this loop runs at all — a sequence of maps never happens for
        // networks, so only secrets/configs/volumes reach this branch.
        if (iv.kind === 'map' && kind !== 'networks') {
          // A long-form volume only names a declared volume when its type
          // says so; a bind mount's source is a path, never this bare name.
          var typeOk = kind !== 'volumes' ||
                       (iv.pairs['type'] && iv.pairs['type'].value &&
                        iv.pairs['type'].value.kind === 'scalar' &&
                        iv.pairs['type'].value.value === 'volume');
          var src = iv.pairs['source'];
          if (typeOk && src && src.value && src.value.kind === 'scalar' && src.value.value === name) {
            edits.push({ spot: scalarSpot(src.value) });
          }
        }
      }
    }

    return edits;
  }

  /**
   * Renames a declared network, volume, secret or config, and every service
   * reference to it, in one batch — the same shape as renameService, one
   * level up. Renaming db_data without also rewriting db's volume line gives
   * a file that no longer starts.
   *
   * -> { ok: true,  refs: <references rewritten, not counting the key> }
   * -> { ok: false, error: '<what to do next>' }
   */
  function renameDeclared(doc, kind, name, newName) {
    if (!newName || !SERVICE_NAME_RE.test(newName)) {
      return { ok: false, error: 'Names may only contain letters, numbers, dots, underscores and hyphens — choose a different name.' };
    }
    if (!doc.root || doc.root.kind !== 'map') {
      return { ok: false, error: 'This file cannot be read as compose, so nothing can be renamed.' };
    }
    var block = doc.root.pairs[kind];
    if (!block || !block.value || block.value.kind !== 'map') {
      return { ok: false, error: 'This file declares no ' + FROM_WORD[kind] + 's, so there is nothing to rename.' };
    }
    var pair = block.value.pairs[name];
    if (!pair) {
      return { ok: false, error: 'There is no ' + FROM_WORD[kind] + ' called "' + name + '" in this file, so it cannot be renamed.' };
    }
    // A rename to its own name is a no-op success, checked before the
    // collision test below, which would otherwise refuse it for colliding
    // with itself.
    if (newName === name) return { ok: true, refs: 0 };
    if (block.value.pairs[newName]) {
      return { ok: false, error: 'A ' + FROM_WORD[kind] + ' called "' + newName + '" already exists — choose another name.' };
    }

    var refs = collectDeclaredRefs(doc, kind, name);
    var writes = [], i;
    for (i = 0; i < refs.length; i++) {
      var e = refs[i];
      var decoded = (e.pre || '') + newName + (e.post || '');
      var raw = emitScalar(decoded, e.spot.style, e.spot.isKey);
      if (raw === null) {
        return { ok: false, error: 'One of the references to "' + name + '" cannot be rewritten safely, so nothing has been changed.' };
      }
      writes.push({ spot: e.spot, raw: raw });
    }

    if (!applyRenameWrites(doc, writes)) {
      return { ok: false, error: 'The file changed underneath this rename, so nothing has been written — try again.' };
    }
    // refs always carries the declaration's own key as its first entry, so
    // the count reported here excludes it, matching renameService.
    return { ok: true, refs: refs.length - 1 };
  }

  /* =====================================================================
   * Text search
   *
   * Lives here rather than in stacks.js for two reasons: this is the only
   * file the test harness under tests/ can reach at all (there is no browser
   * on the dev machine, so anything written straight into stacks.js cannot
   * be exercised by an automated test), and this file already owns
   * offset-to-line arithmetic — see lineAtOffset() above, which walks a
   * doc's own line-start table. searchMatches() builds that same table
   * itself, from plain text rather than a parsed doc, since a search runs
   * over the editor's raw buffer before (or instead of) a successful parse.
   * ===================================================================== */

  // searchMatches(text, needle, opts) -> [{ start, end, line, col }], ordered
  // by start. opts is { caseSensitive, regex }; a missing opts is both false.
  //
  // Never throws. An invalid regex — expected far more often than not, since
  // the user is typing it keystroke by keystroke — comes back as [], not an
  // error. A zero-length match (e.g. "a*" or "^") is never reported: it would
  // highlight nothing and give a replace nothing to replace, and a naive scan
  // that emits one never moves past it, so the scan instead steps one
  // character further and carries on. Capped at 5000 matches so a pattern
  // like "." over a large file cannot build an unbounded array nobody reads.
  function searchMatches(text, needle, opts) {
    try {
      if (typeof text !== 'string' || !needle) return [];
      opts = opts || {};
      var caseSensitive = !!opts.caseSensitive;

      // Line-start offsets, walked once up front rather than re-scanning the
      // text for every match — the same trade lineAtOffset's caller makes,
      // just built here instead of read off a doc.
      var lineStart = [0];
      for (var i = 0; i < text.length; i++) {
        if (text.charCodeAt(i) === 10) lineStart.push(i + 1);
      }

      function locate(offset) {
        var lo = 0, hi = lineStart.length - 1;
        while (lo < hi) {
          var mid = (lo + hi + 1) >> 1;
          if (lineStart[mid] <= offset) lo = mid; else hi = mid - 1;
        }
        return { line: lo, col: offset - lineStart[lo] };
      }

      var out = [];

      if (opts.regex) {
        var re;
        try {
          // 'm' as well as 'g': in a code editor ^ and $ are expected to mean
          // the start and end of a LINE, which is how every editor with a
          // regex search behaves. Without it ^services matches only if the
          // file opens with it, which reads as the search being broken.
          re = new RegExp(needle, caseSensitive ? 'gm' : 'gim');
        } catch (e) {
          return [];
        }
        var pos = 0;
        while (pos <= text.length && out.length < 5000) {
          re.lastIndex = pos;
          var m = re.exec(text);
          if (!m) break;
          if (m[0].length === 0) { pos = m.index + 1; continue; }   // see comment above
          var loc = locate(m.index);
          out.push({ start: m.index, end: m.index + m[0].length, line: loc.line, col: loc.col });
          pos = m.index + m[0].length;
        }
        return out;
      }

      // Plain substring search: indexOf in a loop rather than escaping the
      // needle into a RegExp, which is both slower and has more ways to go
      // wrong for a case that is only ever a fixed string.
      var hay = caseSensitive ? text : text.toLowerCase();
      var pin = caseSensitive ? needle : needle.toLowerCase();
      var at = 0;
      while (out.length < 5000) {
        var idx = hay.indexOf(pin, at);
        if (idx < 0) break;
        var l = locate(idx);
        out.push({ start: idx, end: idx + pin.length, line: l.line, col: l.col });
        at = idx + pin.length;   // non-overlapping: resume after this match
      }
      return out;
    } catch (e) {
      return [];
    }
  }

  /* =====================================================================
   * Syntax highlighting
   *
   * A one-line-at-a-time tokeniser for the editor's overlay: highlight(line,
   * carry) returns {html, carry}. It is deliberately NOT built on parse() —
   * that walks a whole file and seals anything it cannot safely rewrite,
   * whereas every line here has to produce *something* colourable even
   * inside a sealed region, because the editor still has to show it. It
   * does reuse classify() and splitComment(), the two line-shaped pieces
   * parse() itself is built from, rather than inventing a second reading of
   * the same syntax.
   *
   * carry is one of exactly three shapes, chosen so the caller can compare
   * two with !==: '' (nothing open), 'block:<n>' (inside a block scalar
   * whose owning key sits at indent <n>), 'flow:<n>' (inside a [ ] or { }
   * left open at end of line, <n> = bracket depth). Nothing else needs
   * cross-line state — a quoted string left open across lines is real YAML
   * but rare enough, and unimportant enough once it happens, that it is
   * coloured as one best-effort span on its own line and the next line
   * starts fresh; the alternative is a fourth carry shape for a case that
   * does not come up in a compose file.
   *
   * THE INVARIANT: strip the tags from html and decode &amp; &lt; &gt; and
   * the result must be `line`, character for character. Every function
   * below is written to consume exactly the text it is handed and account
   * for all of it, which is what makes that true without a second pass to
   * check it.
   * ===================================================================== */

  var BOOL_RE = /^(?:true|false|yes|no|on|off|null|~)$/i;
  var NUM_RE  = /^[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?$/;
  var BLOCK_INDICATOR_RE = /^[|>][+\-]?[0-9]*/;
  var TOKEN_RE = /^\S+/;
  var LEAD_WS_RE = /^[ \t]*/;
  var TRAIL_WS_RE = /[ \t]*$/;

  function escapeHtml(s) {
    return s.replace(/[&<>]/g, function (c) {
      return c === '&' ? '&amp;' : c === '<' ? '&lt;' : '&gt;';
    });
  }

  function wrapSpan(kind, text) {
    return '<span class="staxx-t--' + kind + '">' + escapeHtml(text) + '</span>';
  }

  // pieces is an array of [kind, text] tuples that together cover the whole
  // input exactly once. Empty tuples are dropped rather than emitted as a
  // zero-width span — harmless either way, but this keeps the output small.
  function piecesToHtml(pieces) {
    var out = [];
    for (var i = 0; i < pieces.length; i++) {
      if (pieces[i][1] === '') continue;
      out.push(wrapSpan(pieces[i][0], pieces[i][1]));
    }
    return out.join('');
  }

  // Splits text into 'var' spans for ${...} and $NAME, and 'str' for
  // everything between them. '$$' is a literal dollar (compose's own escape
  // for one), so it is left inside the surrounding 'str' text rather than
  // starting a var span.
  function splitVars(text) {
    var out = [], start = 0, i = 0, n = text.length;
    while (i < n) {
      if (text.charAt(i) !== '$') { i++; continue; }
      if (text.charAt(i + 1) === '$') { i += 2; continue; }
      if (text.charAt(i + 1) === '{') {
        var close = text.indexOf('}', i + 2);
        var end = close < 0 ? n : close + 1;
        if (i > start) out.push(['str', text.slice(start, i)]);
        out.push(['var', text.slice(i, end)]);
        i = end; start = i; continue;
      }
      if (/[A-Za-z_]/.test(text.charAt(i + 1) || '')) {
        var j = i + 1;
        while (j < n && /[A-Za-z0-9_]/.test(text.charAt(j))) j++;
        if (i > start) out.push(['str', text.slice(start, i)]);
        out.push(['var', text.slice(i, j)]);
        i = j; start = i; continue;
      }
      i++;      // a lone '$' followed by nothing that reads as a name
    }
    if (start < n) out.push(['str', text.slice(start)]);
    return out;
  }

  // A trailing whitespace-then-comment tail, already split off a value by
  // splitComment (whose .comment field always includes that leading gap) —
  // or a raw tail from after a quote or block indicator, where a '#' may not
  // be present at all. Either way the gap is plain text and only a genuine
  // '#...' run is the comment span.
  function tailPieces(s) {
    if (s === '') return [];
    var ws = LEAD_WS_RE.exec(s)[0];
    var rest = s.slice(ws.length);
    var out = [];
    if (ws) out.push(['text', ws]);
    if (rest === '') return out;
    out.push([rest.charAt(0) === '#' ? 'comment' : 'text', rest]);
    return out;
  }

  // A plain (unquoted) scalar: whole-value bool/num, else text with any
  // ${...}/$NAME split out, then whatever trailing comment splitComment finds.
  function scanScalarTail(text) {
    if (text === '') return [];
    var sc = splitComment(text);
    var pieces = [];
    if (sc.value !== '') {
      var trail = TRAIL_WS_RE.exec(sc.value)[0];
      var core = trail.length ? sc.value.slice(0, sc.value.length - trail.length) : sc.value;
      if (core === '') {
        pieces.push(['text', sc.value]);
      } else if (BOOL_RE.test(core)) {
        pieces.push(['bool', core]);
        if (trail) pieces.push(['text', trail]);
      } else if (NUM_RE.test(core)) {
        pieces.push(['num', core]);
        if (trail) pieces.push(['text', trail]);
      } else {
        pieces = pieces.concat(splitVars(core));
        if (trail) pieces.push(['text', trail]);
      }
    }
    return pieces.concat(tailPieces(sc.comment));
  }

  // A single quoted token starting at text.charAt(i). Returns null if it
  // never closes on this line — a genuine multi-line quoted scalar, which is
  // left as one best-effort 'str' span by the caller rather than growing a
  // fourth carry shape for it (see the section comment above).
  function scanQuotedToken(text, i) {
    var q = text.charAt(i), end = -1, k;
    if (q === '"') {
      for (k = i + 1; k < text.length; k++) {
        if (text.charAt(k) === '\\') { k++; continue; }
        if (text.charAt(k) === '"') { end = k; break; }
      }
    } else {
      for (k = i + 1; k < text.length; k++) {
        if (text.charAt(k) === "'") {
          if (text.charAt(k + 1) === "'") { k++; continue; }
          end = k; break;
        }
      }
    }
    if (end < 0) return null;
    // The quotes themselves are part of the 'str' span; only a real
    // ${...}/$NAME inside gets split out, same as an unquoted value.
    return { end: end + 1, pieces: splitVars(text.slice(i, end + 1)) };
  }

  function classifyBareToken(tok) {
    if (tok === '') return [];
    if (BOOL_RE.test(tok)) return [['bool', tok]];
    if (NUM_RE.test(tok)) return [['num', tok]];
    return splitVars(tok);
  }

  // Tokenises `text` as flow-collection content starting at bracket depth
  // `depth`, used both to open a [ ] / { } value and to carry on across a
  // following line. Comments, quoted strings and ${...} all still apply
  // inside a flow collection, so this does not reduce to a dumber scan.
  function scanFlow(text, depth) {
    var out = [], i = 0, n = text.length;
    while (i < n) {
      var ch = text.charAt(i);
      if (ch === ' ' || ch === '\t') {
        var j = i;
        while (j < n && (text.charAt(j) === ' ' || text.charAt(j) === '\t')) j++;
        out.push(['text', text.slice(i, j)]); i = j; continue;
      }
      if (ch === '#' && (i === 0 || text.charAt(i - 1) === ' ' || text.charAt(i - 1) === '\t')) {
        out.push(['comment', text.slice(i)]); i = n; continue;
      }
      if (ch === '[' || ch === '{') { depth++; out.push(['punct', ch]); i++; continue; }
      if (ch === ']' || ch === '}') { if (depth > 0) depth--; out.push(['punct', ch]); i++; continue; }
      if (ch === ',') { out.push(['punct', ch]); i++; continue; }
      if (ch === '"' || ch === "'") {
        var q = scanQuotedToken(text, i);
        if (q) { out.push.apply(out, q.pieces); i = q.end; continue; }
        out.push(['str', text.slice(i)]); i = n; continue;
      }
      var k = i;
      while (k < n) {
        var c2 = text.charAt(k);
        if (c2 === ' ' || c2 === '\t' || c2 === '[' || c2 === ']' || c2 === '{' || c2 === '}' ||
            c2 === ',' || c2 === '"' || c2 === "'") break;
        if (c2 === '#' && (text.charAt(k - 1) === ' ' || text.charAt(k - 1) === '\t')) break;
        k++;
      }
      out.push.apply(out, classifyBareToken(text.slice(i, k)));
      i = k;
    }
    return { pieces: out, depth: depth };
  }

  // Colours one value, from `col` to end of line — whatever compose shape it
  // turns out to be (quoted or plain scalar, block scalar opener, flow
  // collection, anchor/alias/tag). `ownerIndent` is what a block scalar
  // opened here carries forward: the indent of the key or sequence item this
  // value belongs to, exactly as scanValue()'s own ownerIndent parameter
  // means it above.
  function renderValueArea(line, col, ownerIndent) {
    var text = line.slice(col);
    if (text === '') return { pieces: [], carry: '' };
    var head = text.charAt(0);

    if (head === '|' || head === '>') {
      var ind = BLOCK_INDICATOR_RE.exec(text)[0];
      return {
        pieces: [['text', ind]].concat(tailPieces(text.slice(ind.length))),
        carry: 'block:' + ownerIndent
      };
    }

    // '&anchor', '*alias' and '!tag'/'!!tag' all read as one opaque token to
    // the parser proper (scanValue seals each of them), so here they are one
    // 'anchor' span each; anything after the token on the same line is rare
    // enough (an anchored value written beside its own anchor) that it is
    // coloured as a plain scalar tail rather than re-run through this same
    // dispatch a second time.
    if (head === '&' || head === '*' || head === '!') {
      var tok = TOKEN_RE.exec(text)[0];
      return { pieces: [['anchor', tok]].concat(scanScalarTail(text.slice(tok.length))), carry: '' };
    }

    if (head === '[' || head === '{') {
      var fr = scanFlow(text, 0);
      return { pieces: fr.pieces, carry: fr.depth > 0 ? 'flow:' + fr.depth : '' };
    }

    if (head === '"' || head === "'") {
      var q = scanQuotedToken(text, 0);
      if (!q) return { pieces: [['str', text]], carry: '' };   // unterminated: best effort
      return { pieces: q.pieces.concat(tailPieces(text.slice(q.end))), carry: '' };
    }

    return { pieces: scanScalarTail(text), carry: '' };
  }

  // Renders a key line (top-level, or a key sitting beside a sequence dash —
  // `k` is either way a classify()-shaped object with keyRaw/key/valueCol).
  // `pieces` already holds whatever came before the key (the line's indent).
  function renderKeyLine(line, k, pieces) {
    // The merge key itself is one of the constructs the anchor kind covers,
    // not an ordinary mapping key — see the KIND table in the caller's
    // contract.
    pieces.push([k.key === '<<' ? 'anchor' : 'key', k.keyRaw]);

    var afterKey = k.indent + k.keyRaw.length;
    var colon = afterKey;
    while (colon < line.length && line.charAt(colon) !== ':') colon++;
    if (colon > afterKey) pieces.push(['text', line.slice(afterKey, colon)]);
    if (colon < line.length) pieces.push(['punct', ':']);

    if (k.valueCol < 0) {
      return { html: piecesToHtml(pieces.concat(tailPieces(line.slice(colon + 1)))), carry: '' };
    }

    if (k.valueCol > colon + 1) pieces.push(['text', line.slice(colon + 1, k.valueCol)]);
    var v = renderValueArea(line, k.valueCol, k.indent);
    return { html: piecesToHtml(pieces.concat(v.pieces)), carry: v.carry };
  }

  // The classified content beside a dash — a nested key (classifyAt already
  // reads "- image: alpine" as a key at the content column), a comment, or
  // anything else, which is a value read at the sequence item's own indent
  // (scanValue's real ownerIndent for a bare item — see parseItem — is the
  // dash's indent, not the content column, which is why `ownerIndent` is
  // threaded through rather than reusing sub.indent).
  function renderSeqSub(line, sub, pieces, ownerIndent) {
    if (sub.kind === 'comment') {
      return { html: piecesToHtml(pieces.concat([['comment', line.slice(sub.indent)]])), carry: '' };
    }
    if (sub.kind === 'key') return renderKeyLine(line, sub, pieces);
    var v = renderValueArea(line, sub.indent, ownerIndent);
    return { html: piecesToHtml(pieces.concat(v.pieces)), carry: v.carry };
  }

  function renderNormalLine(line) {
    var c = classify(line, 0);
    var pieces = [];

    if (c.kind === 'blank') return { html: piecesToHtml([['text', line]]), carry: '' };

    // Its own kind, not 'text', purely so the editor can hang indent guides on
    // it. Drawn as a background on the pane instead they run the full width of
    // every line and strike through the middle of comment prose; on this span
    // they stop where the indentation does, which is what an indent guide is.
    if (c.indent) pieces.push(['indent', line.slice(0, c.indent)]);

    if (c.kind === 'comment') {
      return { html: piecesToHtml(pieces.concat([['comment', line.slice(c.indent)]])), carry: '' };
    }

    if (c.kind === 'seq') {
      pieces.push(['punct', '-']);
      if (!c.sub) {
        var afterDash = line.slice(c.indent + 1);
        if (afterDash) pieces.push(['text', afterDash]);
        return { html: piecesToHtml(pieces), carry: '' };
      }
      if (c.contentCol > c.indent + 1) pieces.push(['text', line.slice(c.indent + 1, c.contentCol)]);
      return renderSeqSub(line, c.sub, pieces, c.indent);
    }

    if (c.kind === 'key') return renderKeyLine(line, c, pieces);

    // 'other': no recognisable structure (a bare document scalar, a
    // malformed line, a '---' marker). Coloured as a value with nowhere in
    // particular to belong, same fallback the rest of the parser gives it.
    var v = renderValueArea(line, c.indent, c.indent);
    return { html: piecesToHtml(pieces.concat(v.pieces)), carry: v.carry };
  }

  // API.highlight — see the section comment above for the carry contract.
  // Never throws: anything this cannot make sense of comes back as one
  // 'text' span holding the escaped line, because an editor that stops
  // colouring is a nuisance and one that throws is a dead page.
  function highlight(line, carry) {
    try {
      carry = carry || '';
      if (carry.charAt(0) === 'b') {
        var n = parseInt(carry.slice(6), 10);
        var bc = classify(line, 0);
        if (bc.kind === 'blank' || bc.indent > n) {
          return { html: line === '' ? '' : wrapSpan('text', line), carry: carry };
        }
        return renderNormalLine(line);       // back to (or above) the owning indent: the block ends here
      }
      if (carry.charAt(0) === 'f') {
        var fr = scanFlow(line, parseInt(carry.slice(5), 10));
        return { html: piecesToHtml(fr.pieces), carry: fr.depth > 0 ? 'flow:' + fr.depth : '' };
      }
      return renderNormalLine(line);
    } catch (e) {
      return { html: line === '' ? '' : wrapSpan('text', line), carry: '' };
    }
  }

  /* =====================================================================
   * Linting
   *
   * A read-only second pass over an already-parsed doc: nothing here edits
   * the file, so it can run after every keystroke without any of the care
   * splice()/writeScalar() take. Two kinds of thing are worth a line in the
   * gutter — a construct the parser genuinely could not read (an error), and
   * a key that is not a real compose setting (a warning) — everything else
   * (an anchor, a flow list, a block scalar...) is an ordinary construct this
   * parser chooses not to edit in place, not a fault in the file, and stays
   * silent here.
   * ===================================================================== */

  // A short, iterative Levenshtein distance for "did you mean" — only ever
  // run over short key names, so there is no reason to reach for anything
  // cleverer than the textbook one-row version.
  function levenshtein(a, b) {
    var m = a.length, n = b.length, i, j, row = [];
    for (j = 0; j <= n; j++) row[j] = j;
    for (i = 1; i <= m; i++) {
      var prev = row[0];
      row[0] = i;
      for (j = 1; j <= n; j++) {
        var tmp = row[j];
        row[j] = a.charAt(i - 1) === b.charAt(j - 1) ? prev : 1 + Math.min(prev, row[j], row[j - 1]);
        prev = tmp;
      }
    }
    return row[n];
  }

  // The nearest real key within edit distance 2 — offered only once the typo
  // itself is at least 4 characters, since below that almost any word is
  // within 2 of something and the suggestion stops meaning anything.
  function nearestKey(key, list) {
    if (key.length < 4) return null;
    var best = null, bestDist = 3;
    for (var i = 0; i < list.length; i++) {
      var d = levenshtein(key, list[i]);
      if (d < bestDist) { bestDist = d; best = list[i]; }
    }
    return best;
  }

  function unknownKeyMessage(key, list) {
    var near = nearestKey(key, list);
    return near
      ? 'The key "' + key + '" is not a compose setting. Did you mean "' + near + '"?'
      : 'The key "' + key + '" is not a compose setting, so Docker will ignore it.';
  }

  // A key is only judged when it reads as an ordinary identifier — letters,
  // digits, underscores. A quoted key holding spaces or punctuation is
  // exotic enough that guessing at it would be more likely to mislead than
  // help, so it is left alone rather than risking a false alarm.
  var KEY_TEXT_RE = /^[A-Za-z_][A-Za-z0-9_]*$/;

  // Our own metadata key. Compose ignores every key beginning 'x-' without
  // looking inside it, so a misspelling here produces a perfectly valid file
  // whose settings this plugin then never reads — and the failure has no
  // symptom beyond a form that looks oddly empty, which is why it needs
  // saying out loud rather than leaving to be discovered.
  var META_KEY = 'x-unraid';

  // Only a NEAR miss is judged. An 'x-' key that is nothing like ours is
  // somebody's own shared block ('x-common: &common'), which is ordinary
  // compose and none of our business. Compared in lower case because a
  // capitalised key is the same trap: YAML key names are case-sensitive,
  // which is not obvious to anyone who has not been bitten by it.
  function metaKeyTypo(key) {
    var lower = key.toLowerCase();
    if (key === META_KEY || lower.slice(0, 2) !== 'x-') return false;
    return levenshtein(lower, META_KEY) <= 2;
  }

  function metaTypoMessage(key) {
    if (key.toLowerCase() === META_KEY) {
      return 'The key "' + key + '" differs from "' + META_KEY + '" only in capitals, and key ' +
             'names are case-sensitive, so none of the settings inside it are being read. ' +
             'Write it in lower case.';
    }
    return 'The key "' + key + '" looks like a misspelling of "' + META_KEY + '". Compose ignores ' +
           'anything starting with "x-", so the file itself is fine — but none of the settings ' +
           'inside this block are being read. Rename it to "' + META_KEY + '".';
  }

  // Pushes one warning per key in `map` that is not in `specSet` — used at
  // the two levels the schema lets us be sure about: top level, and directly
  // under a service, which are also the two places `x-unraid` may appear.
  // '<<' is YAML's merge key, not a compose setting, and is skipped. So are
  // 'x-' keys, which are valid everywhere and are the whole basis of this
  // project's metadata — except for one that reads as a misspelling of ours.
  function checkSpecKeys(map, specSet, specList, add) {
    for (var i = 0; i < map.keys.length; i++) {
      var key = map.keys[i];
      if (typeof key !== 'string' || key === '<<') continue;
      // Ahead of KEY_TEXT_RE below, which rejects the hyphen every 'x-' key
      // has — tested the other way round, this branch would never run.
      //
      // Matched without regard to case, so 'X-Unraid' reaches the check
      // rather than being dropped by KEY_TEXT_RE and silently waved through.
      // Compose's own extension prefix is lower case, so a capitalised one is
      // not a valid extension key either way; ours is the more useful thing
      // to say about it.
      if (key.slice(0, 2).toLowerCase() === 'x-') {
        if (metaKeyTypo(key)) add(map.pairs[key].start, 'warn', metaTypoMessage(key));
        continue;
      }
      if (!KEY_TEXT_RE.test(key)) continue;
      if (specSet[key]) continue;
      add(map.pairs[key].start, 'warn', unknownKeyMessage(key, specList));
    }
  }

  // The two service-level keys compose's schema types as a plain string
  // rather than a closed enum (PLAN_20's measurement is what narrowed this
  // to just these two). restart's list is closed: anything else is silently
  // ignored by Docker and the container just never restarts again, with
  // nothing to say why. network_mode stays open, because a bare word may be
  // a real docker network on this server (br0, br0.100) that no list of
  // ours could ever know about — so it only warns on a near-miss of the
  // three built-in values, via the same edit-distance test nearestKey()
  // already runs, and never on an unrecognised word.
  var RESTART_VALUES = ['no', 'always', 'unless-stopped', 'on-failure'];
  var NETWORK_MODE_VALUES = ['host', 'none', 'bridge'];

  // English-join a short list — "a, b or c" — so the accepted-values
  // sentence is built from the list itself rather than typed out by hand
  // and left to drift the day the list changes.
  function joinOr(list) {
    if (list.length < 2) return list[0] || '';
    return list.slice(0, -1).join(', ') + ' or ' + list[list.length - 1];
  }

  // A scalar pair's value, or null when there is nothing safe to judge: no
  // such key, a sealed anchor/alias (kind !== 'scalar'), an empty string, or
  // one that reaches into a variable defined outside the file — a value we
  // cannot see the real contents of has no business being called a typo.
  function specScalar(map, key) {
    var pair = map.pairs[key];
    if (!pair || !pair.value || pair.value.kind !== 'scalar') return null;
    var v = pair.value.value;
    if (!v || interpolates(v)) return null;
    return { start: pair.start, value: v };
  }

  // restart: and network_mode: — see the comment above RESTART_VALUES for
  // why each is judged differently. Always 'warn': a wrong value here still
  // leaves a working container, just not the one the file describes.
  //
  // `realNets` is the server's own docker network names, passed down from the
  // page once netLoad() has them. Without it a network genuinely called "node"
  // reads as a typo of "none" — one edit apart — and the file is perfectly
  // correct. A name the server confirms is never second-guessed.
  function checkSpecValues(map, add, realNets) {
    var r = specScalar(map, 'restart');
    if (r && RESTART_VALUES.indexOf(r.value) < 0 && !/^on-failure:\d+$/.test(r.value)) {
      var rNear = nearestKey(r.value, RESTART_VALUES);
      add(r.start, 'warn', rNear
        ? '"' + r.value + '" is not one of the values restart accepts. Did you mean "' + rNear + '"?'
        : '"' + r.value + '" is not one of the values restart accepts. It accepts ' + joinOr(RESTART_VALUES) + '.');
    }

    // No list means the server has not said what networks it has yet, which is
    // not the same as "it has none" — so network_mode is left alone entirely
    // rather than judged against three built-in names. netLoad()'s reply
    // triggers a redraw, so the check simply starts a moment later.
    if (!realNets) return;

    var n = specScalar(map, 'network_mode');
    if (n && NETWORK_MODE_VALUES.indexOf(n.value) < 0 && !/^(service|container):/.test(n.value)
        && realNets.indexOf(n.value) < 0) {
      var nNear = nearestKey(n.value, NETWORK_MODE_VALUES);
      if (nNear) {
        add(n.start, 'warn', '"' + n.value + '" is not one of the values network_mode accepts. Did you mean "' + nNear + '"?');
      }
    }
  }

  // Seal reasons severe enough to call the file itself unreadable, rather
  // than merely locking the one value they cover — see the section comment
  // above for why every other seal reason stays silent here.
  var LINT_ERROR_REASONS = { 'tab-indent': 1, 'directive': 1, 'multi-doc': 1, 'unparsable': 1 };

  function sealErrorMessage(reason) {
    if (reason === 'tab-indent') {
      return 'This file is indented with tabs, which YAML does not allow. Replace the tabs with spaces.';
    }
    if (reason === 'directive') {
      return 'This file uses a YAML directive (%YAML or %TAG), which the form cannot read. Use the Compose view to edit it.';
    }
    if (reason === 'multi-doc') {
      return 'This file holds more than one YAML document, which the form cannot read. Use the Compose view to edit it.';
    }
    return 'This part of the file is written in a way that cannot be read as YAML, so Docker is likely to reject it.';
  }

  /**
   * lineOfPath(doc, path) -> 0-based line, or -1
   *
   * Compose's schema complaints name a path rather than a line — "validating
   * your compose file: services.web.pull_policy 'alwyas' does not match …" —
   * so without this they can only be shown in the status bar, where they are
   * easy to miss. Walking the path back to a line puts the mark on the line
   * that is actually wrong.
   *
   * Segments are matched longest-first, because a service may legitimately be
   * called something like "my.app" and a naive split on '.' would walk past
   * it. A segment that cannot be found stops the walk and returns the deepest
   * parent reached: pointing at the enclosing block is honest, whereas
   * guessing a line is not.
   */
  function lineOfPath(doc, path) {
    if (!doc || !doc.root || doc.root.kind !== 'map' || !path) return -1;

    var parts = String(path).split('.');
    var map = doc.root, at = -1, i = 0;

    while (i < parts.length && map && map.kind === 'map') {
      var pair = null, used = 0;
      for (var take = parts.length - i; take >= 1; take--) {
        var key = parts.slice(i, i + take).join('.');
        if (map.pairs[key]) { pair = map.pairs[key]; used = take; break; }
      }
      if (!pair) break;
      at = pair.start;
      i += used;
      map = pair.value;
    }
    return at;
  }

  /**
   * lint(doc, realNets) -> [{ line, level: 'error'|'warn', message }]
   *
   * `realNets` is the docker network names this server actually has, so a
   * network_mode naming one of them is never mistaken for a typo. Omitting it
   * means "not known yet" — which is not the same as "there are none" — and
   * switches the network_mode check off rather than letting it guess.
   *
   * `line` is 0-based; -1 would mean a problem with the file as a whole, but
   * every check here already has a real line to point at. Sorted ascending,
   * at most one entry per line — several problems on the same line are
   * joined with a space, keeping 'error' over 'warn' if both occur.
   *
   * Never throws: wrapped so any internal failure returns [] rather than
   * risking a linter that can kill the editor being worse than one that
   * quietly says nothing.
   */
  function lint(doc, realNets) {
    try {
      var byLine = {};
      function add(line, level, message) {
        var cur = byLine[line];
        if (!cur) { byLine[line] = { level: level, parts: [message] }; return; }
        cur.parts.push(message);
        if (level === 'error') cur.level = 'error';
      }

      var sealed = (doc && doc.sealed) || [];
      for (var si = 0; si < sealed.length; si++) {
        if (LINT_ERROR_REASONS[sealed[si].reason]) {
          add(sealed[si].start, 'error', sealErrorMessage(sealed[si].reason));
        }
      }

      // Duplicate keys — parse() already found these; reused, not re-detected.
      var warnings = (doc && doc.warnings) || [];
      for (var wi = 0; wi < warnings.length; wi++) {
        add(warnings[wi].line, 'warn', warnings[wi].message);
      }

      if (doc && doc.root && doc.root.kind === 'map') {
        checkSpecKeys(doc.root, TOP_SPEC_SET, TOP_SPEC_KEYS, add);

        var svc = doc.root.pairs['services'];
        if (svc && svc.value && svc.value.kind === 'map') {
          for (var i = 0; i < svc.value.keys.length; i++) {
            var p = svc.value.pairs[svc.value.keys[i]];
            // A service written in a way this parser cannot open at all
            // (kind !== 'map') has no keys to check — that is what "skip
            // anything inside a sealed region" means here.
            if (p.value && p.value.kind === 'map') {
              checkSpecKeys(p.value, SERVICE_SPEC_SET, SERVICE_SPEC_KEYS, add);
              checkSpecValues(p.value, add, realNets);
            }
          }
        }
      }

      var out = [];
      for (var line in byLine) {
        if (!byLine.hasOwnProperty(line)) continue;
        out.push({ line: parseInt(line, 10), level: byLine[line].level, message: byLine[line].parts.join(' ') });
      }
      out.sort(function (a, b) { return a.line - b.line; });
      return out;
    } catch (e) {
      return [];
    }
  }

  /* =====================================================================
   * Key suggestions and descriptions
   *
   * Autocomplete for the raw-YAML editor, and the hover text that goes with
   * it. Both work from the raw text and never call parse() — the file is
   * mid-edit exactly when a suggestion is wanted, which is when parse() has
   * the least to say about it. classify() alone is enough: walking upward
   * through smaller and smaller indents, using only what it reports about
   * each line, rebuilds a key's ancestry without ever needing a tree.
   * ===================================================================== */

  // build:'s own sub-keys. Kept separate from LEAVES on purpose: LEAVES is
  // read by harvestLeaves() to decide which FORM FIELDS every service gets,
  // whether or not the file has them (see the comment there) — adding
  // 'build' to it would silently give every service new build.* fields
  // nobody asked for. This table is read only by keyInfo()/suggestionContext()
  // below.
  var BUILD_LEAVES = {
    context:             'Build context',
    dockerfile:          'Dockerfile path',
    args:                'Build arguments',
    target:              'Build stage',
    cache_from:          'Cache sources',
    cache_to:            'Cache export',
    network:             'Build network',
    platforms:           'Platforms',
    shm_size:            'Shared memory size',
    privileged:          'Privileged build',
    pull:                'Always pull base images',
    labels:              'Image labels',
    secrets:             'Build secrets',
    ssh:                 'SSH access',
    additional_contexts: 'Extra build contexts',
    no_cache:            'Ignore build cache',
    isolation:           'Isolation',
    extra_hosts:         'Extra hosts',
    tags:                'Extra image tags',
    ulimits:             'Build ulimits'
  };

  // deploy:'s own direct children. Split from LEAVES.deploy for exactly the
  // reason BUILD_LEAVES is split from LEAVES above: LEAVES.deploy holds the
  // four dotted paths harvestLeaves() turns into form fields, so adding
  // 'mode' or 'replicas' there would silently give every service new fields
  // nobody asked for. This table is read only by keyInfo()/suggestionContext()
  // below.
  var DEPLOY_LEAVES = {
    mode:            'Replication mode',
    replicas:        'Number of copies',
    labels:          'Deployment labels',
    endpoint_mode:   'Endpoint mode',
    placement:       'Placement',
    resources:       'Resource limits',
    restart_policy:  'Restart policy (swarm)',
    rollback_config: 'Rollback settings',
    update_config:   'Update settings'
  };

  // Two blocks under deploy: with identical shape — a rolling update's
  // settings and the settings used to roll one back — so one key list
  // serves both rather than being written out twice.
  var RESTART_POLICY_KEYS = ['condition', 'delay', 'max_attempts', 'window'];
  var UPDATE_CONFIG_KEYS  = ['parallelism', 'delay', 'failure_action', 'monitor', 'max_failure_ratio', 'order'];

  // logging.options is a free-form map — a logging driver may accept any
  // option at all — so this is a hint offered at the caret, not a closed
  // list the way the other tables here are.
  var LOGGING_OPTIONS_KEYS = ['mode', 'max-size', 'max-file', 'tag', 'labels', 'env'];

  // A long-form dependency's own keys — DEPENDS_LEAVES (:710) plus
  // condition, which lives apart from it because helpGaps() checks it
  // separately too. Read only by suggestionContext() below.
  var DEPENDS_KEYS = ['restart', 'required', 'condition'];

  // The declaration keys the compose specification actually allows, kept
  // apart from DECL_LEAVES for the same reason DEPLOY_LEAVES is kept apart
  // from LEAVES.deploy: DECL_LEAVES also drives which fold rows the form
  // renders, so adding a key here must never touch it. Read only by
  // suggestionContext() below.
  var DECL_SPEC_KEYS = {
    networks: ['driver', 'driver_opts', 'attachable', 'enable_ipv6', 'external', 'internal', 'ipam', 'labels', 'name'],
    volumes:  ['driver', 'driver_opts', 'external', 'labels', 'name'],
    secrets:  ['file', 'environment', 'external', 'name'],
    configs:  ['file', 'environment', 'external', 'name']
  };

  // PLAN_15 phase 1: the allowed-value lists a dropdown offers, moved out of
  // stacks.js so the Compose pane's editor can read them too (stacks.js has
  // no way to reach anything in a browser-only IIFE, and this file is the
  // only half of the two that a Node test can load). One entry per
  // vocabulary id, each a [value, label] list in the exact order the
  // dropdown shows it.
  //
  // Four lists that lived beside these in stacks.js's CHOICES table did NOT
  // move here, on purpose:
  //   - healthcheck.test's mode (shell/cmd/none) — words readTest()/
  //     writeTest() invented for the dropdown; the file itself says
  //     CMD-SHELL, not "shell". Not a compose value.
  //   - a SHORT-form port's protocol and a SHORT-form volume's mode — the
  //     value carries its own separator ('', '/udp', ':ro'), so choosing
  //     "nothing" writes the separator away too. Meaningless as text in an
  //     editor. (The long form spells the protocol out as its own bare-word
  //     line with no separator to carry, so it draws on 'protocol' below.)
  //   - the eight BOOL_CHOICES wordings — the values true/false move below
  //     as 'boolean', but privileged's true/false labels are form prose about
  //     one specific setting, not a compose vocabulary.
  //
  // A label is always the value itself, nothing appended: what the dropdown
  // shows must be exactly what the file ends up holding, or picking an
  // option stops teaching anyone what their compose file says. Any
  // explanation of what a value does belongs behind the field's own help
  // button, not in the option text.
  var VOCAB = {
    restart: [
      ['no',             'no'],
      ['always',         'always'],
      ['unless-stopped', 'unless-stopped'],
      ['on-failure',     'on-failure']
    ],
    netmode: [
      ['bridge', 'bridge'],
      ['host',   'host'],
      ['none',   'none']
    ],
    // A long-form port's protocol: — a bare word on its own line, unlike the
    // short form's slash-carrying value, so it is a genuine closed vocabulary
    // rather than something a dropdown's "nothing chosen" option has to undo.
    protocol: [
      ['tcp', 'tcp'],
      ['udp', 'udp']
    ],
    // The four B3 vocabularies below serve a long-form port or mount's
    // "more settings" extras — values taken from the compose specification,
    // not guessed, since these are otherwise unreachable in either view.
    portmode: [
      ['host',    'host'],
      ['ingress', 'ingress']
    ],
    volumetype: [
      ['bind',   'bind'],
      ['volume', 'volume'],
      ['tmpfs',  'tmpfs'],
      ['npipe',  'npipe'],
      ['cluster','cluster'],
      ['image',  'image']
    ],
    propagation: [
      ['rprivate', 'rprivate'],
      ['private',  'private'],
      ['rshared',  'rshared'],
      ['shared',   'shared'],
      ['rslave',   'rslave'],
      ['slave',    'slave']
    ],
    selinux: [
      ['z', 'z'],
      ['Z', 'Z']
    ],
    dependscondition: [
      ['service_started',                'wait until it has started'],
      ['service_healthy',                'wait until it reports healthy'],
      ['service_completed_successfully',  'wait until it has finished OK']
    ],
    pullpolicy: [
      ['always',        'always'],
      ['never',         'never'],
      ['missing',       'missing'],
      ['if_not_present', 'if_not_present'],
      ['refresh',       'refresh'],
      ['daily',         'daily'],
      ['weekly',        'weekly'],
      ['build',         'build']
    ],
    stopsignal: [
      ['SIGTERM', 'SIGTERM'],
      ['SIGINT',  'SIGINT'],
      ['SIGKILL', 'SIGKILL'],
      ['SIGHUP',  'SIGHUP'],
      ['SIGQUIT', 'SIGQUIT'],
      ['SIGUSR1', 'SIGUSR1'],
      ['SIGUSR2', 'SIGUSR2']
    ],
    ipc: [
      ['host',      'host'],
      ['none',      'none'],
      ['shareable', 'shareable']
    ],
    pid: [
      ['host', 'host']
    ],
    logdriver: [
      ['json-file', 'json-file'],
      ['local',     'local'],
      ['syslog',    'syslog'],
      ['journald',  'journald'],
      ['fluentd',   'fluentd'],
      ['none',      'none']
    ],
    networkdriver: [
      ['bridge',  'bridge'],
      ['host',    'host'],
      ['none',    'none'],
      ['macvlan', 'macvlan'],
      ['ipvlan',  'ipvlan'],
      ['overlay', 'overlay']
    ],
    volumedriver: [
      ['local', 'local']
    ],
    capability: [
      ['ALL',                'ALL'],
      ['CHOWN',              'CHOWN'],
      ['DAC_OVERRIDE',       'DAC_OVERRIDE'],
      ['DAC_READ_SEARCH',    'DAC_READ_SEARCH'],
      ['FOWNER',             'FOWNER'],
      ['FSETID',             'FSETID'],
      ['KILL',               'KILL'],
      ['SETGID',             'SETGID'],
      ['SETUID',             'SETUID'],
      ['SETPCAP',            'SETPCAP'],
      ['LINUX_IMMUTABLE',    'LINUX_IMMUTABLE'],
      ['NET_BIND_SERVICE',   'NET_BIND_SERVICE'],
      ['NET_BROADCAST',      'NET_BROADCAST'],
      ['NET_ADMIN',          'NET_ADMIN'],
      ['NET_RAW',            'NET_RAW'],
      ['IPC_LOCK',           'IPC_LOCK'],
      ['IPC_OWNER',          'IPC_OWNER'],
      ['SYS_MODULE',         'SYS_MODULE'],
      ['SYS_RAWIO',          'SYS_RAWIO'],
      ['SYS_CHROOT',         'SYS_CHROOT'],
      ['SYS_PTRACE',         'SYS_PTRACE'],
      ['SYS_PACCT',          'SYS_PACCT'],
      ['SYS_ADMIN',          'SYS_ADMIN'],
      ['SYS_BOOT',           'SYS_BOOT'],
      ['SYS_NICE',           'SYS_NICE'],
      ['SYS_RESOURCE',       'SYS_RESOURCE'],
      ['SYS_TIME',           'SYS_TIME'],
      ['SYS_TTY_CONFIG',     'SYS_TTY_CONFIG'],
      ['MKNOD',              'MKNOD'],
      ['LEASE',              'LEASE'],
      ['AUDIT_WRITE',        'AUDIT_WRITE'],
      ['AUDIT_CONTROL',      'AUDIT_CONTROL'],
      ['SETFCAP',            'SETFCAP'],
      ['MAC_OVERRIDE',       'MAC_OVERRIDE'],
      ['MAC_ADMIN',          'MAC_ADMIN'],
      ['SYSLOG',             'SYSLOG'],
      ['WAKE_ALARM',         'WAKE_ALARM'],
      ['BLOCK_SUSPEND',      'BLOCK_SUSPEND'],
      ['AUDIT_READ',         'AUDIT_READ'],
      ['PERFMON',            'PERFMON'],
      ['BPF',                'BPF'],
      ['CHECKPOINT_RESTORE', 'CHECKPOINT_RESTORE']
    ],
    // The generic true/false pair — matches BOOL_GENERIC.options in
    // tests/vocab-snapshot.js, not any one BOOL_CHOICES wording.
    boolean: [['true', 'true'], ['false', 'false']],

    // PLAN_15 phase 3: the survey's gap list. isolation, deploymode and
    // endpointmode are Windows-only or Docker-Swarm-only, which almost never
    // applies on an Unraid server — each value says so, rather than reading
    // as a plausible everyday choice.
    uts: [
      ['host', 'host']
    ],
    cgroup: [
      ['host',    'host'],
      ['private', 'private']
    ],
    usernsmode: [
      ['host', 'host']
    ],
    isolation: [
      ['default', 'default'],
      ['process', 'process'],
      ['hyperv',  'hyperv']
    ],
    deploymode: [
      ['replicated',     'replicated'],
      ['global',         'global'],
      ['replicated-job', 'replicated-job'],
      ['global-job',     'global-job']
    ],
    endpointmode: [
      ['vip',    'vip'],
      ['dnsrr',  'dnsrr']
    ],
    restartcondition: [
      ['none',       'none'],
      ['on-failure', 'on-failure'],
      ['any',        'any']
    ],
    updateorder: [
      ['start-first', 'start-first'],
      ['stop-first',  'stop-first']
    ],
    failureaction: [
      ['continue', 'continue'],
      ['rollback', 'rollback'],
      ['pause',    'pause']
    ],
    logmode: [
      ['blocking',     'blocking'],
      ['non-blocking', 'non-blocking']
    ],
    buildnetwork: [
      ['none',    'none'],
      ['host',    'host'],
      ['default', 'default']
    ]
  };

  // vocab(id) -> null | [[value, label], ...]
  //
  // Returns a fresh copy of the list every call, never the registry's own
  // array. stacks.js's netLoad() today does
  // CHOICES['setting/network_mode'].options.push(...) to append the
  // server's own docker networks — which permanently mutates a table every
  // service's dropdown shares (see the comment above serviceModeOptions()
  // in stacks.js for what that leak looks like from the far side). Handing
  // back a copy means a caller that repeats that mistake only ever grows its
  // own array.
  // hasOwnProperty, not a plain lookup, for the reason keyInfo() below uses
  // it too: 'constructor' and 'toString' are inherited from Object and would
  // come back as functions, so a bare check for truthiness reaches .slice()
  // on something that has none.
  function vocab(id) {
    if (!Object.prototype.hasOwnProperty.call(VOCAB, id)) return null;
    return VOCAB[id].slice();
  }

  // PLAN_15 phase 2: which vocabulary (a VOCAB id above) a key's VALUE is
  // drawn from, mirroring DESCRIPTIONS' "where" buckets for keys. Kept as a
  // separate table rather than folded into DESCRIPTIONS, because a key can
  // have help text with no fixed value list (image:) or a value list with no
  // help text worth adding twice — the two tables answer different questions.
  //
  // 'declared' is NOT one shared bucket the way DESCRIPTIONS.declared is. A
  // description of "driver" reads the same for a network or a volume, but the
  // LIST of drivers does not — bridge/host/macvlan means nothing for a
  // volume, and 'local' is the only volume driver this editor knows of. So
  // the four declaration kinds get their own entries here, keyed by kind
  // (path[0] for a declaration — 'networks', 'volumes', 'secrets', 'configs'),
  // and valueSuggestions() below resolves the kind before it ever looks here.
  var VOCAB_AT = {
    service: {
      restart: 'restart', network_mode: 'netmode', pull_policy: 'pullpolicy',
      stop_signal: 'stopsignal', ipc: 'ipc', pid: 'pid',
      cap_add: 'capability', cap_drop: 'capability',
      privileged: 'boolean', read_only: 'boolean', init: 'boolean',
      tty: 'boolean', stdin_open: 'boolean',
      // These six are reached through SERVICE_SPEC_KEYS, not KEYS — they have
      // no form control, so the editor gains a vocabulary with no change to
      // the form at all.
      uts: 'uts', cgroup: 'cgroup', userns_mode: 'usernsmode', isolation: 'isolation',
      attach: 'boolean', oom_kill_disable: 'boolean'
    },
    healthcheck: { disable: 'boolean' },
    logging: { driver: 'logdriver' },
    networks: { driver: 'networkdriver', internal: 'boolean', attachable: 'boolean', external: 'boolean',
                enable_ipv6: 'boolean' },
    volumes:  { driver: 'volumedriver', external: 'boolean' },
    secrets:  { external: 'boolean' },
    configs:  { external: 'boolean' },
    deploy:         { mode: 'deploymode', endpoint_mode: 'endpointmode' },
    restartpolicy:  { condition: 'restartcondition' },
    updateconfig:   { order: 'updateorder', failure_action: 'failureaction' },
    loggingoptions: { mode: 'logmode' },
    // A long-form dependency's own condition: — previously unreachable from
    // the editor, since suggestionContext() had no branch for it either.
    depends: { condition: 'dependscondition' },
    build: {
      network: 'buildnetwork', isolation: 'isolation',
      no_cache: 'boolean', pull: 'boolean', privileged: 'boolean'
    }
  };

  // Where a key's value (or list item) draws its NAMES from — the file's own
  // services, or one of its four declared namespaces, or every profile named
  // anywhere in it — alongside VOCAB_AT's fixed vocabularies above. Keyed the
  // same way: a `where` bucket, then the key itself. `prefix` is set only for
  // network_mode/ipc/pid, whose value is "service:<name>", not the bare name.
  //
  // Two things this needs no extra work for:
  //  - "service:" needs no "closed head, open tail" mechanism: the caret's
  //    word is whitespace-delimited, so the whole string "service:db"
  //    prefix-matches a typed "service:" and replaces the whole word on
  //    accept.
  //  - A volume's host half only suggests while the value is still a bare
  //    word — "- appd|" offers "appdata", but once "appdata:/config" is on
  //    the line the typed word ("appdata:/config") prefix-matches no
  //    declared name and the list goes quiet. That is correct, not a gap.
  var REF_AT = {
    service: {
      depends_on:   { from: 'services' },
      networks:     { from: 'networks' },
      secrets:      { from: 'secrets' },
      configs:      { from: 'configs' },
      profiles:     { from: 'profiles' },
      volumes:      { from: 'volumes' },
      network_mode: { from: 'services', prefix: 'service:' },
      ipc:          { from: 'services', prefix: 'service:' },
      pid:          { from: 'services', prefix: 'service:' }
    },
    build: { secrets: { from: 'secrets' } }
  };

  // refAtFor(key, where) -> null | a REF_AT entry — mirrors vocabIdFor()
  // just below, and is looked up the same way.
  function refAtFor(key, where) {
    var bucket = REF_AT[where];
    return bucket && Object.prototype.hasOwnProperty.call(bucket, key) ? bucket[key] : null;
  }

  // Turns a scanned name list into [value, label] pairs, through refNames()'s
  // one shared rule below — the same rule fromChoice()/serviceModeOptions()
  // in stacks.js apply to the form's own dropdowns. The label is the value,
  // prefix and all, and never a description of it: a dropdown has to show
  // what the compose file will say, or nothing connects the two.
  function refOptions(names, info, serviceName) {
    var list = refNames(names, info.from, serviceName), out = [];
    for (var i = 0; i < list.length; i++) {
      var val = info.prefix ? info.prefix + list[i] : list[i];
      out.push([val, val]);
    }
    return out;
  }

  // vocabIdFor(key, where, path) -> null | a VOCAB id
  //
  // hasOwnProperty for the same reason vocab()/keyInfo() use it: 'declared'
  // is resolved to the declaration's own kind (path[0]) first, since VOCAB_AT
  // has no 'declared' bucket of its own — see the comment above it.
  function vocabIdFor(key, where, path) {
    var bucket = where === 'declared' ? VOCAB_AT[path[0]] : VOCAB_AT[where];
    if (!bucket || !Object.prototype.hasOwnProperty.call(bucket, key)) return null;
    return bucket[key];
  }

  // One entry per key this editor knows how to describe, grouped by the
  // vocabulary it belongs to (a "where" — see keyInfo()). Titles match
  // KEYS/LEAVES/DECL_LEAVES exactly where one of those tables already names
  // the key, so the editor and the form never disagree about what to call
  // something.
  var DESCRIPTIONS = {
    top: {
      services: { title: 'Services', description: "The containers this compose file defines, each one written under its own name inside this block." },
      networks: { title: 'Networks', description: "Declares shared networks the services in this file can join, beyond the default network compose creates automatically." },
      volumes:  { title: 'Volumes', description: "Declares named volumes, storage areas Docker manages on the server's disk, so several containers can share the same data." },
      configs:  { title: 'Configs', description: "Declares configuration files services can read, kept separately from the file so they are easy to reuse or replace." },
      secrets:  { title: 'Secrets', description: "Declares sensitive files, such as passwords or keys, that services can read without writing them into the compose file itself." },
      name:     { title: 'Project name', description: "Sets the project name for this compose file, used as a prefix for the containers and networks it creates." },
      include:  { title: 'Include', description: "Pulls in another compose file's services as if they were written here." },
      version:  { title: 'Version', description: "The old compose file format version marker; modern compose ignores it, and it survives only for older tools that still expect one." }
    },

    service: {
      annotations:         { title: 'Annotations', description: "Adds metadata to the container in the same key: value form as labels, but on the lower-level object Docker or Kubernetes reads." },
      attach:              { title: 'Attach', description: "Controls whether this service's output is shown when running docker compose up; set to false to hide a noisy service's console output." },
      blkio_config:        { title: 'Disk I/O limits', description: "Limits how fast this container can read from and write to disk, using Linux's block I/O (input/output) controls." },
      build:               { title: 'Build', description: "Builds the image from a Dockerfile — a folder path, or a block of build settings. Set Image too to name what the build produces." },
      cap_add:             { title: 'Extra permissions', description: "Grants Linux capabilities the container would not normally have, such as NET_ADMIN for managing network interfaces." },
      cap_drop:            { title: 'Dropped permissions', description: "Removes Linux capabilities the container would otherwise have, tightening what it is allowed to do." },
      cgroup:              { title: 'Cgroup namespace', description: "Chooses whether the container gets its own cgroup, the Linux mechanism that limits CPU and memory, or shares its parent's." },
      cgroup_parent:       { title: 'Cgroup parent', description: "Puts the container's cgroup (its resource-limiting group) under a specific parent group instead of the default one." },
      command:             { title: 'Command', description: "Overrides the command the image normally runs when the container starts." },
      configs:             { title: 'Configs', description: "Lists the configs, declared under the top-level configs: section, this service can read as files inside the container." },
      container_name:      { title: 'Container name', description: "Sets the exact name Docker gives the container, instead of letting compose generate one." },
      cpu_count:           { title: 'CPU count', description: "The number of CPUs the container can use (Windows containers only)." },
      cpu_percent:         { title: 'CPU percent', description: "The percentage of a single CPU core the container can use (Windows containers only)." },
      cpu_period:          { title: 'CPU period', description: "The length, in microseconds, of the CPU scheduling window used to work out cpu_quota." },
      cpu_quota:           { title: 'CPU quota', description: "How much CPU time the container gets within each cpu_period, in microseconds." },
      cpu_rt_period:       { title: 'Real-time CPU period', description: "The real-time CPU scheduling period, in microseconds, for containers that need guaranteed low-latency CPU access." },
      cpu_rt_runtime:      { title: 'Real-time CPU runtime', description: "How much of each real-time period the container is guaranteed, in microseconds." },
      cpu_shares:          { title: 'CPU shares', description: "The container's relative share of CPU time compared with other containers when the CPU is under heavy demand." },
      cpus:                { title: 'CPU limit', description: "The maximum number of CPU cores the container can use, e.g. 1.5 for one and a half cores." },
      cpuset:              { title: 'CPU set', description: "Restricts the container to specific CPU cores, e.g. 0,1 for the first two." },
      credential_spec:     { title: 'Credential spec', description: "Points at the credential file a Windows container uses to join an Active Directory domain." },
      depends_on:          { title: 'Starts after', description: "Lists other services that must start, or finish healthily, before this one starts." },
      deploy:              { title: 'Resource limits', description: "Caps and reserves how much CPU and memory this container may use, plus restart and scaling settings mostly used by Docker Swarm." },
      develop:             { title: 'Develop (watch)', description: "Settings for docker compose watch, which rebuilds or syncs the container automatically when files change during development." },
      device_cgroup_rules: { title: 'Device cgroup rules', description: "Custom rules controlling which host devices the container is allowed to access." },
      devices:             { title: 'Devices', description: "Gives the container direct access to a host device, such as a USB stick or a graphics card." },
      dns:                 { title: 'DNS servers', description: "Custom DNS (Domain Name System, which turns names like example.com into addresses) servers this container uses instead of the host's own." },
      dns_opt:             { title: 'DNS options', description: "Extra options passed to the container's DNS resolver." },
      dns_search:          { title: 'DNS search domains', description: "Extra domain names appended when this container looks up a short hostname." },
      domainname:          { title: 'Domain name', description: "Sets the domain name reported inside the container, e.g. example.com." },
      entrypoint:          { title: 'Entrypoint', description: "Overrides the program the image runs first when the container starts, before any command is added to it." },
      env_file:            { title: 'Variable files', description: "Loads environment variables from a file instead of, or as well as, writing them one by one." },
      environment:         { title: 'Environment variables', description: "Sets environment variables inside the container, e.g. PUID=99." },
      expose:              { title: 'Internal ports', description: "Opens a port to other containers on the same network, without publishing it to the outside network." },
      extends:             { title: 'Extends', description: "Reuses settings from another service defined elsewhere, adding to or overriding them here." },
      external_links:      { title: 'External links', description: "Connects this container to another one that compose does not manage, by name." },
      extra_hosts:         { title: 'Extra hosts', description: "Adds extra entries to the container's hosts file, mapping a hostname to an IP address by hand." },
      gpus:                { title: 'GPUs', description: "Requests access to the server's graphics card (GPU) for this container, e.g. for hardware-accelerated video or AI workloads." },
      group_add:           { title: 'Extra groups', description: "Adds the container's user to extra Linux groups inside the container, on top of its own." },
      healthcheck:         { title: 'Health check', description: "How compose checks that the container is actually working, not just running." },
      hostname:            { title: 'Hostname', description: "Sets the hostname reported inside the container." },
      image:               { title: 'Image', description: "The container image to run, e.g. linuxserver/jellyfin:latest." },
      init:                { title: 'Init process', description: "Runs a minimal init process as PID 1 inside the container, which cleans up properly when the main program exits." },
      ipc:                 { title: 'IPC mode', description: "Controls whether this container shares memory (IPC, inter-process communication) with the host or another container." },
      isolation:           { title: 'Isolation', description: "The container isolation technology to use (Windows only, e.g. hyperv)." },
      label_file:          { title: 'Label files', description: "Loads labels from a file instead of writing them one by one." },
      labels:              { title: 'Labels', description: "Attaches metadata to the container as key: value pairs, e.g. for a tool like Traefik to read." },
      links:               { title: 'Links', description: "An older way of connecting this container to another one by name; networks: is preferred now." },
      logging:             { title: 'Logging', description: "How the container's console output is stored and rotated." },
      mac_address:         { title: 'MAC address', description: "Sets a fixed MAC (Media Access Control, a network hardware address) for the container's network interface." },
      mem_limit:           { title: 'Memory limit', description: "The most memory this container is allowed to use." },
      mem_reservation:     { title: 'Memory reservation', description: "A soft memory limit the container is guaranteed to get before mem_limit is enforced." },
      mem_swappiness:      { title: 'Memory swappiness', description: "How eagerly the container's memory is swapped to disk, from 0 (hardly ever) to 100 (aggressively)." },
      memswap_limit:       { title: 'Memory + swap limit', description: "The combined memory-plus-swap limit for the container." },
      network_mode:        { title: 'Network mode', description: "Chooses how this container connects to the network, e.g. host to share the server's own network directly." },
      networks:            { title: 'Networks', description: "Which networks, declared under the top-level networks: section, this container joins." },
      oom_kill_disable:    { title: 'Disable OOM kill', description: "Stops Linux from killing this container first when the server runs out of memory (OOM, out of memory)." },
      oom_score_adj:       { title: 'OOM score adjustment', description: "Adjusts how likely this container is to be killed first when the server runs out of memory." },
      pid:                 { title: 'Process namespace', description: "Shares the process namespace with the host or another container, letting one see the other's running processes." },
      pids_limit:          { title: 'Process limit', description: "The most processes, or threads, this container is allowed to run at once." },
      platform:            { title: 'Platform', description: "The CPU architecture and operating system to run this container as, e.g. linux/arm64." },
      ports:               { title: 'Published ports', description: "Makes a port inside the container reachable from your network, written as outside:inside — 8080:80 puts the container's port 80 on port 8080 of the server." },
      post_start:          { title: 'After start', description: "A command to run once, right after the container starts." },
      pre_stop:            { title: 'Before stop', description: "A command to run once, just before the container stops." },
      privileged:          { title: 'Privileged', description: "Gives the container full access to the host, bypassing most of Docker's normal safety limits." },
      profiles:            { title: 'Profiles', description: "Marks this service as only starting when a matching profile is chosen, so one compose file can describe several optional setups." },
      provider:            { title: 'Provider', description: "Runs this service through an external provider plugin instead of as a normal container." },
      pull_policy:         { title: 'When to pull the image', description: "When to fetch the image from its registry — always, never, or only if it is missing." },
      pull_refresh_after:  { title: 'Pull refresh', description: "How long a pulled image is trusted before compose checks its registry for a newer one again." },
      read_only:           { title: 'Read-only filesystem', description: "Makes the container's own filesystem read-only, so nothing inside it can write to disk except through mounted volumes." },
      restart:             { title: 'Restart policy', description: "What to do if the container stops — for example always restart it, or only restart it if it failed." },
      runtime:             { title: 'Runtime', description: "The container runtime to use, e.g. nvidia for GPU-enabled containers." },
      scale:               { title: 'Scale', description: "How many copies of this container to run at once." },
      secrets:             { title: 'Secrets', description: "Which secrets, declared under the top-level secrets: section, this service can read as files inside the container." },
      security_opt:        { title: 'Security options', description: "Fine-grained Linux security settings, such as which security profile (AppArmor or SELinux) applies to the container." },
      shm_size:            { title: 'Shared memory size', description: "The size of /dev/shm, a shared-memory folder some programs use for temporary data." },
      stdin_open:          { title: 'Keep input open', description: "Keeps the container's standard input open, as if it were run interactively." },
      stop_grace_period:   { title: 'Stop grace period', description: "How long to wait for the container to stop cleanly before forcing it to stop." },
      stop_signal:         { title: 'Stop signal', description: "The signal sent to ask the container to stop, e.g. SIGTERM." },
      storage_opt:         { title: 'Storage options', description: "Storage driver options for the container's own filesystem." },
      sysctls:             { title: 'Kernel settings', description: "Kernel (the core of the operating system) settings applied inside the container." },
      tmpfs:               { title: 'Temporary filesystem', description: "Mounts a temporary, memory-backed folder inside the container that disappears when it stops." },
      tty:                 { title: 'Terminal (tty)', description: "Gives the container a terminal, as if it were run interactively." },
      ulimits:             { title: 'Resource ulimits', description: "Resource limits Linux enforces on the container, such as the most files it can open at once." },
      user:                { title: 'User', description: "The username or user ID the container's process runs as, instead of the image's default." },
      userns_mode:         { title: 'User namespace', description: "Controls whether the container gets its own separate range of user IDs from the host." },
      uts:                 { title: 'UTS namespace', description: "Controls whether the container shares the host's UTS namespace — its hostname and domain name." },
      volumes:             { title: 'Volumes', description: "Mounts a folder or file from the server into the container, written as host:container — /mnt/user/media:/data shares that folder with the container." },
      volumes_from:        { title: 'Volumes from', description: "Mounts all the same volumes another container already has." },
      working_dir:         { title: 'Working folder', description: "The folder inside the container that commands run from by default." }
    },

    healthcheck: {
      test:           { title: 'The check itself', description: "The command Docker runs inside the container to decide whether it is healthy, e.g. checking that a web server answers." },
      interval:       { title: 'Check every', description: "How often the health check runs once the container is up, e.g. every 30 seconds." },
      timeout:        { title: 'Give up after', description: "How long a single check is allowed to take before it counts as a failure." },
      retries:        { title: 'Failures allowed', description: "How many failed checks in a row before the container is marked unhealthy." },
      start_period:   { title: 'Grace period at start', description: "How long a slow-starting container is given before failed checks start counting against it." },
      start_interval: { title: 'Checking every, while starting', description: "How often the check runs during that start-up grace period, which can be shorter than the normal interval." },
      disable:        { title: 'Disabled', description: "Switches the health check off entirely, even if the image itself defines one." },
      // The form splits test: into these two boxes (see readTest()/writeTest())
      // rather than showing compose's own CMD-SHELL spelling, so their help
      // talks about what each box does instead.
      // Titles are inferTitle()'s own two strings, not variations on them —
      // these describe the two boxes the form splits test: into, and a
      // description headed differently from the label above it reads as being
      // about something else.
      'test.mode':    { title: 'How the check runs', description: "Chooses how the check is carried out — running a command inside the container, or switching the check off." },
      'test.command': { title: 'The check itself', description: "The command the health check runs; it counts as healthy when this command exits without an error." }
    },

    // deploy:'s own direct children (from DEPLOY_LEAVES) come first, offered
    // while typing inside deploy: itself. The four dotted paths below sit
    // several levels deeper, at LEAVES.deploy's own paths — they exist for
    // the form's fields, which are built at those deeper paths directly.
    // Nearly everything here is a Docker Swarm concept and says so, since it
    // mostly does nothing on the single-host setup Unraid actually runs.
    deploy: {
      mode:            { title: 'Replication mode', description: "How this service is scheduled — a set number of copies, or one on every swarm node (Docker Swarm only)." },
      replicas:        { title: 'Number of copies', description: "How many copies of this service to run when mode is replicated (Docker Swarm only)." },
      labels:          { title: 'Deployment labels', description: "Metadata attached to the service itself, rather than to the containers it creates, as key: value pairs (Docker Swarm only)." },
      endpoint_mode:   { title: 'Endpoint mode', description: "How other services reach this one — one shared address, or a DNS record for each copy (Docker Swarm only)." },
      placement:       { title: 'Placement', description: "Which nodes in the swarm this service is allowed to run on (Docker Swarm only)." },
      resources:       { title: 'Resource limits', description: "Caps and reserves how much CPU and memory this container is allowed to use." },
      restart_policy:  { title: 'Restart policy (swarm)', description: "When and how often Docker Swarm restarts a failed task — separate from the restart: setting above, which this replaces under deploy: (Docker Swarm only)." },
      rollback_config: { title: 'Rollback settings', description: "How to roll a service back to its previous version if an update goes wrong (Docker Swarm only)." },
      update_config:   { title: 'Update settings', description: "How a rolling update is carried out — how many copies at once, and what to do if one fails (Docker Swarm only)." },
      'resources.limits.cpus':         { title: 'CPU limit', description: "The most CPU this container may use — a hard cap it is never allowed to cross." },
      'resources.limits.memory':       { title: 'Memory limit', description: "The most memory this container may use — a hard cap it is never allowed to cross." },
      'resources.reservations.cpus':   { title: 'CPU reserved', description: "The CPU this container is guaranteed to get, set aside for it even when the server is busy." },
      'resources.reservations.memory': { title: 'Memory reserved', description: "The memory this container is guaranteed to get, set aside for it even when the server is busy." }
    },

    // deploy.restart_policy's own settings — a Docker Swarm concept distinct
    // from the service-level restart: policy above, which is why it needs
    // its own bucket rather than sharing 'deploy'.
    restartpolicy: {
      condition:    { title: 'Restart condition', description: "Which outcomes should trigger a restart — any exit, none, or only a failure (Docker Swarm only)." },
      delay:        { title: 'Restart delay', description: "How long to wait before restarting a failed task (Docker Swarm only)." },
      max_attempts: { title: 'Restart attempts', description: "How many times to retry restarting before giving up (Docker Swarm only)." },
      window:       { title: 'Restart window', description: "How long to wait before deciding whether a restart succeeded (Docker Swarm only)." }
    },

    // deploy.update_config and deploy.rollback_config share this bucket —
    // identical settings, for a rolling update and for undoing one.
    updateconfig: {
      parallelism:       { title: 'Update parallelism', description: "How many copies to update at once during a rolling update (Docker Swarm only)." },
      delay:             { title: 'Update delay', description: "How long to wait between updating each batch of copies (Docker Swarm only)." },
      failure_action:    { title: 'On update failure', description: "What to do if updating a copy fails — carry on, roll back, or pause (Docker Swarm only)." },
      monitor:           { title: 'Failure monitoring window', description: "How long to watch a newly updated copy for failure before moving on (Docker Swarm only)." },
      max_failure_ratio: { title: 'Failure tolerance', description: "The share of updated copies allowed to fail before the whole update is considered failed (Docker Swarm only)." },
      order:             { title: 'Update order', description: "Whether the new copy starts before the old one stops, or the old one stops first (Docker Swarm only)." }
    },

    logging: {
      driver: { title: 'Log driver', description: "Which logging driver stores the container's console output, e.g. json-file (the default) or a remote logging service." }
    },

    // logging.options is a free-form map — any option a driver understands
    // is legal — so this bucket describes the common ones as a hint, not a
    // complete list.
    loggingoptions: {
      mode:      { title: 'Blocking mode', description: "Whether the container waits for the log driver to keep up, or carries on and risks dropping log lines." },
      'max-size': { title: 'Max log file size', description: "The largest a single log file is allowed to grow before it is rotated." },
      'max-file': { title: 'Log files kept', description: "How many rotated log files to keep before the oldest is deleted." },
      tag:       { title: 'Log tag', description: "A custom tag added to each log line, useful for telling one container's logs apart from another's." },
      labels:    { title: 'Labels in logs', description: "Which of this container's labels to include alongside its log output." },
      env:       { title: 'Environment in logs', description: "Which of this container's environment variables to include alongside its log output." }
    },

    build: {
      context:             { title: 'Build context', description: "Where to find the files, a folder path or a Git URL, used to build the image." },
      dockerfile:          { title: 'Dockerfile path', description: "The name of the Dockerfile to use, when it is not simply called Dockerfile." },
      args:                { title: 'Build arguments', description: "Build-time variables passed into the Dockerfile, e.g. a version number baked into the image." },
      target:              { title: 'Build stage', description: "Builds only up to a named stage in a multi-stage Dockerfile, instead of the whole thing." },
      cache_from:          { title: 'Cache sources', description: "Extra image sources to reuse layers from, speeding up the build." },
      cache_to:            { title: 'Cache export', description: "Where to export this build's layers so a later build can reuse them." },
      network:             { title: 'Build network', description: "Which network the build process itself can use, e.g. to reach a private package server." },
      platforms:           { title: 'Platforms', description: "The CPU architectures this image can be built for, e.g. linux/amd64 and linux/arm64." },
      shm_size:            { title: 'Shared memory size', description: "The size of /dev/shm made available to the build process itself." },
      privileged:          { title: 'Privileged build', description: "Runs the build process with full access to the host, bypassing Docker's normal safety limits." },
      pull:                { title: 'Always pull base images', description: "Always fetches the latest version of any base image before building, rather than reusing a cached copy." },
      labels:              { title: 'Image labels', description: "Attaches metadata to the built image as key: value pairs." },
      secrets:             { title: 'Build secrets', description: "Makes secrets, declared under the top-level secrets: section, available to the build process without baking them into the image." },
      ssh:                 { title: 'SSH access', description: "Passes an SSH (Secure Shell, used to authenticate with private Git servers) key or agent into the build, for pulling private dependencies." },
      additional_contexts: { title: 'Extra build contexts', description: "Extra named sources of files the Dockerfile can pull from, alongside the main build context." },
      no_cache:            { title: 'Ignore build cache', description: "Rebuilds from scratch, ignoring any cached layers from a previous build." },
      isolation:           { title: 'Isolation', description: "The container isolation technology used while building (Windows only, e.g. hyperv)." },
      extra_hosts:         { title: 'Extra hosts', description: "Adds extra entries to the build process's hosts file, mapping a hostname to an IP address by hand." },
      tags:                { title: 'Extra image tags', description: "Extra names (tags) to give the built image, besides the one from image:." },
      ulimits:             { title: 'Build ulimits', description: "Resource limits enforced on the build process itself, such as the most files it can open at once." }
    },

    // One shared bucket for all four declaration kinds (networks, volumes,
    // secrets, configs) — a network's driver and a volume's driver mean the
    // same thing, so there is no reason to describe them twice. Titles reused
    // exactly from DECL_LEAVES, except networkRow/volumeRow: those two are
    // not compose keys at all but the whole row, whose one box answers
    // "already exists, or created here" rather than naming a driver, so
    // driver's own text no longer describes what the reader is looking at.
    declared: {
      driver:      { title: 'Driver', description: "Which plugin manages this network or volume — the default is fine for almost everyone, and only needs changing for a specialised storage or networking setup." },
      networkRow:  { title: 'Network', description: "This network, by name. Set the box beside it to external if the network already exists on the server and this file only joins it, to a driver name if compose should create it a particular way, or leave it blank for compose to create an ordinary network with that name." },
      volumeRow:   { title: 'Volume', description: "This volume, by name. Set the box beside it to external if the volume already exists on the server and this file only uses it, to a driver name if compose should create it a particular way, or leave it blank for compose to create an ordinary volume with that name." },
      internal:    { title: 'Internal only, no outside access', description: "Cuts this network off from the outside world, so containers on it can only talk to each other, not the internet." },
      external:    { title: 'Created outside this file', description: "Tells compose this network, volume, secret or config already exists, rather than asking it to create one." },
      name:        { title: 'Real name in Docker', description: "The real name this network or volume has in Docker, if it should differ from the name used inside this file." },
      attachable:  { title: 'Attachable', description: "Lets standalone containers, ones not managed by this compose file, join this network as well." },
      file:        { title: 'File on the server', description: "The path on the server to the file this secret or config reads its contents from." },
      environment: { title: 'From a variable', description: "Reads this secret's value from an environment variable instead of a file." },
      driver_opts: { title: 'Driver options', description: "Extra settings passed straight to the network or volume driver, specific to whichever one is in use." },
      enable_ipv6: { title: 'IPv6', description: "Turns on IPv6 addressing for this network, alongside the usual IPv4." },
      ipam:        { title: 'IP address management', description: "Controls how addresses are handed out on this network, such as a specific subnet, instead of leaving it to Docker." },
      labels:      { title: 'Labels', description: "Attaches metadata to this network or volume as key: value pairs." }
    },

    // depends_on's long form: a dependency's OWN settings (its condition,
    // and the two folded ones below it), not the service keys of the same
    // name — a dependency's 'restart' has nothing to do with the service-level
    // restart: policy above.
    depends: {
      condition: { title: 'When the dependency counts as ready', description: "What counts as ready before this service starts — the dependency simply running, or reporting healthy, or having finished." },
      restart:   { title: 'Restart this service too when the dependency restarts', description: "Restarts this service automatically whenever the dependency it relies on restarts." },
      required:  { title: 'This dependency must start successfully for this service to start', description: "Switch this off to let this service start even if the dependency fails — otherwise a failed dependency stops this service starting too." }
    }
  };

  /**
   * keyInfo(key, where) -> null | { title, description }
   *
   * `where` is one of 'top', 'service', 'healthcheck', 'deploy', 'logging',
   * 'build', 'declared' — the vocabulary the key is being read against, since
   * the same word can mean different things in different ones ('driver'
   * under logging is a log driver; under a network it is a network driver).
   */
  function keyInfo(key, where) {
    var table = DESCRIPTIONS[where];
    var entry = table && Object.prototype.hasOwnProperty.call(table, key) ? table[key] : null;
    return entry ? { title: entry.title, description: entry.description } : null;
  }

  // A dotted setting target such as 'deploy.mode' names a leaf several levels
  // under one of these four blocks; splits it into the DESCRIPTIONS bucket
  // ('deploy') and the key inside it ('mode'). Shared by fieldHelp() below
  // and inferTitle()'s dotted-target fallback, so there is exactly one place
  // that knows the four bucket names.
  function dottedBucket(target) {
    var m = /^(healthcheck|deploy|logging|build)\.(.+)$/.exec(target);
    return m ? { where: m[1], key: m[2] } : null;
  }

  /**
   * fieldHelp(field) -> null | { title, description }
   *
   * Maps one field the Form pane rendered (see buildForm()) back to its
   * DESCRIPTIONS entry. Never throws — a missing or malformed field is null,
   * the same answer as "nothing to say here", which is also the honest
   * answer for a field the Advanced catch-all built from a key the file
   * actually has but this editor does not otherwise recognise (a
   * misspelling such as portz:).
   */
  function fieldHelp(field) {
    try {
      if (!field) return null;
      var target = field.target;

      if (field.binder === 'setting') {
        if (typeof target !== 'string') return null;
        var db = dottedBucket(target);
        return db ? keyInfo(db.key, db.where) : keyInfo(target, 'service');
      }

      if (field.binder === 'declared') {
        // A fold row is one named setting; its LAST dotted segment is that
        // setting's key. A non-fold row's box holds the declaration's own
        // primary setting instead — its target is just "<kind>.<name>", and
        // a name can itself contain dots (a network called "br0.2"), so the
        // last segment there is part of the name, not a setting.
        if (field.fold) {
          var dsegs = String(target).split('.');
          return keyInfo(dsegs[dsegs.length - 1], 'declared');
        }
        if (field.declKind === 'networks') return keyInfo('networkRow', 'declared');
        if (field.declKind === 'volumes') return keyInfo('volumeRow', 'declared');
        var primary = DECL_PRIMARY[field.declKind];
        return primary ? keyInfo(primary, 'declared') : null;
      }

      if (field.binder === 'depends') {
        if (field.fold) {
          var fsegs = String(target).split('.');
          return keyInfo(fsegs[fsegs.length - 1], 'depends');
        }
        return keyInfo('condition', 'depends');
      }

      // Ports, volumes, variables, labels, devices and other list entries
      // get their help from the group heading, not per row.
      return null;
    } catch (e) {
      return null;
    }
  }

  // Every key one of this file's own tables names but DESCRIPTIONS cannot
  // describe — the guard that stops a key being added to KEYS/LEAVES/
  // DECL_LEAVES/DEPENDS_LEAVES without anyone writing its help sentence.
  // Exists only so a test can demand it comes back empty.
  function helpGaps() {
    var gaps = [];
    function check(key, where) { if (!keyInfo(key, where)) gaps.push({ key: key, where: where }); }

    for (var k in KEYS) { if (KEYS.hasOwnProperty(k)) check(k, 'service'); }
    for (var hk in LEAVES.healthcheck) { if (LEAVES.healthcheck.hasOwnProperty(hk)) check(hk, 'healthcheck'); }
    for (var lk in LEAVES.logging) { if (LEAVES.logging.hasOwnProperty(lk)) check(lk, 'logging'); }
    for (var dk in LEAVES.deploy) { if (LEAVES.deploy.hasOwnProperty(dk)) check(dk, 'deploy'); }
    for (var dlk in DEPLOY_LEAVES) { if (DEPLOY_LEAVES.hasOwnProperty(dlk)) check(dlk, 'deploy'); }
    for (var bk in BUILD_LEAVES) { if (BUILD_LEAVES.hasOwnProperty(bk)) check(bk, 'build'); }
    for (var kind in DECL_LEAVES) {
      if (!DECL_LEAVES.hasOwnProperty(kind)) continue;
      for (var ck in DECL_LEAVES[kind]) { if (DECL_LEAVES[kind].hasOwnProperty(ck)) check(ck, 'declared'); }
    }
    for (var kind2 in DECL_SPEC_KEYS) {
      if (!DECL_SPEC_KEYS.hasOwnProperty(kind2)) continue;
      var specKeys = DECL_SPEC_KEYS[kind2];
      for (var si = 0; si < specKeys.length; si++) check(specKeys[si], 'declared');
    }
    for (var pk in DEPENDS_LEAVES) { if (DEPENDS_LEAVES.hasOwnProperty(pk)) check(pk, 'depends'); }
    check('condition', 'depends');

    for (var ri = 0; ri < RESTART_POLICY_KEYS.length; ri++) check(RESTART_POLICY_KEYS[ri], 'restartpolicy');
    for (var ui = 0; ui < UPDATE_CONFIG_KEYS.length; ui++) check(UPDATE_CONFIG_KEYS[ui], 'updateconfig');
    for (var oi = 0; oi < LOGGING_OPTIONS_KEYS.length; oi++) check(LOGGING_OPTIONS_KEYS[oi], 'loggingoptions');

    return gaps;
  }

  // LEAVES/BUILD_LEAVES paths are dotted (deploy's are several levels below
  // deploy: itself), but the suggestion caret only ever sits at a DIRECT
  // child, so only the first segment of each path is ever offered.
  function leafTopKeys(table) {
    var seen = {}, out = [];
    for (var k in table) {
      if (!table.hasOwnProperty(k)) continue;
      var top = k.split('.')[0];
      if (!seen[top]) { seen[top] = true; out.push(top); }
    }
    return out;
  }

  // Matches a key's ancestry (a top-down array of key names, root first) to
  // one of the positions this editor understands, returning the keys it
  // offers there and which DESCRIPTIONS bucket describes them — or null for
  // anywhere else, including directly under 'services' (a name the user
  // invents, not a key this editor knows).
  //
  // `scan` is optional and carries fileNames()'s result — needed for exactly
  // one position, a long-form dependency's own key, since that key IS
  // another service's name and has no fixed vocabulary to offer instead.
  // keySuggestions() only computes it once this shape is already confirmed,
  // so an ordinary caret position never pays for a scan it does not need;
  // describeKeyAt() never passes one, so that path keeps returning null,
  // unchanged from before.
  function suggestionContext(path, scan) {
    if (path.length === 0) return { keys: TOP_SPEC_KEYS, where: 'top' };

    if (path[0] === 'services') {
      if (path.length === 2) return { keys: SERVICE_SPEC_KEYS, where: 'service' };
      if (path.length === 3) {
        var w = path[2];
        if (w === 'healthcheck' || w === 'logging') return { keys: leafTopKeys(LEAVES[w]), where: w };
        if (w === 'deploy') return { keys: leafTopKeys(DEPLOY_LEAVES), where: 'deploy' };
        if (w === 'build') return { keys: leafTopKeys(BUILD_LEAVES), where: 'build' };
        if (w === 'depends_on' && scan) {
          return { keys: refNames(scan.services, 'services', path[1]), where: 'services' };
        }
      }
      // Two levels deeper than the block above: deploy's own sub-blocks,
      // logging's free-form options map (a hint, not a closed list — see the
      // comment above LOGGING_OPTIONS_KEYS), and a long-form dependency's own
      // settings (condition/restart/required) — a closed set, so no scan is
      // needed here even though the block above it needed one.
      if (path.length === 4) {
        if (path[2] === 'deploy' && path[3] === 'restart_policy') {
          return { keys: RESTART_POLICY_KEYS, where: 'restartpolicy' };
        }
        if (path[2] === 'deploy' && (path[3] === 'update_config' || path[3] === 'rollback_config')) {
          return { keys: UPDATE_CONFIG_KEYS, where: 'updateconfig' };
        }
        if (path[2] === 'logging' && path[3] === 'options') {
          return { keys: LOGGING_OPTIONS_KEYS, where: 'loggingoptions' };
        }
        if (path[2] === 'depends_on') {
          return { keys: DEPENDS_KEYS, where: 'depends' };
        }
      }
      return null;
    }

    if (path.length === 2 && DECL_SPEC_KEYS[path[0]]) {
      return { keys: DECL_SPEC_KEYS[path[0]], where: 'declared' };
    }
    return null;
  }

  function classifyAll(lines) {
    var cls = [];
    for (var i = 0; i < lines.length; i++) cls.push(classify(lines[i], i));
    return cls;
  }

  // From the caret's own indent, walks up to the nearest line above with a
  // smaller indent, records it if it is a key, and repeats — rebuilding the
  // path from the root without ever needing a parsed tree. Returns null the
  // moment a smaller indent turns up that is NOT a key: something the walk
  // cannot make sense of, so neither can the caller.
  function keyPathAbove(cls, lineIdx, indent) {
    var path = [], curIndent = indent, i = lineIdx - 1;
    while (i >= 0 && curIndent > 0) {
      var c = cls[i];
      if (c.kind === 'blank' || c.kind === 'comment') { i--; continue; }
      if (c.indent >= curIndent) { i--; continue; }        // a sibling, or a sibling's child
      if (c.kind !== 'key') return null;
      path.unshift(c.key);
      curIndent = c.indent;
      i--;
    }
    return path;
  }

  // The keys already written in the same block, at the same indent as the
  // caret's own line — offering one of these again would offer a mapping key
  // that already exists. The caret's own line is excluded, so retyping an
  // existing key's own name never suggests that name is somehow taken.
  function siblingKeysOf(cls, lineIdx, indent) {
    var keys = {}, i, c;
    for (i = lineIdx - 1; i >= 0; i--) {
      c = cls[i];
      if (c.kind === 'blank' || c.kind === 'comment') continue;
      if (c.indent < indent) break;
      if (c.indent === indent && c.kind === 'key') keys[c.key] = true;
    }
    for (i = lineIdx + 1; i < cls.length; i++) {
      c = cls[i];
      if (c.kind === 'blank' || c.kind === 'comment') continue;
      if (c.indent < indent) break;
      if (c.indent === indent && c.kind === 'key') keys[c.key] = true;
    }
    return keys;
  }

  // Is `col` sitting in the key position of this line — after the leading
  // spaces, before any ':' on it? Returns the partial word's bounds and the
  // line's indent, or null. A line with no colon at all is read as a key
  // with nothing typed after it yet, which is what "before any :" means when
  // there is none.
  function keyPositionOnLine(line, c, col) {
    if (c.kind === 'comment' || c.kind === 'seq') return null;

    var indent = c.indent, limit;
    if (c.kind === 'key') {
      limit = indent + c.keyRaw.length;                     // the colon sits right here
    } else if (c.kind === 'blank' || c.kind === 'other') {
      if (line.slice(indent).indexOf(':') >= 0) return null; // a colon we could not read as a key line
      limit = line.length;
    } else {
      return null;
    }
    if (col < indent || col > limit) return null;

    var s = col;
    while (s > indent && line.charAt(s - 1) !== ' ' && line.charAt(s - 1) !== '\t') s--;
    var e = col;
    while (e < limit && line.charAt(e) !== ' ' && line.charAt(e) !== '\t') e++;

    return { indent: indent, start: s, end: e, prefix: line.slice(s, e) };
  }

  // Orders and filters the vocabulary for one position: prefix matches
  // first, then the rest that merely contain it, alphabetical within each
  // group, and never a key the block already has.
  function suggestionList(keys, where, prefix, exclude) {
    var pl = prefix.toLowerCase(), starts = [], contains = [];
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      if (exclude[k]) continue;
      var info = keyInfo(k, where);
      var entry = { key: k, title: info ? info.title : k };
      var at = k.toLowerCase().indexOf(pl);
      if (at === 0) starts.push(entry); else if (at > 0) contains.push(entry);
    }
    function byKey(a, b) { return a.key < b.key ? -1 : a.key > b.key ? 1 : 0; }
    starts.sort(byKey);
    contains.sort(byKey);
    return starts.concat(contains);
  }

  /**
   * keySuggestions(text, offset) -> null | { start, end, prefix, keys }
   *
   * `offset` is a character offset into `text` (the caret). Works from the
   * raw text alone — see the section comment above for why parse() is never
   * called here. Never throws: malformed input comes back as null, the same
   * answer as "nothing to suggest here".
   */
  function keySuggestions(text, offset) {
    try {
      text = String(text == null ? '' : text);
      if (typeof offset !== 'number' || isNaN(offset) || offset < 0) return null;

      var lines = text.split('\n');
      var cls = classifyAll(lines);

      var starts = [], off = 0, i;
      for (i = 0; i < lines.length; i++) { starts.push(off); off += lines[i].length + 1; }
      var lineIdx = lineAtOffset({ lineStart: starts }, offset);
      var lineStartOff = starts[lineIdx];
      var col = offset - lineStartOff;

      var pos = keyPositionOnLine(lines[lineIdx], cls[lineIdx], col);
      if (!pos) return null;

      var path = keyPathAbove(cls, lineIdx, pos.indent);
      if (!path) return null;
      var ctx = suggestionContext(path);
      // A long-form dependency's own key is another service's name, not a
      // fixed vocabulary — the one shape that needs a scan, and only once
      // the shape itself is already confirmed, so the scan stays lazy.
      if (!ctx && path.length === 3 && path[0] === 'services' && path[2] === 'depends_on') {
        ctx = suggestionContext(path, fileNames(text));
      }
      if (!ctx) return null;

      var exclude = siblingKeysOf(cls, lineIdx, pos.indent);
      var keys = suggestionList(ctx.keys, ctx.where, pos.prefix, exclude);

      return { start: lineStartOff + pos.start, end: lineStartOff + pos.end, prefix: pos.prefix, keys: keys };
    } catch (e) {
      return null;
    }
  }

  // The value's own span on a line, from `start` (already sitting on the
  // first non-space character — just past "key: " or just past "- ") to
  // wherever a trailing comment begins. Mirrors splitComment(), but a '#'
  // sitting AT `start` itself ("key: # note", "- # note") is still a comment
  // even though nothing in the sliced text says so — the space that made it
  // one is the space already skipped to reach `start`, so splitComment()
  // never gets to see it. Caught here instead, once, rather than at both
  // call sites below.
  function valueRegion(line, start) {
    if (start < line.length && line.charAt(start) === '#') return { start: start, end: start };
    var split = splitComment(line.slice(start));
    return { start: start, end: start + split.value.length };
  }

  // Is the caret sitting inside an unterminated ${...} in `text` (the value
  // up to the caret)? Any compose value may be written ${VAR} or
  // ${VAR:-default}, and popping a list of restart policies over someone
  // typing a variable name is the feature getting in the way rather than
  // helping.
  function caretInInterpolation(text) {
    var open = text.lastIndexOf('${');
    return open >= 0 && text.indexOf('}', open) < 0;
  }

  // Orders and filters a vocabulary's [value, label] pairs for one caret
  // position: prefix matches first, then values that merely contain the
  // typed text — but, unlike suggestionList() above, NEVER re-sorted within
  // a group. restart's four values and stop_signal's seven are in a
  // deliberate order (commonest and safest first), the same order the form's
  // dropdowns show them in, and alphabetising here would leave the editor
  // and the form disagreeing about the order of the same list.
  function vocabSuggestionList(list, prefix) {
    var pl = prefix.toLowerCase(), starts = [], contains = [];
    for (var i = 0; i < list.length; i++) {
      var val = list[i][0], label = list[i][1];
      var at = val.toLowerCase().indexOf(pl);
      var entry = { key: val, title: label };
      if (at === 0) starts.push(entry); else if (at > 0) contains.push(entry);
    }
    return starts.concat(contains);
  }

  /**
   * valueSuggestions(text, offset) -> null | { start, end, prefix, keys, value: true }
   *
   * The mirror image of keySuggestions() above, for the value half of a
   * key: value line, or an item in a `- ` list whose parent key carries a
   * vocabulary (cap_add, cap_drop, ...). Returns the exact same shape
   * keySuggestions() does, so the suggestion panel, its keyboard handling,
   * positioning and clipping all work unchanged — `value: true` is the only
   * addition, there only so the caller knows not to append ': ' on accept
   * the way it does for a key.
   *
   * Never calls parse(), for the reason given in the section comment above.
   * Never throws: malformed input comes back as null.
   */
  function valueSuggestions(text, offset) {
    try {
      text = String(text == null ? '' : text);
      if (typeof offset !== 'number' || isNaN(offset) || offset < 0) return null;

      var lines = text.split('\n');
      var cls = classifyAll(lines);

      var starts = [], off = 0, i;
      for (i = 0; i < lines.length; i++) { starts.push(off); off += lines[i].length + 1; }
      var lineIdx = lineAtOffset({ lineStart: starts }, offset);
      var lineStartOff = starts[lineIdx];
      var col = offset - lineStartOff;
      var line = lines[lineIdx];
      var c = cls[lineIdx];

      var region, path, key;

      if (c.kind === 'key') {
        // The colon sits right after the key (see keyPositionOnLine's own
        // comment on the same arithmetic). YAML needs a space after it, so
        // "restart:|" with no space is not a value position at all — typing
        // there would write "restart:always", a plain scalar string, not a
        // key: value pair.
        var colonPos = c.indent + c.keyRaw.length;
        if (line.charAt(colonPos + 1) !== ' ' && line.charAt(colonPos + 1) !== '\t') return null;
        var vs = colonPos + 1;
        while (vs < line.length && (line.charAt(vs) === ' ' || line.charAt(vs) === '\t')) vs++;

        region = valueRegion(line, vs);
        if (col < region.start || col > region.end) return null;

        path = keyPathAbove(cls, lineIdx, c.indent);
        if (!path) return null;
        key = c.key;
      } else if (c.kind === 'seq') {
        // A long form's own keys ("- target: 8080") are not the parent
        // list's values — out of scope for this phase.
        if (c.sub && c.sub.kind === 'key') return null;

        region = valueRegion(line, c.contentCol);
        if (col < region.start || col > region.end) return null;

        // keyPathAbove() walked from the item's own indent ends WITH the key
        // whose list this item belongs to (cap_add, say) — the containing
        // context is that path with the key itself lopped off the end.
        var fullPath = keyPathAbove(cls, lineIdx, c.indent);
        if (!fullPath || fullPath.length === 0) return null;
        key = fullPath[fullPath.length - 1];
        path = fullPath.slice(0, -1);
      } else {
        return null;
      }

      if (caretInInterpolation(line.slice(region.start, col))) return null;

      var ctx = suggestionContext(path);
      if (!ctx) return null;

      // Resolved ALONGSIDE the static vocabulary lookup, not after it: a key
      // with no VOCAB_AT entry (depends_on, service networks/secrets/...)
      // would otherwise hit the old "return null" below before this ever
      // ran. Static vocabulary first, then names, matching choiceFor()'s own
      // order in the form. The scan itself only runs once a position that
      // actually wants names has been confirmed (REF_AT has an entry) — see
      // the section comment above hostPaths() for why this never parse()s.
      var vocabId = vocabIdFor(key, ctx.where, path);
      var list = vocabId ? vocab(vocabId) : null;
      var refInfo = refAtFor(key, ctx.where);
      if (refInfo) {
        var scanned = fileNames(text)[refInfo.from];
        list = (list || []).concat(refOptions(scanned, refInfo, path[1]));
      }
      if (!list) return null;

      // The whitespace-delimited word under the caret, bounded to the
      // value's own span — the same widening keyPositionOnLine() does for a
      // key, just bounded by the value's start/end instead of the line's.
      var s = col; while (s > region.start && line.charAt(s - 1) !== ' ' && line.charAt(s - 1) !== '\t') s--;
      var e = col; while (e < region.end && line.charAt(e) !== ' ' && line.charAt(e) !== '\t') e++;
      var prefix = line.slice(s, e);

      var matches = vocabSuggestionList(list, prefix);
      // A single match identical to what is already typed is the thing
      // already written, popping back up over the next line — not a
      // suggestion.
      if (matches.length === 1 && matches[0].key.toLowerCase() === prefix.toLowerCase()) return null;

      return { start: lineStartOff + s, end: lineStartOff + e, prefix: prefix, keys: matches, value: true };
    } catch (e) {
      return null;
    }
  }

  /**
   * describeKeyAt(text, line, col) -> null | { key, title, description }
   *
   * `line` and `col` are 0-based. Exported as API.keyAt (see Exports below);
   * named differently in here because the parser already has its own
   * internal keyAt(ctx, i, col, allowSub) with a different job.
   */
  function describeKeyAt(text, line, col) {
    try {
      text = String(text == null ? '' : text);
      var lines = text.split('\n');
      if (line < 0 || line >= lines.length) return null;

      var cls = classifyAll(lines);
      var c = cls[line];
      if (c.kind !== 'key' || col < c.indent || col >= c.indent + c.keyRaw.length) return null;

      var path = keyPathAbove(cls, line, c.indent);
      if (!path) return null;
      var ctx = suggestionContext(path);
      if (!ctx) return null;

      var info = keyInfo(c.key, ctx.where);
      return info ? { key: c.key, title: info.title, description: info.description } : null;
    } catch (e) {
      return null;
    }
  }

  /* =====================================================================
   * Host paths
   *
   * API.hostPaths — every host-side path of a volume mount, for a check
   * that the folder actually exists on the server. Works from classify()
   * alone, the same way highlight() and keySuggestions() above do: this has
   * to run on a file mid-edit, which is exactly when parse() has the least
   * to say. A stack of the mapping keys enclosing the current line rebuilds
   * "services -> <name> -> volumes" without ever building a tree, so a
   * top-level volumes: block (named-volume DECLARATIONS, not mounts) never
   * reaches this — its entries are mapping keys, not sequence items, and
   * are never even looked at.
   * ===================================================================== */

  // The text of a scalar starting at `col` on `line`, quote-stripped, plus
  // where that text starts and how long it is IN THE SOURCE — so a caller
  // can box exactly what is on screen even though the reported value has
  // had its quotes removed. Returns null for anything scanValue() would
  // also seal (an unterminated quote, an escape whose offsets would no
  // longer line up with the raw text): one unreadable entry is skipped,
  // not the whole file.
  function scanEntryText(line, col) {
    var head = line.charAt(col);
    if (head === '"' || head === "'") {
      var i, end = -1;
      if (head === '"') {
        for (i = col + 1; i < line.length; i++) {
          if (line.charAt(i) === '\\') { i++; continue; }
          if (line.charAt(i) === '"') { end = i; break; }
        }
      } else {
        for (i = col + 1; i < line.length; i++) {
          if (line.charAt(i) === "'") {
            if (line.charAt(i + 1) === "'") { i++; continue; }
            end = i; break;
          }
        }
      }
      if (end < 0) return null;
      var inner = line.slice(col + 1, end);
      if (head === '"' && inner.indexOf('\\') >= 0) return null;
      if (head === "'" && inner.indexOf("''") >= 0) return null;
      return { text: inner, col: col + 1 };
    }
    var split = splitComment(line.slice(col));
    return { text: split.value, col: col };
  }

  // Absolute (/mnt/...) or relative (./data) — the two shapes that resolve
  // to a real folder. Anything else — a bare name (appdata), a ${...}
  // reference compose only fills in at run time — is either a named volume
  // or unresolvable, and neither is a path this can go and check.
  function isHostPathLike(s) {
    if (s.indexOf('${') >= 0) return false;
    return s.charAt(0) === '/' || s.charAt(0) === '.';
  }

  // Reads one long-form volume item ({type:, source:, target:, ...}) that
  // starts at line i, whose dash-line classification is c (c.sub is the
  // mapping key beside the dash). Walks past every field at a deeper indent
  // so type: is seen even when it follows source: in the file, and returns
  // the index of the line after the item so the caller can skip straight
  // past it. Shared by hostPaths() and fileRefs(), which both need this and
  // nothing more from a long-form entry.
  function readVolumeItem(lines, i, c) {
    var itemIndent = c.indent, type = null, source = null, j = i;
    while (j < lines.length) {
      var cj = j === i ? c.sub : classify(lines[j], j);
      if (cj.kind === 'blank' || cj.kind === 'comment') { j++; continue; }
      if (j !== i && cj.indent <= itemIndent) break;
      if (cj.kind === 'key' && cj.valueCol >= 0) {
        var sc = scanEntryText(lines[j], cj.valueCol);
        if (sc && cj.key === 'type') type = sc.text;
        if (sc && cj.key === 'source') source = { text: sc.text, line: j, col: sc.col };
      }
      j++;
    }
    return { type: type, source: source, next: j };
  }

  /**
   * hostPaths(text) -> [{path, line, col, len}]
   *
   * Every host-side path of a volume mount under a service — not a
   * top-level volumes: declaration, which names a Docker-managed volume
   * rather than a folder on disk. `line`/`col` are 0-based; `col` points
   * inside any surrounding quotes. Ordered by line then column. Never
   * throws: anything this cannot make sense of is simply left out.
   */
  function hostPaths(text) {
    var out = [];
    try {
      text = String(text == null ? '' : text);
      var lines = text.split('\n');
      var stack = [];    // ancestor mapping keys enclosing the current line

      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        var c = classify(line, i);
        if (c.kind === 'blank' || c.kind === 'comment') continue;

        // Pop ancestors this line is no longer inside. A sequence item is
        // allowed to sit at the SAME indent as its own key (compose accepts
        // both), so only a sibling KEY at that indent replaces the key —
        // the item itself must not pop it off.
        while (stack.length && stack[stack.length - 1].indent > c.indent) stack.pop();
        if (stack.length && stack[stack.length - 1].indent === c.indent && c.kind === 'key') stack.pop();

        if (c.kind === 'key') { stack.push({ indent: c.indent, key: c.key }); continue; }
        if (c.kind !== 'seq' || !c.sub) continue;

        var inServiceVolumes = stack.length >= 3 &&
          stack[stack.length - 1].key === 'volumes' &&
          stack[stack.length - 3].key === 'services';
        if (!inServiceVolumes) continue;

        if (c.sub.kind === 'key') {
          // Long form over several lines: {type:, source:, target:, ...}.
          // Reading it is not folded into the ancestor stack above, which
          // exists to find volumes: blocks and has no business tracking a
          // mount's own keys — see readVolumeItem().
          var r = readVolumeItem(lines, i, c);
          if (r.source && (r.type === null || r.type === 'bind') && isHostPathLike(r.source.text)) {
            out.push({ path: r.source.text, line: r.source.line, col: r.source.col, len: r.source.text.length });
          }
          i = r.next - 1;
          continue;
        }

        // Short form: "HOST:CONTAINER[:MODE]", possibly quoted whole.
        var scanned = scanEntryText(line, c.contentCol);
        if (!scanned) continue;
        var bits = splitOutsideVars(scanned.text);
        if (bits.length < 2) continue;               // a bare container path: no host side
        var host = bits[0];
        if (!isHostPathLike(host)) continue;          // a named volume, not a path
        out.push({ path: host, line: i, col: scanned.col, len: host.length });
      }
    } catch (e) {
      return [];
    }
    out.sort(function (a, b) { return a.line - b.line || a.col - b.col; });
    return out;
  }

  /* =====================================================================
   * The file's own names
   *
   * API.fileNames — every service, declared network/volume/secret/config,
   * and profile name the file itself writes, so the editor's autocomplete
   * can offer them alongside the fixed vocabulary — see REF_AT above and
   * valueSuggestions()/keySuggestions() below. Built from classify() alone,
   * the same reason hostPaths() just above gives: a suggestion is wanted
   * exactly when the file is mid-edit, which is exactly when parse() has
   * the least to say.
   * ===================================================================== */

  // The four top-level declaration blocks, and which fileNames() bucket each
  // one fills — a service's OWN networks:/secrets:/configs: never reaches
  // this, since its key sits three deep under services: rather than one
  // deep at the root, the same depth check hostPaths() uses to tell a
  // top-level volumes: block apart from a service's mount list.
  var DECL_BUCKET = { networks: 'networks', volumes: 'volumes', secrets: 'secrets', configs: 'configs' };

  /**
   * fileNames(text) -> {services, networks, volumes, secrets, configs, profiles}
   *
   * Each value is a plain array of names, deduplicated and in file order.
   * Never throws: anything this cannot make sense of is simply left out.
   */
  function fileNames(text) {
    var out = { services: [], networks: [], volumes: [], secrets: [], configs: [], profiles: [] };
    try {
      text = String(text == null ? '' : text);
      var lines = text.split('\n');
      var stack = [];    // ancestor mapping keys enclosing the current line
      var seen = { services: {}, networks: {}, volumes: {}, secrets: {}, configs: {}, profiles: {} };

      // hasOwnProperty, not a plain lookup, for the reason vocab()/keyInfo()
      // above use it too: a service or volume called 'constructor' inherits a
      // truthy value from Object and would be dropped silently, and a
      // DECL_BUCKET miss on the same name would reach push() on nothing.
      function has(obj, k) { return Object.prototype.hasOwnProperty.call(obj, k); }

      function add(bucket, name) {
        if (name === '' || has(seen[bucket], name)) return;
        seen[bucket][name] = true;
        out[bucket].push(name);
      }

      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        var c = classify(line, i);
        if (c.kind === 'blank' || c.kind === 'comment') continue;

        // Pop ancestors this line is no longer inside. A sequence item is
        // allowed to sit at the SAME indent as its own key (compose accepts
        // both), so only a sibling KEY at that indent replaces the key —
        // the item itself must not pop it off.
        while (stack.length && stack[stack.length - 1].indent > c.indent) stack.pop();
        if (stack.length && stack[stack.length - 1].indent === c.indent && c.kind === 'key') stack.pop();

        if (c.kind === 'key') {
          // A service name: a mapping key whose ancestor stack is exactly
          // ['services']. A declared name: a mapping key whose ancestor
          // stack is exactly one of the four top-level blocks.
          if (stack.length === 1 && stack[0].key === 'services') add('services', c.key);
          if (stack.length === 1 && has(DECL_BUCKET, stack[0].key)) add(DECL_BUCKET[stack[0].key], c.key);
          stack.push({ indent: c.indent, key: c.key });
          continue;
        }
        if (c.kind !== 'seq' || !c.sub) continue;

        // A profile name: a plain scalar item under services -> <name> ->
        // profiles. c.sub.kind === 'key' means the item is a block, not a
        // name, so it is skipped the same way a long-form volume item is
        // skipped by the check just below hostPaths()'s own equivalent.
        var inProfiles = stack.length >= 3 &&
          stack[stack.length - 1].key === 'profiles' &&
          stack[stack.length - 3].key === 'services';
        if (!inProfiles || c.sub.kind === 'key') continue;

        var scanned = scanEntryText(line, c.contentCol);
        if (scanned) add('profiles', scanned.text);
      }
    } catch (e) {
      return { services: [], networks: [], volumes: [], secrets: [], configs: [], profiles: [] };
    }
    return out;
  }

  /**
   * refNames(names, kind, serviceName) -> string[]
   *
   * The one rule for offering a name from the file's own namespaces, shared
   * by the form (fromChoice()/serviceModeOptions() in stacks.js) and the
   * editor (refOptions() above): `default` is always offered as a network,
   * declared or not, because compose creates it regardless; and a service is
   * never offered as its own dependency or namespace-mate.
   */
  function refNames(names, kind, serviceName) {
    var out = (names || []).slice();
    if (kind === 'networks' && out.indexOf('default') < 0) out.push('default');
    if (kind === 'services') out = out.filter(function (n) { return n !== serviceName; });
    return out;
  }

  /* =====================================================================
   * File references
   *
   * API.fileRefs — every place a compose file names a file that is meant to
   * sit beside it in the stack's own folder: a service's env_file, a build
   * context or Dockerfile, a bind-mounted volume's host side, and a
   * top-level secrets:/configs: entry's file:. Built the same way as
   * hostPaths() just above — classify() and an ancestor-key stack, never
   * parse() — because this has to survive running on a file mid-edit too.
   *
   * Only relative references are reported; an absolute path lives somewhere
   * else on the server and is not this stack's folder to carry along. A
   * leading "./" is stripped so "./x" and "x" come back as the one file.
   * ===================================================================== */

  // Quote-strip and relativise one scalar. Returns null for an absolute
  // path — this exists to find files the stack's own folder holds, not
  // anything else on the server.
  function relFile(s) {
    if (s == null || s === '' || s.charAt(0) === '/') return null;
    var rel = s.slice(0, 2) === './' ? s.slice(2) : s;
    // "env_file:" with nothing after it, and a bare "./", both land here.
    // Neither names a file, and an empty name would match the compose tab's
    // own empty data-file and claim the compose file was referenced.
    return rel === '' ? null : rel;
  }

  function pushFileRef(out, raw, service, where) {
    var rel = relFile(raw);
    if (rel !== null) out.push({ file: rel, service: service, where: where });
  }

  /**
   * fileRefs(text) -> [{file, service, where}]
   *
   * `where` is one of 'env_file', 'secret', 'config', 'build', 'volume',
   * 'include'. `service` is the owning service, or '' for a top-level
   * secrets:/configs:/include: entry, which names no service. Never throws:
   * anything this cannot make sense of is simply left out.
   */
  function fileRefs(text) {
    var out = [];
    try {
      text = String(text == null ? '' : text);
      var lines = text.split('\n');
      var stack = [];    // ancestor mapping keys enclosing the current line

      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        var c = classify(line, i);
        if (c.kind === 'blank' || c.kind === 'comment') continue;

        while (stack.length && stack[stack.length - 1].indent > c.indent) stack.pop();
        if (stack.length && stack[stack.length - 1].indent === c.indent && c.kind === 'key') stack.pop();

        // services -> <name> is always stack[0]/stack[1] once inside it,
        // since every other top-level key (volumes:, secrets:, ...) pops
        // "services" off the moment its own indent-0 key is reached.
        var svc = (stack.length >= 2 && stack[0].key === 'services') ? stack[1].key : null;

        if (c.kind === 'key') {
          // A service's env_file: written as a single scalar, not a list.
          if (svc !== null && stack.length === 2 && c.key === 'env_file' && c.valueCol >= 0) {
            var sc1 = scanEntryText(line, c.valueCol);
            if (sc1) pushFileRef(out, sc1.text, svc, 'env_file');
          }
          // build:'s own context:/dockerfile: keys — long form only. A bare
          // "build: ." names a directory, not a file, so the short form is
          // left out rather than reported as one.
          if (svc !== null && stack.length === 3 && stack[2].key === 'build' &&
              (c.key === 'context' || c.key === 'dockerfile') && c.valueCol >= 0) {
            var sc2 = scanEntryText(line, c.valueCol);
            if (sc2) pushFileRef(out, sc2.text, svc, 'build');
          }
          // Top-level secrets:/configs: <name>: file: — never seen under
          // services, so this names no service.
          if (stack.length === 2 && (stack[0].key === 'secrets' || stack[0].key === 'configs') &&
              c.key === 'file' && c.valueCol >= 0) {
            var sc3 = scanEntryText(line, c.valueCol);
            if (sc3) pushFileRef(out, sc3.text, '', stack[0].key === 'secrets' ? 'secret' : 'config');
          }
          // Top-level include:, written as a single scalar. Names no service —
          // an include sits above the services: block, not inside one.
          if (stack.length === 0 && c.key === 'include' && c.valueCol >= 0) {
            var sc4 = scanEntryText(line, c.valueCol);
            if (sc4) pushFileRef(out, sc4.text, '', 'include');
          }
          stack.push({ indent: c.indent, key: c.key });
          continue;
        }

        if (c.kind !== 'seq' || !c.sub) continue;

        // Top-level include:, written as a list. Each entry can also be a map
        // (- path: other.yaml, with extra keys) — left unread rather than
        // guessed at, same as env_file's own long form just below.
        if (stack.length === 1 && stack[0].key === 'include') {
          if (c.sub.kind !== 'key') {
            var inc = scanEntryText(line, c.contentCol);
            if (inc) pushFileRef(out, inc.text, '', 'include');
          }
          continue;
        }

        // A service's env_file: written as a list. The compose spec also
        // allows a long form here (- path: .env, required: false); that is
        // deliberately left unread rather than guessed at.
        if (svc !== null && stack.length === 3 && stack[2].key === 'env_file') {
          if (c.sub.kind !== 'key') {
            var ef = scanEntryText(line, c.contentCol);
            if (ef) pushFileRef(out, ef.text, svc, 'env_file');
          }
          continue;
        }

        // A service's volumes: list — the same host-side extraction as
        // hostPaths() above, restricted to a relative path and attributed
        // to the enclosing service.
        if (svc !== null && stack.length === 3 && stack[2].key === 'volumes') {
          if (c.sub.kind === 'key') {
            var r = readVolumeItem(lines, i, c);
            if (r.source && (r.type === null || r.type === 'bind') && isHostPathLike(r.source.text)) {
              pushFileRef(out, r.source.text, svc, 'volume');
            }
            i = r.next - 1;
            continue;
          }
          var scanned = scanEntryText(line, c.contentCol);
          if (scanned) {
            var bits = splitOutsideVars(scanned.text);
            if (bits.length >= 2 && isHostPathLike(bits[0])) pushFileRef(out, bits[0], svc, 'volume');
          }
        }
      }
    } catch (e) {
      return [];
    }
    return out;
  }

  /* =====================================================================
   * Variable references
   *
   * API.varRefs — every ${NAME}/$NAME placeholder a compose file uses, so
   * the editor can flag one nothing will ever fill in. A variable is a
   * variable wherever it sits on the line, so unlike hostPaths()/fileRefs()
   * above this needs no ancestor-key stack — just a left-to-right walk over
   * the raw text, which is also why a placeholder inside a quoted string is
   * still picked up: going through the parser would only lose the column.
   *
   * $$ is compose's own escape for a literal dollar sign and names nothing,
   * so "$$FOO" is not a reference to FOO. ${NAME:-x} and ${NAME-x} give
   * compose a fallback value; ${NAME:?msg} and ${NAME?msg} make compose
   * refuse to start rather than carry on silently. All four count as
   * "filled" here, because either way something already tells the user what
   * is missing — the case this scan exists to catch is the bare
   * ${NAME}/$NAME that fails with nothing said at all.
   * ===================================================================== */

  // \$ then one of: \$ (escaped, names nothing) | {NAME with an optional
  // :-/-/:?/? default or error clause} | a bare NAME. The bare alternative
  // can match zero characters, so every '$' in the line is accounted for —
  // the scan can never stall on one it does not understand.
  var VAR_RE = /\$(?:\$|\{([A-Za-z0-9_]*)((?::-|-|:\?|\?)[^}]*)?\}|([A-Za-z0-9_]*))/g;

  /**
   * varRefs(text) -> [{name, line, col, len, filled}]
   *
   * `line`/`col` are 0-based, matching hostPaths()/fileRefs() above; `col`
   * and `len` cover the whole placeholder as written, "${NAME}" braces
   * included, so a caller can underline exactly what is on screen. Already
   * in line-then-column order because the scan itself walks the file top to
   * bottom and each line left to right — no separate sort is needed.
   *
   * An unterminated "${NAME" and an empty "${}" both come back as nothing:
   * this reads the syntax that is actually there rather than guessing at
   * what was meant, the same rule sealed regions follow elsewhere in this
   * file. Never throws: anything this cannot make sense of is left out.
   */
  function varRefs(text) {
    var out = [];
    try {
      text = String(text == null ? '' : text);
      var lines = text.split('\n');

      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        var c = classify(line, i);
        if (c.kind === 'blank' || c.kind === 'comment') continue;

        VAR_RE.lastIndex = 0;
        var m;
        while ((m = VAR_RE.exec(line))) {
          if (m[0] === '$$') continue;                // an escaped dollar, not a reference
          var name = m[1] !== undefined ? m[1] : m[3];
          if (name === '') continue;                   // "${}", or a lone "$" naming nothing
          var filled = m[1] !== undefined && m[2] !== undefined;
          out.push({ name: name, line: i, col: m.index, len: m[0].length, filled: filled });
        }
      }
    } catch (e) {
      return [];
    }
    return out;
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
    addService: addService,
    setValue: setValue,
    setComment: setComment,
    addItem: addItem,
    removeItem: removeItem,
    // PLAN_40: reorders one entry inside a service's list — the model side
    // of dragging a port row. Generic over any list key; only ports wires a
    // handle up to it, because no other list has an order that means
    // anything.
    moveItem: moveItem,
    addDeclared: addDeclared,
    declareNetwork: declareNetwork,
    // PLAN_64 phase C: the network row dropdown's mode branch, and its way
    // back — see their own comments for the stash they share.
    setNetworkMode: setNetworkMode,
    restoreNetworkStash: restoreNetworkStash,
    readNetworkStash: readNetworkStash,
    // The row's sentinel value for "created outside this file" — stacks.js
    // needs the exact same string to build the dropdown option, and a second
    // literal there would be a bug waiting to happen.
    externalChoice: EXTERNAL_CHOICE,
    removeDeclared: removeDeclared,
    renameDeclared: renameDeclared,
    // Phase 3 (PLAN_8) calls this from stacks.js to drop a whole
    // healthcheck/deploy block when its tick box is switched off.
    removeKey: removeKey,
    // Phase 4 (PLAN_8): healthcheck.test read as {mode, command} and written
    // back as one canonical line. splitQuoted/parseFlowList are exported
    // because stacks.js's own commandSay() gloss needs the same splitting —
    // see the comment beside splitQuoted() above for why there is only one
    // copy of it now.
    readTest: readTest,
    writeTest: writeTest,
    // Phase 5 (PLAN_8): stacks.js calls this directly to add a long-form
    // dependency — condition: service_started written explicitly alongside
    // it, since a bare "name:" is null and compose refuses the file.
    addNested: addNested,
    // PLAN_34 phase 3a: addNested's sibling for a top-level declaration
    // rather than a service.
    addDeclNested: addDeclNested,
    // PLAN_34 phase 5: turns a service's networks: from a list of names into
    // a map of them, so an entry gains somewhere to hang a fixed or hardware
    // address — see the function's own comment for why it is whole-block.
    promoteNetworksList: promoteNetworksList,
    splitQuoted: splitQuoted,
    parseFlowList: parseFlowList,
    fieldById: fieldById,
    fieldAtLine: fieldAtLine,
    serviceAtLine: serviceAtLine,
    lineAtOffset: lineAtOffset,
    emitScalar: emitScalar,
    splice: splice,
    // Phase 1 (PLAN.md): the Sections panel's byte-for-byte move of a
    // section between the compose file and x-unraid.sections.
    readSections: readSections,
    // Whether a readSections() entry means the section is off — see the
    // docblock above sectionHidden() for why stacks.js must not test the
    // stored shape itself.
    sectionHidden: sectionHidden,
    stashSection: stashSection,
    restoreSection: restoreSection,
    setSectionState: setSectionState,
    // The editor overlay's one-line-at-a-time syntax tokeniser — see the
    // "Syntax highlighting" section above for the carry contract.
    highlight: highlight,
    // The gutter's error/warning check — see the "Linting" section above.
    lint: lint,
    // Turns a path in compose's own complaint ("services.web.pull_policy")
    // into the line it lives on, so a schema error can be marked rather than
    // only mentioned — see lineOfPath() above.
    lineOfPath: lineOfPath,
    // The editor's find/replace bar — see the "Text search" section above.
    searchMatches: searchMatches,
    // The editor's autocomplete and hover help — see the "Key suggestions
    // and descriptions" section above. describeKeyAt is exported as keyAt;
    // the parser's own internal keyAt() is a different function entirely.
    keySuggestions: keySuggestions,
    // PLAN_15 phase 2: valueSuggestions() is keySuggestions()'s mirror image
    // for the value half of a key: value line — see the comment above it.
    valueSuggestions: valueSuggestions,
    keyAt: describeKeyAt,
    keyInfo: keyInfo,
    // The Form pane's field help — see fieldHelp()/helpGaps() above.
    fieldHelp: fieldHelp,
    helpGaps: helpGaps,
    // Every host-side path of a volume mount — see the "Host paths" section
    // above. Used to check the folder actually exists on the server.
    hostPaths: hostPaths,
    // Every service/network/volume/secret/config/profile name the file
    // itself declares — see the "The file's own names" section above.
    fileNames: fileNames,
    // The one rule for offering a name from the file's own namespaces,
    // shared with stacks.js's fromChoice()/serviceModeOptions() — see the
    // comment above it.
    refNames: refNames,
    // Every file the compose file expects to find beside it in the stack's
    // own folder — see the "File references" section above.
    fileRefs: fileRefs,
    // Every ${NAME}/$NAME placeholder, so the editor can flag one nothing
    // will ever fill in — see the "Variable references" section above.
    varRefs: varRefs,
    // PLAN_15 phase 1: the dropdown value lists moved out of stacks.js's
    // CHOICES table — see the comment above VOCAB for what stayed behind.
    vocab: vocab
  };

  if (typeof window !== 'undefined') window.StaxxYaml = API;
  // Node has no window. The round-trip harness under tests/ requires this file
  // directly, and that harness is the only automated test this layer can have.
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
