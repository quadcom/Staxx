#!/usr/bin/env node
/* StaXX — PLAN_62 Stage 2: what has the author added or dropped since we last
 * looked at their published example?
 *
 * Runs through compose-model.js, the exact parser the browser uses — never a
 * second, cruder YAML reader, and never `docker compose config`, which would
 * resolve env_file/extends against a third party's paths and can take
 * fifteen seconds a call. See PLAN_62's "load-bearing decision" section.
 *
 * Usage: node watch-compare.js <local-compose-file> <author-example-file>
 * Prints one line of JSON to stdout and always exits 0 — a parse failure is
 * reported in the JSON (ok:false, reason), not as a process failure, so a
 * caller reading stdout never has to also branch on the exit code.
 *
 * Copyright 2026, StaXX contributors.
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 2, as published
 * by the Free Software Foundation.
 */
'use strict';

var fs = require('fs');
var path = require('path');
var Y  = require(path.join(__dirname, '..', 'javascript', 'compose-model.js'));
var CA = require(path.join(__dirname, '..', 'javascript', 'ca-convert.js'));

// Settings that are always the operator's own choice, never the author's —
// PLAN_62 names these explicitly (paths, ports, user ids, timezone, container
// names, networks) as guaranteed noise on every stack. 'image' is excluded
// too because it is the very thing services are matched by: a differing
// registry or tag there is expected, not a finding.
var EXCLUDED_KEYS = {
  image: true, ports: true, volumes: true, container_name: true,
  hostname: true, networks: true, network_mode: true, user: true
};

// environment/labels entries are compared by NAME only, never by value — a
// changed TZ or PUID is exactly the "value merely differs" case the plan
// rules out, while a variable name appearing or disappearing is real news.
var NAME_VALUE_KEYS = { environment: true, labels: true };

function readFile(p) {
  return fs.readFileSync(p, 'utf8');
}

/** The raw parse-tree node -> a plain JS value. Sealed/unparsable nodes (a
 * line the parser could not make sense of) come back as null: opaque, not
 * drilled into, never mistaken for "absent". */
function toPlain(node) {
  if (!node) return null;
  if (node.kind === 'scalar') return node.value;
  if (node.kind === 'seq') return node.items.map(function (it) { return toPlain(it.value); });
  if (node.kind === 'map') {
    var out = {};
    node.keys.forEach(function (k) { out[k] = toPlain(node.pairs[k].value); });
    return out;
  }
  return null;
}

/** name -> value out of an environment/labels entry, whichever shape compose
 * allows: a map already, or a list of "NAME=value" (or bare "NAME") strings. */
function toNameValueMap(val) {
  var out = {};
  if (Array.isArray(val)) {
    val.forEach(function (item) {
      var s = String(item == null ? '' : item);
      var i = s.indexOf('=');
      var name = i < 0 ? s : s.slice(0, i);
      out[name] = i < 0 ? null : s.slice(i + 1);
    });
  } else if (val && typeof val === 'object') {
    Object.keys(val).forEach(function (k) { out[k] = val[k]; });
  }
  return out;
}

/** Every service in a parsed document, keyed by name, as a plain object —
 * '' (not thrown) for anything that is not a services-holding compose file
 * at all, so a README fragment that turns out not to parse as one just
 * yields no services rather than a crash. */
function servicesOf(doc) {
  if (!doc.root || doc.root.kind !== 'map') return {};
  var svcPair = doc.root.pairs.services;
  if (!svcPair || !svcPair.value || svcPair.value.kind !== 'map') return {};
  var out = {};
  svcPair.value.keys.forEach(function (name) {
    out[name] = toPlain(svcPair.value.pairs[name].value) || {};
  });
  return out;
}

function sameJson(a, b) {
  return JSON.stringify(a) === JSON.stringify(b);
}

/** Every finding for one matched pair of services — settings and list
 * entries present in one and absent in the other, nothing that is merely a
 * differing value. See the module comment for why each key group is handled
 * the way it is. */
