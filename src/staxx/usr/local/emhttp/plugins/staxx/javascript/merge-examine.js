/* StaXX — the reading pass behind merging several stacks into one.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * PLAN_148 phase 2 ("the examination"): given a host stack and one or more
 * stacks being folded into it, work out everything step 3 (clashes) and
 * step 4 (wiring) of the merge wizard need to say, as an ordered list of
 * findings. This file writes nothing, opens nothing, and reads nothing off
 * disk itself — every fact it needs arrives already parsed, exactly as
 * db-images.js and health-offer.js take their own facts pre-gathered rather
 * than going looking for them. The wizard (a later phase) turns these
 * findings into screens; this file only decides what there is to decide.
 *
 * Same dual shape as health-offer.js and db-images.js: a plain browser
 * global under `window.StaxxMergeExamine`, and a `module.exports` so
 * tests/merge_examine.js can `require()` it directly under Node.
 *
 * INPUT SHAPE — a "stack descriptor". compose-model.js's own parse tree and
 * its `form` are both built for interactive single-file editing (a field
 * per row, a range per field) and neither is a convenient shape to compare
 * two files against each other. So this module takes a smaller, already-
 * digested shape instead; the caller (wizard code, or a test) is
 * responsible for building it from whatever compose-model.js handed back.
 * A stack descriptor looks like:
 *
 *   {
 *     name: 'demo-db',                  // the stack's folder name
 *     files: ['config', '.env'],        // top-level names beside the compose file
 *     env: { lines: [...] } | null,     // the stack's own .env, already parsed
 *     compose: {
 *       services: {
 *         <serviceName>: {
 *           image, container_name,
 *           ports: [{ host, container, protocol }],   // host '' = not published
 *           volumes: [{ type: 'named'|'bind'|'anonymous', source, target }],
 *           environment: { NAME: 'value' },
 *           env_file: ['relative/path', ...],
 *           networks: ['name', ...],
 *           depends_on: ['service', ...],
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
 * OUTPUT SHAPE — examine() returns { findings: [...] }, one entry per thing
 * worth saying, in the order the wizard's steps read them (refusals first,
 * then step 3's decisions, then step 4's wiring, then the "checked and
 * fine" list). Each finding is:
 *
 *   {
 *     kind: 'storage-volume' | 'file-clash' | 'settings-join' |
 *           'container-name-clash' | 'port-clash' | 'shorthand-clash' |
 *           'address-rewire' | 'port-unneeded' | 'left-alone' | 'clean',
 *     severity: 'refusal' | 'decision' | 'automatic' | 'wiring' | 'clean',
 *     stack: 'demo-db' | null,       // which incoming stack this concerns
 *     facts: { ...plain-English facts, never markup... },
 *     choices: [ { id, label, recommended: true|false }, ... ]   // only when there is one to make
 *   }
 */

