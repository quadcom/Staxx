/* StaXX — writes in the commented-out x-unraid fields a stack starts life
 * without. Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * PLAN_83: a compose file's presentation fields (icon, overview, links,
 * update policy) live in x-unraid blocks nobody is told about unless they
 * already know the names. scaffold() adds them in as commented-out lines
 * with a short hint beside each, so uncommenting and typing is the whole
 * job. It never invents a value and never touches a line that is already
 * there — see CLAUDE.md rule 2. Pure string in, string out — no DOM — so
 * this runs equally well in the browser and under Node for
 * tests/meta_scaffold.js.
 *
 * A new x-unraid block IS a real key, at both root and service level — not
 * commented out. Measured on the test server (compose v2.40.3):
 * `docker compose config --hash='*'` gives the identical service hash for
 * no x-unraid, a bare `x-unraid:` with nothing under it, and a fully filled
 * one — compose excludes every `x-` extension key from the config hash
 * entirely. So a bare block can never make a running stack look out of
 * date. The one real consequence — a service's x-unraid parses as `null`,
 * which the schema must allow — is fixed in schema/x-unraid.schema.json,
 * not by avoiding the key.
 */

(function () {
  'use strict';

  var Yaml = (typeof window !== 'undefined' && window.StaxxYaml) ||
    (typeof require === 'function' ? require('./compose-model.js') : null);

  function pad(n) { var s = ''; while (s.length < n) s += ' '; return s; }

  /* =====================================================================
   * Field tables — the one source both scaffold() and its tests/callers
   * read, so a tally and a check never describe two different lists.
   * ===================================================================== */

  var STACK_FIELDS = [
    { key: 'icon', hint: 'a selfh.st name, ./icon.png, a URL, or fa-database' },
    { key: 'overview', block: 'What this stack is for.' },
    { key: 'category', hint: 'Unraid category, e.g. MediaApp:Video' },
    { key: 'project', hint: 'the project home page' },
    { key: 'support', hint: 'forum thread or issue tracker' },
    { key: 'readme', hint: 'documentation page' },
    { key: 'author', hint: '' },
    { key: 'update', nested: [
        { key: 'mode', value: 'notify', hint: 'off, notify or auto' },
        { key: 'delay', value: '24', hint: 'hours to wait before auto applies one' }
      ] }
  ];

  var SERVICE_FIELDS = [
    { key: 'icon', hint: 'overrides the stack icon for this service' },
    { key: 'overview', block: 'What this container does.' },
    { key: 'project', hint: 'the page for this service, when it differs' },
    { key: 'support', hint: 'the support page for this service' },
    { key: 'webui', hint: 'e.g. http://[IP]:8096/' }
  ];

  /* =====================================================================
   * Rendering — every line below is a comment, so there is no quoting rule
   * to apply and nothing here can make the file invalid.
   * ===================================================================== */

  // `inner` is the nesting that lives INSIDE the comment rather than in front
  // of it: a nested field is written "#   mode:", not "  # mode:", so the
  // comment marker stays on the block's own edge and the whole block reads as
  // one comment — the same shape the overview block below uses.
  function fieldLine(indent, key, value, hint, inner) {
    var body = '# ' + (inner || '') + key + ':' + (value ? ' ' + value : '');
    var s = pad(indent) + body;
    if (hint) s += pad(Math.max(1, 22 - body.length)) + '# ' + hint;
    return s;
  }

  function renderField(field, indent) {
    if (field.block) {
      return [pad(indent) + '# ' + field.key + ': |', pad(indent) + '#   ' + field.block];
    }
    if (field.nested) {
      var lines = [pad(indent) + '# ' + field.key + ':'];
      field.nested.forEach(function (sub) {
        lines.push(fieldLine(indent, sub.key, sub.value, sub.hint, '  '));
      });
      return lines;
    }
    return [fieldLine(indent, field.key, null, field.hint)];
  }

  // Every line this file splices is one it built itself, so an embedded
  // newline can only get in if a hint string above is ever edited into one
  // by mistake — this is that guard. stashLinesOk() (the model's own set-
  // aside check) does not fit here: it insists the first significant line
  // be a real "key:" line, and every line offered here is a comment, so
  // there is never one to anchor on.
  function linesOk(lines) {
    if (!Array.isArray(lines) || !lines.length) return false;
    for (var i = 0; i < lines.length; i++) {
      var l = lines[i];
      if (typeof l !== 'string' || l.indexOf('\n') >= 0 || l.indexOf('\r') >= 0) return false;
    }
    return true;
  }

  /* =====================================================================
   * Reading what is already there
   * ===================================================================== */

  // The physical reach of a key's block: from the line after it to the last
  // line still indented deeper than the key itself. This is deliberately not
  // `pair.value.end` — a trailing comment with no key after it to attach to
  // is left outside the map the parser built (parseMap breaks rather than
  // guessing what follows it), which would otherwise make a placeholder we
  // just wrote invisible to the very check that is supposed to find it
  // again next time.
  function blockExtent(doc, pair) {
    var i = pair.start + 1, end = pair.start + 1;
    while (i < doc.lines.length) {
      var line = doc.lines[i];
      if (/^\s*$/.test(line)) { i++; continue; }
      var lead = line.match(/^[ \t]*/)[0].length;
      if (lead <= pair.indent) break;
      end = i + 1;
      i++;
    }
    return end;
  }

  // A commented placeholder counts as the field already being offered — the
  // one rule that makes this idempotent. Matched on the commented key name
  // at the block's own child indent, and only within this block's own
  // physical reach, so "# icon:" in a different service can never suppress
  // this one's.
  function hasPlaceholder(doc, pair, childIndent, key) {
    var end = blockExtent(doc, pair);
    var prefix = pad(childIndent) + '#';
    var re = new RegExp('^' + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*:');
    for (var i = pair.start + 1; i < end; i++) {
      var line = doc.lines[i];
      if (line.slice(0, prefix.length) !== prefix) continue;
      if (re.test(line.slice(prefix.length).replace(/^\s+/, ''))) return true;
    }
    return false;
  }

  // Only comments/blanks and, at most, leading document markers precede the
  // header — used to tell a file's own header comment (which must stay
  // above a new root block) from a comment that is actually annotating
  // "services:" itself (which must stay directly above it, untouched).
  function looksLikeHeader(doc, before) {
    for (var i = 0; i < before; i++) {
      var l = doc.lines[i];
      if (/^\s*$/.test(l) || /^(---|\.\.\.)\s*$/.test(l)) continue;
      return false;
    }
    return true;
  }

  /* =====================================================================
   * Writing
   * ===================================================================== */

  function appendMissing(doc, pair, fields, childIndent, isRoot, added) {
    var map = pair.value;
    var lines = [], names = [];
    // version: 1 is the one real (uncommented) key this writes, and only
    // when it is missing — every other field defaults to "not set" and a
    // comment says so, but a reader needs *some* answer for a file that
    // predates this field entirely, so this one gets a real default instead
    // of an offer.
    if (isRoot && !map.pairs.version) {
      lines.push(pad(childIndent) + 'version: 1');
      names.push('version');
    }
    fields.forEach(function (f) {
      if (map.pairs[f.key]) return;
      if (hasPlaceholder(doc, pair, childIndent, f.key)) return;
      lines = lines.concat(renderField(f, childIndent));
      names.push(f.key);
    });
    if (!lines.length) return;
    // Counted only once the write is certain to happen. Recording a field as
    // added and then bailing would have the caller report work it never did.
    if (doc.unreadTail || !linesOk(lines)) return;
    Yaml.splice(doc, blockExtent(doc, pair), 0, lines);
    names.forEach(function (n) { added.push(n); });
  }

  // Builds a brand-new root x-unraid block immediately before `services:`,
  // after any comment lines that are the file's own header — see
  // looksLikeHeader(). Followed by a blank line, matching the separator
  // ca-convert.js already puts around its own x-unraid block.
  function scaffoldNewRoot(doc, servicesPair, step, added) {
    var indent = step;
    var lines = ['x-unraid:', pad(indent) + 'version: 1'];
    var names = ['version'];
    STACK_FIELDS.forEach(function (f) {
      lines = lines.concat(renderField(f, indent));
      names.push(f.key);
    });
    lines.push('');
    if (!linesOk(lines.filter(function (l) { return l !== ''; }))) return;
    var at = looksLikeHeader(doc, servicesPair.leadStart) ? servicesPair.start : servicesPair.leadStart;
    Yaml.splice(doc, at, 0, lines);
    // Counted after the splice, for the same reason appendMissing does.
    names.forEach(function (n) { added.push(n); });
  }

  function scaffoldService(doc, name, step, added, skipped) {
    var svc = doc.root.pairs.services.value.pairs[name];
    if (!svc || !svc.value || svc.value.kind !== 'map') {
      skipped.push('The "' + name + '" service is not written as a plain block, so its StaXX fields were not added.');
      return;
    }

    var xu = svc.value.pairs['x-unraid'];
    if (xu && (!xu.value || xu.value.kind !== 'map')) {
      skipped.push('The x-unraid block in "' + name + '" is not a plain list of settings, so its fields were not added.');
      return;
    }

    var list = [];
    if (xu) {
      appendMissing(doc, xu, SERVICE_FIELDS, xu.value.indent, false, list);
    } else {
      var indent = svc.value.indent + step;
      var lines = [];
      SERVICE_FIELDS.forEach(function (f) {
        lines = lines.concat(renderField(f, indent));
        list.push(f.key);
      });
      if (doc.unreadTail || !linesOk(lines)) return;
      // Appended as the service's last key — insertChild already carries
      // the "x-unraid sits last" rule the rest of the model follows.
      var at = Yaml.insertChild(doc, svc, 'x-unraid', null, null, false);
      if (at < 0) {
        skipped.push('The "' + name + '" service could not be changed, so its StaXX fields were not added.');
        return;
      }
      Yaml.splice(doc, at + 1, 0, lines);
    }
    if (list.length) added[name] = list;
  }

  /* =====================================================================
   * Public API
   * ===================================================================== */

  function refuse(result, message) {
    result.error = message;
    return result;
  }

  function scaffold(yamlText) {
    var result = { yaml: yamlText, added: { stack: [], services: {} }, skipped: [], changed: false, error: '' };
    var doc = Yaml.parse(yamlText);

    if (doc.root.kind !== 'map') {
      var reason = doc.root.reason;
      if (reason === 'multi-doc') {
        return refuse(result, 'This file holds more than one YAML document, separated by a "---" line, so nothing was added — split it into separate files first.');
      }
      if (reason === 'tab-indent') {
        return refuse(result, 'This file uses tab characters to indent, which cannot be read reliably, so nothing was added — replace the tabs with spaces first.');
      }
      if (reason === 'directive') {
        return refuse(result, 'This file opens with a YAML directive that cannot be read here, so nothing was added.');
      }
      return refuse(result, 'This file could not be read as a compose file, so nothing was added.');
    }
    if (doc.unreadTail) {
      // Name the line. This refusal is shown on the settings page, away from
      // the editor and its gutter mark, so "the line the editor flags" sent
      // somebody looking for a fact the parser had already worked out.
      if (typeof doc.unreadLine !== 'number') {
        return refuse(result, 'Part of this file could not be read, so nothing was added — fix the line the editor flags first.');
      }
      return refuse(result, 'This file could not be read past line ' + (doc.unreadLine + 1) +
        ', so nothing was added. That line is usually indented differently from the ones around ' +
        'it — fix it in the Compose view first.');
    }

    var servicesPair = doc.root.pairs.services;
    if (!servicesPair || !servicesPair.value || servicesPair.value.kind !== 'map') {
      return refuse(result, 'This file has no services for StaXX to add fields to, so nothing was added — add at least one service first.');
    }

    var rootXu = doc.root.pairs['x-unraid'];
    if (rootXu && (!rootXu.value || rootXu.value.kind !== 'map')) {
      return refuse(result, 'The x-unraid block at the top of this file is not a plain list of settings, so nothing was added — fix it in the Compose view first.');
    }

    // The file's own indent step, read off where its service names sit
    // relative to "services:" itself — a 4-space file gets 4-space
    // additions rather than a hard-coded 2 fighting its style.
    var step = servicesPair.value.indent - servicesPair.indent;
    if (step <= 0) step = 2;

    if (rootXu) {
      appendMissing(doc, rootXu, STACK_FIELDS, rootXu.value.indent, true, result.added.stack);
    } else if (!doc.unreadTail) {
      scaffoldNewRoot(doc, servicesPair, step, result.added.stack);
    }

    // Re-read fresh: appendMissing/scaffoldNewRoot may have spliced, and
    // splice() re-parses the whole document, so every node held from before
    // is stale — doc.root itself is the same object reference, updated in
    // place, so this is simply reading it again rather than re-deriving it.
    servicesPair = doc.root.pairs.services;
    servicesPair.value.keys.slice().forEach(function (name) {
      if (doc.unreadTail) return;
      scaffoldService(doc, name, step, result.added.services, result.skipped);
    });

    result.changed = !!(result.added.stack.length || Object.keys(result.added.services).length);
    result.yaml = result.changed ? Yaml.serialise(doc) : yamlText;
    return result;
  }

  // Cheap enough for the editor to call on open without doing the work
  // twice — deliberately not a second copy of the missing-field rules.
  function needsScaffold(yamlText) {
    return scaffold(yamlText).changed;
  }

  var API = {
    scaffold: scaffold,
    needsScaffold: needsScaffold,
    stackFields: STACK_FIELDS,
    serviceFields: SERVICE_FIELDS
  };

  if (typeof window !== 'undefined') window.StaxxMeta = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
