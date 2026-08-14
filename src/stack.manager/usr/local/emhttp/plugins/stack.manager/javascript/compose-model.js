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

  // The whole vocabulary the form knows, one entry per compose key: what
  // shape its value takes, what to call it, which declared-name namespace
  // (see buildForm's `declared`) a list entry's value is a reference into.
  // Everything harvest(), inferTitle() and inferType() need is read from
  // here, so adding a key is one line instead of edits in four places.
  var KEYS = {
    image:          { shape: 'scalar', always: 1 },
    container_name: { shape: 'scalar', always: 1 },
    restart:        { shape: 'scalar', always: 1, choices: 'restart' },
    network_mode:   { shape: 'scalar', always: 1, choices: 'netmode', excludes: 'networks' },

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
    expose:         { shape: 'list',  entry: 'plain', title: 'Internal ports', type: 'port' },
    env_file:       { shape: 'list',  entry: 'plain', title: 'Variable files', tool: 'browse' },

    healthcheck:    { shape: 'block', title: 'Health check' },
    deploy:         { shape: 'block', title: 'Resource limits' },

    command:        { shape: 'scalar' },
    entrypoint:     { shape: 'scalar' },
    user:           { shape: 'scalar' },
    hostname:       { shape: 'scalar' },
    privileged:     { shape: 'scalar', type: 'boolean' },
    shm_size:       { shape: 'scalar' },
    working_dir:    { shape: 'scalar', title: 'Working folder' },
    mem_limit:      { shape: 'scalar', title: 'Memory limit' }
  };

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
    }
  };

  // A declaration's settings the form knows what to do with — the same shape
  // as LEAVES above, pointed at harvestBlock via a table argument rather than
  // a second copy of the walk. DECL_PRIMARY is the one setting per kind that
  // stands for the whole declaration and gets its own box on the row, so it
  // is excluded from the fold rather than shown twice.
  var DECL_LEAVES = {
    networks: { driver: 'Driver', internal: 'Internal only, no outside access',
                external: 'Created outside this file', name: 'Real name in Docker',
                attachable: 'Attachable' },
    volumes:  { driver: 'Driver', external: 'Created outside this file',
                name: 'Real name in Docker' },
    secrets:  { file: 'File on the server', environment: 'From a variable',
                external: 'Created outside this file' },
    configs:  { file: 'File on the server', external: 'Created outside this file' }
  };
  var DECL_PRIMARY = { networks: 'driver', volumes: 'driver', secrets: 'file', configs: 'file' };

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
  // table order — image, container_name, restart, network_mode — because the
  // Container group's field count depends on it (see harvest()). LIST_KEY
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
    var bits = splitOutsideVars(text);
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

  // A plain list entry is one whole value, so it keys on itself. Unlike a port
  // or a mount there is no container half to bind to, which means renaming an
  // entry IS replacing it.
  function splitPlain(text, spot) {
    var s = String(text).trim();
    return s ? { key: s, parts: { value: part(s, spot) } } : null;
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

      if (!it.value || it.value.kind === 'opaque') {
        out.push(lockedTarget(binder, '@' + pair.key + '#' + i, range,
          lockReason(it.value ? it.value.reason : 'unparsable'),
          it.value ? it.value.raw : '', listKey));
        continue;
      }

      if (it.value.kind === 'map') {
        // A 'list' binder has no long form — {target:, ...} is a ports/volumes
        // thing — so a map item here is not something harvestLongForm can read
        // either; it would bail on the missing target: and drop the entry
        // silently. Lock it instead, so it stays visible.
        if (binder === 'list') {
          var span = lines.slice(it.start, it.end);
          var raw = span.map(function (l) { return l.slice(it.indent); }).join('\n');
          out.push(lockedTarget(binder, '@' + pair.key + '#' + i, range,
            'this entry is written as a block of its own', raw, listKey));
          continue;
        }
        harvestLongForm(out, binder, it, range, lines, listKey);
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
  function harvestLongForm(out, binder, item, range, lines, listKey) {
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
        lockReason: pub ? '' : 'no host port is set here',
        listKey: listKey
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
      lockReason: src ? '' : 'this mount has no source path to edit',
      listKey: listKey
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
    // A command spelled out as a list of arguments is a perfectly ordinary
    // thing to find, so say that rather than falling back on "the form
    // cannot read this", which sounds like the file is wrong.
    var why = !p.value ? 'this setting has no value'
            : p.value.kind === 'seq' ? 'this is written as a list of separate items'
            : p.value.kind === 'map' ? 'this is written as a block of its own'
            : lockReason(p.value.reason);
    // Only a sealed node carries `raw`. A seq or map was parsed properly and
    // has none, so a command written as a list would otherwise reach the form
    // as an empty code block. Either way the text shown is the file's own
    // lines less the key's own indent, so a command reads as a command rather
    // than as a fragment of a file — and so the two ways of writing one over
    // several lines, a list and a block scalar, look the same on screen.
    var span = p.value && p.value.raw ? p.value.raw.split('\n')
                                      : lines.slice(p.start, p.end);
    var raw = span.map(function (l) { return l.slice(p.indent); }).join('\n');
    return lockedTarget('setting', key, range, why, raw);
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
      if (!already) out.push(settingTarget(p.value.pairs[childName], childTarget, lines));
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
    var spot = ok ? { line: leafPair.start, col: 0, len: 0, style: 'plain' } : null;
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

  function harvest(serviceMap, lines) {
    var out = [], i, p;

    // The Container group's four rows come first, in a fixed order, whether
    // or not the file has them — so the field count for a service never
    // changes when one of them materialises (see fieldsFor()).
    for (i = 0; i < ALWAYS_KEYS.length; i++) {
      var akey = ALWAYS_KEYS[i];
      var spec = KEYS[akey];
      p = serviceMap.pairs[akey];

      // Compose refuses a service with both of these, so an empty slot here is
      // a trap: filling it in would make a working file invalid.
      if (spec.excludes && serviceMap.pairs[spec.excludes]) {
        out.push(target('setting', akey, {
          parts: { value: part('', null) }, range: null, blocked: true,
          advice: ['this service joins the networks listed below instead']
        }));
        continue;
      }

      out.push(p ? settingTarget(p, akey, lines)
                  : target('setting', akey, { parts: { value: part('', null) }, range: null, absent: true }));
    }

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
          // depends_on is the one 'plain list' key that can also be written as
          // a map, one dependency per name — no other key sharing this shape
          // (networks, secrets, profiles...) ever takes this form.
          if (key === 'depends_on' && p.value && p.value.kind === 'map') {
            harvestDependsLong(out, p, lines);
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
    // healthcheck.test's own two fields — checked ahead of the dotted-path
    // lookup below, which cannot find a title for '.mode'/'.command' since
    // LEAVES only names the whole leaf ('test'), not its two halves.
    if (t.testPart === 'mode') return 'How the check runs';
    if (t.testPart === 'command') return 'The check itself';
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
    // A nested leaf's target is dotted — 'healthcheck.interval' — so its
    // title lives in LEAVES rather than KEYS. A dotted target LEAVES does not
    // name (an uncovered child, e.g. deploy.replicas) falls back to the last
    // segment, so it reads "Replicas" rather than the whole path.
    var dot = t.target.indexOf('.');
    if (t.binder === 'setting' && dot > 0) {
      var block = LEAVES[t.target.slice(0, dot)];
      var title = block && block[t.target.slice(dot + 1)];
      if (title) return title;
      var segs = t.target.split('.');
      return humanise(segs[segs.length - 1]);
    }
    return humanise(t.target);
  }

  function inferType(t) {
    if (t.binder === 'port') return 'port';
    if (t.binder === 'volume' || t.binder === 'device') return 'path';
    if (t.binder === 'setting' && KEYS[t.target] && KEYS[t.target].type) return KEYS[t.target].type;
    if (t.binder === 'list') return (KEYS[t.listKey] && KEYS[t.listKey].type) || 'text';
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
      // "depends_on: [web]" would otherwise both become "a/list/web". The index
      // is always appended, not just on a genuine duplicate value — editing one
      // entry to match another would otherwise change an untouched sibling's id
      // too (the edit-stability docs/x-unraid-schema.md:317 asks for), and
      // stacks.js looks a row up by this exact id string in several places, so
      // the shape must never come in two forms.
      var listSpec = t.listKey && KEYS[t.listKey];
      var listSuffix = (t.binder === 'list' && typeof t.index === 'number') ? '#' + t.index : '';

      fields.push({
        id: serviceName + '/' + t.binder +
            (t.binder === 'list' ? '.' + t.listKey + listSuffix : '') + '/' + t.target,
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
        fixedRequired: fixed && (t.target === 'image' || t.target === 'container_name'),
        path: t.path || null,
        testPart: t.testPart || null,
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

        // The row itself.
        var rowTarget = target('declared', kind + '.' + name, {
          parts: {
            name:  part(name, keySpot(pair)),
            value: primaryNode ? part(primaryNode.value, scalarSpot(primaryNode)) : part('', null)
          },
          range: { start: pair.leadStart, end: pair.start + 1 },
          comment: primaryNode ? readComment(primaryNode.comment) : undefined,
          commentSpot: primaryNode ? commentSpot(primaryNode, lines) : null
        });

        fields.push({
          id: '/declared/' + rowTarget.target,
          service: '', binder: rowTarget.binder, target: rowTarget.target,
          title: humanise(name), type: 'text', mode: rowTarget.mode,
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
        if (!pair.value || pair.value.kind !== 'map') continue;

        var titleTable = DECL_LEAVES[kind] || {};
        var raw = [];
        harvestBlock(raw, kind + '.' + name, pair, lines, DECL_LEAVES, kind);

        for (var ri = 0; ri < raw.length; ri++) {
          var t = raw[ri];
          var childKey = t.target.slice((kind + '.' + name + '.').length);
          if (childKey === primaryKey) continue;   // already the row's own value box

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
            declKind: kind, fold: true
          });
        }
      }
    }
    return fields;
  }

  function buildForm(doc) {
    // `declared` is seeded here rather than where it is filled in, because both
    // early returns below happen first — a caller reading declared.networks on
    // an unreadable file must find an empty list, not undefined.
    var out = { stack: {}, services: [], fields: [], warnings: [], sealed: doc.sealed, ok: true,
                declared: { networks: [], volumes: [], secrets: [], configs: [], services: [] } };

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

    // The Stack section: the file's own networks/volumes/secrets/configs,
    // appended after every service's fields. Order in the array does not
    // matter — data-row is an index into it — so appending leaves every
    // existing field's index untouched.
    out.fields = out.fields.concat(declaredFields(doc, doc.lines));

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
      }
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
      for (var hi = 0; hi < out.fields.length && !hasCheck; hi++) {
        var hf = out.fields[hi];
        if (hf.service === depName && hf.target.indexOf('healthcheck.') === 0 && !hf.absent) hasCheck = true;
      }
      if (!hasCheck) {
        df.advice.push('"' + depName + '" has no health check, so this will never come true — ' +
                        'add one to "' + depName + '", or choose a different condition here');
      }
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
    if (!p) return false;

    // healthcheck.test is one file line shown as two fields (see
    // harvestHealthTest() in compose-model.js), so editing either one has to
    // write both together — read live off the sibling rather than assumed,
    // since the sibling may itself be mid-edit. Checked ahead of the f.path
    // branch below: both test fields carry a path too (for fieldsFor()'s
    // bookkeeping), but must never reach addNested()/removeKey()'s flat,
    // one-key writes, which know nothing about the two-field split.
    //
    // A blank command with mode 'shell' or 'cmd' would write a broken
    // test: ["CMD-SHELL", ""], so — matching the blank-writes-nothing rule
    // the rest of this file already follows — that write is skipped and the
    // line is left exactly as it was. Choosing "no check" always writes,
    // because that is a real choice, not an empty box; and a blank command
    // with no mode chosen yet defaults to 'shell', which is what typing a
    // command straight into a fresh Health check group most naturally means.
    if (f.testPart) {
      if (which !== 'value') return false;
      var sib = siblingTestField(form, f);
      if (!sib) return false;
      var modeField = f.testPart === 'mode' ? f : sib;
      var mode    = f.testPart === 'mode'    ? value : (modeField.absent ? 'shell' : modeField.parts.value.value);
      var command = f.testPart === 'command' ? value : sib.parts.value.value;
      if ((mode === 'shell' || mode === 'cmd') && !String(command).trim()) return true;
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

    if (!p.spot) {
      // A declaration with nothing under it yet — "frontend_net:" and no
      // driver line — needs a nested insert rather than addSetting's
      // service-level one. A blank value leaves it exactly as it was: a
      // bare declaration is already a complete, valid one (an ordinary
      // bridge network), so there is nothing to write.
      if (f.binder === 'declared' && which === 'value') {
        if (!String(value).trim()) return true;
        var block = doc.root.pairs[f.declKind];
        var declPair = block && block.value && block.value.kind === 'map'
                        ? block.value.pairs[f.target.slice(f.declKind.length + 1)] : null;
        var primaryKey = DECL_PRIMARY[f.declKind];
        if (!declPair || !primaryKey) return false;
        return insertChild(doc, declPair, primaryKey, value) >= 0;
      }
      // A LEAVES sub-path with no line yet. Its target is dotted
      // ("healthcheck.interval"), which is not itself a compose key, so it
      // needs addNested's walk rather than addSetting's flat insert — but
      // the blank-writes-nothing rule below is the same one, for the same
      // reason.
      if (f.path && which === 'value') {
        if (!String(value).trim()) return true;
        return addNested(doc, form, f.service, f.path, value) >= 0;
      }
      // An absent Container slot has no line yet. A blank must write nothing
      // — typing into an empty box and moving on must not plant a bare
      // "restart:" in the file just because the box was focused. Anything
      // else creates the line, key and value together, via addSetting.
      if (!f.absent || which !== 'value') return false;
      if (!String(value).trim()) return true;
      return addSetting(doc, form, f.service, f.target, value) >= 0;
    }
    // Writing a value back unchanged must never touch the file. Beyond being
    // wasteful it is a correctness rule: emitScalar quotes a bare true or false to
    // stop a string being misread as a boolean, so re-emitting an unquoted `true`
    // would rewrite the line it came from. Nothing here needs to decide what the
    // value means when the answer is "leave it exactly as it was".
    if (String(value) === String(p.value)) return true;
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
  function newEntry(binder, taken, service, shape, value, listKey, declared) {
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
    if (binder === 'list') {
      // A key with a declared-name namespace (networks, secrets, configs,
      // depends_on) offers the first name not already on this service, so
      // the add button hands over something that is already valid rather
      // than a word the user must remember to replace. Everything else is a
      // fixed, obviously-a-placeholder literal.
      var spec = KEYS[listKey] || {};
      if (spec.from && declared) {
        for (var d2 = 0; d2 < declared.length; d2++) {
          if (taken.indexOf(declared[d2]) < 0) return declared[d2];
        }
      }
      var LIST_PLACEHOLDER = {
        networks: 'default', profiles: 'extras', dns: '1.1.1.1',
        cap_add: 'NET_ADMIN', expose: '8080', env_file: './app.env',
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
        return String(pn);
      }
      if (listKey === 'dns') {
        var octets = base.split('.'), last = parseInt(octets[3], 10), candidate;
        do {
          candidate = octets.slice(0, 3).join('.') + '.' + last;
          last++;
        } while (taken.indexOf(candidate) >= 0);
        return candidate;
      }
      // Everything else here is an obvious placeholder already, and the new
      // box's text is selected for overtyping immediately (structuralEdit in
      // stacks.js), so a suffix is enough.
      return freeName(taken, base, '-');
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
             [pad(v.indent) + '-' + gap + newEntry(binder, taken, service, 'seq', value, key, declared)]);
      return v.end;
    }

    if (v && v.kind === 'map') {
      splice(doc, v.end, 0, [pad(v.indent) + newEntry(binder, taken, service, 'map', value, key, declared)]);
      return v.end;
    }

    if (pair) {                       // "ports:" with nothing under it
      var at = pair.end;
      splice(doc, at, 0,
             [pad(pair.indent + 2) + '- ' + newEntry(binder, taken, service, 'seq', value, key, declared)]);
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
      ? pad(indent + 2) + newEntry(binder, taken, service, 'map', value, key, declared)
      : pad(indent + 2) + '- ' + newEntry(binder, taken, service, 'seq', value, key, declared));

    splice(doc, to, 0, lines);
    return to + lines.length - 1;
  }

  // Writes one `key: value` line as a child of `pair`, at pair.indent + 2.
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
  // Returns the inserted line number, or -1 when emitScalar refuses the value.
  function insertChild(doc, pair, key, value, before) {
    var raw = null;
    if (value !== undefined && value !== null) {
      raw = emitScalar(value, 'plain', false);
      if (raw === null) return -1;
    }

    var indent = pair.indent + 2;
    var map = pair.value && pair.value.kind === 'map' ? pair.value : null;

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
  function addSetting(doc, form, service, key, value) {
    var svc = serviceMapOf(doc, service);
    if (!svc) return -1;
    return insertChild(doc, svc, key, value, 'x-unraid');
  }

  /**
   * Writes a value nested any number of levels under a service — the
   * primitive PLAN_7.md names as the shared blocker for turning on an empty
   * healthcheck/deploy group. Walks `path`, creating each missing level as a
   * bare key via insertChild, and writes the leaf last.
   *
   * splice() (called by insertChild) fully re-parses the document, so every
   * pair held before it runs is stale the instant it returns — which is why
   * a level just created is not reused: the whole walk restarts from
   * serviceMapOf so every position is read fresh.
   *
   * Returns -1, rather than guessing, whenever a level along the way is
   * sealed, opaque, or a scalar — inserting into something the parser could
   * not read is how a working file gets corrupted.
   */
  function addNested(doc, form, service, path, value) {
    var pair = serviceMapOf(doc, service), i;
    if (!pair) return -1;

    for (i = 0; i < path.length - 1; i++) {
      var map = pair.value && pair.value.kind === 'map' ? pair.value : null;
      if (pair.value && !map) return -1;
      var next = map ? map.pairs[path[i]] : null;
      if (!next) {
        if (insertChild(doc, pair, path[i], null, i === 0 ? 'x-unraid' : null) < 0) return -1;
        return addNested(doc, form, service, path, value);
      }
      pair = next;
    }

    // The loop above only checks a level before descending past it, so the
    // leaf's immediate parent — reached only when path.length === 1, or by
    // falling out of the loop above — needs the same check the intermediate
    // levels get: a scalar, anchor or flow value here is exactly as unsafe
    // to write a child into as one higher up the path.
    if (pair.value && pair.value.kind !== 'map') return -1;
    return insertChild(doc, pair, path[path.length - 1], value,
                       path.length === 1 ? 'x-unraid' : null);
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
   * Removes the pair at `path` under a service, and any parent the removal
   * leaves with nothing else under it — a bare "healthcheck:" (or a mid-path
   * "deploy: resources:") is null, and compose refuses the file, the same
   * rule removeItem/removeDeclared already follow for a list key or a
   * declared name. Shared by setPart's blank-an-existing-leaf case and (Phase
   * 3) the tick-box's whole-block removal, so both walk the file the same way.
   *
   * Finds the outermost ancestor the removal would leave empty and takes its
   * whole span in one go, rather than removing level by level — every
   * intermediate pair is already in hand from one read of the file, so there
   * is nothing to re-walk the way addNested must after each splice().
   *
   * Returns false when there is nothing at `path`, or a level along the way
   * is sealed, opaque, or not a map.
   */
  function removeKey(doc, form, service, path) {
    var pair = serviceMapOf(doc, service), i;
    if (!pair) return false;

    var chain = [pair];      // chain[i] is the pair holding path[0..i-1]
    for (i = 0; i < path.length - 1; i++) {
      if (!pair.value || pair.value.kind !== 'map') return false;
      pair = pair.value.pairs[path[i]];
      if (!pair) return false;
      chain.push(pair);
    }
    if (!pair.value || pair.value.kind !== 'map') return false;

    var leaf = pair.value.pairs[path[path.length - 1]];
    if (!leaf) return false;

    // Named for what it is rather than "target": target() is the field
    // factory this whole file is built on, and shadowing it inside a
    // removal walk is a trap for whoever edits this next.
    var outermost = leaf;
    for (i = chain.length - 1; i >= 1; i--) {
      if (chain[i].value.keys.length !== 1) break;
      outermost = chain[i];
    }

    spliceBlock(doc, outermost.leadStart, outermost.end);
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
      if (!lp || !lp.value || lp.value.kind !== 'seq') continue;

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

        // Networks has no long form to check here — a service's networks:
        // is either this sequence of names or a map keyed by name, never a
        // sequence of maps — so only secrets/configs/volumes reach this.
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

    applyRenameWrites(doc, writes);
    // refs always carries the declaration's own key as its first entry, so
    // the count reported here excludes it, matching renameService.
    return { ok: true, refs: refs.length - 1 };
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
    addDeclared: addDeclared,
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
    splitQuoted: splitQuoted,
    parseFlowList: parseFlowList,
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