(function () {
  'use strict';

  /* =====================================================================
   * Small shared helpers
   * ===================================================================== */

  function suffix(name) {
    // The stack-name suffix a rename appends — DB_PASSWORD -> DB_PASSWORD_DEMO_DB,
    // demo-db -> DEMO_DB for an env name, or plain "demo-db" for a service.
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

  /* =====================================================================
   * Step 3 — storage Docker manages
   * ===================================================================== */

  function findStorageFindings(host, incoming) {
    var out = [];
    var volDecl = declOf(incoming, 'volumes');
    var used = {};
    var svcs = servicesOf(incoming);
    Object.keys(svcs).forEach(function (svcName) {
      (svcs[svcName].volumes || []).forEach(function (v) {
        if (v.type === 'named') used[v.source] = true;
      });
    });

    Object.keys(used).forEach(function (volName) {
      var decl = volDecl[volName];
      if (decl && decl.external) return;   // already points at a fixed real volume — nothing changes identity

      var oldName = incoming.name + '_' + volName;
      var newName = host.name + '_' + volName;
      out.push({
        kind: 'storage-volume',
        severity: 'decision',
        stack: incoming.name,
        facts: { volume: volName, oldName: oldName, newName: newName },
        choices: [
          { id: 'keep-existing', recommended: true },
          { id: 'start-empty', recommended: false },
          { id: 'stop-here', recommended: false }
        ]
      });
    });
    return out;
  }

  /* =====================================================================
   * Step 3 — files beside the stack
   * ===================================================================== */

  // The top-level name a relative path or env_file entry would land under
  // once copied into the host's folder — the first path segment, with a
  // leading "./" stripped, since that is the one thing checked against the
  // host's own folder listing.
  function topLevelName(relPath) {
    var p = String(relPath).replace(/^\.\//, '');
    var slash = p.indexOf('/');
    return slash >= 0 ? p.slice(0, slash) : p;
  }

  function findFileFindings(host, incoming) {
    var out = [];
    var seen = {};   // de-duplicate — several services may share the same bind mount
    var svcs = servicesOf(incoming);

    function consider(relPath) {
      var name = topLevelName(relPath);
      if (!name || seen[name]) return;
      seen[name] = true;
      var clashes = (host.files || []).indexOf(name) >= 0;
      out.push({
        kind: 'file-clash',
        severity: clashes ? 'refusal' : 'clean',
        stack: incoming.name,
        facts: { name: name }
      });
    }

    Object.keys(svcs).forEach(function (svcName) {
      var svc = svcs[svcName];
      (svc.volumes || []).forEach(function (v) {
        if (v.type === 'bind' && v.source && v.source.charAt(0) !== '/') consider(v.source);
      });
      (svc.env_file || []).forEach(function (f) {
        if (f !== '.env') consider(f);   // ".env" itself is the settings list — joined, not copied
      });
    });

    return out;
  }

  /* =====================================================================
   * Step 3 — the two settings lists (.env)
   * ===================================================================== */

  function envSettings(env) {
    var map = {};
    (env && env.lines || []).forEach(function (line) {
      if (line.type === 'setting') map[line.name] = line.value;
    });
    return map;
  }

  function findSettingsJoin(host, incoming, opts) {
    if (!incoming.env || !incoming.env.lines || !incoming.env.lines.length) return null;

    var hostMap = envSettings(host.env);
    var date = (opts && opts.date) || new Date().toISOString().slice(0, 10);
    var sameValue = [];
    var renamed = [];
    var joined = (host.env && host.env.lines ? host.env.lines.slice() : []);

    joined.push({ type: 'comment', text: '# ── folded in from ' + incoming.name + ', ' + date + ' ──' });

    incoming.env.lines.forEach(function (line) {
      if (line.type !== 'setting') { joined.push(line); return; }

      if (!(line.name in hostMap)) {
        joined.push(line);
        return;
      }

      if (hostMap[line.name] === line.value) {
        sameValue.push(line.name);
        joined.push({
          type: 'comment',
          text: '# ' + line.name + ' already set above (same value) — not repeated'
        });
        return;
      }

      var renamedName = line.name + '_' + suffix(incoming.name);
      renamed.push({ from: line.name, to: renamedName, stack: incoming.name });
      joined.push({
        type: 'setting', name: renamedName, value: line.value,
        comment: (line.comment ? line.comment + ' — ' : '') +
          'renamed: "' + incoming.name + '" also sets ' + line.name + ', to a different value'
      });
    });

    return {
      kind: 'settings-join',
      severity: 'decision',
      stack: incoming.name,
      facts: {
        hostHasEnv: !!(host.env && host.env.lines && host.env.lines.length),
        sameValueNames: sameValue,
        renamed: renamed,
        joinedLines: joined
      },
      choices: [
        { id: 'accept-join', recommended: true },
        { id: 'stop-here', recommended: false }
      ]
    };
  }

  /* =====================================================================
   * Step 3 — name clashes: containers/services, published ports, and the
   * top-level volumes:/networks:/configs:/secrets: shorthand entries.
   * All three follow the same shape: a "taken" set is seeded from the host,
   * then each incoming stack (in the order it was ticked) is checked
   * against it and its own names are added in turn — so a clash between
   * two INCOMING stacks, neither of which is the host, is still caught.
   * ===================================================================== */

  function findServiceNameClashes(host, incomingList) {
    var out = [];
    var taken = {};
    Object.keys(servicesOf(host)).forEach(function (n) { taken[n] = true; });

    incomingList.forEach(function (incoming) {
      Object.keys(servicesOf(incoming)).forEach(function (name) {
        var finalName = name;
        var clashed = !!taken[name];
        if (clashed) finalName = name + '_' + incoming.name;
        taken[finalName] = true;
        if (clashed) {
          out.push({
            kind: 'container-name-clash',
            severity: 'automatic',
            stack: incoming.name,
            facts: { field: 'service', from: name, to: finalName }
          });
        }
      });
    });
    return out;
  }

  function findContainerNameClashes(host, incomingList) {
    var out = [];
    var taken = {};
    Object.keys(servicesOf(host)).forEach(function (n) {
      var cn = servicesOf(host)[n].container_name;
      if (cn) taken[cn] = true;
    });

    incomingList.forEach(function (incoming) {
      Object.keys(servicesOf(incoming)).forEach(function (svcName) {
        var svc = servicesOf(incoming)[svcName];
        if (!svc.container_name) return;
        var finalName = svc.container_name;
        var clashed = !!taken[finalName];
        if (clashed) finalName = svc.container_name + '_' + incoming.name;
        taken[finalName] = true;
        if (clashed) {
          out.push({
            kind: 'container-name-clash',
            severity: 'automatic',
            stack: incoming.name,
            facts: { field: 'container_name', from: svc.container_name, to: finalName, service: svcName }
          });
        }
      });
    });
    return out;
  }

  function findPortClashes(host, incomingList) {
    var out = [];
    var taken = {};
    Object.keys(servicesOf(host)).forEach(function (svcName) {
      (servicesOf(host)[svcName].ports || []).forEach(function (p) {
        if (p.host) taken[p.host] = svcName;
      });
    });

    incomingList.forEach(function (incoming) {
      Object.keys(servicesOf(incoming)).forEach(function (svcName) {
        (servicesOf(incoming)[svcName].ports || []).forEach(function (p) {
          if (!p.host) return;
          if (taken[p.host]) {
            out.push({
              kind: 'port-clash',
              severity: 'decision',
              stack: incoming.name,
              facts: { service: svcName, port: p.host, heldBy: taken[p.host] },
              choices: [
                { id: 'free-port', recommended: true },
                { id: 'stop-publishing', recommended: false }
              ]
            });
          } else {
            taken[p.host] = svcName;
          }
        });
      });
    });
    return out;
  }

  var DECL_KINDS = ['volumes', 'networks', 'configs', 'secrets'];

  function findShorthandClashes(host, incomingList) {
    var out = [];
    DECL_KINDS.forEach(function (kind) {
      var canonical = {};
      Object.keys(declOf(host, kind)).forEach(function (name) {
        canonical[name] = declOf(host, kind)[name].def;
      });

      incomingList.forEach(function (incoming) {
        Object.keys(declOf(incoming, kind)).forEach(function (name) {
          var def = declOf(incoming, kind)[name].def;
          if (name in canonical) {
            if (!sameDef(canonical[name], def)) {
              var finalName = name + '_' + incoming.name;
              canonical[finalName] = def;
              out.push({
                kind: 'shorthand-clash',
                severity: 'automatic',
                stack: incoming.name,
                facts: { declKind: kind, from: name, to: finalName }
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
   * Step 4 — wiring: an address that can become a service name, and a
   * published port nothing needs any more.
   * ===================================================================== */

  function publishedPortsOf(descriptors) {
    // Every published host port across every stack in the merge, so an
    // address in ANY of them can be matched against a port belonging to
    // any OTHER one — not just the host's own.
    var byPort = {};
    descriptors.forEach(function (d) {
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

  // A plausible single-token hostname or IP — no colon (that shape is the
  // combined ADDR_RE form above, handled separately), no whitespace, no path.
  function looksHostLike(v) {
    return /^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/.test(String(v));
  }

  // "DB_HOST" -> "DB", "HOST" -> "", "DB_PORT" -> "DB", "SOMETHING_ELSE" ->
  // null (not host/port-shaped at all). Case-insensitive on both the
  // HOST/PORT keyword and the prefix, so two names differing only in case
  // are still treated as sharing one prefix — see findSplitWiringForService's
  // own comment on why that has to be a refusal rather than a guess.
  var HOST_SUFFIX_RE = /^(?:(.+)_)?HOST$/i;
  var PORT_SUFFIX_RE = /^(?:(.+)_)?PORT$/i;

  function suffixPrefix(re, name) {
    var m = re.exec(name);
    return m ? (m[1] || '').toUpperCase() : null;
  }

  // A separate *_HOST / *_PORT pair, correlated only when the evidence is
  // strong enough that acting on it is not a guess — see PLAN_148 step 4.
  // Two independent facts both have to hold: the two names belong to this
  // one service and share exactly one prefix each way (never a name that
  // could mean more than one thing), and the port's value matches a port
  // actually published somewhere in this merge — that second, independent
  // check is what turns a name-shaped pairing into a fact. Everything that
  // fails either test is left alone with a plain note, never rewritten.
  function findSplitWiringForService(d, svcName, svc, ports, thisServer, rewired, findings) {
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
        kind: 'left-alone',
        severity: 'wiring',
        stack: d.name,
        facts: { service: svcName, envVar: hostVar, value: env[hostVar], note: note }
      });
    }

    Object.keys(hostGroups).forEach(function (prefix) {
      var hostVars = hostGroups[prefix];
      var portVars = portGroups[prefix] || [];

      // A prefix has to name exactly one of each — a name that could pair
      // with more than one candidate is exactly the ambiguity the plan
      // rules out, not a decision this file gets to make for you.
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
      var hostVal = String(env[hostVar]), portVal = String(env[portVar]);

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

      // The port match is the second, independent confirmation. Given that,
      // an explicit list of this server's own addresses is checked strictly;
      // with no list supplied there is nothing to invent, so a plain IPv4
      // literal or bare hostname is accepted on the strength of the port
      // match alone — that is the fact, not a guess.
      var known = thisServer.length ? thisServer.indexOf(hostVal) >= 0 : true;
      if (!known) {
        leftAlone(hostVar, 'looks like an address, but is not confidently this server — left as written');
        return;
      }

      findings.push({
        kind: 'address-rewire',
        severity: 'wiring',
        stack: d.name,
        facts: {
          service: svcName, split: true, hostVar: hostVar, portVar: portVar,
          fromHost: hostVal, fromPort: portVal,
          envVar: hostVar + ' / ' + portVar, from: hostVal + ':' + portVal,
          toService: target.service, toPort: target.container,
          matchedOn: thisServer.length ? 'server-list' : 'port-evidence'
        },
        choices: [{ id: 'rewire', recommended: true, ticked: true }]
      });

      var key = target.stack + '/' + target.service + '/' + portVal;
      if (!rewired[key]) {
        rewired[key] = true;
        findings.push({
          kind: 'port-unneeded',
          severity: 'wiring',
          stack: target.stack,
          facts: { service: target.service, port: portVal },
          choices: [{ id: 'stop-publishing', recommended: false, ticked: false }]
        });
      }
    });
  }

  function findWiringFindings(host, incomingList, opts) {
    var all = [host].concat(incomingList);
    var ports = publishedPortsOf(all);
    var thisServer = (opts && opts.thisServer) || [];
    var rewired = {};   // "stack/service/port" already turned into an address-rewire, so
                         // port-unneeded is offered once per port, not once per address
    var findings = [];

    all.forEach(function (d) {
      Object.keys(servicesOf(d)).forEach(function (svcName) {
        var svc = servicesOf(d)[svcName];

        // A separate *_HOST / *_PORT pair — independent of the combined
        // "host:port" form checked below, since a bare host or port value
        // never matches ADDR_RE (it needs a literal ":" to match at all).
        findSplitWiringForService(d, svcName, svc, ports, thisServer, rewired, findings);

        var env = svc.environment || {};
        Object.keys(env).forEach(function (varName) {
          var value = String(env[varName]);
          var m = ADDR_RE.exec(value);
          if (!m) return;

          var addrHost = m[1], port = m[2];
          if (!looksAddressy(addrHost)) return;

          var known = thisServer.length ? thisServer.indexOf(addrHost) >= 0 : isIpLike(addrHost);
          var target = ports[port];

          if (known && target && !(target.stack === d.name && target.service === svcName)) {
            findings.push({
              kind: 'address-rewire',
              severity: 'wiring',
              stack: d.name,
              facts: {
                service: svcName, envVar: varName, from: addrHost + ':' + port,
                toService: target.service, toPort: target.container
              },
              choices: [{ id: 'rewire', recommended: true, ticked: true }]
            });
            var key = target.stack + '/' + target.service + '/' + port;
            if (!rewired[key]) {
              rewired[key] = true;
              findings.push({
                kind: 'port-unneeded',
                severity: 'wiring',
                stack: target.stack,
                facts: { service: target.service, port: port },
                choices: [{ id: 'stop-publishing', recommended: false, ticked: false }]
              });
            }
          } else {
            findings.push({
              kind: 'left-alone',
              severity: 'wiring',
              stack: d.name,
              facts: {
                service: svcName, envVar: varName, value: value,
                note: known
                  ? 'names a port nothing in this merge publishes — left as written'
                  : 'looks like an address, but is not confidently this server — left as written'
              }
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

  function findCleanEntries(findings, incomingList) {
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
      if (relevant.length) return;   // something was actually found — no "clean" line for this category
      out.push({ kind: 'clean', severity: 'clean', stack: null, facts: { category: cat.label } });
    });
    return out;
  }

  /* =====================================================================
   * Entry point
   * ===================================================================== */

  function examine(host, incomingList, opts) {
    incomingList = incomingList || [];
    opts = opts || {};

    var findings = [];

    // Refusals first — nothing past a refusal is written, so it is said first.
    incomingList.forEach(function (incoming) {
      findFileFindings(host, incoming).forEach(function (f) {
        if (f.severity === 'refusal') findings.push(f);
      });
    });

    // Step 3's decisions, in the plan's order.
    incomingList.forEach(function (incoming) {
      findings = findings.concat(findStorageFindings(host, incoming));
    });
    incomingList.forEach(function (incoming) {
      findFileFindings(host, incoming).forEach(function (f) {
        if (f.severity !== 'refusal') findings.push(f);   // the clean ones feed the summary below
      });
    });
    incomingList.forEach(function (incoming) {
      var j = findSettingsJoin(host, incoming, opts);
      if (j) findings.push(j);
    });
    findings = findings.concat(findServiceNameClashes(host, incomingList));
    findings = findings.concat(findContainerNameClashes(host, incomingList));
    findings = findings.concat(findPortClashes(host, incomingList));
    findings = findings.concat(findShorthandClashes(host, incomingList));

    // Step 4's wiring.
    findings = findings.concat(findWiringFindings(host, incomingList, opts));

    // The dimmed "checked and fine" summary, built from what is already known.
    findings = findings.concat(findCleanEntries(findings, incomingList));

    return { findings: findings };
  }

  /* =====================================================================
   * A builder for the merged file's *shape* — the decided structure, not
   * rendered YAML text. compose-model.js's writing functions are built to
   * edit one already-open file interactively (insertChild, addService, one
   * field at a time); splicing two whole files together a service at a time
   * is a different job, and forcing it through that API would need new
   * machinery there rather than a straightforward use of what exists. This
   * returns the decided structure instead, for whichever phase turns it
   * into text.
   * ===================================================================== */

  function buildMergedStructure(host, incomingList, decisions, opts) {
    decisions = decisions || {};
    opts = opts || {};
    var date = opts.date || new Date().toISOString().slice(0, 10);

    var serviceRenames = {};   // "stack/service" -> final name, from the automatic clashes above
    var examResult = examine(host, incomingList, opts);
    examResult.findings.forEach(function (f) {
      if (f.kind === 'container-name-clash' && f.facts.field === 'service') {
        serviceRenames[f.stack + '/' + f.facts.from] = f.facts.to;
      }
    });

    var servicesOrder = [];
    Object.keys(servicesOf(host)).forEach(function (name) {
      servicesOrder.push({ origin: 'host', stack: host.name, name: name, service: servicesOf(host)[name] });
    });

    incomingList.forEach(function (incoming) {
      Object.keys(servicesOf(incoming)).forEach(function (name) {
        var finalName = serviceRenames[incoming.name + '/' + name] || name;
        var svc = Object.assign({}, servicesOf(incoming)[name]);
        // Step 4's rename of the identity attaches here too, so the folded-in
        // stack's own icon/description/links travel with its service rather
        // than the host's stack-level block, which is left exactly as it is.
        svc.x_unraid = Object.assign({}, svc.x_unraid, incoming.compose.stack_x_unraid);
        servicesOrder.push({
          origin: 'incoming', stack: incoming.name, name: finalName, service: svc,
          headerComment: '# folded in from ' + incoming.name + ', ' + date
        });
      });
    });

    var topLevel = {};
    DECL_KINDS.forEach(function (kind) {
      var union = {};
      Object.keys(declOf(host, kind)).forEach(function (n) { union[n] = declOf(host, kind)[n]; });
      incomingList.forEach(function (incoming) {
        Object.keys(declOf(incoming, kind)).forEach(function (n) {
          if (union[n] && sameDef(union[n].def, declOf(incoming, kind)[n].def)) return;   // identical — kept once
          var finalName = union[n] ? n + '_' + incoming.name : n;
          union[finalName] = declOf(incoming, kind)[n];
        });
      });
      topLevel[kind] = union;
    });

    var envLines = host.env && host.env.lines ? host.env.lines.slice() : [];
    incomingList.forEach(function (incoming) {
      var j = findSettingsJoin(host, incoming, opts);
      if (j) envLines = j.facts.joinedLines;
    });

    return {
      servicesOrder: servicesOrder,
      topLevel: topLevel,
      env: { lines: envLines },
      historyNote: 'Merged ' + incomingList.map(function (s) { return s.name; }).join(', ') +
        ' into ' + host.name + ', ' + date
    };
  }

  var API = {
    examine: examine,
    buildMergedStructure: buildMergedStructure,
    // Exposed for tests — pure helpers with their own edge cases worth
    // proving directly rather than only through examine()'s combined output.
    suffix: suffix,
    topLevelName: topLevelName
  };

  if (typeof window !== 'undefined') window.StaxxMergeExamine = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