function diffService(serviceName, ours, theirs) {
  var findings = [];
  var keys = {};
  Object.keys(ours).forEach(function (k) { keys[k] = true; });
  Object.keys(theirs).forEach(function (k) { keys[k] = true; });

  Object.keys(keys).forEach(function (key) {
    // Every x- extension key (x-unraid above all) is this plugin's own
    // metadata, or some other tool's — never something a published example
    // would carry, so flagging its absence there is pure noise, proved by
    // the real jellyfin comparison this script was checked against.
    if (EXCLUDED_KEYS[key] || key.slice(0, 2) === 'x-') return;

    if (NAME_VALUE_KEYS[key]) {
      var ourMap = toNameValueMap(ours[key]);
      var theirMap = toNameValueMap(theirs[key]);
      Object.keys(theirMap).forEach(function (name) {
        if (!(name in ourMap)) {
          findings.push({ service: serviceName, setting: key + '.' + name, side: 'added', value: theirMap[name] });
        }
      });
      Object.keys(ourMap).forEach(function (name) {
        if (!(name in theirMap)) {
          findings.push({ service: serviceName, setting: key + '.' + name, side: 'dropped', value: ourMap[name] });
        }
      });
      return;
    }

    var inOurs = Object.prototype.hasOwnProperty.call(ours, key);
    var inTheirs = Object.prototype.hasOwnProperty.call(theirs, key);

    if (inTheirs && !inOurs) {
      findings.push({ service: serviceName, setting: key, side: 'added', value: theirs[key] });
      return;
    }
    if (inOurs && !inTheirs) {
      findings.push({ service: serviceName, setting: key, side: 'dropped', value: ours[key] });
      return;
    }

    // Present on both sides. A plain list is compared item by item — an
    // added capability or a dropped one is real news. Anything else (a
    // scalar, or a map like healthcheck/deploy) is left alone: present on
    // both sides and not a list means whatever differs is a value, not a
    // structural change, and values are exactly what this feature must not
    // report.
    if (Array.isArray(ours[key]) && Array.isArray(theirs[key])) {
      theirs[key].forEach(function (item) {
        if (!ours[key].some(function (o) { return sameJson(o, item); })) {
          findings.push({ service: serviceName, setting: key, side: 'added', value: item });
        }
      });
      ours[key].forEach(function (item) {
        if (!theirs[key].some(function (t) { return sameJson(t, item); })) {
          findings.push({ service: serviceName, setting: key, side: 'dropped', value: item });
        }
      });
    }
  });

  return findings;
}

/** Pair up services across the two files by image, not by name — people
 * rename services freely, but the image is what makes two entries "the same
 * app" for this purpose. First match wins on both sides. */
function matchServices(ourSvcs, theirSvcs) {
  var usedTheirs = {};
  var pairs = [];
  Object.keys(ourSvcs).forEach(function (ourName) {
    var ourPath = CA.repositoryPath(String(ourSvcs[ourName].image || ''));
    if (ourPath === '') return;
    var theirName = Object.keys(theirSvcs).find(function (name) {
      if (usedTheirs[name]) return false;
      return CA.repositoryPath(String(theirSvcs[name].image || '')) === ourPath;
    });
    if (theirName === undefined) return;
    usedTheirs[theirName] = true;
    pairs.push([ourName, theirName]);
  });
  return pairs;
}

function main() {
  var localPath = process.argv[2];
  var remotePath = process.argv[3];
  if (!localPath || !remotePath) {
    console.log(JSON.stringify({ ok: false, reason: 'two file paths are required', findings: [] }));
    return;
  }

  var localText, remoteText;
  try {
    localText = readFile(localPath);
  } catch (e) {
    console.log(JSON.stringify({ ok: false, reason: 'could not read the local file', findings: [] }));
    return;
  }
  try {
    remoteText = readFile(remotePath);
  } catch (e) {
    console.log(JSON.stringify({ ok: false, reason: 'could not read the author\'s example', findings: [] }));
    return;
  }

  var ourSvcs = servicesOf(Y.parse(localText));
  var theirSvcs = servicesOf(Y.parse(remoteText));

  if (Object.keys(theirSvcs).length === 0) {
    console.log(JSON.stringify({ ok: false, reason: 'the author\'s example has no services to compare', findings: [] }));
    return;
  }

  var findings = [];
  matchServices(ourSvcs, theirSvcs).forEach(function (pair) {
    findings = findings.concat(diffService(pair[0], ourSvcs[pair[0]], theirSvcs[pair[1]]));
  });

  console.log(JSON.stringify({ ok: true, reason: '', findings: findings }));
}

try {
  main();
} catch (e) {
  console.log(JSON.stringify({ ok: false, reason: 'could not compare the two files', findings: [] }));
}
