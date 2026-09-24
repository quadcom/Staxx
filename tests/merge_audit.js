/* StaXX — PLAN_179 part 1: the merge audit.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * A check that needs no prediction, unlike a hand-picked trap: every difference between what the
 * sources said and what buildMergedText() actually wrote must be accounted for by a change
 * record, or it is reported. CLAUDE.md rule 2 is the whole point — a merge that alters something
 * with no card is the fault this exists to catch, and every fault PLAN_179 was written after
 * (PLAN_169 F12/F15/F16/F18, PLAN_178 F1/F2) was exactly that: a silent change.
 *
 *   audit(sources, built, opts) -> { ok, problems: [string, ...] }
 *
 * `sources` is the array buildMergedText() was itself called with — {name, text, envText, ...};
 * `built` is that same call's own return value; `opts` is the same options object. Nothing here
 * re-derives what the merge decided — every check reads it straight back out of `built.findings`
 * and `built.changes`, the same records the wizard's own screens are built from, so the audit can
 * never disagree with them by inventing a different idea of what should have happened.
 *
 * Two levels, because they catch different things (see PLAN_179 itself for the full table):
 *
 *   TEXT level — rule 2, "nothing the author wrote is lost": every comment line, every sanitise
 *   marker, survives somewhere in the merged text or is named on a change record.
 *
 *   CONFIG level — rule 2's other half, "nothing changes silently": every source service is
 *   matched to its merged one through the rename records, parsed into a plain per-field shape
 *   (compose-model.js's own tree, read the same way merge-write.js's descriptorFromText() does),
 *   and every field that differs must be explained by a change record of a kind that makes
 *   exactly that difference — never merely "some record exists".
 *
 * A handful of helper functions below are copied rather than required from merge-write.js: that
 * file does not export toPlain()/parseFlow() (they are its own private reading step) or
 * decisionValue() (its own private answer-resolver). Duplicating a dozen lines here is cheaper,
 * and safer, than reaching into another module's closure — each copy says why, and a drift
 * between the two would show up as the audit disagreeing with the merge it is checking, which is
 * exactly the kind of failure this file exists to surface.
 */

'use strict';

var path = require('path');

var JS_DIR = path.join(__dirname, '..', 'src', 'staxx', 'usr', 'local', 'emhttp', 'plugins', 'staxx', 'javascript');
var CM = require(path.join(JS_DIR, 'compose-model.js'));
var ME = require(path.join(JS_DIR, 'merge-examine.js'));

var leaf = ME.leaf;

/* =========================================================================
 * Copied from merge-write.js — see this file's own header for why.
 * ========================================================================= */

// toPlain()/parseFlow(): compose-model.js's parse tree -> a plain JS value, exactly as
// merge-write.js's own descriptorFromText() reads a source, so the audit sees the same shape the
// merge itself decided against.
function parseFlow(raw) {
  var idx = raw.indexOf(':');
  var text = idx < 0 ? raw : raw.slice(idx + 1);
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
    var o = {};
    inner.split(',').forEach(function (part) {
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

// decisionValue(): merge-write.js's own answer-resolver, copied verbatim (bar the wiring branch,
// which this audit never needs — nothing it checks reads a wiring tick-box) — a decision not
// recorded falls back to the finding's own recommended choice, exactly as the merge itself would.
function decisionValue(decisions, f) {
  var stored = decisions[f.key];
  var rec = null;
  (f.choices || []).forEach(function (c) { if (c.recommended) rec = c.id; });
  return stored === undefined ? rec : stored;
}

/* =========================================================================
 * Reading a compose text into the plain per-service shape the config-level
 * check compares. Modelled on descriptorFromText() (merge-write.js), minus
 * the .env resolution (the audit compares AS-WRITTEN text, since that is
 * what the merge itself edits and what a person reads) and plus a few
 * fields descriptorFromText() has no need of (command, healthcheck).
 * ========================================================================= */

function normalisePorts(raw) {
  return asStringArray(raw).map(function (entry) {
    var p = ME.parsePortSpec(entry);
    return { address: p.address || '', host: p.host || '', container: p.container || '', protocol: p.protocol };
  });
}

function normaliseVolumes(raw) {
  return asStringArray(raw).map(function (entry) {
    if (typeof entry === 'string') {
      var parts = entry.split(':');
      var source = parts[0], target = parts[1] || '';
      var type = (source.charAt(0) === '.' || source.charAt(0) === '/' || source.charAt(0) === '~' || source.charAt(0) === '$') ? 'bind' : 'named';
      return { type: type, source: source, target: target, ro: parts[2] === 'ro' };
    }
    if (entry && typeof entry === 'object') {
      if (entry.type === 'volume') return { type: 'named', source: entry.source || '', target: entry.target || '', ro: !!entry.read_only };
      if (entry.type === 'bind') return { type: 'bind', source: entry.source || '', target: entry.target || '', ro: !!entry.read_only };
      return { type: entry.type || 'other', source: entry.source || '', target: entry.target || '', ro: !!entry.read_only };
    }
    return null;
  }).filter(Boolean);
}

function kvMap(raw) {
  var out = {};
  if (Array.isArray(raw)) {
    raw.forEach(function (kv) {
      var eq = String(kv).indexOf('=');
      if (eq >= 0) out[kv.slice(0, eq)] = kv.slice(eq + 1);
    });
  } else if (raw && typeof raw === 'object') {
    out = raw;
  }
  return out;
}

function nameList(raw) {
  if (Array.isArray(raw)) return raw.slice();
  if (raw && typeof raw === 'object') return Object.keys(raw);
  return [];
}

// The service's own network set per PLAN_155 C15's rule — an absent/empty networks: key means
// the project's implicit default network, never "reaches nothing" (see merge-write.js's
// networkSetOf(), which this mirrors for the same reason).
function networksOf(raw) {
  var l = nameList(raw.networks);
  return l.length ? l : ['default'];
}

function buildConfig(text) {
  var doc = CM.parse(text);
  var plain = toPlain(doc.root) || {};
  var services = {};
  Object.keys(plain.services || {}).forEach(function (svcName) {
    var raw = plain.services[svcName] || {};
    services[svcName] = {
      image: raw.image || '',
      command: raw.command !== undefined ? raw.command : null,
      container_name: raw.container_name || null,
      restart: raw.restart || '',
      network_mode: raw.network_mode || null,
      profiles: asStringArray(raw.profiles).slice().sort(),
      healthcheck: raw.healthcheck || null,
      environment: kvMap(raw.environment),
      ports: normalisePorts(raw.ports),
      volumes: normaliseVolumes(raw.volumes),
      labels: kvMap(raw.labels),
      networks: networksOf(raw).slice().sort(),
      depends_on: nameList(raw.depends_on).slice().sort()
    };
  });

  function declBlock(key) {
    var out = {};
    Object.keys(plain[key] || {}).forEach(function (n) {
      var def = plain[key][n] || {};
      out[n] = { external: !!def.external, name: def.name || null, def: def };
    });
    return out;
  }

  return {
    services: services,
    volumes: declBlock('volumes'),
    networks: declBlock('networks'),
    configs: declBlock('configs'),
    secrets: declBlock('secrets')
  };
}

/* =========================================================================
 * TEXT LEVEL — comments and sanitise markers.
 * ========================================================================= */

function commentLines(text) {
  return String(text || '').split(/\r?\n/).filter(function (l) { return /^\s*#/.test(l); })
    .map(function (l) { return l.replace(/^\s*#\s?/, '').trim(); }).filter(function (l) { return l !== ''; });
}

// Every marker token ("-!S", "-!R", ...) found in a comment line, kept with its own count — two
// occurrences of "-!S" in two different runs are two instances, not one.
function markerTokens(text) {
  var out = [];
  String(text || '').split(/\r?\n/).forEach(function (line) {
    if (!/^\s*#/.test(line)) return;
    var re = /-![A-Z]\b/g, m;
    while ((m = re.exec(line))) out.push(m[0]);
  });
  return out;
}

function countBy(arr) {
  var c = {};
  arr.forEach(function (x) { c[x] = (c[x] || 0) + 1; });
  return c;
}

// The stack's own contiguous regions inside the merged text — every block buildMergedText()
// splices in is introduced by a "# From <leaf>" comment (services, declared blocks, x-unraid
// fields, other top-level keys), so a region runs from one such marker up to the next one
// belonging to ANY stack (or the end of the file). This is coarser than "attached to the same
// key" — it is "attached to the same SOURCE" — but computing an exact key-level match would mean
// re-deriving buildMergedText()'s own splice bookkeeping a second time; scoping by stack is
// enough to catch a comment or marker dropped outright, which is what every real fault so far
// (PLAN_169 F15, PLAN_178 F2) actually was.
function stackRegions(mergedText, stackLeaf) {
  var lines = String(mergedText || '').split(/\r?\n/);
  var markerRe = /#\s*From\s+(\S+)\s*$/;
  var starts = [];
  lines.forEach(function (l, i) {
    var m = markerRe.exec(l.trim());
    if (m) starts.push({ line: i, leaf: m[1] });
  });
  var regions = [];
  starts.forEach(function (s, idx) {
    if (s.leaf !== stackLeaf) return;
    var end = idx + 1 < starts.length ? starts[idx + 1].line : lines.length;
    // A source's own file-header comment (its first line, above "services:") is spliced in
    // immediately BEFORE this marker, not after it — buildMergedText()'s own per-service loop
    // writes headerLines then the "# From <leaf>" line back to back. Walked backwards through
    // any run of comment lines that is not itself another stack's own marker, so that header
    // is not mistaken for lost.
    var begin = s.line;
    while (begin > 0 && /^\s*#/.test(lines[begin - 1]) && !markerRe.test(lines[begin - 1].trim())) begin--;
    regions.push(lines.slice(begin, end).join('\n'));
  });
  return regions.join('\n');
}

function textLevelAudit(sources, built, problems) {
  if (built.text === null) return;   // a refusal writes nothing — nothing to check
  var mergedText = built.text;
  var mergedComments = countBy(commentLines(mergedText));

  var struckByStack = {};   // stack -> [comment lines struck, from every change record]
  (built.changes || []).forEach(function (c) {
    if (!c.struckComment) return;
    (struckByStack[c.stack] = struckByStack[c.stack] || []).push.apply(
      struckByStack[c.stack], c.struckComment.map(function (l) { return l.replace(/^\s*#\s?/, '').trim(); })
    );
  });

  sources.forEach(function (s) {
    var region = stackRegions(mergedText, leaf(s.name));
    var regionComments = countBy(commentLines(region));
    var struckHere = countBy(struckByStack[s.name] || []);
    // Whole-file counts too — a source's own comment can legitimately end up carried inside
    // ANOTHER stack's region (a struck marker line rewritten and left where it fell, an x-unraid
    // field merged into the FIRST source's own shared block) or the joined .env; the region scan
    // is the primary net, the whole-file count is the backstop before something is called lost.
    commentLines(s.text).forEach(function (line) {
      var inRegion = (regionComments[line] || 0) > 0;
      var struck = (struckHere[line] || 0) > 0;
      var anywhere = (mergedComments[line] || 0) > 0;
      if (inRegion) { regionComments[line]--; return; }
      if (struck) { struckHere[line]--; return; }
      if (anywhere) { mergedComments[line]--; return; }
      problems.push(s.name + ': comment "' + line + '" is not carried into the merged file, ' +
        'and no change record struck it — rule 2 (CLAUDE.md) says a comment is never silently dropped.');
    });

    var srcMarkers = countBy(markerTokens(s.text));
    var mergedMarkers = countBy(markerTokens(region).concat(markerTokens((struckByStack[s.name] || []).join('\n'))));
    Object.keys(srcMarkers).forEach(function (token) {
      if ((mergedMarkers[token] || 0) < srcMarkers[token]) {
        problems.push(s.name + ': sanitise marker "' + token + '" appears ' + srcMarkers[token] +
          ' time(s) in the source but only ' + (mergedMarkers[token] || 0) + ' time(s) in its own region of the merged file.');
      }
    });

    // The same two checks against the source's own .env, if it has one — settings-join carries
    // ordinary comment lines straight through (Object.assign in findSettingsJoin()), and a
    // genuinely dropped dedupe entry gets its own `removed`/`removedText` change record instead.
    if (s.envText && built.env) {
      var envRegion = stackRegions(built.env, leaf(s.name)) || '';
      // The joined .env's own "# From <leaf>" header has no trailing content markers the way
      // compose blocks do (findSettingsJoin() writes exactly one per source, at the top of its
      // own run of lines) — stackRegions() still finds it, since the regex only cares that the
      // line ends with "# From <leaf>".
      var envComments = countBy(commentLines(envRegion));
      var envRemoved = countBy((built.changes || []).filter(function (c) { return c.file === 'env' && c.stack === s.name && c.removedText; })
        .map(function (c) { return c.removedText.replace(/^\s*#\s?/, '').trim(); }));
      commentLines(s.envText).forEach(function (line) {
        if ((envComments[line] || 0) > 0) { envComments[line]--; return; }
        if ((countBy(commentLines(built.env))[line] || 0) > 0) return;   // carried, just outside the naive region split
        problems.push(s.name + ': .env comment "' + line + '" is not carried into the joined .env.');
      });
    }
  });
}

/* =========================================================================
 * CONFIG LEVEL — every source service matched to its merged one, every
 * field difference explained by a change record of the matching kind.
 * ========================================================================= */

function deepEqual(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

function multisetEqual(a, b, keyFn) {
  var ac = countBy(a.map(keyFn)), bc = countBy(b.map(keyFn));
  return deepEqual(ac, bc);
}

function configLevelAudit(sources, built, opts, problems) {
  if (built.text === null) return;
  var decisions = (opts && opts.decisions) || {};
  var findings = built.findings || [];
  var changes = built.changes || [];

  var changesByKey = {};
  changes.forEach(function (c) { (changesByKey[c.key] = changesByKey[c.key] || []).push(c); });
  function declinedFor(key, part) {
    var list = changesByKey[key] || [];
    return list.some(function (c) { return (part === undefined || c.part === part) && c.declined; });
  }

  // The rename plan, read straight back out of the findings — the identical reduction
  // examine() itself performs (see merge-examine.js's own examine(), building `plan`), so this
  // can never disagree with what the merge actually used.
  var serviceRenames = {}, containerNameRenames = {}, declRenames = {};
  findings.forEach(function (f) {
    if (f.kind === 'container-name-clash' && f.facts.field === 'service') serviceRenames[f.stack + '/' + f.facts.from] = f.facts.to;
    if (f.kind === 'container-name-clash' && f.facts.field === 'container_name') containerNameRenames[f.stack + '/' + f.facts.service] = { from: f.facts.from, to: f.facts.to };
    if (f.kind === 'shorthand-clash') declRenames[f.stack + '/' + f.facts.declKind + '/' + f.facts.from] = f.facts.to;
  });

  var storageCarry = {};   // "stack/volume" -> finding
  findings.forEach(function (f) { if (f.kind === 'storage-carry') storageCarry[f.stack + '/' + f.facts.volume] = f; });

  var addressRewires = {};   // "stack/service/envVar" -> finding (both split halves indexed)
  findings.forEach(function (f) {
    if (f.kind !== 'address-rewire') return;
    if (f.facts.split) {
      addressRewires[f.stack + '/' + f.facts.service + '/' + f.facts.hostVar] = { f: f, part: 'host' };
      addressRewires[f.stack + '/' + f.facts.service + '/' + f.facts.portVar] = { f: f, part: 'port' };
    } else {
      addressRewires[f.stack + '/' + f.facts.service + '/' + f.facts.envVar] = { f: f, part: undefined };
    }
  });

  var portClashes = {};      // "stack/service/port" -> finding
  var portUnneeded = {};     // "stack/service/port" -> finding
  findings.forEach(function (f) {
    if (f.kind === 'port-clash') portClashes[f.stack + '/' + f.facts.service + '/' + f.facts.port] = f;
    if (f.kind === 'port-unneeded') portUnneeded[f.stack + '/' + f.facts.service + '/' + f.facts.port] = f;
  });

  var depthPaths = {};   // "stack/field/service/oldPath" -> finding
  findings.forEach(function (f) {
    if (f.kind !== 'depth-path') return;
    depthPaths[f.stack + '/' + f.facts.field + '/' + (f.facts.service || '') + '/' + f.facts.oldPath] = f;
  });

  var labelClashes = {};   // "stack/service" -> [findings] (a service can clash on more than one name)
  findings.forEach(function (f) {
    if (f.kind !== 'label-clash') return;
    (labelClashes[f.stack + '/' + f.facts.service] = labelClashes[f.stack + '/' + f.facts.service] || []).push(f);
  });

  var networkModeJoins = {};   // "stack/service" -> finding (PLAN_179 P2)
  findings.forEach(function (f) { if (f.kind === 'network-mode-join') networkModeJoins[f.stack + '/' + f.facts.service] = f; });

  var networkJoined = {};   // stack -> true, at least one network-join record exists for it — see
                             // this function's own comment on why this is stack-scoped, not per-service.
  changes.forEach(function (c) { if (/^network-join\|/.test(c.key)) networkJoined[c.stack] = true; });

  var settingsJoin = findings.filter(function (f) { return f.kind === 'settings-join'; })[0];

  var srcConfigs = sources.map(function (s) { return buildConfig(s.text); });
  var mergedConfig = buildConfig(built.text);

  sources.forEach(function (s, si) {
    var stack = s.name, cfg = srcConfigs[si];
    Object.keys(cfg.services).forEach(function (svcName) {
      var svc = cfg.services[svcName];
      var finalSvc = serviceRenames[stack + '/' + svcName] || svcName;
      var m = mergedConfig.services[finalSvc];
      if (!m) {
        problems.push(stack + ' service ' + svcName + ': missing from the merged file entirely (expected under "' + finalSvc + '").');
        return;
      }

      if (svc.image !== m.image) problems.push(stack + ' service ' + svcName + ': image changed from "' + svc.image + '" to "' + m.image + '" with no explaining record.');
      if (!deepEqual(svc.command, m.command)) problems.push(stack + ' service ' + svcName + ': command changed with no explaining record.');
      if (svc.restart !== m.restart) problems.push(stack + ' service ' + svcName + ': restart changed from "' + svc.restart + '" to "' + m.restart + '" with no explaining record.');
      if (svc.network_mode !== m.network_mode) {
        var expectedNm = svc.network_mode;
        // PLAN_179 P2 — a "container:<name>" naming a service's own container_name INSIDE this
        // merge is a wiring finding (network-mode-join), ticked by default like every other
        // rewire: approved, it becomes "service:<that service's final key>"; declined, it is
        // left exactly as written.
        var nj = networkModeJoins[stack + '/' + svcName];
        if (nj && svc.network_mode === 'container:' + nj.facts.fromContainer) {
          var njApproved = decisions.hasOwnProperty(nj.key) ? !!decisions[nj.key] : true;
          var finalTargetSvc = serviceRenames[nj.facts.toStack + '/' + nj.facts.toService] || nj.facts.toService;
          expectedNm = njApproved ? ('service:' + finalTargetSvc) : svc.network_mode;
        } else {
          // "service:<name>"/"container:<name>" is a reference to a SIBLING service in this same
          // source, followed through by CM.renameService() the same way a depends_on: entry is —
          // not in PLAN_179's own table (which only lists depends_on/environment/ports/volumes/
          // labels/networks explicitly), but the same underlying rename record explains it, so a
          // difference here is only a problem when it does not match that rename.
          var nmMatch = /^(service|container):(.+)$/.exec(svc.network_mode || '');
          if (nmMatch && nmMatch[1] === 'service' && serviceRenames[stack + '/' + nmMatch[2]]) {
            expectedNm = 'service:' + serviceRenames[stack + '/' + nmMatch[2]];
          } else if (nmMatch && nmMatch[1] === 'container') {
            Object.keys(containerNameRenames).forEach(function (k) {
              if (k.indexOf(stack + '/') === 0 && containerNameRenames[k].from === nmMatch[2]) expectedNm = 'container:' + containerNameRenames[k].to;
            });
          }
        }
        if (expectedNm !== m.network_mode) problems.push(stack + ' service ' + svcName + ': network_mode changed from "' + svc.network_mode + '" to "' + m.network_mode + '" with no explaining record (expected "' + expectedNm + '").');
      }
      if (!deepEqual(svc.profiles, m.profiles)) problems.push(stack + ' service ' + svcName + ': profiles changed with no explaining record.');
      if (!deepEqual(svc.healthcheck, m.healthcheck)) problems.push(stack + ' service ' + svcName + ': healthcheck changed with no explaining record.');

      // container_name — explained only by a container-name-clash record.
      var cnRename = containerNameRenames[stack + '/' + svcName];
      var expectedContainerName = cnRename ? cnRename.to : svc.container_name;
      if (expectedContainerName !== m.container_name) {
        problems.push(stack + ' service ' + svcName + ': container_name is "' + m.container_name + '", expected "' + expectedContainerName + '" (from the rename record, or unchanged).');
      }

      // environment — every var explained by an address-rewire (approved) or a settings-join
      // ${VAR} substitution; a declined rewire must leave the value untouched.
      Object.keys(svc.environment).forEach(function (varName) {
        var oldVal = svc.environment[varName];
        var newVal = m.environment[varName];
        // compose-model.js deliberately refuses to read a double-quoted scalar that carries a
        // backslash escape ("left alone rather than half-understood" — its own scanValue()
        // comment): the value comes back as an opaque node, so toPlain() here (and
        // merge-write.js's own identically-built `environment` map) both get `undefined` for
        // it, whether it is the source or the merged file. Nothing downstream in the real merge
        // can read such a value either, so it is never a candidate for a wiring rewrite — it is
        // carried completely unedited, inside whichever whole service block is spliced across
        // (this is PLAN_179's own P3 finding: a value shaped like a JSON string is not "dropped",
        // it is simply outside what either this audit or the merge itself parses as a value).
        if (oldVal === undefined) return;
        if (newVal === undefined) { problems.push(stack + ' service ' + svcName + ' env ' + varName + ': dropped from the merged file entirely.'); return; }
        if (newVal === oldVal) return;

        var rw = addressRewires[stack + '/' + svcName + '/' + varName];
        if (rw) {
          var declined = declinedFor(rw.f.key, rw.part);
          if (declined) {
            problems.push(stack + ' service ' + svcName + ' env ' + varName + ': changed even though its address-rewire record was declined.');
            return;
          }
          var expected;
          if (rw.f.facts.split) {
            expected = rw.part === 'host' ? rw.f.facts.toService : rw.f.facts.toPort;
          } else {
            expected = oldVal.split(rw.f.facts.matchAddr).join(rw.f.facts.toService + ':' + rw.f.facts.toPort);
          }
          if (newVal !== expected) {
            problems.push(stack + ' service ' + svcName + ' env ' + varName + ': is "' + newVal + '", the address-rewire record implies "' + expected + '".');
          }
          return;
        }

        // A settings-join rename followed the ${VAR}/$VAR interpolation into this same source's
        // own compose lines (rewriteEnvVarReferences() in merge-write.js) — the only OTHER way
        // an environment: value's text legitimately changes.
        if (settingsJoin) {
          var explained = settingsJoin.facts.renamed.some(function (r) {
            if (r.stack !== stack) return false;
            var reBraced = new RegExp('\\$\\{' + r.from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\}');
            var reBare = new RegExp('\\$' + r.from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![A-Za-z0-9_])');
            if (!reBraced.test(oldVal) && !reBare.test(oldVal)) return false;
            var finalName = r.finalName || r.to;
            var want = oldVal.replace(new RegExp('\\$\\{' + r.from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\}', 'g'), '${' + finalName + '}')
                              .replace(new RegExp('\\$' + r.from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![A-Za-z0-9_])', 'g'), '$' + finalName);
            return want === newVal;
          });
          if (explained) return;
        }

        problems.push(stack + ' service ' + svcName + ' env ' + varName + ': changed from "' + oldVal + '" to "' + newVal + '" with no explaining record.');
      });

      // ports — every host port that moved or vanished must be a port-clash (approved) or
      // port-unneeded (approved); a declined one must survive unchanged.
      svc.ports.forEach(function (p) {
        if (!p.host) return;   // unpublished — nothing here can clash or need rewiring
        var stillThere = m.ports.some(function (mp) { return mp.address === p.address && mp.host === p.host && mp.container === p.container && mp.protocol === p.protocol; });
        if (stillThere) return;

        var pc = portClashes[stack + '/' + svcName + '/' + p.host];
        var pu = portUnneeded[stack + '/' + svcName + '/' + p.host];
        if (pc) {
          if (decisionValue(decisions, pc) === 'leave') {
            problems.push(stack + ' service ' + svcName + ' port ' + p.host + ': moved even though its port-clash record was left/declined.');
          }
          // approved — a new port for the same container/address/protocol must exist somewhere
          // in the merged service; the exact number is the finding's own pickFreePort() choice,
          // trusted rather than re-derived (see this file's own header on reading records, not
          // re-deciding them).
          var moved = m.ports.some(function (mp) { return mp.address === p.address && mp.container === p.container && mp.protocol === p.protocol; });
          if (!moved) problems.push(stack + ' service ' + svcName + ' port ' + p.host + ': the port-clash record says it moved, but no replacement publish for the same container port survives.');
          return;
        }
        if (pu) {
          if (!decisionValue(decisions, pu)) {
            problems.push(stack + ' service ' + svcName + ' port ' + p.host + ': removed even though its port-unneeded record was declined (kept published).');
          }
          return;   // approved — removal is exactly what the record says happens
        }
        problems.push(stack + ' service ' + svcName + ' port ' + p.host + ': gone from the merged file with no port-clash or port-unneeded record.');
      });

      // A published port present in BOTH but pointed at a webui address — F17's own rewrite —
      // is not re-checked here; it is a service-level x-unraid field the config shape above
      // never reads, and re-parsing it would mean a third copy of rewriteServiceWebui()'s own
      // regex. Left to the walk suites' own dedicated checks (trap 4 in merge_walk_six_dryrun.js).

      // volumes — a NAMED mount's source key, explained by storage-carry (used) or a
      // shorthand-clash rename; a BIND mount's source path, explained by a depth-path record.
      svc.volumes.forEach(function (v) {
        if (v.type === 'bind') {
          var stillThereBind = m.volumes.some(function (mv) { return mv.type === 'bind' && mv.source === v.source && mv.target === v.target && mv.ro === v.ro; });
          if (stillThereBind) return;
          var dp = depthPaths[stack + '/volumes/' + svcName + '/' + v.source];
          if (dp) {
            if (decisionValue(decisions, dp) === 'leave') {
              problems.push(stack + ' service ' + svcName + ' bind ' + v.source + ': changed even though its depth-path record was left/declined.');
              return;
            }
            var okNew = m.volumes.some(function (mv) { return mv.type === 'bind' && mv.source === dp.facts.newPath && mv.target === v.target && mv.ro === v.ro; });
            if (!okNew) problems.push(stack + ' service ' + svcName + ' bind ' + v.source + ': depth-path record says it becomes "' + dp.facts.newPath + '", but no such mount survives.');
            return;
          }
          problems.push(stack + ' service ' + svcName + ' bind ' + v.source + ': changed with no depth-path record.');
          return;
        }
        if (v.type !== 'named') return;   // an anonymous mount has no declared name to carry
        var carry = storageCarry[stack + '/' + v.source];
        var expectedKey = carry ? carry.facts.mergedKey : (declRenames[stack + '/volumes/' + v.source] || v.source);
        var stillThereNamed = m.volumes.some(function (mv) { return mv.type === 'named' && mv.source === expectedKey && mv.target === v.target && mv.ro === v.ro; });
        if (!stillThereNamed) {
          problems.push(stack + ' service ' + svcName + ' volume ' + v.source + ': expected to mount "' + expectedKey + '" in the merged file (storage-carry/shorthand-clash record), but does not.');
        }
      });

      // labels — a Traefik name clash is the only kind that legitimately changes a label; every
      // other difference is unexplained. Coarse on purpose: rather than re-implementing
      // rewriteServiceLabels()'s own section-by-section text substitution a second time, any
      // label whose KEY OR VALUE differs is accepted so long as this service has at least one
      // label-clash record and the difference is consistent with its from->to rename (the old
      // name no longer appears, the new one does); anything else is a problem.
      var clashesHere = labelClashes[stack + '/' + svcName] || [];
      Object.keys(svc.labels).forEach(function (key) {
        // A clashing label's own KEY changes text (the name segment is rewritten), so it will
        // never be found unchanged under its old key — matched instead by rewriting the old key
        // the same way rewriteServiceLabels() does and checking THAT key survives.
        var rewrittenKey = key;
        clashesHere.forEach(function (f) {
          rewrittenKey = rewrittenKey.split('.' + f.facts.from + '.').join('.' + f.facts.to + '.');
        });
        if (rewrittenKey !== key) {
          if (m.labels[rewrittenKey] === undefined) {
            problems.push(stack + ' service ' + svcName + ' label ' + key + ': renamed by a label-clash record to "' + rewrittenKey + '", but that key is missing from the merged file.');
          }
          return;
        }
        if (m.labels[key] === undefined) { problems.push(stack + ' service ' + svcName + ' label ' + key + ': dropped from the merged file with no record.'); return; }
        if (m.labels[key] !== svc.labels[key] && !clashesHere.length) {
          problems.push(stack + ' service ' + svcName + ' label ' + key + ': value changed from "' + svc.labels[key] + '" to "' + m.labels[key] + '" with no explaining record.');
        }
      });

      // networks — a name a shorthand-clash renamed (this stack declared its own "demo" beside
      // another source's differently-defined "demo") is expected under its OWN new name, same
      // as a mount's volume key; an ADDED network beyond that is explained by a network-join
      // record existing SOMEWHERE for this stack (see this function's own comment above:
      // network-join's own change-record key carries no service name, only a stack and a line,
      // so this is stack-scoped rather than exact). A network REMOVED is never explained by
      // anything in the table — a problem.
      var expectedNetworks = svc.networks.map(function (n) { return declRenames[stack + '/networks/' + n] || n; });
      var added = m.networks.filter(function (n) { return expectedNetworks.indexOf(n) === -1; });
      var removed = expectedNetworks.filter(function (n) { return m.networks.indexOf(n) === -1; });
      if (removed.length) problems.push(stack + ' service ' + svcName + ': dropped from network(s) ' + removed.join(', ') + ' with no explaining record.');
      if (added.length && !networkJoined[stack]) {
        problems.push(stack + ' service ' + svcName + ': joined network(s) ' + added.join(', ') + ' with no network-join record for this stack.');
      }
    });
  });

  // Top-level declared blocks — every source's own volumes:/networks:/configs:/secrets: entry,
  // matched into the merged file's own block the same way buildMergedText()'s own assembly pass
  // resolves it: storage-carry (volumes only, when the volume is actually used) takes priority
  // over a shorthand-clash rename, which takes priority over the plain original key.
  ['volumes', 'networks', 'configs', 'secrets'].forEach(function (kind) {
    var seen = {};   // mergedKey already checked once — two sources agreeing on an identical
                      // block share one entry (findShorthandClashes()'s own "same def, no
                      // finding" branch), so only the first source to reach a given key is
                      // checked against the merged file; a second, identical one is expected to
                      // contribute nothing further.
    sources.forEach(function (s, si) {
      var cfg = srcConfigs[si];
      Object.keys(cfg[kind]).forEach(function (name) {
        var def = cfg[kind][name];
        if (def.external) return;   // external: true is never carried, renamed or copied
        var carry = kind === 'volumes' ? storageCarry[s.name + '/' + name] : null;
        var mergedKey = carry ? carry.facts.mergedKey : (declRenames[s.name + '/' + kind + '/' + name] || name);
        if (seen[mergedKey]) return;
        seen[mergedKey] = true;

        var m = mergedConfig[kind][mergedKey];
        if (!m) { problems.push(s.name + ' ' + kind + ' ' + name + ': missing from the merged file (expected under "' + mergedKey + '").'); return; }

        if (carry) {
          var decision = decisionValue(decisions, carry);
          if (decision === 'carry' || decision === undefined) {
            if (m.name !== carry.facts.realName) {
              problems.push(s.name + ' ' + kind + ' ' + name + ': carried, but its merged declaration has name: "' + m.name + '", expected "' + carry.facts.realName + '".');
            }
          } else if (decision === 'start-empty') {
            if (m.name) problems.push(s.name + ' ' + kind + ' ' + name + ': "start empty" was chosen, but a name: override was written anyway.');
          }
        }
        // Anything else about the block (driver options, labels, other def fields) is expected
        // identical to the source's own — a genuine difference here is not covered by any row
        // in PLAN_179's table, so it is reported the same as any other unexplained change.
        var defWithoutName = Object.assign({}, def.def); delete defWithoutName.name;
        var mDefWithoutName = Object.assign({}, m.def); delete mDefWithoutName.name;
        if (!deepEqual(defWithoutName, mDefWithoutName)) {
          problems.push(s.name + ' ' + kind + ' ' + name + ': declaration body differs from the merged file\'s (' + mergedKey + ') beyond the name: override.');
        }
      });
    });
  });
}

/* =========================================================================
 * MARKER LEVEL (PLAN_178 F4) — every change record's own position agrees
 * with the merged file it was built against. buildMergedText() resolves a
 * change's `line` by searching for its `marker` text inside finalLines
 * (compose-model.js's own array of lines, one per array entry — see that
 * function's own comment just above its PLAN_160 A block), so `marker` is
 * always the exact text of the line `line` names, for EVERY kind of change
 * — a rewired address, a moved port, a struck comment's replacement, a
 * carried volume's declaration. That one relationship is general enough to
 * check without knowing what kind of change it is; a change record whose
 * `marker` and merged-file line have drifted apart is exactly the fault
 * F4 was: every card after a links-block insert pointed one line too low.
 *
 * Two kinds are left out on purpose, both already excluded upstream by
 * buildMergedText() itself, not by a limit of this check:
 *   - `file: 'env'` — a joined .env line, never part of the compose text
 *     built.text holds, so there is nothing here to compare it against.
 *   - `removed: true` — its stand-in line is spliced OUT of finalLines
 *     once resolved (see the removal pass in merge-write.js), so `line`
 *     then means "insert a struck row before this index", not "this text
 *     sits here"; checking it against the text that replaced it would be
 *     comparing the wrong thing, not confirming the right one.
 * ========================================================================= */

function markerLevelAudit(built, problems) {
  if (built.text === null) return;   // a refusal writes nothing
  var mergedLines = built.text.split(/\r?\n/);
  (built.changes || []).forEach(function (c) {
    if (c.file === 'env') return;
    if (c.removed) return;
    if (typeof c.line !== 'number' || typeof c.marker !== 'string') return;
    if (mergedLines[c.line] !== c.marker) {
      problems.push((c.stack || '?') + ' key=' + c.key + ' "' + c.title + '": its marker text ("' +
        c.marker + '") is not the merged file\'s line ' + (c.line + 1) + ' ("' + (mergedLines[c.line] || '') +
        '") — this card is pointing at the wrong line.');
    }
  });
}

function audit(sources, built, opts) {
  sources = sources || [];
  built = built || {};
  opts = opts || {};
  var problems = [];
  textLevelAudit(sources, built, problems);
  configLevelAudit(sources, built, opts, problems);
  markerLevelAudit(built, problems);
  return { ok: problems.length === 0, problems: problems };
}

/* =========================================================================
 * Order comparison — "the two merged configs must be equal apart from
 * block order" (PLAN_179). A clash's own resolution (which side keeps the
 * plain name, which free port a port-clash lands on) can legitimately
 * differ between pick orders — merge-examine.js's own "taken" sets are
 * built by iterating the sources in the order they were picked — so this
 * does not attempt a byte-for-byte compare of every field. It compares
 * what pick order can never legitimately change: which (source, service)
 * pairs exist in the merged file at all, and the handful of fields no
 * clash or rewire ever touches (image, restart, network_mode, profiles,
 * healthcheck, command, and the SET of environment variable names — not
 * their values, which an address-rewire may point at a differently-named
 * target depending on which side of a clash that target landed on).
 * ========================================================================= */

function originIndex(sources) {
  var out = {};   // "stack::svc" -> true, independent of any pick order
  sources.forEach(function (s) {
    var cfg = buildConfig(s.text);
    Object.keys(cfg.services).forEach(function (svcName) { out[s.name + '::' + svcName] = true; });
  });
  return out;
}

function finalNameOf(built, stack, svcName) {
  var rename = (built.findings || []).filter(function (f) {
    return f.kind === 'container-name-clash' && f.facts.field === 'service' && f.stack === stack && f.facts.from === svcName;
  })[0];
  return rename ? rename.facts.to : svcName;
}

function compareOrders(sources, builtA, builtB) {
  var problems = [];
  if (builtA.text === null || builtB.text === null) return { ok: true, problems: problems };   // nothing written either way
  var origins = Object.keys(originIndex(sources));
  var cfgA = buildConfig(builtA.text), cfgB = buildConfig(builtB.text);

  origins.forEach(function (id) {
    var sep = id.indexOf('::'), stack = id.slice(0, sep), svcName = id.slice(sep + 2);
    var aName = finalNameOf(builtA, stack, svcName), bName = finalNameOf(builtB, stack, svcName);
    var a = cfgA.services[aName], b = cfgB.services[bName];
    if (!a || !b) { problems.push(id + ': present in one pick order\'s merged file but not the other.'); return; }

    if (a.image !== b.image) problems.push(id + ': image differs between pick orders ("' + a.image + '" vs "' + b.image + '").');
    if (a.restart !== b.restart) problems.push(id + ': restart differs between pick orders.');
    // A "service:<name>"/"container:<name>" reference is followed through by CM.renameService()
    // (see configLevelAudit()'s own comment on this), so its TEXT can legitimately differ between
    // pick orders exactly the way an address-rewire's target name can — only whether it is a
    // reference at all, never which name it names, is order-invariant.
    var aIsRef = /^(service|container):/.test(a.network_mode || ''), bIsRef = /^(service|container):/.test(b.network_mode || '');
    if (aIsRef || bIsRef) {
      if (aIsRef !== bIsRef || a.network_mode.split(':')[0] !== b.network_mode.split(':')[0]) {
        problems.push(id + ': network_mode differs between pick orders in a way a rename cannot explain (' + a.network_mode + ' vs ' + b.network_mode + ').');
      }
    } else if (a.network_mode !== b.network_mode) {
      problems.push(id + ': network_mode differs between pick orders.');
    }
    if (!deepEqual(a.profiles, b.profiles)) problems.push(id + ': profiles differ between pick orders.');
    if (!deepEqual(a.healthcheck, b.healthcheck)) problems.push(id + ': healthcheck differs between pick orders.');
    if (!deepEqual(a.command, b.command)) problems.push(id + ': command differs between pick orders.');

    var aVars = Object.keys(a.environment).sort(), bVars = Object.keys(b.environment).sort();
    if (!deepEqual(aVars, bVars)) problems.push(id + ': environment variable names differ between pick orders (' + aVars.join(',') + ' vs ' + bVars.join(',') + ').');
  });

  return { ok: problems.length === 0, problems: problems };
}

module.exports = {
  audit: audit,
  buildConfig: buildConfig,
  compareOrders: compareOrders
};
